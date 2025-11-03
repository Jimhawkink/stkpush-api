<?php
header("Content-Type: application/json");

// ✅ Include database configuration (recommended)
require_once __DIR__ . "/config.php";  // Make sure config.php exists in same folder

// OR, if you prefer to keep it self-contained without config.php, use this:
///*
// $conn = new mysqli("db", "fatherss_mp", "J1iMh078@", "fatherss_mp", 3306);
// if ($conn->connect_errno) {
//     echo json_encode(["success" => false, "message" => $conn->connect_error]);
//     exit();
// }
// $conn->set_charset("utf8mb4");
//*/

// Get JSON body from POST request
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
    exit();
}

// Prepare and execute insert query
$stmt = $conn->prepare("
    INSERT INTO mpesa_transactions 
    (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, MpesaReceiptNumber, PhoneNumber, TransactionDate, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

if (!$stmt) {
    echo json_encode(["success" => false, "message" => $conn->error]);
    exit();
}

$stmt->bind_param(
    "ssisdsss",
    $data["MerchantRequestID"],
    $data["CheckoutRequestID"],
    $data["ResultCode"],
    $data["ResultDesc"],
    $data["Amount"],
    $data["MpesaReceiptNumber"],
    $data["PhoneNumber"],
    $data["TransactionDate"]
);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Transaction saved"]);
} else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
}

$stmt->close();
$conn->close();
?>
