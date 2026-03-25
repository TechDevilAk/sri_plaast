<?php
session_start();
require_once 'includes/db.php';

if (isset($_SESSION['user_id'])) {
    // Log activity
    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'logout', 'User logged out')";
    $stmt = $conn->prepare($log_query);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
}

// Destroy session
session_destroy();
header("Location: login.php");
exit();
?>