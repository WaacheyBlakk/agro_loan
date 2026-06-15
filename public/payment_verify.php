<?php
/**
 * payment_verify.php
 * Polled by checkout.php JS every 5s.
 * Checks MoMo payment status; on confirmation: updates order, deducts stock, creates escrow records.
 */
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php';

header('Content-Type: application/json');

$user_id  = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$order_id = filter_input(INPUT_GET,'order_id',FILTER_VALIDATE_INT);
$ref      = filter_input(INPUT_GET,'ref',FILTER_SANITIZE_SPECIAL_CHARS);

if (!$user_id || !$order_id || !$ref) {
    echo json_encode(['status'=>'error','message'=>'Invalid request']); exit;
}

$pdo = getPDO();

// Fetch order — must belong to this buyer
$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND buyer_id=?");
$orderStmt->execute([$order_id, $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) { echo json_encode(['status'=>'error','message'=>'Order not found']); exit; }

// Already confirmed? Return early.
if ($order['payment_status'] === 'confirmed') {
    echo json_encode(['status'=>'confirmed','order_id'=>$order_id]); exit;
}
if ($order['payment_status'] === 'failed') {
    echo json_encode(['status'=>'failed']); exit;
}

// Query MoMo API
$momoStatus = checkMoMoPaymentStatus($ref);
$apiStatus  = strtolower($momoStatus['status'] ?? 'pending');

if ($apiStatus === 'successful' || $apiStatus === 'approved') {

    try {
        $pdo->beginTransaction();

        // Update order status
        $pdo->prepare("
            UPDATE orders SET payment_status='confirmed', order_status='payment_confirmed', updated_at=NOW()
            WHERE id=?
        ")->execute([$order_id]);

        // Fetch order items
        $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $feePercent = 2.5;

        foreach ($items as $item) {
            // Deduct stock
            $pdo->prepare("
                UPDATE produce_listings SET bags_available = GREATEST(0, bags_available - ?) WHERE id=?
            ")->execute([$item['quantity'], $item['produce_id']]);

            // Create escrow record (net of platform fee portion)
            $feeAmount    = round($item['subtotal'] * ($feePercent / 100), 2);
            $farmerAmount = $item['subtotal'] - $feeAmount;

            $pdo->prepare("
                INSERT INTO escrow (order_id, order_item_id, farmer_id, amount, platform_fee_portion, status)
                VALUES (?, ?, ?, ?, ?, 'held')
            ")->execute([$order_id, $item['id'], $item['farmer_id'], $farmerAmount, $feeAmount]);
        }

        // Add tracking entry
        $pdo->prepare("
            INSERT INTO order_tracking (order_id, status, notes) VALUES (?,?,?)
        ")->execute([$order_id, 'payment_confirmed', 'Payment received and confirmed. Funds held in escrow.']);

        $pdo->commit();

        echo json_encode(['status'=>'confirmed','order_id'=>$order_id]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Payment verify escrow error: " . $e->getMessage());
        echo json_encode(['status'=>'pending']); // retry
    }

} elseif (in_array($apiStatus, ['failed','rejected','cancelled','timeout'])) {

    $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=?")->execute([$order_id]);
    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?,?,?)")
        ->execute([$order_id, 'payment_failed', 'Payment ' . $apiStatus . '. Order cancelled.']);

    echo json_encode(['status'=>'failed']);

} else {
    // Still pending
    echo json_encode(['status'=>'pending']);
}
