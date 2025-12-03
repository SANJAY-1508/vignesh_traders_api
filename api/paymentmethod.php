<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000"); 
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); 
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = array();
$compID = $_GET['id'];
date_default_timezone_set('Asia/Calcutta');

function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// List Payment Methods (Search)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $sql = "SELECT payment_method_id, payment_method_name FROM payment_methods WHERE delete_at = '0' AND company_id = ? AND payment_method_name LIKE ?";
    $payment_methods = fetchQuery($conn, $sql, [$compID, "%$search_text%"]);

    if (count($payment_methods) > 0) {
        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $payment_methods;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}

// Create Payment Method
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['payment_method_name'])) {
    $payment_method_name = $obj['payment_method_name'];
    $sql = "SELECT * FROM payment_methods WHERE payment_method_name = ? AND company_id = ?";
    $existingPaymentMethod = fetchQuery($conn, $sql, [$payment_method_name, $compID]);

    if (count($existingPaymentMethod) > 0) {
        $output['status'] = 400;
        $output['msg'] = 'Payment Method already exists';
    } else {
        $sqlInsert = "INSERT INTO payment_methods (company_id, payment_method_name, delete_at) VALUES (?, ?, '0')";
        $stmt = $conn->prepare($sqlInsert);
        $stmt->bind_param('ss', $compID, $payment_method_name);

        if ($stmt->execute()) {
            $insertId = $conn->insert_id;
            $uniqueID = "PM" . str_pad($insertId, 4, '0', STR_PAD_LEFT); 
            $sqlUpdate = "UPDATE payment_methods SET payment_method_id = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sis', $uniqueID, $insertId, $compID);

            if ($stmtUpdate->execute()) {
                $output['status'] = 200;
                $output['msg'] = 'Payment Method Created Successfully';
                $output['data'] = ['payment_method_id' => $uniqueID];
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error updating Payment Method ID';
            }
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error creating Payment Method';
        }
    }
}

// Update Payment Method
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $payment_method_id = $obj['payment_method_id'];
    $payment_method_name = $obj['payment_method_name'];

    if (!empty($payment_method_name) && !empty($payment_method_id)) {
        $sql = "UPDATE payment_methods SET payment_method_name = ? WHERE payment_method_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $payment_method_name, $payment_method_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Payment Method Updated Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating Payment Method';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
}

// Delete Payment Method
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $payment_method_id = $obj['payment_method_id'];

    if (!empty($payment_method_id)) {
        $sql = "UPDATE payment_methods SET delete_at = '1' WHERE payment_method_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $payment_method_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Payment Method Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting Payment Method';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid request';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
?>