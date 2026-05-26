<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$pdo = getPDO();

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$role = $_SESSION['role'] ?? null;

if (!$user_id || $role !== 'buyer') {
    header("Location: buyers_login.php");
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);

if ($product_id <= 0) {
    header("Location: shop.php");
    exit;
}

$stmt = $pdo->prepare("
    INSERT IGNORE INTO wishlist_items (user_id, product_id)
    VALUES (?, ?)
");

$stmt->execute([$user_id, $product_id]);

http_response_code(200);
echo "success";
exit;