<?php
/**
 * Skrypt do przetwarzania dłuższych okresów czasu pracy
 * Może być wywołany bezpośrednio lub przez cron_processor.php
 * Wersja z obsługą bazy danych
 */

// Wymagane pliki konfiguracyjne
require_once $_SERVER['DOCUMENT_ROOT'] . '/main.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/emp_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_time_set.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sam_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/yf_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/semp_config.php';
require_once 'functions.php';
require_once 'db_functions.php';
require_once 'wconfig.php';

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
    die($errorMessage);
}

// Sprawdź, czy skrypt jest wywoływany bezpośrednio czy przez cron
$isCronMode = (isset($task) && isset($taskId));

// W trybie bezpośrednim, pobierz parametry z żądania
if (!$isCronMode) {
    check_loggedin($pdo);
    checkAppAccess($apps, $apps_to_domains);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }
    
    $startDate = $_POST['startDate'] ?? '';
    $endDate = $_POST['endDate'] ?? '';
    $samNumber = $_POST['samNumber'] ?? '';
    $userId = $_SESSION['id'] ?? 0;
    
    // Walidacja dat
    if (empty($startDate) || empty($endDate)) {
        echo json_encode(['error' => 'Brak wymaganych parametrów']);
        exit;
    }
    
    // Sprawdź, czy okres nie jest dłuższy niż maksymalny dozwolony
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = $start->diff($end);
    $days = $interval->days;
    
    if ($days > WTA_MAX_ANALYSIS_DAYS) {
        echo json_encode([
            'error' => 'Okres jest zbyt długi do analizy', 
            'days' => $days, 
            'max_days' => WTA_MAX_ANALYSIS_DAYS
        ]);
        exit;
    }
    
    // Dodaj zadanie do kolejki
    $taskId = add_task_to_queue_db($mysqlConn, $userId, $startDate, $endDate, $samNumber);
    
    // W trybie bezpośrednim zwróć informację o dodaniu zadania do kolejki
    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'message' => 'Zadanie zostało dodane do kolejki'
    ]);
    exit;
}

// W trybie cron, zadanie jest już pobrane i gotowe do przetwarzania
// Kod przetwarzania przeniesiony do cron_processor.php