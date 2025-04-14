<?php
/**
 * Funkcje pomocnicze systemu WTA - Część 2
 * Zawiera zaawansowane funkcje do obliczania czasu pracy i zarządzania zadaniami
 */

/**
 * Oblicza czas pracy na podstawie wszystkich źródeł danych
 * 
 * @param array $orders Zamówienia na pracowników
 * @param array $rfidInfo Informacje o kartach RFID i pracownikach
 * @param array $fakeEntries Ręczne korekty odbić
 * @param array $cardLogEntries Rzeczywiste odbicia kart
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @return array Wyniki obliczeń czasu pracy
 */
function calculate_worktime($orders, $rfidInfo, $fakeEntries, $cardLogEntries, $startDate, $endDate) {
    $results = [];
    
    // Strukturyzacja odbić według numeru karty i daty
    $structuredEntries = structure_entries($fakeEntries, $cardLogEntries, $startDate, $endDate);
    
    // Dla każdego zamówienia, znajdź odpowiednie odbicia i oblicz czas pracy
    foreach ($orders as $order) {
        $orderDate = $order['ordered_for'];
        $shift = $order['shift'];
        $samNumber = $order['sam_number'];
        
        // Znajdź odpowiedniego pracownika i jego kartę RFID
        $employeeInfo = find_employee_by_sam($rfidInfo, $samNumber, $orderDate);
        
        if (!$employeeInfo) {
            // Jeśli nie znaleziono pracownika, dodaj informację bez odbić
            $results[] = create_worktime_entry_without_swipes($order, null, $orderDate);
            continue;
        }
        
        $idHash = $employeeInfo['id_hash'];
        $cardRfid = null;
        
        // Znajdź aktualną kartę RFID dla danej daty
        foreach ($employeeInfo['rfid_cards'] as $card) {
            $activationDate = $card['aktywacja_rfid'] ?? null;
            $deactivationDate = $card['dezaktywacja_rfid'] ?? null;
            
            if (
                (!$activationDate || $activationDate <= $orderDate) && 
                (!$deactivationDate || $deactivationDate >= $orderDate)
            ) {
                $cardRfid = $card['numerrfid'];
                break;
            }
        }
        
        if (!$cardRfid) {
            // Jeśli pracownik nie ma aktywnej karty na daną datę, dodaj informację bez odbić
            $results[] = create_worktime_entry_without_swipes($order, $employeeInfo, $orderDate);
            continue;
        }
        
        // Znajdź odpowiednie odbicia dla tej karty i daty
        $dayEntries = $structuredEntries[$cardRfid][$orderDate] ?? [];
        
        // Oblicz czas pracy na podstawie odbić
        $worktimeEntry = calculate_worktime_for_day($order, $employeeInfo, $dayEntries, $cardRfid, $orderDate, $shift);
        
        $results[] = $worktimeEntry;
    }
    
    // Dodaj niestandardowe wpisy dla pracowników, którzy mają odbicia, ale nie są zamówieni
    add_unordered_employees($results, $orders, $rfidInfo, $structuredEntries, $startDate, $endDate);
    
    return $results;
}

/**
 * Strukturyzuje odbicia według numeru karty i daty
 * 
 * @param array $fakeEntries Ręczne korekty odbić
 * @param array $cardLogEntries Rzeczywiste odbicia kart
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @return array Strukturyzowane odbicia
 */
