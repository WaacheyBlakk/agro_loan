<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//checkout.php
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['buyer_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    header('Location: login.php'); 
    exit; 
}

$pdo       = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;

define('PLATFORM_FEE_PERCENT', 1.0);

// Cart count for nav badge
$cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id = ?");
$cStmt->execute([$user_id]);
$cart_count = (int)$cStmt->fetchColumn();

if ($cart_count === 0) { 
    header('Location: cart.php'); 
    exit; 
}

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
foreach($items as $item) { 
    $subtotal += $item['price_per_bag'] * $item['quantity']; 
}
$platform_fee = round($subtotal * (PLATFORM_FEE_PERCENT / 100), 2);
$total        = $subtotal + $platform_fee;

// Prefill buyer info from buyers table with a fallback
$buyer = [];
try {
    $userStmt = $pdo->prepare("SELECT name, email, phone, location FROM buyers WHERE id = ?");
    $userStmt->execute([$user_id]);
    $buyer = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $userStmt = $pdo->prepare("SELECT name, email, phone FROM buyers WHERE id = ?");
    $userStmt->execute([$user_id]);
    $buyer = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $buyer['location'] = '';
}

$user_name = $buyer['name'] ?? ($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'User');

// Flash messages
$error   = $_SESSION['checkout_error']   ?? null; unset($_SESSION['checkout_error']);
$success = $_SESSION['checkout_success'] ?? null; unset($_SESSION['checkout_success']);

