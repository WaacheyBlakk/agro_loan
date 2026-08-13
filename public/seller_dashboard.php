<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//seller_dashboard.php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) {
    header('Location: login.php');
    exit;
}

$pdo = getPDO();

// Ensure 'status' column exists in 'produce_listings'
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM `produce_listings` LIKE 'status'");
    if ($colCheck && $colCheck->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `produce_listings` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'active'");
    }
} catch (Exception $e) {
    // Fail silently if restricted
}

$user_role = $_SESSION['role'] ?? 'farmer';
$is_logged = true;

if ($user_role !== 'farmer') {
    header('Location: buyer_dashboard.php');
    exit;
}

// Farmer profile
$farmerStmt = $pdo->prepare("SELECT id, name, email, phone, momo_phone, location, profile_bio, created_at FROM users WHERE id = ?");
$farmerStmt->execute([$user_id]);
$farmer = $farmerStmt->fetch(PDO::FETCH_ASSOC);

if (!$farmer) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$user_name = $farmer['name'] ?? ($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Farmer');

// Cart count (kept for header parity with buyer_dashboard, sellers can also browse the shop)
$cart_count = $_SESSION['cart_count'] ?? 0;

// Scoped stats
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT og.id) AS total_orders,
        COALESCE(SUM(oi.subtotal), 0) AS gross_revenue,
        COALESCE(SUM(CASE WHEN e.status='held' THEN e.amount END), 0) AS escrow_held,
        COALESCE(SUM(CASE WHEN e.status='released' THEN e.amount END), 0) AS paid_out,
        SUM(og.status IN ('payment_confirmed','preparing','in_transit','ready_for_pickup')) AS active_orders,
        SUM(og.status = 'delivered') AS completed_orders
    FROM order_items oi
    JOIN order_groups og ON oi.order_group_id = og.id
    LEFT JOIN escrow e ON e.order_item_id = oi.id
    WHERE oi.farmer_id = ?
