<?php
/**
 * Funkcje do obsługi zadań w bazie danych
 */

/**
 * Pobiera zadania oczekujące dla danego użytkownika
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param int $userId ID użytkownika
 * @return array Tablica z zadaniami oczekującymi
 */
function get_pending_tasks_db($conn, $userId) {
    $query = "
        SELECT 
            id, task_id, user_id, start_date, end_date, sam_number, status, 
            created_at, started_at, completed_at, row_count
        FROM 
            sepm_wta_cron
        WHERE 
            user_id = :user_id
            AND (status = 'pending' OR status = 'processing')
        ORDER BY 
            created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pobiera ukończone zadania dla danego użytkownika
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param int $userId ID użytkownika
 * @return array Tablica z ukończonymi zadaniami
 */
function get_completed_tasks_db($conn, $userId) {
    $query = "
        SELECT 
            id, task_id, user_id, start_date, end_date, sam_number, status, 
            created_at, started_at, completed_at, row_count, file_path, json_path
        FROM 
            sepm_wta_cron
        WHERE 
            user_id = :user_id
            AND status = 'completed'
        ORDER BY 
            completed_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pobiera zadania zakończone błędem dla danego użytkownika
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param int $userId ID użytkownika
 * @return array Tablica z zadaniami zakończonymi błędem
 */
function get_failed_tasks_db($conn, $userId) {
    $query = "
        SELECT 
            id, task_id, user_id, start_date, end_date, sam_number, status, 
            created_at, started_at, completed_at, error_message
        FROM 
            sepm_wta_cron
        WHERE 
            user_id = :user_id
            AND status = 'failed'
        ORDER BY 
            completed_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dodaje nowe zadanie do kolejki w bazie danych
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param int $userId ID użytkownika
 * @param string $startDate Data początkowa
 * @param string $endDate Data końcowa
 * @param string $samNumber Opcjonalny numer SAM do filtrowania
 * @return string ID zadania
 */
function add_task_to_queue_db($conn, $userId, $startDate, $endDate, $samNumber = '') {
    $taskId = uniqid('task_') . '_' . time();
    
    $query = "
        INSERT INTO sepm_wta_cron
            (task_id, user_id, start_date, end_date, sam_number, status, created_at)
        VALUES
            (:task_id, :user_id, :start_date, :end_date, :sam_number, 'pending', NOW())
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':start_date', $startDate, PDO::PARAM_STR);
    $stmt->bindParam(':end_date', $endDate, PDO::PARAM_STR);
    $stmt->bindParam(':sam_number', $samNumber, PDO::PARAM_STR);
    $stmt->execute();
    
    return $taskId;
}

/**
 * Pobiera najstarsze oczekujące zadanie
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @return array|null Dane zadania lub null jeśli brak zadań
 */
function get_oldest_pending_task_db($conn) {
    $query = "
        SELECT 
            id, task_id, user_id, start_date, end_date, sam_number, status, 
            created_at, started_at, completed_at
        FROM 
            sepm_wta_cron
        WHERE 
            status = 'pending'
        ORDER BY 
            created_at ASC
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($task) {
        // Oznacz zadanie jako przetwarzane
        mark_task_as_processing_db($conn, $task['task_id']);
        return $task;
    }
    
    return null;
}

/**
 * Oznacza zadanie jako przetwarzane
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @return bool Sukces operacji
 */
function mark_task_as_processing_db($conn, $taskId) {
    $query = "
        UPDATE sepm_wta_cron
        SET 
            status = 'processing',
            started_at = NOW()
        WHERE 
            task_id = :task_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    
    return $stmt->execute();
}

/**
 * Oznacza zadanie jako ukończone
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @param int $rowCount Liczba przetworzonych wierszy
 * @param string $filePath Ścieżka do pliku CSV
 * @param string $jsonPath Ścieżka do pliku JSON
 * @return bool Sukces operacji
 */
function mark_task_as_completed_db($conn, $taskId, $rowCount, $filePath, $jsonPath) {
    $query = "
        UPDATE sepm_wta_cron
        SET 
            status = 'completed',
            completed_at = NOW(),
            row_count = :row_count,
            file_path = :file_path,
            json_path = :json_path
        WHERE 
            task_id = :task_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->bindParam(':row_count', $rowCount, PDO::PARAM_INT);
    $stmt->bindParam(':file_path', $filePath, PDO::PARAM_STR);
    $stmt->bindParam(':json_path', $jsonPath, PDO::PARAM_STR);
    
    return $stmt->execute();
}

/**
 * Oznacza zadanie jako zakończone błędem
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @param string $errorMessage Komunikat błędu
 * @return bool Sukces operacji
 */
function mark_task_as_failed_db($conn, $taskId, $errorMessage) {
    $query = "
        UPDATE sepm_wta_cron
        SET 
            status = 'failed',
            completed_at = NOW(),
            error_message = :error_message
        WHERE 
            task_id = :task_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->bindParam(':error_message', $errorMessage, PDO::PARAM_STR);
    
    return $stmt->execute();
}

/**
 * Zapisuje log przetwarzania zadania
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @param string $message Komunikat do zapisania
 * @return bool Sukces operacji
 */
function log_processing_progress_db($conn, $taskId, $message) {
    $query = "
        INSERT INTO sepm_wta_cron_logs
            (task_id, message, log_time)
        VALUES
            (:task_id, :message, NOW())
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->bindParam(':message', $message, PDO::PARAM_STR);
    
    return $stmt->execute();
}

/**
 * Pobiera logi przetwarzania zadania
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @return array Tablica z logami
 */
function get_task_logs_db($conn, $taskId) {
    $query = "
        SELECT 
            id, task_id, message, log_time
        FROM 
            sepm_wta_cron_logs
        WHERE 
            task_id = :task_id
        ORDER BY 
            log_time ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Usuwa zadanie z bazy danych i pliki związane z nim
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @return bool Sukces operacji
 */
function delete_task_db($conn, $taskId) {
    // Najpierw pobierz informacje o plikach zadania
    $query = "
        SELECT 
            file_path, json_path
        FROM 
            sepm_wta_cron
        WHERE 
            task_id = :task_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Usuń pliki, jeśli istnieją
    if ($task) {
        if (!empty($task['file_path']) && file_exists($task['file_path'])) {
            unlink($task['file_path']);
        }
        
        if (!empty($task['json_path']) && file_exists($task['json_path'])) {
            unlink($task['json_path']);
        }
    }
    
    // Usuń logi zadania
    $queryLogs = "
        DELETE FROM 
            sepm_wta_cron_logs
        WHERE 
            task_id = :task_id
    ";
    
    $stmtLogs = $conn->prepare($queryLogs);
    $stmtLogs->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmtLogs->execute();
    
    // Usuń zadanie
    $queryTask = "
        DELETE FROM 
            sepm_wta_cron
        WHERE 
            task_id = :task_id
    ";
    
    $stmtTask = $conn->prepare($queryTask);
    $stmtTask->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    
    return $stmtTask->execute();
}

/**
 * Sprawdza, czy zadanie należy do użytkownika
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @param int $userId ID użytkownika
 * @return bool Czy zadanie należy do użytkownika
 */
function is_task_owner_db($conn, $taskId, $userId) {
    $query = "
        SELECT 
            id
        FROM 
            sepm_wta_cron
        WHERE 
            task_id = :task_id
            AND user_id = :user_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}

/**
 * Ponownie uruchamia zadanie
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param string $taskId ID zadania
 * @return string|false Nowe ID zadania lub false w przypadku błędu
 */
function restart_task_db($conn, $taskId) {
    // Pobierz informacje o zadaniu
    $query = "
        SELECT 
            user_id, start_date, end_date, sam_number
        FROM 
            sepm_wta_cron
        WHERE 
            task_id = :task_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':task_id', $taskId, PDO::PARAM_STR);
    $stmt->execute();
    
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return false;
    }
    
    // Utwórz nowe zadanie
    return add_task_to_queue_db(
        $conn, 
        $task['user_id'], 
        $task['start_date'], 
        $task['end_date'], 
        $task['sam_number']
    );
}

/**
 * Czyści stare zadania z bazy danych
 * 
 * @param PDO $conn Połączenie z bazą danych
 * @param int $daysToKeep Liczba dni, przez które zadania mają być przechowywane
 * @return int Liczba usuniętych zadań
 */
function cleanup_old_tasks_db($conn, $daysToKeep = 30) {
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysToKeep days"));
    
    // Najpierw znajdź wszystkie zadania do usunięcia
    $queryFind = "
        SELECT 
            task_id, file_path, json_path
        FROM 
            sepm_wta_cron
        WHERE 
            (status = 'completed' OR status = 'failed')
            AND completed_at < :cutoff_date
    ";
    
    $stmtFind = $conn->prepare($queryFind);
    $stmtFind->bindParam(':cutoff_date', $cutoffDate, PDO::PARAM_STR);
    $stmtFind->execute();
    
    $tasksToDelete = $stmtFind->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    
    // Usuń pliki i zadania
    foreach ($tasksToDelete as $task) {
        if (delete_task_db($conn, $task['task_id'])) {
            $count++;
        }
    }
    
    return $count;
}