$page_title = 'Checkout | AgroMarket';
$active_nav = 'cart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50:'#f0fdf4', 100:'#dcfce7', 500:'#22c55e', 600:'#16a34a', 700:'#15803d', 900:'#14532d' }
                    },
                    fontFamily: { 
                        sans: ['Plus Jakarta Sans','sans-serif'],
                        serif: ['Merriweather', 'serif']
                    }
                }
            }
        }
    </script>
    <style>
    :root {
        /* Exact Variables from index.php */
        --primary: #15803d;       
        --primary-dark: #14532d;  
        --accent: #22c55e;        
        --accent-hover: #16a34a;
        --bg-body: #f8fafc;       
        --bg-card: #ffffff;
        --text-main: #1e293b;     
        --text-muted: #64748b;    
        --border: #e2e8f0;
        --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        --glass: rgba(255, 255, 255, 0.85);
        --primary-light: #dcfce7;
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    }

    body.dark {
        /* Exact Dark Variables from index.php */
        --primary: #22c55e;
        --primary-dark: #4ade80;
        --accent: #15803d;
        --bg-body: #0f172a;       
        --bg-card: #1e293b;       
        --text-main: #f1f5f9;
        --text-muted: #94a3b8;
        --border: #334155;
        --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
        --glass: rgba(15, 23, 42, 0.85);
        --primary-light: #14532d;
    }
    
    *, *::before, *::after {
        box-sizing: border-box;
    }

    html {
        scroll-behavior: smooth;
    }

    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    body { 
        font-family: 'Plus Jakarta Sans', sans-serif; 
        background: var(--bg-body); 
        color: var(--text-main); 
        transition: background 0.3s ease, color 0.3s ease;
        margin: 0;
        padding-top: 75px;
        padding-bottom: 80px;
        min-height: 100vh;
    }
    
    /* Header / Navbar */
    header { 
        position: fixed; 
        top: 0; 
        left: 0;
        width: 100%; 
        background: var(--glass); 
        backdrop-filter: blur(12px); 
        -webkit-backdrop-filter: blur(12px); 
        z-index: 1000; 
        border-bottom: 1px solid var(--border); 
        transition: all 0.3s ease; 
        padding: 12px 5%;
    }
    header.scrolled { 
        padding: 8px 5%;
        box-shadow: var(--shadow); 
    }

    .logo-container { 
        display: flex; 
        align-items: center; 
        gap: 10px; 
        text-decoration: none; 
        color: var(--primary-dark);
        flex-shrink: 0; 
    }
    body.dark .logo-container { color: var(--text-main); }
    .logo-container img {
        height: 38px;
        width: 38px;
        border-radius: 8px;
        object-fit: cover;
    }
    .logo-container h1 { 
        font-size: 1.4rem; 
        font-weight: 800; 
        margin: 0; 
        background: linear-gradient(135deg, var(--primary), var(--accent)); 
        -webkit-background-clip: text; 
        -webkit-text-fill-color: transparent; 
        letter-spacing: -0.5px; 
    }
    
    /* Desktop Nav */
    .desktop-nav {
        display: flex;
        align-items: center;
        gap: 25px;
    }

    .nav-link { 
        color: var(--text-main); 
        text-decoration: none; 
        font-weight: 600; 
        font-size: 0.95rem; 
        transition: color 0.3s; 
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .nav-link:hover { 
        color: var(--primary); 
    }
    .nav-link::after {
        content: '';
        position: absolute;
        width: 0;
        height: 2px;
        bottom: -4px;
        left: 0;
        background-color: var(--primary);
        transition: width 0.3s;
    }
    .nav-link:hover::after,
    .nav-link.active::after { 
        width: 100%; 
    }
    .nav-link.active { 
        color: var(--primary); 
    }
    
    .btn-login { 
        padding: 8px 20px; 
        border: 2px solid var(--primary); 
        border-radius: 50px; 
        color: var(--primary); 
        font-weight: 600; 
        transition: 0.3s; 
        text-decoration: none; 
        font-size: 0.9rem; 
        display: inline-flex; 
        align-items: center; 
        justify-content: center; 
        background: transparent;
    }
    .btn-login:hover { 
        background: var(--primary); 
        color: #ffffff !important; 
        text-decoration: none; 
        box-shadow: 0 4px 12px rgba(22, 163, 74, 0.2);
    }
    
    .theme-toggle { 
        background: var(--bg-card); 
        border: 1px solid var(--border); 
        border-radius: 50%; 
        color: var(--text-main); 
        cursor: pointer; 
        width: 40px; 
        height: 40px; 
        font-size: 1.2rem; 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        transition: 0.3s; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        flex-shrink: 0; 
    }
    .theme-toggle:hover { 
        transform: rotate(15deg) scale(1.1); 
        border-color: var(--primary); 
    }
    
    .cart-badge { 
        position: absolute; 
        top: -4px; 
        right: -4px; 
        background: #f68b1e; 
        color: #ffffff; 
        border-radius: 9999px; 
        min-width: 18px; 
        height: 18px; 
        padding: 0 4px; 
        font-size: 9px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-weight: 800; 
        box-shadow: 0 2px 4px rgba(246,139,30,0.3); 
    }

    /* Mobile Hamburger */
    .mobile-hamburger-btn {
        display: none;
        background: none;
        border: none;
        color: var(--text-main);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 4px;
        margin-left: 5px;
    }

    /* Mobile Drawer Overlay */
    .mobile-drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .mobile-drawer-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Mobile Slide-Out Drawer Panel */
    .mobile-drawer {
        position: fixed;
        top: 0;
        right: -100%;
        width: 75%;
        max-width: 320px;
        height: 100vh;
        background: var(--bg-card);
        z-index: 1001;
        box-shadow: -5px 0 25px rgba(0,0,0,0.2);
        transition: right 0.4s ease;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }
    .mobile-drawer.active {
        right: 0;
    }

    .drawer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        border-bottom: 1px solid var(--border);
    }
    .drawer-header h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--primary);
    }
    .drawer-close-btn {
        background: none;
        border: none;
        color: var(--text-main);
        font-size: 1.5rem;
        cursor: pointer;
    }

    .drawer-user-card {
        padding: 14px 20px;
        background: var(--primary-light);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .drawer-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--primary);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 16px;
        flex-shrink: 0;
    }
    .drawer-user-info { overflow: hidden; }
    .drawer-user-name {
        font-weight: 700;
        font-size: 14px;
        color: var(--text-main);
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
    }
    .drawer-user-role { font-size: 11px; color: var(--text-muted); text-transform: capitalize; }

    .drawer-menu {
        padding: 15px 20px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        flex: 1;
    }
    .drawer-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border);
        color: var(--text-main);
        font-size: 1rem;
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s ease;
    }
    .drawer-link i {
        font-size: 1.15rem;
        color: var(--primary);
    }
    .drawer-link:hover, .drawer-link.active {
        color: var(--primary);
    }
    .drawer-link.logout {
        color: #ef4444;
        border-bottom: none;
        margin-top: auto;
    }
    .drawer-link.logout i { color: #ef4444; }

    /* Mobile Bottom Navigation Bar */
    .mobile-bottom-nav {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 64px;
        background: var(--glass);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-top: 1px solid var(--border);
        z-index: 999;
        justify-content: space-around;
        align-items: center;
        padding-bottom: env(safe-area-inset-bottom);
        box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
    }
    .mobile-nav-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 600;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        cursor: pointer;
        text-decoration: none;
        flex: 1;
        height: 100%;
        transition: color 0.2s ease;
    }
    .mobile-nav-btn i {
        font-size: 20px;
    }
    .mobile-nav-btn.active, .mobile-nav-btn:hover {
        color: var(--primary);
    }

    @media (max-width: 768px) {
        .desktop-nav { 
            display: none !important; 
        }
        .mobile-hamburger-btn { 
            display: block; 
        }
        .mobile-bottom-nav {
            display: flex !important;
        }
    }
    </style>
