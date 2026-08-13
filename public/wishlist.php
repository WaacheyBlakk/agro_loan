<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    header('Location: login.php'); 
    exit; 
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;

// Cart count for badge
$cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id = ?");
$cStmt->execute([$user_id]);
$cart_count = (int)$cStmt->fetchColumn();

// Fetch user display name
$uStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$uStmt->execute([$user_id]);
$user_name = $uStmt->fetchColumn() ?: ($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'User');

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
        padding-bottom: 80px; /* space for mobile bottom bar */
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
            <a href="shop.php" class="nav-link <?= ($active_nav === 'shop') ? 'active' : '' ?>">
                <i class="ri-store-2-line"></i> Shop
            </a>
            <?php if($is_logged): ?>
            <a href="wishlist.php" class="nav-link <?= ($active_nav === 'wishlist') ? 'active' : '' ?>">
                <i class="ri-heart-3-line"></i> Wishlist
            </a>
            <a href="<?= ($user_role === 'farmer') ? 'seller_dashboard.php' : 'buyer_dashboard.php' ?>" class="nav-link <?= ($active_nav === 'dashboard') ? 'active' : '' ?>">
                <i class="ri-dashboard-3-line"></i> Dashboard
            </a>
            <?php endif; ?>
        </nav>

        <div class="flex items-center gap-3">
            <!-- Desktop Cart Icon -->
            <?php if($is_logged): ?>
            <a href="cart.php" class="relative items-center justify-center w-10 h-10 rounded-full border border-[var(--border)] text-[var(--text-main)] hover:text-[var(--primary)] hover:border-[var(--primary)] transition-all hidden md:flex" title="Cart">
                <i class="ri-shopping-bag-line text-lg"></i>
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
        <a href="cart.php" class="drawer-link">
            <i class="ri-shopping-cart-2-line"></i> My Cart
            <?php if(($cart_count ?? 0) > 0): ?>
            <span class="ml-auto bg-[#f68b1e] text-white text-xs px-2 py-0.5 rounded-full font-bold"><?= min($cart_count, 99) ?></span>
            <?php endif; ?>
        </a>
        <a href="wishlist.php" class="drawer-link active" style="color:var(--primary);">
            <i class="ri-heart-fill"></i> Saved Wishlist
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

<!-- MAIN WISHLIST CONTENT -->
<main class="flex-grow px-4 md:px-8 max-w-7xl mx-auto w-full pt-4">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-[var(--text-main)] flex items-center gap-2 font-serif">
                <i class="ri-heart-3-fill text-red-500"></i> My Wishlist
            </h1>
            <p class="text-[var(--text-muted)] text-sm mt-1 font-sans"><?= count($items) ?> item<?= count($items)!=1?'s':'' ?> saved</p>
        </div>
    </div>

    <?php if(empty($items)): ?>
    <div class="flex flex-col items-center justify-center bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl py-20 px-6 text-center shadow-[var(--shadow)] transition-colors duration-300">
        <div class="w-24 h-24 bg-red-50 dark:bg-red-950/30 rounded-2xl flex items-center justify-center mb-5">
            <i class="ri-heart-line text-5xl text-red-500"></i>
        </div>
        <h2 class="text-2xl font-bold text-[var(--text-main)] mb-2 font-serif">Your wishlist is empty</h2>
        <p class="text-[var(--text-muted)] mb-6 max-w-sm">Browse the marketplace and save items you love. They'll appear here for quick ordering.</p>
        <a href="shop.php" class="bg-[var(--accent)] hover:bg-[var(--accent-hover)] text-white px-8 py-3 rounded-full font-bold transition shadow-lg inline-flex items-center gap-2">
            Explore Produce <i class="ri-arrow-right-line"></i>
        </a>
    </div>

    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
        <?php foreach($items as $item): ?>
        <?php
            $imgSrc  = !empty($item['image']) ? "../uploads/produce/".htmlspecialchars($item['image']) : "https://via.placeholder.com/300?text=No+Image";
            $inStock = $item['bags_available'] > 0;
        ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl overflow-hidden shadow-[var(--shadow)] hover:shadow-[var(--shadow-lg)] hover:border-[var(--primary)] transition-all duration-300 group flex flex-col justify-between" id="wish-item-<?= $item['produce_id'] ?>">
            
            <div class="relative">
                <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="block aspect-square overflow-hidden bg-[var(--bg-body)]">
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-full h-full object-contain p-3 hover:scale-105 transition duration-300" onerror="this.src='https://via.placeholder.com/300?text=Produce'">
                    <?php if(!$inStock): ?>
                    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center">
                        <span class="bg-red-600 text-white text-xs px-2.5 py-1 rounded-full font-bold shadow">Out of Stock</span>
                    </div>
                    <?php endif; ?>
                </a>
                <button onclick="removeFromWishlist(<?= $item['produce_id'] ?>)"
                    class="absolute top-2 right-2 w-8 h-8 bg-[var(--bg-card)] border border-[var(--border)] rounded-full flex items-center justify-center text-red-500 hover:text-red-600 hover:scale-110 shadow transition"
                    aria-label="Remove item"
                    title="Remove from Wishlist">
                    <i class="ri-heart-fill text-sm"></i>
                </button>
            </div>

            <div class="p-3 sm:p-4 flex flex-col flex-grow justify-between">
                <div>
                    <p class="text-xs text-[var(--text-muted)] mb-1">By <span class="font-medium text-[var(--primary)]"><?= htmlspecialchars($item['farmer_name']) ?></span></p>
                    <a href="product_details.php?id=<?= $item['produce_id'] ?>" class="text-sm font-bold text-[var(--text-main)] line-clamp-2 hover:text-[var(--primary)] transition">
                        <?= htmlspecialchars($item['name']) ?>
                    </a>
                    <div class="text-base font-extrabold text-[var(--text-main)] mt-1.5">
                        GH₵ <?= number_format($item['price_per_bag'],2) ?>
                        <span class="text-xs text-[var(--text-muted)] font-normal">/ bag</span>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <?php if($inStock): ?>
                    <button onclick="moveToCart(<?= $item['produce_id'] ?>, this)"
                        class="w-full bg-[var(--accent)] hover:bg-[var(--accent-hover)] text-white text-xs font-bold py-2 rounded-xl transition shadow flex items-center justify-center gap-1.5">
                        <i class="ri-shopping-cart-add-line"></i> Add to Cart
                    </button>
                    <?php else: ?>
                    <button disabled class="w-full bg-[var(--bg-body)] text-[var(--text-muted)] border border-[var(--border)] text-xs font-bold py-2 rounded-xl cursor-not-allowed">
                        Out of Stock
                    </button>
                    <?php endif; ?>
                    <button onclick="removeFromWishlist(<?= $item['produce_id'] ?>)"
                        class="w-full border border-[var(--border)] text-[var(--text-muted)] text-xs font-semibold py-1.5 rounded-xl hover:border-red-300 hover:text-red-500 transition">
                        Remove
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
    <a href="wishlist.php" class="mobile-nav-btn active">
        <i class="ri-heart-3-fill"></i>
        <span>Wishlist</span>
    </a>
    <a href="cart.php" class="mobile-nav-btn relative">
        <i class="ri-shopping-cart-2-line"></i>
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

