<?php
/**
 * checkout_process.php
 * Accepts POST from checkout.php, creates order, initiates MoMo payment.
 * Returns JSON for AJAX handling.
 */
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/momo.php'; // Your existing MoMo helper

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Session expired. Please log in again.']); exit; }

// CSRF check
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token']??'')) {
    echo json_encode(['success'=>false,'message'=>'Invalid request token.']); exit;
}

define('PLATFORM_FEE_PERCENT', 2.5);

$delivery_name    = trim(filter_input(INPUT_POST,'delivery_name',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$delivery_phone   = trim(filter_input(INPUT_POST,'delivery_phone',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$delivery_address = trim(filter_input(INPUT_POST,'delivery_address',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$buyer_notes      = trim(filter_input(INPUT_POST,'buyer_notes',FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$momo_number      = preg_replace('/\D/','',$_POST['momo_number']??'');
$momo_network     = in_array($_POST['momo_network']??'',['MTN','Telecel','AirtelTigo']) ? $_POST['momo_network'] : 'MTN';

// Basic validation
if (!$delivery_name || !$delivery_phone || !$delivery_address) {
    echo json_encode(['success'=>false,'message'=>'Please fill in all delivery details.']); exit;
}
if (strlen($momo_number) < 9) {
    echo json_encode(['success'=>false,'message'=>'Please enter a valid MoMo number.']); exit;
}

$pdo = getPDO();

// Fetch cart items
$cartStmt = $pdo->prepare("
    SELECT c.produce_id, c.quantity,
           p.produce_name, p.price_per_bag, p.bags_available, p.farmer_id
    FROM cart c JOIN produce_listings p ON c.produce_id = p.id
    WHERE c.user_id = ?
");
$cartStmt->execute([$user_id]);
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($cartItems)) {
    echo json_encode(['success'=>false,'message'=>'Your cart is empty.']); exit;
}

// Validate stock
foreach ($cartItems as $item) {
    if ($item['quantity'] > $item['bags_available']) {
        echo json_encode(['success'=>false,'message'=>"'{$item['produce_name']}' only has {$item['bags_available']} bags available. Please update your cart."]);
        exit;
    }
}

// Compute totals
$subtotal     = array_sum(array_map(fn($i) => $i['price_per_bag'] * $i['quantity'], $cartItems));
$platform_fee = round($subtotal * (PLATFORM_FEE_PERCENT / 100), 2);
$total        = $subtotal + $platform_fee;

// Format MoMo number for API (Ghana: strip leading 0, add country code)
$momoFormatted = '233' . ltrim($momo_number, '0');

try {
    $pdo->beginTransaction();

    // 1. Create order (pending_payment)
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

    // 2. Create order items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, produce_id, farmer_id, quantity, unit_price, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($cartItems as $item) {
        $lineTotal = $item['price_per_bag'] * $item['quantity'];
        $itemStmt->execute([$orderId, $item['produce_id'], $item['farmer_id'], $item['quantity'], $item['price_per_bag'], $lineTotal]);
    }

    // 3. Add initial tracking entry
    $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, notes, updated_by) VALUES (?,?,?,?)");
    $trackStmt->execute([$orderId, 'pending_payment', 'Order placed, awaiting payment.', $user_id]);

    // 4. Initiate MoMo payment
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

    // 5. Store MoMo reference
    $pdo->prepare("UPDATE orders SET momo_reference=? WHERE id=?")->execute([$reference, $orderId]);

    // 6. Clear cart
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
