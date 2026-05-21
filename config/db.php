<?php
//connection between backend and database
// Database configuration and connection
define('DB_HOST', 'localhost');
define('DB_PORT', '1521');
define('DB_SID', 'xe');
define('DB_USERNAME', 'system');
define('DB_PASSWORD', 'dominiccasaul12345');

try {
    $dsn = "oci:dbname=//" . DB_HOST . ":" . DB_PORT . "/" . DB_SID;
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>


