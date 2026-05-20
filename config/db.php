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



<?php
// The IP address of Computer A (Database Server)
$host = '192.168.1.50'; 
$port = '1521';
// Your Oracle Service Name or SID (usually XE or ORCL)
$service_name = 'XE'; 
$username = 'YOUR_ORACLE_USERNAME';
$password = 'YOUR_ORACLE_PASSWORD';

// The OCI connection string pointing to the separate machine
$tns = "(DESCRIPTION=(ADDRESS_LIST = (ADDRESS = (PROTOCOL = TCP)(HOST = {$host})(PORT = {$port})))(CONNECT_DATA=(SERVICE_NAME={$service_name})))";

try {
    $pdo = new PDO("oci:dbname=".$tns, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>