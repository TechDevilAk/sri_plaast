<?php
// update_cart.php
session_start();
require_once 'includes/db.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['items'])) {
    $_SESSION['sale_cart'] = $input['items'];
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No items provided']);
}