</head>
<body class="flex flex-col min-h-screen">

<!-- MAIN HEADER -->
<header id="mainHeader">
    <div class="max-w-7xl mx-auto flex items-center justify-between transition-all duration-300">
        
        <a href="index.php" class="logo-container">
            <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" onerror="this.style.display='none'">
            <h1>AgroMarket</h1>
        </a>

        <!-- Desktop Navigation -->
        <nav class="desktop-nav">
            <a href="shop.php" class="nav-link">
                <i class="ri-store-2-line"></i> Shop
            </a>
            <?php if($is_logged): ?>
            <a href="wishlist.php" class="nav-link">
                <i class="ri-heart-3-line"></i> Wishlist
            </a>
            <a href="<?= ($user_role === 'farmer') ? 'seller_dashboard.php' : 'buyer_dashboard.php' ?>" class="nav-link">
                <i class="ri-dashboard-3-line"></i> Dashboard
            </a>
            <?php endif; ?>
        </nav>

        <div class="flex items-center gap-3">
            <!-- Desktop Cart Icon -->
            <?php if($is_logged): ?>
            <a href="cart.php" class="relative items-center justify-center w-10 h-10 rounded-full border border-[var(--primary)] text-[var(--primary)] bg-[var(--primary-light)] transition-all hidden md:flex" title="Cart">
                <i class="ri-shopping-bag-fill text-lg"></i>
                <?php if(($cart_count ?? 0) > 0): ?>
                <span class="cart-badge"><?= min($cart_count, 99) ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <!-- Theme Toggle -->
            <button class="theme-toggle" id="themeToggle" title="Toggle dark mode" aria-label="Toggle Theme">
                <i class="ri-moon-line"></i>
            </button>

            <!-- Desktop Login / Logout Button -->
            <div class="hidden md:block">
                <?php if($is_logged): ?>
                <a href="logout.php" class="btn-login">Logout</a>
                <?php else: ?>
                <a href="login.php" class="btn-login">Login</a>
                <?php endif; ?>
            </div>

            <!-- Mobile Hamburger Menu Button -->
            <button onclick="toggleMobileMenu()" class="mobile-hamburger-btn" aria-label="Open Mobile Menu">
                <i class="ri-menu-3-line"></i>
            </button>
        </div>
    </div>
</header>

<!-- MOBILE DRAWER BACKDROP OVERLAY -->
<div id="mobileMenuOverlay" class="mobile-drawer-overlay" onclick="toggleMobileMenu()"></div>