function structure_entries($fakeEntries, $cardLogEntries, $startDate, $endDate) {
    $structuredEntries = [];
    
    // Zainicjalizuj strukturę dla każdego dnia w zakresie dat
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($current <= $end) {
        $currentDate = $current->format('Y-m-d');
        $structuredEntries['dates'][] = $currentDate;
        $current->modify('+1 day');
    }
    
    // Priorytet 1: Dodaj ręczne korekty odbić
    foreach ($fakeEntries as $entry) {
        $cardId = $entry['CardID'];
        $readAt = $entry['ReadAt'];
        $readDate = date('Y-m-d', strtotime($readAt));
        $readerFunction = $entry['ReaderFunction'];
        
        if (!isset($structuredEntries[$cardId])) {
            $structuredEntries[$cardId] = [];
        }
        
        if (!isset($structuredEntries[$cardId][$readDate])) {
            $structuredEntries[$cardId][$readDate] = [];
        }
        
        $entry['source'] = 'GENEROWANE';
        $structuredEntries[$cardId][$readDate][] = $entry;
    }
    
    // Priorytet 2: Dodaj rzeczywiste odbicia kart
    foreach ($cardLogEntries as $entry) {
        $cardId = $entry['CardID'];
        $readAt = $entry['ReadAt'];
        $readDate = date('Y-m-d', strtotime($readAt));
        $readerFunction = $entry['ReaderFunction'];
        
        // Jeśli dla tej karty i daty są już ręczne korekty odbić o takiej samej funkcji,
        // nie dodawaj rzeczywistego odbicia
        if (
            isset($structuredEntries[$cardId][$readDate]) &&
            array_filter($structuredEntries[$cardId][$readDate], function($e) use ($readerFunction) {
                return $e['ReaderFunction'] == $readerFunction && $e['source'] === 'GENEROWANE';
            })
        ) {
            continue;
        }
        
        if (!isset($structuredEntries[$cardId])) {
            $structuredEntries[$cardId] = [];
        }
        
        if (!isset($structuredEntries[$cardId][$readDate])) {
            $structuredEntries[$cardId][$readDate] = [];
        }
        
        $entry['source'] = 'CARDLOG';
        $structuredEntries[$cardId][$readDate][] = $entry;
    }
    
    // Sortuj odbicia według czasu dla każdej karty i daty
    foreach ($structuredEntries as $cardId => &$dates) {
        if ($cardId === 'dates') continue;
        
        foreach ($dates as &$entries) {
            usort($entries, function($a, $b) {
                return strtotime($a['ReadAt']) - strtotime($b['ReadAt']);
            });
        }
    }
    
    return $structuredEntries;
}

/**
 * Znajduje pracownika na podstawie numeru SAM
 * 
 * @param array $rfidInfo Informacje o kartach RFID i pracownikach
 * @param string $samNumber Numer SAM
 * @param string $date Data, dla której szukamy pracownika
 * @return array|null Informacje o pracowniku lub null
 */
function find_employee_by_sam($rfidInfo, $samNumber, $date) {
    // Znajdź hash ID na podstawie numeru SAM
    $idHash = $rfidInfo['sam_to_id'][$samNumber] ?? null;
    
    if (!$idHash) {
        return null;
    }
    
    // Zwróć dane pracownika
    return $rfidInfo['employee_data'][$idHash] ?? null;
}

/**
 * Tworzy wpis o czasie pracy dla pracownika bez odbić
 * 
 * @param array $order Zamówienie na pracownika
 * @param array|null $employeeInfo Informacje o pracowniku
 * @param string $date Data
 * @return array Wpis o czasie pracy
 */
function create_worktime_entry_without_swipes($order, $employeeInfo, $date) {
    $employeeName = $employeeInfo ? ($employeeInfo['imie'] . ' ' . $employeeInfo['nazwisko']) : 
                            ($order['name'] . ' ' . $order['surname']);
    $idHash = $employeeInfo ? $employeeInfo['id_hash'] : md5($order['sam_number']);
    
    return [
        'id_hash' => $idHash,
        'date' => $date,
        'card_rfid' => '',
        'sam_number' => $order['sam_number'],
        'employee_name' => $employeeName,
        'gate_in' => $order['entered_at'] ?? null,
        'gate_out' => $order['left_at'] ?? null,
        'work_start' => $order['entered_at'] ?? null,
        'work_end' => $order['left_at'] ?? null,
        'work_minutes' => 0,
        'deducted_minutes' => 0,
        'entry_reader' => '',
        'department' => $order['department_name'] ?? 'Niezdefiniowany',
        'data_source' => 'SEPM-GATE',
        'shift' => $order['shift'],
        'resignation' => $order['resignation'],
        'is_present' => false
    ];
}

/**
 * Oblicza czas pracy dla jednego dnia na podstawie odbić
 * 
 * @param array $order Zamówienie na pracownika
 * @param array $employeeInfo Informacje o pracowniku
 * @param array $dayEntries Odbicia dla danego dnia
 * @param string $cardRfid Numer karty RFID
 * @param string $date Data
 * @param int $shift Zmiana
 * @return array Wpis o czasie pracy
 */
