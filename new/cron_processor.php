<?php
/**
 * Skrypt uruchamiany przez cron co 2 minuty
 * Pobiera najstarsze zadanie z kolejki i je przetwarza
 * Wersja z obsługą bazy danych
 */

// Ustawienie ścieżki absolutnej do skryptów
define('WTA_ROOT', dirname(__FILE__));

// Wymagane pliki konfiguracyjne
require_once $_SERVER['DOCUMENT_ROOT'] . '/main.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/emp_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_time_set.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sam_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/yf_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/semp_config.php';
require_once WTA_ROOT . '/functions.php';
require_once WTA_ROOT . '/db_functions.php';
require_once WTA_ROOT . '/wconfig.php';

// Ustaw limit czasu wykonania skryptu (10 minut)
set_time_limit(600);

// Ustaw flagę uruchomienia przez CLI
$isCLI = (php_sapi_name() == 'cli');

// Sprawdź, czy skrypt jest uruchamiany przez cron
if (!$isCLI) {
    // Jeśli nie jest uruchamiany przez cron, wymagaj uwierzytelnienia
    check_loggedin($pdo);
    checkAppAccess($apps, $apps_to_domains);
    
    // Sprawdź, czy użytkownik ma uprawnienia administratora
    // Tutaj można dodać własną logikę sprawdzania uprawnień
    
    echo "<h1>Processor CRON (DB Version)</h1>";
    echo "<pre>";
}

// Inicjalizacja połączenia z bazą danych MySQL (sepm_emp)
try {
    $mysqlConn = new PDO(
        "mysql:host={$emp_db_host};dbname={$emp_db_name}",
        $emp_db_user,
        $emp_db_pass,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]
    );
    $mysqlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $errorMessage = "Błąd połączenia z bazą MySQL: " . $e->getMessage();
    console_log($errorMessage);
    exit($errorMessage);
}

// Funkcja do logowania w konsoli
function console_log($message) {
    global $isCLI;
    
    $formattedMessage = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
    
    // Zapisz log do pliku
    $logDir = WTA_ROOT . '/tasks/cron_logs/';
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . date('Y-m-d') . '.log';
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
    
    // Wyświetl w przeglądarce, jeśli nie jesteśmy w CLI
    if (!$isCLI) {
        echo $formattedMessage;
    }
}

// Zapisz czas rozpoczęcia
$startTime = microtime(true);
console_log("Rozpoczęcie przetwarzania zadań");

// Losowo wywołaj czyszczenie starych zadań (z prawdopodobieństwem 5%)
if (rand(1, 20) == 1) {
    $deletedCount = cleanup_old_tasks_db($mysqlConn);
    console_log("Wyczyszczono $deletedCount starych zadań");
}

// Sprawdź, czy są jakieś zadania w kolejce
$task = get_oldest_pending_task_db($mysqlConn);

if (!$task) {
    console_log("Brak zadań w kolejce");
    if (!$isCLI) {
        echo "</pre>";
    }
    exit;
}

$taskId = $task['task_id'];
console_log("Przetwarzanie zadania: $taskId");
console_log("Dane zadania: " . json_encode($task));

// Przygotuj katalogi wyjściowe
$outputDir = WTA_ROOT . '/tasks/output/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Inicjalizacja pozostałych połączeń z bazami danych
try {
    // Połączenie z bazą MySQL (YF)
    $yfConn = new PDO(
        "mysql:host={$yf_db_host};dbname={$yf_db_name}",
        $yf_db_user,
        $yf_db_pass,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]
    );
    $yfConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Połączenie z bazą SQL Server (CardLog)
    $sqlsrv_conn = sqlsrv_connect($serverName, $connectionOptions);
    if ($sqlsrv_conn === false) {
        throw new Exception("Błąd połączenia z bazą SQL Server: " . print_r(sqlsrv_errors(), true));
    }
} catch (Exception $e) {
    $errorMessage = "Błąd inicjalizacji połączeń: " . $e->getMessage();
    log_processing_progress_db($mysqlConn, $taskId, $errorMessage);
    mark_task_as_failed_db($mysqlConn, $taskId, $errorMessage);
    console_log($errorMessage);
    if (!$isCLI) {
        echo "</pre>";
    }
    exit;
}