");
$statsStmt->execute([$user_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0,
    'gross_revenue' => 0,
    'escrow_held' => 0,
    'paid_out' => 0,
    'active_orders' => 0,
    'completed_orders' => 0
];

// Scoped order groups for this farmer
$groupsStmt = $pdo->prepare("
    SELECT 
        og.*,
        o.buyer_id,
        o.delivery_name,
        o.delivery_phone,
        o.delivery_address,
        o.buyer_notes,
        o.created_at AS order_date,
        b.name AS buyer_name,
        b.phone AS buyer_phone,
        o.payment_status
    FROM order_groups og
    JOIN orders o ON o.id = og.order_id
    JOIN buyers b ON b.id = o.buyer_id
    WHERE og.farmer_id = ?
    ORDER BY og.created_at DESC
");
$groupsStmt->execute([$user_id]);
$myGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch items helper scoped to the group
$itemsStmt = $pdo->prepare("
    SELECT 
        oi.*,
        p.produce_name,
        p.photo,
        e.status AS escrow_status,
        e.amount AS escrow_amount
    FROM order_items oi
    JOIN produce_listings p ON p.id = oi.produce_id
    LEFT JOIN escrow e ON e.order_item_id = oi.id
    WHERE oi.order_group_id = ?
");

$groups = [];
foreach ($myGroups as $g) {
    $itemsStmt->execute([$g['id']]);
    $g['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $groups[] = $g;
}

// Produce listings
$listingsStmt = $pdo->prepare("
    SELECT 
        p.*,
        c.name AS category_name,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.produce_id = p.id) AS total_orders
    FROM produce_listings p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.farmer_id = ?
    ORDER BY p.created_at DESC
");
$listingsStmt->execute([$user_id]);
$listings = $listingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle profile update
$profileError = '';
$profileSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    csrf_verify();
    $name     = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
    $phone    = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS));
    $momo     = preg_replace('/\D/', '', ($_POST['momo_phone'] ?? ''));
    $location = trim(filter_input(INPUT_POST, 'location', FILTER_SANITIZE_SPECIAL_CHARS));
    $bio      = trim(filter_input(INPUT_POST, 'profile_bio', FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$name) {
        $profileError = 'Name is required.';
    } elseif (!$momo || strlen($momo) < 9) {
        $profileError = 'A valid Mobile Money number is required for receiving payouts.';
    } else {
        $upd = $pdo->prepare("UPDATE users SET name = ?, phone = ?, momo_phone = ?, location = ?, profile_bio = ? WHERE id = ?");
        $upd->execute([$name, $phone, $momo, $location, $bio, $user_id]);
        $profileSuccess = 'Profile updated successfully!';

        $farmer['name'] = $name;
        $farmer['phone'] = $phone;
        $farmer['momo_phone'] = $momo;
        $farmer['location'] = $location;
        $farmer['profile_bio'] = $bio;
        $_SESSION['user_name'] = $name;
        $user_name = $name;
    }
}

$activeTab = $_GET['tab'] ?? 'overview';

// Status config using the same Tailwind, dark-mode aware pattern as buyer_dashboard.php
$statusConfig = [
    'pending_payment'   => ['label' => 'Pending Payment',   'color' => 'bg-amber-50 text-amber-800 border border-amber-200/50 dark:bg-amber-950/30 dark:text-amber-300 dark:border-amber-900/40', 'icon' => 'ri-time-line'],
    'payment_confirmed' => ['label' => 'Confirmed',         'color' => 'bg-blue-50 text-blue-800 border border-blue-200/50 dark:bg-blue-950/30 dark:text-blue-300 dark:border-blue-900/40',      'icon' => 'ri-check-double-line'],
    'preparing'         => ['label' => 'Preparing',         'color' => 'bg-purple-50 text-purple-800 border border-purple-200/50 dark:bg-purple-950/30 dark:text-purple-300 dark:border-purple-900/40', 'icon' => 'ri-box-3-line'],
    'in_transit'        => ['label' => 'In Transit',        'color' => 'bg-orange-50 text-orange-800 border border-orange-200/50 dark:bg-orange-950/30 dark:text-orange-300 dark:border-orange-900/40', 'icon' => 'ri-truck-line'],
    'ready_for_pickup'  => ['label' => 'Ready for Pickup',  'color' => 'bg-cyan-50 text-cyan-800 border border-cyan-200/50 dark:bg-cyan-950/30 dark:text-cyan-300 dark:border-cyan-900/40',      'icon' => 'ri-store-line'],
    'delivered'         => ['label' => 'Delivered',         'color' => 'bg-emerald-50 text-emerald-800 border border-emerald-200/50 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900/40', 'icon' => 'ri-checkbox-circle-line'],
    'cancelled'         => ['label' => 'Cancelled',         'color' => 'bg-rose-50 text-rose-800 border border-rose-200/50 dark:bg-rose-950/30 dark:text-rose-300 dark:border-rose-900/40',       'icon' => 'ri-close-circle-line'],
];

$escrowConfig = [
    'held'     => ['label' => 'In Escrow', 'color' => 'text-amber-600 dark:text-amber-400',   'icon' => 'ri-lock-line'],
    'released' => ['label' => 'Paid Out',  'color' => 'text-emerald-600 dark:text-emerald-400', 'icon' => 'ri-check-line'],
    'refunded' => ['label' => 'Refunded',  'color' => 'text-rose-600 dark:text-rose-400',      'icon' => 'ri-arrow-go-back-line'],
];

$page_title = 'Seller Dashboard | AgroMarket';
$active_nav = 'dashboard';
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
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        agro: { 50:'#f0fdf4', 100:'#dcfce7', 500:'#22c55e', 600:'#16a34a', 700:'#15803d', 900:'#14532d' },
                        jumia: { orange: '#f68b1e', blue: '#264996' }
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

    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    html, body { max-width: 100%; overflow-x: hidden; }

    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        transition: background 0.3s ease, color 0.3s ease;
        margin: 0;
        padding-top: 75px;
        padding-bottom: 40px;
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
    header.scrolled { padding: 8px 5%; box-shadow: var(--shadow); }

    .logo-container { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--primary-dark); flex-shrink: 0; }
    body.dark .logo-container { color: var(--text-main); }
    .logo-container img { height: 38px; width: 38px; border-radius: 8px; object-fit: cover; }
    .logo-container h1 {
        font-size: 1.4rem; font-weight: 800; margin: 0;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.5px;
    }

    .desktop-nav { display: flex; align-items: center; gap: 25px; }

    .nav-link {
        color: var(--text-main); text-decoration: none; font-weight: 600; font-size: 0.95rem;
        transition: color 0.3s; position: relative; display: inline-flex; align-items: center; gap: 6px;
    }
    .nav-link:hover { color: var(--primary); }
    .nav-link::after {
        content: ''; position: absolute; width: 0; height: 2px; bottom: -4px; left: 0;
        background-color: var(--primary); transition: width 0.3s;
    }
    .nav-link:hover::after, .nav-link.active::after { width: 100%; }
    .nav-link.active { color: var(--primary); }

    .btn-login {
        padding: 8px 20px; border: 2px solid var(--primary); border-radius: 50px; color: var(--primary);
        font-weight: 600; transition: 0.3s; text-decoration: none; font-size: 0.9rem;
        display: inline-flex; align-items: center; justify-content: center; background: transparent;
    }
    .btn-login:hover { background: var(--primary); color: #ffffff !important; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.2); }

    .theme-toggle {
        background: var(--bg-card); border: 1px solid var(--border); border-radius: 50%; color: var(--text-main);
        cursor: pointer; width: 40px; height: 40px; font-size: 1.2rem; display: flex; justify-content: center;
        align-items: center; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex-shrink: 0;
    }
    .theme-toggle:hover { transform: rotate(15deg) scale(1.1); border-color: var(--primary); }

    .cart-badge {
        position: absolute; top: -4px; right: -4px; background: #f68b1e; color: #ffffff; border-radius: 9999px;
        min-width: 18px; height: 18px; padding: 0 4px; font-size: 9px; display: flex; align-items: center;
        justify-content: center; font-weight: 800; box-shadow: 0 2px 4px rgba(246,139,30,0.3);
    }

    .mobile-hamburger-btn { display: none; background: none; border: none; color: var(--text-main); font-size: 1.5rem; cursor: pointer; padding: 4px; margin-left: 5px; }

    .mobile-drawer-overlay {
        position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px); z-index: 1000; opacity: 0; visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .mobile-drawer-overlay.active { opacity: 1; visibility: visible; }

    .mobile-drawer {
        position: fixed; top: 0; right: -100%; width: 75%; max-width: 320px; height: 100vh;
        background: var(--bg-card); z-index: 1001; box-shadow: -5px 0 25px rgba(0,0,0,0.2);
        transition: right 0.4s ease; display: flex; flex-direction: column; overflow-y: auto;
    }
    .mobile-drawer.active { right: 0; }

    .drawer-header { display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid var(--border); }
    .drawer-header h3 { margin: 0; font-size: 1.1rem; font-weight: 800; color: var(--primary); }
    .drawer-close-btn { background: none; border: none; color: var(--text-main); font-size: 1.5rem; cursor: pointer; }

    .drawer-user-card { padding: 14px 20px; background: var(--primary-light); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
    .drawer-avatar {
        width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #ffffff;
        display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; flex-shrink: 0;
    }
    .drawer-user-info { overflow: hidden; }
    .drawer-user-name { font-weight: 700; font-size: 14px; color: var(--text-main); white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
    .drawer-user-role { font-size: 11px; color: var(--text-muted); text-transform: capitalize; }

    .drawer-menu { padding: 15px 20px; display: flex; flex-direction: column; gap: 14px; flex: 1; }
    .drawer-link {
        display: flex; align-items: center; gap: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border);
        color: var(--text-main); font-size: 1rem; font-weight: 600; text-decoration: none; transition: color 0.2s ease;
    }
    .drawer-link i { font-size: 1.15rem; color: var(--primary); }
    .drawer-link:hover, .drawer-link.active { color: var(--primary); }
    .drawer-link.logout { color: #ef4444; border-bottom: none; margin-top: auto; }
    .drawer-link.logout i { color: #ef4444; }

    .tab-btn {
        padding: 0.625rem 1rem; font-size: 0.8125rem; font-weight: 600; border-radius: 0.75rem;
        transition: all 0.2s ease-in-out; display: inline-flex; align-items: center; justify-content: center;
        gap: 0.5rem; width: 100%;
    }
    .tab-btn.active { background: var(--primary); color: #fff; box-shadow: 0 4px 14px rgba(22, 163, 74, 0.2); }
    .tab-btn:not(.active) { color: var(--text-muted); }
    .tab-btn:not(.active):hover { background: var(--primary-light); color: var(--text-main); }
    .step-line { flex: 1; height: 3px; border-radius: 9999px; }

    @media (max-width: 768px) {
        .desktop-nav { display: none !important; }
        .mobile-hamburger-btn { display: block; }
    }
    @media (min-width: 768px) {
        .tab-btn { width: auto; font-size: 0.875rem; padding: 0.625rem 1.25rem; }
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
            <a href="wishlist.php" class="nav-link <?= ($active_nav === 'wishlist') ? 'active' : '' ?>">
                <i class="ri-heart-3-line"></i> Wishlist
            </a>
            <a href="seller_dashboard.php" class="nav-link <?= ($active_nav === 'dashboard') ? 'active' : '' ?>">
                <i class="ri-dashboard-3-line"></i> Dashboard
            </a>
        </nav>

        <div class="flex items-center gap-3">
            <!-- Desktop Cart Icon -->
            <a href="cart.php" class="relative items-center justify-center w-10 h-10 rounded-full border border-[var(--primary)] text-[var(--primary)] bg-[var(--primary-light)] transition-all hidden md:flex" title="Cart">
                <i class="ri-shopping-bag-fill text-lg"></i>
                <?php if (($cart_count ?? 0) > 0): ?>
                <span class="cart-badge"><?= min($cart_count, 99) ?></span>
                <?php endif; ?>
            </a>

            <!-- Theme Toggle -->
            <button class="theme-toggle" id="themeToggle" title="Toggle dark mode" aria-label="Toggle Theme">
                <i class="ri-moon-line"></i>
            </button>

            <!-- Desktop Logout Button -->
            <div class="hidden md:block">
                <a href="logout.php" class="btn-login">Logout</a>
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

    <?php $userInitial = strtoupper(substr($user_name, 0, 1)); ?>
    <div class="drawer-user-card">
        <div class="drawer-avatar"><?= $userInitial ?></div>
        <div class="drawer-user-info">
            <div class="drawer-user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="drawer-user-role"><?= htmlspecialchars($user_role) ?> Account</div>
        </div>
    </div>

    <div class="drawer-menu">
        <a href="index.php" class="drawer-link">
            <i class="ri-home-4-line"></i> Home
        </a>
        <a href="shop.php" class="drawer-link">
            <i class="ri-store-3-line"></i> Shop Produce
        </a>
        <a href="wishlist.php" class="drawer-link">
            <i class="ri-heart-line"></i> Saved Wishlist
        </a>

        <a href="seller_dashboard.php" class="drawer-link active" style="color:var(--primary);">
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
        <a href="market_disputes.php" class="drawer-link">
            <i class="ri-scales-3-line"></i> Order Disputes
        </a>

        <a href="logout.php" class="drawer-link logout">
            <i class="ri-logout-box-r-line"></i> Log Out
        </a>
    </div>
</div>

<div class="pt-6 pb-24 md:pb-16 min-h-screen px-3 sm:px-6 max-w-6xl mx-auto w-full">

    <!-- Header Section -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-4 sm:p-6 md:p-8 shadow-sm mb-6 md:mb-8 flex flex-col md:flex-row items-center justify-between gap-5 text-center md:text-left transition-colors duration-300">
        <div class="flex flex-col md:flex-row items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-2xl font-extrabold shadow-md flex-shrink-0">
                <?= strtoupper(substr($farmer['name'] ?? 'F', 0, 1)) ?>
            </div>
            <div>
                <h1 class="text-xl md:text-2xl font-extrabold text-[var(--text-main)] leading-tight flex items-center justify-center md:justify-start gap-2">
                    Welcome back, <?= htmlspecialchars(explode(' ', $farmer['name'] ?? 'Farmer')[0]) ?>
                </h1>
                <p class="text-[var(--text-muted)] text-xs mt-1 font-medium flex items-center justify-center md:justify-start gap-1.5">
                    <i class="ri-calendar-line text-emerald-600 dark:text-emerald-400"></i> Member since <?= date('F Y', strtotime($farmer['created_at'] ?? 'now')) ?>
                </p>
            </div>
        </div>
        <a href="add_product.php" class="w-full md:w-auto bg-[var(--primary)] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg flex items-center justify-center gap-2">
            <i class="ri-add-circle-line text-base text-white"></i> Add New Listing
        </a>
    </div>

    <!-- MoMo Warning Alert -->
    <?php if (empty($farmer['momo_phone'])): ?>
    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-950/20 border border-amber-200/50 dark:border-amber-900/40 rounded-2xl text-amber-800 dark:text-amber-300 text-sm flex items-start gap-3">
        <i class="ri-alert-line text-lg flex-shrink-0 mt-0.5"></i>
        <div>
            <strong class="block mb-1">Mobile Money Number Missing!</strong>
            You need a valid Mobile Money number to receive payouts automatically when buyers confirm orders.
            <button type="button" onclick="setTab('profile')" class="underline font-bold ml-1">Add it now &rarr;</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tab Container -->
    <div class="grid grid-cols-2 sm:grid-cols-3 md:flex md:flex-row gap-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-1.5 mb-6 md:mb-8 w-full shadow-sm transition-colors duration-300">
        <button onclick="setTab('overview')" id="tab-overview" class="tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>">
            <i class="ri-dashboard-line text-base"></i><span>Overview</span>
        </button>
        <button onclick="setTab('orders')" id="tab-orders" class="tab-btn <?= $activeTab === 'orders' ? 'active' : '' ?>">
            <i class="ri-shopping-bag-3-line text-base"></i><span>Orders</span>
            <?php if (($stats['active_orders'] ?? 0) > 0): ?>
            <span class="bg-red-500 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold leading-none"><?= (int)$stats['active_orders'] ?></span>
            <?php endif; ?>
        </button>
        <button onclick="setTab('listings')" id="tab-listings" class="tab-btn <?= $activeTab === 'listings' ? 'active' : '' ?>">
            <i class="ri-store-2-line text-base"></i><span>Listings</span>
        </button>
        <button onclick="setTab('profile')" id="tab-profile" class="tab-btn <?= $activeTab === 'profile' ? 'active' : '' ?>">
            <i class="ri-user-line text-base"></i><span>Profile</span>
        </button>
        <a href="market_disputes.php" class="tab-btn text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-semibold justify-center">
            <i class="ri-scales-3-line text-base"></i><span>Disputes</span>
        </a>
    </div>

    <!-- ===== TAB: OVERVIEW ===== -->
    <div id="panel-overview" class="<?= $activeTab !== 'overview' ? 'hidden' : '' ?> space-y-6 animate-fadeIn">
        <!-- Stat Cards Grid -->
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">
            <?php
            $cards = [
                ['icon' => 'ri-shopping-bag-3-line', 'color' => 'text-blue-600 bg-blue-50 dark:bg-blue-950/40 dark:text-blue-300', 'label' => 'Total Packages', 'value' => (int)$stats['total_orders']],
                ['icon' => 'ri-money-cny-circle-line', 'color' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300', 'label' => 'Gross Revenue', 'value' => 'GH₵ ' . number_format((float)$stats['gross_revenue'], 2)],
                ['icon' => 'ri-lock-line', 'color' => 'text-amber-600 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-300', 'label' => 'In Escrow', 'value' => 'GH₵ ' . number_format((float)$stats['escrow_held'], 2)],
                ['icon' => 'ri-bank-line', 'color' => 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300', 'label' => 'Paid Out', 'value' => 'GH₵ ' . number_format((float)$stats['paid_out'], 2)],
                ['icon' => 'ri-time-line', 'color' => 'text-orange-600 bg-orange-50 dark:bg-orange-950/40 dark:text-orange-300', 'label' => 'Active Orders', 'value' => (int)$stats['active_orders']],
                ['icon' => 'ri-checkbox-circle-line', 'color' => 'text-purple-600 bg-purple-50 dark:bg-purple-950/40 dark:text-purple-300', 'label' => 'Completed', 'value' => (int)$stats['completed_orders']],
            ];
            foreach ($cards as $c): ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-3 sm:p-5 shadow-sm hover:shadow-md transition-all duration-300">
                <div class="flex items-center justify-between mb-2 md:mb-4">
                    <span class="text-[10px] md:text-xs text-[var(--text-muted)] font-bold uppercase tracking-wider"><?= $c['label'] ?></span>
                    <div class="w-8 h-8 md:w-10 md:h-10 rounded-xl <?= $c['color'] ?> flex items-center justify-center flex-shrink-0">
                        <i class="<?= $c['icon'] ?> text-sm md:text-lg"></i>
                    </div>
                </div>
                <div class="text-base sm:text-lg md:text-2xl font-black text-[var(--text-main)] tracking-tight truncate"><?= $c['value'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- How Escrow Works -->
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl shadow-sm p-4 sm:p-6 transition-colors duration-300">
            <h2 class="font-extrabold text-[var(--text-main)] text-base md:text-lg flex items-center gap-2 mb-4">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span> How Escrow Works
            </h2>
            <div class="grid sm:grid-cols-3 gap-3 md:gap-4 text-sm">
                <div class="bg-[var(--bg-body)] border border-[var(--border)] rounded-xl p-4">
                    <strong class="text-[var(--primary)] block mb-1">1. Buyer Places Order</strong>
                    <span class="text-[var(--text-muted)] text-xs md:text-sm">Buyer submits payment securely. Money is held in escrow until completion.</span>
                </div>
                <div class="bg-[var(--bg-body)] border border-[var(--border)] rounded-xl p-4">
                    <strong class="text-[var(--primary)] block mb-1">2. Prepare & Deliver</strong>
                    <span class="text-[var(--text-muted)] text-xs md:text-sm">Process and dispatch the order, updating status so the buyer can track it.</span>
                </div>
                <div class="bg-[var(--bg-body)] border border-[var(--border)] rounded-xl p-4">
                    <strong class="text-[var(--primary)] block mb-1">3. Direct Payment Release</strong>
                    <span class="text-[var(--text-muted)] text-xs md:text-sm">Once the buyer confirms delivery, funds release straight to your MoMo account.</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== TAB: ORDERS ===== -->
    <div id="panel-orders" class="<?= $activeTab !== 'orders' ? 'hidden' : '' ?> space-y-6 animate-fadeIn">
        <?php if (empty($groups)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-10 md:p-16 text-center shadow-sm">
            <div class="w-16 h-16 rounded-full bg-[var(--bg-body)] flex items-center justify-center mx-auto mb-4 border border-[var(--border)]">
                <i class="ri-inbox-line text-2xl text-[var(--text-muted)]"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">No Orders Yet</h3>
            <p class="text-[var(--text-muted)] text-sm mb-6 max-w-sm mx-auto">Orders from buyers will appear here. Keep your listings updated!</p>
            <a href="add_product.php" class="inline-block bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition-all">Add a Listing</a>
        </div>
        <?php else: ?>
        <!-- Filter Bar -->
        <div class="flex gap-2 overflow-x-auto pb-1" style="scrollbar-width:none;">
            <?php
            $filters = ['all' => 'All', 'payment_confirmed' => 'New', 'preparing' => 'Preparing', 'in_transit' => 'In Transit', 'ready_for_pickup' => 'Pickup', 'delivered' => 'Delivered'];
            foreach ($filters as $fk => $fl):
            ?>
            <button type="button" onclick="filterOrders('<?= $fk ?>')" id="filter-<?= $fk ?>"
                class="filter-pill flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border transition-all
                <?= $fk === 'all' ? 'bg-[var(--primary-light)] text-[var(--primary)] border-[var(--primary)]' : 'bg-[var(--bg-card)] text-[var(--text-muted)] border-[var(--border)]' ?>">
                <?= $fl ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div id="ordersContainer" class="space-y-6">
            <?php foreach ($groups as $g): ?>
            <?php $sc = $statusConfig[$g['status']] ?? ['label' => $g['status'], 'color' => 'bg-gray-50 text-gray-800 border border-gray-200/30 dark:bg-gray-900 dark:text-gray-300', 'icon' => 'ri-circle-line']; ?>
            <div class="order-card bg-[var(--bg-card)] border-2 border-[var(--border)] rounded-2xl overflow-hidden shadow-sm transition-all duration-300" data-status="<?= htmlspecialchars($g['status']) ?>">
                <!-- Order Header -->
                <div class="p-4 md:p-5 border-b border-[var(--border)] bg-[var(--bg-body)] flex flex-col sm:flex-row gap-4 justify-between sm:items-center">
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="font-black text-[var(--text-main)] text-base md:text-lg"><?= htmlspecialchars($g['group_code']) ?></span>
                            <span class="inline-flex items-center gap-1 text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider <?= $sc['color'] ?>">
                                <i class="<?= $sc['icon'] ?> text-[10px]"></i> <?= $sc['label'] ?>
                            </span>
                        </div>
                        <p class="text-[11px] md:text-xs text-[var(--text-muted)] mt-1.5 font-medium">
                            <i class="ri-user-line"></i> <?= htmlspecialchars($g['buyer_name'] ?? 'Buyer') ?>
                            <span class="mx-1.5">|</span> <?= date('d M Y, h:i A', strtotime($g['order_date'])) ?>
                        </p>
                    </div>
                    <div class="sm:text-right flex sm:flex-col justify-between items-center sm:items-end gap-1 border-t sm:border-t-0 border-[var(--border)] pt-3 sm:pt-0">
                        <div class="text-lg md:text-xl font-black text-[var(--primary)]">GH₵ <?= number_format((float)array_sum(array_column($g['items'], 'subtotal')), 2) ?></div>
                        <div class="text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider"><?= count($g['items']) ?> item<?= count($g['items']) !== 1 ? 's' : '' ?></div>
                    </div>
                </div>

                <!-- Items List -->
                <div class="p-4 md:p-6 space-y-3">
                    <?php foreach ($g['items'] as $oi): ?>
                    <?php
                        $img = !empty($oi['photo']) ? "../uploads/produce/" . htmlspecialchars($oi['photo']) : "https://via.placeholder.com/60?text=?";
                        $ec  = $escrowConfig[$oi['escrow_status']] ?? ['label' => 'N/A', 'color' => 'text-[var(--text-muted)]', 'icon' => 'ri-circle-line'];
                    ?>
                    <div class="flex items-center gap-3 bg-[var(--bg-body)] border border-[var(--border)] rounded-xl p-2.5">
                        <img src="<?= $img ?>" alt="<?= htmlspecialchars($oi['produce_name']) ?>" class="w-11 h-11 object-cover rounded-lg flex-shrink-0 bg-[var(--bg-card)]" onerror="this.src='https://via.placeholder.com/60?text=Produce'">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-bold text-[var(--text-main)] truncate"><?= htmlspecialchars($oi['produce_name']) ?></div>
                            <div class="text-[11px] text-[var(--text-muted)]">Qty: <?= (int)$oi['quantity'] ?> · GH₵ <?= number_format((float)$oi['unit_price'], 2) ?>/bag</div>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-sm font-bold text-[var(--text-main)]">GH₵ <?= number_format((float)$oi['subtotal'], 2) ?></div>
                            <?php if (!empty($oi['escrow_status'])): ?>
                            <div class="text-[10px] font-bold <?= $ec['color'] ?>"><i class="<?= $ec['icon'] ?>"></i> <?= $ec['label'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Delivery Info -->
                <div class="px-4 md:px-6 pb-4 text-[12px] text-[var(--text-muted)] space-y-1">
                    <div><i class="ri-map-pin-line text-emerald-600 dark:text-emerald-400"></i> <strong class="text-[var(--text-main)]">Deliver to:</strong> <?= htmlspecialchars($g['delivery_name'] ?? '') ?>, <?= htmlspecialchars($g['delivery_address'] ?? '') ?></div>
                    <div><i class="ri-phone-line text-emerald-600 dark:text-emerald-400"></i> <?= htmlspecialchars($g['delivery_phone'] ?? '') ?></div>
                    <?php if (!empty($g['buyer_notes'])): ?>
                    <div class="italic"><i class="ri-chat-3-line"></i> "<?= htmlspecialchars($g['buyer_notes']) ?>"</div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <?php if (($g['payment_status'] ?? '') === 'confirmed' && !in_array($g['status'], ['delivered', 'cancelled'])): ?>
                <div class="px-4 md:px-6 pb-4 pt-3 border-t border-[var(--border)] flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-[var(--text-muted)] w-full">Update Order Status:</span>
                    <?php if ($g['status'] === 'payment_confirmed'): ?>
                    <button type="button" onclick="updateStatus(<?= (int)$g['id'] ?>, 'preparing', this)" class="flex-1 bg-[var(--primary)] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-[var(--primary-dark)] transition flex items-center justify-center gap-2">
                        <i class="ri-box-3-line"></i> Mark Preparing
                    </button>
                    <?php elseif ($g['status'] === 'preparing'): ?>
                    <button type="button" onclick="updateStatus(<?= (int)$g['id'] ?>, 'in_transit', this)" class="flex-1 bg-[var(--primary)] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-[var(--primary-dark)] transition flex items-center justify-center gap-2">
                        <i class="ri-truck-line"></i> Mark In Transit
                    </button>
                    <button type="button" onclick="updateStatus(<?= (int)$g['id'] ?>, 'ready_for_pickup', this)" class="flex-1 border border-[var(--border)] text-[var(--text-main)] px-4 py-2 rounded-xl text-xs font-bold hover:border-[var(--primary)] hover:text-[var(--primary)] transition flex items-center justify-center gap-2">
                        <i class="ri-store-line"></i> Ready Pickup
                    </button>
                    <?php elseif (in_array($g['status'], ['in_transit', 'ready_for_pickup'])): ?>
                    <span class="text-xs text-[var(--text-muted)] italic"><i class="ri-time-line"></i> Awaiting buyer delivery confirmation...</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: LISTINGS ===== -->
    <div id="panel-listings" class="<?= $activeTab !== 'listings' ? 'hidden' : '' ?> space-y-6 animate-fadeIn">
        <?php if (empty($listings)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-10 md:p-16 text-center shadow-sm">
            <div class="w-16 h-16 rounded-full bg-[var(--bg-body)] flex items-center justify-center mx-auto mb-4 border border-[var(--border)]">
                <i class="ri-plant-line text-2xl text-[var(--text-muted)]"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">No Active Listings</h3>
            <p class="text-[var(--text-muted)] text-sm mb-6 max-w-sm mx-auto">List your farm produce to begin receiving orders from buyers.</p>
            <a href="add_product.php" class="inline-block bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition-all">List Your Produce</a>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
            <?php foreach ($listings as $l): ?>
            <?php
                $img = !empty($l['photo']) ? "../uploads/produce/" . htmlspecialchars($l['photo']) : "https://via.placeholder.com/200?text=No+Image";
                $isActive = true;
                if (isset($l['status']) && in_array($l['status'], ['inactive', 'draft', 'hidden', 'deactivated'])) {
                    $isActive = false;
                } elseif (isset($l['is_active']) && (int)$l['is_active'] === 0) {
                    $isActive = false;
                }
            ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-all duration-300">
                <img src="<?= $img ?>" alt="<?= htmlspecialchars($l['produce_name']) ?>" class="w-full h-36 sm:h-40 object-cover bg-[var(--bg-body)]" onerror="this.src='https://via.placeholder.com/200?text=Produce'">
                <div class="p-4">
                    <div class="flex justify-between items-start gap-2 mb-1">
                        <h4 class="font-bold text-sm text-[var(--text-main)] truncate flex-1"><?= htmlspecialchars($l['produce_name']) ?></h4>
                        <span class="text-[9px] font-bold uppercase tracking-wider bg-[var(--bg-body)] text-[var(--text-muted)] border border-[var(--border)] px-2 py-0.5 rounded-full flex-shrink-0">
                            <?= htmlspecialchars($l['category_name'] ?? 'General') ?>
                        </span>
                    </div>
                    <div class="text-base font-black text-[var(--primary)] mb-3">
                        GH₵ <?= number_format((float)$l['price_per_bag'], 2) ?> <span class="text-[11px] font-normal text-[var(--text-muted)]">/ bag</span>
                    </div>
                    <div class="flex justify-between text-[11px] text-[var(--text-muted)] font-semibold bg-[var(--bg-body)] border border-[var(--border)] rounded-lg px-3 py-2 mb-3">
                        <span>Stock: <strong class="text-[var(--text-main)]"><?= (int)$l['bags_available'] ?></strong></span>
                        <span>Orders: <strong class="text-[var(--text-main)]"><?= (int)$l['total_orders'] ?></strong></span>
                    </div>
                    <div class="flex gap-2">
                        <a href="edit_produce.php?id=<?= (int)$l['id'] ?>" class="flex-1 text-center border border-[var(--border)] text-[var(--text-main)] px-3 py-2 rounded-xl text-xs font-bold hover:border-[var(--primary)] hover:text-[var(--primary)] transition">
                            <i class="ri-edit-line"></i> Edit
                        </a>
                        <button type="button" onclick="toggleListing(<?= (int)$l['id'] ?>, <?= $isActive ? 1 : 0 ?>, this)" class="flex-1 border border-[var(--border)] text-[var(--text-main)] px-3 py-2 rounded-xl text-xs font-bold hover:border-[var(--primary)] hover:text-[var(--primary)] transition">
                            <i class="ri-eye-<?= $isActive ? 'off' : 'line' ?>-line"></i> <?= $isActive ? 'Take Down' : 'Publish' ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: PROFILE ===== -->
    <div id="panel-profile" class="<?= $activeTab !== 'profile' ? 'hidden' : '' ?> space-y-6 animate-fadeIn">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">

            <!-- Profile Overview Card -->
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm flex flex-col items-center justify-between text-center self-start transition-colors duration-300">
                <div class="w-full">
                    <div class="w-20 h-20 md:w-24 md:h-24 rounded-full bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-3xl md:text-4xl font-extrabold mx-auto mb-4 shadow-md relative">
                        <?= strtoupper(substr($farmer['name'] ?? 'F', 0, 1)) ?>
                    </div>
                    <h2 class="font-extrabold text-lg md:text-xl text-[var(--text-main)] truncate"><?= htmlspecialchars($farmer['name'] ?? '') ?></h2>
                    <p class="text-xs md:text-sm text-[var(--text-muted)] mt-1 font-medium truncate"><?= htmlspecialchars($farmer['email'] ?? '') ?></p>
                    <span class="inline-block mt-3 text-[9px] md:text-[10px] bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 px-3.5 py-1 rounded-full font-bold uppercase tracking-wider border border-emerald-200/20">Seller Account</span>

                    <?php if (!empty($farmer['location'])): ?>
                    <p class="text-xs text-[var(--text-muted)] mt-4 flex items-center justify-center gap-1.5 font-medium">
                        <i class="ri-map-pin-line text-emerald-600 dark:text-emerald-400"></i> <?= htmlspecialchars($farmer['location']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div class="mt-6 w-full grid grid-cols-2 gap-3 md:gap-4 text-center border-t border-[var(--border)] pt-6">
                    <div class="bg-[var(--bg-body)] rounded-2xl p-3 md:p-4 border border-[var(--border)]">
                        <div class="text-xl md:text-2xl font-black text-[var(--text-main)]"><?= (int)$stats['total_orders'] ?></div>
                        <div class="text-[9px] md:text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Orders</div>
                    </div>
                    <div class="bg-[var(--bg-body)] rounded-2xl p-3 md:p-4 border border-[var(--border)]">
                        <div class="text-xl md:text-2xl font-black text-[var(--text-main)]"><?= (int)$stats['completed_orders'] ?></div>
                        <div class="text-[9px] md:text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Completed</div>
                    </div>
                </div>
            </div>

            <!-- Profile Form Column -->
            <div class="lg:col-span-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 md:p-8 shadow-sm transition-colors duration-300">
                <h2 class="font-extrabold text-base md:text-lg text-[var(--text-main)] mb-6 flex items-center gap-2">
                    <i class="ri-user-settings-line text-emerald-600 dark:text-emerald-400 text-lg md:text-xl"></i> Personal Information
                </h2>

                <?php if ($profileError): ?>
                <div class="mb-5 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 rounded-xl text-red-700 dark:text-red-400 text-sm flex items-center gap-2">
                    <i class="ri-error-warning-line text-red-600 dark:text-red-400"></i> <?= htmlspecialchars($profileError) ?>
                </div>
                <?php endif; ?>
                <?php if ($profileSuccess): ?>
                <div class="mb-5 p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 rounded-xl text-emerald-700 dark:text-emerald-400 text-sm flex items-center gap-2">
                    <i class="ri-checkbox-circle-line text-emerald-600 dark:text-emerald-400"></i> <?= htmlspecialchars($profileSuccess) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="seller_dashboard.php?tab=profile" class="space-y-5 md:space-y-6">
                    <input type="hidden" name="update_profile" value="1">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Full Name *</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($farmer['name'] ?? '') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Phone Number</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($farmer['phone'] ?? '') ?>" placeholder="0XX XXX XXXX"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">MoMo Number * <span class="normal-case font-medium text-amber-600 dark:text-amber-400">(Required for payouts)</span></label>
                            <input type="tel" name="momo_phone" required value="<?= htmlspecialchars($farmer['momo_phone'] ?? '') ?>" placeholder="0XX XXX XXXX"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Farm Location / Region</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($farmer['location'] ?? '') ?>" placeholder="e.g. Kumasi, Ashanti Region"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">About Your Farm / Bio</label>
                            <textarea name="profile_bio" rows="4" placeholder="Tell buyers about your farm, crop varieties, and delivery options..."
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition resize-none"><?= htmlspecialchars($farmer['profile_bio'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" class="w-full sm:w-auto bg-[var(--primary)] text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg">
                            <i class="ri-save-line"></i> Save Profile Changes
                        </button>
                    </div>
                </form>
            </div>
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

// --- Dark Mode Logic ---
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
        if (window.scrollY > 15) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    }
});

const csrfToken = '<?= csrf_token() ?>';

// --- Toast Helper ---
function showToast(msg, type = 'success') {
    let c = document.getElementById('toastContainer');
    if (!c) {
        c = document.createElement('div');
        c.id = 'toastContainer';
        c.className = 'fixed top-4 right-4 z-[9999] space-y-2 pointer-events-none';
        document.body.appendChild(c);
    }
    const t = document.createElement('div');
    const col = type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-blue-600';
    const ic = type === 'success' ? 'ri-check-line' : 'ri-error-warning-line';
    t.className = `pointer-events-auto flex items-center gap-3 ${col} text-white px-4 py-2.5 rounded-lg shadow-lg transform translate-x-full transition-all duration-300 max-w-[90vw]`;
    t.innerHTML = `<i class="${ic} text-base"></i><span class="font-semibold text-xs">${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => t.classList.remove('translate-x-full'));
    setTimeout(() => {
        t.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => t.remove(), 300);
    }, 3500);
}

// --- Tab Switching ---
function setTab(tabId) {
    const tabs = ['overview', 'orders', 'listings', 'profile'];
    tabs.forEach(t => {
        const panel = document.getElementById('panel-' + t);
        const btn = document.getElementById('tab-' + t);
        if (panel) panel.classList.toggle('hidden', t !== tabId);
        if (btn) btn.classList.toggle('active', t === tabId);
    });
    const url = new URL(window.location);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url);
}

// --- Filter Orders ---
function filterOrders(status) {
    document.querySelectorAll('.filter-pill').forEach(p => {
        p.classList.remove('bg-[var(--primary-light)]', 'text-[var(--primary)]', 'border-[var(--primary)]');
        p.classList.add('bg-[var(--bg-card)]', 'text-[var(--text-muted)]', 'border-[var(--border)]');
    });
    const activePill = document.getElementById('filter-' + status);
    if (activePill) {
        activePill.classList.remove('bg-[var(--bg-card)]', 'text-[var(--text-muted)]', 'border-[var(--border)]');
        activePill.classList.add('bg-[var(--primary-light)]', 'text-[var(--primary)]', 'border-[var(--primary)]');
    }

    document.querySelectorAll('.order-card').forEach(card => {
        card.style.display = (status === 'all' || card.getAttribute('data-status') === status) ? 'block' : 'none';
    });
}

// --- Update Order Group Status ---
async function updateStatus(groupId, status, btn) {
    if (!confirm(`Are you sure you want to mark this package as ${status.replace('_', ' ')}?`)) return;

    btn.disabled = true;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> ...';

    try {
        const res = await fetch('update_order_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ group_id: groupId, status: status, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Order status updated.', 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(data.message || 'Failed to update order status.', 'error');
            btn.disabled = false;
            btn.innerHTML = oldText;
        }
    } catch (err) {
        showToast('An error occurred. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = oldText;
    }
}

// --- Toggle Listing Visibility ---
async function toggleListing(produceId, currentActive, btn) {
    btn.disabled = true;
    const newStatus = currentActive ? 'inactive' : 'active';

    try {
        const res = await fetch('toggle_produce_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ produce_id: produceId, status: newStatus, csrf_token: csrfToken })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Listing updated.', 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(data.message || 'Failed to update listing.', 'error');
            btn.disabled = false;
        }
    } catch (err) {
        showToast('An error occurred. Please try again.', 'error');
        btn.disabled = false;
    }
}
</script>
</body>
</html>