function calculate_worktime_for_day($order, $employeeInfo, $dayEntries, $cardRfid, $date, $shift) {
    // Domyślne godziny rozpoczęcia i zakończenia pracy dla zmian
    $defaultShiftStart = $shift == 1 ? '06:00:00' : '18:00:00';
    $defaultShiftEnd = $shift == 1 ? '18:00:00' : '06:00:00';
    $nextDay = $shift == 2 ? date('Y-m-d', strtotime($date . ' +1 day')) : $date;
    
    // Godziny zmiany z zamówienia (jeśli dostępne)
    $shiftStart = $order['shift_start'] ?? $defaultShiftStart;
    $shiftEnd = $order['shift_end'] ?? $defaultShiftEnd;
    
    // Przerwy z zamówienia (jeśli dostępne)
    $breaks = parse_breaks($order['breaks'] ?? '');
    
    // Poszukaj odbić dla wyznaczenia czasookresu
    $gateIn = find_entry($dayEntries, 3, 'min'); // 3 = brama wejście (gate in)
    $gateOut = find_entry($dayEntries, 4, 'max'); // 4 = brama wyjście (gate out)
    
    // Jeśli nie ma odbicia gate-in lub gate-out, użyj danych z sepm_order_person
    if (!$gateIn && !$gateOut) {
        return create_worktime_entry_without_swipes($order, $employeeInfo, $date);
    }
    
    // Poszukaj odbić dla wyznaczenia faktycznego czasu pracy
    $dzialIn = find_entry($dayEntries, 1, 'min'); // 1 = dział wejście
    $dzialOut = find_entry($dayEntries, 2, 'max'); // 2 = dział wyjście
    
    // Korekty odbić
    $entrySource = 'CARDLOG';
    
    // Korekta 1: Jeśli nie ma dział-in, użyj gate-in jako dział-in
    if (!$dzialIn) {
        $dzialIn = $gateIn;
        $entrySource = 'CARDLOG';
    }
    
    // Korekta 2: Jeśli nie ma dział-out, użyj gate-out jako dział-out
    if (!$dzialOut) {
        $dzialOut = $gateOut;
        $entrySource = 'CARDLOG';
    }
    
    // Sprawdź, czy odbicia są z sepm_fake_entries
    if ($dzialIn && $dzialIn['source'] === 'GENEROWANE') {
        $entrySource = 'GENEROWANE';
    }
    
    // Sprawdź, czy pracownik zrezygnował
    $resignation = $order['resignation'] ?? 0;
    $isPresent = ($dzialIn && $dzialOut) && $resignation == 0;
    
    // Pomiń niepełne odbicia, jeśli pracownik zrezygnował
    if ($resignation == 1) {
        $isPresent = false;
    }
    
    // Zaokrąglij czas rozpoczęcia i zakończenia pracy
    $workStart = $dzialIn ? format_time_with_rounding($dzialIn['ReadAt'], 'up') : null;
    $workEnd = $dzialOut ? format_time_with_rounding($dzialOut['ReadAt'], 'down') : null;
    
    // Oblicz czas pracy w minutach
    $workMinutes = 0;
    $deductedMinutes = 0;
    
    if ($workStart && $workEnd) {
        $startTime = strtotime($workStart);
        $endTime = strtotime($workEnd);
        
        // Dla drugiej zmiany, jeśli czas zakończenia jest mniejszy niż czas rozpoczęcia,
        // dodaj jeden dzień do czasu zakończenia
        if ($shift == 2 && $endTime < $startTime) {
            $endTime = strtotime('+1 day', $endTime);
        }
        
        // Oblicz całkowity czas w minutach
        $workMinutes = ($endTime - $startTime) / 60;
        
        // Odejmij czas przerw
        $deductedMinutes = calculate_break_time($breaks, $workStart, $workEnd, $shift);
        $workMinutes -= $deductedMinutes;
    }
    
    // Tworzenie wyniku
    return [
        'id_hash' => $employeeInfo['id_hash'],
        'date' => $date,
        'card_rfid' => $cardRfid,
        'sam_number' => $order['sam_number'],
        'employee_name' => $employeeInfo['imie'] . ' ' . $employeeInfo['nazwisko'],
        'gate_in' => $gateIn ? $gateIn['ReadAt'] : null,
        'gate_out' => $gateOut ? $gateOut['ReadAt'] : null,
        'work_start' => $workStart,
        'work_end' => $workEnd,
        'work_minutes' => max(0, round($workMinutes)),
        'deducted_minutes' => round($deductedMinutes),
        'entry_reader' => $dzialIn ? $dzialIn['Reader'] : '',
        'department' => $order['department_name'] ?? 'Niezdefiniowany',
        'data_source' => $entrySource,
        'shift' => $shift,
        'resignation' => $resignation,
        'is_present' => $isPresent
    ];
}

