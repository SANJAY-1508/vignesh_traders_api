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

// Database connection check
if ($conn->connect_error) {
    $output["status"] = 500;
    $output["msg"] = "Connection failed: " . $conn->connect_error;
    echo json_encode($output);
    exit();
}

// List purchase parties with search filter
if (isset($obj->search_text)) {

    $search_text = $conn->real_escape_string($obj->search_text);
    $company_id  = $conn->real_escape_string($obj->company_id);

    // Date Filters
    $from_date = isset($obj->from_date) ? $obj->from_date : null;
    $to_date   = isset($obj->to_date) ? $obj->to_date : null;

    $sql = "SELECT party_id, party_name, mobile_number, alter_number, email, company_name, gst_no, address, city, state, opening_balance, 
            DATE_FORMAT(opening_date, '%Y-%m-%d') as opening_date, ac_type 
            FROM purchase_party 
            WHERE delete_at = '0' 
            AND company_id = '$company_id' 
            AND (party_name LIKE '%$search_text%' OR mobile_number LIKE '%$search_text%' OR alter_number LIKE '%$search_text%')";

    $result = $conn->query($sql);

    if ($result->num_rows > 0) {

        $data = array();

        while ($row = $result->fetch_assoc()) {

            $party_id = $row['party_id'];
            $transactions = [];

            // ----------------------------------------
            // PURCHASE DATA WITH DATE FILTER
            // ----------------------------------------
            $purchase_sql = "SELECT purchase_id, bill_no, 
                             DATE_FORMAT(bill_date, '%Y-%m-%d') as bill_date, 
                             total, paid, balance_amount  AS balance
                             FROM purchase 
                             WHERE delete_at = '0' 
                             AND company_id = '$company_id'
                             AND party_id = '$party_id'";

            if ($from_date && $to_date) {
                $purchase_sql .= " AND bill_date BETWEEN '$from_date' AND '$to_date'";
            }

            $purchase_result = $conn->query($purchase_sql);

            while ($p = $purchase_result->fetch_assoc()) {
                $transactions[] = [
                    "id"         => $p["purchase_id"],
                    "type"       => "Purchase",
                    "date"       => $p["bill_date"],
                    "receipt_no" => $p["bill_no"],
                    "amount"     => $p["total"],
                    "paid"       => $p["paid"],
                    "balance"    => $p["balance"]
                ];
            }

            // ----------------------------------------
            // PAYOUT DATA WITH DATE FILTER
            // ----------------------------------------
            $payout_sql = "SELECT payout_id, voucher_no,details,
                           DATE_FORMAT(voucher_date, '%Y-%m-%d') as voucher_date,
                           paid
                           FROM payout
                           WHERE delete_at = '0'
                           AND company_id = '$company_id'
                           AND party_id = '$party_id'";

            if ($from_date && $to_date) {
                $payout_sql .= " AND voucher_date BETWEEN '$from_date' AND '$to_date'";
            }

            $payout_result = $conn->query($payout_sql);

            while ($pay = $payout_result->fetch_assoc()) {
                $transactions[] = [
                    "id"         => $pay["payout_id"],
                    "type"       => "Payout",
                    "date"       => $pay["voucher_date"],
                    "receipt_no" => $pay["voucher_no"],
                    "amount"     => $pay["paid"],
                    "paid"       => $pay["paid"],
                    "details"    => $pay["details"],
                    "balance"    => "0"
                ];
            }

            // Sort by date DESC
            usort($transactions, function ($a, $b) {
                return strtotime($b["date"]) - strtotime($a["date"]);
            });

            $row["transactions"] = $transactions;
            $data[] = $row;
        }

        $output["status"] = 200;
        $output["msg"]    = "Success";
        $output["data"]   = $data;
    } else {
        $output["status"] = 400;
        $output["msg"]    = "No Records Found";
    }
} else if (isset($obj->company_id) && isset($obj->edit_party_id)) {
    $party_id = $obj->edit_party_id;
    $party_name = $obj->party_name;
    $mobile_number = $obj->mobile_number;
    $alter_number = isset($obj->alter_number) ? $obj->alter_number : null;
    $email = isset($obj->email) ? $obj->email : null;
    $company_name = isset($obj->company_name) ? $obj->company_name : null;
    $gst_no = $obj->gst_no;
    $address = $obj->address;
    $city = $obj->city;
    $state = $obj->state;
    $opening_balance = $obj->opening_balance;
    $opening_date = $obj->opening_date;
    $ac_type = $obj->ac_type;
    $company_id = $obj->company_id;

    // Update query
    $sql = "UPDATE purchase_party SET party_name=?, mobile_number=?, alter_number=?, email=?, company_name=?, gst_no=?, address=?, city=?, state=?, opening_balance=?, opening_date=?, ac_type=? WHERE party_id=? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssssss", $party_name, $mobile_number, $alter_number, $email, $company_name, $gst_no, $address, $city, $state, $opening_balance, $opening_date, $ac_type, $party_id, $company_id);

    if ($stmt->execute()) {
        $output["status"] = 200;
        $output["msg"] = "Purchase Party Details Updated Successfully";
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error updating record";
    }

    // Delete purchase party
} else if (isset($obj->party_name) && isset($obj->company_id)) {
    $compID = $obj->company_id;
    $party_name = $obj->party_name ?? null;
    $mobile_number = $obj->mobile_number ?? null;
    $alter_number = $obj->alter_number ?? null;
    $email = $obj->email ?? null;
    $company_name = $obj->company_name ?? null;
    $gst_no = $obj->gst_no ?? null;
    $address = $obj->address ?? null;
    $city = $obj->city ?? null;
    $state = $obj->state ?? null;
    $opening_balance = $obj->opening_balance ?? null;
    $opening_date = $obj->opening_date ?? null;
    $ac_type = $obj->ac_type ?? null;

    if (!$party_name) {
        $output["status"] = 400;
        $output["msg"] = "Parameter MisMatch";
        echo json_encode($output);
        exit();
    }

    // Insert query
    $openDate = date('Y-m-d', strtotime($opening_date));
    $sql = "INSERT INTO purchase_party (company_id, party_name, mobile_number, alter_number, email, company_name, gst_no, address, city, state, opening_balance, opening_date, ac_type, delete_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";

    // Updated to 13 parameters
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $output["status"] = 400;
        $output["msg"] = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        echo json_encode($output);
        exit();
    }

    // Bind parameters
    $stmt->bind_param("ssssssssssdss", $compID, $party_name, $mobile_number, $alter_number, $email, $company_name, $gst_no, $address, $city, $state, $opening_balance, $openDate, $ac_type);

    // Execute the statement
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $uniqueID = uniqueID("purchase_party", $id); // Call to uniqueID function

        // Update party_id
        $updateSql = "UPDATE purchase_party SET party_id=? WHERE id=? AND company_id=?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("sis", $uniqueID, $id, $compID);

        if ($updateStmt->execute()) {
            $output["status"] = 200;
            $output["msg"] = "Purchase Party Created Successfully";
            $output["data"] = array("party_id" => $uniqueID);
        } else {
            $output["status"] = 400;
            $output["msg"] = "Error updating record: " . $updateStmt->error; // Display error
        }
        $updateStmt->close();
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error inserting record: " . $stmt->error; // Display error
    }

    $stmt->close();
} else if (isset($obj->delete_party_id) && isset($obj->company_id)) {
    $party_id = $conn->real_escape_string($obj->delete_party_id);
    $company_id = $obj->company_id;

    // Delete query
    $sql = "UPDATE purchase_party SET delete_at = '1' WHERE party_id = ? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $party_id, $company_id); // Assuming party_id is an integer

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $output["status"] = 200;
            $output["msg"] = "Purchase Party Deleted Successfully";
        } else {
            $output["status"] = 404;
            $output["msg"] = "No record found to delete";
        }
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error deleting record";
    }

    $stmt->close();
} else {
    // Handle invalid request method
    $output["status"] = 405;
    $output["msg"] = "Method Not Allowed";
}

echo json_encode($output, JSON_NUMERIC_CHECK);
