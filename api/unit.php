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

// MySQL query function
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

function getUnitName($unit_id, $units)
{
    foreach ($units as $unit) {
        if ($unit['unit_id'] == $unit_id) {
            return $unit['unit_name'];
        }
    }
    return null;
}

// List Units
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];

    $sql = "SELECT unit_id, unit_name, short_name FROM unit WHERE delete_at = '0' AND company_id = ? AND (unit_name LIKE ? OR short_name LIKE ?)";
    $units = fetchQuery($conn, $sql, [$compID, "%$search_text%", "%$search_text%"]);

    if (count($units) > 0) {
        foreach ($units as &$unit) {
            $unit_id = $unit['unit_id'];
            $sqlProduct = "SELECT unit_converson_id, unit_id, rate, subunit_id FROM unit_converson WHERE unit_id = ? AND delete_at = '0' AND company_id = ?";
            $transactions = fetchQuery($conn, $sqlProduct, [$unit_id, $compID]);

            if (count($transactions) > 0) {
                foreach ($transactions as &$transaction) {
                    $transaction['unit_name'] = getUnitName($transaction['unit_id'], $units);
                    $transaction['subunit_name'] = getUnitName($transaction['subunit_id'], $units);
                }
            }

            $unit['transactions'] = $transactions;
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $units;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}

// Create Unit
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['unit_name'])) {
    $unit_name = $obj['unit_name'];
    $short_name = $obj['short_name'];

    if (!$unit_name) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "SELECT * FROM unit WHERE unit_name = ? AND company_id = ? AND delete_at = '0'";
        $existingUnit = fetchQuery($conn, $sql, [$unit_name, $compID]);

        if (count($existingUnit) > 0) {
            $output['status'] = 400;
            $output['msg'] = 'Unit Details Already Exist';
        } else {
            $sqlInsert = "INSERT INTO unit (company_id, unit_name, short_name, delete_at) VALUES (?, ?, ?, '0')";
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param('sss', $compID, $unit_name, $short_name);

            if ($stmt->execute()) {
                $insertId = $conn->insert_id;
                $uniqueID = uniqueID("unit", $insertId);

                $sqlUpdate = "UPDATE unit SET unit_id = ? WHERE id = ? AND company_id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param('sss', $uniqueID, $insertId, $compID);

                if ($stmtUpdate->execute()) {
                    $output['status'] = 200;
                    $output['msg'] = 'Unit Created Successfully';
                    $output['data'] = ['unit_id' => $uniqueID];
                } else {
                    $output['status'] = 400;
                    $output['msg'] = 'Error updating unit';
                }
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error creating unit';
            }
        }
    }
}

// Update Unit
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $unit_id = $obj['unit_id'];
    $unit_name = $obj['unit_name'];
    $short_name = $obj['short_name'];

    if (!$unit_id || !$unit_name) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE unit SET unit_name = ?, short_name = ? WHERE unit_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $unit_name, $short_name, $unit_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Unit Details Updated Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating unit';
        }
    }
}

// Delete Unit
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $unit_id = $obj['unit_id'];

    if (!$unit_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE unit SET delete_at = '1' WHERE unit_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $unit_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Unit Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting unit';
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid request';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