/**
 * Dodaje wpisy dla pracowników, którzy mają odbicia, ale nie są zamówieni
 * 
 * @param array &$results Tablica wyników (modyfikowana przez referencję)
 * @param array $orders Zamówienia na pracowników
 * @param array $rfidInfo Informacje o kartach RFID i pracownikach
 * @param array $structuredEntries Strukturyzowane odbicia
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 */
function add_unordered_employees(&$results, $orders, $rfidInfo, $structuredEntries, $startDate, $endDate) {
    // Stwórz słownik wszystkich zamówionych kombinacji SAM+data
    $orderedEmployees = [];
    foreach ($orders as $order) {
        $key = $order['sam_number'] . '|' . $order['ordered_for'];
        $orderedEmployees[$key] = true;
    }
    
    // Przeszukaj odbicia dla wszystkich kart RFID
    foreach ($structuredEntries as $cardId => $dateEntries) {
        // Pomiń klucz 'dates' (jeśli istnieje)
        if ($cardId === 'dates') continue;
        
        // Znajdź pracownika na podstawie numeru karty
        $idHash = $rfidInfo['rfid_to_id'][$cardId] ?? null;
        
        if (!$idHash) {
            // Jeśli nie znaleziono pracownika, pomiń
            continue;
        }
        
        $employeeInfo = $rfidInfo['employee_data'][$idHash] ?? null;
        
        if (!$employeeInfo) {
            // Jeśli nie znaleziono informacji o pracowniku, pomiń
            continue;
        }
        
        // Dla każdego dnia z odbiciami
        foreach ($dateEntries as $date => $entries) {
            // Pobierz aktualny numer SAM dla tej daty
            $samNumber = null;
            foreach ($employeeInfo['sam_numbers'] as $sam) {
                if (
                    (!$sam['valid_from'] || $sam['valid_from'] <= $date) && 
                    (!$sam['valid_to'] || $sam['valid_to'] >= $date)
                ) {
                    $samNumber = $sam['number_sam'];
                    break;
                }
            }
            
            if (!$samNumber) {
                // Jeśli nie znaleziono aktualnego numeru SAM, pomiń
                continue;
            }
            
            // Sprawdź, czy pracownik był zamówiony na tę datę
            $key = $samNumber . '|' . $date;
            if (isset($orderedEmployees[$key])) {
                // Jeśli pracownik był zamówiony, pomiń (już został przetworzony)
                continue;
            }
            
            // Znajdź odbicia dla tego dnia
            $gateIn = find_entry($entries, 3, 'min'); // 3 = brama wejście
            $gateOut = find_entry($entries, 4, 'max'); // 4 = brama wyjście
            $dzialIn = find_entry($entries, 1, 'min'); // 1 = dział wejście
            $dzialOut = find_entry($entries, 2, 'max'); // 2 = dział wyjście
            
            // Określ zmianę na podstawie czasu odbicia
            $shift = determine_shift($dzialIn ? $dzialIn['ReadAt'] : ($gateIn ? $gateIn['ReadAt'] : null));
            
            // Jeśli nie ma odbić, pomiń
            if (!$gateIn && !$gateOut && !$dzialIn && !$dzialOut) {
                continue;
            }
            
            // Korekty odbić
            if (!$dzialIn) $dzialIn = $gateIn;
            if (!$dzialOut) $dzialOut = $gateOut;
            
            // Określ źródło danych
            $entrySource = ($dzialIn && $dzialIn['source'] === 'GENEROWANE') ? 'GENEROWANE' : 'CARDLOG';
            
            // Zaokrąglij czas rozpoczęcia i zakończenia pracy
            $workStart = $dzialIn ? format_time_with_rounding($dzialIn['ReadAt'], 'up') : null;
            $workEnd = $dzialOut ? format_time_with_rounding($dzialOut['ReadAt'], 'down') : null;
            
            // Oblicz czas pracy w minutach
            $workMinutes = 0;
            
            if ($workStart && $workEnd) {
                $startTime = strtotime($workStart);
                $endTime = strtotime($workEnd);
                
                // Dla drugiej zmiany, jeśli czas zakończenia jest mniejszy niż czas rozpoczęcia,
                // dodaj jeden dzień do czasu zakończenia
                if ($shift == 2 && $endTime < $startTime) {
                    $endTime = strtotime('+1 day', $endTime);
                }
                
                // Oblicz całkowity czas w minutach
                $workMinutes = ($endTime - $startTime) / 60;
            }
            
            // Dodaj wpis do wyników
            $results[] = [
                'id_hash' => $idHash,
                'date' => $date,
                'card_rfid' => $cardId,
                'sam_number' => $samNumber,
                'employee_name' => $employeeInfo['imie'] . ' ' . $employeeInfo['nazwisko'],
                'gate_in' => $gateIn ? $gateIn['ReadAt'] : null,
                'gate_out' => $gateOut ? $gateOut['ReadAt'] : null,
                'work_start' => $workStart,
                'work_end' => $workEnd,
                'work_minutes' => max(0, round($workMinutes)),
                'deducted_minutes' => 0, // Brak informacji o przerwach dla niezamówionych pracowników
                'entry_reader' => $dzialIn ? $dzialIn['Reader'] : '',
                'department' => 'Niezdefiniowany', // Brak informacji o dziale dla niezamówionych pracowników
                'data_source' => $entrySource,
                'shift' => $shift,
                'resignation' => 0,
                'is_present' => true,
                'unordered' => true // Dodatkowy znacznik, że pracownik nie był zamówiony
            ];
        }
    }
}

