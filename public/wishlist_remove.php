<?php
// wishlist_remove.php — AJAX endpoint
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    echo json_encode(['success' => false, 'message' => 'Login required']); 
    exit; 
}

$produce_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
if (!$produce_id) { 
    echo json_encode(['success' => false, 'message' => 'Invalid product identifier.']); 
    exit; 
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Dynamic schema detection to prevent column/table mismatches
$wishlistTable = 'wishlist';
$wishlistColumn = 'produce_id';

// 1. Detect table name
try {
    $pdo->query("SELECT 1 FROM wishlist_items LIMIT 1");
    $wishlistTable = 'wishlist_items';
} catch (PDOException $e) {
    $wishlistTable = 'wishlist';
}

// 2. Detect column name
try {
    $pdo->query("SELECT product_id FROM {$wishlistTable} LIMIT 1");
    $wishlistColumn = 'product_id';
} catch (PDOException $e) {
    $wishlistColumn = 'produce_id';
}

// 3. Execute statement
try {
    $stmt = $pdo->prepare("DELETE FROM {$wishlistTable} WHERE user_id = ? AND {$wishlistColumn} = ?");
    $stmt->execute([$user_id, $produce_id]);
    echo json_encode(['success' => true, 'message' => 'Removed from wishlist successfully']);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error during removal: ' . $e->getMessage()
    ]);
}