<?php
/**
 * Konfiguracja aplikacji WTA
 */

// Ustawienia systemu
define('WTA_MAX_ANALYSIS_DAYS', 31); // Maksymalny okres analizy w dniach
define('WTA_PREVIEW_DAYS', 7);       // Maksymalny okres dla szybkiego podglądu
define('WTA_TASK_RETENTION_DAYS', 30); // Okres przechowywania zadań w dniach

// Ustawienia zmiany
// Te ustawienia są zastępowane przez wartości z SEPM_CONFIG
// Są używane tylko jako wartości domyślne jeśli nie można pobrać konfiguracji
define('WTA_DEFAULT_SHIFT_1_START', '06:00:00'); // Domyślny czas rozpoczęcia pierwszej zmiany
define('WTA_DEFAULT_SHIFT_1_END', '18:00:00');   // Domyślny czas zakończenia pierwszej zmiany
define('WTA_DEFAULT_SHIFT_2_START', '18:00:00'); // Domyślny czas rozpoczęcia drugiej zmiany
define('WTA_DEFAULT_SHIFT_2_END', '06:00:00');   // Domyślny czas zakończenia drugiej zmiany

// Ustawienia przetwarzania
define('WTA_PROCESS_BATCH_SIZE', 100); // Ilość pracowników przetwarzanych w jednej partii

// Mapowanie funkcji czytników
$WTA_READER_FUNCTIONS = [
    1 => 'IN',  // Wejście na dział
    2 => 'OUT', // Wyjście z działu
    3 => 'GIN', // Wejście przez bramę
    4 => 'GOUT' // Wyjście przez bramę
];

// Mapowanie obszarów czytników
$WTA_READER_AREAS = [
    1 => 'DZIAŁ',
    2 => 'DZIAŁ',
    3 => 'BRAMA',
    4 => 'BRAMA'
];

// Mapowanie źródeł danych
$WTA_DATA_SOURCES = [
    'GENEROWANE' => 'Ręczna korekta',
    'CARDLOG' => 'Odbicie karty',
    'SEPM-GATE' => 'System zamówień'
];

// Katalogi systemu
define('WTA_TASKS_DIR', __DIR__ . '/tasks');
define('WTA_PENDING_DIR', WTA_TASKS_DIR . '/pending');
define('WTA_COMPLETED_DIR', WTA_TASKS_DIR . '/completed');
define('WTA_OUTPUT_DIR', WTA_TASKS_DIR . '/output');
define('WTA_LOGS_DIR', WTA_TASKS_DIR . '/logs');
define('WTA_CRON_LOGS_DIR', WTA_TASKS_DIR . '/cron_logs');

// Utwórz katalogi, jeśli nie istnieją
$directories = [
    WTA_TASKS_DIR,
    WTA_PENDING_DIR,
    WTA_COMPLETED_DIR,
    WTA_OUTPUT_DIR,
    WTA_LOGS_DIR,
    WTA_CRON_LOGS_DIR
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Załaduj konfigurację SEPM
global $SEPM_SETTINGS;
if (isset($SEPM_SETTINGS)) {
    // Przekształć godziny na format z sekundami
    define('WTA_SHIFT_1_START', $SEPM_SETTINGS["SHIFT_1_START"] . ':00');
    define('WTA_SHIFT_2_START', $SEPM_SETTINGS["SHIFT_2_START"] . ':00');
} else {
    // Użyj wartości domyślnych
    define('WTA_SHIFT_1_START', WTA_DEFAULT_SHIFT_1_START);
    define('WTA_SHIFT_2_START', WTA_DEFAULT_SHIFT_2_START);
}

// Funkcja do czyszczenia starych zadań
function wta_cleanup_old_tasks() {
    $cutoffDate = time() - (86400 * WTA_TASK_RETENTION_DAYS); // 86400 sekund = 1 dzień
    
    // Wyczyść stare pliki ukończonych zadań
    $completedFiles = glob(WTA_COMPLETED_DIR . '/*.json');
    foreach ($completedFiles as $file) {
        if (filemtime($file) < $cutoffDate) {
            @unlink($file);
        }
    }
    
    // Wyczyść stare pliki wyjściowe
    $outputFiles = glob(WTA_OUTPUT_DIR . '/*');
    foreach ($outputFiles as $file) {
        if (filemtime($file) < $cutoffDate) {
            @unlink($file);
        }
    }
    
    // Wyczyść stare logi
    $logFiles = glob(WTA_LOGS_DIR . '/*');
    foreach ($logFiles as $file) {
        if (filemtime($file) < $cutoffDate) {
            @unlink($file);
        }
    }
    
    // Wyczyść stare logi cron
    $cronLogFiles = glob(WTA_CRON_LOGS_DIR . '/*');
    foreach ($cronLogFiles as $file) {
        if (filemtime($file) < $cutoffDate) {
            @unlink($file);
        }
    }
}

// Uruchom czyszczenie z prawdopodobieństwem 1%
if (rand(1, 100) == 1) {
    wta_cleanup_old_tasks();
}