<?php
/**
 * Skrypt do pobierania wygenerowanych plików CSV
 * Wersja z obsługą bazy danych
 */

// Wymagane pliki konfiguracyjne
require_once $_SERVER['DOCUMENT_ROOT'] . '/main.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/emp_config_db.php';
require_once 'functions.php';
require_once 'db_functions.php';
require_once 'wconfig.php';

// Sprawdzenie uprawnień
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);

// Pobierz identyfikator zadania
$taskId = $_GET['task_id'] ?? '';

if (empty($taskId)) {
    http_response_code(400);
    echo "Błąd: Brak identyfikatora zadania";
    exit;
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
    http_response_code(500);
    echo "Błąd połączenia z bazą danych: " . $e->getMessage();
    exit;
}

// Sprawdź, czy zadanie należy do zalogowanego użytkownika
$userId = $_SESSION['account_id'] ?? 0;

if (!is_task_owner_db($mysqlConn, $taskId, $userId)) {
    http_response_code(403);
    echo "Błąd: Brak uprawnień do zadania";
    exit;
}

// Pobierz informacje o zadaniu
$query = "
    SELECT 
        task_id, start_date, end_date, sam_number, status, file_path
    FROM 
        sepm_wta_cron
    WHERE 
        task_id = :task_id
";

$stmt = $mysqlConn->prepare($query);
$stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
$stmt->execute();

$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    http_response_code(404);
    echo "Błąd: Zadanie nie istnieje";
    exit;
}

if ($task['status'] != 'completed') {
    http_response_code(400);
    echo "Błąd: Zadanie nie zostało ukończone";
    exit;
}

// Ścieżka do pliku CSV
$csvFile = $task['file_path'];

if (!file_exists($csvFile)) {
    http_response_code(404);
    echo "Błąd: Plik nie istnieje";
    exit;
}

// Pobierz informacje o okresie i ewentualnie numerze SAM
$startDate = $task['start_date'];
$endDate = $task['end_date'];
$samNumber = $task['sam_number'] ?? '';

// Nazwa pliku do pobrania
$filename = 'czas_pracy_' . $startDate . '_' . $endDate;

if (!empty($samNumber)) {
    $filename .= '_' . $samNumber;
}

$filename .= '.csv';

// Zaloguj akcję pobrania
log_action('DOWNLOAD', 'WTA_SYSTEM_CSV');

// Dodaj log do bazy danych
$logMessage = "Pobrano plik: " . $filename;
log_processing_progress_db($mysqlConn, $taskId, $logMessage);

// Wyślij plik do pobrania
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($csvFile));
header('Pragma: no-cache');
header('Expires: 0');

// Pobierz dane i przekoduj na UTF-8 z BOM (dla prawidłowego otwierania w Excelu)
$data = file_get_contents($csvFile);
echo "\xEF\xBB\xBF" . $data; // Dodaj BOM dla UTF-8