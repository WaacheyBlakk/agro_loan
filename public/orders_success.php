<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//orders_success.php
$order_id = intval($_GET['order_id'] ?? 0);
$pdo = getPDO();
$order = null;

if ($order_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT o.*, COALESCE(b.name, o.delivery_name, 'Valued Customer') as buyer_name 
            FROM orders o 
            LEFT JOIN buyers b ON o.buyer_id = b.id 
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback query
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Order Placed | AgroMarket</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 font-['Plus_Jakarta_Sans']">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl border border-gray-100 p-8 text-center">
        <?php if($order): ?>
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="ri-checkbox-circle-fill text-5xl"></i>
            </div>
            
            <h2 class="text-2xl font-extrabold text-gray-900 mb-1">Thank You!</h2>
            <p class="text-sm text-gray-600 mb-6">Dear <span class="font-bold text-gray-800"><?= htmlspecialchars($order['buyer_name'] ?? 'Customer') ?></span>, your order has been received.</p>
            
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-left space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Order Reference:</span>
                    <span class="font-bold text-gray-800">#<?= intval($order['id']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Total Amount:</span>
                    <span class="font-bold text-emerald-600">GHS <?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Status:</span>
                    <span class="capitalize font-semibold text-blue-600"><?= htmlspecialchars($order['order_status'] ?? $order['status'] ?? 'Processing') ?></span>
                </div>
            </div>

            <div class="space-y-3">
                <a href="shop.php" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 rounded-xl shadow-md transition">
                    Continue Shopping
                </a>
                <a href="buyer_dashboard.php?tab=orders" class="block w-full border border-gray-300 text-gray-700 hover:bg-gray-50 font-bold py-3.5 rounded-xl transition text-sm">
                    View My Orders
                </a>
            </div>
        <?php else: ?>
            <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="ri-error-warning-fill text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">Order Not Found</h2>
            <p class="text-sm text-gray-500 mb-6">We could not find the details for this order.</p>
            <a href="shop.php" class="inline-block bg-emerald-600 text-white font-bold px-6 py-3 rounded-xl hover:bg-emerald-700 transition">
                Return to Shop
            </a>
        <?php endif; ?>
    </div>

</body>
</html>