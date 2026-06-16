<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: buyers_login.php'); exit; }

$pdo       = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;
define('PLATFORM_FEE_PERCENT', 2.5);

// Cart count for nav badge
$cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
$cStmt->execute([$user_id]);
$cart_count = (int)$cStmt->fetchColumn();

if ($cart_count === 0) { header('Location: cart.php'); exit; }

// Fetch cart items
$sql = "
    SELECT c.product_id, c.quantity,
           p.produce_name AS name, p.photo AS image, p.price_per_bag, p.bags_available,
           u.name AS farmer_name, u.id AS farmer_id
    FROM cart c
    JOIN produce_listings p ON c.product_id = p.id
    JOIN users u ON p.farmer_id = u.id
    WHERE c.user_id = ?
    ORDER BY c.created_at ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute totals
$subtotal     = 0;
foreach($items as $item) { $subtotal += $item['price_per_bag'] * $item['quantity']; }
$platform_fee = round($subtotal * (PLATFORM_FEE_PERCENT / 100), 2);
$total        = $subtotal + $platform_fee;

// Prefill buyer info from buyers table with a fallback for the location column
$buyer = [];
try {
    $userStmt = $pdo->prepare("SELECT name, email, phone, location FROM buyers WHERE id=?");
    $userStmt->execute([$user_id]);
    $buyer = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    // Fallback if 'location' does not exist in the 'buyers' table
    $userStmt = $pdo->prepare("SELECT name, email, phone FROM buyers WHERE id=?");
    $userStmt->execute([$user_id]);
    $buyer = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $buyer['location'] = '';
}

// Flash messages
$error   = $_SESSION['checkout_error']   ?? null; unset($_SESSION['checkout_error']);
$success = $_SESSION['checkout_success'] ?? null; unset($_SESSION['checkout_success']);

$page_title = 'Checkout | AgroMarket';
$active_nav = 'cart';
include 'nav.php';
?>

