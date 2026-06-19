<?php
session_start();
require_once __DIR__ . '/../src/db.php';
header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if (!$user_id || $user_role !== 'farmer') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$order_id  = filter_input(INPUT_POST,'order_id',FILTER_VALIDATE_INT);
$newStatus = trim($_POST['status'] ?? '');

// Only these transitions are allowed by seller
$allowed = ['preparing','in_transit','ready_for_pickup'];

if (!$order_id || !in_array($newStatus, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}

$pdo = getPDO();

// Verify this order contains at least one item belonging to this farmer
$check = $pdo->prepare("
    SELECT o.id, o.order_status, o.payment_status
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.id = ? AND oi.farmer_id = ?
    LIMIT 1
");
$check->execute([$order_id, $user_id]);
$order = $check->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success'=>false,'message'=>'Order not found or not yours']); exit;
}
if ($order['payment_status'] !== 'confirmed') {
    echo json_encode(['success'=>false,'message'=>'Cannot update order before payment is confirmed']); exit;
}
if (in_array($order['order_status'],['delivered','cancelled'])) {
    echo json_encode(['success'=>false,'message'=>'Order is already finalised']); exit;
}

// Enforce valid transitions
$validTransitions = [
    'payment_confirmed' => ['preparing'],
    'preparing'         => ['in_transit','ready_for_pickup'],
];

$allowed_next = $validTransitions[$order['order_status']] ?? [];
if (!in_array($newStatus, $allowed_next)) {
    echo json_encode(['success'=>false,'message'=>"Cannot move from '{$order['order_status']}' to '{$newStatus}'"]); exit;
}

$statusLabels = [
    'preparing'        => 'Order is being prepared by the farmer.',
    'in_transit'       => 'Order is on the way — expect delivery soon.',
    'ready_for_pickup' => 'Order is ready for pickup at the farm.',
];

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE orders SET order_status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $order_id]);

    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)")
        ->execute([$order_id, $newStatus, $statusLabels[$newStatus], $user_id]);

    $pdo->commit();

    echo json_encode(['success'=>true,'message'=>'Status updated to: '.$newStatus]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("update_order_status error: ".$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
