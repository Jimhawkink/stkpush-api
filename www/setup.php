<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>Database Setup</title>";
echo "<style>body{font-family:Arial;padding:40px;background:#f5f5f5;}";
echo ".success{color:green;} .error{color:red;} .info{color:blue;}</style></head><body>";
echo "<h1>🔧 M-Pesa Database Setup</h1>";

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "<div class='info'><strong>Connection Info:</strong><br>";
echo "Host: $host<br>";
echo "Port: $port<br>";
echo "Database: $dbname<br>";
echo "User: $user<br></div><hr>";

if (!$host || !$dbname || !$user || !$pass) {
    echo "<div class='error'>❌ ERROR: Missing database environment variables!<br>";
    echo "Make sure Railway MySQL service is added and connected.</div>";
    exit;
}

try {
    echo "<p>Connecting to database...</p>";
    
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "<div class='success'>✅ Connected to database successfully!</div><br>";
    
    // Create the mpesa_transactions table
    echo "<p>Creating mpesa_transactions table...</p>";
    
    $sql = "CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_request_id VARCHAR(100),
        checkout_request_id VARCHAR(100),
        result_code INT,
        result_desc VARCHAR(255),
        amount DECIMAL(10,2),
        mpesa_receipt_number VARCHAR(100),
        phone_number VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merchant_request (merchant_request_id),
        INDEX idx_checkout_request (checkout_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    
    echo "<div class='success'>✅ Table 'mpesa_transactions' created successfully!</div><br>";
    
    // Verify table structure
    echo "<h2>📋 Table Structure:</h2>";
    $columns = $pdo->query("DESCRIBE mpesa_transactions")->fetchAll();
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Show all tables in database
    echo "<h2>📦 All Tables in Database:</h2>";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<ul>";
    foreach($tables as $table) {
        echo "<li><strong>$table</strong></li>";
    }
    echo "</ul>";
    
    echo "<hr><div class='success'><h2>🎉 Setup Complete!</h2>";
    echo "<p>Your database is ready to receive M-Pesa callbacks.</p>";
    echo "<p><strong>IMPORTANT:</strong> Delete or protect this setup.php file after setup!</p></div>";
    
} catch(PDOException $e) {
    echo "<div class='error'><h2>❌ Database Error</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Code:</strong> " . $e->getCode() . "</p></div>";
}

echo "</body></html>";
?>