<div class="pt-24 pb-12 min-h-screen px-4 md:px-8 max-w-5xl mx-auto">
    <div class="mb-6">
        <a href="cart.php" class="text-sm text-[var(--text-muted)] hover:text-[var(--primary)] flex items-center gap-1 w-fit">
            <i class="ri-arrow-left-line"></i> Back to Cart
        </a>
        <h1 class="text-2xl font-bold text-[var(--text-main)] mt-2">Checkout</h1>
    </div>

    <?php if($error): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm flex items-center gap-2">
        <i class="ri-error-warning-line text-xl"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Progress Steps -->
    <div class="flex items-center justify-center gap-2 mb-8">
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--primary)] text-white flex items-center justify-center text-xs font-bold">1</span>
            <span class="text-sm font-semibold text-[var(--primary)] hidden sm:block">Delivery Details</span>
        </div>
        <div class="h-px w-8 bg-[var(--border)]"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--primary)] text-white flex items-center justify-center text-xs font-bold">2</span>
            <span class="text-sm font-semibold text-[var(--primary)] hidden sm:block">Payment</span>
        </div>
        <div class="h-px w-8 bg-[var(--border)]"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--border)] text-[var(--text-muted)] flex items-center justify-center text-xs font-bold">3</span>
            <span class="text-sm font-semibold text-[var(--text-muted)] hidden sm:block">Confirmation</span>
        </div>
    </div>

    <form id="checkoutForm" method="POST" action="checkout_process.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32)) ?>">

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- Left column: forms -->
            <div class="lg:col-span-3 space-y-5">

                <!-- Delivery Details Card -->
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 shadow-sm">
                    <h2 class="text-base font-bold text-[var(--text-main)] mb-4 flex items-center gap-2">
                        <i class="ri-map-pin-line text-[var(--primary)]"></i> Delivery Details
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Full Name *</label>
                            <input type="text" name="delivery_name" required
                                value="<?= htmlspecialchars($buyer['name']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-lg px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="Your full name">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Phone Number *</label>
                            <input type="tel" name="delivery_phone" required
                                value="<?= htmlspecialchars($buyer['phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-lg px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="0XX XXX XXXX">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Delivery Address *</label>
                            <input type="text" name="delivery_address" required
                                value="<?= htmlspecialchars($buyer['location']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-lg px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="Town / District / Region">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Order Notes <span class="font-normal">(optional)</span></label>
                            <textarea name="buyer_notes" rows="2"
                                class="w-full border border-[var(--border)] rounded-lg px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent resize-none"
                                placeholder="Any special instructions for the farmer…"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Payment Card -->
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 shadow-sm">
                    <h2 class="text-base font-bold text-[var(--text-main)] mb-4 flex items-center gap-2">
                        <i class="ri-smartphone-line text-[var(--primary)]"></i> Mobile Money Payment
                    </h2>

                    <!-- Network selector -->
                    <div class="flex gap-3 mb-4">
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="MTN" checked class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-yellow-400 peer-checked:bg-yellow-50 rounded-xl p-3 text-center transition">
                                <div class="text-2xl mb-1">📱</div>
                                <div class="text-xs font-bold text-[var(--text-main)]">MTN MoMo</div>
                            </div>
                        </label>
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="Telecel" class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-red-400 peer-checked:bg-red-50 rounded-xl p-3 text-center transition">
                                <div class="text-2xl mb-1">📡</div>
                                <div class="text-xs font-bold text-[var(--text-main)]">Telecel Cash</div>
                            </div>
                        </label>
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="AirtelTigo" class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-blue-400 peer-checked:bg-blue-50 rounded-xl p-3 text-center transition">
                                <div class="text-2xl mb-1">🌐</div>
                                <div class="text-xs font-bold text-[var(--text-main)]">AT Money</div>
                            </div>
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">MoMo Number *</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] font-bold text-sm">+233</span>
                            <input type="tel" name="momo_number" required id="momoInput"
                                class="w-full border border-[var(--border)] rounded-lg pl-14 pr-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="XX XXX XXXX" pattern="[0-9]{9,10}" maxlength="10">
                        </div>
                        <p class="text-[10px] text-[var(--text-muted)] mt-1 flex items-center gap-1">
                            <i class="ri-lock-line"></i> You'll receive a push prompt on this number to approve the payment.
                        </p>
                    </div>
                </div>

            </div>

            <!-- Right column: order summary -->
            <div class="lg:col-span-2">
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 shadow-sm sticky top-24">
                    <h2 class="text-base font-bold text-[var(--text-main)] mb-4 pb-3 border-b border-[var(--border)]">
                        Order Summary
                    </h2>

                    <!-- Items list -->
                    <div class="space-y-3 mb-4 max-h-52 overflow-y-auto pr-1">
                        <?php foreach($items as $item): ?>
                        <?php $imgSrc = !empty($item['image']) ? "../uploads/produce/".htmlspecialchars($item['image']) : "https://via.placeholder.com/60?text=?"; ?>
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-lg overflow-hidden bg-gray-50 flex-shrink-0">
                                <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-full object-contain p-1">
                            </div>
                            <div class="flex-grow min-w-0">
                                <p class="text-xs font-semibold text-[var(--text-main)] line-clamp-1"><?= htmlspecialchars($item['name']) ?></p>
                                <p class="text-xs text-[var(--text-muted)]">Qty: <?= $item['quantity'] ?> × ₵<?= number_format($item['price_per_bag'],2) ?></p>
                            </div>
                            <div class="text-xs font-bold text-[var(--text-main)] flex-shrink-0">
                                ₵ <?= number_format($item['price_per_bag']*$item['quantity'],2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Totals -->
                    <div class="space-y-2 text-sm border-t border-[var(--border)] pt-3">
                        <div class="flex justify-between text-[var(--text-muted)]">
                            <span>Subtotal</span><span>₵ <?= number_format($subtotal,2) ?></span>
                        </div>
                        <div class="flex justify-between text-[var(--text-muted)]">
                            <span>Platform Fee (<?= PLATFORM_FEE_PERCENT ?>%)</span>
                            <span>₵ <?= number_format($platform_fee,2) ?></span>
                        </div>
                        <div class="flex justify-between font-bold text-base text-[var(--text-main)] pt-2 border-t border-[var(--border)]">
                            <span>Total</span>
                            <span class="text-[var(--primary)]">₵ <?= number_format($total,2) ?></span>
                        </div>
                    </div>

                    <!-- Escrow badge -->
                    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl flex gap-2">
                        <i class="ri-shield-check-fill text-blue-600 text-xl flex-shrink-0"></i>
                        <p class="text-xs text-blue-700 dark:text-blue-300 font-medium">
                            Escrow Protected — Payment held securely until you confirm delivery.
                        </p>
                    </div>

                    <!-- Submit -->
                    <button type="submit" id="payBtn"
                        class="mt-5 w-full bg-[var(--primary)] text-white font-bold py-4 rounded-xl hover:bg-[var(--primary-dark)] transition shadow-md uppercase tracking-wide text-sm flex items-center justify-center gap-2">
                        <i class="ri-secure-payment-line text-lg"></i>
                        Pay ₵ <?= number_format($total,2) ?> Now
                    </button>
                    <p class="text-center text-[10px] text-[var(--text-muted)] mt-3 flex items-center justify-center gap-1">
                        <i class="ri-lock-fill"></i> Secured by AgroMarket Escrow
                    </p>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Payment Processing Overlay -->
<div id="paymentOverlay" class="fixed inset-0 bg-black/60 z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-[var(--bg-card)] rounded-2xl p-8 max-w-sm w-full text-center shadow-2xl">
        <!-- Spinner -->
        <div id="paySpinner" class="w-16 h-16 border-4 border-[var(--primary-light)] border-t-[var(--primary)] rounded-full animate-spin mx-auto mb-5"></div>
        <!-- Success icon (hidden) -->
        <div id="paySuccess" class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5 hidden">
            <i class="ri-check-fill text-green-600 text-4xl"></i>
        </div>
        <!-- Fail icon (hidden) -->
        <div id="payFail" class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-5 hidden">
            <i class="ri-close-fill text-red-600 text-4xl"></i>
        </div>

        <h3 id="payTitle" class="text-lg font-bold text-[var(--text-main)] mb-2">Processing Payment…</h3>
        <p id="payMsg" class="text-sm text-[var(--text-muted)]">Please check your phone for the MoMo prompt and approve the payment.</p>

        <div id="payProgress" class="mt-4 flex justify-center gap-1.5">
            <span class="w-2 h-2 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:0s"></span>
            <span class="w-2 h-2 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:.2s"></span>
            <span class="w-2 h-2 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:.4s"></span>
        </div>
    </div>
</div>

<script>
document.getElementById('checkoutForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn  = document.getElementById('payBtn');
    const overlay = document.getElementById('paymentOverlay');

    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Initiating…';
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    const formData = new FormData(this);

    try {
        const res  = await fetch('checkout_process.php', { method:'POST', body: formData });
        const data = await res.json();

        if (data.success && data.order_id && data.reference) {
            // Start polling for payment confirmation
            pollPayment(data.order_id, data.reference);
        } else {
            showPayFail(data.message || 'Could not initiate payment. Please try again.');
        }
    } catch (err) {
        showPayFail('Connection error. Please try again.');
    }
});

