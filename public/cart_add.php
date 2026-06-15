<?php
// cart_add.php — AJAX endpoint
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success'=>false,'message'=>'Please log in to add items to cart','redirect'=>'buyers_login.php']);
    exit;
}

// Farmers cannot buy
$role = $_SESSION['role'] ?? 'buyer';
if ($role === 'farmer') {
    echo json_encode(['success'=>false,'message'=>'Farmer accounts cannot purchase items']);
    exit;
}

$produce_id = filter_input(INPUT_POST,'product_id',FILTER_VALIDATE_INT);
$quantity   = max(1, (int)(filter_input(INPUT_POST,'quantity',FILTER_VALIDATE_INT) ?? 1));

if (!$produce_id) { echo json_encode(['success'=>false,'message'=>'Invalid product']); exit; }

$pdo = getPDO();

// Check stock
$check = $pdo->prepare("SELECT id, bags_available, produce_name FROM produce_listings WHERE id = ?");
$check->execute([$produce_id]);
$product = $check->fetch(PDO::FETCH_ASSOC);

if (!$product) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }
if ($product['bags_available'] < 1) { echo json_encode(['success'=>false,'message'=>'This item is out of stock']); exit; }
if ($quantity > $product['bags_available']) {
    echo json_encode(['success'=>false,'message'=>'Only '.$product['bags_available'].' bags available']);
    exit;
}

// Insert or update quantity
$stmt = $pdo->prepare("
    INSERT INTO cart (user_id, produce_id, quantity)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ?)
");
$stmt->execute([$user_id, $produce_id, $quantity, $product['bags_available']]);

// Return updated cart count
$countStmt = $pdo->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ?");
$countStmt->execute([$user_id]);
$cartCount = (int)$countStmt->fetchColumn();

echo json_encode(['success'=>true,'message'=>'Added to cart!','cart_count'=>$cartCount]);
