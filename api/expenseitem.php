<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000"); // Allow only your React app
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE"); // Allow HTTP methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Allow headers
header("Access-Control-Allow-Credentials: true"); // If needed for cookies/auth

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = array();
$compID = $_GET['id'];

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

class BillNoCreation
{
    public static function create($params)
    {
        $prefix_name = $params['prefix_name'];
        $crtFinancialYear = self::getFinancialYear();
        $oldBillNumber = $params['billno'];

        // If the old bill number is 0
        if ($oldBillNumber == '0') {
            $oldBillNumber = "{$prefix_name}/0/{$crtFinancialYear}"; // Adjust this logic if needed
        }

        $explodedBillNumber = explode("/", $oldBillNumber);
        if (count($explodedBillNumber) < 2) {
            return 'Invalid bill number format'; // Handle invalid format
        }

        $lastBillNumber = $explodedBillNumber[1];
        $currentBillNumber = intval($lastBillNumber) + 1;

        $currentBillNumber = self::billNumberFormat($currentBillNumber);

        $result = "{$prefix_name}/{$currentBillNumber}/{$crtFinancialYear}";
        return $result;
    }


    private static function getFinancialYear()
    {
        // Logic to determine the current financial year
        $currentYear = date('Y');
        $currentMonth = date('m');

        if ($currentMonth >= 4) {
            // FY starts in April of the current year
            return substr($currentYear, 2) . '-' . substr($currentYear + 1, 2);
        } else {
            // FY starts in April of the previous year
            return substr($currentYear - 1, 2) . '-' . substr($currentYear, 2);
        }
    }

    private static function billNumberFormat($number)
    {
        // Format the bill number as needed (e.g., pad with zeros)
        return str_pad($number, 3, '0', STR_PAD_LEFT); // Change 3 to the required number of digits
    }
}

// MySQL query function
function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// List Expenses
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $from_date = $obj['from_date'] ?? null;
    $to_date = $obj['to_date'] ?? null;

    $sql = "SELECT expenses_item_id, category_id, DATE_FORMAT(created_date, '%Y-%m-%d') as bill_date, party_name, total,details 
            FROM expenses_item 
            WHERE delete_at = '0' AND company_id = ? 
            AND (party_name LIKE ? OR created_date BETWEEN ? AND ?)";
    $result = fetchQuery($conn, $sql, [$compID, "%$search_text%", $from_date, $to_date]);

    if ($result->num_rows > 0) {
        $output['status'] = 200;
        $output['msg'] = "Success";
        $output['data'] = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No records found';
    }
}

// Create Expense Item
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['category_id'], $obj['party_name'], $obj['total'], $obj['details'])) {
    $category_id = $obj['category_id'];
    $details = $obj['details'];
    $party_name = $obj['party_name'];
    $total = $obj['total'];

    $delete = "0";

    if (empty($category_id) || empty($party_name) || empty($total)) {
        $output['status'] = 400;
        $output['msg'] = "Parameter MisMatch";
    } else {
        $sqlbill = "INSERT INTO expenses_item (company_id, category_id, party_name, details, total, delete_at) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sqlbill);
        $stmt->bind_param('ssssss', $compID, $category_id, $party_name, $details, $total, $delete); 
        
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $id = $stmt->insert_id;
            $uniqueID = uniqueID("expenses_item", $id); 

            $sqlUpdate = "UPDATE expenses_item SET expenses_item_id = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sis', $uniqueID, $id, $compID);
            $stmtUpdate->execute();

            if ($stmtUpdate->affected_rows > 0) {
                $output['status'] = 200;
                $output['msg'] = "Expenses Item Details Created Successfully";
                $output['data'] = ['item_id' => $uniqueID];
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error updating expense item';
            }
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error creating expense item';
        }
    }
}

// Update Expense Item
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $expenses_item_id = $obj['expenses_item_id'];
    $category_id = $obj['category_id'];
    $party_name = $obj['party_name'];
    $total = $obj['total'];
    $details = $obj['details']; 

    if (!empty($expenses_item_id) && !empty($category_id) && !empty($party_name) && !empty($total)) {
        
        $sql = "UPDATE expenses_item SET category_id = ?, party_name = ?, details = ?, total = ? WHERE expenses_item_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssss', $category_id, $party_name, $details, $total, $expenses_item_id);
        
        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = "Expenses Item Details Updated Successfully";
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating expense item';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter MisMatch';
    }
}

// Delete Expense Item
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $expenses_item_id = $obj['expenses_item_id'];

    if (!empty($expenses_item_id)) {
        $sql = "UPDATE expenses_item SET delete_at = '1' WHERE expenses_item_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $expenses_item_id);
        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = "Expenses Item deleted Successfully";
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting expense item';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter MisMatch';
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid request';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
