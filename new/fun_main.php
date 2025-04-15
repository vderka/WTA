<?php
/**
 * Funkcje pomocnicze systemu WTA - Część 1
 */

/**
 * Pobiera zamówienia pracowników z tabeli sepm_order_person
 * 
 * @param PDO $conn Połączenie z bazą MySQL
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param string $samNumber Opcjonalny numer SAM do filtrowania
 * @return array Tablica z zamówieniami pracowników
 */
function get_orders($conn, $startDate, $endDate, $samNumber = '') {
    // Warunek dla filtrowania po numerze SAM
    $samCondition = !empty($samNumber) ? "AND o.sam_number = :sam_number" : "";
    
    $query = "
        SELECT 
            o.id,
            o.sam_number, 
            o.name,
            o.surname,
            o.shift, 
            o.ordered_for, 
            o.department_id,
            o.entered_at,
            o.left_at,
            o.resignation,
            o.group_name,
            d.initial_name AS department_code,
            COALESCE(dn.depname, d.initial_name) AS department_name,
            s.start_time AS shift_start,
            s.end_time AS shift_end,
            c.display_name AS company_name,
            GROUP_CONCAT(DISTINCT CONCAT(b.start_time, '|', b.end_time, '|', b.ispaid) SEPARATOR ';') AS breaks
        FROM 
            sepm_order_person o
        LEFT JOIN 
            sepm_departments d ON o.department_id = d.id
        LEFT JOIN 
            sepm_company_def c ON d.company_owner_code = c.company_code
        LEFT JOIN 
            sepm_departments_names dn ON d.id = dn.department_id 
                AND o.ordered_for BETWEEN dn.begda AND COALESCE(dn.enda, '9999-12-31')
        LEFT JOIN 
            sepm_shifts_to_departments s ON o.department_id = s.departament_id 
                AND o.shift = s.shift 
                AND o.ordered_for BETWEEN COALESCE(s.begda, '0000-00-00') AND COALESCE(s.enda, '9999-12-31')
        LEFT JOIN 
            sepm_breaks_to_shifts b ON s.id = b.shift_id
        WHERE 
            o.ordered_for BETWEEN :start_date AND :end_date
            AND o.is_deleted = 0
            $samCondition
        GROUP BY 
            o.id, o.sam_number, o.shift, o.ordered_for, o.department_id
        ORDER BY 
            o.ordered_for, o.sam_number, o.shift
    ";

    $stmt = $conn->prepare($query);
    $params = [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ];
    
    if (!empty($samNumber)) {
        $params[':sam_number'] = $samNumber;
    }
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * Pobiera informacje o pracownikach i ich kartach RFID
 * Mapuje numery SAM i RFID do zaszyfrowanego numeru identyfikacyjnego
 * 
 * @param PDO $conn Połączenie z bazą YF
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param string $samNumber Opcjonalny numer SAM do filtrowania
 * @return array Tablica z informacjami o kartach RFID i pracownikach
 */
function get_rfid_info($conn, $startDate, $endDate, $samNumber = '') {
    // Warunek dla filtrowania po numerze SAM
    $samCondition = !empty($samNumber) ? "AND p.number_sam = :sam_number" : "";
    
    $query = "
        SELECT 
            p.pracownicysepmid,
            p.numer_identyfikacyjny,
            MD5(p.numer_identyfikacyjny) AS id_hash,
            p.number_sam,
            p.imie,
            p.nazwisko,
            p.karta_rfid,
            k.numerrfid,
            p.aktywacja_rfid,
            p.dezaktywacja_rfid,
            p.grupa_rfid,
            p.status_pracownika,
            p.spolka_zatrudnienie,
            p.projekt_sepm,
            m.company_name AS spolka_nazwa
        FROM 
            u_yf_pracownicysepm p
        LEFT JOIN 
            u_yf_kartyrfid k ON p.karta_rfid = k.kartyrfidid
        LEFT JOIN
            u_yf_multicompany m ON p.spolka_zatrudnienie = m.multicompanyid
        WHERE 
            (
                (p.aktywacja_rfid BETWEEN :start_date AND :end_date)
                OR 
                (p.aktywacja_rfid <= :end_date AND (p.dezaktywacja_rfid IS NULL OR p.dezaktywacja_rfid >= :start_date))
            )
            AND p.karta_rfid > 0
            $samCondition
        ORDER BY 
            p.number_sam
    ";

    $stmt = $conn->prepare($query);
    $params = [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ];
    
    if (!empty($samNumber)) {
        $params[':sam_number'] = $samNumber;
    }
    
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Zbuduj słownik mapujący karty RFID i numery SAM do zaszyfrowanych numerów identyfikacyjnych
    $employeeData = []; 
    $idHashMap = [];   // Mapowanie md5 hash -> dane pracownika
    $samToIdHash = []; // Mapowanie SAM -> md5 hash
    $rfidToIdHash = []; // Mapowanie RFID -> md5 hash
    
    foreach ($results as $row) {
        $idHash = $row['id_hash']; // MD5 z numeru identyfikacyjnego
        
        // Zapisz mapowanie: numer SAM -> hash ID
        $samToIdHash[$row['number_sam']] = $idHash;
        
        // Zapisz mapowanie: numer RFID -> hash ID
        if (!empty($row['numerrfid'])) {
            $rfidToIdHash[$row['numerrfid']] = $idHash;
        }
        
        // Zapisz pełne dane pracownika pod kluczem hash ID
        if (!isset($idHashMap[$idHash])) {
            $idHashMap[$idHash] = [
                'id_hash' => $idHash,
                'imie' => $row['imie'],
                'nazwisko' => $row['nazwisko'],
                'sam_numbers' => [],
                'rfid_cards' => []
            ];
        }
        
        // Dodaj numer SAM do listy dla tego pracownika
        if (!in_array($row['number_sam'], $idHashMap[$idHash]['sam_numbers'])) {
            $idHashMap[$idHash]['sam_numbers'][] = [
                'number_sam' => $row['number_sam'],
                'valid_from' => $row['date_of_employment'] ?? null,
                'valid_to' => $row['data_rezygnacji'] ?? null
            ];
        }
        
        // Dodaj kartę RFID do listy dla tego pracownika
        if (!empty($row['numerrfid']) && !array_filter($idHashMap[$idHash]['rfid_cards'], function($card) use ($row) {
            return $card['numerrfid'] === $row['numerrfid'];
        })) {
            $idHashMap[$idHash]['rfid_cards'][] = [
                'numerrfid' => $row['numerrfid'],
                'karta_rfid' => $row['karta_rfid'],
                'aktywacja_rfid' => $row['aktywacja_rfid'],
                'dezaktywacja_rfid' => $row['dezaktywacja_rfid']
            ];
        }
    }
    
    return [
        'employee_data' => $idHashMap,
        'sam_to_id' => $samToIdHash,
        'rfid_to_id' => $rfidToIdHash,
        'raw_data' => $results  // Zachowujemy oryginalne dane na potrzeby analizy
    ];
}

/**
 * Pobiera ręczne korekty odbić z tabeli sepm_fake_entries
 * 
 * @param PDO $conn Połączenie z bazą MySQL
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param array $cardIds Tablica z numerami kart RFID
 * @return array Tablica z korektami odbić
 */
function get_fake_entries($conn, $startDate, $endDate, $cardIds = []) {
    if (empty($cardIds)) {
        return [];
    }
    
    // Rozszerz daty o jedną zmianę (żeby złapać nocne odbicia)
    $startDateTime = date('Y-m-d H:i:s', strtotime($startDate . ' -1 day 18:00:00'));
    $endDateTime = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day 06:00:00'));
    
    // Przygotuj parametry dla kart RFID
    $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
    
    $query = "
        SELECT 
            LogID,
            CardID,
            ReadAt,
            Reader,
            ReaderFunction,
            LastActionBy,
            CreatedAt,
            UpdatedAt
        FROM 
            sepm_fake_entries
        WHERE 
            ReadAt BETWEEN ? AND ?
            AND CardID IN ($placeholders)
            AND is_deleted = 0
        ORDER BY 
            CardID, ReadAt
    ";
    
    $params = array_merge(
        [$startDateTime, $endDateTime],
        $cardIds
    );
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pobiera rzeczywiste odbicia kart z bazy SQL Server card_log
 * 
 * @param resource $conn Połączenie z bazą SQL Server
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param array $cardIds Tablica z numerami kart RFID
 * @return array Tablica z odbiciami kart
 */
function get_card_log_entries($conn, $startDate, $endDate, $cardIds = []) {
    if (empty($cardIds)) {
        return [];
    }
    
    // Rozszerz daty o jedną zmianę (żeby złapać nocne odbicia)
    $startDateTime = date('Y-m-d H:i:s', strtotime($startDate . ' -1 day 18:00:00'));
    $endDateTime = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day 06:00:00'));
    
    // Przygotuj parametry dla zapytania
    $placeholders = implode("','", $cardIds);
    
    $sql = "
        SELECT 
            LogID,
            CardID,
            ReadAt,
            Reader,
            ReaderFunction,
            CreatedAt,
            UpdatedAt
        FROM 
            [dbo].[card_log]
        WHERE 
            ReadAt BETWEEN ? AND ?
            AND CardID IN ('$placeholders')
        ORDER BY 
            CardID, ReadAt
    ";
    
    $params = [$startDateTime, $endDateTime];
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        throw new Exception("Błąd podczas pobierania odbić z card_log: " . print_r(sqlsrv_errors(), true));
    }
    
    $entries = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Konwertuj obiekt DateTime na string dla spójności z innymi źródłami danych
        if ($row['ReadAt'] instanceof DateTime) {
            $row['ReadAt'] = $row['ReadAt']->format('Y-m-d H:i:s');
        }
        if ($row['CreatedAt'] instanceof DateTime) {
            $row['CreatedAt'] = $row['CreatedAt']->format('Y-m-d H:i:s');
        }
        if ($row['UpdatedAt'] instanceof DateTime) {
            $row['UpdatedAt'] = $row['UpdatedAt']->format('Y-m-d H:i:s');
        }
        
        $entries[] = $row;
    }
    
    return $entries;
}

