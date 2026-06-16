<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: buyers_login.php'); exit; }

$pdo      = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;

// Cart count for badge
$cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
$cStmt->execute([$user_id]);
$cart_count = (int)$cStmt->fetchColumn();

// Dynamic schema detection
$wishlistTable = 'wishlist';
$wishlistColumn = 'produce_id';

try {
    $pdo->query("SELECT 1 FROM wishlist_items LIMIT 1");
    $wishlistTable = 'wishlist_items';
} catch (PDOException $e) {
    $wishlistTable = 'wishlist';
}

try {
    $pdo->query("SELECT product_id FROM {$wishlistTable} LIMIT 1");
    $wishlistColumn = 'product_id';
} catch (PDOException $e) {
    $wishlistColumn = 'produce_id';
}

// Fetch wishlist items matching correct detected DB schema
$sql = "
    SELECT w.id AS wish_id, w.{$wishlistColumn} AS produce_id, w.created_at AS wishlisted_at,
           p.produce_name AS name, p.photo AS image, p.price_per_bag,
           p.bags_available, p.description,
           u.name AS farmer_name, c.name AS category_name
    FROM {$wishlistTable} w
    JOIN produce_listings p ON w.{$wishlistColumn} = p.id
    JOIN users u ON p.farmer_id = u.id
    JOIN categories c ON p.category_id = c.id
    WHERE w.user_id = ?
    ORDER BY w.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'My Wishlist | AgroMarket';
$active_nav = 'wishlist';
include 'nav.php';
?>

<div class="pt-24 pb-10 min-h-screen px-4 md:px-8 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-[var(--text-main)]">
                <i class="ri-heart-3-fill text-red-500 mr-2"></i>My Wishlist
            </h1>
            <p class="text-[var(--text-muted)] text-sm mt-1"><?= count($items) ?> item<?= count($items)!=1?'s':'' ?> saved</p>
        </div>
        <?php if(count($items) > 0): ?>
        <a href="shop.php" class="text-sm text-[var(--primary)] font-semibold hover:underline flex items-center gap-1">
            <i class="ri-store-2-line"></i> Continue Shopping
        </a>
        <?php endif; ?>
    </div>

    <?php if(empty($items)): ?>
    <div class="flex flex-col items-center justify-center bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl py-20 px-6 text-center shadow-sm">
        <div class="w-24 h-24 bg-red-50 dark:bg-red-900/20 rounded-full flex items-center justify-center mb-5">
            <i class="ri-heart-line text-5xl text-red-400"></i>
        </div>
        <h2 class="text-xl font-bold text-[var(--text-main)] mb-2">Your wishlist is empty</h2>
        <p class="text-[var(--text-muted)] mb-6 max-w-sm">Browse the marketplace and save items you love. They'll appear here for easy access.</p>
        <a href="shop.php" class="bg-[var(--primary)] text-white px-8 py-3 rounded-full font-bold hover:bg-[var(--primary-dark)] transition shadow-md">
            Explore Products
        </a>
    </div>

    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
        <?php foreach($items as $item): ?>
        <?php
            $imgSrc  = !empty($item['image']) ? "../uploads/produce/".htmlspecialchars($item['image']) : "https://via.placeholder.com/300?text=No+Image";
            $inStock = $item['bags_available'] > 0;
        ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-xl overflow-hidden shadow-sm hover:shadow-md transition group" id="wish-item-<?= $item['produce_id'] ?>">
            
            <div class="relative">
                <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="block aspect-square overflow-hidden bg-gray-50">
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-full object-contain p-3 hover:scale-105 transition duration-500">
                    <?php if(!$inStock): ?>
                    <div class="absolute inset-0 bg-white/70 flex items-center justify-center">
                        <span class="bg-gray-700 text-white text-xs px-2 py-1 rounded">Out of Stock</span>
                    </div>
                    <?php endif; ?>
                </a>
                <button onclick="removeFromWishlist(<?= $item['produce_id'] ?>)"
                    class="absolute top-2 right-2 w-8 h-8 bg-white rounded-full flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 shadow transition">
                    <i class="ri-heart-fill text-sm"></i>
                </button>
            </div>

            <div class="p-3">
                <p class="text-xs text-[var(--text-muted)] mb-0.5">By <?= htmlspecialchars($item['farmer_name']) ?></p>
                <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="text-sm font-semibold text-[var(--text-main)] line-clamp-2 hover:text-[var(--primary)]">
                    <?= htmlspecialchars($item['name']) ?>
                </a>
                <div class="text-base font-bold text-[var(--text-main)] mt-1">
                    ₵ <?= number_format($item['price_per_bag'],2) ?>
                    <span class="text-xs text-[var(--text-muted)] font-normal">/bag</span>
                </div>

                <div class="mt-3 space-y-2">
                    <?php if($inStock): ?>
                    <button onclick="moveToCart(<?= $item['produce_id'] ?>, this)"
                        class="w-full bg-[var(--primary)] text-white text-xs font-bold py-2 rounded-lg hover:bg-[var(--primary-dark)] transition active:scale-95">
                        <i class="ri-shopping-cart-add-line"></i> Add to Cart
                    </button>
                    <?php else: ?>
                    <button disabled class="w-full bg-gray-200 text-gray-400 text-xs font-bold py-2 rounded-lg cursor-not-allowed">
                        Out of Stock
                    </button>
                    <?php endif; ?>
                    <button onclick="removeFromWishlist(<?= $item['produce_id'] ?>)"
                        class="w-full border border-[var(--border)] text-[var(--text-muted)] text-xs font-semibold py-1.5 rounded-lg hover:border-red-300 hover:text-red-500 transition">
                        Remove
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Mobile Bottom Nav -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bottom-nav z-50 flex justify-between items-center px-6 py-2 text-[10px] font-medium text-[var(--text-muted)]">
    <a href="index.php" class="flex flex-col items-center gap-1 hover:text-[var(--primary)]"><i class="ri-home-4-line text-xl"></i>Home</a>
    <a href="shop.php"  class="flex flex-col items-center gap-1 hover:text-[var(--primary)]"><i class="ri-store-2-line text-xl"></i>Shop</a>
    <a href="wishlist.php" class="flex flex-col items-center gap-1 text-[var(--primary)]"><i class="ri-heart-3-fill text-xl"></i>Wishlist</a>
    <a href="cart.php"  class="flex flex-col items-center gap-1 hover:text-[var(--primary)] relative">
        <i class="ri-shopping-cart-2-line text-xl"></i>
        <?php if($cart_count>0): ?><span class="absolute top-0 right-2 bg-orange-500 w-2 h-2 rounded-full"></span><?php endif; ?>
        Cart
    </a>
