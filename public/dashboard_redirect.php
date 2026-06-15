<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: buyers_login.php");
    exit();
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'farmer') {
    header("Location: seller_dashboard.php");
} else {
    header("Location: buyer_dashboard.php");
}
exit();