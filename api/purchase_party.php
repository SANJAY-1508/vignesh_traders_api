<?php
include 'config/dbconfig.php';

$allowed_origins = [
    "http://localhost:3000",
    "http://192.168.1.6:3000"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

// 1. List purchase parties with search + date filter + balance sheet
if (isset($obj->search_text)) {

    $search_text = $conn->real_escape_string($obj->search_text);
    $company_id  = $conn->real_escape_string($obj->company_id);

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

            // ================================
            // PURCHASE DATA
            // ================================
            $purchase_sql = "SELECT purchase_id, bill_no, 
                             DATE_FORMAT(bill_date, '%Y-%m-%d') as bill_date,
                             created_date,
                             total, paid, balance_amount AS balance, remark, payment_method
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
                    "id"            => $p["purchase_id"],
                    "type"          => "Purchase",
                    "date"          => $p["bill_date"],
                    "created_at"    => $p["created_date"],
                    "receipt_no"    => $p["bill_no"],
                    "amount"        => $p["total"],
                    "paid"          => $p["paid"],
                    "balance"       => $p["balance"],
                    "details"       => $p["remark"],
                    "payment_method"=> $p["payment_method"]
                ];
            }

            // ================================
            // PAYOUT DATA
            // ================================
            $payout_sql = "SELECT payout_id, voucher_no, details,
                           DATE_FORMAT(voucher_date, '%Y-%m-%d') as voucher_date,
                           created_date,
                           paid, payment_method_name
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
                    "id"            => $pay["payout_id"],
                    "type"          => "Payout",
                    "date"          => $pay["voucher_date"],
                    "created_at"    => $pay["created_date"],
                    "receipt_no"    => $pay["voucher_no"],
                    "amount"        => $pay["paid"],
                    "paid"          => $pay["paid"],
                    "details"       => $pay["details"],
                    "payment_method"=> $pay["payment_method_name"],
                    "balance"       => "0"
                ];
            }

            // ==========================================
            // FINAL SORTING – Chronological order
            // ==========================================
            usort($transactions, function($a, $b) {
                $d1 = strtotime($a["date"]);
                $d2 = strtotime($b["date"]);

                if ($d1 === $d2) {
                    if (!empty($a["created_at"]) && !empty($b["created_at"])) {
                        return strtotime($a["created_at"]) - strtotime($b["created_at"]);
                    }
                    return $a["id"] - $b["id"];
                }
                return $d1 - $d2;
            });

            // =============================================
            // BUILD PARTY-WISE BALANCE SHEET (Ledger Style)
            // =============================================
            $balanceSheet = [];
            $runningBalance = floatval($row['opening_balance'] ?? 0);

            foreach ($transactions as $txn) {

                $entry = [
                    "Date"        => $txn["date"],
                    "Particulars" => "",
                    "Credit"      => "0",
                    "Debit"       => "0",
                    "Balance"     => "0"
                ];

                // PURCHASE → DEBIT (you owe supplier more)
                if ($txn["type"] === "Purchase") {
                    $particular = "Bill No: " . $txn["receipt_no"];
                    if (!empty($txn["details"])) {
                        $particular .= " - " . $txn["details"];
                    }
                    $entry["Particulars"] = $particular;
                    $entry["Debit"] = $txn["amount"];
                    $runningBalance += floatval($txn["amount"]);
                }

                // PAYOUT → CREDIT (you paid supplier → owe less)
                if ($txn["type"] === "Payout") {
                    $particular = $txn["receipt_no"] . " " . $txn["details"];
                    if (!empty($txn["payment_method"])) {
                        $particular .= " (" . trim($txn["payment_method"]) . ")";
                    }
                    $entry["Particulars"] = trim($particular);
                    $entry["Credit"] = $txn["amount"];
                    $runningBalance -= floatval($txn["amount"]);
                }

                $entry["Balance"] = number_format($runningBalance, 2, '.', '');
                $balanceSheet[] = $entry;
            }

            $row["transactions"] = $transactions;
            $row["party_wise_balance_sheet_report"] = $balanceSheet;

            $data[] = $row;
        }

        $output["status"] = 200;
        $output["msg"]    = "Success";
        $output["data"]   = $data;
    } else {
        $output["status"] = 400;
        $output["msg"]    = "No Records Found";
    }
}

// 2. Update existing purchase party
else if (isset($obj->company_id) && isset($obj->edit_party_id)) {
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
    $stmt->close();
}

// 3. Create new purchase party
else if (isset($obj->party_name) && isset($obj->company_id)) {
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

    $openDate = date('Y-m-d', strtotime($opening_date));
    $sql = "INSERT INTO purchase_party (company_id, party_name, mobile_number, alter_number, email, company_name, gst_no, address, city, state, opening_balance, opening_date, ac_type, delete_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        $output["status"] = 400;
        $output["msg"] = "Prepare failed: (" . $conn->errno . ") " . $conn->error;
        echo json_encode($output);
        exit();
    }

    $stmt->bind_param("ssssssssssdss", $compID, $party_name, $mobile_number, $alter_number, $email, $company_name, $gst_no, $address, $city, $state, $opening_balance, $openDate, $ac_type);

    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $uniqueID = uniqueID("purchase_party", $id);

        $updateSql = "UPDATE purchase_party SET party_id=? WHERE id=? AND company_id=?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("sis", $uniqueID, $id, $compID);

        if ($updateStmt->execute()) {
            $output["status"] = 200;
            $output["msg"] = "Purchase Party Created Successfully";
            $output["data"] = array("party_id" => $uniqueID);
        } else {
            $output["status"] = 400;
            $output["msg"] = "Error updating record: " . $updateStmt->error;
        }
        $updateStmt->close();
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error inserting record: " . $stmt->error;
    }

    $stmt->close();
}

// 4. Soft delete purchase party
else if (isset($obj->delete_party_id) && isset($obj->company_id)) {
    $party_id = $conn->real_escape_string($obj->delete_party_id);
    $company_id = $obj->company_id;

    $sql = "UPDATE purchase_party SET delete_at = '1' WHERE party_id = ? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $party_id, $company_id);

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
}

// Invalid request
else {
    $output["status"] = 405;
    $output["msg"] = "Method Not Allowed";
}

echo json_encode($output, JSON_NUMERIC_CHECK);
$conn->close();
?>