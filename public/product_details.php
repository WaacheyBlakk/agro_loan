<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: shop.php');
    exit;
}

$pdo = getPDO();

// Fetch product details along with category and farmer/user info
$sql = "
    SELECT 
        p.id, 
        p.produce_name AS name, 
        p.photo AS image, 
        p.price_per_bag, 
        p.bags_available, 
        p.description, 
        p.category_id,
        u.name AS farmer_name, 
        u.email AS farmer_email,
        c.name AS category_name,
        p.created_at
    FROM produce_listings p
    JOIN users u ON p.farmer_id = u.id
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: shop.php');
    exit;
}

$is_logged = isset($_SESSION['user_id']) || isset($_SESSION['id']);
$user_role = $_SESSION['role'] ?? null;
$imgSrc = !empty($product['image']) ? "../uploads/produce/" . htmlspecialchars($product['image']) : "https://via.placeholder.com/600?text=No+Image";
$inStock = $product['bags_available'] > 0;

// Dynamic star ratings based on product id
$stars = 3 + ($product['id'] % 3);
$reviews_count = ($product['id'] * 12) % 200;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agro Market | <?= htmlspecialchars($product['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50: '#ecfdf5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' },
                        jumia: { orange: '#f68b1e' }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="min-h-screen pb-12">

<!-- Top Navigation -->
<nav class="bg-white border-b border-gray-100 py-4 px-6 sticky top-0 z-50 shadow-sm flex items-center justify-between">
    <a href="shop.php" class="flex items-center gap-2 text-agro-700 font-bold text-lg hover:text-agro-900 transition">
        <i class="ri-arrow-left-line"></i> Back to Shop
    </a>
    <div class="flex items-center gap-4">
        <?php if ($is_logged && $user_role !== 'farmer'): ?>
            <a href="cart.php" class="flex items-center gap-1.5 text-gray-700 hover:text-agro-700 font-bold text-sm bg-gray-50 hover:bg-agro-50 px-4 py-2 rounded-full border border-gray-100 transition">
                <i class="ri-shopping-cart-2-line text-lg text-agro-600"></i>
                <span>Go to Cart</span>
            </a>
        <?php endif; ?>
    </div>
</nav>

<div class="max-w-6xl mx-auto px-4 mt-8">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden grid grid-cols-1 md:grid-cols-2 gap-8 p-6 md:p-10">
        
        <!-- Product Image Section -->
        <div class="flex flex-col justify-center bg-gray-50 rounded-xl p-6 relative">
            <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="max-h-96 w-full object-contain mix-blend-multiply rounded-lg">
            <?php if(!$inStock): ?>
                <div class="absolute inset-0 bg-white/70 flex items-center justify-center">
                    <span class="bg-red-600 text-white font-bold px-4 py-2 rounded-lg text-sm uppercase">Out of Stock</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Product Purchase / Details Area -->
        <div class="flex flex-col justify-between">
            <div>
                <span class="bg-agro-50 text-agro-700 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider">
                    <?= htmlspecialchars($product['category_name']) ?>
                </span>
                
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-800 mt-3 mb-1">
                    <?= htmlspecialchars($product['name']) ?>
                </h1>

                <p class="text-xs text-gray-400">Listed on: <?= date('F j, Y', strtotime($product['created_at'])) ?></p>

                <!-- Stars and Reviews -->
                <div class="flex items-center gap-2 mt-3 mb-5">
                    <div class="flex text-jumia-orange">
                        <?php for($i=0; $i<5; $i++): ?>
                            <i class="<?= $i < $stars ? 'ri-star-fill' : 'ri-star-line' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <span class="text-xs text-gray-500 font-semibold">(<?= $reviews_count ?> verified reviews)</span>
                </div>

                <div class="border-y border-gray-100 py-4 my-4">
                    <div class="text-sm text-gray-400">Price per Bag</div>
                    <div class="text-3xl font-extrabold text-agro-700">₵ <?= number_format($product['price_per_bag'], 2) ?></div>
                    <div class="text-xs text-gray-500 mt-1">Estimated standard fee: 1.0% platform secure payment support.</div>
                </div>

                <!-- Product Information Description -->
                <div class="mb-6">
                    <h3 class="font-bold text-gray-800 text-sm uppercase tracking-wide mb-2">Produce Details</h3>
                    <p class="text-gray-600 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                </div>

                <!-- Farmer Info Banner -->
                <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 bg-agro-100 rounded-full flex items-center justify-center text-agro-700 font-bold">
                        <?= strtoupper(substr($product['farmer_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="text-xs text-gray-400">Farmer / Merchant</div>
                        <div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($product['farmer_name']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Interaction Form -->
            <div class="space-y-4">
                <?php if ($user_role === 'farmer'): ?>
                    <a href="edit_produce.php?id=<?= $product['id'] ?>" class="block w-full bg-gray-800 text-white text-center font-bold py-3.5 rounded-xl hover:bg-black transition">
                        Edit Listing Details
                    </a>
                <?php else: ?>
                    <form id="detailsCartForm" onsubmit="handleDetailsSubmit(event)" class="space-y-4">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        
                        <?php if ($inStock): ?>
                            <!-- Quantity selector -->
                            <div class="flex items-center gap-4">
                                <span class="text-sm font-bold text-gray-700">Quantity (Bags):</span>
                                <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden">
                                    <button type="button" onclick="adjustQty(-1)" class="px-3 py-1 bg-gray-50 hover:bg-gray-100 font-extrabold text-lg text-gray-600">-</button>
                                    <input type="number" id="purchaseQuantity" name="quantity" value="1" min="1" max="<?= $product['bags_available'] ?>" class="w-12 text-center text-sm font-bold border-none focus:ring-0">
                                    <button type="button" onclick="adjustQty(1)" class="px-3 py-1 bg-gray-50 hover:bg-gray-100 font-extrabold text-lg text-gray-600">+</button>
                                </div>
                                <span class="text-xs text-gray-400 font-medium">(<?= $product['bags_available'] ?> bags available)</span>
                            </div>

                            <button type="submit" class="w-full bg-agro-600 hover:bg-agro-700 text-white font-extrabold py-3.5 rounded-xl shadow-md uppercase tracking-wide transition transform active:scale-95">
                                <i class="ri-shopping-cart-2-line"></i> Add To Cart
                            </button>
                        <?php else: ?>
                            <button type="button" disabled class="w-full bg-gray-200 text-gray-400 font-bold py-3.5 rounded-xl cursor-not-allowed uppercase">
                                Out Of Stock
                            </button>
                        <?php endif; ?>
                    </form>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button onclick="addToWishlistDetails(<?= $product['id'] ?>)" class="w-full border border-gray-300 hover:bg-gray-50 text-gray-700 font-bold py-3.5 rounded-xl transition flex items-center justify-center gap-2">
                            <i class="ri-heart-line text-red-500"></i> Save to Wishlist
                        </button>
                        
                        <a href="cart.php" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold py-3.5 rounded-xl transition flex items-center justify-center gap-2">
                            <i class="ri-shopping-bag-line"></i> View Cart
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="toastContainer" class="fixed top-4 right-4 z-[100] space-y-2 pointer-events-none"></div>

<script>
    function adjustQty(amount) {
        const qtyInput = document.getElementById('purchaseQuantity');
        if (!qtyInput) return;
        let val = parseInt(qtyInput.value) + amount;
        if (val < 1) val = 1;
        const maxVal = parseInt(qtyInput.getAttribute('max'));
        if (val > maxVal) val = maxVal;
        qtyInput.value = val;
    }

    function handleDetailsSubmit(e) {
        e.preventDefault();
        const form = document.getElementById('detailsCartForm');
        const formData = new FormData(form);

        fetch('cart_add.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Modified to include a dynamic action button inside the success message
                showToast(
                    `${data.message} <a href="cart.php" class="underline ml-2 font-extrabold hover:text-gray-100">View Cart &rarr;</a>`, 
                    'success'
                );
            } else {
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showToast(data.message, 'error');
                }
            }
        })
        .catch(() => showToast('Failed to connect to cart system.', 'error'));
    }

    function addToWishlistDetails(productId) {
        const formData = new FormData();
        formData.append('product_id', productId);

        fetch('wishlist_add.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showToast(data.message, 'error');
                }
            }
        })
        .catch(() => showToast('Error communicating with wishlist service.', 'error'));
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        const colors = type === 'success' ? 'bg-green-600' : 'bg-red-600';
        const icon = type === 'success' ? 'ri-check-line' : 'ri-error-warning-line';

        toast.className = `pointer-events-auto flex items-center gap-3 ${colors} text-white px-6 py-3 rounded-lg shadow-lg transform translate-x-full transition-all duration-300`;
        toast.innerHTML = `<i class="${icon} text-xl flex-shrink-0"></i> <span class="font-bold text-sm">${message}</span>`;
        
        container.appendChild(toast);
        requestAnimationFrame(() => { toast.classList.remove('translate-x-full'); });
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            toast.classList.add('opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 4000); // Extended toast visible time slightly to allow users to read/click the 'View Cart' link
    }
</script>
</body>
</html>