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

error_log(print_r($_SERVER['REQUEST_METHOD'], true));
error_log(print_r($obj, true));

class BillNoCreation
{
    public static function create($params)
    {
        $prefix_name = $params['prefix_name'];
        $crtFinancialYear = self::getFinancialYear();
        $oldBillNumber = $params['billno'];

        if ($oldBillNumber == '0') {
            $oldBillNumber = "{$prefix_name}/0/{$crtFinancialYear}";
        }

        $explodedBillNumber = explode("/", $oldBillNumber);
        if (count($explodedBillNumber) < 2) {
            return 'Invalid bill number format';
        }

        $lastBillNumber = $explodedBillNumber[1];
        $currentBillNumber = intval($lastBillNumber) + 1;

        $currentBillNumber = self::billNumberFormat($currentBillNumber);

        $result = "{$prefix_name}/{$currentBillNumber}/{$crtFinancialYear}";
        return $result;
    }

    private static function getFinancialYear()
    {
        $currentYear = date('Y');
        $currentMonth = date('m');

        if ($currentMonth >= 4) {
            return substr($currentYear, 2) . '-' . substr($currentYear + 1, 2);
        } else {
            return substr($currentYear - 1, 2) . '-' . substr($currentYear, 2);
        }
    }

    private static function billNumberFormat($number)
    {
        return str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}

// MySQL query function
function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Payout Functions (like Node's PayoutController)

// List Payouts
function listPayouts($conn, $compID, $obj)
{
    $search_text = $obj['search_text'] ?? '';
    $party_id = $obj['party_id'] ?? '';
    $from_date = $obj['from_Date'] ?? '';
    $to_date = $obj['to_date'] ?? '';

    $where = "delete_at = '0' AND company_id = ?";
    $params = [$compID];

    // PARTY ID
    if (!empty($party_id)) {
        $where .= " AND party_id = ?";
        $params[] = $party_id;
    }

    // SEARCH TEXT (party name / voucher no)
    if (!empty($search_text)) {
        $where .= " AND (JSON_EXTRACT(party_details, '$.party_name') LIKE ? OR voucher_no LIKE ?)";
        $params[] = "%$search_text%";
        $params[] = "%$search_text%";
    }

    // DATE RANGE
    if (!empty($from_date) && !empty($to_date)) {
        $where .= " AND voucher_date BETWEEN ? AND ?";
        $params[] = $from_date;
        $params[] = $to_date;
    }

    $sql = "SELECT payout_id, party_id, party_details, company_details, voucher_no, 
            DATE_FORMAT(voucher_date,'%Y-%m-%d') AS voucher_date, paid, payment_method_id, payment_method_name,details
            FROM payout 
            WHERE $where";

    $result = fetchQuery($conn, $sql, $params);

    if ($result) {
        foreach ($result as &$element) {
            $element['party_details'] = json_decode($element['party_details'], true);
            $element['company_details'] = json_decode($element['company_details'], true);
        }
        return ['status' => 200, 'msg' => 'Success', 'data' => $result];
    }
    return ['status' => 400, 'msg' => 'Error fetching payout data'];
}

function createPayout($conn, $compID, $obj)
{
    $party_id = $obj['party_id'] ?? null;
    $voucher_date = $obj['voucher_date'] ?? null;
    $paid = $obj['paid'] ?? null;
    $payment_method_id = $obj['payment_method_id'] ?? null;
     $details = $obj['details'] ?? null;

    if ($voucher_date) {
        $dateParts = explode('-', $voucher_date);
        if (count($dateParts) === 3) {
            $voucher_date = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
        } else {
            return ['status' => 400, 'msg' => 'Invalid Date Format'];
        }
    }

    if (!$party_id || !$voucher_date || !$paid || !$payment_method_id) {
        return ['status' => 400, 'msg' => 'Parameter MisMatch'];
    }

    // Fetch payment method name
    $sql_method = "SELECT `payment_method_name` FROM `payment_methods` WHERE `company_id` = ? AND `payment_method_id` = ? AND `delete_at` = '0'";
    $methodResult = fetchQuery($conn, $sql_method, [$compID, $payment_method_id]);

    if (empty($methodResult)) {
        return ['status' => 404, 'msg' => 'Payment method not found'];
    }

    $payment_method_name = $methodResult[0]['payment_method_name'];

    $sqlParty = "SELECT * FROM `purchase_party` WHERE `party_id`=? AND `company_id`=?";
    $result = fetchQuery($conn, $sqlParty, [$party_id, $compID]);

    if ($result) {
        $party_details = json_encode($result[0]);
        $companyDetailsSql = "SELECT * FROM `company` WHERE `company_id`=?";
        $companyDetailsResult = fetchQuery($conn, $companyDetailsSql, [$compID]);

        if ($companyDetailsResult) {
            $companyData = json_encode($companyDetailsResult[0]);

            $sqlInsert = "INSERT INTO payout (company_id, party_id, party_details, voucher_date, paid, company_details, payment_method_id, payment_method_name, details,delete_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,'0')";
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param('sssssssss', $compID, $party_id, $party_details, $voucher_date, $paid, $companyData, $payment_method_id, $payment_method_name,$details);

            if ($stmt->execute()) {
                $insertId = $conn->insert_id;
                $uniqueID = uniqueID("payout", $insertId);

                $lastVoucherSql = "SELECT voucher_no FROM `payout` WHERE company_id=? AND id != ? ORDER BY id DESC LIMIT 1";
                $resultLastVoucher = fetchQuery($conn, $lastVoucherSql, [$compID, $insertId]);

                $voucherPrefixSql = "SELECT bill_prefix FROM `company` WHERE company_id=?";
                $resultVoucherPrefix = fetchQuery($conn, $voucherPrefixSql, [$compID]);

                $companyPrefix = isset($resultVoucherPrefix[0]['bill_prefix']) ? $resultVoucherPrefix[0]['bill_prefix'] : 'INV';
                $voucherNumber = isset($resultLastVoucher[0]['voucher_no']) ? $resultLastVoucher[0]['voucher_no'] : null;

                if (!$voucherNumber) {
                    $voucherNumber = '0';
                }

                $params = [
                    'prefix_name' => $companyPrefix,
                    'billno' => $voucherNumber,
                ];
                $voucherNo = BillNoCreation::create($params);

                $sqlUpdate = "UPDATE payout SET payout_id=?, voucher_no=? WHERE id=? AND company_id=?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param('ssss', $uniqueID, $voucherNo, $insertId, $compID);

                if ($stmtUpdate->execute()) {
                    return ['status' => 200, 'msg' => 'Payout Created Successfully', 'data' => ['payout_id' => $uniqueID, 'voucher_no' => $voucherNo]];
                }
                return ['status' => 400, 'msg' => 'Error updating voucher number'];
            }
        }
    }
    return ['status' => 400, 'msg' => 'Error creating payout'];
}

function updatePayout($conn, $compID, $obj)
{
    $payout_id = $obj['payout_id'] ?? null;
    $party_id = $obj['party_id'] ?? null;
    $voucher_date = $obj['voucher_date'] ?? null;
    $paid = $obj['paid'] ?? null;
    $payment_method_id = $obj['payment_method_id'] ?? null;
     $details = $obj['details'] ?? null;


    if (!$payout_id || !$party_id || !$voucher_date || !$paid || !$payment_method_id) {
        return ['status' => 400, 'msg' => 'Parameter MisMatch'];
    }

    if ($voucher_date) {
        $dateParts = explode('-', $voucher_date);
        if (count($dateParts) === 3) {
            $voucher_date = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
        } else {
            return ['status' => 400, 'msg' => 'Invalid Date Format'];
        }
    }

    // Fetch payment method name
    $sql_method = "SELECT `payment_method_name` FROM `payment_methods` WHERE `company_id` = ? AND `payment_method_id` = ? AND `delete_at` = '0'";
    $methodResult = fetchQuery($conn, $sql_method, [$compID, $payment_method_id]);

    if (empty($methodResult)) {
        return ['status' => 404, 'msg' => 'Payment method not found'];
    }

    $payment_method_name = $methodResult[0]['payment_method_name'];

    $sqlParty = "SELECT * FROM `purchase_party` WHERE `party_id`=? AND `company_id`=?";
    $partyResult = fetchQuery($conn, $sqlParty, [$party_id, $compID]);

    if ($partyResult) {
        $party_details = json_encode($partyResult[0]);
    } else {
        return ['status' => 400, 'msg' => 'Party not found'];
    }

    $sql = "UPDATE payout SET party_id=?, party_details=?, voucher_date=?, paid=?, payment_method_id=?, payment_method_name=?, details=? WHERE payout_id=? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssssss', $party_id, $party_details, $voucher_date, $paid, $payment_method_id, $payment_method_name, $details, $payout_id, $compID);

    if ($stmt->execute()) {
        return ['status' => 200, 'msg' => 'Payout Details Updated Successfully'];
    }

    return ['status' => 400, 'msg' => 'Error updating payout'];
}

function deletePayout($conn, $compID, $obj)
{
    $payout_id = $obj['payout_id'] ?? null;

    if (!$payout_id) {
        return ['status' => 400, 'msg' => 'Parameter MisMatch'];
    }

    $sql = "UPDATE payout SET delete_at='1' WHERE payout_id=? AND company_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $payout_id, $compID);

    if ($stmt->execute()) {
        return ['status' => 200, 'msg' => 'Payout Deleted Successfully'];
    }
    return ['status' => 400, 'msg' => 'Error deleting payout'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($obj['search_text'])) {
        $output = listPayouts($conn, $compID, $obj);
    } elseif (isset($obj['party_id']) && isset($obj['voucher_date']) && !isset($obj['payout_id'])) {
        $output = createPayout($conn, $compID, $obj);
    } else {
        $output = ['status' => 400, 'msg' => 'Invalid POST request parameters'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    if (isset($obj['payout_id']) && isset($obj['party_id']) && isset($obj['voucher_date']) && isset($obj['paid'])) {
        $output = updatePayout($conn, $compID, $obj);
    } else {
        $output = ['status' => 400, 'msg' => 'Invalid PUT request parameters'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $output = deletePayout($conn, $compID, $obj);
} else {
    $output = ['status' => 400, 'msg' => 'Invalid request method', 'data' => $obj];
}

echo json_encode($output, JSON_NUMERIC_CHECK);
