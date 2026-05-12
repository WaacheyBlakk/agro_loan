<?php
session_start();
require_once __DIR__ . '/../src/db.php';
$pdo = getPDO();
if (!isset($_SESSION['buyer_id'])) { header('Location: buyers_login.php'); exit; }
$buyer_id = $_SESSION['buyer_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
$shipping = trim($_POST['shipping_address']);

// fetch cart items again (with FOR UPDATE)
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT ci.id, ci.quantity, p.id AS produce_id, p.price_per_bag, p.bags_available, p.farmer_id
        FROM cart_items ci JOIN produce_listings p ON ci.produce_id=p.id
        WHERE ci.buyer_id = ? FOR UPDATE");
    $stmt->execute([$buyer_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) throw new Exception("Cart is empty.");

    $total = 0;
    foreach ($items as $it) {
        if ($it['quantity'] > $it['bags_available']) throw new Exception("Insufficient stock for " . $it['produce_name']);
        $total += $it['quantity'] * $it['price_per_bag'];
    }

    // create order
    $oi = $pdo->prepare("INSERT INTO orders (buyer_id,total_amount,shipping_address,status) VALUES (?,?,?,?)");
    $oi->execute([$buyer_id, $total, $shipping, 'processing']);
    $order_id = $pdo->lastInsertId();

    // insert order items and decrement stock
    $ins = $pdo->prepare("INSERT INTO order_items (order_id,produce_id,farmer_id,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?)");
    $updStock = $pdo->prepare("UPDATE produce_listings SET bags_available = bags_available - ? WHERE id = ?");
    $delCart = $pdo->prepare("DELETE FROM cart_items WHERE id = ?");

    foreach ($items as $it) {
        $subtotal = $it['quantity'] * $it['price_per_bag'];
        $ins->execute([$order_id, $it['produce_id'], $it['farmer_id'], $it['quantity'], $it['price_per_bag'], $subtotal]);
        $updStock->execute([$it['quantity'], $it['produce_id']]);
        $delCart->execute([$it['id']]);
    }

    $pdo->commit();
    header("Location: orders_success.php?order_id=" . $order_id);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['msg'] = "Order failed: " . $e->getMessage();
    header('Location: checkout.php');
    exit;
}
