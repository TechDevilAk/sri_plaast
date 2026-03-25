<?php
$db_host = 'srv1740.hstgr.io';
$db_name = 'u966043993_sriplaast';
$db_user = 'u966043993_sriplaast';
$db_pass = 'Sridpi@29';

if (!$db_host || !$db_name || !$db_user) {
    die("Database configuration missing. Please set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, and MYSQL_PASSWORD environment variables.");
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
