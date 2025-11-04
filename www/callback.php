<?php
// mpesa_callback.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = "dbmysql-204162-0.cloudclusters.net";
$user = "admin";
$password = "5ZT8bJWM";
$database = "Mpesa_DB";
$port = 19902;

// Your VB.NET API endpoint
$vbnetApiUrl = "http://your-vbnet-server/api/mpesa/update"; // 🔴 replace with real URL

// Log helper
function logMessage($msg) {
    $ts = date('Y-m-d H:i:s');
    file_put_contents('mpesa_callback.log', "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

try {
    // ✅ Connect to DB using PDO instead of mysqli
    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    logMessage("Database connected successfully");

    // Get raw JSON
    $callbackJSON = file_get_contents('php://input');
    logMessage("Received callback: $callbackJSON");

    if (empty($callbackJSON)) throw new Exception("No callback data received");

    $data = json_decode($callbackJSON, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON: " . json_last_error_msg());

    if (!isset($data['Body']['stkCallback'])) throw new Exception("Missing stkCallback");

    $cb = $data['Body']['stkCallback'];

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
                case 'Amount': $Amount = $item['Value']; break;
                case 'MpesaReceiptNumber': $MpesaReceiptNumber = $item['Value']; break;
                case 'PhoneNumber': $PhoneNumber = $item['Value']; break;
                case 'TransactionDate':
                    $TransactionDate = date('Y-m-d H:i:s', strtotime($item['Value']));
                    break;
            }
        }
    }

    // Check if transaction exists in MySQL
    $stmt = $conn->prepare("SELECT id, ResultCode, MpesaReceiptNumber FROM mpesa_transactions WHERE CheckoutRequestID = ? LIMIT 1");
    $stmt->execute([$CheckoutRequestID]);
    $row = $stmt->fetch();

    $message = "";

    if ($row) {
        // Update if receipt arrives or ResultCode changed
        if (($row['ResultCode'] != $ResultCode) || (empty($row['MpesaReceiptNumber']) && !empty($MpesaReceiptNumber))) {
            $upd = $conn->prepare("
                UPDATE mpesa_transactions 
                SET ResultCode = ?, 
                    ResultDesc = ?, 
                    Amount = COALESCE(?, Amount), 
                    MpesaReceiptNumber = COALESCE(NULLIF(?, ''), MpesaReceiptNumber), 
                    PhoneNumber = COALESCE(NULLIF(?, ''), PhoneNumber), 
                    TransactionDate = COALESCE(?, TransactionDate), 
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $upd->execute([$ResultCode, $ResultDesc, $Amount, $MpesaReceiptNumber, $PhoneNumber, $TransactionDate, $row['id']]);
            $message = "Transaction updated successfully";
            logMessage("Updated transaction id {$row['id']} - Receipt: $MpesaReceiptNumber");
        } else {
            $message = "Duplicate callback - no action needed";
            logMessage("Duplicate callback - CheckoutRequestID: $CheckoutRequestID");
        }
    } else {
        // Insert new
        $ins = $conn->prepare("
            INSERT INTO mpesa_transactions 
            (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, MpesaReceiptNumber, PhoneNumber, TransactionDate, raw, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([$MerchantRequestID, $CheckoutRequestID, $ResultCode, $ResultDesc, $Amount, $MpesaReceiptNumber, $PhoneNumber, $TransactionDate, $callbackJSON]);
        $message = "Transaction saved successfully";
        logMessage("Inserted transaction - CheckoutRequestID: $CheckoutRequestID, Receipt: $MpesaReceiptNumber");
    }

    // 🔥 Forward to VB.NET API so SQL Server also gets updated
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
        curl_close($ch);

        logMessage("Forwarded to VB.NET API: " . json_encode($payload) . " Response: $resp");
    } catch (Exception $ex) {
        logMessage("ERROR forwarding to VB.NET API: " . $ex->getMessage());
    }

    // Respond to Safaricom
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Transaction Completed successfully',
        'Status' => $message
    ]);

} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Transaction received but processing failed'
    ]);
}
?>
