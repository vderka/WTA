<?php
// Wymagane pliki konfiguracyjne
require_once $_SERVER['DOCUMENT_ROOT'] . '/main.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/emp_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_time_set.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sam_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/yf_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/semp_config.php';

// Sprawdzenie uprawnień
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);
log_action('VIEW','Wizyta');

// Funkcja renderująca wartości
function renderFunction($value, $type = 'text') {
    if (empty($value) || $value === null) {
        return '<span class="text-muted">Brak</span>';
    }
    
    switch ($type) {
        case 'date':
            return date('Y-m-d', strtotime($value));
        case 'datetime':
            return date('Y-m-d H:i:s', strtotime($value));
        case 'bool':
            return $value ? '<span class="badge bg-success">Tak</span>' : '<span class="badge bg-danger">Nie</span>';
        case 'active':
            return $value ? '<span class="badge bg-success">Aktywna</span>' : '<span class="badge bg-danger">Nieaktywna</span>';
        case 'attendance':
            return $value ? '<span class="badge bg-success">Obecny</span>' : 'Brak';
        case 'json':
            return '<pre class="m-0 p-0" style="font-size: 11px;">' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        default:
            return htmlspecialchars($value);
    }
}

// Parametry filtrowania
$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$currentShift = isset($_GET['shift']) ? (int)$_GET['shift'] : getCurrentShift(); // Używamy funkcji z sepm_config
$departmentId = isset($_GET['department']) ? (int)$_GET['department'] : null;

// Określenie czasu rozpoczęcia/zakończenia zmiany na podstawie SEPM_CONFIG
$shiftStartTime = $currentShift == 1 ? $SEPM_SETTINGS["SHIFT_1_START"] . ':00' : $SEPM_SETTINGS["SHIFT_2_START"] . ':00';
$shiftEndTime = $currentShift == 1 ? $SEPM_SETTINGS["SHIFT_2_START"] . ':00' : $SEPM_SETTINGS["SHIFT_1_START"] . ':00';

// Połączenie z bazą MySQL (sepm_emp)
try {
    $mysqlConn = new PDO(
        "mysql:host={$emp_db_host};dbname={$emp_db_name}", 
        $emp_db_user, 
        $emp_db_pass,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]
    );
    $mysqlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Błąd połączenia z bazą sepm_emp: " . $e->getMessage());
    die("Błąd połączenia z bazą danych sepm_emp: " . $e->getMessage());
}

