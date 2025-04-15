<?php
// Wyświetl wszystkie błędy
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Utwórz prosty formularz do testowania
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Debug Preview</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .form-group { margin-bottom: 15px; }
            label { display: block; margin-bottom: 5px; }
            input, button { padding: 5px; }
            button { cursor: pointer; }
            pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow: auto; }
        </style>
    </head>
    <body>
        <h1>Debug Preview</h1>
        <form method="post">
            <div class="form-group">
                <label for="startDate">Data początkowa:</label>
                <input type="date" id="startDate" name="startDate" required>
            </div>
            <div class="form-group">
                <label for="endDate">Data końcowa:</label>
                <input type="date" id="endDate" name="endDate" required>
            </div>
            <div class="form-group">
                <label for="samNumber">Numer SAM (opcjonalnie):</label>
                <input type="text" id="samNumber" name="samNumber">
            </div>
            <button type="submit">Testuj</button>
        </form>
    </body>
    </html>';
    exit;
}

// Przechwytuj wyjście
ob_start();

// Wywołaj preview.php poprzez include
$_POST = [
    'startDate' => $_POST['startDate'] ?? '',
    'endDate' => $_POST['endDate'] ?? '',
    'samNumber' => $_POST['samNumber'] ?? ''
];

try {
    include 'preview.php';
} catch (Throwable $e) {
    echo "Złapany wyjątek: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
}

// Pobierz zawartość bufora
$output = ob_get_clean();

// Wyświetl rezultat
echo '<!DOCTYPE html>
<html>
<head>
    <title>Wynik debugowania</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow: auto; }
    </style>
</head>
<body>
    <h1>Wynik debugowania</h1>
    <h2>Parametry</h2>
    <pre>' . htmlspecialchars(print_r($_POST, true)) . '</pre>
    <h2>Odpowiedź</h2>
    <pre>' . htmlspecialchars($output) . '</pre>
</body>
</html>';