</nav>

<script>
async function removeFromWishlist(productId) {
    const card = document.getElementById('wish-item-' + productId);
    card.style.opacity = '0.4';
    card.style.pointerEvents = 'none';

    const form = new FormData();
    form.append('product_id', productId);

    try {
        const res = await fetch('wishlist_remove.php', { method:'POST', body:form });
        const data = await res.json();
        
        if (data.success) {
            card.style.transition = 'all .4s';
            card.style.transform  = 'scale(0.85)';
            card.style.opacity    = '0';
            setTimeout(() => { card.remove(); updateCount(); }, 400);
            showToast('Removed from wishlist', 'success');
        } else {
            card.style.opacity = '1';
            card.style.pointerEvents = '';
            showToast(data.message || 'Error removing item', 'error');
        }
    } catch(e) {
        card.style.opacity = '1';
        card.style.pointerEvents = '';
        showToast('Connection error', 'error');
    }
}

async function moveToCart(productId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i>';

    const form = new FormData();
    form.append('product_id', productId);

    try {
        const res  = await fetch('cart_add.php', { method:'POST', body:form });
        const data = await res.json();
        if (data.success) {
            showToast('Added to cart!', 'success');
            btn.innerHTML = '<i class="ri-check-line"></i> In Cart';
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            showToast(data.message||'Could not add to cart', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-shopping-cart-add-line"></i> Add to Cart';
        }
    } catch(e) {
        showToast('Something went wrong', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="ri-shopping-cart-add-line"></i> Add to Cart';
    }
}

function updateCount(){
    const cards = document.querySelectorAll('[id^="wish-item-"]');
    const h = document.querySelector('h1 + p');
    if(h) h.textContent = cards.length + ' item' + (cards.length!==1?'s':'') + ' saved';
    if(cards.length === 0) location.reload();
}
</script>
</body>
</html>