/**
 * Pobiera zadania oczekujące dla danego użytkownika
 * 
 * @param int $userId ID użytkownika
 * @return array Tablica z zadaniami oczekującymi
 */
function get_pending_tasks_for_user($userId) {
    $pendingDir = __DIR__ . '/tasks/pending/';
    $tasks = [];
    
    if (!is_dir($pendingDir)) {
        mkdir($pendingDir, 0755, true);
    }
    
    $files = glob($pendingDir . '*_' . $userId . '.json');
    
    foreach ($files as $file) {
        $fileContent = file_get_contents($file);
        $task = json_decode($fileContent, true);
        $task['id'] = basename($file, '.json');
        $tasks[] = $task;
    }
    
    // Sortuj zadania według daty utworzenia (od najnowszych)
    usort($tasks, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $tasks;
}

/**
 * Pobiera ukończone zadania dla danego użytkownika
 * 
 * @param int $userId ID użytkownika
 * @return array Tablica z ukończonymi zadaniami
 */
function get_completed_tasks_for_user($userId) {
    $completedDir = __DIR__ . '/tasks/completed/';
    $tasks = [];
    
    if (!is_dir($completedDir)) {
        mkdir($completedDir, 0755, true);
    }
    
    $files = glob($completedDir . '*_' . $userId . '.json');
    
    foreach ($files as $file) {
        $fileContent = file_get_contents($file);
        $task = json_decode($fileContent, true);
        $task['id'] = basename($file, '.json');
        $tasks[] = $task;
    }
    
    // Sortuj zadania według daty ukończenia (od najnowszych)
    usort($tasks, function($a, $b) {
        return strtotime($b['completed_at']) - strtotime($a['created_at']);
    });
    
    return $tasks;
}

/**
 * Dodaje nowe zadanie do kolejki
 * 
 * @param int $userId ID użytkownika
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param string $samNumber Opcjonalny numer SAM do filtrowania
 * @return string ID zadania
 */
function add_task_to_queue($userId, $startDate, $endDate, $samNumber = '') {
    $pendingDir = __DIR__ . '/tasks/pending/';
    
    if (!is_dir($pendingDir)) {
        mkdir($pendingDir, 0755, true);
    }
    
    $taskId = time() . '_' . $userId;
    $task = [
        'user_id' => $userId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'sam_number' => $samNumber,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $filePath = $pendingDir . $taskId . '.json';
    file_put_contents($filePath, json_encode($task, JSON_PRETTY_PRINT));
    
    return $taskId;
}