<!-- MOBILE SLIDE-OUT DRAWER NAVIGATION PANEL -->
<div id="mobileMenuDrawer" class="mobile-drawer">
    <div class="drawer-header">
        <h3>Menu</h3>
        <button class="drawer-close-btn" onclick="toggleMobileMenu()" aria-label="Close Menu">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <?php if ($is_logged): ?>
    <?php
        $userInitial = strtoupper(substr($user_name, 0, 1));
    ?>
    <div class="drawer-user-card">
        <div class="drawer-avatar">
            <?= $userInitial ?>
        </div>
        <div class="drawer-user-info">
            <div class="drawer-user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="drawer-user-role"><?= htmlspecialchars($user_role) ?> Account</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="drawer-menu">
        <a href="index.php" class="drawer-link">
            <i class="ri-home-4-line"></i> Home
        </a>
        <a href="shop.php" class="drawer-link">
            <i class="ri-store-3-line"></i> Shop Produce
        </a>

        <?php if($is_logged): ?>
        <a href="cart.php" class="drawer-link active" style="color:var(--primary);">
            <i class="ri-shopping-cart-fill"></i> My Cart
            <?php if(($cart_count ?? 0) > 0): ?>
            <span class="ml-auto bg-[#f68b1e] text-white text-xs px-2 py-0.5 rounded-full font-bold"><?= min($cart_count, 99) ?></span>
            <?php endif; ?>
        </a>
        <a href="wishlist.php" class="drawer-link">
            <i class="ri-heart-line"></i> Saved Wishlist
        </a>

        <?php if ($user_role === 'farmer'): ?>
        <a href="seller_dashboard.php" class="drawer-link">
            <i class="ri-dashboard-line"></i> Seller Dashboard
        </a>
        <a href="add_product.php" class="drawer-link">
            <i class="ri-add-circle-line"></i> Add New Produce
        </a>
        <a href="apply_loan.php" class="drawer-link">
            <i class="ri-hand-coin-line"></i> Apply for Loan
        </a>
        <a href="farmer_repayment.php" class="drawer-link">
            <i class="ri-refund-2-line"></i> Loan Repayments
        </a>
        <?php else: ?>
        <a href="buyer_dashboard.php" class="drawer-link">
            <i class="ri-dashboard-line"></i> Buyer Dashboard
        </a>
        <?php endif; ?>

        <a href="market_disputes.php" class="drawer-link">
            <i class="ri-scales-3-line"></i> Order Disputes
        </a>

        <a href="logout.php" class="drawer-link logout">
            <i class="ri-logout-box-r-line"></i> Log Out
        </a>
        <?php else: ?>
        <div class="pt-4">
            <a href="login.php" class="btn-login w-full text-center">Login</a>
        </div>
        <div class="pt-2">
            <a href="register.php" class="btn-login w-full text-center">Register</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MAIN CHECKOUT CONTENT -->
