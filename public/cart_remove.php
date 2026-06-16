<?php
// cart_remove.php — AJAX endpoint
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Login required']); exit; }

$produce_id = filter_input(INPUT_POST,'product_id',FILTER_VALIDATE_INT);
if (!$produce_id) { echo json_encode(['success'=>false,'message'=>'Invalid product']); exit; }

$pdo = getPDO();
$pdo->prepare("DELETE FROM cart WHERE user_id=? AND product_id=?")->execute([$user_id,$produce_id]);

// Recalculate cart total using standard relation mappings
$totalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(c.quantity * p.price_per_bag),0)
    FROM cart c JOIN produce_listings p ON c.product_id=p.id
    WHERE c.user_id=?
");
$totalStmt->execute([$user_id]);
$cartTotal = (float)$totalStmt->fetchColumn();

$countStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
$countStmt->execute([$user_id]);
$cartCount = (int)$countStmt->fetchColumn();

echo json_encode(['success'=>true,'cart_total'=>number_format($cartTotal,2),'cart_count'=>$cartCount]);