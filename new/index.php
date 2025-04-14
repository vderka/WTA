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

// Sprawdzenie uprawnień
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);
log_action('VIEW', 'WTA_SYSTEM');

// Pobierz aktualną datę
$currentDate = date('Y-m-d');
$defaultStartDate = date('Y-m-d', strtotime('-7 days'));
$defaultEndDate = $currentDate;

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

// Sprawdzanie czy użytkownik ma jakieś zadania w kolejce
$userId = $_SESSION['account_id'] ?? 0;
$pendingTasks = get_pending_tasks_db($mysqlConn, $userId);
$completedTasks = get_completed_tasks_db($mysqlConn, $userId);
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $APPtitle = isset($apps[basename(__DIR__)]) ? $apps[basename(__DIR__)] : "WTA System"; ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="wta.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php renderNavbar(); ?>
    
    <div class="container sepm-container">
        <h1 class="app-title">
            <i class="fas fa-clock me-2"></i>
            <?php echo $APPtitle; ?> - System analizy czasu pracy (DB)
        </h1>
        
        <!-- Informacja o systemie -->
        <div class="info-note mb-4">
            <i class="fas fa-info-circle me-2"></i>
            System analizuje czas pracy wszystkich pracowników w wybranym okresie czasu. 
            Możliwe jest również wyszukanie konkretnego pracownika po numerze SAM.
            Maksymalny okres analizy to <?php echo WTA_MAX_ANALYSIS_DAYS; ?> dni.
        </div>
        
        <!-- Wyświetlanie alertów -->
        <?php display_alerts(); ?>
        
        <!-- Formularz wyboru okresu -->
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-calendar me-2"></i>
                Wybierz zakres dat do analizy
            </div>
            <div class="card-body">
                <form id="dateRangeForm" method="post">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="startDate" class="form-label">Data początkowa:</label>
                            <input type="date" class="form-control" id="startDate" name="startDate" value="<?php echo $defaultStartDate; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="endDate" class="form-label">Data końcowa:</label>
                            <input type="date" class="form-control" id="endDate" name="endDate" value="<?php echo $defaultEndDate; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="samNumber" class="form-label">Numer SAM (opcjonalnie):</label>
                            <input type="text" class="form-control" id="samNumber" name="samNumber" placeholder="Np. SAM_12345">
                            <div class="form-text">Pozostaw puste, aby analizować wszystkich pracowników</div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="sepm-btn btn-primary" id="processButton">
                            <i class="fas fa-search me-2"></i>
                            Przetwórz dane
                        </button>
                        <div class="form-text mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Szybki podgląd dostępny dla okresu do <?php echo WTA_PREVIEW_DAYS; ?> dni. Dłuższe okresy będą przetwarzane w tle.
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Sekcja oczekujących zadań -->
        <?php if (!empty($pendingTasks)): ?>
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-hourglass-half me-2"></i>
                Zadania w trakcie przetwarzania
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID zadania</th>
                                <th>Okres</th>
                                <th>Numer SAM</th>
                                <th>Data utworzenia</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingTasks as $task): ?>
                            <tr>
                                <td><?php echo $task['task_id']; ?></td>
                                <td><?php echo $task['start_date'] . ' — ' . $task['end_date']; ?></td>
                                <td><?php echo !empty($task['sam_number']) ? $task['sam_number'] : '<span class="text-muted">Wszyscy</span>'; ?></td>
                                <td><?php echo $task['created_at']; ?></td>
                                <td>
                                    <?php if ($task['status'] == 'pending'): ?>
                                        <span class="badge bg-warning">W kolejce</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Przetwarzanie</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <a href="queue.php" class="btn btn-secondary">
                        <i class="fas fa-tasks me-2"></i>
                        Zarządzaj kolejką zadań
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sekcja ukończonych zadań -->
        <?php if (!empty($completedTasks)): ?>
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-check-circle me-2"></i>
                Gotowe do pobrania
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID zadania</th>
                                <th>Okres</th>
                                <th>Numer SAM</th>
                                <th>Data ukończenia</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedTasks as $task): ?>
                            <tr>
                                <td><?php echo $task['task_id']; ?></td>
                                <td><?php echo $task['start_date'] . ' — ' . $task['end_date']; ?></td>
                                <td><?php echo !empty($task['sam_number']) ? $task['sam_number'] : '<span class="text-muted">Wszyscy</span>'; ?></td>
                                <td><?php echo $task['completed_at']; ?></td>
                                <td>
                                    <a href="download.php?task_id=<?php echo $task['task_id']; ?>" class="btn btn-success btn-sm">
                                        <i class="fas fa-download me-1"></i> Pobierz
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <a href="queue.php" class="btn btn-secondary">
                        <i class="fas fa-tasks me-2"></i>
                        Zarządzaj kolejką zadań
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Kontener na wyniki podglądu -->
        <div id="previewResults" class="mt-4" style="display: none;">
            <div class="card sepm-card">
                <div class="card-header sepm-card-header">
                    <i class="fas fa-table me-2"></i>
                    Wyniki analizy czasu pracy
                </div>
                <div class="card-body" id="previewContent">
                    <!-- Tutaj będą wyświetlane wyniki analizy -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal informacyjny o dodaniu zadania do kolejki -->
    <div class="modal fade" id="queuedTaskModal" tabindex="-1" aria-labelledby="queuedTaskModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="queuedTaskModalLabel">Zadanie dodane do kolejki</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Twoje zadanie analizy czasu pracy zostało dodane do kolejki przetwarzania.</p>
                    <p>Ze względu na wybrany okres (powyżej <?php echo WTA_PREVIEW_DAYS; ?> dni), dane będą przetwarzane w tle.</p>
                    <p>Po zakończeniu przetwarzania, będziesz mógł pobrać wyniki w sekcji "Gotowe do pobrania".</p>
                    <p id="queuedTaskInfo"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="wta.js"></script>
</body>
</html>

<?php
/**
 * Ustawia alert w sesji
 */
function set_alert($type, $message) {
    $_SESSION['wta_alert'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Wyświetla alerty z sesji
 */
function display_alerts() {
    if (isset($_SESSION['wta_alert'])) {
        $alert = $_SESSION['wta_alert'];
        echo '<div class="alert alert-' . $alert['type'] . ' alert-dismissible fade show" role="alert">';
        echo $alert['message'];
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        
        // Usuń alert z sesji
        unset($_SESSION['wta_alert']);
    }
}