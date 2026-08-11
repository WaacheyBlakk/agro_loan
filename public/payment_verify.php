<?php
require_once __DIR__ . '/../src/security_headers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//payment_verify.php
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php';
require_once __DIR__ . '/../src/order_helpers.php';
require_once __DIR__ . '/../src/mailer.php';

header('Content-Type: application/json');

$user_id  = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$order_id = filter_input(INPUT_GET,'order_id',FILTER_VALIDATE_INT);
$ref      = filter_input(INPUT_GET,'ref',FILTER_SANITIZE_SPECIAL_CHARS);

if (!$user_id || !$order_id || !$ref) {
    echo json_encode(['status'=>'error','message'=>'Invalid request']);
    exit;
}

$pdo = getPDO();

$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id=? AND buyer_id=?");
$orderStmt->execute([$order_id, $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['status'=>'error','message'=>'Order not found']);
    exit;
}

// Idempotency guards — these prevent re-running escrow creation or stock
// restoration on repeated polls once a terminal state has been reached.
if ($order['payment_status'] === 'confirmed') {
    echo json_encode(['status'=>'confirmed','order_id'=>$order_id]);
    exit;
}
if ($order['payment_status'] === 'failed') {
    echo json_encode(['status'=>'failed']);
    exit;
}

$momoStatus = checkMoMoPaymentStatus($ref);
$apiStatus  = strtolower($momoStatus['status'] ?? 'pending');

if ($apiStatus === 'successful' || $apiStatus === 'approved') {

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE orders SET payment_status='confirmed', order_status='payment_confirmed', updated_at=NOW()
            WHERE id=?
        ")->execute([$order_id]);

        $pdo->prepare("UPDATE order_groups SET status='payment_confirmed', updated_at=NOW() WHERE order_id=?")
            ->execute([$order_id]);

        $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
        $itemsStmt->execute([$order_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $feePercent = 1.0;

        foreach ($items as $item) {
            $feeAmount    = round($item['subtotal'] * ($feePercent / 100), 2);
            $farmerAmount = $item['subtotal'];

            $pdo->prepare("
                INSERT INTO escrow (order_id, order_item_id, order_group_id, farmer_id, amount, platform_fee_portion, status)
                VALUES (?, ?, ?, ?, ?, ?, 'held')
            ")->execute([$order_id, $item['id'], $item['order_group_id'], $item['farmer_id'], $farmerAmount, $feeAmount]);
        }

        $pdo->prepare("
            INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)
        ")->execute([$order_id, 'payment_confirmed', 'Payment received and confirmed. Funds held in escrow, split per farmer package.', $user_id]);

        $pdo->commit();

        $buyerInfo = $pdo->prepare("SELECT name, email FROM buyers WHERE id = ?");
        $buyerInfo->execute([$user_id]);
        $buyer = $buyerInfo->fetch(PDO::FETCH_ASSOC);
        if ($buyer && !empty($buyer['email'])) {
            send_payment_confirmation_email($buyer['email'], $buyer['name'], $order_id, $order['total_amount']);
        }

        echo json_encode(['status'=>'confirmed','order_id'=>$order_id]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Payment verify escrow error: " . $e->getMessage());
        echo json_encode(['status'=>'pending']);
    }

} elseif (in_array($apiStatus, ['failed','rejected','cancelled','timeout'])) {

    try {
        $pdo->beginTransaction();
        restoreOrderStock($pdo, $order_id);

        $pdo->prepare("UPDATE orders SET payment_status='failed', order_status='payment_failed' WHERE id=?")
            ->execute([$order_id]);
        $pdo->prepare("UPDATE order_groups SET status='cancelled', updated_at=NOW() WHERE order_id=?")
            ->execute([$order_id]);
        $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)")
            ->execute([$order_id, 'payment_failed', 'Payment ' . $apiStatus . '. Order cancelled and stock released.', $user_id]);

        $pdo->commit();

        echo json_encode(['status'=>'failed']);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Payment verify failure-handling error: " . $e->getMessage());
        echo json_encode(['status'=>'pending']);
    }

} else {
    echo json_encode(['status'=>'pending']);
}