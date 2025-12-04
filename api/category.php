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

// List Categories
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];

    $sql = "SELECT category_id, category_name FROM category WHERE delete_at = '0' AND company_id = ? AND category_name LIKE ?";
    $categories = fetchQuery($conn, $sql, [$compID, "%$search_text%"]);

    if (count($categories) > 0) {
        foreach ($categories as &$category) {
            $category_id = $category['category_id'];
            $sqlProduct = "SELECT product_name, crt_stock AS current_stock FROM product WHERE category_id = ? AND delete_at = '0' AND company_id = ?";
            $products = fetchQuery($conn, $sqlProduct, [$category_id, $compID]);

            $category['product_count'] = count($products);
            $category['transactions'] = $products;
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $categories;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}

// Create Category
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['category_name'])) {
    $category_name = $obj['category_name'];

    $sql = "SELECT * FROM category WHERE category_name = ? AND company_id = ? AND delete_at = 0";
    $existingCategory = fetchQuery($conn, $sql, [$category_name, $compID]);

    if (count($existingCategory) > 0) {
        $output['status'] = 400;
        $output['msg'] = 'Category already exists';
    } else {
        $sqlInsert = "INSERT INTO category (company_id, category_name, delete_at) VALUES (?, ?, '0')";
        $stmt = $conn->prepare($sqlInsert);
        $stmt->bind_param('ss', $compID, $category_name);

        if ($stmt->execute()) {
            $insertId = $conn->insert_id;
            $uniqueID = uniqueID("category", $insertId);  // Replace uniqueIDGen logic with basic format
            $sqlUpdate = "UPDATE category SET category_id = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sss', $uniqueID, $insertId, $compID);

            if ($stmtUpdate->execute()) {
                $output['status'] = 200;
                $output['msg'] = 'Category Created Successfully';
                $output['data'] = ['category_id' => $uniqueID];
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error updating category';
            }
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error creating category';
        }
    }
}

// Update Category
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $category_id = $obj['category_id'];
    $category_name = $obj['category_name'];

    if (!empty($category_name)) {
        $sql = "UPDATE category SET category_name = ? WHERE category_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sss', $category_name, $category_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Category Updated Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating category';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
}

// Delete Category
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $category_id = $obj['category_id'];

    if (!empty($category_id)) {
        $sql = "UPDATE category SET delete_at = '1' WHERE category_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $category_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Category Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting category';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid request';
}

echo json_encode($output, JSON_NUMERIC_CHECK);
