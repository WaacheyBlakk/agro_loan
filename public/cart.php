<?php
session_start();
require_once __DIR__ . '/../src/db.php';
$pdo = getPDO();

// 1. AUTH CHECK
// Using 'id' or 'user_id' based on previous context. 
$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? null;

if (!$user_id) {
    header('Location: buyers_login.php');
    exit;
}

// 2. HANDLE UPDATES (Quantity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    if (isset($_POST['qty']) && is_array($_POST['qty'])) {
        foreach ($_POST['qty'] as $cart_id => $q) {
            $q = max(1, intval($q));
            
            // Verify item belongs to user
            $stmt = $pdo->prepare("SELECT produce_id FROM cart_items WHERE id=? AND buyer_id=?");
            $stmt->execute([$cart_id, $user_id]);
            $cid = $stmt->fetchColumn();
            
            if ($cid) {
                // Check stock availability
                $stockStmt = $pdo->prepare("SELECT bags_available FROM produce_listings WHERE id = ?");
                $stockStmt->execute([$cid]);
                $stock = $stockStmt->fetchColumn();
                
                // Ensure quantity doesn't exceed stock
                $finalQty = min($q, $stock);

                $s = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ? AND buyer_id = ?");
                $s->execute([$finalQty, $cart_id, $user_id]);
            }
        }
        $_SESSION['msg'] = "Cart updated successfully.";
        header('Location: cart.php'); 
        exit;
    }
}

// 3. HANDLE REMOVE
if (isset($_GET['remove'])) {
    $id = intval($_GET['remove']);
    $d = $pdo->prepare("DELETE FROM cart_items WHERE id=? AND buyer_id=?");
    $d->execute([$id, $user_id]);
    $_SESSION['msg'] = "Item removed from cart.";
    header('Location: cart.php'); 
    exit;
}

// 4. FETCH CART ITEMS
// Added p.photo to the query for images
$stmt = $pdo->prepare("
    SELECT ci.id AS cart_id, ci.quantity, p.id AS produce_id, p.produce_name, p.photo, p.price_per_bag, p.bags_available
    FROM cart_items ci 
    JOIN produce_listings p ON ci.produce_id = p.id
    WHERE ci.buyer_id = ?
");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. CALCULATE TOTALS
$subtotal = 0;
$total_items_count = 0;
foreach($items as $it) {
    $subtotal += $it['quantity'] * $it['price_per_bag'];
    $total_items_count += $it['quantity'];
}

// Message handling
$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Cart | Agro Market</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' },
                        jumia: { orange: '#f68b1e', blue: '#264996' } 
                    },
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] }
                }
            }
        }
    </script>

    <style>
    /* Global Styles & Variables */
    :root {
        --primary: #15803d;       
        --bg-body: #f8fafc;       
        --bg-card: #ffffff;
        --text-main: #1e293b;     
        --text-muted: #64748b;    
        --border: #e2e8f0;
        --glass: rgba(255, 255, 255, 0.95); 
    }
    
    body.dark {
        --bg-body: #0f172a;       
        --bg-card: #1e293b;       
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border: #334155;
        --glass: rgba(15, 23, 42, 0.95);
    }

    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-main); }
    
    /* Header */
    header {
        position: fixed; top: 0; width: 100%; background: var(--glass);
        backdrop-filter: blur(12px); border-bottom: 1px solid var(--border);
        z-index: 1000; padding: 15px 20px;
    }
    
    /* Components */
    .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; }
    .btn-primary { background: var(--primary); color: white; transition: 0.3s; }
    .btn-primary:hover { opacity: 0.9; }
    .quantity-input { border: 1px solid var(--border); background: var(--bg-body); color: var(--text-main); }
    </style>
