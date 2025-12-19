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
// List Products
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $from_date = $obj['from_date'] ?? null;
    $to_date = $obj['to_date'] ?? null;
    $conditions = ["delete_at = '0'", "company_id = ?"];
    $parameters = [$compID];
    if ($search_text) {
        $conditions[] = "(product_name LIKE ? OR item_code LIKE ? OR hsn_no LIKE ?)";
        $parameters[] = "%$search_text%";
        $parameters[] = "%$search_text%";
        $parameters[] = "%$search_text%";
    }
    if ($from_date && $to_date) {
        $conditions[] = "opening_date BETWEEN ? AND ?";
        $parameters[] = $from_date;
        $parameters[] = $to_date;
    }

$sql = "SELECT id, company_id, product_id, product_name, hsn_no, item_code, item_gst, unit_id, subunit_id, unit_rate, opening_stock, opening_date, min_stock, crt_stock, created_date
            FROM product
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY id DESC";
    $products = fetchQuery($conn, $sql, $parameters);
    if (count($products) > 0) {
        foreach ($products as &$product) {
            $product['opening_date'] = date('Y-m-d', strtotime($product['opening_date']));
            $product['created_date'] = date('Y-m-d', strtotime($product['created_date']));
        }
        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $products;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}
// Create Product
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['product_name'])) {
    $product_name = $obj['product_name'];
    $hsn_no = $obj['hsn_no'];
    $item_code = $obj['item_code'];
    $item_gst = $obj['item_gst'];
    $unit_id = $obj['unit_id'];
    $subunit_id = $obj['subunit_id'] ?? null;
    $unit_rate = $obj['unit_rate'];
    $opening_stock = $obj['opening_stock'] ?? 0;
    $opening_date = $obj['opening_date'] ?? date('Y-m-d');
    $min_stock = $obj['min_stock'] ?? 0;
    $crt_stock = $obj['crt_stock'] ?? $opening_stock;
    $delete_at = 0;
    $created_date = date('Y-m-d H:i:s');
    try {
        // Parameter validation
        if (!$product_name || !$unit_id || !$unit_rate) {
            echo json_encode([
                'status' => 400,
                'msg' => "Parameter Mismatch",
            ]);
            exit();
        }
        // Insert product into database
        $sql = "INSERT INTO product (company_id,  product_name, hsn_no, item_code, item_gst, unit_id, subunit_id, unit_rate, opening_stock, opening_date, min_stock, crt_stock, delete_at, created_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssidsddis", $compID, $product_name, $hsn_no, $item_code, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock, $opening_date, $min_stock, $crt_stock, $delete_at, $created_date);
        if ($stmt->execute()) {
            $id = $conn->insert_id;
        } else {
            echo json_encode(['status' => 400, 'msg' => 'Product Creation Failed: ' . $conn->error]);
            exit();
        }
        // Generate unique product ID
        $uniqueID = uniqueID("product", $id);
        // Update the product with generated ID
        $sqlUpdate = "UPDATE product SET product_id = ? WHERE id = ? AND company_id = ?";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bind_param("sis", $uniqueID, $id, $compID);
        if (!$stmtUpdate->execute()) {
            echo json_encode(['status' => 400, 'msg' => 'Product Update Failed']);
            exit();
        }
        echo json_encode([
            'status' => 200,
            'msg' => 'Product Created Successfully',
            'data' => ['product_id' => $uniqueID],
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
// Update Product
else if ($_SERVER['REQUEST_METHOD'] == 'PUT' && isset($obj['product_id'])) {
    $product_id = $obj['product_id'];
    $category_id = $obj['category_id'] ?? null;
    $product_name = $obj['product_name'] ?? null;
    $hsn_no = $obj['hsn_no'] ?? null;
    $item_code = $obj['item_code'] ?? null;
    $item_gst = $obj['item_gst'] ?? null;
    $unit_id = $obj['unit_id'] ?? null;
    $subunit_id = $obj['subunit_id'] ?? null;
    $unit_rate = $obj['unit_rate'] ?? null;
    $opening_stock = $obj['opening_stock'] ?? null;
    $opening_date = $obj['opening_date'] ?? null;
    $min_stock = $obj['min_stock'] ?? null;
    $crt_stock = $obj['crt_stock'] ?? null;
    // Validate required fields
    if (!$product_id) {
        $output = ['status' => 400, 'msg' => 'Parameter Mismatch'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit; // Ensure script stops after sending response
    }
    // Fetch the old product details
    $sqlProduct = "SELECT * FROM product WHERE product_id = ? AND company_id = ?";
    $resultProduct = fetchQuery($conn, $sqlProduct, [$product_id, $compID]);
    if (empty($resultProduct)) {
        $output = ['status' => 400, 'msg' => 'Product Not Found'];
        header('Content-Type: application/json');
        echo json_encode($output);
        exit;
    }
    // Fixed update query with all fields, allowing NULL
    $sqlUpdate = "UPDATE product SET category_id = ?, product_name = ?, hsn_no = ?, item_code = ?, item_gst = ?, unit_id = ?, subunit_id = ?, unit_rate = ?, opening_stock = ?, opening_date = ?, min_stock = ?, crt_stock = ? WHERE product_id = ? AND company_id = ?";
    $params = [$category_id, $product_name, $hsn_no, $item_code, $item_gst, $unit_id, $subunit_id, $unit_rate, $opening_stock, $opening_date, $min_stock, $crt_stock, $product_id, $compID];
    $resultUpdate = fetchQuery($conn, $sqlUpdate, $params);
    $output = [
        'status' => 200,
        'msg' => 'Product Updated Successfully',
        'data' => ['product_id' => $product_id]
    ];
    echo json_encode($output);
    exit;
}
// Delete Product
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE' && isset($obj['product_id'])) {
    $product_id = $obj['product_id'];
    if (!$product_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sqlDelete = "UPDATE product SET delete_at = '1' WHERE product_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlDelete);
        if (!$stmt) {
            $output['status'] = 400;
            $output['msg'] = 'Prepare failed: ' . $conn->error;
        } else {
            $stmt->bind_param("ss", $product_id, $compID);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $output['status'] = 200;
                    $output['msg'] = 'Product Deleted Successfully';
                } else {
                    $output['status'] = 400;
                    $output['msg'] = 'No product found with the provided ID';
                }
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error deleting product: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid Request Method';
}
echo json_encode($output);