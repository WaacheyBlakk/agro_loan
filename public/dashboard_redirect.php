<?php
require_once __DIR__ . '/../src/security_headers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'farmer') {
    header("Location: seller_dashboard.php");
} else {
    header("Location: buyer_dashboard.php");
}
exit();