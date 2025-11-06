<?php
// mpesa_callback.php - FIXED VERSION
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
$vbnetApiUrl = "https://alphaplusapi.onrender.com/api/Mpesa/update";

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

    // Extract CallbackMetadata (only present on success ResultCode=0)
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
                    // M-Pesa format: 20231115143022 (YYYYMMDDHHmmss)
                    $dateStr = (string)$item['Value'];
                    if (strlen($dateStr) == 14) {
                        $TransactionDate = date('Y-m-d H:i:s', strtotime(
                            substr($dateStr, 0, 4) . '-' .
                            substr($dateStr, 4, 2) . '-' .
                            substr($dateStr, 6, 2) . ' ' .
                            substr($dateStr, 8, 2) . ':' .
                            substr($dateStr, 10, 2) . ':' .
                            substr($dateStr, 12, 2)
                        ));
                    }
                    break;
            }
        }
    }

    logMessage("Parsed -> CheckoutRequestID: $CheckoutRequestID | ResultCode: $ResultCode | Amount: $Amount | Receipt: $MpesaReceiptNumber | Phone: $PhoneNumber");

    // =============================
    // CHECK EXISTING TRANSACTION
    // =============================
    $stmt = $conn->prepare("SELECT id, result_code, mpesa_receipt_number FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1");
    $stmt->execute([$CheckoutRequestID]);
    $row = $stmt->fetch();

    $message = "";

    if ($row) {
        // =============================
        // UPDATE EXISTING TRANSACTION
        // =============================
        logMessage("📝 Updating existing transaction ID: {$row['id']}");
        
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
        $message = "Transaction updated: ResultCode=$ResultCode";
        logMessage("✅ Updated transaction - Receipt: $MpesaReceiptNumber");
        
    } else {
        // =============================
        // INSERT NEW TRANSACTION
        // =============================
        logMessage("🆕 Inserting new transaction");
        
        $ins = $conn->prepare("
            INSERT INTO mpesa_transactions 
            (merchant_request_id, checkout_request_id, result_code, result_desc, amount, mpesa_receipt_number, phone_number, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$MerchantRequestID, $CheckoutRequestID, $ResultCode, $ResultDesc, $Amount, $MpesaReceiptNumber, $PhoneNumber, $TransactionDate]);
        $message = "Transaction saved: ResultCode=$ResultCode";
        logMessage("✅ Inserted transaction - CheckoutRequestID: $CheckoutRequestID");
    }

    // =============================
    // FORWARD TO VB.NET API (Only on success)
    // =============================
    if ($ResultCode == 0 && !empty($MpesaReceiptNumber)) {
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            
            if (curl_errno($ch)) {
                logMessage("⚠️ CURL error forwarding to VB.NET: " . curl_error($ch));
            } else {
                logMessage("➡️ Forwarded to VB.NET API: Response=$resp");
            }
            curl_close($ch);
        } catch (Exception $ex) {
            logMessage("❌ ERROR forwarding to VB.NET API: " . $ex->getMessage());
        }
    }

    // =============================
    // SUCCESS RESPONSE
    // =============================
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Callback processed successfully',
        'Status' => $message
    ]);
    logMessage("✅ Callback processed -> $message");

} catch (Exception $e) {
    // =============================
    // ERROR HANDLER
    // =============================
    logMessage("❌ ERROR: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 1,
        'ResultDesc' => 'Callback received but processing failed',
        'Error' => $e->getMessage()
    ]);
}
?>