// Przetwarzanie zadania
try {
    // Krok 1: Pobierz zamówienia na pracowników z sepm_order_person
    $orders = get_orders($mysqlConn, $task['start_date'], $task['end_date'], $task['sam_number']);
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano " . count($orders) . " zamówień");
    console_log("Pobrano " . count($orders) . " zamówień");
    
    // Krok 2: Pobierz informacje o kartach RFID i pracownikach
    $rfidInfo = get_rfid_info($yfConn, $task['start_date'], $task['end_date'], $task['sam_number']);
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano informacje o " . count($rfidInfo['raw_data']) . " kartach RFID");
    console_log("Pobrano informacje o " . count($rfidInfo['raw_data']) . " kartach RFID");
    
    // Krok 3: Pobierz odbicia z fake_entries (priorytet 1)
    $fakeEntries = get_fake_entries($mysqlConn, $task['start_date'], $task['end_date'], array_keys($rfidInfo['rfid_to_id']));
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano " . count($fakeEntries) . " ręcznych korekt odbić");
    console_log("Pobrano " . count($fakeEntries) . " ręcznych korekt odbić");
    
    // Krok 4: Pobierz rzeczywiste odbicia z card_log (priorytet 2)
    $cardLogEntries = get_card_log_entries($sqlsrv_conn, $task['start_date'], $task['end_date'], array_keys($rfidInfo['rfid_to_id']));
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano " . count($cardLogEntries) . " rzeczywistych odbić");
    console_log("Pobrano " . count($cardLogEntries) . " rzeczywistych odbić");
    
    // Krok 5: Oblicz czas pracy na podstawie wszystkich źródeł danych
    $worktimeResults = calculate_worktime($orders, $rfidInfo, $fakeEntries, $cardLogEntries, $task['start_date'], $task['end_date']);
    log_processing_progress_db($mysqlConn, $taskId, "Obliczono czas pracy dla " . count($worktimeResults) . " wpisów");
    console_log("Obliczono czas pracy dla " . count($worktimeResults) . " wpisów");
    
    // Krok 6: Zapisz wyniki do plików
    $csvFilePath = $outputDir . $taskId . '.csv';
    $jsonFilePath = $outputDir . $taskId . '.json';
    
    // Zapis do CSV
    $f = fopen($csvFilePath, 'w');
    
    // Nagłówki
    $headers = [
        'Data', 
        'Karta RFID', 
        'SAM', 
        'Imię i Nazwisko',
        'Nr identyfikacyjny (MD5)',
        'Wejście', 
        'Wyjście', 
        'Rozpoczęcie pracy', 
        'Zakończenie pracy', 
        'Czas pracy (min)', 
        'Potrącenia (min)', 
        'Czytnik wejścia', 
        'Dział',
        'Źródło danych',
        'Zmiana',
        'Rezygnacja',
        'Obecność'
    ];
    
    fputcsv($f, $headers);
    
    // Dane
    foreach ($worktimeResults as $row) {
        $csvRow = [
            $row['date'],
            $row['card_rfid'],
            $row['sam_number'],
            $row['employee_name'],
            $row['id_hash'],
            $row['gate_in'],
            $row['gate_out'],
            $row['work_start'],
            $row['work_end'],
            $row['work_minutes'],
            $row['deducted_minutes'],
            $row['entry_reader'],
            $row['department'],
            $row['data_source'],
            $row['shift'],
            $row['resignation'] ? 'Tak' : 'Nie',
            $row['is_present'] ? 'Tak' : 'Nie'
        ];
        
        fputcsv($f, $csvRow);
    }
    
    fclose($f);
    
    // Zapis do JSON dla bardziej zaawansowanej obróbki
    file_put_contents($jsonFilePath, json_encode($worktimeResults, JSON_PRETTY_PRINT));
    
    // Krok 7: Oznacz zadanie jako ukończone
    mark_task_as_completed_db($mysqlConn, $taskId, count($worktimeResults), $csvFilePath, $jsonFilePath);
    
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    log_processing_progress_db($mysqlConn, $taskId, "Zakończono przetwarzanie w $executionTime s");
    console_log("Zadanie zakończone pomyślnie: $taskId (czas: $executionTime s)");
    
} catch (Exception $e) {
    $errorMessage = "Błąd podczas przetwarzania zadania: " . $e->getMessage();
    log_processing_progress_db($mysqlConn, $taskId, $errorMessage);
    mark_task_as_failed_db($mysqlConn, $taskId, $errorMessage);
    console_log($errorMessage);
}

// Zakończ skrypt
if (!$isCLI) {
    echo "</pre>";
}