// Toast Helper
function showToast(msg, type='success'){
    let c = document.getElementById('toastContainer');
    if (!c) { 
        c = document.createElement('div'); 
        c.id = 'toastContainer'; 
        c.className = 'fixed top-4 right-4 z-[9999] space-y-2 pointer-events-none'; 
        document.body.appendChild(c); 
    }
    const t = document.createElement('div');
    const col = type === 'success' ? 'bg-emerald-600' : type === 'error' ? 'bg-red-600' : 'bg-blue-600';
    const ic  = type === 'success' ? 'ri-check-line' : 'ri-error-warning-line';
    t.className = `pointer-events-auto flex items-center gap-3 ${col} text-white px-4 py-2.5 rounded-xl shadow-lg transform translate-x-full transition-all duration-300 max-w-[90vw]`;
    t.innerHTML = `<i class="${ic} text-base"></i><span class="font-semibold text-xs">${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => t.classList.remove('translate-x-full'));
    setTimeout(() => { 
        t.classList.add('translate-x-full', 'opacity-0'); 
        setTimeout(() => t.remove(), 300); 
    }, 3500);
}

const CSRF_TOKEN = "<?= csrf_token() ?>";

async function removeFromWishlist(productId) {
    const card = document.getElementById('wish-item-' + productId);
    if (card) {
        card.style.opacity = '0.4';
        card.style.pointerEvents = 'none';
    }

    const form = new FormData();
    form.append('product_id', productId);
    form.append('csrf_token', CSRF_TOKEN);

    try {
        const res = await fetch('wishlist_remove.php', { method:'POST', body:form });
        const data = await res.json();
        
        if (data.success) {
            if (card) {
                card.style.transition = 'all .4s ease';
                card.style.transform  = 'scale(0.85)';
                card.style.opacity    = '0';
                setTimeout(() => { card.remove(); updateCount(); }, 400);
            }
            showToast('Removed from wishlist', 'success');
        } else {
            if (card) {
                card.style.opacity = '1';
                card.style.pointerEvents = '';
            }
            showToast(data.message || 'Error removing item', 'error');
        }
    } catch(e) {
        if (card) {
            card.style.opacity = '1';
            card.style.pointerEvents = '';
        }
        showToast('Connection error', 'error');
    }
}

async function moveToCart(productId, btn) {
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line ri-spin"></i> Adding...';

    const form = new FormData();
    form.append('product_id', productId);
    form.append('csrf_token', CSRF_TOKEN);

    try {
        const res  = await fetch('cart_add.php', { method:'POST', body:form });
        const data = await res.json();
        if (data.success) {
            showToast('Added to cart!', 'success');
            btn.innerHTML = '<i class="ri-check-line"></i> In Cart';
        } else if (data.redirect) {
            window.location.href = data.redirect;
        } else {
            showToast(data.message || 'Could not add to cart', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch(e) {
        showToast('Something went wrong', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
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