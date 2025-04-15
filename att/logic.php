<?php
/**
 * SEPM Attendance System - Logic File
 * Obsługuje przetwarzanie danych, połączenia z bazami danych i logikę biznesową
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/emp_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_time_set.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/sam_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/yf_config_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/semp_config.php';

/**
 * Inicjalizacja połączeń z bazami danych i pobranie danych
 */
function initializeAndLoadData() {
    global $emp_db_host, $emp_db_name, $emp_db_user, $emp_db_pass;
    global $yf_db_host, $yf_db_name, $yf_db_user, $yf_db_pass;
    global $serverName, $connectionOptions;
    global $SEPM_SETTINGS;
    
    // Parametry filtrowania
    $currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $currentShift = isset($_GET['shift']) ? (int)$_GET['shift'] : getCurrentShift(); // Używamy funkcji z sepm_config
    $companyOwnerCode = isset($_GET['company']) ? $_GET['company'] : null;
    $departmentId = isset($_GET['department']) ? (int)$_GET['department'] : null;
    $ignoreExits = isset($_GET['ignore_exits']) && $_GET['ignore_exits'] == '1';
    $showUnordered = true; // Zawsze pokazujemy niezamówionych pracowników

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

    // Pobierz listę wszystkich firm właścicieli
    $companiesQuery = "
        SELECT DISTINCT 
            company_owner_code
        FROM 
            sepm_departments
        WHERE 
            is_active = 1
            AND company_owner_code IS NOT NULL
        ORDER BY 
            company_owner_code
    ";
    $companiesStmt = $mysqlConn->prepare($companiesQuery);
    $companiesStmt->execute();
    $companies = $companiesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Warunek dla firmy właściciela
    $companyCondition = $companyOwnerCode ? "AND d.company_owner_code = :company_code" : "";

    // Pobierz listę działów, tylko te na które są zamówienia dla wybranej daty i zmiany
    $departmentsQuery = "
        SELECT 
            d.id, 
            d.company_owner_code,
            COALESCE(dn.depname, d.initial_name) AS department_name,
            (
                SELECT COUNT(DISTINCT o.sam_number) 
                FROM sepm_order_person o 
                WHERE o.department_id = d.id 
                AND o.shift = :shift_dept 
                AND o.ordered_for = :date_dept 
                AND o.is_deleted = 0
            ) AS employee_count,
            0 AS present_count
        FROM 
            sepm_departments d
        LEFT JOIN 
            sepm_departments_names dn ON d.id = dn.department_id 
                AND :selected_date BETWEEN dn.begda AND COALESCE(dn.enda, '9999-12-31')
        INNER JOIN (
            SELECT DISTINCT department_id 
            FROM sepm_order_person 
            WHERE shift = :shift_dept 
            AND ordered_for = :date_dept 
            AND is_deleted = 0
        ) AS active_depts ON d.id = active_depts.department_id
        WHERE 
            d.is_active = 1
            $companyCondition
        ORDER BY 
            department_name
    ";
    $departmentsStmt = $mysqlConn->prepare($departmentsQuery);
    $params = [
        ':shift_dept' => $currentShift,
        ':date_dept' => $currentDate,
        ':selected_date' => $currentDate
    ];

    if ($companyOwnerCode) {
        $params[':company_code'] = $companyOwnerCode;
    }

    $departmentsStmt->execute($params);
    $departments = $departmentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Dane pracowników zwracamy tylko gdy została wybrana firma lub dział
    $tableData = [];
    $unorderedEmployees = [];
    $departmentPresentCounts = []; // Liczba obecnych osób w działach

    if ($companyOwnerCode || $departmentId) {
        // Warunek dla działu
        $departmentCondition = $departmentId ? "AND o.department_id = :department_id" : "";

        // Pobranie danych o zamówionych pracownikach
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
                o.substitute,
                o.comment,
                d.initial_name AS department_code,
                d.company_owner_code,
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
                " . ($companyOwnerCode ? "AND d.company_owner_code = :company_code" : "") . "
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

        if ($companyOwnerCode) {
            $params[':company_code'] = $companyOwnerCode;
        }

        $stmt->execute($params);
        $orderPersonEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Grupuj wpisy według numeru SAM - bierzemy najnowsze wpisy
        $uniqueEntries = [];

        foreach ($orderPersonEntries as $entry) {
            $samNumber = $entry['sam_number'];
            
            // Dla unikalnych wpisów bierzemy najnowszy
            if (!isset($uniqueEntries[$samNumber]) || strtotime($entry['ordered_for']) >= strtotime($uniqueEntries[$samNumber]['ordered_for'])) {
                $uniqueEntries[$samNumber] = $entry;
            }
        }

        // Data końcowa dla zmiany 2 to następny dzień
        $endDate = $currentShift == 1 ? $currentDate : date('Y-m-d', strtotime("$currentDate +1 day"));
        $startDateTime = "$currentDate $shiftStartTime";
        $endDateTime = "$endDate $shiftEndTime";

        // Pobierz wszystkie karty RFID aby znaleźć niezamówionych pracowników jeśli potrzeba
        $allRfidCardsQuery = "
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
                p.spolka_zatrudnienie,
                m.company_name AS spolka_nazwa,
                CASE 
                    WHEN p.aktywacja_rfid <= ? AND (p.dezaktywacja_rfid IS NULL OR p.dezaktywacja_rfid >= ?) 
                    THEN 1 
                    ELSE 0 
                END AS karta_aktywna
            FROM 
                u_yf_pracownicysepm p
            LEFT JOIN 
                u_yf_kartyrfid k ON p.karta_rfid = k.kartyrfidid
            LEFT JOIN
                u_yf_multicompany m ON p.spolka_zatrudnienie = m.multicompanyid
            WHERE 
                k.numerrfid IS NOT NULL
                " . ($companyOwnerCode ? "AND m.company_name = ?" : "") . "
        ";

        $params = [$currentDate, $currentDate];
        if ($companyOwnerCode) {
            $params[] = $companyOwnerCode;
        }

        $stmt = $yfConn->prepare($allRfidCardsQuery);
        $stmt->execute($params);
        $allRfidInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Indeksuj informacje o RFID po numerrfid i numer_sam
        $rfidByCardNumber = [];
        $rfidBySamNumber = [];

        foreach ($allRfidInfo as $info) {
            if (!empty($info['numerrfid'])) {
                $rfidByCardNumber[$info['numerrfid']] = $info;
            }
            if (!empty($info['numer_sam'])) {
                $rfidBySamNumber[$info['numer_sam']] = $info;
            }
        }

        // Jeśli mamy zamówione osoby, przetwórz ich dane
        if (!empty($uniqueEntries)) {
            // Przygotuj parametry do zapytania YF
            $samNumbers = array_keys($uniqueEntries);
            $placeholders = implode(',', array_fill(0, count($samNumbers), '?'));

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
                    p.spolka_zatrudnienie,
                    m.company_name AS spolka_nazwa,
                    CASE 
                        WHEN p.aktywacja_rfid <= ? AND (p.dezaktywacja_rfid IS NULL OR p.dezaktywacja_rfid >= ?) 
                        THEN 1 
                        ELSE 0 
                    END AS karta_aktywna
                FROM 
                    u_yf_pracownicysepm p
                LEFT JOIN 
                    u_yf_kartyrfid k ON p.karta_rfid = k.kartyrfidid
                LEFT JOIN
                    u_yf_multicompany m ON p.spolka_zatrudnienie = m.multicompanyid
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
            
            // Przetwórz dane dla tabeli
            foreach ($uniqueEntries as $samNumber => $sepmData) {
                // Dane podstawowe
                $rfidData = $rfidInfoDict[$samNumber] ?? null;
                $rfidCard = $rfidData['numerrfid'] ?? null;
                
                // Pobierz dane CardLog dla wejść (ReaderFunction 1, 3)
                $cardLogEntries = getCardLogData($sqlsrv_conn, $rfidCard, $startDateTime, $endDateTime, [1, 3]);
                
                // Pobierz dane CardLog dla wyjść (ReaderFunction 2, 4)
                $cardLogExits = getCardLogData($sqlsrv_conn, $rfidCard, $startDateTime, $endDateTime, [2, 4]);
                
                // Pobierz fałszywe wpisy dla wejść (ReaderFunction 1, 3)
                $fakeEntries = getFakeEntries($mysqlConn, $rfidCard, $startDateTime, $endDateTime, [1, 3]);
                
                // Pobierz fałszywe wpisy dla wyjść (ReaderFunction 2, 4)
                $fakeExits = getFakeEntries($mysqlConn, $rfidCard, $startDateTime, $endDateTime, [2, 4]);
                
                // Określ statusy obecności
                $attendanceStatus = determineAttendanceStatus(
                    $sepmData['sepm_entered_at'], 
                    $sepmData['sepm_left_at'], 
                    $cardLogEntries, 
                    $cardLogExits, 
                    $fakeEntries, 
                    $fakeExits, 
                    $sepmData['resignation'],
                    $ignoreExits
                );
                
                // Dodaj wpis do danych tabeli
                $tableData[] = [
                    'sepm_data' => $sepmData,
                    'rfid_data' => $rfidData,
                    'card_log_entries' => $cardLogEntries,
                    'card_log_exits' => $cardLogExits,
                    'fake_entries' => $fakeEntries,
                    'fake_exits' => $fakeExits,
                    'attendance_status' => $attendanceStatus
                ];
                
                // Zwiększ licznik obecnych osób dla danego działu
                if ($attendanceStatus == 'present' || $attendanceStatus == 'probable') {
                    $deptId = $sepmData['department_id'];
                    if (!isset($departmentPresentCounts[$deptId])) {
                        $departmentPresentCounts[$deptId] = 0;
                    }
                    $departmentPresentCounts[$deptId]++;
                }
            }
        }

        // Pobierz niezamówionych pracowników, którzy mają odbicia w CardLog, tylko jeśli przycisk został kliknięty
        if ($showUnordered) {
            // Pobierz wszystkie odbicia CardLog dla danego okresu
            $allCardLogsQuery = "
                SELECT 
                    CardID,
                    ReadAt,
                    Reader,
                    ReaderFunction
                FROM 
                    [dbo].[card_log]
                WHERE 
                    ReadAt >= ? 
                    AND ReadAt <= ?
                    AND ReaderFunction IN (1, 3)
                ORDER BY 
                    CardID, ReadAt ASC
            ";
            
            $cardLogStmt = sqlsrv_query($sqlsrv_conn, $allCardLogsQuery, [$startDateTime, $endDateTime]);
            
            if ($cardLogStmt) {
                $cardLogs = [];
                while ($row = sqlsrv_fetch_array($cardLogStmt, SQLSRV_FETCH_ASSOC)) {
                    if ($row['ReadAt'] instanceof DateTime) {
                        $row['ReadAt'] = $row['ReadAt']->format('Y-m-d H:i:s');
                    }
                    $cardLogs[] = $row;
                }
                
                // Grupuj odbicia według CardID
                $cardLogsByCardID = [];
                foreach ($cardLogs as $log) {
                    if (!isset($cardLogsByCardID[$log['CardID']])) {
                        $cardLogsByCardID[$log['CardID']] = [];
                    }
                    $cardLogsByCardID[$log['CardID']][] = $log;
                }
                
                // Sprawdź, którzy pracownicy z odbiciami nie są zamówieni
                foreach ($cardLogsByCardID as $cardID => $logs) {
                    // Pobierz informacje o pracowniku na podstawie CardID
                    $rfidInfo = $rfidByCardNumber[$cardID] ?? null;
                    
                    if ($rfidInfo) {
                        $samNumber = $rfidInfo['numer_sam'];
                        
                        // Sprawdź, czy pracownik jest już zamówiony
                        if (!isset($uniqueEntries[$samNumber])) {
                            // Znajdź pierwszy wpis (wejście)
                            $firstEntry = null;
                            foreach ($logs as $log) {
                                if ($log['ReaderFunction'] == 1 || $log['ReaderFunction'] == 3) {
                                    $firstEntry = $log;
                                    break;
                                }
                            }
                            
                            if ($firstEntry) {
                                // Pobierz wyjścia dla tego pracownika
                                $exits = getCardLogData($sqlsrv_conn, $cardID, $startDateTime, $endDateTime, [2, 4]);
                                
                                // Pobierz fałszywe wpisy
                                $fakeEntriesData = getFakeEntries($mysqlConn, $cardID, $startDateTime, $endDateTime, [1, 3]);
                                $fakeExitsData = getFakeEntries($mysqlConn, $cardID, $startDateTime, $endDateTime, [2, 4]);
                                
                                // Określ status obecności
                                $attendanceStatus = determineAttendanceStatus(
                                    null, null, $logs, $exits, $fakeEntriesData, $fakeExitsData, 0, $ignoreExits
                                );
                                
                                // Dodaj niezamówionego pracownika
                                $unorderedEmployees[] = [
                                    'rfid_data' => $rfidInfo,
                                    'card_log_entries' => $logs,
                                    'card_log_exits' => $exits,
                                    'fake_entries' => $fakeEntriesData,
                                    'fake_exits' => $fakeExitsData,
                                    'attendance_status' => $attendanceStatus,
                                    'is_unordered' => true
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        // Aktualizuj liczniki obecnych osób w tabelach działów
        foreach ($departments as &$dept) {
            $dept['present_count'] = $departmentPresentCounts[$dept['id']] ?? 0;
        }
    }

    // Zwróć wszystkie potrzebne dane
    return [
        'mysqlConn' => $mysqlConn,
        'yfConn' => $yfConn,
        'sqlsrv_conn' => $sqlsrv_conn,
        'currentDate' => $currentDate,
        'currentShift' => $currentShift,
        'shiftStartTime' => $shiftStartTime,
        'shiftEndTime' => $shiftEndTime,
        'companyOwnerCode' => $companyOwnerCode,
        'departmentId' => $departmentId,
        'ignoreExits' => $ignoreExits,
        'showUnordered' => $showUnordered,
        'companies' => $companies,
        'departments' => $departments,
        'tableData' => $tableData,
        'unorderedEmployees' => $unorderedEmployees
    ];
}

/**
 * Funkcja do pobrania danych CardLog
 */
function getCardLogData($conn, $cardID, $startDateTime, $endDateTime, $readerFunctions) {
    if (!$cardID) {
        return [];
    }
    
    $readerFunctionsStr = implode(',', (array)$readerFunctions);
    
    $query = "
        SELECT 
            LogID,
            CardID,
            ReadAt AS read_at, 
            Reader AS reader_name,
            ReaderFunction
        FROM 
            [dbo].[card_log]
        WHERE 
            CardID = ? 
            AND ReadAt >= ? 
            AND ReadAt <= ?
            AND ReaderFunction IN ($readerFunctionsStr)
        ORDER BY 
            ReadAt ASC
    ";
    
    $params = [
        $cardID,
        $startDateTime,
        $endDateTime
    ];
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    $entries = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Formatuj datę
            if ($row['read_at'] instanceof DateTime) {
                $row['read_at_formatted'] = $row['read_at']->format('Y-m-d H:i:s');
            } else {
                $row['read_at_formatted'] = $row['read_at'];
            }
            
            $entries[] = $row;
        }
    }
    
    return $entries;
}

/**
 * Funkcja do pobrania fałszywych wpisów
 */
function getFakeEntries($conn, $cardID, $startDateTime, $endDateTime, $readerFunctions) {
    if (!$cardID) {
        return [];
    }
    
    $readerFunctionsStr = implode(',', (array)$readerFunctions);
    
    $query = "
        SELECT 
            LogID,
            CardID,
            ReadAt AS read_at, 
            Reader AS reader_name,
            ReaderFunction,
            LastActionBy
        FROM 
            sepm_fake_entries
        WHERE 
            CardID = ? 
            AND ReadAt >= ? 
            AND ReadAt <= ?
            AND ReaderFunction IN ($readerFunctionsStr)
            AND is_deleted = 0
        ORDER BY 
            ReadAt ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        $cardID,
        $startDateTime,
        $endDateTime
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Funkcja do określania statusu obecności z uwzględnieniem priorytetów
 * UWAGA: Fałszywe wpisy mają priorytet nad innymi!
 */
function determineAttendanceStatus($sepmEnteredAt, $sepmLeftAt, $cardLogEntries, $cardLogExits, $fakeEntries, $fakeExits, $resignation, $ignoreExits) {
    // Sprawdź, czy jest rezygnacja
    if ($resignation == 1) {
        return 'absent'; // Nieobecny z powodu rezygnacji
    }
    
    // Sprawdź wejścia i wyjścia z podziałem na ReaderFunction i priorytetyzacją
    
    // Wejścia - najpierw sprawdzamy FAKE entries, potem ReaderFunction 3 (BRAMA), potem 1 (DZIAŁ)
    $hasFakeGateEntry = false;
    $hasFakeDepartmentEntry = false;
    $hasCardGateEntry = false;
    $hasCardDepartmentEntry = false;
    
    // Sprawdź wejścia w Fake Entries - PRIORYTET #1
    foreach ($fakeEntries as $entry) {
        if ($entry['ReaderFunction'] == 3) {
            $hasFakeGateEntry = true;
        } else if ($entry['ReaderFunction'] == 1) {
            $hasFakeDepartmentEntry = true;
        }
    }
    
    // Sprawdź wejścia w CardLog - PRIORYTET #2
    foreach ($cardLogEntries as $entry) {
        if ($entry['ReaderFunction'] == 3) {
            $hasCardGateEntry = true;
        } else if ($entry['ReaderFunction'] == 1) {
            $hasCardDepartmentEntry = true;
        }
    }
    
    $hasSepmEntry = !empty($sepmEnteredAt);
    
    // Wyjścia - najpierw sprawdzamy FAKE entries, potem ReaderFunction 4 (BRAMA), potem 2 (DZIAŁ)
    $hasFakeGateExit = false;
    $hasFakeDepartmentExit = false;
    $hasCardGateExit = false;
    $hasCardDepartmentExit = false;
    
    // Sprawdź wyjścia w Fake Entries - PRIORYTET #1
    foreach ($fakeExits as $exit) {
        if ($exit['ReaderFunction'] == 4) {
            $hasFakeGateExit = true;
        } else if ($exit['ReaderFunction'] == 2) {
            $hasFakeDepartmentExit = true;
        }
    }
    
    // Sprawdź wyjścia w CardLog - PRIORYTET #2
    foreach ($cardLogExits as $exit) {
        if ($exit['ReaderFunction'] == 4) {
            $hasCardGateExit = true;
        } else if ($exit['ReaderFunction'] == 2) {
            $hasCardDepartmentExit = true;
        }
    }
    
    $hasSepmExit = !empty($sepmLeftAt);
    
    // Jeśli ignorujemy wyjścia, to uznajemy, że nie ma wyjścia
    if ($ignoreExits) {
        $hasFakeGateExit = false;
        $hasFakeDepartmentExit = false;
        $hasCardGateExit = false;
        $hasCardDepartmentExit = false;
        $hasSepmExit = false;
    }
    
    // Określ status obecności zgodnie z wymaganiami logicznymi
    
    // OBECNY: 
    // (entered_at IS NOT NULL OR cardlog_in IS NOT NULL) 
    // AND (left_at IS NULL AND cardlog_out IS NULL)
    // AND resignation = 0
    if (($hasSepmEntry || $hasFakeGateEntry || $hasFakeDepartmentEntry || $hasCardGateEntry || $hasCardDepartmentEntry) && 
        !$hasSepmExit && !$hasFakeGateExit && !$hasFakeDepartmentExit && !$hasCardGateExit && !$hasCardDepartmentExit && 
        !$resignation) {
        return 'present';
    }
    
    // NIEOBECNY:
    // resignation = 1
    // OR left_at IS NOT NULL 
    // OR cardlog_out IS NOT NULL
    // OR (entered_at IS NULL AND cardlog_in IS NULL)
    // OR generowane reader function 2 or 4
    if ($resignation || 
        $hasSepmExit || $hasFakeGateExit || $hasFakeDepartmentExit || $hasCardGateExit || $hasCardDepartmentExit || 
        (!$hasSepmEntry && !$hasFakeGateEntry && !$hasFakeDepartmentEntry && !$hasCardGateEntry && !$hasCardDepartmentEntry)) {
        return 'absent';
    }
    
    // PRAWDOPODOBNIE OBECNY:
    // (entered_at IS NOT NULL AND cardlog_IN IS NULL) 
    // AND (left_at IS NULL AND cardlog_out IS NULL)
    // AND resignation = 0
    if ($hasSepmEntry && !$hasFakeGateEntry && !$hasFakeDepartmentEntry && !$hasCardGateEntry && !$hasCardDepartmentEntry && 
        !$hasSepmExit && !$hasFakeGateExit && !$hasFakeDepartmentExit && !$hasCardGateExit && !$hasCardDepartmentExit && 
        !$resignation) {
        return 'probable';
    }
    
    // Domyślnie, jeśli nie pasuje do żadnego z powyższych warunków
    return 'absent';
}

/**
 * Zwraca najlepsze dostępne wejście dla pracownika z wybranych źródeł
 * Priorytet: 1. Generowane (Fake) 2. Brama 3. Dział
 */
function getBestEntrySource($fakeEntries, $cardLogEntries, $type = 'gate') {
    $readerFunction = ($type == 'gate') ? 3 : 1;
    
    // Najpierw szukaj w Fake Entries - PRIORYTET #1
    foreach ($fakeEntries as $entry) {
        if ($entry['ReaderFunction'] == $readerFunction) {
            $entry['source'] = 'fake';
            return $entry;
        }
    }
    
    // Jeśli nie znaleziono, szukaj w CardLog - PRIORYTET #2
    foreach ($cardLogEntries as $entry) {
        if ($entry['ReaderFunction'] == $readerFunction) {
            $entry['source'] = 'cardlog';
            return $entry;
        }
    }
    
    return null;
}

/**
 * Zwraca najlepsze dostępne wyjście dla pracownika z wybranych źródeł
 * Priorytet: 1. Generowane (Fake) 2. Brama 3. Dział
 */
function getBestExitSource($fakeExits, $cardLogExits, $type = 'gate') {
    $readerFunction = ($type == 'gate') ? 4 : 2;
    
    // Najpierw szukaj w Fake Entries - PRIORYTET #1
    foreach ($fakeExits as $exit) {
        if ($exit['ReaderFunction'] == $readerFunction) {
            $exit['source'] = 'fake';
            return $exit;
        }
    }
    
    // Jeśli nie znaleziono, szukaj w CardLog - PRIORYTET #2
    foreach ($cardLogExits as $exit) {
        if ($exit['ReaderFunction'] == $readerFunction) {
            $exit['source'] = 'cardlog';
            return $exit;
        }
    }
    
    return null;
}

/**
 * Funkcja renderująca wartości
 */
function renderFunction($value, $type = 'text') {
    if (empty($value) || $value === null) {
        return '<span class="text-muted">Brak</span>';
    }
    
    switch ($type) {
        case 'date':
            return date('Y-m-d', strtotime($value));
        case 'datetime':
            return date('Y-m-d H:i:s', strtotime($value));
        case 'time':
            return date('H:i:s', strtotime($value));
        case 'bool':
            return $value ? '<span class="badge bg-success">Tak</span>' : '<span class="badge bg-danger">Nie</span>';
        case 'active':
            return $value ? '<span class="badge bg-success">Aktywna</span>' : '<span class="badge bg-danger">Nieaktywna</span>';
        case 'attendance':
            if ($value == 'present') {
                return '<span class="badge bg-success">Obecny</span>';
            } elseif ($value == 'absent') {
                return '<span class="badge bg-danger">Nieobecny</span>';
            } else {
                return '<span class="badge bg-warning text-dark">Prawdopodobnie obecny</span>';
            }
        case 'json':
            return '<pre class="m-0 p-0" style="font-size: 11px;">' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        default:
            return htmlspecialchars($value);
    }
}

/**
 * Funkcja do renderowania statusu obecności z odpowiednim stylem
 */
function renderAttendanceStatus($status) {
    switch ($status) {
        case 'present':
            return '<span class="badge bg-success">Obecny</span>';
        case 'absent':
            return '<span class="badge bg-danger">Nieobecny</span>';
        case 'probable':
            return '<span class="badge bg-warning text-dark">Prawdopodobnie obecny</span>';
        default:
            return '<span class="badge bg-secondary">Nieznany</span>';
    }
}

/**
 * Formatuje datę w czytelnym formacie
 */
function formatDate($date) {
    if (empty($date)) {
        return 'Brak daty';
    }
    
    return date('d.m.Y', strtotime($date));
}

/**
 * Formatuje czas w czytelnym formacie
 */
function formatTime($time) {
    if (empty($time)) {
        return 'Brak czasu';
    }
    
    return date('H:i', strtotime($time));
}

/**
 * Formatuje datę i czas w czytelnym formacie
 */
function formatDateTime($datetime) {
    if (empty($datetime)) {
        return 'Brak daty/czasu';
    }
    
    return date('d.m.Y H:i:s', strtotime($datetime));
}

/**
 * Konwertuje wartość MD5 numeru identyfikacyjnego
 * Używane do maskowania danych osobowych w eksporcie
 */
function md5IdentityNumber($number) {
    if (empty($number)) {
        return '';
    }
    
    return md5($number);
}

/**
 * Eksportuje dane do formatu Excel
 * Nie implementujemy pełnej funkcjonalności w tym pliku
 */
function exportToExcel($tableData, $unorderedEmployees, $currentDate, $currentShift, $departmentName) {
    // Implementacja eksportu Excel będzie dodana w przyszłości
    return true;
}