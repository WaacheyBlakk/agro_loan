<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//place_order.php
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/mailer.php';

$pdo = getPDO();

$buyer_id = $_SESSION['user_id'] ?? $_SESSION['buyer_id'] ?? $_SESSION['id'] ?? null;
if (!$buyer_id) { header('Location: login.php'); exit; }

$buyerInfo = $pdo->prepare("SELECT name, email FROM buyers WHERE id = ?");
$buyerInfo->execute([$buyer_id]);
$buyer = $buyerInfo->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
csrf_verify();
$shipping = trim($_POST['shipping_address'] ?? $_POST['delivery_address'] ?? '');

$pdo->beginTransaction();
try {
    // 1. Fetch cart items with row locking (FOR UPDATE)
    $stmt = $pdo->prepare("
        SELECT c.id AS cart_id, c.quantity, p.id AS produce_id, p.produce_name, p.price_per_bag, p.bags_available, p.farmer_id
        FROM cart c 
        JOIN produce_listings p ON c.product_id = p.id
        WHERE c.user_id = ? 
        FOR UPDATE
    ");
    $stmt->execute([$buyer_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        // Fallback for cart_items table schema
        $stmtAlt = $pdo->prepare("
            SELECT ci.id AS cart_id, ci.quantity, p.id AS produce_id, p.produce_name, p.price_per_bag, p.bags_available, p.farmer_id
            FROM cart_items ci 
            JOIN produce_listings p ON ci.produce_id = p.id
            WHERE ci.buyer_id = ? 
            FOR UPDATE
        ");
        $stmtAlt->execute([$buyer_id]);
        $items = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$items) throw new Exception("Your cart is empty.");

    $aggregated = [];
    foreach ($items as $it) {
        $pid = $it['produce_id'];
        if (!isset($aggregated[$pid])) {
            $aggregated[$pid] = $it;
        } else {
            $aggregated[$pid]['quantity'] += $it['quantity'];
        }
    }
    $items = array_values($aggregated);

    $stockErrors = [];
    $total = 0;
    foreach ($items as $it) {
        if ($it['quantity'] > $it['bags_available']) {
            $avail = max(0, (int)$it['bags_available']);
            $stockErrors[] = "'{$it['produce_name']}': you requested {$it['quantity']}, only {$avail} bag(s) available";
            continue;
        }
        $total += $it['quantity'] * $it['price_per_bag'];
    }
    if ($stockErrors) {
        throw new Exception("Insufficient stock — " . implode('; ', $stockErrors));
    }

    // 2. Create Order
    $oi = $pdo->prepare("INSERT INTO orders (buyer_id, total_amount, delivery_address, order_status) VALUES (?,?,?, 'processing')");
    $oi->execute([$buyer_id, $total, $shipping]);
    $order_id = $pdo->lastInsertId();

    // 3. Insert order items & decrement DB stock accurately by exact quantity purchased
    $ins = $pdo->prepare("INSERT INTO order_items (order_id, produce_id, farmer_id, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?)");
    $updStock = $pdo->prepare("UPDATE produce_listings SET bags_available = bags_available - ? WHERE id = ? AND bags_available >= ?");
    $delCartUser = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $delCartAlt  = $pdo->prepare("DELETE FROM cart_items WHERE buyer_id = ?");

    foreach ($items as $it) {
        $subtotal = $it['quantity'] * $it['price_per_bag'];
        $ins->execute([$order_id, $it['produce_id'], $it['farmer_id'], $it['quantity'], $it['price_per_bag'], $subtotal]);
        
        // REDUCE DB STOCK BY EXACT QUANTITY BOUGHT
        $updStock->execute([$it['quantity'], $it['produce_id'], $it['quantity']]);
        if ($updStock->rowCount() === 0) {
            throw new Exception("Insufficient stock for produce '{$it['produce_name']}'.");
        }
    }

    $delCartUser->execute([$buyer_id]);
    $delCartAlt->execute([$buyer_id]);

    $pdo->commit();

    if ($buyer && !empty($buyer['email'])) {
        send_order_confirmation_email($buyer['email'], $buyer['name'], $order_id, $total);
    }

    header("Location: orders_success.php?order_id=" . $order_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['checkout_error'] = "Order failed: " . $e->getMessage();
    header('Location: checkout.php');
    exit;
}