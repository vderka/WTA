<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');

include_once("includes/config.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $period_start = $_POST['startDate'];
    $period_end = $_POST['endDate'];

    // Connect to MySQL
    $conn = new mysqli($mysqli_host, $mysqli_user, $mysqli_password, $mysqli_db);

    // Set UTF-8 charset
    $conn->set_charset("utf8mb4");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $data = [];

    // Prepare SQL query to fetch data within the specified date range
    $sql = "
        SELECT 
            o.sam_number,
            o.name,
            o.surname,
            o.onsite_order,
            o.ordered_for,
            o.shift,
            o.group_name,
            o.entered_at,
            o.left_at,
            o.resignation,
            o.department_id,
            d.company_owner_code,
            dn.depname AS dep_name,
            s.start_time AS shift_start_time,
            s.end_time AS shift_end_time,
            b.start_time AS break_start_time,
            b.end_time AS break_end_time,
            b.ispaid AS break_is_paid
        FROM 
            sepm_order_person o
        LEFT JOIN 
            sepm_departments d ON o.department_id = d.id
        LEFT JOIN 
            sepm_departments_names dn ON o.department_id = dn.department_id AND o.ordered_for BETWEEN dn.begda AND IFNULL(dn.enda, '9999-12-31')
        LEFT JOIN 
            sepm_shifts_to_departments s ON o.department_id = s.departament_id AND o.shift = s.shift AND o.ordered_for BETWEEN s.begda AND IFNULL(s.enda, '9999-12-31')
        LEFT JOIN 
            sepm_breaks_to_shifts b ON s.shift = b.shift_id
        WHERE 
            o.ordered_for BETWEEN ? AND ?
            AND o.is_deleted = 0
        ORDER BY 
            d.company_owner_code, o.sam_number, o.ordered_for, o.shift
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param('ss', $period_start, $period_end);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $company_code = $row['company_owner_code'];
            $sam_number = $row['sam_number'];
            $ordered_for = $row['ordered_for'];
            $shift = $row['shift'];

            // Group by company code
            if (!isset($data[$company_code])) {
                $data[$company_code] = [];
            }

            // Group by SAM number and assign name/surname once
            if (!isset($data[$company_code][$sam_number])) {
                $data[$company_code][$sam_number] = [
                    'name' => $row['name'],
                    'surname' => $row['surname'],
                    'orders' => []
                ];
            }

            // Group by ordered_for date
            if (!isset($data[$company_code][$sam_number]['orders'][$ordered_for])) {
                $data[$company_code][$sam_number]['orders'][$ordered_for] = [];
            }

            // Group by shift under the ordered_for date
            if (!isset($data[$company_code][$sam_number]['orders'][$ordered_for][$shift])) {
                $data[$company_code][$sam_number]['orders'][$ordered_for][$shift] = [];
            }

            // Aggregate data
            $entry = [
                'onsite_order' => $row['onsite_order'],
                'group_name' => $row['group_name'],
                'entered_at' => $row['entered_at'],
                'left_at' => $row['left_at'],
                'resignation' => $row['resignation'],
                'department_id' => $row['department_id'],
                'dep_info' => [
                    'dep_name' => $row['dep_name'],
                    'shift_start_time' => $row['shift_start_time'],
                    'shift_end_time' => $row['shift_end_time'],
                    'breaks' => []
                ]
            ];

            // Add break information if available
            if (!empty($row['break_start_time']) && !empty($row['break_end_time'])) {
                $entry['dep_info']['breaks'][] = [
                    'break_start_time' => $row['break_start_time'],
                    'break_end_time' => $row['break_end_time'],
                    'break_is_paid' => $row['break_is_paid']
                ];
            }

            $data[$company_code][$sam_number]['orders'][$ordered_for][$shift][] = $entry;
        }
    }

    $stmt->close();
    $conn->close();

    // Convert to JSON with error checking
    $json_output = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($json_output === false) {
        echo "JSON encoding error: " . json_last_error_msg();
    } else {
        header('Content-Type: application/json');
        echo $json_output;
    }
}
?>
