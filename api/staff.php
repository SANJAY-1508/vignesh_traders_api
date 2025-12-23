<?php

include 'config/dbconfig.php'; // Include database connection
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin:*"); // Allow only your React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); // Allow HTTP methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth

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

// List Staff
if ($action === 'listStaff') {
    $query = "SELECT * FROM staff WHERE delete_at = 0 ORDER BY create_at DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $staff = $result->fetch_all(MYSQLI_ASSOC);
        $response = [
            "head" => ["code" => 200, "msg" => "Success"],
            "body" => ["staff" => $staff]
        ];
    } else {
        $response = [
            "head" => ["code" => 200, "msg" => "No Staff Found"],
            "body" => ["staff" => []]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Add Staff
elseif ($action === 'createStaff') {
    $staff_name = $obj->staff_name ?? null;
    $mobile_number = $obj->mobile_number ?? null;
    $role = $obj->role ?? null;
    $place = $obj->place ?? null;
    $wages_amount = $obj->wages_amount ?? null;

    // Validate Required Fields
    if ($staff_name) {
        // Prepare and execute the insert query for the staff
        $stmt = $conn->prepare("INSERT INTO staff (staff_name, mobile_number, role, place,wages_amount
, create_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "ssssss",
            $staff_name,
            $mobile_number,
            $role,
            $place,
            $wages_amount,
            $timestamp
        );

        if ($stmt->execute()) {
            // Get the inserted staff ID
            $insertId = $conn->insert_id;

            // Generate a unique staff ID
            $staff_id = uniqid('STF' . $insertId);  // Fixed: Use uniqid directly, assuming no custom uniqueID function

            // Update the staff record with the generated unique ID
            $stmtUpdate = $conn->prepare("UPDATE staff SET staff_id = ? WHERE id = ?");
            $stmtUpdate->bind_param("si", $staff_id, $insertId);

            if ($stmtUpdate->execute()) {
                $response = [
                    "head" => ["code" => 200, "msg" => "Staff Added Successfully"],
                    "body" => ["staff_id" => $staff_id] // Return the unique staff ID
                ];
            } else {
                $response = [
                    "head" => ["code" => 400, "msg" => "Failed to update Staff ID"]
                ];
            }

            $stmtUpdate->close();
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Add Staff. Error: " . $stmt->error]
            ];
        }

        $stmt->close();
    } else {
        $response = [
            "head" => ["code" => 400, "msg" => "Missing required fields (staff_name)"]
        ];
    }
    echo json_encode($response, JSON_NUMERIC_CHECK);
    exit();
}

// Update Staff
elseif ($action === 'updateStaff') {
    $edit_staff_id = $obj->edit_staff_id ?? null;
    $staff_name = $obj->staff_name ?? null;
    $mobile_number = $obj->mobile_number ?? null;
    $role = $obj->role ?? null;
    $place = $obj->place ?? null;
    $wages_amount = $obj->wages_amount ?? null;

    // Validate Required Fields
    if ($edit_staff_id && $staff_name) {
        $stmt = $conn->prepare("UPDATE staff SET staff_name = ?, mobile_number = ?, role = ?, place = ?, wages_amount = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $staff_name, $mobile_number, $role, $place, $wages_amount, $edit_staff_id);

        if ($stmt->execute()) {
            $response = [
                "head" => ["code" => 200, "msg" => "Staff Updated Successfully"]
            ];
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Update Staff. Error: " . $stmt->error]
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

// Delete Staff
elseif ($action === 'deleteStaff') {
    $delete_staff_id = $obj->delete_staff_id ?? null;

    if ($delete_staff_id) {
        // Correct SQL to update the delete_at column
        $stmt = $conn->prepare("UPDATE staff SET delete_at = 1  WHERE id = ?");
        $stmt->bind_param("i", $delete_staff_id);
        if ($stmt->execute()) {
            $response = [
                "head" => ["code" => 200, "msg" => "Staff Deleted Successfully"]
            ];
        } else {
            $response = [
                "head" => ["code" => 400, "msg" => "Failed to Delete Staff. Error: " . $stmt->error]
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

// Invalid Action
else {
    $response = [
        "head" => ["code" => 400, "msg" => "Invalid Action"]
    ];
}

// Close Database Connection
$conn->close();

// Return JSON Response
echo json_encode($response, JSON_NUMERIC_CHECK);