/**
 * Formatuje czas z zaokrągleniem do najbliższych 5 minut
 * 
 * @param string $time Czas w formacie Y-m-d H:i:s
 * @param string $direction Kierunek zaokrąglenia (up=w górę, down=w dół)
 * @return string Sformatowany czas w formacie Y-m-d H:i:s
 */
function format_time_with_rounding($time, $direction = 'up') {
    $timestamp = strtotime($time);
    $minutes = date('i', $timestamp);
    $seconds = date('s', $timestamp);
    
    // Oblicz, ile minut trzeba dodać lub odjąć, aby zaokrąglić do najbliższych 5 minut
    $remainder = $minutes % 5;
    
    if ($direction === 'up') {
        // Zaokrąglenie w górę (do następnych 5 minut)
        if ($remainder > 0 || $seconds > 0) {
            $addMinutes = 5 - $remainder;
            $timestamp = strtotime("+$addMinutes minutes", $timestamp);
        }
        // Wyzeruj sekundy
        $timestamp = strtotime(date('Y-m-d H:i:00', $timestamp));
    } else {
        // Zaokrąglenie w dół (do poprzednich 5 minut)
        if ($remainder > 0) {
            $subMinutes = $remainder;
            $timestamp = strtotime("-$subMinutes minutes", $timestamp);
        }
        // Wyzeruj sekundy
        $timestamp = strtotime(date('Y-m-d H:i:00', $timestamp));
    }
    
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Oblicza czas przerw (w minutach) na podstawie definicji przerw i faktycznego czasu pracy
 * 
 * @param array $breaks Definicje przerw
 * @param string $workStart Czas rozpoczęcia pracy
 * @param string $workEnd Czas zakończenia pracy
 * @param int $shift Zmiana
 * @return float Czas przerw w minutach
 */
function calculate_break_time($breaks, $workStart, $workEnd, $shift) {
    if (empty($breaks)) {
        return 0;
    }
    
    $totalBreakMinutes = 0;
    $workStartTime = strtotime($workStart);
    $workEndTime = strtotime($workEnd);
    
    // Dla drugiej zmiany, jeśli czas zakończenia jest mniejszy niż czas rozpoczęcia,
    // dodaj jeden dzień do czasu zakończenia
    if ($shift == 2 && $workEndTime < $workStartTime) {
        $workEndTime = strtotime('+1 day', $workEndTime);
    }
    
    foreach ($breaks as $break) {
        // Pomijamy płatne przerwy, ponieważ nie odejmujemy ich od czasu pracy
        if ($break['is_paid']) {
            continue;
        }
        
        // Pobierz czas rozpoczęcia i zakończenia przerwy
        $breakStartTime = strtotime(date('Y-m-d ', $workStartTime) . $break['start_time']);
        $breakEndTime = strtotime(date('Y-m-d ', $workStartTime) . $break['end_time']);
        
        // Dla przerw, które przekraczają północ (np. 23:30-00:30)
        if ($breakEndTime < $breakStartTime) {
            $breakEndTime = strtotime('+1 day', $breakEndTime);
        }
        
        // Sprawdź, czy przerwa nakłada się z czasem pracy
        if ($breakStartTime >= $workEndTime || $breakEndTime <= $workStartTime) {
            // Przerwa jest całkowicie poza czasem pracy
            continue;
        }
        
        // Oblicz faktyczny czas przerwy w ramach czasu pracy
        $effectiveBreakStart = max($breakStartTime, $workStartTime);
        $effectiveBreakEnd = min($breakEndTime, $workEndTime);
        $breakMinutes = ($effectiveBreakEnd - $effectiveBreakStart) / 60;
        
        $totalBreakMinutes += max(0, $breakMinutes);
    }
    
    return $totalBreakMinutes;
}

/**
 * Parsuje string z przerwami do tablicy obiektów
 * 
 * @param string $breaksStr String z przerwami w formacie "start|end|isPaid;start|end|isPaid"
 * @return array Tablica z przerwami
 */
function parse_breaks($breaksStr) {
    if (empty($breaksStr)) {
        return [];
    }
    
    $breaks = [];
    $breakItems = explode(';', $breaksStr);
    
    foreach ($breakItems as $item) {
        $parts = explode('|', $item);
        if (count($parts) === 3) {
            $breaks[] = [
                'start_time' => $parts[0],
                'end_time' => $parts[1],
                'is_paid' => (int)$parts[2]
            ];
        }
    }
    
    return $breaks;
}

/**
 * Znajduje odpowiednie odbicie karty na podstawie funkcji czytnika
 * 
 * @param array $entries Odbicia kart
 * @param int $readerFunction Funkcja czytnika (1=dział in, 2=dział out, 3=brama in, 4=brama out)
 * @param string $mode Tryb wyboru (min=najwcześniejsze, max=najpóźniejsze)
 * @return array|null Znalezione odbicie lub null
 */
function find_entry($entries, $readerFunction, $mode = 'min') {
    $filteredEntries = array_filter($entries, function($entry) use ($readerFunction) {
        return $entry['ReaderFunction'] == $readerFunction;
    });
    
    if (empty($filteredEntries)) {
        return null;
    }
    
    if ($mode === 'min') {
        // Znajdź najwcześniejsze odbicie
        return array_reduce($filteredEntries, function($carry, $item) {
            if ($carry === null || strtotime($item['ReadAt']) < strtotime($carry['ReadAt'])) {
                return $item;
            }
            return $carry;
        });
    } else {
        // Znajdź najpóźniejsze odbicie
        return array_reduce($filteredEntries, function($carry, $item) {
            if ($carry === null || strtotime($item['ReadAt']) > strtotime($carry['ReadAt'])) {
                return $item;
            }
            return $carry;
        });
    }
}

/**
 * Określa zmianę na podstawie czasu odbicia
 * 
 * @param string|null $timeStr Czas odbicia w formacie Y-m-d H:i:s
 * @return int Numer zmiany (1 lub 2)
 */
function determine_shift($timeStr) {
    if (!$timeStr) {
        return 1; // Domyślnie pierwsza zmiana
    }
    
    $hour = (int)date('H', strtotime($timeStr));
    
    // Jeśli godzina jest między 6:00 a 17:59, to pierwsza zmiana
    if ($hour >= 6 && $hour < 18) {
        return 1;
    } else {
        return 2;
    }
}