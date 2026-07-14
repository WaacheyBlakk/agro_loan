<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
/**
 * confirm_delivery.php
 * Called by buyer_dashboard.php JS.
 * Buyer confirms receipt of ONE farmer's package (an order_group) at a
 * time. Only that farmer's escrow is released — never another farmer's,
 * even though they may share the same parent order.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php';
require_once __DIR__ . '/../src/order_helpers.php';
header('Content-Type: application/json');

if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $decodedJson = json_decode($rawInput, true);
    if (is_array($decodedJson)) {
        $_POST = array_merge($_POST, $decodedJson);
    }
}

if (!csrf_verify_json()) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh the page and try again.']);
    exit;
}

$user_id   = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$user_role = $_SESSION['role'] ?? 'buyer';

if (!$user_id || $user_role === 'farmer') {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit;
}

$group_id = filter_input(INPUT_POST,'group_id',FILTER_VALIDATE_INT) ?: filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT);

if (!$group_id) {
    echo json_encode(['success'=>false,'message'=>'Invalid request parameters']);
    exit;
}

$pdo = getPDO();

try {
    // Buyer must own the parent order of this specific farmer package.
    $group = assertGroupBelongsToBuyerOrder($pdo, $group_id, (int)$user_id);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}

$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND payment_status = 'confirmed'");
$orderStmt->execute([$group['order_id']]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success'=>false,'message'=>'Order not found or not eligible for confirmation']); exit;
}

if ($group['status'] === 'delivered') {
    echo json_encode(['success'=>false,'message'=>'This package was already confirmed']); exit;
}

// Only escrow tied to THIS group (i.e. this farmer's items) is fetched —
// another farmer's held funds in the same order are never touched here.
$escrowStmt = $pdo->prepare("
    SELECT e.*, u.momo_phone, u.name AS farmer_name
    FROM escrow e
    JOIN users u ON e.farmer_id = u.id
    WHERE e.order_group_id = ? AND e.status = 'held'
");
$escrowStmt->execute([$group_id]);
$escrowRecords = $escrowStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($escrowRecords)) {
    echo json_encode(['success'=>false,'message'=>'No pending escrow records found for this package']); exit;
}

try {
    $pdo->beginTransaction();

    // 1. Mark this farmer's items + group delivered
    $pdo->prepare("UPDATE order_items SET item_status='delivered' WHERE order_group_id = ?")
        ->execute([$group_id]);

    $pdo->prepare("UPDATE order_groups SET status='delivered', updated_at=NOW() WHERE id = ?")
        ->execute([$group_id]);

    $pdo->prepare("INSERT INTO order_tracking (order_id, order_group_id, status, notes, updated_by) VALUES (?,?,?,?,?)")
        ->execute([$group['order_id'], $group_id, 'delivered', "Buyer confirmed delivery for package {$group['group_code']}.", $user_id]);

    $disbursementErrors = [];

    // 2. Disburse only this farmer's held funds
    foreach ($escrowRecords as $escrow) {
        if (empty($escrow['momo_phone'])) {
            $disbursementErrors[] = "Farmer {$escrow['farmer_name']} has no MoMo number set.";
            $pdo->prepare("UPDATE escrow SET status='released', released_at=NOW(), momo_disbursement_ref='MANUAL_NEEDED' WHERE id=?")
                ->execute([$escrow['id']]);
            continue;
        }

        $momoPhone = '233' . ltrim(preg_replace('/\D/','',$escrow['momo_phone']), '0');

        try {
            $disburse = disburseMoMoPayment([
                'amount'       => $escrow['amount'],
                'currency'     => 'GHS',
                'phone'        => $momoPhone,
                'external_id'  => 'ESCROW-'.$escrow['id'].'-'.time(),
                'description'  => "AgroMarket payment for {$group['group_code']}",
            ]);

            $ref = $disburse['reference'] ?? ('FALLBACK-'.uniqid());

            $pdo->prepare("
                UPDATE escrow
                SET status='released', released_at=NOW(), momo_disbursement_ref=?
                WHERE id=?
            ")->execute([$ref, $escrow['id']]);

            $pdo->prepare("INSERT INTO order_tracking (order_id, order_group_id, status, notes) VALUES (?,?,?,?)")
                ->execute([$group['order_id'], $group_id, 'escrow_released', "₵".number_format($escrow['amount'],2)." released to {$escrow['farmer_name']} ({$group['group_code']})."]);

        } catch (Exception $apiException) {
            error_log("MoMo API Exception for Escrow ID {$escrow['id']}: " . $apiException->getMessage());
            $disbursementErrors[] = "Funds release API error for {$escrow['farmer_name']} (flagged for manual review).";

            $pdo->prepare("
                UPDATE escrow
                SET status='released', released_at=NOW(), momo_disbursement_ref='API_ERROR_CHECK_CONSOLE'
                WHERE id=?
            ")->execute([$escrow['id']]);
        }
    }

    // 3. Roll the parent order's summary status up — it only becomes
    //    'delivered' once every farmer's package in it is delivered.
    recomputeOrderStatus($pdo, (int)$group['order_id']);

    $pdo->commit();

    $response = ['success'=>true, 'message'=>"Package {$group['group_code']} confirmed and paid out."];
    if ($disbursementErrors) {
        $response['warnings'] = $disbursementErrors;
    }
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("confirm_delivery error: " . $e->getMessage());
    echo json_encode(['success'=>false, 'message'=>'A system adjustment occurred. Please try again.']);
}
