<?php
/**
 * Zarządzanie kolejką zadań
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

// Sprawdzenie uprawnień
check_loggedin($pdo);
checkAppAccess($apps, $apps_to_domains);

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

// Obsługa akcji
$action = $_GET['action'] ?? '';
$taskId = $_GET['task_id'] ?? '';
$userId = $_SESSION['account_id'] ?? 0;

// Usuwanie zadania
if ($action === 'delete' && !empty($taskId)) {
    // Sprawdź, czy zadanie należy do zalogowanego użytkownika
    if (is_task_owner_db($mysqlConn, $taskId, $userId)) {
        delete_task_db($mysqlConn, $taskId);
        set_alert('success', 'Zadanie zostało usunięte.');
    } else {
        set_alert('danger', 'Brak uprawnień do usunięcia zadania.');
    }
    
    // Przekieruj z powrotem na stronę kolejki
    header('Location: queue.php');
    exit;
}

// Ponowne uruchomienie zadania
if ($action === 'restart' && !empty($taskId)) {
    // Sprawdź, czy zadanie należy do zalogowanego użytkownika
    if (is_task_owner_db($mysqlConn, $taskId, $userId)) {
        $newTaskId = restart_task_db($mysqlConn, $taskId);
        if ($newTaskId) {
            set_alert('success', 'Zadanie zostało ponownie dodane do kolejki.');
        } else {
            set_alert('danger', 'Nie udało się ponownie uruchomić zadania.');
        }
    } else {
        set_alert('danger', 'Brak uprawnień do ponownego uruchomienia zadania.');
    }
    
    // Przekieruj z powrotem na stronę kolejki
    header('Location: queue.php');
    exit;
}

// Pobierz zadania dla użytkownika
$pendingTasks = get_pending_tasks_db($mysqlConn, $userId);
$completedTasks = get_completed_tasks_db($mysqlConn, $userId);
$failedTasks = get_failed_tasks_db($mysqlConn, $userId);

// Wyświetl stronę
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $APPtitle = isset($apps[basename(__DIR__)]) ? $apps[basename(__DIR__)] : "WTA System"; ?> - Kolejka zadań</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="wta.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php renderNavbar(); ?>
    
    <div class="container sepm-container">
        <h1 class="app-title">
            <i class="fas fa-tasks me-2"></i>
            Kolejka zadań analizy czasu pracy (DB)
        </h1>
        
        <!-- Wyświetlanie alertów -->
        <?php display_alerts(); ?>
        
        <!-- Przyciski nawigacji -->
        <div class="mb-4">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home me-2"></i>
                Powrót do strony głównej
            </a>
        </div>
        
        <!-- Sekcja zadań w trakcie przetwarzania -->
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-hourglass-half me-2"></i>
                Zadania w trakcie przetwarzania (<?php echo count($pendingTasks); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($pendingTasks)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Brak zadań w trakcie przetwarzania.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID zadania</th>
                                    <th>Okres</th>
                                    <th>Numer SAM</th>
                                    <th>Data utworzenia</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
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
                                    <td>
                                        <a href="queue.php?action=delete&task_id=<?php echo $task['task_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Czy na pewno chcesz usunąć to zadanie?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sekcja ukończonych zadań -->
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-check-circle me-2"></i>
                Zadania ukończone (<?php echo count($completedTasks); ?>)
            </div>
            <div class="card-body">
                <?php if (empty($completedTasks)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        Brak ukończonych zadań.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID zadania</th>
                                    <th>Okres</th>
                                    <th>Numer SAM</th>
                                    <th>Data ukończenia</th>
                                    <th>Ilość wyników</th>
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
                                    <td><?php echo $task['row_count'] ?? 'N/A'; ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="download.php?task_id=<?php echo $task['task_id']; ?>" class="btn btn-success btn-sm">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <a href="queue.php?action=restart&task_id=<?php echo $task['task_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-redo"></i>
                                            </a>
                                            <a href="queue.php?action=delete&task_id=<?php echo $task['task_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Czy na pewno chcesz usunąć to zadanie?');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sekcja zadań zakończonych błędem -->
        <?php if (!empty($failedTasks)): ?>
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Zadania zakończone błędem (<?php echo count($failedTasks); ?>)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID zadania</th>
                                <th>Okres</th>
                                <th>Numer SAM</th>
                                <th>Data zakończenia</th>
                                <th>Błąd</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($failedTasks as $task): ?>
                            <tr>
                                <td><?php echo $task['task_id']; ?></td>
                                <td><?php echo $task['start_date'] . ' — ' . $task['end_date']; ?></td>
                                <td><?php echo !empty($task['sam_number']) ? $task['sam_number'] : '<span class="text-muted">Wszyscy</span>'; ?></td>
                                <td><?php echo $task['completed_at']; ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#errorModal<?php echo str_replace(['.', '-'], '_', $task['task_id']); ?>">
                                        <i class="fas fa-exclamation-circle me-1"></i>
                                        Pokaż błąd
                                    </button>
                                    
                                    <!-- Modal z treścią błędu -->
                                    <div class="modal fade" id="errorModal<?php echo str_replace(['.', '-'], '_', $task['task_id']); ?>" tabindex="-1" aria-labelledby="errorModalLabel<?php echo str_replace(['.', '-'], '_', $task['task_id']); ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="errorModalLabel<?php echo str_replace(['.', '-'], '_', $task['task_id']); ?>">Błąd zadania #<?php echo $task['task_id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="alert alert-danger">
                                                        <?php echo nl2br(htmlspecialchars($task['error_message'])); ?>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="queue.php?action=restart&task_id=<?php echo $task['task_id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-redo"></i>
                                        </a>
                                        <a href="queue.php?action=delete&task_id=<?php echo $task['task_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Czy na pewno chcesz usunąć to zadanie?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sekcja logów zadań -->
        <div class="card sepm-card mb-4">
            <div class="card-header sepm-card-header">
                <i class="fas fa-info-circle me-2"></i>
                Informacje o bazie danych
            </div>
            <div class="card-body">
                <?php
                // Pobierz statystyki z bazy danych
                $statsQuery = "
                    SELECT 
                        status, 
                        COUNT(*) as count 
                    FROM 
                        sepm_wta_cron 
                    GROUP BY 
                        status
                ";
                $statsStmt = $mysqlConn->query($statsQuery);
                $stats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $statsData = [
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'total' => 0
                ];
                
                foreach ($stats as $row) {
                    $statsData[$row['status']] = $row['count'];
                    $statsData['total'] += $row['count'];
                }
                ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <h5>Statystyki zadań</h5>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Oczekujące
                                <span class="badge bg-warning rounded-pill"><?php echo $statsData['pending']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Przetwarzane
                                <span class="badge bg-info rounded-pill"><?php echo $statsData['processing']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Ukończone
                                <span class="badge bg-success rounded-pill"><?php echo $statsData['completed']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Błędy
                                <span class="badge bg-danger rounded-pill"><?php echo $statsData['failed']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <strong>Razem</strong>
                                <span class="badge bg-primary rounded-pill"><?php echo $statsData['total']; ?></span>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h5>Ostatnie logi systemowe</h5>
                        <?php
                        // Pobierz ostatnie logi
                        $logsQuery = "
                            SELECT 
                                log_time,
                                message
                            FROM 
                                sepm_wta_cron_logs
                            ORDER BY 
                                log_time DESC
                            LIMIT 5
                        ";
                        $logsStmt = $mysqlConn->query($logsQuery);
                        $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php if (empty($logs)): ?>
                            <div class="alert alert-info">
                                Brak logów systemowych.
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($logs as $log): ?>
                                    <div class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <small class="text-muted"><?php echo $log['log_time']; ?></small>
                                        </div>
                                        <p class="mb-1"><?php echo htmlspecialchars($log['message']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
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