// Połączenie z bazą MySQL (YF)
try {
    $yfConn = new PDO(
        "mysql:host={$yf_db_host};dbname={$yf_db_name}", 
        $yf_db_user, 
        $yf_db_pass,
        [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"]
    );
    $yfConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Błąd połączenia z bazą YF: " . $e->getMessage());
    die("Błąd połączenia z bazą danych YF: " . $e->getMessage());
}

// Połączenie z bazą SQL Server (CardLog)
$sqlsrv_conn = sqlsrv_connect($serverName, $connectionOptions);
if ($sqlsrv_conn === false) {
    error_log("Błąd połączenia z bazą SQL Server: " . print_r(sqlsrv_errors(), true));
    die("Błąd połączenia z bazą SQL Server: " . print_r(sqlsrv_errors(), true));
}

// Pobierz listę wszystkich działów
$departmentsQuery = "
    SELECT 
        d.id, 
        COALESCE(dn.depname, d.initial_name) AS department_name
    FROM 
        sepm_departments d
    LEFT JOIN 
        sepm_departments_names dn ON d.id = dn.department_id 
            AND :selected_date BETWEEN dn.begda AND COALESCE(dn.enda, '9999-12-31')
    WHERE 
        d.is_active = 1
    ORDER BY 
        department_name
";
$departmentsStmt = $mysqlConn->prepare($departmentsQuery);
$departmentsStmt->execute([':selected_date' => $currentDate]);
$departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Warunek dla działu
$departmentCondition = $departmentId ? "AND o.department_id = :department_id" : "";

// Pobierz wpisy pracowników dla danej zmiany i dnia
$query = "
    SELECT 
        o.id,
        o.sam_number, 
        o.shift, 
        o.ordered_for, 
        o.department_id,
        o.entered_at AS sepm_entered_at,
        o.left_at AS sepm_left_at,
        o.onsite_order,
        o.group_name,
        o.resignation,
        o.comment,
        d.initial_name AS department_code,
        COALESCE(dn.depname, d.initial_name) AS department_name
    FROM 
        sepm_order_person o
    LEFT JOIN 
        sepm_departments d ON o.department_id = d.id
    LEFT JOIN 
        sepm_departments_names dn ON d.id = dn.department_id 
            AND o.ordered_for BETWEEN dn.begda AND COALESCE(dn.enda, '9999-12-31')
    WHERE 
        o.shift = :shift 
        AND o.ordered_for = :current_date
        AND o.is_deleted = 0
        $departmentCondition
    ORDER BY 
        o.sam_number, 
        o.entered_at DESC
";

$stmt = $mysqlConn->prepare($query);
$params = [
    ':shift' => $currentShift,
    ':current_date' => $currentDate
];

if ($departmentId) {
    $params[':department_id'] = $departmentId;
}

$stmt->execute($params);
$orderPersonEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Grupuj wpisy według numeru SAM i licz wpisy dla każdego numeru SAM
$uniqueEntries = [];
$originalEntries = []; // Dodajemy nową tablicę dla pierwotnych wpisów
$samEntriesCount = []; // Liczymy wpisy dla każdego SAM
$samOldestNullEntry = []; // Najstarsze wpisy z pustym entered_at dla każdego SAM

foreach ($orderPersonEntries as $entry) {
    $samNumber = $entry['sam_number'];
    
    // Liczymy wpisy dla każdego SAM
    if (!isset($samEntriesCount[$samNumber])) {
        $samEntriesCount[$samNumber] = 1;
    } else {
        $samEntriesCount[$samNumber]++;
    }
    
    // Dla pierwotnych wpisów bierzemy najstarszy wpis, który nie ma null w entered_at
    if (!empty($entry['sepm_entered_at'])) {
        if (!isset($originalEntries[$samNumber]) || $entry['sepm_entered_at'] < $originalEntries[$samNumber]['sepm_entered_at']) {
            $originalEntries[$samNumber] = $entry;
        }
    }
    
    // Śledzimy najstarszy wpis z pustym entered_at dla każdego SAM
    if (empty($entry['sepm_entered_at'])) {
        if (!isset($samOldestNullEntry[$samNumber])) {
            $samOldestNullEntry[$samNumber] = $entry;
        } else if (strtotime($entry['ordered_for']) < strtotime($samOldestNullEntry[$samNumber]['ordered_for'])) {
            $samOldestNullEntry[$samNumber] = $entry;
        }
    }
    
    // Dla unikalnych wpisów bierzemy najnowszy
    if (!isset($uniqueEntries[$samNumber]) || $entry['sepm_entered_at'] > $uniqueEntries[$samNumber]['sepm_entered_at']) {
        $uniqueEntries[$samNumber] = $entry;
    }
}

// Przygotuj parametry do zapytania YF
$samNumbers = array_keys($uniqueEntries);
$placeholders = implode(',', array_fill(0, count($samNumbers), '?'));

// Tablica przechowująca dane do wyświetlenia
$tableData = [];

// Jeśli są pracownicy do wyświetlenia
if (!empty($samNumbers)) {
    // Zapytanie do pobrania informacji o kartach RFID
    $rfidQuery = "
        SELECT 
            p.numer_identyfikacyjny,
            p.number_sam AS numer_sam,
            k.numerrfid,
            p.karta_rfid,
            p.aktywacja_rfid,
            p.dezaktywacja_rfid,
            p.imie,
            p.nazwisko,
            p.grupa_rfid,
            p.telefon_pl,
            p.status_pracownika,
            CASE 
                WHEN p.aktywacja_rfid <= ? AND (p.dezaktywacja_rfid IS NULL OR p.dezaktywacja_rfid >= ?) 
                THEN 1 
                ELSE 0 
            END AS karta_aktywna
        FROM 
            u_yf_pracownicysepm p
        LEFT JOIN 
            u_yf_kartyrfid k ON p.karta_rfid = k.kartyrfidid
        WHERE 
            p.number_sam IN ($placeholders)
    ";

    // Parametry do zapytania
    $params = array_merge(
        [$currentDate, $currentDate], 
        array_map('strval', $samNumbers)
    );

    // Wykonaj zapytanie o karty RFID
    $stmt = $yfConn->prepare($rfidQuery);
    $stmt->execute($params);
    $rfidInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Przekształć informacje o kartach do słownika
    $rfidInfoDict = [];
    foreach ($rfidInfo as $info) {
        $rfidInfoDict[$info['numer_sam']] = $info;
    }
    
    // Data końcowa dla zmiany 2 to następny dzień
    $endDate = $currentShift == 1 ? $currentDate : date('Y-m-d', strtotime("$currentDate +1 day"));
    
    $startDateTime = "$currentDate $shiftStartTime";
    $endDateTime = "$endDate $shiftEndTime";

    // Przetwórz dane dla tabeli
    foreach ($uniqueEntries as $samNumber => $sepmData) {
        // Dane podstawowe
        $rfidData = $rfidInfoDict[$samNumber] ?? null;
        $rfidCard = $rfidData['numerrfid'] ?? null;
        $cardLogEntry = null;
        $fakeEntry = null;
        
        // Pobierz dane z CardLog - pobierz wszystkie wpisy z ReaderFunction 1 i 3, a potem wybierz odpowiedni
        if ($rfidCard) {
            $cardLogQuery = "
                SELECT 
                    LogID,
                    CardID,
                    ReadAt AS entered_at, 
                    Reader AS reader_name,
                    ReaderFunction
                FROM 
                    [dbo].[card_log]
                WHERE 
                    CardID = ? 
                    AND ReadAt >= ? 
                    AND ReadAt <= ?
                    AND ReaderFunction IN (1, 3)
                ORDER BY 
                    ReadAt ASC
            ";
            
            $cardLogParams = [
                $rfidCard,
                $startDateTime,
                $endDateTime
            ];
            
            $cardLogStmt = sqlsrv_query($sqlsrv_conn, $cardLogQuery, $cardLogParams);
            
            // Znajdź najwcześniejsze wpisy - priorytet ma ReaderFunction = 1
            $cardLogEntry = null;
            $cardLogEntryRF3 = null;
            
            if ($cardLogStmt) {
                while ($row = sqlsrv_fetch_array($cardLogStmt, SQLSRV_FETCH_ASSOC)) {
                    // Formatuj datę
                    if ($row['entered_at'] instanceof DateTime) {
                        $row['entered_at_formatted'] = $row['entered_at']->format('Y-m-d H:i:s');
                    } else {
                        $row['entered_at_formatted'] = $row['entered_at'];
                    }
                    
                    // Zapisz pierwsze napotkane wpisy dla każdego ReaderFunction
                    if ($row['ReaderFunction'] == 1 && $cardLogEntry === null) {
                        $cardLogEntry = $row;
                    } else if ($row['ReaderFunction'] == 3 && $cardLogEntryRF3 === null) {
                        $cardLogEntryRF3 = $row;
                    }
                    
                    // Jeśli mamy już wpis z RF=1, nie musimy szukać dalej
                    if ($cardLogEntry !== null) {
                        break;
                    }
                }
                
                // Jeśli nie znaleziono wpisu z RF=1, użyj wpisu z RF=3
                if ($cardLogEntry === null) {
                    $cardLogEntry = $cardLogEntryRF3;
                }
            }
        }
        
        // Pobierz dane z FakeEntries - tylko z ReaderFunction = 1
        if ($rfidCard) {
            $fakeEntriesQuery = "
                SELECT 
                    LogID,
                    CardID,
                    ReadAt AS entered_at, 
                    Reader AS reader_name,
                    ReaderFunction,
                    LastActionBy
                FROM 
                    sepm_fake_entries
                WHERE 
                    CardID = ? 
                    AND ReadAt >= ? 
                    AND ReadAt <= ?
                    AND ReaderFunction = 1
                    AND is_deleted = 0
                ORDER BY 
                    ReadAt ASC
                LIMIT 1
            ";
            
            $fakeEntriesStmt = $mysqlConn->prepare($fakeEntriesQuery);
            $fakeEntriesStmt->execute([
                $rfidCard,
                $startDateTime,
                $endDateTime
            ]);
            $fakeEntry = $fakeEntriesStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Znajdź najstarsze wejście
        $oldestTime = null;
        $oldestSource = '';
        $oldestRF = null;
        $oldestLogID = null;
        $oldestReader = null;
        
        if (!empty($sepmData['sepm_entered_at'])) {
            $oldestTime = $sepmData['sepm_entered_at'];
            $oldestSource = 'SEPM-GATE';
            $oldestRF = null; // SEPM nie ma ReaderFunction
            $oldestLogID = $sepmData['id'];
            $oldestReader = null;
        }
        
        if ($cardLogEntry && isset($cardLogEntry['entered_at_formatted'])) {
            if ($oldestTime === null || $cardLogEntry['entered_at_formatted'] < $oldestTime) {
                $oldestTime = $cardLogEntry['entered_at_formatted'];
                $oldestSource = 'CARDLOG';
                $oldestRF = $cardLogEntry['ReaderFunction'];
                $oldestLogID = $cardLogEntry['LogID'];
                $oldestReader = $cardLogEntry['reader_name'];
            }
        }
        
        if ($fakeEntry && isset($fakeEntry['entered_at'])) {
            if ($oldestTime === null || $fakeEntry['entered_at'] < $oldestTime) {
                $oldestTime = $fakeEntry['entered_at'];
                $oldestSource = 'GENEROWANE';
                $oldestRF = $fakeEntry['ReaderFunction'];
                $oldestLogID = $fakeEntry['LogID'];
                $oldestReader = $fakeEntry['reader_name'];
            }
        }
        
        // Przygotuj dane do zapisu DB
        $zapisDBData = null;
        // Jeśli istnieje jakikolwiek wpis entered_at dla danego SAM, pozostaw pusty
        if (!empty($sepmData['sepm_entered_at'])) {
            $zapisDBData = null;
        } else {
            // W przeciwnym razie pobierz najstarsze odbicie
            if ($oldestTime !== null) {
                // Użyj ID najstarszego wpisu z pustym entered_at dla tego SAM
                $nullEntryId = isset($samOldestNullEntry[$samNumber]) ? $samOldestNullEntry[$samNumber]['id'] : null;
                
                $zapisDBData = [
                    'id' => $nullEntryId, // ID najstarszego wpisu z pustym entered_at
                    'time' => $oldestTime,
                    'source' => $oldestSource,
                    'reader' => $oldestReader
                ];
            }
        }
        
        // Określ status obecności - na podstawie najstarszego wejścia
        $isPresent = false; // Domyślnie zakładamy, że osoba jest nieobecna
        $hasResignation = !empty($sepmData['resignation']) && $sepmData['resignation'] == 1;
        
        // Pracownik jest obecny jeśli ma jakiekolwiek najstarsze wejście
        if ($oldestTime !== null) {
            $isPresent = true;
        }
        
        // Dodaj pierwotny wpis entered_at
        $originalData = null;
        // Tylko jeśli liczba wpisów dla tego samego numeru SAM jest większa niż 1
        if (isset($samEntriesCount[$samNumber]) && $samEntriesCount[$samNumber] > 1) {
            // Jeśli istnieje najstarszy wpis, który nie jest nullem
            if (isset($originalEntries[$samNumber])) {
                $originalData = [
                    'entries_count' => $samEntriesCount[$samNumber],
                    'entered_at' => $originalEntries[$samNumber]['sepm_entered_at']
                ];
            }
        }
        
        // Dodaj wpis do danych tabeli
        $tableData[] = [
            'sepm_data' => $sepmData,
            'rfid_data' => $rfidData,
            'card_log_entry' => $cardLogEntry,
            'fake_entry' => $fakeEntry,
            'is_present' => $isPresent,
            'has_resignation' => $hasResignation,
            'original_data' => $originalData,
            'zapis_db_data' => $zapisDBData
        ];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php  $safe_dir = basename(dirname(filter_input(INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_URL))); echo htmlspecialchars($APPtitle = isset($apps[$safe_dir]) ? $apps[$safe_dir] : "APP");  ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
            padding-top: 0;
            padding-bottom: 0;
            margin: 0;
        }
        .container {
            width: 100%;
            max-width: 100%;
            background-color: #fff;
            padding: 15px;
            margin: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .badge-rf {
            background-color: #6c757d;
            color: white;
            font-size: 10px;
            margin-left: 5px;
            vertical-align: top;
            padding: 3px 6px;
            border-radius: 4px;
        }
        .badge-sepm {
            background-color: #d4edda;
            color: #155724;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .badge-cardlog {
            background-color: #cce5ff;
            color: #004085;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .badge-fake {
            background-color: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .alert-info {
            margin-bottom: 15px;
            padding: 8px;
            font-size: 14px;
        }
        h1 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 24px;
        }
        .form-control, .form-select, .btn {
            padding: 6px 12px;
            height: auto;
        }
        .table thead th {
            background-color: #90caf9;
            color: #000;
            font-weight: 500;
        }
        .table {
            width: 100%;
            margin-bottom: 0;
        }
        .table td {
            vertical-align: middle;
            padding: 8px;
        }
        .table th {
            padding: 8px;
        }
        .table-responsive {
            margin-bottom: 0;
        }
        .row.g-3 {
            margin-bottom: 15px;
        }
        .filter-form {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .toggle-zapis {
            cursor: pointer;
            color: #0d6efd;
        }
        .toggle-zapis:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php renderNavbar(); ?>
    <div class="container">
        <h1 class="text-center mb-4"><?php echo $APPtitle; ?></h1>

        <!-- Formularz filtru -->
        <form method="GET" class="row g-3 mb-4 bg-light p-3 rounded">
            <div class="col-md-3">
                <label for="date" class="form-label fw-bold">Data:</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($currentDate) ?>" class="form-control">
            </div>
            
            <div class="col-md-3">
                <label for="shift" class="form-label fw-bold">Zmiana:</label>
                <select id="shift" name="shift" class="form-select">
                    <option value="1" <?= $currentShift == 1 ? 'selected' : '' ?>>Zmiana 1</option>
                    <option value="2" <?= $currentShift == 2 ? 'selected' : '' ?>>Zmiana 2</option>
                </select>
            </div>
            
            <div class="col-md-4">
                <label for="department" class="form-label fw-bold">Dział:</label>
                <select id="department" name="department" class="form-select">
                    <option value="">Wszystkie działy</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $departmentId == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Filtruj</button>
            </div>
        </form>

        <!-- Informacja o filtrach -->
        <div class="alert alert-info mb-4">
            <strong>Data:</strong> <?= htmlspecialchars($currentDate) ?> | 
            <strong>Zmiana:</strong> <?= $currentShift == 1 ? 'Zmiana 1' : 'Zmiana 2' ?> (<?= $shiftStartTime ?> - <?= $shiftEndTime ?>)
            <?php if ($departmentId): ?>
                <?php foreach ($departments as $dept): ?>
                    <?php if ($dept['id'] == $departmentId): ?>
                        | <strong>Dział:</strong> <?= htmlspecialchars($dept['department_name']) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                | <strong>Dział:</strong> Wszystkie działy
            <?php endif; ?>
            | <span class="toggle-zapis" onclick="toggleZapisColumn()">Pokaż/Ukryj ZAPIS DB</span>
        </div>

        <?php if (empty($tableData)): ?>
            <div class="alert alert-warning">
                Brak wpisów do wyświetlenia dla podanych kryteriów.
            </div>
        <?php else: ?>
            <!-- Tabela z wynikami -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-primary">
                        <tr>
                            <th>Lp.</th>
                            <th>SAM</th>
                            <th>Imię i<br>Nazwisko</th>
                            <th>Dział</th>
                            <th>RFID</th>
                            <th>Status</th>
                            <th>SEPM-GATE</th>
                            <th>CARDLOG</th>
                            <th>GENEROWANE</th>
                            <th>PIERWOTNE</th>
                            <th>Obecność</th>
                            <th class="zapis-db-column" style="display: none;">ZAPIS DB</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableData as $i => $entry): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= renderFunction($entry['sepm_data']['sam_number']) ?></td>
                                <td>
                                    <?php if ($entry['rfid_data']): ?>
                                        <?= renderFunction($entry['rfid_data']['imie'] . ' ' . $entry['rfid_data']['nazwisko']) ?>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= renderFunction($entry['sepm_data']['department_name']) ?></td>
                                <td><?= renderFunction($entry['rfid_data']['numerrfid'] ?? null) ?></td>
                                <td>
                                    <?php if ($entry['rfid_data']): ?>
                                        <?php $isActive = $entry['rfid_data']['karta_aktywna'] == 1; ?>
                                        <?php if ($isActive): ?>
                                            <span class="badge bg-success">Aktywna</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Nieaktywna</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= renderFunction($entry['sepm_data']['sepm_entered_at'] ?? null, 'datetime') ?></td>
                                <td>
                                    <?php if ($entry['card_log_entry']): ?>
                                        <?= renderFunction($entry['card_log_entry']['entered_at_formatted'], 'datetime') ?>
                                        <small>(<?= renderFunction($entry['card_log_entry']['reader_name']) ?>)</small>
                                        <span class="badge-rf">RF<?= renderFunction($entry['card_log_entry']['ReaderFunction']) ?></span>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['fake_entry']): ?>
                                        <?= renderFunction($entry['fake_entry']['entered_at'], 'datetime') ?>
                                        <small>(<?= renderFunction($entry['fake_entry']['reader_name']) ?>)</small>
                                        <span class="badge-rf">RF<?= renderFunction($entry['fake_entry']['ReaderFunction']) ?></span>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['original_data']): ?>
                                        <span class="badge bg-info"><?= $entry['original_data']['entries_count'] ?> wpisy</span>
                                        <?= renderFunction($entry['original_data']['entered_at'], 'datetime') ?>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($entry['has_resignation']): ?>
                                        <span class="badge bg-danger">Rezygnacja: <?= !empty($entry['sepm_data']['sepm_left_at']) ? date('H:i:s', strtotime($entry['sepm_data']['sepm_left_at'])) : 'Brak czasu' ?></span>
                                        <?php if ($entry['is_present'] && !$entry['has_resignation']): ?>
                                            <br><span class="badge bg-success">Obecny</span>
                                        <?php else: ?>
                                            <br><span class="badge bg-warning text-dark">Nieobecny</span>
                                        <?php endif; ?>
                                    <?php elseif ($entry['is_present']): ?>
                                        <span class="badge bg-success">Obecny</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Nieobecny</span>
                                    <?php endif; ?>
                                </td>
                                <td class="zapis-db-column" style="display: none;">
                                    <?php if ($entry['zapis_db_data']): ?>
                                        <?= renderFunction($entry['zapis_db_data'], 'json') ?>
                                    <?php else: ?>
                                        <?= renderFunction(null) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Skrypt do przełączania widoczności kolumny ZAPIS DB -->
    <script>
        function toggleZapisColumn() {
            const columns = document.querySelectorAll('.zapis-db-column');
            columns.forEach(column => {
                column.style.display = column.style.display === 'none' ? '' : 'none';
            });
        }
    </script>
</body>
</html>