<main class="flex-grow px-4 md:px-8 max-w-5xl mx-auto w-full pt-4">
    <div class="mb-6">
        <a href="cart.php" class="text-sm font-semibold text-[var(--text-muted)] hover:text-[var(--primary)] flex items-center gap-1.5 w-fit transition">
            <i class="ri-arrow-left-line"></i> Back to Cart
        </a>
        <h1 class="text-2xl md:text-3xl font-bold text-[var(--text-main)] mt-2 font-serif">Checkout</h1>
    </div>

    <?php if($error): ?>
    <div class="mb-5 p-4 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-2xl text-red-600 dark:text-red-400 text-sm flex items-center gap-2.5 shadow-[var(--shadow)]">
        <i class="ri-error-warning-fill text-xl flex-shrink-0"></i> 
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Progress Steps -->
    <div class="flex items-center justify-center gap-3 mb-8">
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--primary)] text-white flex items-center justify-center text-xs font-bold shadow-sm">1</span>
            <span class="text-sm font-bold text-[var(--primary)] hidden sm:block">Delivery Details</span>
        </div>
        <div class="h-0.5 w-8 sm:w-12 bg-[var(--border)]"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--primary)] text-white flex items-center justify-center text-xs font-bold shadow-sm">2</span>
            <span class="text-sm font-bold text-[var(--primary)] hidden sm:block">Payment</span>
        </div>
        <div class="h-0.5 w-8 sm:w-12 bg-[var(--border)]"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-[var(--bg-card)] border border-[var(--border)] text-[var(--text-muted)] flex items-center justify-center text-xs font-bold">3</span>
            <span class="text-sm font-semibold text-[var(--text-muted)] hidden sm:block">Confirmation</span>
        </div>
    </div>

    <form id="checkoutForm" method="POST" action="checkout_process.php">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32)) ?>">

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- Left column: forms -->
            <div class="lg:col-span-3 space-y-6">

                <!-- Delivery Details Card -->
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-[var(--shadow)] transition-colors duration-300">
                    <h2 class="text-lg font-bold text-[var(--text-main)] mb-5 flex items-center gap-2 font-serif">
                        <i class="ri-map-pin-2-fill text-[var(--primary)]"></i> Delivery Details
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1.5">Full Name *</label>
                            <input type="text" name="delivery_name" required
                                value="<?= htmlspecialchars($buyer['name']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3.5 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="Your full name">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1.5">Phone Number *</label>
                            <input type="tel" name="delivery_phone" required
                                value="<?= htmlspecialchars($buyer['phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3.5 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="0XX XXX XXXX">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1.5">Delivery Address *</label>
                            <input type="text" name="delivery_address" required
                                value="<?= htmlspecialchars($buyer['location']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3.5 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="Town / District / Region">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1.5">Order Notes <span class="font-normal">(optional)</span></label>
                            <textarea name="buyer_notes" rows="2"
                                class="w-full border border-[var(--border)] rounded-xl px-3.5 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent resize-none transition"
                                placeholder="Any special instructions for the farmer…"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Payment Card -->
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-[var(--shadow)] transition-colors duration-300">
                    <h2 class="text-lg font-bold text-[var(--text-main)] mb-5 flex items-center gap-2 font-serif">
                        <i class="ri-smartphone-fill text-[var(--primary)]"></i> Mobile Money Payment
                    </h2>

                    <!-- Network selector -->
                    <div class="flex gap-3 mb-5">
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="MTN" checked class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-[var(--primary)] peer-checked:bg-[var(--primary-light)] rounded-2xl p-3.5 text-center transition-all duration-200">
                                <div class="text-xs font-bold text-[var(--text-main)]">MTN MoMo</div>
                            </div>
                        </label>
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="Telecel" class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-[var(--primary)] peer-checked:bg-[var(--primary-light)] rounded-2xl p-3.5 text-center transition-all duration-200">
                                <div class="text-xs font-bold text-[var(--text-main)]">Telecel Cash</div>
                            </div>
                        </label>
                        <label class="flex-1 relative cursor-pointer">
                            <input type="radio" name="momo_network" value="AirtelTigo" class="sr-only peer">
                            <div class="border-2 border-[var(--border)] peer-checked:border-[var(--primary)] peer-checked:bg-[var(--primary-light)] rounded-2xl p-3.5 text-center transition-all duration-200">
                                <div class="text-xs font-bold text-[var(--text-main)]">AT Money</div>
                            </div>
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1.5">MoMo Number *</label>
                        <div class="relative">
                            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-muted)] font-bold text-sm">+233</span>
                            <input type="tel" name="momo_number" required id="momoInput"
                                class="w-full border border-[var(--border)] rounded-xl pl-16 pr-3.5 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="XX XXX XXXX" pattern="[0-9]{9,10}" maxlength="10">
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-2 flex items-center gap-1.5">
                            <i class="ri-lock-2-line text-[var(--primary)]"></i> You will receive an instant prompt on this number to authorize the payment.
                        </p>
                    </div>
                </div>

            </div>

            <!-- Right column: order summary -->
            <div class="lg:col-span-2">
                <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-[var(--shadow)] sticky top-24 transition-colors duration-300">
                    <h2 class="text-lg font-bold text-[var(--text-main)] mb-4 pb-3 border-b border-[var(--border)] font-serif">
                        Order Summary
                    </h2>

                    <!-- Items list -->
                    <div class="space-y-3 mb-4 max-h-56 overflow-y-auto pr-1">
                        <?php foreach($items as $item): ?>
                        <?php $imgSrc = !empty($item['image']) ? "../uploads/produce/".htmlspecialchars($item['image']) : "https://via.placeholder.com/60?text=?"; ?>
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl overflow-hidden bg-[var(--bg-body)] border border-[var(--border)] flex-shrink-0 flex items-center justify-center">
                                <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-full object-contain p-1" onerror="this.src='https://via.placeholder.com/60?text=Produce'">
                            </div>
                            <div class="flex-grow min-w-0">
                                <p class="text-xs font-bold text-[var(--text-main)] line-clamp-1"><?= htmlspecialchars($item['name']) ?></p>
                                <p class="text-xs text-[var(--text-muted)]">Qty: <?= $item['quantity'] ?> × GH₵ <?= number_format($item['price_per_bag'],2) ?></p>
                            </div>
                            <div class="text-xs font-bold text-[var(--text-main)] flex-shrink-0">
                                GH₵ <?= number_format($item['price_per_bag']*$item['quantity'],2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Totals -->
                    <div class="space-y-2 text-sm border-t border-[var(--border)] pt-3">
                        <div class="flex justify-between text-[var(--text-muted)]">
                            <span>Subtotal</span>
                            <span class="font-semibold text-[var(--text-main)]">GH₵ <?= number_format($subtotal,2) ?></span>
                        </div>
                        <div class="flex justify-between text-[var(--text-muted)]">
                            <span>Platform Fee (<?= PLATFORM_FEE_PERCENT ?>%)</span>
                            <span class="font-semibold text-[var(--text-main)]">GH₵ <?= number_format($platform_fee,2) ?></span>
                        </div>
                        <div class="flex justify-between font-bold text-base text-[var(--text-main)] pt-3 border-t border-[var(--border)]">
                            <span>Total</span>
                            <span class="text-lg text-[var(--primary)]">GH₵ <?= number_format($total,2) ?></span>
                        </div>
                    </div>

                    <!-- Escrow badge -->
                    <div class="mt-5 p-3.5 bg-[var(--primary-light)] border border-[var(--border)] rounded-xl flex gap-2.5 items-start">
                        <i class="ri-shield-check-fill text-[var(--primary)] text-xl flex-shrink-0"></i>
                        <p class="text-xs text-[var(--text-main)] font-medium leading-relaxed">
                            <strong>Escrow Protected</strong> — Payment is held safely and only released after you inspect and accept your delivery.
                        </p>
                    </div>

                    <!-- Submit -->
                    <button type="submit" id="payBtn"
                        class="mt-6 w-full bg-[var(--accent)] hover:bg-[var(--accent-hover)] text-white font-bold py-4 rounded-full transition shadow-lg tracking-wide text-sm flex items-center justify-center gap-2">
                        <i class="ri-lock-2-line text-lg"></i>
                        Pay GH₵ <?= number_format($total,2) ?> Now
                    </button>
                    <p class="text-center text-xs text-[var(--text-muted)] mt-3 flex items-center justify-center gap-1">
                        <i class="ri-shield-keyhole-line text-[var(--primary)]"></i> Encrypted 256-Bit Escrow Security
                    </p>
                </div>
            </div>
        </div>
    </form>
</main>

<!-- MOBILE BOTTOM NAVIGATION BAR (Visible Only on Mobile View) -->
<nav class="mobile-bottom-nav">
    <a href="index.php" class="mobile-nav-btn">
        <i class="ri-home-4-line"></i>
        <span>Home</span>
    </a>
    <a href="shop.php" class="mobile-nav-btn">
        <i class="ri-store-2-line"></i>
        <span>Shop</span>
    </a>
    <a href="wishlist.php" class="mobile-nav-btn">
        <i class="ri-heart-3-line"></i>
        <span>Wishlist</span>
    </a>
    <a href="cart.php" class="mobile-nav-btn active relative">
        <i class="ri-shopping-cart-2-fill"></i>
        <?php if($cart_count > 0): ?>
        <span class="absolute top-1 right-5 bg-[#f68b1e] w-2 h-2 rounded-full"></span>
        <?php endif; ?>
        <span>Cart</span>
    </a>
    <a href="<?= ($user_role === 'farmer') ? 'seller_dashboard.php' : 'buyer_dashboard.php' ?>" class="mobile-nav-btn">
        <i class="ri-user-3-line"></i>
        <span>Dashboard</span>
    </a>
</nav>

<!-- Payment Processing Overlay -->
<div id="paymentOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4">
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-8 max-w-sm w-full text-center shadow-[var(--shadow)] transition-colors duration-300">
        <!-- Spinner -->
        <div id="paySpinner" class="w-16 h-16 border-4 border-[var(--primary-light)] border-t-[var(--primary)] rounded-full animate-spin mx-auto mb-5"></div>
        <!-- Success icon -->
        <div id="paySuccess" class="w-16 h-16 bg-emerald-100 dark:bg-emerald-950/40 rounded-full flex items-center justify-center mx-auto mb-5 hidden">
            <i class="ri-check-fill text-emerald-600 dark:text-emerald-400 text-4xl"></i>
        </div>
        <!-- Fail icon -->
        <div id="payFail" class="w-16 h-16 bg-red-100 dark:bg-red-950/40 rounded-full flex items-center justify-center mx-auto mb-5 hidden">
            <i class="ri-close-fill text-red-600 dark:text-red-400 text-4xl"></i>
        </div>

        <h3 id="payTitle" class="text-lg font-bold text-[var(--text-main)] mb-2 font-serif">Processing Payment…</h3>
        <p id="payMsg" class="text-sm text-[var(--text-muted)]">Please check your phone for the MoMo prompt and approve the payment.</p>

        <div id="payProgress" class="mt-5 flex justify-center gap-2">
            <span class="w-2.5 h-2.5 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:0s"></span>
            <span class="w-2.5 h-2.5 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:.2s"></span>
            <span class="w-2.5 h-2.5 bg-[var(--primary)] rounded-full animate-bounce" style="animation-delay:.4s"></span>
        </div>

        <!-- Try again button container -->
        <div id="tryAgainBtnContainer" class="hidden mt-6">
            <button type="button" id="tryAgainBtn" class="w-full bg-[var(--accent)] hover:bg-[var(--accent-hover)] text-white py-3 rounded-full font-bold text-sm transition shadow-lg">
                Try Again
            </button>
        </div>
    </div>
</div>

<script>
// --- Mobile Menu Toggle ---
function toggleMobileMenu() {
    const overlay = document.getElementById('mobileMenuOverlay');
    const drawer = document.getElementById('mobileMenuDrawer');
    if (overlay && drawer) {
        const isActive = drawer.classList.contains('active');
        if (isActive) {
            drawer.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        } else {
            drawer.classList.add('active');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }
}

// --- Dark Mode Logic (Exact from index.php) ---
const toggleBtn = document.getElementById('themeToggle');
const icon = toggleBtn ? toggleBtn.querySelector('i') : null;
const body = document.body;

if (localStorage.getItem('theme') === 'dark') {
    body.classList.add('dark');
    if (icon) icon.className = 'ri-sun-line';
}

if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
        body.classList.toggle('dark');
        const isDark = body.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        if (icon) icon.className = isDark ? 'ri-sun-line' : 'ri-moon-line';
    });
}

