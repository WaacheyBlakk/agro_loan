<?php
session_start();
require_once '../src/db.php';

if ($_SESSION['role'] !== 'buyer') exit("Only buyers can checkout");

$pdo = getPDO();
$buyer_id = $_SESSION['id'];

$cart = $pdo->prepare("
    SELECT cart.*, produce.name, produce.price_per_bag, produce.farmer_id
    FROM cart 
    JOIN produce ON cart.product_id = produce.id
    WHERE cart.user_id = ?
");
$cart->execute([$buyer_id]);
$items = $cart->fetchAll(PDO::FETCH_ASSOC);

if (!$items) die("Your cart is empty.");

$total = 0;
foreach ($items as $i) $total += $i['price_per_bag'] * $i['quantity'];

// CREATE ORDER
$order = $pdo->prepare("INSERT INTO orders (buyer_id, total_amount) VALUES (?, ?)");
$order->execute([$buyer_id, $total]);
$order_id = $pdo->lastInsertId();

// ORDER ITEMS
foreach ($items as $i) {
    $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price_per_bag)
        VALUES (?, ?, ?, ?)
    ")->execute([$order_id, $i['product_id'], $i['quantity'], $i['price_per_bag']]);
}

$pdo->prepare("DELETE FROM cart WHERE user_id=?")->execute([$buyer_id]);

// EMAIL NOTIFICATION
require_once "../src/email.php";

foreach ($order_items as $item) {
    $farmer_email = $item['farmer_email'];
    $farmer_name = $item['farmer_name'];
    $product_name = $item['product_name'];

    mail(
        $farmer_email,
        "Your produce has been purchased!",
        "Hello $farmer_name,\nA buyer has purchased your product: $product_name."
    );
}


mail(
    $buyer_email,
    "Your AgroLoan Order Receipt",
    "Thank you! Your order (ID: $order_id) totaling GH₵$total has been received."
);

?>
