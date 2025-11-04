<?php
// mpesa_callback.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =============================
// DATABASE CONFIGURATION
// =============================
$host = "maglev.proxy.rlwy.net";
$user = "root";
$password = "cJYEAVTFXdujqruHefgQxugPVfdASWRv";
$database = "railway";
$port = 13831;

// =============================
// VB.NET API ENDPOINT
// =============================
$vbnetApiUrl = "http://your-vbnet-server/api/mpesa/update"; // 🔴 replace with real URL

// =============================
// LOGGING HELPER
// =============================
function logMessage($msg)
{
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg\n";
    file_put_contents('mpesa_callback.log', $line, FILE_APPEND | LOCK_EX);
    echo $line;
    flush();
}

try {
    // =============================
    // CONNECT TO DATABASE (PDO)
    // =============================
    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    logMessage("✅ Database connected successfully");

    // =============================
    // READ INCOMING JSON
    // =============================
    $callbackJSON = file_get_contents('php://input');
    logMessage("📥 Received callback: $callbackJSON");

    if (empty($callbackJSON)) throw new Exception("No callback data received");

    $data = json_decode($callbackJSON, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }

    if (!isset($data['Body']['stkCallback'])) throw new Exception("Missing stkCallback object");

    $cb = $data['Body']['stkCallback'];

    // =============================
    // EXTRACT FIELDS
    // =============================
    $MerchantRequestID = $cb['MerchantRequestID'] ?? '';
    $CheckoutRequestID = $cb['CheckoutRequestID'] ?? '';
    $ResultCode = $cb['ResultCode'] ?? '';
    $ResultDesc = $cb['ResultDesc'] ?? '';

    $Amount = null;
    $MpesaReceiptNumber = '';
    $PhoneNumber = '';
    $TransactionDate = date('Y-m-d H:i:s');

    // Extract CallbackMetadata
    if (isset($cb['CallbackMetadata']['Item'])) {
        foreach ($cb['CallbackMetadata']['Item'] as $item) {
            switch ($item['Name']) {
                case 'Amount':
                    $Amount = $item['Value'];
                    break;
                case 'MpesaReceiptNumber':
                    $MpesaReceiptNumber = $item['Value'];
                    break;
                case 'PhoneNumber':
                    $PhoneNumber = $item['Value'];
                    break;
                case 'TransactionDate':
                    $TransactionDate = date('Y-m-d H:i:s', strtotime($item['Value']));
                    break;
            }
        }
    }

    logMessage("Parsed Data -> CheckoutRequestID: $CheckoutRequestID | Amount: $Amount | Receipt: $MpesaReceiptNumber | Phone: $PhoneNumber");

    // =============================
    // CHECK EXISTING TRANSACTION (using lowercase column names)
    // =============================
    $stmt = $conn->prepare("SELECT id, result_code, mpesa_receipt_number FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1");
    $stmt->execute([$CheckoutRequestID]);
    $row = $stmt->fetch();

    $message = "";

    if ($row) {
        // UPDATE EXISTING TRANSACTION
        if (($row['result_code'] != $ResultCode) || (empty($row['mpesa_receipt_number']) && !empty($MpesaReceiptNumber))) {
            $upd = $conn->prepare("
                UPDATE mpesa_transactions 
                SET result_code = ?, 
                    result_desc = ?, 
                    amount = COALESCE(?, amount), 
                    mpesa_receipt_number = COALESCE(NULLIF(?, ''), mpesa_receipt_number), 
                    phone_number = COALESCE(NULLIF(?, ''), phone_number), 
                    created_at = COALESCE(?, created_at)
                WHERE id = ?
            ");
            $upd->execute([$ResultCode, $ResultDesc, $Amount, $MpesaReceiptNumber, $PhoneNumber, $TransactionDate, $row['id']]);
            $message = "Transaction updated successfully";
            logMessage("🔁 Updated transaction id {$row['id']} - Receipt: $MpesaReceiptNumber");
        } else {
            $message = "Duplicate callback - no action needed";
            logMessage("⚠️ Duplicate callback - CheckoutRequestID: $CheckoutRequestID");
        }
    } else {
        // INSERT NEW TRANSACTION (using lowercase column names)
        $ins = $conn->prepare("
            INSERT INTO mpesa_transactions 
            (merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, phone_number, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$MerchantRequestID, $CheckoutRequestID, $ResultCode, $ResultDesc, $Amount, $MpesaReceiptNumber, $PhoneNumber, $TransactionDate]);
        $message = "Transaction saved successfully";
        logMessage("🆕 Inserted transaction - CheckoutRequestID: $CheckoutRequestID, Receipt: $MpesaReceiptNumber");
    }

    // =============================
    // FORWARD TO VB.NET API
    // =============================
    try {
        $payload = [
            "CheckoutRequestID" => $CheckoutRequestID,
            "MpesaReceiptNumber" => $MpesaReceiptNumber,
            "ResultCode" => $ResultCode,
            "ResultDesc" => $ResultDesc,
            "StatusMessage" => $ResultDesc,
            "PhoneNumber" => $PhoneNumber,
            "Amount" => $Amount,
            "TransactionDate" => $TransactionDate
        ];

        $ch = curl_init($vbnetApiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            logMessage("❌ CURL error: " . curl_error($ch));
        }
        curl_close($ch);

        logMessage("➡️ Forwarded to VB.NET API: " . json_encode($payload) . " | Response: $resp");
    } catch (Exception $ex) {
        logMessage("❌ ERROR forwarding to VB.NET API: " . $ex->getMessage());
    }

    // =============================
    // SUCCESS RESPONSE
    // =============================
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Transaction Completed successfully',
        'Status' => $message
    ]);
    logMessage("✅ Callback processed successfully -> $message");

} catch (Exception $e) {
    // =============================
    // ERROR HANDLER
    // =============================
    logMessage("❌ ERROR: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Transaction received but processing failed',
        'Error' => $e->getMessage()
    ]);
}
?>