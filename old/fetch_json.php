<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');

include_once("includes/config.php");

// Debug flag
$debug = false; // Set to true to enable debugging

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $period_start = $_POST['startDate'];
    $period_end = $_POST['endDate'];

    // Step 1: Generate RFID Numbers from SQL Server
    $rfid_numbers = generate_rfid_numbers($period_start, $period_end);

    if (isset($rfid_numbers['error'])) {
        echo json_encode(['error' => 'Error fetching RFID numbers', 'details' => $rfid_numbers['details']]);
        exit;
    }

    if (empty($rfid_numbers)) {
        echo json_encode(["message" => "No RFID numbers found."]);
        exit;
    }

    // Debug: Output the retrieved RFID numbers
    if ($debug) {
        echo "<pre>RFID Numbers: ";
        print_r($rfid_numbers);
        echo "</pre>";
    }

    // Step 2: Fetch Employee Data from MySQL
    $conn = new mysqli($mysqli_host, $mysqli_user, $mysqli_password, $mysqli_db);

    // Set UTF-8 charset
    $conn->set_charset("utf8mb4");  // Use utf8mb4 for better compatibility with emojis and special characters

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $data = [];

    // Process RFID numbers in chunks
    $chunks = array_chunk($rfid_numbers, 1000);

    foreach ($chunks as $chunk) {
        execute_query($conn, $chunk, $period_start, $period_end, $data);
    }

    // Debug: Output employee data after MySQL fetch
    if ($debug) {
        echo "<pre>Employee Data: ";
        print_r($data);
        echo "</pre>";
    }

    // Step 3: Fetch Swipe Data from SQL Server and Merge with Employee Data
    fetch_card_log_data($rfid_numbers, $period_start, $period_end, $data);

    // Debug: Output final data before JSON encoding
    if ($debug) {
        echo "<pre>Final Data: ";
        print_r($data);
        echo "</pre>";
    }

    // Clean data from unwanted characters
    array_walk_recursive($data, function (&$item) {
        if (is_string($item)) {
            // Remove non-printable characters
            $item = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $item);
            // Ensure UTF-8 encoding
            $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
        }
    });

    // Convert to JSON with error checking
    $json_output = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); // Add JSON_UNESCAPED_UNICODE

    // Check for JSON encoding errors
    if ($json_output === false) {
        echo "JSON encoding error: " . json_last_error_msg();
    } else {
        // Send JSON response
        header('Content-Type: application/json');
        echo $json_output;
    }

    // Close MySQL connection
    $conn->close();
}

// Function to generate RFID numbers from SQL Server
function generate_rfid_numbers($startDate, $endDate) {
    global $serverName, $connectionOptions;

    $conn = sqlsrv_connect($serverName, $connectionOptions);

    if ($conn === false) {
        return array("error" => "Connection failed", "details" => sqlsrv_errors());
    }

    $startDateTime = date('Y-m-d H:i:s', strtotime($startDate . ' -1 day 18:00:00'));
    $endDateTime = date('Y-m-d H:i:s', strtotime($endDate . ' +1 day 06:00:00'));

    $sql = "SELECT DISTINCT CardID FROM card_log WHERE ReadAt BETWEEN ? AND ?";
    $params = array($startDateTime, $endDateTime);

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return array("error" => "Query execution failed", "details" => sqlsrv_errors());
    }

    $data = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row['CardID'];
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);

    return array_unique($data);
}

