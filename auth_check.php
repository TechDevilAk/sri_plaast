<?php

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check role-based access
function checkRoleAccess($allowed_roles = ['admin', 'sale']) {
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        header("Location: login.php");
        exit();
    }
}

// Get current user info
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role']
    ];
}
?>