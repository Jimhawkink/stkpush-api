<?php
// stkpush.php — Initiate M-Pesa STK Push
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- Database Configuration (CloudClusters) ---
$host = getenv('DB_HOST') ?: 'mysql-204162-0.cloudclusters.net';
$user = getenv('DB_USER') ?: 'admin';
$password = getenv('DB_PASSWORD') ?: '5ZT8bJWM';
$database = getenv('DB_NAME') ?: 'Mpesa_DB';
$port = intval(getenv('DB_PORT') ?: 19902);

// --- M-Pesa Credentials ---
$consumerKey = getenv('MPESA_CONSUMER_KEY') ?: 'BqGXfPzkAS3Ada7JAV6jNcr26hKRmzVn';
$consumerSecret = getenv('MPESA_CONSUMER_SECRET') ?: 'NHfO1qmG1pMzBiVy';
$shortCode = getenv('MPESA_SHORTCODE') ?: '7887702';
$passkey = getenv('MPESA_PASSKEY') ?: '8ba2b74132b75970ed1d1ca22396f8b4eb79106902bf8e0017f4f0558fb6cc18';
$callbackUrl = getenv('MPESA_CALLBACK_URL') ?: 'https://stkpush-api.onrender.com/callback.php';

// --- Input from frontend ---
$input = json_decode(file_get_contents('php://input'), true);
$amount = $input['amount'] ?? 1;
$phone = $input['phone'] ?? '';
$accountRef = $input['account'] ?? 'ALPHAPLUS';
$transactionDesc = $input['description'] ?? 'Payment';

// ✅ Sanitize phone number
$phone = preg_replace('/^0/', '254', $phone);
if (!preg_match('/^254\d{9}$/', $phone)) {
    respond(false, "Invalid phone number format. Use 07XXXXXXXX or 2547XXXXXXXX");
}

// --- Get access token ---
$accessToken = getAccessToken($consumerKey, $consumerSecret);
if (!$accessToken) {
    respond(false, "Failed to get access token from M-Pesa");
}

// --- Prepare STK Push request ---
$timestamp = date('YmdHis');
$password = base64_encode($shortCode . $passkey . $timestamp);

$request = [
    'BusinessShortCode' => $shortCode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => $shortCode,
    'PhoneNumber' => $phone,
    'CallBackURL' => $callbackUrl,
    'AccountReference' => $accountRef,
    'TransactionDesc' => $transactionDesc
];

// --- Log request for debugging ---
$logFile = __DIR__ . '/logs/stk_debug.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] REQUEST: " . json_encode($request) . PHP_EOL, FILE_APPEND);

// --- Make STK Push request ---
$response = makeStkRequest($accessToken, $request);

// --- Log response for debugging ---
file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] RESPONSE: " . json_encode($response) . PHP_EOL, FILE_APPEND);

// --- Handle response ---
if (!$response || !isset($response['ResponseCode'])) {
    respond(false, "Invalid response from M-Pesa: " . json_encode($response));
}

if ($response['ResponseCode'] == '0') {
    // ✅ Save to database
    $conn = new mysqli($host, $user, $password, $database, $port);
    if ($conn->connect_error) {
        respond(false, "DB Connection failed: " . $conn->connect_error);
    }

    $MerchantRequestID = $response['MerchantRequestID'] ?? '';
    $CheckoutRequestID = $response['CheckoutRequestID'] ?? '';
    $CustomerMessage = $response['CustomerMessage'] ?? 'Request accepted for processing';

    $stmt = $conn->prepare("
        INSERT INTO mpesa_transactions 
        (MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc, Amount, PhoneNumber, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $resultCode = 0;
    $resultDesc = 'Request accepted for processing';
    $stmt->bind_param('ssisss', $MerchantRequestID, $CheckoutRequestID, $resultCode, $resultDesc, $amount, $phone);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    respond(true, $CustomerMessage, [
        'MerchantRequestID' => $MerchantRequestID,
        'CheckoutRequestID' => $CheckoutRequestID
    ]);
} else {
    respond(false, "M-Pesa Error: " . ($response['errorMessage'] ?? 'Unknown error'));
}

// --- Helper Functions ---
function getAccessToken($key, $secret) {
    $credentials = base64_encode("$key:$secret");
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
        CURLOPT_HTTPHEADER => ["Authorization: Basic $credentials"],
        CURLOPT_RETURNTRANSFER => true
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}

function makeStkRequest($token, $data) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $token"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true
    ]);
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result, true);
}

function respond($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}
?>
