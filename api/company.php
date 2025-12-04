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
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// List Function
if (isset($obj->search_text)) {
    $sql = "SELECT company_id, company_name, mobile_number, gst_no, address, city, state, bill_prefix, fssai_code, bank_name, ifsc_code,acc_name, acc_no, upi_no FROM company WHERE delete_at = '0'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $data = array();
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        $output["status"] = 200;
        $output["msg"] = "Success";
        $output["data"] = $data;
    } else {
        $output["status"] = 400;
        $output["msg"] = "No Records Found";
    }
} else if (!empty($obj->company_id) && !empty($obj->company_name) && !empty($obj->mobile_number) && !empty($obj->gst_no) && !empty($obj->address) && !empty($obj->city) && !empty($obj->state) && !empty($obj->bill_prefix)) {
    // Required fields
    $company_id = $obj->company_id;
    $company_name = $obj->company_name;
    $mobile_number = $obj->mobile_number;
    $gst_no = $obj->gst_no;
    $address = $obj->address;
    $city = $obj->city;
    $state = $obj->state;
    $bill_prefix = $obj->bill_prefix;

    // Optional fields (nullable)
    $fssai_code = isset($obj->fssai_code) ? $obj->fssai_code : null;
    $acc_name = isset($obj->acc_name) ? $obj->acc_name : null;
    $bank_name = isset($obj->bank_name) ? $obj->bank_name : null;
    $ifsc_code = isset($obj->ifsc_code) ? $obj->ifsc_code : null;
    $acc_no = isset($obj->acc_no) ? $obj->acc_no : null;
    $upi_no = isset($obj->upi_no) ? $obj->upi_no : null;

    // Update query
    $sql = "UPDATE company SET company_name=?, mobile_number=?, gst_no=?, address=?, city=?, state=?, bill_prefix=?, fssai_code=?, bank_name=?, ifsc_code=?,acc_name=?, acc_no=?, upi_no=? WHERE company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssssss", $company_name, $mobile_number, $gst_no, $address, $city, $state, $bill_prefix, $fssai_code, $bank_name, $ifsc_code, $acc_name, $acc_no, $upi_no, $company_id);

    if ($stmt->execute()) {
        $output["status"] = 200;
        $output["msg"] = "Company Details Updated Successfully";
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error updating record";
    }
} else {
    $output["status"] = 400;
    $output["msg"] = "Required parameters not set";
}
echo json_encode($output, JSON_NUMERIC_CHECK);
