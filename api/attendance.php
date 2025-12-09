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
    
    $data_json = json_encode($data,true);


        // Create an individual attendance entry for each staff member
        $stmt = $conn->prepare("INSERT INTO attendance (attendance_id, entry_date, data, create_at) VALUES (?, ?, ?, ?)");
        $attendance_id = uniqid('ATT'); // Generate unique ID


        $stmt->bind_param("ssss", $attendance_id, $formattedDate, $data_json, $timestamp);

        if (!$stmt->execute()) {
            $errors[] = "Failed to insert attendance for " . $stmt->error;
        }else{
             $response = [
                "head" => ["code" => 200, "msg" => "Attendance created successfully"]
            ];
        }
        $stmt->close();
    
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
?>