// Function to fetch employee data from MySQL and map with RFID numbers
function execute_query($conn, $rfid_chunk, $period_start, $period_end, &$data) {
    $placeholders = implode(',', array_fill(0, count($rfid_chunk), '?'));

    $sql = "
        SELECT 
            p.numer_identyfikacyjny,
            ? AS period_start,
            ? AS period_end,
            p.number_sam AS numer_sam,
            k.numerrfid,  
            p.karta_rfid,
            p.aktywacja_rfid,
            p.dezaktywacja_rfid,
            p.imie,
            p.nazwisko,
            p.grupa_rfid,
            p.telefon_pl
        FROM 
            u_yf_pracownicysepm p
        JOIN 
            u_yf_kartyrfid k ON p.karta_rfid = k.kartyrfidid
        WHERE 
            k.numerrfid IN ($placeholders)
        AND (
            (p.aktywacja_rfid BETWEEN ? AND ?)
            OR (p.aktywacja_rfid <= ? AND (p.dezaktywacja_rfid >= ? OR p.dezaktywacja_rfid IS NULL))
        )
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        die("Prepare failed: " . $conn->error);
    }

    $params = array_merge(
        [$period_start, $period_end],
        $rfid_chunk,
        [$period_start, $period_end, $period_end, $period_start]
    );
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Ensure each value is UTF-8 encoded, checking for nulls
            $row = array_map(function($value) {
                return $value !== null ? mb_convert_encoding($value, 'UTF-8', 'UTF-8') : $value;
            }, $row);

            $key = $row['numer_identyfikacyjny'];
            if (!isset($data[$key])) {
                $data[$key] = [
                    'numer_identyfikacyjny' => $row['numer_identyfikacyjny'],
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'personal_info' => [],
                    'entries' => []  // Will now store entries grouped by date
                ];
            }

            // Add personal info, allowing for multiple records
            $personal_info = [
                'numer_sam' => $row['numer_sam'],
                'numerrfid' => $row['numerrfid'], // Correctly use numerrfid for display
                'karta_rfid' => $row['karta_rfid'], // Keeping karta_rfid for internal mapping
                'aktywacja_rfid' => $row['aktywacja_rfid'],
                'dezaktywacja_rfid' => $row['dezaktywacja_rfid'],
                'imie' => $row['imie'],
                'nazwisko' => $row['nazwisko'],
                'grupa_rfid' => $row['grupa_rfid'],
                'telefon_pl' => $row['telefon_pl']
            ];

            // Avoid duplicate personal info records
            if (!in_array($personal_info, $data[$key]['personal_info'])) {
                $data[$key]['personal_info'][] = $personal_info;
            }
        }
    }

    $stmt->close();
}

// Function to fetch card log data from SQL Server
function fetch_card_log_data($rfid_numbers, $period_start, $period_end, &$data) {
    global $serverName, $connectionOptions, $debug;

    $conn = sqlsrv_connect($serverName, $connectionOptions);

    if ($conn === false) {
        die("SQL Server connection failed: " . print_r(sqlsrv_errors(), true));
    }

    $startDateTime = date('Y-m-d H:i:s', strtotime($period_start . ' -1 day 18:00:00'));
    $endDateTime = date('Y-m-d H:i:s', strtotime($period_end . ' +1 day 06:00:00'));

    $rfid_placeholders = implode(',', array_fill(0, count($rfid_numbers), '?'));
    $sql = "
        SELECT 
            cl.CardID,
            cl.ReadAt,
            cl.Reader,
            cl.ReaderFunction
        FROM 
            card_log cl
        WHERE 
            cl.CardID IN ($rfid_placeholders)
        AND cl.ReadAt BETWEEN ? AND ?
        ORDER BY cl.CardID, cl.ReadAt
    ";

    $params = array_merge($rfid_numbers, [$startDateTime, $endDateTime]);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        die("SQL Server query failed: " . print_r(sqlsrv_errors(), true));
    }

    // Debug: Output card log data being fetched
    if ($debug) {
        echo "<pre>Card Log Data:</pre>";
    }

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $cardID = $row['CardID'];

        // Translate ReaderFunction
        $readerFunctionMapping = [
            1 => 'DIN',
            2 => 'DOUT',
            3 => 'GIN',
            4 => 'GOUT'
        ];
        $readerFunctionLabel = $readerFunctionMapping[$row['ReaderFunction']] ?? 'UNKNOWN';

        // Convert ReadAt to string format
        $readAt = $row['ReadAt'];
        $readAtString = $readAt->format('Y-m-d H:i:s');
        $readAtDate = $readAt->format('Y-m-d');

        // Prepare entry without shift logic
        $entry = [
            'numerrfid' => $cardID,
            'read_at' => $readAtString,
            'reader' => $row['Reader'],
            'reader_function' => $readerFunctionLabel,
        ];

        // Debug: Print each entry
        if ($debug) {
            echo "<pre>";
            print_r($entry);
            echo "</pre>";
        }

        // Map entries directly to their respective dates
        foreach ($data as &$person) {
            foreach ($person['personal_info'] as $info) {
                if ($info['numerrfid'] == $cardID) {
                    if (!isset($person['entries'][$readAtDate])) {
                        $person['entries'][$readAtDate] = [];
                    }
                    $person['entries'][$readAtDate][] = $entry;
                }
            }
        }
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
}

?>
