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
elseif ($action === 'createAttendance') {
    $data = $obj->data ?? null;
    $date = $obj->date;
    $dateObj = new DateTime($date);

    $formattedDate = $dateObj->format('Y-m-d');
    $data_json = json_encode($data, true);

    $stmt = $conn->prepare("INSERT INTO attendance (attendance_id, entry_date, data, create_at) VALUES (?, ?, ?, ?)");
    $attendance_id = uniqid('ATT'); // Generate unique ID

    $stmt->bind_param("ssss", $attendance_id, $formattedDate, $data_json, $timestamp);

    if ($stmt->execute()) {
        $response = [
            "head" => ["code" => 200, "msg" => "Attendance created successfully"],
            "body" => ["attendance_id" => $attendance_id]
        ];
    } else {
        $response = [
            "head" => ["code" => 400, "msg" => "Failed to create attendance: " . $stmt->error]
        ];
    }
    $stmt->close();
    echo json_encode($response, JSON_NUMERIC_CHECK);
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
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Set Attendance Deduction (for create/edit adjustments)
elseif ($action === 'setAttendanceDeduction') {
    $attendance_id = $obj->attendance_id ?? null;
    $staff_id = $obj->staff_id ?? null;
    $new_amount = (float) ($obj->amount ?? 0);
    $date = $obj->date ?? date('Y-m-d');

    if (!$attendance_id || !$staff_id) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Missing attendance_id or staff_id"]
        ]);
        exit();
    }

    // STEP 1: Fetch current staff balance and row id
    $stmt = $conn->prepare("
        SELECT id, advance_balance, staff_name
        FROM staff
        WHERE staff_id = ? AND delete_at = 0
    ");
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff_row = $result->fetch_assoc();
    $stmt->close();

    if (!$staff_row) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Staff not found"]
        ]);
        exit();
    }

    $staff_row_id = $staff_row['id'];
    $current_balance = (float) $staff_row['advance_balance'];
    $staff_name = $staff_row['staff_name'];

    $new_balance = $current_balance;

    // STEP 2: Check for existing advance record
    $stmt = $conn->prepare("
        SELECT id, amount
        FROM staff_advance
        WHERE attendance_id = ? AND staff_id = ? AND delete_at = 0
    ");
    $stmt->bind_param("ss", $attendance_id, $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $advance_row = $result->fetch_assoc();
    $stmt->close();

    $entry_date = (new DateTime($date))->format('Y-m-d');

    if ($advance_row) {
        // Existing record
        $old_amount = (float) $advance_row['amount'];
        $diff = $new_amount - $old_amount;

        if ($new_amount === 0) {
            // Refund old amount and soft delete
            $new_balance = $current_balance + $old_amount;
            $stmt = $conn->prepare("UPDATE staff_advance SET delete_at = 1 WHERE id = ?");
            $stmt->bind_param("i", $advance_row['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            // Update amount and adjust balance
            $new_balance = $current_balance - $diff;
            $stmt = $conn->prepare("UPDATE staff_advance SET amount = ? WHERE id = ?");
            $stmt->bind_param("di", $new_amount, $advance_row['id']);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        // No existing record
        if ($new_amount > 0) {
            // Insert new record
            $advance_id = uniqid('ADV');
            $stmt = $conn->prepare("
                INSERT INTO staff_advance
                (advance_id, attendance_id, staff_id, staff_name, amount, type, recovery_mode, entry_date, created_at, delete_at)
                VALUES (?, ?, ?, ?, ?, 'less', 'salary', ?, ?, 0)
            ");
            $stmt->bind_param(
                "ssssdss",
                $advance_id,
                $attendance_id,
                $staff_id,
                $staff_name,
                $new_amount,
                $entry_date,
                $timestamp
            );
            $stmt->execute();
            $stmt->close();
            $new_balance = $current_balance - $new_amount;
        }
        // If new_amount == 0, no op
    }

    // STEP 3: Update staff balance if changed
    if ($new_balance != $current_balance) {
        $stmt = $conn->prepare("
            UPDATE staff
            SET advance_balance = ?
            WHERE id = ?
        ");
        $stmt->bind_param("di", $new_balance, $staff_row_id);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode([
        "head" => ["code" => 200, "msg" => "Attendance deduction set successfully"],
        "body" => [
            "new_balance" => $new_balance
        ]
    ], JSON_NUMERIC_CHECK);
    exit();
}

// Manual Add Advance (direct)
elseif ($action === 'addAdvance') {

    $staff_id      = $obj->staff_id ?? null;
    $staff_name    = $obj->staff_name ?? null;
    $amount        = (float) ($obj->amount ?? 0);
    $type          = $obj->type ?? null;
    $recovery_mode = isset($obj->recovery_mode) ? trim($obj->recovery_mode) : null;
    $date          = $obj->date ?? date('Y-m-d');

    if (!$staff_id || !$staff_name || !$amount || !$type) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Missing parameters"]
        ]);
        exit();
    }

    if (!in_array($type, ['add', 'less'])) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Invalid advance type"]
        ]);
        exit();
    }

    if ($type === 'less' && !in_array($recovery_mode, ['salary', 'direct'])) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Invalid recovery_mode value"]
        ]);
        exit();
    }


    /* STEP 1: Fetch current balance for specific staff */
    $stmt = $conn->prepare("
        SELECT id, advance_balance
        FROM staff
        WHERE staff_id = ? AND delete_at = 0
    ");
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Staff not found"]
        ]);
        exit();
    }

    $staff_row_id = $row['id'];
    $current_balance   = (float) $row['advance_balance'];

    /* STEP 2: Balance calculation */
    if ($type === 'add') {
        $new_balance = $current_balance + $amount;
    } else {
        if ($amount > $current_balance) {
            echo json_encode([
                "head" => ["code" => 400, "msg" => "Recovery exceeds balance"]
            ]);
            exit();
        }
        $new_balance = $current_balance - $amount;
    }

    /* STEP 3: Ledger insert */
    $advance_id = uniqid('ADV');
    $entry_date = (new DateTime($date))->format('Y-m-d');

    $stmt = $conn->prepare("
        INSERT INTO staff_advance
        (advance_id, staff_id, staff_name, amount, type, recovery_mode, entry_date, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "sssdssss",
        $advance_id,
        $staff_id,
        $staff_name,
        $amount,
        $type,
        $recovery_mode,
        $entry_date,
        $timestamp
    );
    $stmt->execute();
    $stmt->close();

    /* STEP 4: Update balance snapshot */
    $stmt = $conn->prepare("
        UPDATE staff
        SET advance_balance = ?
        WHERE id = ?
    ");
    $stmt->bind_param("di", $new_balance, $staff_row_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "head" => ["code" => 200, "msg" => "Advance transaction completed"],
        "body" => [
            "previous_balance" => $current_balance,
            "current_balance"  => $new_balance,
            "recovery_mode"    => $recovery_mode
        ]
    ], JSON_NUMERIC_CHECK);
    exit();
} else {
    $response = [
        "head" => ["code" => 400, "msg" => "Invalid Action"]
    ];
}

// Close Database Connection
$conn->close();

// Return JSON Response
echo json_encode($response, JSON_NUMERIC_CHECK);
