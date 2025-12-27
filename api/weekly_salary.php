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

// List Weekly Salary
if ($action === 'listWeeklySalary') {
    $query = "SELECT * FROM weekly_salary WHERE delete_at = 0 ORDER BY create_at DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $weekly_salary = [];
        while ($row = $result->fetch_assoc()) {
            $weekly_salary[] = [
                "id" => $row["id"],
                "weekly_salary_id" => $row["weekly_salary_id"],
                "from_date" => $row["from_date"],
                "to_date" => $row["to_date"],
                "salary_data" => json_decode($row["salary_data"], true), // Decode JSON data
                "create_at" => $row["create_at"]
            ];
        }
        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["weekly_salary" => $weekly_salary]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "No Weekly Salary Found"],
            "body" => ["weekly_salary" => []]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
} elseif ($action === 'listWeeklySalaryByRange') {
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
    $query = "SELECT weekly_salary_id, from_date, to_date, salary_data, create_at 
              FROM weekly_salary 
              WHERE delete_at = 0 
                AND from_date BETWEEN ? AND ? 
              ORDER BY from_date DESC";

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

    $weekly_salary = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $weekly_salary[] = [
                "weekly_salary_id" => $row["weekly_salary_id"],
                "from_date"     => $row["from_date"],
                "to_date"           => $row["to_date"],
                "salary_data"      => json_decode($row["salary_data"], true), // Ensure valid JSON
                "create_at"      => $row["create_at"]
            ];
        }

        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["weekly_salary" => $weekly_salary]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "No weekly salary found in the selected range"],
            "body" => ["weekly_salary" => []]
        ];
    }

    $stmt->close();
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Create Weekly Salary
elseif ($action === 'createWeeklySalary') {
    $data = $obj->data ?? null;
    $from_date = $obj->from_date ?? null;
    $to_date = $obj->to_date ?? null;

    if (empty($from_date) || empty($to_date) || empty($data) || !is_array($data)) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Missing or invalid parameters: from_date, to_date, and data (array) required"]
        ], JSON_NUMERIC_CHECK);
        exit();
    }

    $from_date_obj = new DateTime($from_date);
    $to_date_obj = new DateTime($to_date);
    $formatted_from = $from_date_obj->format('Y-m-d');
    $formatted_to = $to_date_obj->format('Y-m-d');
    $data_json = json_encode($data, true);

    // Check if exists
    $stmtCheck = $conn->prepare("SELECT COUNT(*) as count FROM weekly_salary WHERE from_date = ? AND to_date = ? AND delete_at = 0");
    $stmtCheck->bind_param("ss", $formatted_from, $formatted_to);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    $rowCheck = $resultCheck->fetch_assoc();
    $stmtCheck->close();

    if ($rowCheck['count'] > 0) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Weekly salary already exists for this date range"]
        ], JSON_NUMERIC_CHECK);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO weekly_salary (weekly_salary_id, from_date, to_date, salary_data, create_at) VALUES (?, ?, ?, ?, ?)");
    $weekly_salary_id = uniqid('WSY'); // Generate unique ID

    $stmt->bind_param("sssss", $weekly_salary_id, $formatted_from, $formatted_to, $data_json, $timestamp);

    if ($stmt->execute()) {
        $response = [
            "head" => ["code" => 200, "msg" => "Weekly salary created successfully"],
            "body" => ["weekly_salary_id" => $weekly_salary_id]
        ];
    } else {
        $response = [
            "head" => ["code" => 400, "msg" => "Failed to create weekly salary: " . $stmt->error]
        ];
    }
    $stmt->close();
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Update Weekly Salary
elseif ($action === 'updateWeeklySalary') {
    $weekly_salary_id = $obj->weekly_salary_id ?? null;
    $data = $obj->data ?? null;

    if ($weekly_salary_id && $data && is_array($data)) {
        $salary_data = json_encode($data);

        $stmt = $conn->prepare("UPDATE weekly_salary SET salary_data = ? WHERE weekly_salary_id = ? AND delete_at = 0");
        $stmt->bind_param("ss", $salary_data, $weekly_salary_id);

        if ($stmt->execute()) {
            $response = [
                "head" => ["code" => 200, "msg" => "Weekly salary updated successfully"]
            ];
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to update weekly salary. Error: " . $stmt->error]
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

// Get Weekly Salary
elseif ($action === 'getWeeklySalary') {
    $weekly_salary_id = $obj->weekly_salary_id ?? null;

    if (!$weekly_salary_id) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Missing weekly_salary_id"]
        ], JSON_NUMERIC_CHECK);
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM weekly_salary WHERE weekly_salary_id = ? AND delete_at = 0");
    $stmt->bind_param("s", $weekly_salary_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => [
                "salary_data" => json_decode($row["salary_data"], true)
            ]
        ];
    } else {
        $response = [
            "head" => ["code" => 404, "msg" => "Weekly salary not found"]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Delete Weekly Salary
elseif ($action === 'deleteWeeklySalary') {
    $weekly_salary_id = $obj->weekly_salary_id ?? null;

    if ($weekly_salary_id) {
        // STEP 1: Fetch weekly salary data to process refunds
        $stmtFetch = $conn->prepare("SELECT salary_data FROM weekly_salary WHERE weekly_salary_id = ? AND delete_at = 0");
        $stmtFetch->bind_param("s", $weekly_salary_id);
        $stmtFetch->execute();
        $resultFetch = $stmtFetch->get_result();
        $weekly_row = $resultFetch->fetch_assoc();
        $stmtFetch->close();

        if (!$weekly_row) {
            echo json_encode([
                "head" => ["code" => 404, "msg" => "Weekly salary not found"]
            ], JSON_NUMERIC_CHECK);
            exit();
        }

        $weekly_data = json_decode($weekly_row['salary_data'], true);
        $refund_errors = false;

        // STEP 2: For each staff with deduction > 0, refund balance and soft delete advance record
        if (is_array($weekly_data)) {
            foreach ($weekly_data as $item) {
                $staff_id = $item['staffId'] ?? null;
                $deduction = (float) ($item['advanceDeductionThisWeek'] ?? 0);

                if ($staff_id && $deduction > 0) {
                    // Fetch staff internal ID and current balance (though we add directly)
                    $stmtStaff = $conn->prepare("
                        SELECT id
                        FROM staff
                        WHERE staff_id = ? AND delete_at = 0
                    ");
                    $stmtStaff->bind_param("s", $staff_id);
                    $stmtStaff->execute();
                    $staffResult = $stmtStaff->get_result();
                    $staff_row = $staffResult->fetch_assoc();
                    $stmtStaff->close();

                    if ($staff_row) {
                        $staff_row_id = $staff_row['id'];

                        // Refund: Add back deduction to advance_balance
                        $stmtRefund = $conn->prepare("
                            UPDATE staff
                            SET advance_balance = advance_balance + ?
                            WHERE id = ?
                        ");
                        $stmtRefund->bind_param("di", $deduction, $staff_row_id);
                        if (!$stmtRefund->execute()) {
                            $refund_errors = true;
                            error_log("Refund failed for staff_id: $staff_id - " . $stmtRefund->error);
                        }
                        $stmtRefund->close();

                        // Soft delete related staff_advance record
                        $stmtDeleteAdvance = $conn->prepare("
                            UPDATE staff_advance
                            SET delete_at = 1
                            WHERE weekly_salary_id = ? AND staff_id = ? AND delete_at = 0
                        ");
                        $stmtDeleteAdvance->bind_param("ss", $weekly_salary_id, $staff_id);
                        if (!$stmtDeleteAdvance->execute()) {
                            $refund_errors = true;
                            error_log("Advance delete failed for staff_id: $staff_id - " . $stmtDeleteAdvance->error);
                        }
                        $stmtDeleteAdvance->close();
                    } else {
                        $refund_errors = true;
                        error_log("Staff not found for refund: $staff_id");
                    }
                }
            }
        }

        // STEP 3: Soft delete weekly salary
        $stmtDelete = $conn->prepare("UPDATE weekly_salary SET delete_at = 1 WHERE weekly_salary_id = ?");
        $stmtDelete->bind_param("s", $weekly_salary_id);
        $delete_success = $stmtDelete->execute();
        $stmtDelete->close();

        if ($delete_success) {
            if ($refund_errors) {
                $response = [
                    "head" => ["code" => 200, "msg" => "Weekly salary deleted, but some refunds may have failed. Check logs."]
                ];
            } else {
                $response = [
                    "head" => ["code" => 200, "msg" => "Weekly Salary Deleted Successfully"]
                ];
            }
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Delete Weekly Salary. Error: " . $stmtDelete->error]
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

// Set Weekly Salary Recovery (for create/edit adjustments)
elseif ($action === 'setWeeklySalaryRecovery') {
    $weekly_salary_id = $obj->weekly_salary_id ?? null;
    $staff_id = $obj->staff_id ?? null;
    $new_amount = (float) ($obj->amount ?? 0);
    $date = $obj->date ?? date('Y-m-d');

    if (!$weekly_salary_id || !$staff_id) {
        echo json_encode([
            "head" => ["code" => 400, "msg" => "Missing weekly_salary_id or staff_id"]
        ]);
        exit();
    }

    // STEP 1: Fetch current staff balance and row id
    $stmt = $conn->prepare("
        SELECT id, advance_balance, staff_name
        FROM staff
        WHERE staff_id = ? AND delete_at = 0
    ");
    if (!$stmt) {
        echo json_encode([
            "head" => ["code" => 500, "msg" => "Database prepare error for staff query"]
        ]);
        exit();
    }
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
        WHERE weekly_salary_id = ? AND staff_id = ? AND delete_at = 0
    ");
    if (!$stmt) {
        echo json_encode([
            "head" => ["code" => 500, "msg" => "Database prepare error for advance query"]
        ]);
        exit();
    }
    $stmt->bind_param("ss", $weekly_salary_id, $staff_id);
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
            if (!$stmt) {
                echo json_encode([
                    "head" => ["code" => 500, "msg" => "Database prepare error for delete advance"]
                ]);
                exit();
            }
            $stmt->bind_param("i", $advance_row['id']);
            if (!$stmt->execute()) {
                echo json_encode([
                    "head" => ["code" => 400, "msg" => "Failed to delete advance record: " . $stmt->error]
                ]);
                exit();
            }
            $stmt->close();
            $current_advance_id = null;
        } else {
            // Update amount and adjust balance
            $new_balance = $current_balance - $diff;
            $stmt = $conn->prepare("UPDATE staff_advance SET amount = ? WHERE id = ?");
            if (!$stmt) {
                echo json_encode([
                    "head" => ["code" => 500, "msg" => "Database prepare error for update advance"]
                ]);
                exit();
            }
            $stmt->bind_param("di", $new_amount, $advance_row['id']);
            if (!$stmt->execute()) {
                echo json_encode([
                    "head" => ["code" => 400, "msg" => "Failed to update advance record: " . $stmt->error]
                ]);
                exit();
            }
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
                (advance_id, weekly_salary_id, staff_id, staff_name, amount, type, recovery_mode, entry_date, created_at, delete_at)
                VALUES (?, ?, ?, ?, ?, 'less', 'salary', ?, ?, 0)
            ");
            if (!$stmt) {
                echo json_encode([
                    "head" => ["code" => 500, "msg" => "Database prepare error for insert advance: " . $conn->error]
                ]);
                exit();
            }
            $stmt->bind_param(
                "ssssdss",
                $advance_id,
                $weekly_salary_id,
                $staff_id,
                $staff_name,
                $new_amount,
                $entry_date,
                $timestamp
            );
            if (!$stmt->execute()) {
                echo json_encode([
                    "head" => ["code" => 400, "msg" => "Failed to insert advance record: " . $stmt->error]
                ]);
                exit();
            }
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
        if (!$stmt) {
            echo json_encode([
                "head" => ["code" => 500, "msg" => "Database prepare error for update staff balance"]
            ]);
            exit();
        }
        $stmt->bind_param("di", $new_balance, $staff_row_id);
        if (!$stmt->execute()) {
            echo json_encode([
                "head" => ["code" => 400, "msg" => "Failed to update staff balance: " . $stmt->error]
            ]);
            exit();
        }
        $stmt->close();
    }

    echo json_encode([
        "head" => ["code" => 200, "msg" => "Weekly salary recovery set successfully"],
        "body" => [
            "new_balance" => $new_balance,
            "advance_id" => $current_advance_id
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
