<?php
// Wymagane pliki konfiguracyjne
require_once $_SERVER['DOCUMENT_ROOT'] . '/main.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_time_set.php';
require_once 'logic.php';

// Sprawdzenie uprawnień
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);
log_action('VIEW', 'SEPM Attendance');

// Inicjalizacja i pobieranie danych
$data = initializeAndLoadData();
extract($data);

// Bezpieczna nazwa aplikacji
$safe_dir = basename(dirname(filter_input(INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_URL)));
$APPtitle = isset($apps[$safe_dir]) ? $apps[$safe_dir] : "SEPM Attendance";
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($APPtitle); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="style.css" rel="stylesheet">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <?php renderNavbar(); ?>
    
    <div class="container-fluid py-3">
        <!-- Nagłówek strony -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="sepm-card">
                    <div class="sepm-card-header d-flex justify-content-between align-items-center">
                        <h1 class="h4 mb-0">System Ewidencji Pracowników Montażu</h1>
                        <a href="#" class="btn sepm-btn btn-sm btn-light">
                            <i class="bi bi-question-circle"></i> Pomoc
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Kolumna lewa - filtry -->
            <div class="col-md-3">
                <!-- Blok filtru podstawowego -->
                <div class="filter-block mb-3">
                    <div class="filter-header">
                        <i class="bi bi-funnel-fill"></i> Filtry podstawowe
                    </div>
                    
                    <form method="GET" id="filter-form">
                        <div class="mb-2">
                            <label for="date" class="form-label">Data:</label>
                            <input type="date" id="date" name="date" value="<?= htmlspecialchars($currentDate) ?>" class="form-control">
                        </div>
                        
                        <div class="mb-2">
                            <label for="shift" class="form-label">Zmiana:</label>
                            <select id="shift" name="shift" class="form-select">
                                <option value="1" <?= $currentShift == 1 ? 'selected' : '' ?>>Zmiana 1</option>
                                <option value="2" <?= $currentShift == 2 ? 'selected' : '' ?>>Zmiana 2</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="ignore_exits" name="ignore_exits" value="1" <?= $ignoreExits ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ignore_exits">
                                    Pomijaj wyjścia w sprawdzaniu obecności
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn sepm-btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i> Zastosuj filtry
                        </button>
                    </form>
                </div>
                
                <!-- Blok wyboru firmy -->
                <div class="filter-block mb-3">
                    <div class="filter-header">
                        <i class="bi bi-building"></i> Wybierz firmę
                    </div>
                    
                    <a href="?date=<?= urlencode($currentDate) ?>&shift=<?= urlencode($currentShift) ?><?= $ignoreExits ? '&ignore_exits=1' : '' ?>" 
                       class="company-button w-100 <?= empty($companyOwnerCode) ? 'active' : '' ?>">
                        <i class="bi bi-buildings company-icon"></i> Wszystkie firmy
                    </a>
                    
                    <?php foreach ($companies as $company): ?>
                        <a href="?date=<?= urlencode($currentDate) ?>&shift=<?= urlencode($currentShift) ?>&company=<?= urlencode($company) ?><?= $ignoreExits ? '&ignore_exits=1' : '' ?>" 
                           class="company-button w-100 <?= $companyOwnerCode === $company ? 'active' : '' ?>">
                            <i class="bi bi-building company-icon"></i> <?= htmlspecialchars($company) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                
                <!-- Informacja o aktywnych filtrach -->
                <div class="filter-block">
                    <div class="filter-header">
                        <i class="bi bi-info-circle"></i> Aktywne filtry
                    </div>
                    
                    <div class="summary-item w-100">
                        <strong><i class="bi bi-calendar-date"></i> Data:</strong> <?= formatDate($currentDate) ?>
                    </div>
                    
                    <div class="summary-item w-100">
                        <strong><i class="bi bi-clock"></i> Zmiana:</strong> <?= $currentShift == 1 ? 'Zmiana 1' : 'Zmiana 2' ?>
                        (<?= formatTime($shiftStartTime) ?> - <?= formatTime($shiftEndTime) ?>)
                    </div>
                    
                    <div class="summary-item w-100">
                        <strong><i class="bi bi-building"></i> Firma:</strong> <?= $companyOwnerCode ?: 'Wszystkie firmy' ?>
                    </div>
                    
                    <div class="summary-item w-100">
                        <strong><i class="bi bi-diagram-3"></i> Dział:</strong> 
                        <?php 
                        if ($departmentId) {
                            $deptName = '';
                            foreach ($departments as $d) {
                                if ($d['id'] == $departmentId) {
                                    $deptName = $d['department_name'];
                                    break;
                                }
                            }
                            echo $deptName ?: 'Nieznany';
                        } else {
                            echo 'Wszystkie działy';
                        }
                        ?>
                    </div>
                    
                    <div class="summary-item w-100">
                        <strong><i class="bi bi-toggle-<?= $ignoreExits ? 'on' : 'off' ?>"></i> Pomijaj wyjścia:</strong> <?= $ignoreExits ? 'Tak' : 'Nie' ?>
                    </div>
                    
                    <div class="mt-3">
                        <a href="#" class="btn sepm-btn btn-success w-100">
                            <i class="bi bi-file-excel me-1"></i> Eksportuj do Excel
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Kolumna prawa - zawartość -->
            <div class="col-md-9">
                <?php if ($companyOwnerCode || $departmentId): ?>
                    <!-- Działy i niezamówieni -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="sepm-card">
                                <div class="sepm-card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-diagram-3 me-1"></i> Działy
                                        <?php if ($companyOwnerCode): ?>
                                            <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($companyOwnerCode) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="badge bg-info rounded-pill"><?= count($departments) ?> działów</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12 mb-2">
                                            <a href="?date=<?= urlencode($currentDate) ?>&shift=<?= urlencode($currentShift) ?><?= $companyOwnerCode ? '&company=' . urlencode($companyOwnerCode) : '' ?><?= $ignoreExits ? '&ignore_exits=1' : '' ?>" 
                                               class="btn btn-sm btn-outline-secondary <?= empty($departmentId) ? 'active' : '' ?>">
                                                Wszystkie działy
                                            </a>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <?php if (!empty($departments)): ?>
                                            <?php foreach ($departments as $dept): ?>
                                                <div class="col-md-4 mb-2">
                                                    <a href="?date=<?= urlencode($currentDate) ?>&shift=<?= urlencode($currentShift) ?><?= $companyOwnerCode ? '&company=' . urlencode($companyOwnerCode) : '' ?>&department=<?= urlencode($dept['id']) ?><?= $ignoreExits ? '&ignore_exits=1' : '' ?>" 
                                                       class="department-button btn <?= $departmentId == $dept['id'] ? 'btn-success' : 'btn-outline-success' ?> w-100">
                                                        <i class="bi bi-diagram-3-fill me-1"></i> <?= htmlspecialchars($dept['department_name']) ?>
                                                        <span class="employee-count"><?= $dept['present_count'] ?>/<?= $dept['employee_count'] ?></span>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="col-12">
                                                <div class="alert alert-info">
                                                    Brak działów z zamówieniami dla wybranej daty i zmiany.
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if(!empty($unorderedEmployees)): ?>
                                        <div class="row mt-2">
                                            <div class="col-md-4 mb-2">
                                                <a href="?date=<?= urlencode($currentDate) ?>&shift=<?= urlencode($currentShift) ?><?= $companyOwnerCode ? '&company=' . urlencode($companyOwnerCode) : '' ?><?= $ignoreExits ? '&ignore_exits=1' : '' ?>&unordered=1" 
                                                   class="department-button unordered w-100">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Niezamówieni
                                                    <span class="employee-count"><?= count($unorderedEmployees) ?></span>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tabela pracowników -->
                    <div class="row">
                        <div class="col-12">
                            <div class="sepm-card">
                                <div class="sepm-card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="bi bi-people-fill me-1"></i> Lista pracowników
                                    </div>
                                    <div>
                                        <button id="toggle-all-details" class="btn btn-sm btn-outline-light me-2" data-expanded="false">
                                            <i class="bi bi-arrows-expand me-1"></i> Rozwiń wszystko
                                        </button>
                                        <span class="badge bg-primary rounded-pill"><?= count($tableData) + count($unorderedEmployees) ?> pracowników</span>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($tableData) && empty($unorderedEmployees)): ?>
                                        <div class="alert alert-warning m-3">
                                            Brak danych do wyświetlenia dla wybranych filtrów.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm attendance-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th width="30">LP.</th>
                                                        <th width="40"></th>
                                                        <th>Kod</th>
                                                        <th>Imię i Nazwisko</th>
                                                        <th>Numer ID</th>
                                                        <th>Dział</th>
                                                        <th>Spółka</th>
                                                        <th>RFID</th>
                                                        <th width="90">Obecność</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $counter = 1;
                                                    
                                                    // Wyświetl zamówionych pracowników
                                                    foreach ($tableData as $index => $entry): 
                                                        $rfidData = $entry['rfid_data'] ?? null;
                                                        $hasResignation = !empty($entry['sepm_data']['resignation']) && $entry['sepm_data']['resignation'] == 1;
                                                        $substitute = !empty($entry['sepm_data']['substitute']) && $entry['sepm_data']['substitute'] == 1;
                                                    ?>
                                                        <tr>
                                                            <td class="text-center"><?= $counter++ ?></td>
                                                            <td class="text-center">
                                                                <i class="bi bi-chevron-down toggle-details"></i>
                                                            </td>
                                                            <td><?= renderFunction($entry['sepm_data']['sam_number']) ?></td>
                                                            <td>
                                                                <?php if ($rfidData): ?>
                                                                    <?= renderFunction($rfidData['imie'] . ' ' . $rfidData['nazwisko']) ?>
                                                                <?php else: ?>
                                                                    <?= renderFunction(null) ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?= renderFunction($rfidData['numer_identyfikacyjny'] ?? null) ?></td>
                                                            <td><?= renderFunction($entry['sepm_data']['department_name']) ?></td>
                                                            <td><?= renderFunction($rfidData['spolka_nazwa'] ?? null) ?></td>
                                                            <td><?= renderFunction($rfidData['numerrfid'] ?? null) ?></td>
                                                            <td class="text-center">
                                                                <?php if ($entry['attendance_status'] == 'present'): ?>
                                                                    <span class="status-present"><i class="bi bi-check-circle-fill"></i> Obecny</span>
                                                                <?php elseif ($entry['attendance_status'] == 'absent'): ?>
                                                                    <span class="status-absent"><i class="bi bi-x-circle-fill"></i> Nieobecny</span>
                                                                <?php else: ?>
                                                                    <span class="status-probable"><i class="bi bi-exclamation-circle-fill"></i> Prawdop.</span>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($hasResignation): ?>
                                                                    <br><small class="text-danger"><i class="bi bi-dash-circle-fill"></i> Rezygnacja</small>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        
                                                        <!-- Szczegóły pracownika - rozwinięty widok -->
                                                        <tr class="details-row">
                                                            <td colspan="9" class="p-0">
                                                                <div class="details-container">
                                                                    <div class="expandable-section">
                                                                        <!-- WEJŚCIE - BRAMA -->
                                                                        <div class="entry-section">
                                                                            <div class="entry-section-header">
                                                                                <i class="bi bi-door-open-fill"></i> WEJŚCIE - BRAMA
                                                                            </div>
                                                                            <?php 
                                                                            // Szukaj wejścia BRAMA - priorytet: Fake Entries, potem CardLog
                                                                            $gateEntry = getBestEntrySource($entry['fake_entries'], $entry['card_log_entries'], 'gate');
                                                                            
                                                                            if ($gateEntry) {
                                                                                $time = isset($gateEntry['read_at_formatted']) ? $gateEntry['read_at_formatted'] : $gateEntry['read_at'];
                                                                                $isFake = isset($gateEntry['source']) && $gateEntry['source'] == 'fake';
                                                                                echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                echo '<div class="entry-reader">' . $gateEntry['reader_name'] . '</div>';
                                                                                echo '<div class="entry-source">' . ($isFake ? 
                                                                                    '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                    '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                            } else {
                                                                                echo '<span class="text-muted">Brak</span>';
                                                                            }
                                                                            ?>
                                                                        </div>
                                                                        
                                                                        <!-- WEJŚCIE - DZIAŁ -->
                                                                        <div class="entry-section">
                                                                            <div class="entry-section-header">
                                                                                <i class="bi bi-building-fill-check"></i> WEJŚCIE - DZIAŁ
                                                                            </div>
                                                                            <?php 
                                                                            // Szukaj wejścia DZIAŁ - priorytet: Fake Entries, potem CardLog
                                                                            $departmentEntry = getBestEntrySource($entry['fake_entries'], $entry['card_log_entries'], 'department');
                                                                            
                                                                            if ($departmentEntry) {
                                                                                $time = isset($departmentEntry['read_at_formatted']) ? $departmentEntry['read_at_formatted'] : $departmentEntry['read_at'];
                                                                                $isFake = isset($departmentEntry['source']) && $departmentEntry['source'] == 'fake';
                                                                                echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                echo '<div class="entry-reader">' . $departmentEntry['reader_name'] . '</div>';
                                                                                echo '<div class="entry-source">' . ($isFake ? 
                                                                                    '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                    '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                            } else {
                                                                                echo '<span class="text-muted">Brak</span>';
                                                                            }
                                                                            ?>
                                                                        </div>
                                                                        
                                                                        <!-- WEJŚCIE - SEPM -->
                                                                        <div class="entry-section">
                                                                            <div class="entry-section-header">
                                                                                <i class="bi bi-pin-map-fill"></i> WEJŚCIE - SEPM
                                                                            </div>
                                                                            <?php 
                                                                            // Wejście SEPM
                                                                            if (!empty($entry['sepm_data']['sepm_entered_at'])) {
                                                                                echo '<div class="entry-time">' . date('H:i:s', strtotime($entry['sepm_data']['sepm_entered_at'])) . '</div>';
                                                                                echo '<div class="entry-source"><span class="badge bg-sepm">SEPM-GATE</span></div>';
                                                                            } else {
                                                                                echo '<span class="text-muted">Brak</span>';
                                                                            }
                                                                            ?>
                                                                        </div>
                                                                        
                                                                        <!-- WYJŚCIE - BRAMA -->
                                                                        <div class="entry-section">
                                                                            <div class="entry-section-header">
                                                                                <i class="bi bi-door-closed-fill"></i> WYJŚCIE - BRAMA
                                                                            </div>
                                                                            <?php 
                                                                            // Szukaj wyjścia BRAMA - priorytet: Fake Entries, potem CardLog
                                                                            $gateExit = getBestExitSource($entry['fake_exits'], $entry['card_log_exits'], 'gate');
                                                                            
                                                                            if ($gateExit) {
                                                                                $time = isset($gateExit['read_at_formatted']) ? $gateExit['read_at_formatted'] : $gateExit['read_at'];
                                                                                $isFake = isset($gateExit['source']) && $gateExit['source'] == 'fake';
                                                                                echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                echo '<div class="entry-reader">' . $gateExit['reader_name'] . '</div>';
                                                                                echo '<div class="entry-source">' . ($isFake ? 
                                                                                    '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                    '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                            } else {
                                                                                echo '<span class="text-muted">Brak</span>';
                                                                            }
                                                                            ?>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <?php if ($hasResignation || $substitute || !empty($entry['sepm_data']['comment'])): ?>
                                                                        <div class="additional-info">
                                                                            <?php if ($hasResignation): ?>
                                                                                <span class="info-badge resignation">
                                                                                    <i class="bi bi-dash-circle"></i> Rezygnacja
                                                                                </span>
                                                                            <?php endif; ?>
                                                                            
                                                                            <?php if ($substitute): ?>
                                                                                <span class="info-badge substitute">
                                                                                    <i class="bi bi-arrow-repeat"></i> Uzupełnienie
                                                                                </span>
                                                                            <?php endif; ?>
                                                                            
                                                                            <?php if (!empty($entry['sepm_data']['comment'])): ?>
                                                                                <span class="info-comment">
                                                                                    <i class="bi bi-chat-text"></i> <?= htmlspecialchars($entry['sepm_data']['comment']) ?>
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    
                                                    <!-- Wyświetl niezamówionych pracowników -->
                                                    <?php if (!empty($unorderedEmployees)): ?>
                                                        <tr class="table-warning">
                                                            <td colspan="9" class="bg-warning text-dark p-2">
                                                                <div class="text-center fw-bold">
                                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                                    Pracownicy niezamówieni (<?= count($unorderedEmployees) ?>)
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        
                                                        <?php foreach ($unorderedEmployees as $unordered): 
                                                            $rfidData = $unordered['rfid_data'] ?? null;
                                                        ?>
                                                            <tr class="table-warning">
                                                                <td class="text-center"><?= $counter++ ?></td>
                                                                <td class="text-center">
                                                                    <i class="bi bi-chevron-down toggle-details"></i>
                                                                </td>
                                                                <td><?= renderFunction($rfidData['numer_sam'] ?? null) ?></td>
                                                                <td>
                                                                    <?php if ($rfidData): ?>
                                                                        <?= renderFunction($rfidData['imie'] . ' ' . $rfidData['nazwisko']) ?>
                                                                    <?php else: ?>
                                                                        <?= renderFunction(null) ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?= renderFunction($rfidData['numer_identyfikacyjny'] ?? null) ?></td>
                                                                <td><span class="badge bg-warning text-dark">Niezamówiony</span></td>
                                                                <td><?= renderFunction($rfidData['spolka_nazwa'] ?? null) ?></td>
                                                                <td><?= renderFunction($rfidData['numerrfid'] ?? null) ?></td>
                                                                <td class="text-center">
                                                                    <?php if ($unordered['attendance_status'] == 'present'): ?>
                                                                        <span class="status-present"><i class="bi bi-check-circle-fill"></i> Obecny</span>
                                                                    <?php elseif ($unordered['attendance_status'] == 'absent'): ?>
                                                                        <span class="status-absent"><i class="bi bi-x-circle-fill"></i> Nieobecny</span>
                                                                    <?php else: ?>
                                                                        <span class="status-probable"><i class="bi bi-exclamation-circle-fill"></i> Prawdop.</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            
                                                            <!-- Szczegóły niezamówionego pracownika -->
                                                            <tr class="details-row">
                                                                <td colspan="9" class="p-0">
                                                                    <div class="details-container">
                                                                        <div class="expandable-section">
                                                                            <!-- WEJŚCIE - BRAMA -->
                                                                            <div class="entry-section">
                                                                                <div class="entry-section-header">
                                                                                    <i class="bi bi-door-open-fill"></i> WEJŚCIE - BRAMA
                                                                                </div>
                                                                                <?php 
                                                                                // Szukaj wejścia BRAMA - priorytet: Fake Entries, potem CardLog
                                                                                $gateEntry = getBestEntrySource($unordered['fake_entries'], $unordered['card_log_entries'], 'gate');
                                                                                
                                                                                if ($gateEntry) {
                                                                                    $time = isset($gateEntry['read_at_formatted']) ? $gateEntry['read_at_formatted'] : $gateEntry['read_at'];
                                                                                    $isFake = isset($gateEntry['source']) && $gateEntry['source'] == 'fake';
                                                                                    echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                    echo '<div class="entry-reader">' . $gateEntry['reader_name'] . '</div>';
                                                                                    echo '<div class="entry-source">' . ($isFake ? 
                                                                                        '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                        '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                                } else {
                                                                                    echo '<span class="text-muted">Brak</span>';
                                                                                }
                                                                                ?>
                                                                            </div>
                                                                            
                                                                            <!-- WEJŚCIE - DZIAŁ -->
                                                                            <div class="entry-section">
                                                                                <div class="entry-section-header">
                                                                                    <i class="bi bi-building-fill-check"></i> WEJŚCIE - DZIAŁ
                                                                                </div>
                                                                                <?php 
                                                                                // Szukaj wejścia DZIAŁ - priorytet: Fake Entries, potem CardLog
                                                                                $departmentEntry = getBestEntrySource($unordered['fake_entries'], $unordered['card_log_entries'], 'department');
                                                                                
                                                                                if ($departmentEntry) {
                                                                                    $time = isset($departmentEntry['read_at_formatted']) ? $departmentEntry['read_at_formatted'] : $departmentEntry['read_at'];
                                                                                    $isFake = isset($departmentEntry['source']) && $departmentEntry['source'] == 'fake';
                                                                                    echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                    echo '<div class="entry-reader">' . $departmentEntry['reader_name'] . '</div>';
                                                                                    echo '<div class="entry-source">' . ($isFake ? 
                                                                                        '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                        '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                                } else {
                                                                                    echo '<span class="text-muted">Brak</span>';
                                                                                }
                                                                                ?>
                                                                            </div>
                                                                            
                                                                            <!-- WEJŚCIE - SEPM -->
                                                                            <div class="entry-section">
                                                                                <div class="entry-section-header">
                                                                                    <i class="bi bi-pin-map-fill"></i> WEJŚCIE - SEPM
                                                                                </div>
                                                                                <span class="text-muted">Brak</span>
                                                                            </div>
                                                                            
                                                                            <!-- WYJŚCIE - BRAMA -->
                                                                            <div class="entry-section">
                                                                                <div class="entry-section-header">
                                                                                    <i class="bi bi-door-closed-fill"></i> WYJŚCIE - BRAMA
                                                                                </div>
                                                                                <?php 
                                                                                // Szukaj wyjścia BRAMA - priorytet: Fake Entries, potem CardLog
                                                                                $gateExit = getBestExitSource($unordered['fake_exits'], $unordered['card_log_exits'], 'gate');
                                                                                
                                                                                if ($gateExit) {
                                                                                    $time = isset($gateExit['read_at_formatted']) ? $gateExit['read_at_formatted'] : $gateExit['read_at'];
                                                                                    $isFake = isset($gateExit['source']) && $gateExit['source'] == 'fake';
                                                                                    echo '<div class="entry-time">' . date('H:i:s', strtotime($time)) . '</div>';
                                                                                    echo '<div class="entry-reader">' . $gateExit['reader_name'] . '</div>';
                                                                                    echo '<div class="entry-source">' . ($isFake ? 
                                                                                        '<span class="badge bg-generated">GENEROWANE</span>' : 
                                                                                        '<span class="badge bg-cardlog">CARDLOG</span>') . '</div>';
                                                                                } else {
                                                                                    echo '<span class="text-muted">Brak</span>';
                                                                                }
                                                                                ?>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        <div class="additional-info">
                                                                            <span class="info-badge resignation">
                                                                                <i class="bi bi-exclamation-triangle"></i> Pracownik niezamówiony
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Komunikat o konieczności wyboru firmy -->
                    <div class="sepm-card">
                        <div class="card-body">
                            <div class="alert alert-info mb-0">
                                <h4 class="alert-heading"><i class="bi bi-info-circle-fill me-2"></i> Witaj w systemie SEPM</h4>
                                <p>Aby zobaczyć listę pracowników, wybierz jedną z dostępnych firm z listy po lewej stronie.</p>
                                <hr>
                                <p class="mb-0">Możesz również ustawić datę i zmianę, aby precyzyjniej filtrować wyniki.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Toggle details row
            $('.toggle-details').click(function() {
                $(this).toggleClass('bi-chevron-down bi-chevron-up');
                $(this).closest('tr').next('.details-row').toggle();
            });
            
            // Toggle all details
            $('#toggle-all-details').click(function() {
                const isExpanded = $(this).data('expanded');
                
                if (isExpanded) {
                    // Collapse all
                    $('.details-row').hide();
                    $('.toggle-details').removeClass('bi-chevron-up').addClass('bi-chevron-down');
                    $(this).data('expanded', false);
                    $(this).html('<i class="bi bi-arrows-expand me-1"></i> Rozwiń wszystko');
                } else {
                    // Expand all
                    $('.details-row').show();
                    $('.toggle-details').removeClass('bi-chevron-down').addClass('bi-chevron-up');
                    $(this).data('expanded', true);
                    $(this).html('<i class="bi bi-arrows-collapse me-1"></i> Zwiń wszystko');
                }
            });
            
            // Export to Excel functionality
            $('#export-excel').click(function(e) {
                e.preventDefault();
                alert('Eksport do Excel - funkcjonalność w trakcie implementacji');
                // TODO: Implement Excel export
            });
        });
    </script>
</body>
</html>