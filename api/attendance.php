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
}else if ($action === 'listAttendanceByRange') {
    // Validate and sanitize input dates
    $start_date = isset($obj->start_date) ? trim($obj->start_date) : '';
    $end_date = isset($obj->end_date) ? trim($obj->end_date) : '';

    if (empty($start_date) || empty($end_date)) {
        $response = [
            "head" => ["code" => 400, "msg" => "Start date and end date are required"],
            "body" => []
        ];
        echo json_encode($response);
        exit();
    }

    // Optional: Validate date format (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $response = [
            "head" => ["code" => 400, "msg" => "Invalid date format. Use YYYY-MM-DD"],
            "body" => []
        ];
        echo json_encode($response);
        exit();
    }

    // Prevent SQL injection using prepared statements
    $query = "SELECT attendance_id, entry_date, data, create_at 
              FROM attendance 
              WHERE delete_at = 0 
                AND entry_date BETWEEN ? AND ? 
              ORDER BY entry_date DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $response = [
            "head" => ["code" => 500, "msg" => "Database prepare error"],
            "body" => []
        ];
        echo json_encode($response);
        exit();
    }

    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $attendance = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $attendance[] = [
                "attendance_id" => $row["attendance_id"],
                "entry_date"     => $row["entry_date"],
                "data"           => json_decode($row["data"], true), // Ensure valid JSON
                "create_at"      => $row["create_at"]
            ];
        }

        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["attendance" => $attendance]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "No attendance found in the selected range"],
            "body" => ["attendance" => []]
        ];
    }

    $stmt->close();
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

    // Check if exists
    $stmtCheck = $conn->prepare("SELECT COUNT(*) as count FROM attendance WHERE entry_date = ? AND delete_at = 0");
    $stmtCheck->bind_param("s", $formattedDate);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    $rowCheck = $resultCheck->fetch_assoc();
    $stmtCheck->close();

    if ($rowCheck['count'] > 0) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Attendance already exists for this date"]
        ], JSON_NUMERIC_CHECK);
        exit();
    }

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
        // STEP 1: Fetch attendance data to process refunds
        // $stmtFetch = $conn->prepare("SELECT data FROM attendance WHERE attendance_id = ? AND delete_at = 0");
        // $stmtFetch->bind_param("s", $attendance_id);
        // $stmtFetch->execute();
        // $resultFetch = $stmtFetch->get_result();
        // $attendance_row = $resultFetch->fetch_assoc();
        // $stmtFetch->close();

        // if (!$attendance_row) {
        //     echo json_encode([
        //         "head" => ["code" => 404, "msg" => "Attendance not found"]
        //     ], JSON_NUMERIC_CHECK);
        //     exit();
        // }

        // $attendance_data = json_decode($attendance_row['data'], true);
        // $refund_errors = false;

        // // STEP 2: For each staff with deduction > 0, refund balance and soft delete advance record
        // if (is_array($attendance_data)) {
        //     foreach ($attendance_data as $item) {
        //         $staff_id = $item['staff_id'] ?? null;
        //         $deduction = (float) ($item['deduction'] ?? 0);

        //         if ($staff_id && $deduction > 0) {
        //             // Fetch staff internal ID and current balance (though we add directly)
        //             $stmtStaff = $conn->prepare("
        //                 SELECT id
        //                 FROM staff
        //                 WHERE staff_id = ? AND delete_at = 0
        //             ");
        //             $stmtStaff->bind_param("s", $staff_id);
        //             $stmtStaff->execute();
        //             $staffResult = $stmtStaff->get_result();
        //             $staff_row = $staffResult->fetch_assoc();
        //             $stmtStaff->close();

        //             if ($staff_row) {
        //                 $staff_row_id = $staff_row['id'];

        //                 // Refund: Add back deduction to advance_balance
        //                 $stmtRefund = $conn->prepare("
        //                     UPDATE staff
        //                     SET advance_balance = advance_balance + ?
        //                     WHERE id = ?
        //                 ");
        //                 $stmtRefund->bind_param("di", $deduction, $staff_row_id);
        //                 if (!$stmtRefund->execute()) {
        //                     $refund_errors = true;
        //                     error_log("Refund failed for staff_id: $staff_id - " . $stmtRefund->error);
        //                 }
        //                 $stmtRefund->close();

        //                 // Soft delete related staff_advance record
        //                 $stmtDeleteAdvance = $conn->prepare("
        //                     UPDATE staff_advance
        //                     SET delete_at = 1
        //                     WHERE attendance_id = ? AND staff_id = ? AND delete_at = 0
        //                 ");
        //                 $stmtDeleteAdvance->bind_param("ss", $attendance_id, $staff_id);
        //                 if (!$stmtDeleteAdvance->execute()) {
        //                     $refund_errors = true;
        //                     error_log("Advance delete failed for staff_id: $staff_id - " . $stmtDeleteAdvance->error);
        //                 }
        //                 $stmtDeleteAdvance->close();
        //             } else {
        //                 $refund_errors = true;
        //                 error_log("Staff not found for refund: $staff_id");
        //             }
        //         }
        //     }
        // }

        // STEP 3: Soft delete attendance
        $stmtDelete = $conn->prepare("UPDATE attendance SET delete_at = 1 WHERE attendance_id = ?");
        $stmtDelete->bind_param("s", $attendance_id);
        $delete_success = $stmtDelete->execute();
        $stmtDelete->close();

        if ($delete_success) {
            if ($refund_errors) {
                $response = [
                    "head" => ["code" => 200, "msg" => "Attendance deleted, but some refunds may have failed. Check logs."]
                ];
            } else {
                $response = [
                    "head" => ["code" => 200, "msg" => "Attendance Deleted Successfully"]
                ];
            }
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Delete Attendance. Error: " . $stmtDelete->error]
            ];
        }
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
    $current_advance_id = null;

    // STEP 2: Check for existing advance record
    $stmt = $conn->prepare("
        SELECT id, amount, advance_id
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
            $current_advance_id = null;
        } else {
            // Update amount and adjust balance
            $new_balance = $current_balance - $diff;
            $stmt = $conn->prepare("UPDATE staff_advance SET amount = ? WHERE id = ?");
            $stmt->bind_param("di", $new_amount, $advance_row['id']);
            $stmt->execute();
            $stmt->close();
            $current_advance_id = $advance_row['advance_id'];
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
            $current_advance_id = $advance_id;
        } else {

            $current_advance_id = null;
        }
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
            "new_balance" => $new_balance,
            "advance_id" => $current_advance_id
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