</head>
<body class="flex flex-col min-h-screen pt-24 pb-12">

    <!-- HEADER (Consistent with Shop) -->
    <header class="flex justify-between items-center">
        <a href="index.php" class="flex items-center gap-2 text-[var(--primary)] no-underline">
            <img src="../assets/images/logo.jpg" alt="Logo" class="w-10 h-10 rounded-lg object-cover" onerror="this.style.display='none'">
            <h1 class="text-xl font-extrabold tracking-tight">AgroMarket</h1>
        </a>
        
        <div class="flex items-center gap-6">
            <nav class="hidden md:flex items-center gap-6 font-semibold text-sm">
                <a href="index.php" class="hover:text-agro-600 transition">Home</a>
                <a href="shop.php" class="hover:text-agro-600 transition">Shop</a>
                <a href="buyer_dashboard.php" class="hover:text-agro-600 transition">Dashboard</a>
            </nav>
            
            <a href="cart.php" class="relative text-[var(--text-main)]">
                <i class="ri-shopping-cart-2-fill text-xl"></i>
                <?php if($total_items_count > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-jumia-orange text-white text-[10px] font-bold h-4 w-4 rounded-full flex items-center justify-center"><?= $total_items_count ?></span>
                <?php endif; ?>
            </a>
            
            <button class="md:hidden text-2xl" id="mobileToggle"><i class="ri-menu-3-line"></i></button>
        </div>
    </header>

    <!-- MOBILE MENU OVERLAY -->
    <div id="mobileMenu" class="fixed inset-0 bg-black/50 z-[1001] hidden transition-opacity">
        <div class="absolute right-0 top-0 h-full w-3/4 max-w-xs bg-[var(--bg-card)] p-6 shadow-2xl">
            <div class="flex justify-between items-center mb-8">
                <h2 class="text-xl font-bold">Menu</h2>
                <button id="closeMenu" class="text-2xl"><i class="ri-close-line"></i></button>
            </div>
            <nav class="flex flex-col gap-4 font-medium">
                <a href="index.php">Home</a>
                <a href="shop.php">Shop</a>
                <a href="buyer_dashboard.php">Dashboard</a>
                <a href="logout.php" class="text-red-500">Logout</a>
            </nav>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
        
        <h1 class="text-2xl md:text-3xl font-bold mb-6">Shopping Cart</h1>

        <?php if (!empty($msg)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg flex items-center gap-2">
                <i class="ri-checkbox-circle-fill text-lg"></i> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if(empty($items)): ?>
            <!-- EMPTY STATE -->
            <div class="card p-12 text-center flex flex-col items-center justify-center">
                <div class="w-24 h-24 bg-[var(--bg-body)] rounded-full flex items-center justify-center mb-6">
                    <i class="ri-shopping-basket-2-line text-5xl text-[var(--text-muted)]"></i>
                </div>
                <h2 class="text-xl font-bold mb-2">Your cart is empty</h2>
                <p class="text-[var(--text-muted)] mb-8">Looks like you haven't added any produce yet.</p>
                <a href="shop.php" class="btn-primary px-8 py-3 rounded-full font-bold shadow-lg shadow-green-200">Start Shopping</a>
            </div>
        <?php else: ?>
            
            <form method="POST" action="">
                <div class="flex flex-col lg:flex-row gap-8">
                    
                    <!-- CART ITEMS LIST (Left Column) -->
                    <div class="flex-grow space-y-4">
                        <div class="card overflow-hidden">
                            <div class="p-4 bg-[var(--bg-body)] border-b border-[var(--border)] font-semibold text-sm text-[var(--text-muted)] hidden md:grid grid-cols-12 gap-4">
                                <div class="col-span-6">Product</div>
                                <div class="col-span-2 text-center">Price</div>
                                <div class="col-span-2 text-center">Quantity</div>
                                <div class="col-span-2 text-right">Subtotal</div>
                            </div>

                            <div class="divide-y divide-[var(--border)]">
                                <?php foreach($items as $it): ?>
                                    <?php 
                                        $imgSrc = !empty($it['photo']) ? "../uploads/produce/" . htmlspecialchars($it['photo']) : "https://via.placeholder.com/100?text=No+Img";
                                        $itemTotal = $it['quantity'] * $it['price_per_bag'];
                                    ?>
                                    <div class="p-4 grid grid-cols-1 md:grid-cols-12 gap-4 items-center group">
                                        
                                        <!-- Product Info -->
                                        <div class="col-span-12 md:col-span-6 flex gap-4">
                                            <a href="product_details.php?id=<?= $it['produce_id'] ?>" class="shrink-0">
                                                <img src="<?= $imgSrc ?>" class="w-20 h-20 object-cover rounded-md border border-[var(--border)]" alt="<?= htmlspecialchars($it['produce_name']) ?>">
                                            </a>
                                            <div>
                                                <h3 class="font-bold text-[var(--text-main)] hover:text-agro-600 transition">
                                                    <a href="product_details.php?id=<?= $it['produce_id'] ?>"><?= htmlspecialchars($it['produce_name']) ?></a>
                                                </h3>
                                                <div class="text-xs text-[var(--text-muted)] mt-1">Available: <?= $it['bags_available'] ?> bags</div>
                                                <a href="cart.php?remove=<?= $it['cart_id'] ?>" onclick="return confirm('Are you sure you want to remove this item?')" class="text-red-500 text-xs font-semibold hover:underline mt-2 inline-block md:hidden">
                                                    <i class="ri-delete-bin-line"></i> Remove
                                                </a>
                                            </div>
                                        </div>

                                        <!-- Price -->
                                        <div class="col-span-4 md:col-span-2 md:text-center text-sm font-medium">
                                            <span class="md:hidden text-[var(--text-muted)]">Price: </span>
                                            ₵ <?= number_format($it['price_per_bag'], 2) ?>
                                        </div>

                                        <!-- Quantity -->
                                        <div class="col-span-4 md:col-span-2 flex justify-center">
                                            <input type="number" 
                                                   name="qty[<?= intval($it['cart_id']) ?>]" 
                                                   value="<?= intval($it['quantity']) ?>" 
                                                   min="1" 
                                                   max="<?= intval($it['bags_available']) ?>"
                                                   class="quantity-input w-16 py-1 px-2 text-center rounded focus:ring-2 focus:ring-agro-500 outline-none text-sm">
                                        </div>

                                        <!-- Subtotal & Remove -->
                                        <div class="col-span-4 md:col-span-2 text-right">
                                            <div class="font-bold text-agro-700">
                                                ₵ <?= number_format($itemTotal, 2) ?>
                                            </div>
                                            <a href="cart.php?remove=<?= $it['cart_id'] ?>" onclick="return confirm('Remove this item?')" class="hidden md:inline-block text-[var(--text-muted)] hover:text-red-500 transition mt-1" title="Remove Item">
                                                <i class="ri-close-circle-line text-xl"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Update Button Row -->
                        <div class="flex justify-between items-center pt-2">
                            <a href="shop.php" class="text-sm font-semibold text-agro-600 hover:text-agro-700 flex items-center gap-1">
                                <i class="ri-arrow-left-line"></i> Continue Shopping
                            </a>
                            <button type="submit" name="update" class="bg-[var(--bg-body)] border border-[var(--border)] text-[var(--text-main)] px-4 py-2 rounded-lg text-sm font-semibold hover:bg-gray-100 transition shadow-sm">
                                <i class="ri-refresh-line"></i> Update Cart
                            </button>
                        </div>
                    </div>

                    <!-- ORDER SUMMARY (Right Column) -->
                    <div class="w-full lg:w-96 shrink-0">
                        <div class="card p-6 sticky top-28">
                            <h3 class="text-lg font-bold border-b border-[var(--border)] pb-4 mb-4">Order Summary</h3>
                            
                            <div class="space-y-3 text-sm mb-6">
                                <div class="flex justify-between text-[var(--text-muted)]">
                                    <span>Subtotal</span>
                                    <span>₵ <?= number_format($subtotal, 2) ?></span>
                                </div>
                                <div class="flex justify-between text-[var(--text-muted)]">
                                    <span>Tax estimate (0%)</span>
                                    <span>₵ 0.00</span>
                                </div>
                                <div class="border-t border-[var(--border)] pt-3 flex justify-between font-bold text-lg text-[var(--text-main)]">
                                    <span>Total</span>
                                    <span>₵ <?= number_format($subtotal, 2) ?></span>
                                </div>
                            </div>

                            <a href="checkout.php" class="block w-full btn-primary text-center py-3.5 rounded-lg font-bold shadow-lg shadow-green-200 hover:shadow-green-300 transform active:scale-[0.98] transition">
                                Proceed to Checkout
                            </a>
                            
                            <div class="mt-4 flex items-center justify-center gap-2 text-[var(--text-muted)] text-xs">
                                <i class="ri-shield-check-line"></i> Secure Checkout
                            </div>
                        </div>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Mobile Menu Logic
        const mobileBtn = document.getElementById('mobileToggle');
        const closeBtn = document.getElementById('closeMenu');
        const menu = document.getElementById('mobileMenu');

        function toggleMenu() {
            if (menu.classList.contains('hidden')) {
                menu.classList.remove('hidden');
            } else {
                menu.classList.add('hidden');
            }
        }

        mobileBtn.addEventListener('click', toggleMenu);
        closeBtn.addEventListener('click', toggleMenu);

        // Auto-check Dark Mode
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark');
        }
    </script>
</body>
</html>