<?php
/**
 * api/confirm_delivery.php
 * Called by buyer_dashboard.php JS.
 * Marks order as delivered, releases escrow funds to each farmer via MoMo Disbursements API.
 */
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php'; // Your existing MoMo helper
header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$user_role = $_SESSION['role'] ?? 'buyer';

if (!$user_id || $user_role === 'farmer') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$order_id = filter_input(INPUT_POST,'order_id',FILTER_VALIDATE_INT);
if (!$order_id) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

$pdo = getPDO();

// Verify order belongs to buyer and is in a deliverable state
$orderStmt = $pdo->prepare("
    SELECT * FROM orders WHERE id=? AND buyer_id=? AND payment_status='confirmed'
    AND order_status IN ('in_transit','ready_for_pickup')
");
$orderStmt->execute([$order_id, $user_id]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success'=>false,'message'=>'Order not found or not eligible for confirmation']); exit;
}

// Fetch escrow records to release
$escrowStmt = $pdo->prepare("
    SELECT e.*, u.momo_phone, u.name AS farmer_name
    FROM escrow e
    JOIN users u ON e.farmer_id = u.id
    WHERE e.order_id=? AND e.status='held'
");
$escrowStmt->execute([$order_id]);
$escrowRecords = $escrowStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($escrowRecords)) {
    echo json_encode(['success'=>false,'message'=>'No escrow records found for this order']); exit;
}

try {
    $pdo->beginTransaction();

    // 1. Mark order as delivered
    $pdo->prepare("UPDATE orders SET order_status='delivered', updated_at=NOW() WHERE id=?")
        ->execute([$order_id]);

    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)")
        ->execute([$order_id, 'delivered', 'Buyer confirmed delivery. Releasing funds to farmer(s).', $user_id]);

    $disbursementErrors = [];

    foreach ($escrowRecords as $escrow) {
        if (empty($escrow['momo_phone'])) {
            // Log but don't fail — manual disbursement will be needed
            $disbursementErrors[] = "Farmer {$escrow['farmer_name']} has no MoMo number set.";
            $pdo->prepare("UPDATE escrow SET status='released', released_at=NOW(), momo_disbursement_ref='MANUAL_NEEDED' WHERE id=?")
                ->execute([$escrow['id']]);
            continue;
        }

        // Format number for Ghana (+233)
        $momoPhone = '233' . ltrim(preg_replace('/\D/','',$escrow['momo_phone']), '0');

        // Call MoMo Disbursements API
        $disburse = disburseMoMoPayment([
            'amount'       => $escrow['amount'],
            'currency'     => 'GHS',
            'phone'        => $momoPhone,
            'external_id'  => 'ESCROW-'.$escrow['id'].'-'.time(),
            'description'  => "AgroMarket payment for Order #{$order_id}",
        ]);

        $ref = $disburse['reference'] ?? ('FALLBACK-'.uniqid());

        $pdo->prepare("
            UPDATE escrow
            SET status='released', released_at=NOW(), momo_disbursement_ref=?
            WHERE id=?
        ")->execute([$ref, $escrow['id']]);

        // Tracking note per farmer
        $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes) VALUES (?,?,?)")
            ->execute([$order_id, 'escrow_released', "₵".number_format($escrow['amount'],2)." released to {$escrow['farmer_name']}."]);
    }

    $pdo->commit();

    $response = ['success'=>true,'message'=>'Delivery confirmed. Farmer payment initiated.'];
    if ($disbursementErrors) {
        $response['warnings'] = $disbursementErrors;
    }
    echo json_encode($response);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("confirm_delivery error: ".$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'A server error occurred. Please try again or contact support.']);
}
