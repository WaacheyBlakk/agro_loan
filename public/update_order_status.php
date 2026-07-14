<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/order_helpers.php';
header('Content-Type: application/json');

if (!csrf_verify_json()) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh the page and try again.']);
    exit;
}

$user_id   = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

if (!$user_id || $user_role !== 'farmer') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$group_id  = filter_input(INPUT_POST,'group_id',FILTER_VALIDATE_INT);
$newStatus = trim($_POST['status'] ?? '');

$allowed = ['preparing','in_transit','ready_for_pickup'];

if (!$group_id || !in_array($newStatus, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}

$pdo = getPDO();

try {
    $group = assertGroupBelongsToFarmer($pdo, $group_id, (int)$user_id);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}

$orderStmt = $pdo->prepare("SELECT payment_status FROM orders WHERE id = ?");
$orderStmt->execute([$group['order_id']]);
$paymentStatus = $orderStmt->fetchColumn();

if ($paymentStatus !== 'confirmed') {
    echo json_encode(['success'=>false,'message'=>'Cannot update before payment is confirmed']); exit;
}
if (in_array($group['status'], ['delivered','cancelled'])) {
    echo json_encode(['success'=>false,'message'=>'This package is already finalised']); exit;
}

$validTransitions = [
    'payment_confirmed' => ['preparing'],
    'preparing'         => ['in_transit','ready_for_pickup'],
];

$allowed_next = $validTransitions[$group['status']] ?? [];
if (!in_array($newStatus, $allowed_next)) {
    echo json_encode(['success'=>false,'message'=>"Cannot move from '{$group['status']}' to '{$newStatus}'"]); exit;
}

$statusLabels = [
    'preparing'        => 'Your package is being prepared by the farmer.',
    'in_transit'       => 'Your package is on the way — expect delivery soon.',
    'ready_for_pickup' => 'Your package is ready for pickup at the station.',
];

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE order_groups SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $group_id]);

    $pdo->prepare("INSERT INTO order_tracking (order_id, order_group_id, status, notes, updated_by) VALUES (?,?,?,?,?)")
        ->execute([$group['order_id'], $group_id, $newStatus, "[{$group['group_code']}] " . $statusLabels[$newStatus], $user_id]);

    recomputeOrderStatus($pdo, (int)$group['order_id']);

    $pdo->commit();

    echo json_encode(['success'=>true,'message'=>"{$group['group_code']} status updated to: ".$newStatus]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("update_order_status error: ".$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
