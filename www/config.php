<?php
// config.php - Railway MySQL connection
$host = getenv('DB_HOST') ?: 'maglev.proxy.rlwy.net';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: 'cJYEAVTFXdujqruHefgQxugPVfdASWRv';
$database = getenv('DB_NAME') ?: 'railway';
$port = intval(getenv('DB_PORT') ?: 13831);

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