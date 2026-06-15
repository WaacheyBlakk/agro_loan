<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: buyers_login.php'); exit; }

$pdo       = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;
define('PLATFORM_FEE_PERCENT', 2.5); // 2.5% platform fee

// Fetch cart items
$sql = "
    SELECT c.id AS cart_id, c.product_id, c.quantity,
           p.produce_name AS name, p.photo AS image, p.price_per_bag,
           p.bags_available, p.description,
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
$subtotal = 0;
foreach($items as $item) { $subtotal += $item['price_per_bag'] * $item['quantity']; }
$platform_fee = $subtotal * (PLATFORM_FEE_PERCENT / 100);
$total        = $subtotal + $platform_fee;

// Cart count
$cart_count = array_sum(array_column($items, 'quantity'));

$page_title = 'My Cart | AgroMarket';
$active_nav = 'cart';
include 'nav.php';
?>

<div class="pt-24 pb-12 min-h-screen px-4 md:px-8 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-[var(--text-main)] mb-6 flex items-center gap-2">
        <i class="ri-shopping-bag-3-line text-[var(--primary)]"></i>
        My Cart
        <span class="text-base font-normal text-[var(--text-muted)] ml-1">(<?= $cart_count ?> item<?= $cart_count!=1?'s':'' ?>)</span>
    </h1>

    <?php if(empty($items)): ?>
    <div class="flex flex-col items-center justify-center bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl py-20 px-6 text-center shadow-sm">
        <div class="w-24 h-24 bg-green-50 rounded-full flex items-center justify-center mb-5">
            <i class="ri-shopping-cart-2-line text-5xl text-[var(--primary)]"></i>
        </div>
        <h2 class="text-xl font-bold text-[var(--text-main)] mb-2">Your cart is empty</h2>
        <p class="text-[var(--text-muted)] mb-6 max-w-sm">Discover fresh produce from farmers across Ghana and add them to your cart.</p>
        <a href="shop.php" class="bg-[var(--primary)] text-white px-8 py-3 rounded-full font-bold hover:bg-[var(--primary-dark)] transition shadow-md">
            Shop Now
        </a>
    </div>

    <?php else: ?>
    <div class="flex flex-col lg:flex-row gap-6">

        <!-- Cart Items -->
        <div class="flex-grow space-y-3" id="cartItemsContainer">
            <?php foreach($items as $item): ?>
            <?php
                $imgSrc  = !empty($item['image']) ? "../uploads/produce/".htmlspecialchars($item['image']) : "https://via.placeholder.com/150?text=No+Image";
                $inStock = $item['bags_available'] > 0;
                $maxQty  = min($item['bags_available'], 100);
            ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-xl p-4 flex gap-4 shadow-sm" id="cart-row-<?= $item['produce_id'] ?>">
                <!-- Image -->
                <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="flex-shrink-0 w-24 h-24 rounded-lg overflow-hidden bg-gray-50">
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-full object-contain p-2">
                </a>

                <!-- Details -->
                <div class="flex-grow min-w-0">
                    <p class="text-xs text-[var(--text-muted)] mb-0.5">Sold by <span class="font-medium"><?= htmlspecialchars($item['farmer_name']) ?></span></p>
                    <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="text-sm font-semibold text-[var(--text-main)] hover:text-[var(--primary)] line-clamp-2">
                        <?= htmlspecialchars($item['name']) ?>
                    </a>
                    <div class="text-sm font-bold text-[var(--text-main)] mt-1">₵ <?= number_format($item['price_per_bag'],2) ?>/bag</div>

                    <?php if(!$inStock): ?>
                    <span class="inline-block mt-1 text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded font-medium">Out of Stock</span>
                    <?php endif; ?>
                </div>

                <!-- Qty + Subtotal + Remove -->
                <div class="flex flex-col items-end justify-between flex-shrink-0">
                    <button onclick="removeItem(<?= $item['produce_id'] ?>)"
                        class="text-[var(--text-muted)] hover:text-red-500 transition text-lg">
                        <i class="ri-delete-bin-5-line"></i>
                    </button>

                    <div>
                        <!-- Subtotal -->
                        <div class="text-base font-bold text-[var(--text-main)] text-right mb-2" id="subtotal-<?= $item['produce_id'] ?>">
                            ₵ <?= number_format($item['price_per_bag'] * $item['quantity'], 2) ?>
                        </div>

                        <!-- Qty controls -->
                        <div class="flex items-center gap-2 border border-[var(--border)] rounded-lg overflow-hidden">
                            <button onclick="changeQty(<?= $item['produce_id'] ?>, -1, <?= $item['price_per_bag'] ?>)"
                                class="px-3 py-2 text-[var(--text-muted)] hover:bg-[var(--bg-body)] hover:text-[var(--primary)] transition font-bold">−</button>
                            <span id="qty-<?= $item['produce_id'] ?>" class="w-8 text-center text-sm font-bold text-[var(--text-main)]">
                                <?= $item['quantity'] ?>
                            </span>
                            <button onclick="changeQty(<?= $item['produce_id'] ?>, 1, <?= $item['price_per_bag'] ?>, <?= $maxQty ?>)"
                                class="px-3 py-2 text-[var(--text-muted)] hover:bg-[var(--bg-body)] hover:text-[var(--primary)] transition font-bold">+</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Order Summary -->
        <div class="w-full lg:w-80 flex-shrink-0">
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-xl p-5 shadow-sm sticky top-24">
                <h2 class="text-base font-bold text-[var(--text-main)] mb-4 pb-3 border-b border-[var(--border)]">
                    Order Summary
                </h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between text-[var(--text-muted)]">
                        <span>Subtotal</span>
                        <span id="summarySubtotal">₵ <?= number_format($subtotal,2) ?></span>
                    </div>
                    <div class="flex justify-between text-[var(--text-muted)]">
                        <span>Platform Fee (<?= PLATFORM_FEE_PERCENT ?>%)</span>
                        <span id="summaryFee">₵ <?= number_format($platform_fee,2) ?></span>
                    </div>
                    <div class="flex justify-between text-[var(--text-muted)] text-xs italic">
                        <span>Delivery</span>
                        <span class="text-green-600 font-semibold">Negotiated with Seller</span>
                    </div>
                    <div class="border-t border-[var(--border)] pt-3 flex justify-between font-bold text-base text-[var(--text-main)]">
                        <span>Total</span>
                        <span id="summaryTotal">₵ <?= number_format($total,2) ?></span>
                    </div>
                </div>

                <!-- Escrow notice -->
                <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                    <div class="flex gap-2 items-start">
                        <i class="ri-shield-check-line text-blue-600 text-lg flex-shrink-0 mt-0.5"></i>
                        <p class="text-xs text-blue-700 dark:text-blue-300 font-medium">
                            Your payment is held in escrow and only released to the farmer after you confirm delivery.
                        </p>
                    </div>
                </div>

                <a href="checkout.php"
                    class="mt-5 block w-full bg-[var(--primary)] text-white text-center font-bold py-3.5 rounded-xl hover:bg-[var(--primary-dark)] transition shadow-md text-sm uppercase tracking-wide">
                    <i class="ri-secure-payment-line mr-1"></i> Proceed to Checkout
                </a>

                <a href="shop.php" class="mt-3 block text-center text-sm text-[var(--text-muted)] hover:text-[var(--primary)] font-medium">
                    ← Continue Shopping
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Mobile Bottom Nav -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bottom-nav z-50 flex justify-between items-center px-6 py-2 text-[10px] font-medium text-[var(--text-muted)]">
    <a href="index.php"    class="flex flex-col items-center gap-1 hover:text-[var(--primary)]"><i class="ri-home-4-line text-xl"></i>Home</a>
    <a href="shop.php"     class="flex flex-col items-center gap-1 hover:text-[var(--primary)]"><i class="ri-store-2-line text-xl"></i>Shop</a>
    <a href="wishlist.php" class="flex flex-col items-center gap-1 hover:text-[var(--primary)]"><i class="ri-heart-3-line text-xl"></i>Wishlist</a>
    <a href="cart.php"     class="flex flex-col items-center gap-1 text-[var(--primary)]"><i class="ri-shopping-cart-2-fill text-xl"></i>Cart</a>
