<?php
// cart_update.php — AJAX endpoint: update quantity
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Login required']); exit; }

$produce_id = filter_input(INPUT_POST,'product_id',FILTER_VALIDATE_INT);
$quantity   = filter_input(INPUT_POST,'quantity',FILTER_VALIDATE_INT);

if (!$produce_id || $quantity === false) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}

$pdo = getPDO();

if ($quantity < 1) {
    // Remove item if quantity <= 0
    $pdo->prepare("DELETE FROM cart WHERE user_id=? AND product_id=?")->execute([$user_id,$produce_id]);
    echo json_encode(['success'=>true,'message'=>'Item removed','removed'=>true]);
    exit;
}

// Validate against available stock using uniform produce columns
$stock = $pdo->prepare("SELECT bags_available FROM produce_listings WHERE id=?");
$stock->execute([$produce_id]);
$available = (int)$stock->fetchColumn();

if ($quantity > $available) {
    echo json_encode(['success'=>false,'message'=>"Only $available bags available"]);
    exit;
}

$pdo->prepare("UPDATE cart SET quantity=? WHERE user_id=? AND product_id=?")->execute([$quantity,$user_id,$produce_id]);

// Calculate new item subtotal
$price = $pdo->prepare("SELECT price_per_bag FROM produce_listings WHERE id=?");
$price->execute([$produce_id]);
$unitPrice = (float)$price->fetchColumn();
$subtotal  = $unitPrice * $quantity;

// Recalculate cart total
$totalStmt = $pdo->prepare("
    SELECT SUM(c.quantity * p.price_per_bag)
    FROM cart c JOIN produce_listings p ON c.product_id = p.id
    WHERE c.user_id = ?
");
$totalStmt->execute([$user_id]);
$cartTotal = (float)$totalStmt->fetchColumn();

echo json_encode([
    'success'    => true,
    'subtotal'   => number_format($subtotal,2),
    'cart_total' => number_format($cartTotal,2),
]);