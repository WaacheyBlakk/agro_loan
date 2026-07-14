<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
/**
 * checkout_process.php
 * Accepts POST from checkout.php, creates the order PLUS one order_group
 * per farmer represented in the cart, initiates MoMo payment.
 * Returns JSON for AJAX handling.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php';
require_once __DIR__ . '/../src/order_helpers.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    echo json_encode(['success'=>false,'message'=>'Session expired. Please log in again.']);
    exit;
}

if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token']??'')) {
    echo json_encode(['success'=>false,'message'=>'Invalid request token.']);
    exit;
}

define('PLATFORM_FEE_PERCENT', 1.0);

$delivery_name    = trim(filter_input(INPUT_POST,'delivery_name',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$delivery_phone   = trim(filter_input(INPUT_POST,'delivery_phone',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$delivery_address = trim(filter_input(INPUT_POST,'delivery_address',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$buyer_notes      = trim(filter_input(INPUT_POST,'buyer_notes',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$momo_number      = preg_replace('/\D/','',$_POST['momo_number']??'');
$momo_network     = in_array($_POST['momo_network']??'',['MTN','Telecel','AirtelTigo']) ? $_POST['momo_network'] : 'MTN';

if (!$delivery_name || !$delivery_phone || !$delivery_address) {
    echo json_encode(['success'=>false,'message'=>'Please fill in all delivery details.']);
    exit;
}
if (strlen($momo_number) < 9) {
    echo json_encode(['success'=>false,'message'=>'Please enter a valid MoMo number.']);
    exit;
}

$pdo = getPDO();

$cartStmt = $pdo->prepare("
    SELECT c.product_id AS produce_id, c.quantity,
           p.produce_name, p.price_per_bag, p.bags_available, p.farmer_id
    FROM cart c
    JOIN produce_listings p ON c.product_id = p.id
    WHERE c.user_id = ?
");
$cartStmt->execute([$user_id]);
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($cartItems)) {
    echo json_encode(['success'=>false,'message'=>'Your cart is empty.']);
    exit;
}

foreach ($cartItems as $item) {
    if ($item['quantity'] > $item['bags_available']) {
        echo json_encode(['success'=>false,'message'=>"'{$item['produce_name']}' only has {$item['bags_available']} bags available. Please update your cart."]);
        exit;
    }
}

$subtotal     = array_sum(array_map(fn($i) => $i['price_per_bag'] * $i['quantity'], $cartItems));
$platform_fee = round($subtotal * (PLATFORM_FEE_PERCENT / 100), 2);
$total        = $subtotal + $platform_fee;

$momoFormatted = '233' . ltrim($momo_number, '0');

try {
    $pdo->beginTransaction();

    // 1. Create the parent order (a checkout basket, may span farmers)
    $orderStmt = $pdo->prepare("
        INSERT INTO orders
            (buyer_id, total_amount, platform_fee, payment_method, payment_status, order_status,
             delivery_name, delivery_phone, delivery_address, buyer_notes)
        VALUES (?, ?, ?, ?, 'pending', 'pending_payment', ?, ?, ?, ?)
    ");
    $orderStmt->execute([
        $user_id, $total, $platform_fee, strtolower($momo_network).'_momo',
        $delivery_name, $delivery_phone, $delivery_address, $buyer_notes
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // 2. Split the cart by farmer -> one order_group per farmer, each
    //    with its own human-readable code and its own status lifecycle.
    $byFarmer = [];
    foreach ($cartItems as $item) {
        $byFarmer[$item['farmer_id']][] = $item;
    }

    $groupStmt = $pdo->prepare("
        INSERT INTO order_groups (order_id, farmer_id, group_code, status, subtotal)
        VALUES (?, ?, ?, 'pending_payment', ?)
    ");
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, produce_id, farmer_id, order_group_id, quantity, unit_price, subtotal)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $sequence = 0;
    foreach ($byFarmer as $farmerId => $farmerItems) {
        $farmerSubtotal = array_sum(array_map(fn($i) => $i['price_per_bag'] * $i['quantity'], $farmerItems));
        $groupCode = generateGroupCode($orderId, $sequence++);

        $groupStmt->execute([$orderId, $farmerId, $groupCode, $farmerSubtotal]);
        $groupId = (int)$pdo->lastInsertId();

        foreach ($farmerItems as $item) {
            $lineTotal = $item['price_per_bag'] * $item['quantity'];
            $itemStmt->execute([$orderId, $item['produce_id'], $farmerId, $groupId, $item['quantity'], $item['price_per_bag'], $lineTotal]);
        }
    }

    // 3. Initial tracking entry (order-level; per-group entries are added
    //    once payment clears and each group starts moving independently)
    $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)")
        ->execute([$orderId, 'pending_payment', 'Order placed, awaiting payment.', $user_id]);

    // 4. Initiate MoMo payment for the whole basket total
    $momoResult = initiateMoMoCollection([
        'amount'       => $total,
        'currency'     => 'GHS',
        'phone'        => $momoFormatted,
        'external_id'  => 'ORDER-' . $orderId . '-' . time(),
        'description'  => "AgroMarket Order #{$orderId}",
        'network'      => $momo_network,
    ]);

    if (!$momoResult['success']) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Payment initiation failed: ' . ($momoResult['message']??'Unknown error')]);
        exit;
    }

    $reference = $momoResult['reference'];

    $pdo->prepare("UPDATE orders SET momo_reference=? WHERE id=?")->execute([$reference, $orderId]);
    $pdo->prepare("DELETE FROM cart WHERE user_id=?")->execute([$user_id]);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'order_id' => $orderId,
        'reference'=> $reference,
        'message'  => 'Order created. Awaiting payment confirmation.',
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Checkout error: " . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'A server error occurred. Please try again.']);
}
