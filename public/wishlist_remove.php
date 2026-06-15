<?php
// wishlist_remove.php — AJAX endpoint
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Login required']); exit; }

$produce_id = filter_input(INPUT_POST,'product_id',FILTER_VALIDATE_INT);
if (!$produce_id) { echo json_encode(['success'=>false,'message'=>'Invalid product']); exit; }

$pdo = getPDO();
$stmt = $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND produce_id = ?");
$stmt->execute([$user_id, $produce_id]);

echo json_encode(['success'=>true,'message'=>'Removed from wishlist']);
