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
        error_log("Prepare failed: " . $conn->error);
        return false; // Return false if prepare fails
    }

    // Bind parameters if any
    if ($params) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return false; // Return false if execute fails
    }

    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : []; // Return results or an empty array
}


// List Purchase Bills
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    try {
        $search_text = $obj['search_text'] ?? null;
        $party_id = $obj['party_id'] ?? null;
        $from_date = $obj['from_date'] ?? null;
        $to_date = $obj['to_date'] ?? null;

        $conditions = ["delete_at = '0'", "company_id = ?"];
        $parameters = [$compID];

        if ($search_text) {
            $conditions[] = "(JSON_EXTRACT(party_details, '$.party_name') LIKE ? OR bill_no LIKE ?)";
            $parameters[] = "%$search_text%";
            $parameters[] = "%$search_text%";
        }

        if ($party_id) {
            $conditions[] = "party_id = ?";
            $parameters[] = $party_id;
        }

        if ($from_date && $to_date) {
            $conditions[] = "bill_date BETWEEN ? AND ?";
            $parameters[] = $from_date;
            $parameters[] = $to_date;
        }

        $sql = "SELECT party_id, purchase_id, party_details, bill_no, 
                    DATE_FORMAT(bill_date, '%Y-%m-%d') as bill_date, 
                    DATE_FORMAT(stock_date, '%Y-%m-%d') as stock_date,
                    sum_total, product, purchase_gst, purchasemobile_no,
                    total, paid, balance_amount, company_details
                FROM purchase WHERE " . implode(' AND ', $conditions);

        $result = fetchQuery($conn, $sql, $parameters);

        if ($result) {
            foreach ($result as &$row) {
                $row['bill_date'] = date('d-m-Y', strtotime($row['bill_date']));
                $row['stock_date'] = date('d-m-Y', strtotime($row['stock_date']));
                $row['party_details'] = json_decode($row['party_details'], true);
                $row['product'] = json_decode($row['product'], true);
                $row['company_details'] = json_decode($row['company_details'], true);
            }

            $output = [
                'status' => 200,
                'msg' => 'Success',
                'data' => $result
            ];
        } else {
            $output = ['status' => 400, 'msg' => 'No records found'];
        }
    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error'];
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'PUT' && isset($obj['purchase_id'])) {
    try {
        $purchase_id = $obj['purchase_id'];
        $party_id = $obj['party_id'];
        $bill_date = $obj['bill_date'];
        $stock_date = $obj['stock_date'];
        $sum_total = 0;
        $product = $obj['product'];
        $purchase_gst = $obj['purchase_gst'];
        $purchasemobile_no = $obj['purchasemobile_no'];
        $total = $obj['total'];
        $paid = $obj['paid'];
        $balance_amount = isset($obj['balance_amount']) ? $obj['balance_amount'] : 0;


        if (!$purchase_id || !$party_id || !$bill_date || !$stock_date || !$product || !$total || !$paid || !$balance_amount) {
            $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];

            exit();
        }

        // Fetch party details
        $sqlparty = "SELECT * FROM purchase_party WHERE party_id = ? AND company_id = ?";
        $party_details = fetchQuery($conn, $sqlparty, [$party_id, $compID]);

        if ($party_details) {
            foreach ($product as &$item) {
                $item['without_tax_amount'] = ($item['qty'] * $item['price_unit']) - ($item['discount_amt'] ?? 0);
                $sum_total += $item['without_tax_amount'];

                // Fetch product details
                $sqlProduct = "SELECT * FROM product WHERE product_id = ? AND company_id = ?";
                $productData = fetchQuery($conn, $sqlProduct, [$item['product_id'], $compID]);

                if ($productData) {
                    $item['hsn_no'] = $productData[0]['hsn_no'];
                    $item['item_code'] = $productData[0]['item_code'];
                    $item['product_name'] = $productData[0]['product_name'];
                } else {
                    $output = ['status' => 400, 'msg' => 'Product Details Not Found'];

                    exit();
                }
            }

            // Update purchase bill
            $party_details_json = json_encode($party_details[0]);
            $product_json = json_encode($product);

            $sql = "UPDATE purchase SET party_id = ?, party_details = ?, bill_date = ?, stock_date = ?, total = ?, paid = ?, balance_amount = ?, sum_total = ?, product = ?, purchase_gst = ?, purchasemobile_no = ? 
                    WHERE purchase_id = ? AND company_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssss', $party_id, $party_details_json, $bill_date, $stock_date, $total, $paid, $balance_amount, $sum_total, $product_json, $purchase_gst, $purchasemobile_no, $purchase_id, $compID);

            if ($stmt->execute()) {
                $output = ['status' => 200, 'msg' => 'Purchase Bill Updated Successfully'];
            } else {
                $output = ['status' => 400, 'msg' => 'Failed to Update Purchase Bill'];
            }
        } else {
            $output = ['status' => 400, 'msg' => 'Party Details Not Found'];
        }
    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_id'])) {
    try {
        $party_id = $obj['party_id'];
        $bill_no = $obj['bill_no'];
        $bill_date = $obj['bill_date'];
        $stock_date = $obj['stock_date'];
        $sum_total = 0;
        $product = $obj['product'];
        $purchase_gst = $obj['purchase_gst'];
        $purchasemobile_no = $obj['purchasemobile_no'];
        $total = $obj['total'];
        $paid = $obj['paid'];
        $balance_amount = isset($obj['balance_amount']) ? $obj['balance_amount'] : 0;
        $delete_at = 0;

        // Validate required parameters
        if (!$party_id || !$bill_no || !$bill_date || !$product || !$total) {
            $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
            echo json_encode($output);
            exit;
        }

        // Check party details
        $sqlparty = "SELECT * FROM purchase_party WHERE party_id = ? AND company_id = ?";
        $party_details = fetchQuery($conn, $sqlparty, [$party_id, $compID]);

        if ($party_details) {
            foreach ($product as &$item) {
                $item['without_tax_amount'] = ($item['qty'] * $item['price_unit']) - ($item['discount_amt'] ?? 0);
                $sum_total += $item['without_tax_amount'];

                $sqlProduct = "SELECT * FROM product WHERE product_id = ? AND company_id = ?";
                $productData = fetchQuery($conn, $sqlProduct, [$item['product_id'], $compID]);

                if ($productData) {
                    $item['hsn_no'] = $productData[0]['hsn_no'];
                    $item['item_code'] = $productData[0]['item_code'];
                    $item['product_name'] = $productData[0]['product_name'];

                    // Update stock based on product details
                    $quantity = (int)$item['qty'];
                    $unit_id = $item['unit'];
                    $db_unit_id = $productData[0]['unit_id'];

                    // Adjust stock based on unit types
                    $new_stock = (int)$productData[0]['crt_stock'] + $quantity; // Default adjustment

                    // Only check subunit_id if it exists
                    if (isset($item['subunit_id']) && $item['subunit_id'] !== null && $item['subunit_id'] !== "") {
                        // Logic for adjusting stock based on unit
                        if ($unit_id !== $db_unit_id) {
                            // Implement unit conversion logic if needed
                            $new_stock = (int)$productData[0]['crt_stock'] + $quantity; // Adjust as necessary
                        }
                    }

                    // Update the product stock in the database
                    $updateStockSql = "UPDATE product SET crt_stock = ? WHERE product_id = ? AND company_id = ?";
                    fetchQuery($conn, $updateStockSql, [$new_stock, $item['product_id'], $compID]);

                    // Log stock history
                    $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id, bill_date, delete_at) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, '0')";
                    fetchQuery($conn, $stockSql, ['STACKIN', $bill_no, $item['product_id'], $item['product_name'], $quantity, $compID, 'UNIQUE_BILL_ID', $bill_date]);
                } else {
                    $output = ['status' => 400, 'msg' => 'Product Details Not Found'];
                }
            }

            // Insert purchase bill
            $party_details_json = json_encode($party_details[0]);
            $product_json = json_encode($product);
            $company_details_json = json_encode(fetchQuery($conn, "SELECT * FROM company WHERE company_id = ?", [$compID])[0]);

            $sql = "INSERT INTO purchase (company_id, party_id, party_details, bill_date, stock_date, bill_no, total, paid, balance_amount, sum_total, product, purchase_gst, purchasemobile_no, company_details, delete_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssssssssssssss', $compID, $party_id, $party_details_json, $bill_date, $stock_date, $bill_no, $total, $paid, $balance_amount, $sum_total, $product_json, $purchase_gst, $purchasemobile_no, $company_details_json, $delete_at);

            if ($stmt->execute()) {
                $insertId = $conn->insert_id;
                $uniqueID = uniqueID("purchase", $insertId);
                $sqlUpdate = "UPDATE purchase SET purchase_id = ? WHERE id = ? AND company_id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param('sss', $uniqueID, $insertId, $compID);

                if ($stmtUpdate->execute()) {
                    $output = ['status' => 200, 'msg' => 'Purchase Bill Created Successfully', 'data' => ['invoice_id' => $uniqueID]];
                } else {
                    $output = ['status' => 400, 'msg' => 'Error updating purchase ID'];
                }
            } else {
                $output = ['status' => 400, 'msg' => 'Error creating purchase bill'];
            }
        } else {
            $output = ['status' => 400, 'msg' => 'Party Details Not Found'];
        }
    } catch (Exception $e) {
        $output = ['status' => 500, 'msg' => 'Internal Server Error: ' . $e->getMessage()];
    }
}


// Delete Purchase Bill (Soft delete)
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $purchase_id = $obj['purchase_id'];
    if (!$purchase_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sql = "UPDATE purchase SET delete_at = '1' WHERE purchase_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $purchase_id, $compID);
        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'purchase Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting purchase';
        }
    }
} else {
    $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
}
echo json_encode($output);
