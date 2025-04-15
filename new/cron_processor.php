<?php
// Ścisła kontrola błędów i raportowanie
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dodaj mechanizm blokady, aby zapobiec równoległemu wykonaniu
$lockFile = __DIR__ . '/cron.lock';
$lockHandle = fopen($lockFile, 'w');

// Spróbuj utworzyć blokadę
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    console_log("Inny proces jest już uruchomiony");
    exit('Skrypt jest już wykonywany');
}

/**
 * Skrypt uruchamiany przez cron co 2 minuty
 * Pobiera najstarsze zadanie z kolejki i je przetwarza
 * Wersja z obsługą bazy danych - tylko do użytku przez CRON
 */

// Ustawienie ścieżki absolutnej do skryptów
define('WTA_ROOT', dirname(__FILE__));
define('APPS_ROOT', dirname(WTA_ROOT));
define('DOCUMENT_ROOT', dirname(APPS_ROOT));

// Wymagane pliki konfiguracyjne
require_once DOCUMENT_ROOT . '/main.php';
require_once DOCUMENT_ROOT . '/includes/emp_config_db.php';
require_once DOCUMENT_ROOT . '/includes/inc_time_set.php';
require_once DOCUMENT_ROOT . '/includes/sam_config_db.php';
require_once DOCUMENT_ROOT . '/includes/yf_config_db.php';
require_once DOCUMENT_ROOT . '/includes/semp_config.php';
require_once WTA_ROOT . '/functions.php';
require_once WTA_ROOT . '/db_functions.php';
require_once WTA_ROOT . '/wconfig.php';

// Ustaw limit czasu wykonania skryptu (15 minut zamiast 10)
set_time_limit(900);

// Dodatkowe zabezpieczenia przed nieuprawnionym dostępem
if (!defined('CRON_RUNNING')) {
    define('CRON_RUNNING', true);
}

// Ustaw flagę uruchomienia przez CLI
$isCLI = (php_sapi_name() == 'cli');

// Bardziej rygorystyczne sprawdzenie CLI
if (!$isCLI) {
    http_response_code(403);
    error_log('Próba uruchomienia skryptu cron spoza CLI');
    exit('Dostęp zabroniony - ten skrypt może być uruchamiany tylko przez CRON');
}

// Ulepszona funkcja logowania
function console_log($message, $type = 'info') {
    global $isCLI;
    
    $logDir = WTA_ROOT . '/tasks/cron_logs/';
    
    // Utwórz katalog logów, jeśli nie istnieje
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . date('Y-m-d') . '_' . $type . '.log';
    $formattedMessage = date('Y-m-d H:i:s') . " [$type] " . $message . PHP_EOL;
    
    // Dodaj informacje o pamięci i obciążeniu systemu
    $memoryUsage = memory_get_usage(true) / 1024 / 1024;
    $loadAvg = sys_getloadavg();
    $additionalInfo = sprintf(
        "Pamięć: %.2f MB, Obciążenie: %.2f, %.2f, %.2f\n", 
        $memoryUsage, 
        $loadAvg[0], 
        $loadAvg[1], 
        $loadAvg[2]
    );
    
    file_put_contents($logFile, $formattedMessage . $additionalInfo, FILE_APPEND);
    
    // Wyświetl w konsoli, jeśli jesteśmy w CLI
    if ($isCLI) {
        echo $formattedMessage;
    }
}

// Główna logika skryptu
try {
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
        console_log($errorMessage, 'error');
        throw new Exception($errorMessage);
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
        console_log($errorMessage, 'error');
        throw $e;
    }

    // Przetwarzanie zadania
    // Krok 1: Pobierz zamówienia na pracowników z sepm_order_person
    $orders = get_orders($mysqlConn, $task['start_date'], $task['end_date'], $task['sam_number']);
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano " . count($orders) . " zamówień");
    console_log("Pobrano " . count($orders) . " zamówień");
    
    // Krok 2: Pobierz informacje o kartach RFID i pracownikach
    $rfidInfo = get_rfid_info($yfConn, $task['start_date'], $task['end_date'], $task['sam_number']);
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano informacje o " . count($rfidInfo['raw_data'] ?? []) . " kartach RFID");
    console_log("Pobrano informacje o " . count($rfidInfo['raw_data'] ?? []) . " kartach RFID");
    
    // Krok 3: Pobierz odbicia z fake_entries (priorytet 1)
    $rfidKeys = array_keys($rfidInfo['rfid_to_id'] ?? []);
    if (!empty($rfidKeys)) {
        $fakeEntries = get_fake_entries($mysqlConn, $task['start_date'], $task['end_date'], $rfidKeys);
    } else {
        $fakeEntries = [];
    }
    log_processing_progress_db($mysqlConn, $taskId, "Pobrano " . count($fakeEntries) . " ręcznych korekt odbić");
    console_log("Pobrano " . count($fakeEntries) . " ręcznych korekt odbić");
    
    // Krok 4: Pobierz rzeczywiste odbicia z card_log (priorytet 2)
    if (!empty($rfidKeys)) {
        $cardLogEntries = get_card_log_entries($sqlsrv_conn, $task['start_date'], $task['end_date'], $rfidKeys);
    } else {
        $cardLogEntries = [];
    }
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
            $row['date'] ?? '',
            $row['card_rfid'] ?? '',
            $row['sam_number'] ?? '',
            $row['employee_name'] ?? '',
            $row['id_hash'] ?? '',
            $row['gate_in'] ?? '',
            $row['gate_out'] ?? '',
            $row['work_start'] ?? '',
            $row['work_end'] ?? '',
            $row['work_minutes'] ?? '',
            $row['deducted_minutes'] ?? '',
            $row['entry_reader'] ?? '',
            $row['department'] ?? '',
            $row['data_source'] ?? '',
            $row['shift'] ?? '',
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
    
    // Zwolnij blokadę
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    unlink($lockFile);

} catch (Exception $e) {
    // Rozszerzona obsługa błędów
    console_log('Krytyczny błąd: ' . $e->getMessage(), 'error');
    console_log('Ślad stosu: ' . $e->getTraceAsString(), 'error');
    
    // Wysyłanie powiadomień np. przez email
    error_log('Błąd w skrypcie cron: ' . $e->getMessage());
    
    // Zwolnij blokadę nawet w przypadku błędu
    if (isset($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        unlink($lockFile);
    }
    
    exit(1);
}