<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
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
    if (!$stmt) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    if (!$stmt->execute()) {
        die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log("Fetch failed: (" . $stmt->errno . ") " . $stmt->error);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}
// List Sale Invoices
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $from_Date = $obj['from_date'];
    $to_date = $obj['to_date'];
    // SQL Query
    $sql = "SELECT *
        FROM invoice
        WHERE delete_at = '0'
        AND company_id = ?
        AND ((bill_date BETWEEN ? AND ?)
        OR JSON_UNQUOTE(JSON_EXTRACT(party_details, '$.party_name')) LIKE ?
        OR bill_no LIKE ?)
        ORDER BY id DESC";
    // Prepare parameters
    $params = [$compID, $from_Date, $to_date, "%$search_text%", "%$search_text%"];
    $invoices = fetchQuery($conn, $sql, $params);
    if (count($invoices) > 0) {
        foreach ($invoices as &$invoice) {
            $invoice['bill_date'] = date('Y-m-d', strtotime($invoice['bill_date']));
            $invoice['product'] = json_decode($invoice['product'], true);
            $invoice['company_details'] = json_decode($invoice['company_details'], true);
            $invoice['party_details'] = json_decode($invoice['party_details'], true);
        }
        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $invoices;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}
// Create Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_id'])) {
    $party_name = $obj['party_name'];
    $party_id = $obj['party_id'];
    $bill_date = $obj['bill_date'];
    $eway_no = $obj['eway_no'];
    $vechile_no = $obj['vechile_no'];
    $address = $obj['address'];
    $product = $obj['product'];
    $total = $obj['total'];
    $paid = isset($obj['paid']) && $obj['paid'] !== '' ? $obj['paid'] : 0;
    $balance_amount = isset($obj['balance_amount']) && $obj['balance_amount'] !== '' ? $obj['balance_amount'] : 0;
    $mobile_number = $obj['mobile_number'];
    $state_of_supply = $obj['state_of_supply'];
    $payment_method = $obj['payment_method'];
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $discount = isset($obj['discount']) && $obj['discount'] !== '' ? $obj['discount'] : 0;
    $discount_amount = isset($obj['discount_amount']) && $obj['discount_amount'] !== '' ? $obj['discount_amount'] : 0;
    $discount_type = $obj['discount_type'];
    $gst_type = $obj['gst_type'];
    $gst_amount = isset($obj['gst_amount']) && $obj['gst_amount'] !== '' ? $obj['gst_amount'] : 0;
    $subtotal = $obj['subtotal'];
    $round_off = isset($obj['round_off']) ? intval($obj['round_off']) : 0;
    $round_off_amount = isset($obj['round_off_amount']) ? floatval($obj['round_off_amount']) : 0;
    $overall_total =  $obj['overall_total'] ?? null;
    $payment_method_json = json_encode($payment_method, true);
    try {
        // Parameter validation
        if (!$party_id || !$bill_date || !$product || !isset($total)) {
            echo json_encode([
                'status' => 400,
                'msg' => "Parameter Mismatch",
            ]);
            exit();
        }
        // Fetch party details
        $partySql = "SELECT * FROM sales_party WHERE party_id = ? AND company_id = ? AND delete_at = 0";
        $partyData = fetchQuery($conn, $partySql, [$party_id, $compID]);
        if (empty($partyData)) {
            echo json_encode(['status' => 400, 'msg' => 'Party Not Found']);
            exit();
        }
        $party_details_json = json_encode($partyData[0]);
        // Get company details
        $companyDetailsSQL = "SELECT * FROM company WHERE company_id = ?";
        $companyDetailsresult = fetchQuery($conn, $companyDetailsSQL, [$compID]);
        if (empty($companyDetailsresult)) {
            echo json_encode(['status' => 400, 'msg' => 'Company Details Not Found']);
            exit();
        }
        $sum_total = 0;
        foreach ($product as $i => $element) {
            // Tax excluded amount calculation
            $qty = floatval($element['qty']);
            $price = floatval($element['price_unit']);
            $product_discount_amt = !empty($element['discount_amt']) ? floatval($element['discount_amt']) : 0;
            $element['without_tax_amount'] = ($qty * $price) - $product_discount_amt;
            $sum_total += $element['without_tax_amount'];
            // Fetch unit name
            $sqlunit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = 0";
            $unitData = fetchQuery($conn, $sqlunit, [$element['unit']]);
            if (!empty($unitData)) {
                $element['unit_name'] = $unitData[0]['unit_name'];
            }
            // Save back updated product to original array
            $product[$i] = $element;
        }
        $companyData = json_encode($companyDetailsresult[0]);
        foreach ($product as $key => $pro) {
            if (isset($pro['product_name']) && $pro['product_name'] !== null) {
                $product[$key]['product_name'] = str_replace('"', '\"', $pro['product_name']);
            }
        }
        $product_json = json_encode($product, JSON_UNESCAPED_UNICODE);
        $billDate = date('Y-m-d', strtotime($bill_date));
        $delete_at = 0;
        // Insert invoice into database
        $sqlinvoice = "INSERT INTO invoice (company_id, party_id,party_name, party_details, bill_date, product, sub_total, discount,discount_amount,discount_type,gst_type,gst_amount, total, overall_total, paid, balance, delete_at, eway_no, vechile_no, address, mobile_number, company_details, sum_total, round_off, round_off_amount, state_of_supply, remark, payment_method)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sqlinvoice);
        $stmt->bind_param("ssssssddsssssdisssssssssdsss", $compID, $party_id, $party_name, $party_details_json, $billDate, $product_json, $subtotal, $discount, $discount_amount, $discount_type, $gst_type, $gst_amount, $total, $overall_total, $paid, $balance_amount, $delete_at, $eway_no, $vechile_no, $address, $mobile_number, $companyData, $sum_total, $round_off, $round_off_amount, $state_of_supply, $remark, $payment_method_json);
        if ($stmt->execute()) {
            $id = $conn->insert_id;
        } else {
            echo json_encode(['status' => 400, 'msg' => 'Invoice Creation Failed: ' . $conn->error]);
            exit();
        }
        // Generate unique invoice ID
        $uniqueID = uniqueID("invoice", $id);
        // Fetch the last bill number from the database
        $lastBillSql = "SELECT bill_no FROM invoice WHERE company_id = ? AND bill_no IS NOT NULL ORDER BY id DESC LIMIT 1";
        $resultLastBill = fetchQuery($conn, $lastBillSql, [$compID]);
        // Fetch bill prefix from the company settings
        $billPrefixSql = "SELECT bill_prefix FROM company WHERE company_id = ?";
        $resultBillPrefix = fetchQuery($conn, $billPrefixSql, [$compID]);
        // Determine the fiscal year
        $year = date('y');
        $fiscal_year = ($year . '-' . ($year + 1));
        // Initialize the bill number
        $billcount = 1;
        if (!empty($resultLastBill[0]['bill_no'])) {
            preg_match('/\/(\d+)\/\d{2}-\d{2}$/', $resultLastBill[0]['bill_no'], $matches);
            if (isset($matches[1])) { // Fixed: Changed matches1 to matches[1]
                $billcount = (int) $matches[1] + 1;
            }
        }
        $billcountFormatted = str_pad($billcount, 3, '0', STR_PAD_LEFT);
        $bill_no = $resultBillPrefix[0]['bill_prefix'] . '/' . $billcountFormatted . '/' . $fiscal_year;
        // Update the invoice with generated ID and new bill number
        $sqlUpdate = "UPDATE invoice SET invoice_id = ?, bill_no = ? WHERE id = ? AND company_id = ?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("ssis", $uniqueID, $bill_no, $id, $compID);
        if (!$stmtUpdate->execute()) {
            echo json_encode(['status' => 400, 'msg' => 'Invoice Update Failed']);
            exit();
        }
        echo json_encode([
            'status' => 200,
            'msg' => 'Invoice Created Successfully',
            'data' => ['invoice_id' => $uniqueID],
        ]);
        exit(); // Stop execution after sending the final response
    } catch (Exception $error) {
        echo json_encode([
            'status' => 500,
            'msg' => 'An error occurred: ' . $error->getMessage(),
        ]);
        exit();
    }
}
// Update Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $invoice_id = $obj['invoice_id'] ?? null;
    $party_id = $obj['party_id'] ?? null;
    $party_name = $obj['party_name'] ?? null;
    $company_details = $obj['company_details'] ?? null;
    $bill_date = $obj['bill_date'] ?? null;
    $product = $obj['product'] ?? null;
    $eway_no = $obj['eway_no'] ?? null;
    $vechile_no = $obj['vechile_no'] ?? null;
    $address = $obj['address'] ?? null;
    $mobile_number = $obj['mobile_number'] ?? null;
    $total = $obj['total'] ?? null;
    $sum_total = $obj['sum_total'] ?? null;
    $paid = $obj['paid'] ?? null;
    $balance = $obj['balance'] ?? null;
    $payment_method = $obj['payment_method'];
    $state_of_supply = $obj['state_of_supply'] ?? null;
    $discount = $obj['discount'] ?? null;
    $discount_amount = $obj['discount_amount'] ?? null;
    $discount_type = $obj['discount_type'] ?? null;
    $gst_type = $obj['gst_type'] ?? null;
    $gst_amount = isset($obj['gst_amount']) && $obj['gst_amount'] !== '' ? $obj['gst_amount'] : 0;
    $remark = isset($obj['remark']) && $obj['remark'] !== '' ? $obj['remark'] : '';
    $round_off = isset($obj['round_off']) ? intval($obj['round_off']) : 0;
    $round_off_amount = isset($obj['round_off_amount']) ? floatval($obj['round_off_amount']) : 0;
    $overall_total =  $obj['overall_total'] ?? null;
    $payment_method_json = json_encode($payment_method, true);
    // Validate required fields
    if (!$invoice_id || !$party_id) {
        $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit; // Ensure script stops after sending response
    }
    // Fetch party details
    $partySql = "SELECT * FROM sales_party WHERE party_id = ? AND company_id = ? AND delete_at = 0";
    $partyData = fetchQuery($conn, $partySql, [$party_id, $compID]);
    if (empty($partyData)) {
        $output = ['status' => 400, 'msg' => 'Party Not Found'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit;
    }
    $party_details_json = json_encode($partyData[0]);
    // Fetch the old invoice details
    $sqlInvoice = "SELECT * FROM invoice WHERE invoice_id = ? AND company_id = ?";
    $resultInvoice = fetchQuery($conn, $sqlInvoice, [$invoice_id, $compID]);
    if (empty($resultInvoice)) {
        $output = ['status' => 400, 'msg' => 'Invoice Not Found'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit;
    }
    // In the PUT section
    $sum_total = 0;
    foreach ($product as &$element) {
        $element['without_tax_amount'] = (floatval($element['qty']) * floatval($element['price_unit'])) - (empty($element['discount_amt']) ? 0 : floatval($element['discount_amt']));
        $sum_total += $element['without_tax_amount'];
    }
    $product_json = json_encode($product);
    // Update the invoice details
    $billDate = date('Y-m-d', strtotime($bill_date));
    $sqlUpdateInvoice = "UPDATE invoice SET party_id = ?,party_name = ?, party_details = ?, company_details = ?,
                         bill_date = ?, product = ?, eway_no = ?, vechile_no = ?,
                         address = ?, mobile_number = ?, total = ?, overall_total = ?, sum_total = ?, round_off = ?, round_off_amount = ?,
                         paid = ?, balance = ?, payment_method = ?,state_of_supply = ?, discount = ?,discount_amount = ?, discount_type = ?,gst_type = ?,gst_amount = ?,sub_total = ?,remark = ?
                         WHERE invoice_id = ? AND company_id = ?";
    $paramsUpdate = [
        $party_id,
        $party_name,
        $party_details_json,
        json_encode($company_details),
        $billDate,
        $product_json,
        $eway_no,
        $vechile_no,
        $address,
        $mobile_number,
        $total,
        $overall_total,
        $sum_total,
        $round_off,
        $round_off_amount,
        $paid,
        $balance,
        $payment_method_json,
        $state_of_supply,
        $discount,
        $discount_amount,
        $discount_type,
        $gst_type,
        $gst_amount,
        $obj['sub_total'],
        $remark,
        $invoice_id,
        $compID
    ];
    $stmtUpdateInvoice = $conn->prepare($sqlUpdateInvoice);
    if (!$stmtUpdateInvoice) {
        $output = ['status' => 400, 'msg' => 'Prepare failed: ' . $conn->error];
        echo json_encode($output);
        exit;
    }
    $stmtUpdateInvoice->bind_param(str_repeat("s", count($paramsUpdate)), ...$paramsUpdate);
    if ($stmtUpdateInvoice->execute()) {
        if ($stmtUpdateInvoice->affected_rows > 0) {
            $output = [
                'status' => 200,
                'msg' => 'Invoice Updated Successfully',
                'data' => ['invoice_id' => $invoice_id]
            ];
        } else {
            $output = ['status' => 400, 'msg' => 'No changes made or invoice not found'];
        }
    } else {
        $output = ['status' => 400, 'msg' => 'Update failed: ' . $stmtUpdateInvoice->error];
    }
    $stmtUpdateInvoice->close();
    echo json_encode($output);
    exit;
}
// Delete Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $invoice_id = $obj['invoice_id'];
    if (!$invoice_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sqlDelete = "UPDATE invoice SET delete_at = '1' WHERE invoice_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlDelete);
        if (!$stmt) {
            $output['status'] = 400;
            $output['msg'] = 'Prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("ss", $invoice_id, $compID);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $output['status'] = 200;
                    $output['msg'] = 'Invoice Deleted Successfully';
                } else {
                    $output['status'] = 400;
                    $output['msg'] = 'No invoice found with the provided ID';
                }
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error deleting invoice: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid Request Method';
}
echo json_encode($output);
