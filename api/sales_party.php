<?php
include 'config/dbconfig.php'; // Database connection
$allowed_origins = [
    "http://localhost:3000",
<<<<<<< HEAD
    "http://192.168.1.6:3000"
=======
    "http://192.168.1.71:3000"
>>>>>>> 3934453c992752b5d20e5feb120645f62a832c39
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Optional: Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}



$json = file_get_contents('php://input');
$obj = json_decode($json);
$output = array();

date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

// List Sales Parties
if (isset($obj->search_text)) {

    $compID      = $obj->company_id;
    $searchText  = $obj->search_text;

    // Date filter
    $from_date = isset($obj->from_date) ? $obj->from_date : null;
    $to_date   = isset($obj->to_date) ? $obj->to_date : null;

    $sql = "SELECT party_id, party_name, mobile_number, alter_number, email, company_name, gst_no, billing_address, 
                   shipp_address, opening_balance, DATE_FORMAT(opening_date, '%Y-%m-%d') as opening_date, 
                   ac_type, city, state 
            FROM sales_party 
            WHERE delete_at = '0' 
            AND company_id = ? 
            AND (party_name LIKE ? OR mobile_number LIKE ? 
                 OR alter_number LIKE ? OR email LIKE ? 
                 OR company_name LIKE ? OR gst_no LIKE ?)";

    $stmt = $conn->prepare($sql);
    $searchParam = '%' . $searchText . '%';
    $stmt->bind_param('issssss', $compID, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $data = array();

        while ($row = $result->fetch_assoc()) {

            $party_id = $row["party_id"];
            $transactions = [];

            // -------------------------------------------------------
            // INVOICE DATA WITH DATE FILTER
            // -------------------------------------------------------
            $invoice_sql = "SELECT invoice_id, bill_no, 
                                   DATE_FORMAT(bill_date, '%Y-%m-%d') AS bill_date, 
                                   total, created_date, paid, balance,payment_method,remark,gst_amount,product
                            FROM invoice 
                            WHERE delete_at = '0'
                            AND company_id = ?
                            AND party_id = ?";

            if ($from_date && $to_date) {
                $invoice_sql .= " AND bill_date BETWEEN ? AND ?";
                $stmt_invoice = $conn->prepare($invoice_sql);
                $stmt_invoice->bind_param('ssss', $compID, $party_id, $from_date, $to_date);
            } else {
                $stmt_invoice = $conn->prepare($invoice_sql);
                $stmt_invoice->bind_param('ss', $compID, $party_id);
            }

            $stmt_invoice->execute();
            $invoiceResult = $stmt_invoice->get_result();

            while ($inv = $invoiceResult->fetch_assoc()) {
                $transactions[] = [
                    "id"         => $inv["invoice_id"],
                    "type"       => "Invoice",
                    "date"       => $inv["bill_date"],
                    "receipt_no" => $inv["bill_no"],
                    "amount"     => $inv["total"],
                    "paid"       => $inv["paid"],
                    "balance"    => $inv["balance"],
                    "create_date" => $inv["created_date"],
                    "details"  => $inv["remark"],
                    "gst" =>$inv["gst_amount"],
                    "payment_method" => $inv["payment_method"],
                    "product" =>$inv["product"]
                ];
            }

            // -------------------------------------------------------
            // PAYIN DATA WITH DATE FILTER
            // -------------------------------------------------------
            $payin_sql = "SELECT payin_id, receipt_no, details,
                                 DATE_FORMAT(receipt_date, '%Y-%m-%d') AS receipt_date,
                                 paid, created_date,payment_method_name
                          FROM payin 
                          WHERE delete_at = '0'
                          AND company_id = ?
                          AND party_id = ?";

            if ($from_date && $to_date) {
                $payin_sql .= " AND receipt_date BETWEEN ? AND ?";
                $stmt_payin = $conn->prepare($payin_sql);
                $stmt_payin->bind_param('ssss', $compID, $party_id, $from_date, $to_date);
            } else {
                $stmt_payin = $conn->prepare($payin_sql);
                $stmt_payin->bind_param('ss', $compID, $party_id);
            }

            $stmt_payin->execute();
            $payinResult = $stmt_payin->get_result();

            while ($pay = $payinResult->fetch_assoc()) {
                $transactions[] = [
                    "id"         => $pay["payin_id"],
                    "type"       => "Payin",
                    "date"       => $pay["receipt_date"],
                    "receipt_no" => $pay["receipt_no"],
                    "amount"     => $pay["paid"],
                    "paid"       => $pay["paid"],
                    "balance"    => "0",
                    "create_date" => $pay["created_date"],
                    "details"    => $pay["details"],
                    "payment_method" => $pay["payment_method_name"],
                     "gst" =>"0.00",
                      "product" =>[]
                ];
            }

            // SORT BY DATE DESC
           // SORT BY DATE ASCENDING
usort($transactions, function ($a, $b) {
    $timeA = strtotime($a["date"]);
    $timeB = strtotime($b["date"]);

    if ($timeA === $timeB) {
        // fallback ordering if dates are identical
        return strtotime($a["create_date"] ?? $a["date"]) - strtotime($b["create_date"] ?? $b["date"]);
    }

    return $timeA - $timeB;
});


            $row["transactions"] = $transactions;
            
            $balanceSheet = [];
            $runningBalance = 0;
            foreach ($transactions as $txn) {

                $entry = [
                    "Date"        => $txn["date"],
                    "Particulars" => "",
                    "Credit"      => "0",
                    "Debit"       => "0",
                    "Balance"     => "0"
                ];
            
                // ----- INVOICE → DEBIT -----
               if ($txn["type"] === "Invoice") {

    $particular = "";
    $productData = $txn["product"];

    if (!empty($productData)) {
        $items = json_decode($productData, true);

        if (is_array($items)) {
            $productSummaryArr = [];

            foreach ($items as $p) {

                $productName = $p["product_name"] ?? "";
                $qty         = $p["qty"] ?? "";
                $unitName    = strtoupper($p["unit_name"] ?? "");
                $unitCount   = $p["unit_count"] ?? "";
                $priceUnit   = $p["price_unit"] ?? "";

                // plural logic example: BAG -> BAGS (optional tweak if needed)
                $unitDisplay = $unitCount . " " . $unitName;

                // calc string → 1040 × 40
                $calcString = $qty . " × " . $priceUnit;

                // final push → 38 MM – 26 BAGS - 1040 × 40
                $productSummaryArr[] = $productName . " – " . $unitDisplay . " - " . $calcString;
            }

            $productSummary = implode(" | ", $productSummaryArr);

            // Build Bill format
            $bill = "(Bill No: " . $txn["receipt_no"];

            if (isset($txn["gst"]) && is_numeric($txn["gst"]) && floatval($txn["gst"]) > 0) {
                $bill .= " + GST";
            }

            $bill .= ")";

            // final combined
            $particular = $productSummary . " - " . $bill;
        }
    }

//     $entry["Particulars"] = $particular;
//     $entry["Debit"] = $txn["amount"];
//     $runningBalance += floatval($txn["amount"]);
// }


            
                    $entry["Particulars"] = $particular;
                    $entry["Debit"]       = $txn["amount"];
            
                    $runningBalance += floatval($txn["amount"]);
                }
            
                // ----- PAYIN → CREDIT -----
                if ($txn["type"] === "Payin") {
            
                    $entry["Particulars"] = $txn["receipt_no"] . " Payin Paid";
                    $entry["Credit"]      = $txn["amount"];
            
                    $runningBalance -= floatval($txn["amount"]);
                }
            
                // ----- If amount paid inside invoice -----
                if ($txn["type"] === "Invoice" && floatval($txn["paid"]) > 0) {
            
                    if ($txn["paid"] > 0) {
                        $ledgerPay = [
                            "Date"        => $txn["date"],
                            "Particulars" => $txn["receipt_no"] . " Paid",
                            "Credit"      => $txn["paid"],
                            "Debit"       => "0",
                            "Balance"     => "0"
                        ];
            
                        $runningBalance -= floatval($txn["paid"]);
                        $ledgerPay["Balance"] = number_format($runningBalance, 2, '.', '');
            
                        $balanceSheet[] = $ledgerPay;
                    }
                }
            
                $entry["Balance"] = number_format($runningBalance, 2, '.', '');
            
                $balanceSheet[] = $entry;
            }
            
            // Attach to response
            $row["party_wise_balance_sheet_report"] = $balanceSheet;

            $data[] = $row;
        }

        $output["code"] = 200;
        $output["msg"]  = "Success";
        $output["data"] = $data;
    } else {

        $output["code"] = 400;
        $output["msg"] = "No Records Found";
    }
} else if (!empty($obj->company_id) && !empty($obj->edit_party_id)) {
    $compID = $obj->company_id;
    $edit_party_id = $obj->edit_party_id;
    $party_name = $obj->party_name;
    $mobile_number = $obj->mobile_number;
    $alter_number = $obj->alter_number;
    $email = $obj->email;
    $company_name = $obj->company_name;
    $gst_no = $obj->gst_no;
    $billing_address = $obj->billing_address;
    $shipp_address = $obj->shipp_address;
    $opening_balance = $obj->opening_balance;
    $opening_date = $obj->opening_date;
    $ac_type = $obj->ac_type;
    $city = $obj->city;
    $state = $obj->state;
    $billing_address = preg_replace('/\s+/', ' ', $billing_address);
    $shipp_address = preg_replace('/\s+/', ' ', $shipp_address);
    // Correct UPDATE query without using insert_id since it's not needed
    $sql = "UPDATE sales_party 
            SET party_name = ?, mobile_number = ?, alter_number = ?, email = ?, company_name = ?, 
                gst_no = ?, billing_address = ?, shipp_address = ?, opening_balance = ?, opening_date = ?, 
                ac_type = ?, city = ?, state = ? 
            WHERE party_id = ? AND company_id = ?";

    // Prepare and bind the statement with correct types (s = string, i = integer, etc.)
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssss',
        $party_name,
        $mobile_number,
        $alter_number,
        $email,
        $company_name,
        $gst_no,
        $billing_address,
        $shipp_address,
        $opening_balance,
        $opening_date,
        $ac_type,
        $city,
        $state,
        $edit_party_id,
        $compID
    );

    // Execute and handle the result
    if ($stmt->execute()) {
        $output['code'] = 200;
        $output['msg'] = 'Sales Party Updated Successfully';
    } else {
        $output['code'] = 400;
        $output['msg'] = 'Error updating Sales Party';
    }
} else if (!empty($obj->company_id) && !empty($obj->party_name)) {
    $compID = $obj->company_id;
    $party_name = $obj->party_name;
    $mobile_number = $obj->mobile_number;
    $alter_number = $obj->alter_number;
    $email = $obj->email;
    $company_name = $obj->company_name;
    $gst_no = $obj->gst_no;
    $billing_address = $obj->billing_address;
    $shipp_address = $obj->shipp_address;
    $opening_balance = !empty($obj->opening_balance) ? $obj->opening_balance : '0.00';
    $opening_date = !empty($obj->opening_date) ? $obj->opening_date : date('Y-m-d H:i:s');
    $ac_type = $obj->ac_type;
    $city = !empty($obj->city) ? $obj->city : '';
    $state = !empty($obj->state) ? $obj->state : '';
    $billing_address = preg_replace('/\s+/', ' ', $billing_address);
    $shipp_address = preg_replace('/\s+/', ' ', $shipp_address);

    // Insert query with the correct number of columns and values
    $sql = "INSERT INTO sales_party (company_id, party_name, mobile_number, alter_number, email, company_name, gst_no, billing_address, shipp_address, opening_balance, opening_date, ac_type, city, state, delete_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";

    // Prepare and bind the statement
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssssssss', $compID, $party_name, $mobile_number, $alter_number, $email, $company_name, $gst_no, $billing_address, $shipp_address, $opening_balance, $opening_date, $ac_type, $city, $state);

    // Execute and handle the result
    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $uniqueID = uniqueID("sales_party", $id);
        $update_sql = "UPDATE sales_party SET party_id = ? WHERE id = ? AND company_id = ?";
        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param('sis', $uniqueID, $id, $compID);
        $stmt_update->execute();

        $output['code'] = 200;
        $output['msg'] = 'Sales Party Created Successfully';
        $output['data'] = ['party_id' => $uniqueID];
    } else {
        $output['code'] = 400;
        $output['msg'] = 'Error creating Sales Party';
    }
} else if (isset($obj->delete_party_id) && isset($obj->company_id)) {
    $party_id = $conn->real_escape_string($obj->delete_party_id);
    $company_id = $obj->company_id;

    // Delete query
    $sql = "UPDATE sales_party SET delete_at = '1' WHERE party_id = ? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $party_id, $company_id); // Assuming party_id is an integer

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $output["status"] = 200;
            $output["msg"] = "sales Party Deleted Successfully";
        } else {
            $output["status"] = 400;
            $output["msg"] = "No record found to delete";
            $output["data"] = $obj;
        }
    } else {
        $output["status"] = 400;
        $output["msg"] = "Error deleting record";
    }

    $stmt->close();
} else {
    $output['code'] = 400;
    $output['msg'] = 'Required parameters not set';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
