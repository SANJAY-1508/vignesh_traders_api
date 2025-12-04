<?php
include 'config/dbconfig.php'; // Database connection
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

try {
    // Read JSON input
    $rawData = file_get_contents("php://input");
    $data = json_decode($rawData, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit;
    }

    // Extract and validate required fields
    $product_id = $data['product_id'] ?? null;
    $product_name = $data['product_name'] ?? '';
    $ordered_qty = $data['ordered_qty'] ?? 0;
    $remaining_stock = $data['remaining_stock'] ?? 0;
    $company_id = $data['company_id'] ?? '';
    $action = $data['action'] ?? 'UNKNOWN';
    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');

    if (!$product_id || !$company_id || !$ordered_qty) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
        exit;
    }

    // Insert into stock_history table
    $stmt = $conn->prepare("
        INSERT INTO stock_history 
            (product_id, product_name, qty, remaining_stock, company_id, action_type, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "isdisss",
        $product_id,
        $product_name,
        $ordered_qty,
        $remaining_stock,
        $company_id,
        $action,
        $timestamp
    );

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Stock history updated successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to insert stock history.']);
    }

    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
