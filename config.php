<?php
// config.php - MySQL connection using environment variables from Docker
$host = getenv('DB_HOST') ?: 'mysql-204162-0.cloudclusters.net';
$user = getenv('DB_USER') ?: 'admin';
$password = getenv('DB_PASSWORD') ?: '5ZT8bJWM';
$database = getenv('DB_NAME') ?: 'Mpesa_DB';
$port = intval(getenv('DB_PORT') ?: 19902);

$conn = new mysqli($host, $user, $password, $database, $port);

if ($conn->connect_error) {
    file_put_contents(__DIR__ . "/db_error.log", date("c") . " - Connection failed: " . $conn->connect_error . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit();
}

// set charset
$conn->set_charset("utf8mb4");
?>