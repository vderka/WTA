<?php
// Wyświetlanie błędów
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');

// Połączenie z konfiguracją
include_once("includes/config.php");

$readerMapping = [
    1 => 'DZ FAKE IN',
    2 => 'DZ FAKE OUT',
    3 => 'GT FAKE IN',
    4 => 'GT FAKE OUT'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Połączenie z MySQL
    $conn = new mysqli($mysqli_host, $mysqli_user, $mysqli_password, $mysqli_db);
    $conn->set_charset("utf8mb4");

    if ($conn->connect_error) {
        die("Połączenie nieudane: " . $conn->connect_error);
    }

    // Sprawdzanie rodzaju akcji
    $action = $_POST['action'];

    if ($action === 'fetch') {
        // Pobieranie rekordów
        $selected_date = $_POST['selectedDate'];
        $cardID = $_POST['cardID'];

        // Rozszerzenie zakresu dat o jeden dzień przed oraz jeden dzień po wybranej dacie
        $start_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
        $end_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

        // Zapytanie do bazy danych, pobiera także usunięte rekordy
        $sql = "SELECT LogID, CardID, ReadAt, Reader, ReaderFunction, LastActionBy, is_deleted 
                FROM sepm_fake_entries 
                WHERE DATE(ReadAt) BETWEEN ? AND ? 
                AND CardID = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $start_date, $end_date, $cardID);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];

        while ($row = $result->fetch_assoc()) {
            // Mapowanie funkcji ReaderFunction na wartości tekstowe Reader
            $row['Reader'] = $readerMapping[$row['ReaderFunction']] ?? $row['Reader'];
            $entries[] = $row;
        }

        // Zwróć dane w formacie JSON
        echo json_encode($entries);

        $stmt->close();
    }

    // Dodawanie rekordu
    if ($action === 'add') {
        $cardID = $_POST['cardID'];
        $readAt = $_POST['readAt'];
        $readerFunction = $_POST['readerFunction'];
        $lastActionBy = $_POST['lastActionBy'];
        
        // Mapowanie ReaderFunction na Reader
        $reader = $readerMapping[$readerFunction] ?? 'DZ FAKE IN';

        // Dodawanie nowego wpisu
        $sql = "INSERT INTO sepm_fake_entries (CardID, ReadAt, ReaderFunction, Reader, LastActionBy, CreatedAt, UpdatedAt, is_deleted) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiss", $cardID, $readAt, $readerFunction, $reader, $lastActionBy);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Rekord został dodany']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Błąd podczas dodawania rekordu']);
        }

        $stmt->close();
    }

    // Oznaczanie rekordu jako usunięty (zamiast usuwania)
    if ($action === 'delete') {
        $logID = $_POST['logID'];

        // Oznaczenie rekordu jako usunięty
        $sql = "UPDATE sepm_fake_entries SET is_deleted = 1 WHERE LogID = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $logID);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Rekord został oznaczony jako usunięty']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Błąd podczas usuwania rekordu']);
        }

        $stmt->close();
    }

    $conn->close();
}