// --- Navbar Scroll Effect ---
window.addEventListener('scroll', () => {
    const header = document.getElementById('mainHeader');
    if (header) {
        if (window.scrollY > 30) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    }
});

// Checkout Form Submission & Payment Processing
document.getElementById('checkoutForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const btn  = document.getElementById('payBtn');
    const overlay = document.getElementById('paymentOverlay');

    // Reset overlay states
    document.getElementById('paySpinner').classList.remove('hidden');
    document.getElementById('paySuccess').classList.add('hidden');
    document.getElementById('payFail').classList.add('hidden');
    document.getElementById('payProgress').classList.remove('hidden');
    document.getElementById('tryAgainBtnContainer').classList.add('hidden');
    document.getElementById('payTitle').textContent = 'Processing Payment…';
    document.getElementById('payMsg').textContent   = 'Please check your phone for the MoMo prompt and approve the payment.';

    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Initiating…';
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    const formData = new FormData(this);

    try {
        const res  = await fetch('checkout_process.php', { method:'POST', body: formData });
        const data = await res.json();

        if (data.success && data.order_id && data.reference) {
            pollPayment(data.order_id, data.reference);
        } else {
            const msg = data.stock_errors ? data.stock_errors.join('\n') : (data.message || 'Could not initiate payment. Please try again.');
            showPayFail(msg);
        }
    } catch (err) {
        showPayFail('Connection error. Please try again.');
    }
});

