<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Railway Database Configuration
$host = getenv('DB_HOST') ?: 'maglev.proxy.rlwy.net';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: 'cJYEAVTFXdujqruHefgQxugPVfdASWRv';
$database = getenv('DB_NAME') ?: 'railway';
$port = intval(getenv('DB_PORT') ?: 13831);

// Get CheckoutRequestID from query parameter
$checkoutRequestID = $_GET['checkout_request_id'] ?? '';

if (empty($checkoutRequestID)) {
    echo json_encode([
        "success" => false,
        "message" => "CheckoutRequestID is required",
        "resultCode" => null,
        "resultDesc" => null
    ]);
    exit();
}

try {
    // Connect to database
    $conn = new mysqli($host, $user, $password, $database, $port);
    
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
    // Query the transaction
    $stmt = $conn->prepare("
        SELECT 
            checkout_request_id as CheckoutRequestID,
            merchant_request_id as MerchantRequestID,
            result_code as ResultCode,
            result_desc as ResultDesc,
            amount as Amount,
            mpesa_receipt_number as MpesaReceiptNumber,
            phone_number as PhoneNumber,
            created_at as TransactionDate
        FROM mpesa_transactions 
        WHERE checkout_request_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    
    $stmt->bind_param("s", $checkoutRequestID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Transaction found
        echo json_encode([
            "success" => true,
            "message" => "Transaction found",
            "resultCode" => $row['ResultCode'],
            "resultDesc" => $row['ResultDesc'],
            "checkoutRequestID" => $row['CheckoutRequestID'],
            "merchantRequestID" => $row['MerchantRequestID'],
            "amount" => floatval($row['Amount'] ?? 0),
            "mpesaReceiptNumber" => $row['MpesaReceiptNumber'],
            "phoneNumber" => $row['PhoneNumber'],
            "transactionDate" => $row['TransactionDate']
        ]);
    } else {
        // Transaction not found yet
        echo json_encode([
            "success" => false,
            "message" => "Transaction not found",
            "resultCode" => null,
            "resultDesc" => "Pending - awaiting callback",
            "checkoutRequestID" => $checkoutRequestID
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage(),
        "resultCode" => null,
        "resultDesc" => null
    ]);
}
?>