</nav>

<script>
const PLATFORM_FEE_PCT = <?= PLATFORM_FEE_PERCENT ?> / 100;

async function changeQty(productId, delta, unitPrice, maxQty) {
    const qtyEl   = document.getElementById('qty-' + productId);
    const subEl   = document.getElementById('subtotal-' + productId);
    let   current = parseInt(qtyEl.textContent);
    let   newQty  = current + delta;

    if (newQty < 1) { removeItem(productId); return; }
    if (maxQty && newQty > maxQty) { showToast('Maximum available: ' + maxQty, 'error'); return; }

    const form = new FormData();
    form.append('product_id', productId);
    form.append('quantity', newQty);

    try {
        const res  = await fetch('cart_update.php', { method:'POST', body:form });
        const data = await res.json();

        if (data.success) {
            qtyEl.textContent = newQty;
            subEl.textContent = '₵ ' + (unitPrice * newQty).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            updateSummary(data.cart_total);
        } else {
            showToast(data.message, 'error');
        }
    } catch(e) { showToast('Update failed', 'error'); }
}

async function removeItem(productId) {
    const row  = document.getElementById('cart-row-' + productId);
    row.style.transition = 'all .3s';
    row.style.opacity    = '0';
    row.style.transform  = 'translateX(20px)';

    const form = new FormData();
    form.append('product_id', productId);

    try {
        const res  = await fetch('cart_remove.php', { method:'POST', body:form });
        const data = await res.json();
        setTimeout(() => { row.remove(); }, 300);
        updateSummary(data.cart_total);
        showToast('Item removed', 'success');
        if(data.cart_count === 0) setTimeout(()=>location.reload(), 800);
    } catch(e) { showToast('Error removing item', 'error'); }
}

function updateSummary(cartTotalStr) {
    const total    = parseFloat(cartTotalStr.replace(/,/g,''));
    const fee      = total * PLATFORM_FEE_PCT;
    const subtotal = total;
    const grandTotal = total + fee;

    const fmt = n => '₵ ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('summarySubtotal').textContent = fmt(subtotal);
    document.getElementById('summaryFee').textContent      = fmt(fee);
    document.getElementById('summaryTotal').textContent    = fmt(grandTotal);
}
</script>
</body>
</html>