function pollPayment(orderId, reference) {
    let attempts = 0;
    const maxAttempts = 24; // 2 min at 5s intervals

    const interval = setInterval(async () => {
        attempts++;
        try {
            const res  = await fetch(`payment_verify.php?order_id=${orderId}&ref=${reference}`);
            const data = await res.json();

            if (data.status === 'confirmed') {
                clearInterval(interval);
                showPaySuccess(orderId);
            } else if (data.status === 'failed' || data.status === 'cancelled') {
                clearInterval(interval);
                showPayFail('Payment was ' + data.status + '. Please try again.');
            } else if (attempts >= maxAttempts) {
                clearInterval(interval);
                showPayFail('Payment timed out. Check your order history — if payment was deducted, contact support.');
            }
        } catch(e) { /* keep polling */ }
    }, 5000);
}

function showPaySuccess(orderId) {
    document.getElementById('paySpinner').classList.add('hidden');
    document.getElementById('paySuccess').classList.remove('hidden');
    document.getElementById('payProgress').classList.add('hidden');
    document.getElementById('payTitle').textContent = 'Payment Successful! 🎉';
    document.getElementById('payMsg').textContent   = 'Your order has been placed and is now being prepared.';
    setTimeout(() => { window.location.href = `buyer_dashboard.php?tab=orders&order_id=${orderId}`; }, 2500);
}

function showPayFail(msg) {
    document.getElementById('paySpinner').classList.add('hidden');
    document.getElementById('payFail').classList.remove('hidden');
    document.getElementById('payProgress').classList.add('hidden');
    document.getElementById('payTitle').textContent = 'Payment Failed';
    document.getElementById('payMsg').textContent   = msg;

    const btn2 = document.createElement('button');
    btn2.textContent = 'Try Again';
    btn2.className   = 'mt-5 bg-[var(--primary)] text-white px-6 py-2 rounded-full font-bold text-sm';
    btn2.onclick = () => {
        document.getElementById('paymentOverlay').classList.add('hidden');
        document.getElementById('paymentOverlay').classList.remove('flex');
        const payBtn = document.getElementById('payBtn');
        payBtn.disabled = false;
        payBtn.innerHTML = '<i class="ri-secure-payment-line text-lg"></i> Pay ₵ <?= number_format($total,2) ?> Now';
    };
    document.getElementById('payMsg').after(btn2);
}
</script>
</body>
</html>