function pollPayment(orderId, reference) {
    let attempts = 0;
    const maxAttempts = 24;

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
    document.getElementById('tryAgainBtnContainer').classList.add('hidden');
    document.getElementById('payTitle').textContent = 'Payment Successful!';
    document.getElementById('payMsg').textContent   = 'Your order has been placed and is now being prepared.';
    setTimeout(() => { window.location.href = `orders_success.php?order_id=${orderId}`; }, 2000);
}

function showPayFail(msg) {
    document.getElementById('paySpinner').classList.add('hidden');
    document.getElementById('payFail').classList.remove('hidden');
    document.getElementById('payProgress').classList.add('hidden');
    
    document.getElementById('payTitle').textContent = 'Payment Failed';
    document.getElementById('payMsg').textContent   = msg;

    document.getElementById('tryAgainBtnContainer').classList.remove('hidden');
}

document.getElementById('tryAgainBtn').onclick = () => {
    const overlay = document.getElementById('paymentOverlay');
    overlay.classList.add('hidden');
    overlay.classList.remove('flex');

    const payBtn = document.getElementById('payBtn');
    payBtn.disabled = false;
    payBtn.innerHTML = '<i class="ri-lock-2-line text-lg"></i> Pay GH₵ <?= number_format($total,2) ?> Now';
};
</script>
</body>
</html>