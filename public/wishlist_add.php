<?php
// wishlist_add.php — AJAX endpoint
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

if (!$user_id) {
    echo json_encode(['success'=>false,'message'=>'Login required','redirect'=>'buyers_login.php']);
    exit;
}

$produce_id = filter_input(INPUT_POST,'product_id',FILTER_VALIDATE_INT);
if (!$produce_id) { echo json_encode(['success'=>false,'message'=>'Invalid product']); exit; }

$pdo = getPDO();

// Verify product exists in our listing database
$check = $pdo->prepare("SELECT id FROM produce_listings WHERE id = ?");
$check->execute([$produce_id]);
if (!$check->fetch()) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

// standard query mapping table: wishlist_items
$stmt = $pdo->prepare("INSERT IGNORE INTO wishlist_items (user_id, product_id) VALUES (?, ?)");
$stmt->execute([$user_id, $produce_id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['success'=>true,'message'=>'Added to your wishlist!']);
} else {
    echo json_encode(['success'=>true,'message'=>'Already in your wishlist','already_exists'=>true]);
}