<?php
require_once __DIR__ . '/../src/db.php';
$order_id = intval($_GET['order_id'] ?? 0);
$pdo = getPDO();
$order = null;
if ($order_id) {
    $stmt = $pdo->prepare("SELECT o.*, b.name as buyer_name FROM orders o JOIN buyers b on o.buyer_id=b.id WHERE o.id=?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Order Placed</title></head><body>
<?php if($order): ?>
  <h2>Thank you, <?=htmlspecialchars($order['buyer_name'])?></h2>
  <p>Your order #<?=intval($order['id'])?> has been placed.</p>
  <p>Total: GHS <?=number_format($order['total_amount'],2)?></p>
  <a href="shop.php">Back to shop</a>
<?php else: ?>
  <p>Order not found.</p>
<?php endif; ?>
</body></html>
