<?php
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

// Wyłącz wyświetlanie ostrzeżeń i błędów - zamiast tego będą zapisywane do logu
ini_set('display_errors', 0);
error_reporting(E_ERROR | E_PARSE);

// Rozpocznij buforowanie wyjścia
ob_start();

// Sprawdzenie uprawnień i metody żądania
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Pobierz parametry
$startDate = $_POST['startDate'] ?? '';
$endDate = $_POST['endDate'] ?? '';
$samNumber = $_POST['samNumber'] ?? '';

// Walidacja dat
if (empty($startDate) || empty($endDate)) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Brak wymaganych parametrów']);
    exit;
}

// Sprawdź, czy okres nie jest dłuższy niż dozwolony dla podglądu
$start = new DateTime($startDate);
$end = new DateTime($endDate);
$interval = $start->diff($end);
$days = $interval->days;

if ($days > WTA_PREVIEW_DAYS) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Okres jest zbyt długi do szybkiego podglądu', 'days' => $days]);
    exit;
}

// Inicjalizacja połączeń z bazami danych
try {
    // Połączenie z bazą MySQL sepm_emp
    $mysqlConn = new PDO(
        "mysql:host={$emp_db_host};dbname={$emp_db_name}",
        $emp_db_user,
        $emp_db_pass,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]
    );
    $mysqlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Logika obliczania czasu pracy
try {
    // Krok 1: Pobierz zamówienia na pracowników z sepm_order_person
    $orders = get_orders($mysqlConn, $startDate, $endDate, $samNumber);
    
    // Krok 2: Pobierz informacje o kartach RFID i pracownikach
    $rfidInfo = get_rfid_info($yfConn, $startDate, $endDate, $samNumber);
    
    // Krok 3: Pobierz odbicia z fake_entries (priorytet 1)
    $rfidKeys = array_keys($rfidInfo['rfid_to_id'] ?? []);
    if (!empty($rfidKeys)) {
        $fakeEntries = get_fake_entries($mysqlConn, $startDate, $endDate, $rfidKeys);
    } else {
        $fakeEntries = [];
    }
    
    // Krok 4: Pobierz rzeczywiste odbicia z card_log (priorytet 2)
    if (!empty($rfidKeys)) {
        $cardLogEntries = get_card_log_entries($sqlsrv_conn, $startDate, $endDate, $rfidKeys);
    } else {
        $cardLogEntries = [];
    }
    
    // Krok 5: Oblicz czas pracy na podstawie wszystkich źródeł danych
    $worktimeResults = calculate_worktime($orders, $rfidInfo, $fakeEntries, $cardLogEntries, $startDate, $endDate);
    
    // Wyczyść bufor i wyślij odpowiedź JSON
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => generate_preview_html($worktimeResults, $startDate, $endDate),
        'count' => count($worktimeResults)
    ]);
    exit;
    
} catch (Exception $e) {
    error_log("Błąd podczas przetwarzania danych: " . $e->getMessage());
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

/**
 * Generuje HTML dla szybkiego podglądu wyników
 */
function generate_preview_html($worktimeResults, $startDate, $endDate) {
    $html = '';
    
    if (empty($worktimeResults)) {
        return '<div class="alert alert-warning">Brak danych do wyświetlenia dla podanego okresu i filtrów.</div>';
    }
    
    $html .= '<div class="d-flex justify-content-between mb-3">
                <h4>Wyniki dla okresu: ' . $startDate . ' — ' . $endDate . '</h4>
                <button class="btn btn-success export-btn" id="exportPreviewBtn">
                    <i class="fas fa-file-excel me-2"></i>Eksportuj do Excel
                </button>
              </div>';
    
    $html .= '<div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                    <thead class="table-primary">
                        <tr>
                            <th>Data</th>
                            <th>Karta RFID</th>
                            <th>SAM</th>
                            <th>Imię i Nazwisko</th>
                            <th>Wejście</th>
                            <th>Wyjście</th>
                            <th>Rozpoczęcie</th>
                            <th>Zakończenie</th>
                            <th>Czas pracy (min)</th>
                            <th>Potrącenia (min)</th>
                            <th>Czytnik wejścia</th>
                            <th>Dział</th>
                            <th>Źródło danych</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    foreach ($worktimeResults as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['date'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['card_rfid'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['sam_number'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['employee_name'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['gate_in'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['gate_out'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['work_start'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['work_end'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['work_minutes'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['deducted_minutes'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['entry_reader'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['department'] ?? '') . '</td>';
        
        // Kolorowe oznaczenie źródła danych
        $sourceClass = '';
        switch ($row['data_source'] ?? '') {
            case 'GENEROWANE':
                $sourceClass = 'bg-warning text-dark';
                break;
            case 'CARDLOG':
                $sourceClass = 'bg-info text-dark';
                break;
            case 'SEPM-GATE':
                $sourceClass = 'bg-success text-white';
                break;
            default:
                $sourceClass = 'bg-secondary text-white';
        }
        
        $html .= '<td><span class="badge ' . $sourceClass . '">' . htmlspecialchars($row['data_source'] ?? '') . '</span></td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table></div>';
    
    return $html;
}