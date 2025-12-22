<?php

include 'config/dbconfig.php'; // Include database connection
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin:*"); // Allow React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true"); // For cookies/auth

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// Ensure action is set
if (!isset($obj->action)) {
    echo json_encode([
        "head" => ["code" => 400, "msg" => "Action parameter is missing"]
    ]);
    exit();
}

$action = $obj->action; // Extract action from the request

// List Attendance
if ($action === 'listAttendance') {
    $query = "SELECT * FROM attendance WHERE delete_at = 0 ORDER BY create_at DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $attendance = [];
        while ($row = $result->fetch_assoc()) {
            $attendance[] = [
                "id" => $row["id"],
                "attendance_id" => $row["attendance_id"],
                "entry_date" => $row["entry_date"],
                "data" => json_decode($row["data"], true), // Decode JSON data
                "create_at" => $row["create_at"]
            ];
        }
        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["attendance" => $attendance]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "No Attendance Found"],
            "body" => ["attendance" => []]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Create Attendance
// Create Attendance
elseif ($action === 'createAttendance') {
    $data = $obj->data ?? null;
    $date = $obj->date;
    $dateObj = new DateTime($date);
    $formattedDate = $dateObj->format('Y-m-d');

    // --- NEW: Check if attendance for this date already exists ---
    $checkStmt = $conn->prepare("SELECT id FROM attendance WHERE entry_date = ? AND delete_at = 0 LIMIT 1");
    $checkStmt->bind_param("s", $formattedDate);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        // Attendance for this date already exists
        $response = [
            "head" => ["code" => 400, "msg" => "Attendance for this date already exists. Please use 'Edit' to make changes."]
        ];
        $checkStmt->close();
        echo json_encode($response);
        exit();
    }
    $checkStmt->close();
    // -------------------------------------------------------------

    $data_json = json_encode($data, true);

    // Create an individual attendance entry for each staff member
    $stmt = $conn->prepare("INSERT INTO attendance (attendance_id, entry_date, data, create_at) VALUES (?, ?, ?, ?)");
    $attendance_id = uniqid('ATT'); // Generate unique ID

    $stmt->bind_param("ssss", $attendance_id, $formattedDate, $data_json, $timestamp);

    if (!$stmt->execute()) {
        $response = [
            "head" => ["code" => 400, "msg" => "Failed to insert attendance: " . $stmt->error]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "Attendance created successfully"]
        ];
    }
    $stmt->close();
    
    // Ensure response is sent if the above code didn't already exit
    echo json_encode($response);
    exit();
}


// Update Attendance
elseif ($action === 'updateAttendance') {
    $attendance_id = $obj->attendance_id ?? null;
    $data = $obj->data ?? null;

    if ($attendance_id && $data && is_array($data)) {
        $attendance_data = json_encode($data);

        $stmt = $conn->prepare("UPDATE attendance SET data = ? WHERE attendance_id = ? AND delete_at = 0");
        $stmt->bind_param("ss", $attendance_data, $attendance_id);

        if ($stmt->execute()) {
            $response = [
                "head" => ["code" => 200, "msg" => "Attendance updated successfully"]
            ];
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to update attendance. Error: " . $stmt->error]
            ];
        }
        $stmt->close();
    } else {
        $response = [
            "head" => ["code" => 400, "msg" => "Missing or invalid parameters"]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Delete Attendance

elseif ($action === 'deleteAttendance') {
    $attendance_id = $obj->attendance_id ?? null;

    if ($attendance_id) {
        // Correct SQL to update the delete_at column
        $stmt = $conn->prepare("UPDATE attendance SET delete_at = 1 WHERE attendance_id = ?");
        $stmt->bind_param("s", $attendance_id); // Use "s" for string
        if ($stmt->execute()) {
            $response = [
                "head" => ["code" => 200, "msg" => "Attendance Deleted Successfully"]
            ];
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Delete Attendance. Error: " . $stmt->error]
            ];
        }
        $stmt->close();
    } else {
        $response = [
            "head" => ["code" => 400, "msg" => "Missing or Invalid Parameters"]
        ];
    }
}
elseif ($action === 'addAdvance') {
    $staff_id      = $obj->staff_id ?? null;
    $staff_name    = $obj->staff_name ?? null;
    $amount        = (float) ($obj->amount ?? 0);
    $type          = $obj->type ?? null; // 'add' or 'less'
    $recovery_mode = isset($obj->recovery_mode) ? trim($obj->recovery_mode) : null;
    $date          = $obj->date ?? date('Y-m-d');

    // 1. Correct the Date Format (Fixes the Fatal Error)
    if (strpos($date, '/') !== false) {
        $parts = explode('/', $date);
        $entry_date = (strlen($parts[2]) == 2 ? "20".$parts[2] : $parts[2])."-".$parts[1]."-".$parts[0];
    } else {
        $entry_date = (new DateTime($date))->format('Y-m-d');
    }

    /* STEP 1: Fetch current staff master balance */
    $stmt = $conn->prepare("SELECT advance_balance FROM staff WHERE staff_id = ? AND delete_at = 0 LIMIT 1");
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $staff_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff_data) {
        echo json_encode(["head" => ["code" => 400, "msg" => "Staff not found"]]);
        exit();
    }

    $current_staff_balance = (float)$staff_data['advance_balance'];

    /* STEP 2: Check for existing salary deduction on this date to prevent double entry */
    $checkStmt = $conn->prepare("
        SELECT advance_id, amount FROM staff_advance 
        WHERE staff_id = ? AND entry_date = ? AND recovery_mode = 'salary' 
        LIMIT 1
    ");
    $checkStmt->bind_param("ss", $staff_id, $entry_date);
    $checkStmt->execute();
    $existingRecord = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existingRecord) {
        /* UPDATE CASE: Edit existing entry */
        $old_amount = (float)$existingRecord['amount'];
        $advance_id = $existingRecord['advance_id'];

        // Logic: Revert the old deduction and apply the new one
        // If it was a deduction (less), we add back the old amount and subtract the new one
        $new_balance = $current_staff_balance + $old_amount - $amount;

        $upd = $conn->prepare("UPDATE staff_advance SET amount = ? WHERE advance_id = ?");
        $upd->bind_param("ds", $amount, $advance_id);
        $upd->execute();
        $upd->close();
    } else {
        /* INSERT CASE: New entry */
        $advance_id = uniqid('ADV');

        // Logic: If 'add', balance goes UP. If 'less', balance goes DOWN.
        if ($type === 'add') {
            $new_balance = $current_staff_balance + $amount;
        } else {
            $new_balance = $current_staff_balance - $amount;
        }

        $ins = $conn->prepare("INSERT INTO staff_advance (advance_id, staff_id, staff_name, amount, type, recovery_mode, entry_date, created_at) VALUES (?,?,?,?,?,?,?,?)");
        $ins->bind_param("sssdssss", $advance_id, $staff_id, $staff_name, $amount, $type, $recovery_mode, $entry_date, $timestamp);
        $ins->execute();
        $ins->close();
    }

    /* STEP 3: Update Staff Master Balance with the corrected math */
    $stmt = $conn->prepare("UPDATE staff SET advance_balance = ? WHERE staff_id = ?");
    $stmt->bind_param("ds", $new_balance, $staff_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "head" => ["code" => 200, "msg" => "Advance updated successfully"],
        "body" => ["new_balance" => $new_balance]
    ]);
    exit();
}
else {
    $response = [
        "head" => ["code" => 400, "msg" => "Invalid Action"]
    ];
}

// Close Database Connection
$conn->close();

// Return JSON Response
echo json_encode($response, JSON_NUMERIC_CHECK);
?>
