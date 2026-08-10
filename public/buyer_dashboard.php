<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//buyer_dashboard.php
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: login.php'); exit; }

$pdo       = getPDO();
$user_role = $_SESSION['role'] ?? 'buyer';
$is_logged = true;
if ($user_role === 'farmer') { header('Location: seller_dashboard.php'); exit; }

// Cart count
$cStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart WHERE user_id=?");
$cStmt->execute([$user_id]);
$cart_count = (int)$cStmt->fetchColumn();

// Buyer profile
$buyer = $pdo->prepare("SELECT id,name,email,phone,momo_phone,location,profile_bio,created_at FROM buyers WHERE id=?");
$buyer->execute([$user_id]);
$buyer = $buyer->fetch(PDO::FETCH_ASSOC);

// Stats
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                                           AS total_orders,
        COALESCE(SUM(total_amount),0)                                     AS total_spent,
        SUM(order_status='delivered')                                     AS delivered,
        SUM(order_status IN ('pending_payment','payment_confirmed','preparing','in_transit','ready_for_pickup')) AS active
    FROM orders WHERE buyer_id=?
");
$stats->execute([$user_id]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

// All orders
$ordersStmt = $pdo->prepare("
    SELECT o.*,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.buyer_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$ordersStmt->execute([$user_id]);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle profile update
$profileError = ''; $profileSuccess = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_profile'])) {
    $name     = trim(filter_input(INPUT_POST,'name',FILTER_SANITIZE_SPECIAL_CHARS));
    $phone    = trim(filter_input(INPUT_POST,'phone',FILTER_SANITIZE_SPECIAL_CHARS));
    $momo     = trim(filter_input(INPUT_POST,'momo_phone',FILTER_SANITIZE_SPECIAL_CHARS));
    $location = trim(filter_input(INPUT_POST,'location',FILTER_SANITIZE_SPECIAL_CHARS));
    $bio      = trim(filter_input(INPUT_POST,'profile_bio',FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$name) { $profileError = 'Name is required.'; }
    else {
        $upd = $pdo->prepare("UPDATE buyers SET name=?,phone=?,momo_phone=?,location=?,profile_bio=? WHERE id=?");
        $upd->execute([$name,$phone,$momo,$location,$bio,$user_id]);
        $_SESSION['user_name'] = $name;
        $profileSuccess = 'Profile updated successfully!';
        $buyer['name']=$name; $buyer['phone']=$phone; $buyer['momo_phone']=$momo;
        $buyer['location']=$location; $buyer['profile_bio']=$bio;
    }
}

// Active tab
$activeTab = $_GET['tab'] ?? 'overview';
$highlight = filter_input(INPUT_GET,'order_id',FILTER_VALIDATE_INT);

// Status config optimized for light and dark modes (High Contrast colors)
$statusConfig = [
    'pending_payment'   => ['label'=>'Pending Payment',   'color'=>'bg-amber-50 text-amber-800 border border-amber-200/50 dark:bg-amber-950/30 dark:text-amber-300 dark:border-amber-900/40', 'icon'=>'ri-time-line'],
    'payment_confirmed' => ['label'=>'Confirmed', 'color'=>'bg-blue-50 text-blue-800 border border-blue-200/50 dark:bg-blue-950/30 dark:text-blue-300 dark:border-blue-900/40',    'icon'=>'ri-check-double-line'],
    'preparing'         => ['label'=>'Preparing',   'color'=>'bg-purple-50 text-purple-800 border border-purple-200/50 dark:bg-purple-950/30 dark:text-purple-300 dark:border-purple-900/40','icon'=>'ri-box-3-line'],
    'in_transit'        => ['label'=>'In Transit',        'color'=>'bg-orange-50 text-orange-800 border border-orange-200/50 dark:bg-orange-950/30 dark:text-orange-300 dark:border-orange-900/40','icon'=>'ri-truck-line'],
    'ready_for_pickup'  => ['label'=>'Ready for Pickup',  'color'=>'bg-cyan-50 text-cyan-800 border border-cyan-200/50 dark:bg-cyan-950/30 dark:text-cyan-300 dark:border-cyan-900/40',    'icon'=>'ri-store-line'],
    'delivered'         => ['label'=>'Delivered',         'color'=>'bg-emerald-50 text-emerald-800 border border-emerald-200/50 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900/40',  'icon'=>'ri-checkbox-circle-line'],
    'cancelled'         => ['label'=>'Cancelled',         'color'=>'bg-rose-50 text-rose-800 border border-rose-200/50 dark:bg-rose-950/30 dark:text-rose-300 dark:border-rose-900/40',      'icon'=>'ri-close-circle-line'],
];

$trackingSteps = [
    'pending_payment'   => 0,
    'payment_confirmed' => 1,
    'preparing'         => 2,
    'in_transit'        => 3,
    'ready_for_pickup'  => 3,
    'delivered'         => 4,
];

// Reusable prepared statements for scoped order items, groups, and tracking
$groupsStmt = $pdo->prepare("
    SELECT og.*, u.name AS farmer_name
    FROM order_groups og
    JOIN users u ON u.id = og.farmer_id
    WHERE og.order_id = ?
    ORDER BY og.id
");

$oiStmt = $pdo->prepare("
    SELECT oi.*, p.produce_name, p.photo
    FROM order_items oi
    JOIN produce_listings p ON oi.produce_id=p.id
    WHERE oi.order_group_id=?
");

$trackStmt = $pdo->prepare("
    SELECT * FROM order_tracking 
    WHERE order_group_id=? 
    ORDER BY created_at ASC
");

$page_title = 'My Dashboard | AgroMarket';
$active_nav = 'dashboard';
include 'nav.php';
?>

<!-- Fonts & RemixIcons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    agro: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' },
                    jumia: { orange: '#f68b1e', blue: '#264996' } 
                },
                fontFamily: {
                    sans: ['Plus Jakarta Sans', 'sans-serif']
                }
            }
        }
    }
</script>

<style>
:root {
    /* Variables synchronized directly with index.php & shop.php */
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
    /* Dark Variables synchronized directly with index.php & shop.php */
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

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg-body);
    color: var(--text-main);
    transition: background 0.3s ease, color 0.3s ease;
}

.tab-btn { 
    padding: 0.625rem 1rem; 
    font-size: 0.8125rem; 
    font-weight: 600; 
    border-radius: 0.75rem; 
    transition: all 0.2s ease-in-out; 
    display: inline-flex; 
    align-items: center; 
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
}
.tab-btn.active { 
    background: var(--primary); 
    color: #fff; 
    box-shadow: 0 4px 14px rgba(22, 163, 74, 0.2); 
}
.tab-btn:not(.active) { 
    color: var(--text-muted); 
}
.tab-btn:not(.active):hover { 
    background: var(--primary-light); 
    color: var(--text-main); 
}
.step-line { 
    flex: 1; 
    height: 3px; 
    border-radius: 9999px;
}
@media (min-width: 768px) {
    .tab-btn {
        width: auto;
        font-size: 0.875rem;
        padding: 0.625rem 1.25rem;
    }
}
</style>

<div class="pt-24 md:pt-32 pb-24 md:pb-16 min-h-screen px-3 sm:px-6 max-w-6xl mx-auto">

    <!-- Header Section -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-4 sm:p-6 md:p-8 shadow-sm mb-6 md:mb-8 flex flex-col md:flex-row items-center justify-between gap-5 text-center md:text-left transition-colors duration-300">
        <div class="flex flex-col md:flex-row items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-2xl font-extrabold shadow-md flex-shrink-0">
                <?= strtoupper(substr($buyer['name'],0,1)) ?>
            </div>
            <div>
                <h1 class="text-xl md:text-2xl font-extrabold text-[var(--text-main)] leading-tight flex items-center justify-center md:justify-start gap-2">
                    Welcome back, <?= htmlspecialchars(explode(' ',$buyer['name'])[0]) ?>
                </h1>
                <p class="text-[var(--text-muted)] text-xs mt-1 font-medium flex items-center justify-center md:justify-start gap-1.5">
                    <i class="ri-calendar-line text-emerald-600 dark:text-emerald-400"></i> Member since <?= date('F Y', strtotime($buyer['created_at'])) ?>
                </p>
            </div>
        </div>
        <a href="shop.php" class="w-full md:w-auto bg-[var(--primary)] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg flex items-center justify-center gap-2">
            <i class="ri-store-2-line text-base text-white"></i> Shop Products
        </a>
    </div>

    <!-- Tab Container -->
    <div class="grid grid-cols-2 md:flex md:flex-row gap-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-1.5 mb-6 md:mb-8 w-full shadow-sm transition-colors duration-300">
        <button onclick="setTab('overview')"  id="tab-overview"  class="tab-btn <?= $activeTab==='overview'?'active':'' ?>">
            <i class="ri-dashboard-line text-base"></i><span>Overview</span>
        </button>
        <button onclick="setTab('orders')"    id="tab-orders"    class="tab-btn <?= $activeTab==='orders'?'active':'' ?>">
            <i class="ri-file-list-3-line text-base"></i><span>My Orders</span>
        </button>
        <button onclick="setTab('profile')"   id="tab-profile"   class="tab-btn <?= $activeTab==='profile'?'active':'' ?>">
            <i class="ri-user-line text-base"></i><span>Profile</span>
        </button>
        <a href="market_disputes.php" class="tab-btn text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 font-semibold justify-center">
            <i class="ri-scales-3-line text-base"></i><span>Disputes</span>
        </a>
    </div>

    <!-- ===== TAB: OVERVIEW ===== -->
    <div id="panel-overview" class="<?= $activeTab!=='overview'?'hidden':'' ?> space-y-6 animate-fadeIn">
        <!-- Stat Cards Grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-6">
            <?php
            $cards = [
                ['icon'=>'ri-shopping-bag-3-line','color'=>'text-blue-600 bg-blue-50 dark:bg-blue-950/40 dark:text-blue-300','label'=>'Total Orders','value'=>$stats['total_orders']],
                ['icon'=>'ri-wallet-3-line','color'=>'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 dark:text-emerald-300','label'=>'Total Spent','value'=>'₵ '.number_format($stats['total_spent'],2)],
                ['icon'=>'ri-truck-line','color'=>'text-orange-600 bg-orange-50 dark:bg-orange-950/40 dark:text-orange-300','label'=>'Active Shipments','value'=>$stats['active']],
                ['icon'=>'ri-checkbox-circle-line','color'=>'text-purple-600 bg-purple-50 dark:bg-purple-950/40 dark:text-purple-300','label'=>'Completed Delivery','value'=>$stats['delivered']],
            ];
            foreach($cards as $c): ?>
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

        <!-- Recent Activity Section -->
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl shadow-sm overflow-hidden transition-colors duration-300">
            <div class="px-4 py-4 border-b border-[var(--border)] flex justify-between items-center">
                <h2 class="font-extrabold text-[var(--text-main)] text-base md:text-lg flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-600 dark:bg-emerald-400"></span> Recent Orders
                </h2>
                <button onclick="setTab('orders')" class="text-xs md:text-sm text-emerald-600 dark:text-emerald-400 font-bold hover:underline">View All</button>
            </div>
            
            <?php if(empty($orders)): ?>
            <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-[var(--bg-body)] flex items-center justify-center mx-auto mb-4 border border-[var(--border)]">
                    <i class="ri-shopping-bag-3-line text-2xl text-[var(--text-muted)]"></i>
                </div>
                <p class="font-semibold text-sm text-[var(--text-muted)]">No purchases recorded yet</p>
                <a href="shop.php" class="inline-block mt-4 bg-[var(--primary-light)] text-[var(--primary)] px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--primary)] hover:text-white transition-all duration-200">Start Shopping</a>
            </div>
            <?php else: ?>
            <div class="divide-y divide-[var(--border)]">
                <?php foreach(array_slice($orders,0,5) as $o): ?>
                <?php $sc = $statusConfig[$o['order_status']] ?? ['label'=>$o['order_status'],'color'=>'bg-gray-50 text-gray-800 border border-gray-200/30 dark:bg-gray-900 dark:text-gray-300','icon'=>'ri-circle-line']; ?>
                <div class="px-4 py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 hover:bg-[var(--primary-light)]/20 transition-all duration-200">
                    <div class="flex items-center gap-3 w-full min-w-0">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 <?= $sc['color'] ?>">
                            <i class="<?= $sc['icon'] ?> text-base"></i>
                        </div>
                        <div class="min-w-0 flex-grow">
                            <div class="text-sm font-bold text-[var(--text-main)] flex items-center gap-1.5">
                                Order #<?= $o['id'] ?>
                            </div>
                            <div class="text-[11px] text-[var(--text-muted)] mt-0.5 flex items-center gap-1.5 font-medium flex-wrap">
                                <span><?= $o['item_count'] ?> item<?= $o['item_count']!=1?'s':'' ?></span>
                                <span class="text-[var(--text-muted)]">•</span>
                                <span><?= date('d M Y', strtotime($o['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-row sm:flex-col items-center sm:items-end justify-between sm:justify-center gap-2 w-full sm:w-auto border-t sm:border-t-0 border-[var(--border)] pt-2 sm:pt-0">
                        <div class="text-sm sm:text-base font-extrabold text-[var(--text-main)]">₵<?= number_format($o['total_amount'],2) ?></div>
                        <span class="inline-flex items-center gap-1 text-[9px] md:text-[10px] px-2 py-0.5 md:py-1 rounded-full font-bold uppercase tracking-wider <?= $sc['color'] ?>">
                            <i class="<?= $sc['icon'] ?> text-[9px]"></i> <?= $sc['label'] ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== TAB: ORDERS ===== -->
    <div id="panel-orders" class="<?= $activeTab!=='orders'?'hidden':'' ?> space-y-6 animate-fadeIn">
        <?php if(empty($orders)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-10 md:p-16 text-center shadow-sm">
            <div class="w-16 h-16 rounded-full bg-[var(--bg-body)] flex items-center justify-center mx-auto mb-4 border border-[var(--border)]">
                <i class="ri-shopping-cart-2-line text-2xl text-[var(--text-muted)]"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">Your Order Shelf is Empty</h3>
            <p class="text-[var(--text-muted)] text-sm mb-6 max-w-sm mx-auto">Your physical produce shipments will show up here as soon as you place an order.</p>
            <a href="shop.php" class="inline-block bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition-all">Browse Produce</a>
        </div>
        <?php else: ?>
        <div class="space-y-6">
            <?php foreach($orders as $o): ?>
            <?php
                $isNew = ($highlight == $o['id']);

                // Fetch database order groups directly for this order
                $groupsStmt->execute([$o['id']]);
                $orderGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

                $farmerPackages = [];
                foreach ($orderGroups as $g) {
                    $oiStmt->execute([$g['id']]);
                    $g['items'] = $oiStmt->fetchAll(PDO::FETCH_ASSOC);
                    $farmerPackages[$g['id']] = $g;
                }
            ?>
            <div class="bg-[var(--bg-card)] border-2 <?= $isNew?'border-[var(--primary)] shadow-md':'border-[var(--border)] shadow-sm' ?> rounded-2xl overflow-hidden transition-all duration-300">
                <!-- Order Header -->
                <div class="p-4 md:p-5 border-b border-[var(--border)] bg-[var(--bg-body)] flex flex-col sm:flex-row gap-4 justify-between sm:items-center">
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="font-black text-[var(--text-main)] text-base md:text-lg">Order ID: #<?= $o['id'] ?></span>
                            <?php if($isNew): ?><span class="text-[9px] bg-[var(--primary)] text-white px-2 py-0.5 rounded-full font-black tracking-wide animate-pulse">HIGHLIGHT</span><?php endif; ?>
                        </div>
                        <p class="text-[11px] md:text-xs text-[var(--text-muted)] mt-1.5 font-medium">
                            Date: <span class="text-[var(--text-main)]"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                            <span class="text-[var(--text-muted)] mx-1.5">|</span>
                            Packages: <span class="text-[var(--text-main)] font-semibold"><?= count($farmerPackages) ?> source vendor(s)</span>
                        </p>
                    </div>
                    <div class="sm:text-right flex sm:flex-col justify-between items-center sm:items-end gap-1 border-t sm:border-t-0 border-[var(--border)] pt-3 sm:pt-0">
                        <div class="text-lg md:text-xl font-black text-[var(--text-main)]">₵<?= number_format($o['total_amount'],2) ?></div>
                        <div class="text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider"><?= str_replace('_',' ',$o['payment_method']) ?></div>
                    </div>
                </div>

                <!-- Farmer Packages Section -->
                <div class="divide-y-4 divide-[var(--border)]">
                    <?php foreach ($farmerPackages as $gId => $pkg): ?>
                    <?php 
                        // Map internal group package status directly to style configurations
                        $mappedStatus = $pkg['status'] ?? 'pending_payment';
                        $sc = $statusConfig[$mappedStatus] ?? ['label'=>$pkg['status'],'color'=>'bg-gray-50 text-gray-800 border border-gray-200/30 dark:bg-gray-900 dark:text-gray-300','icon'=>'ri-circle-line'];
                        $stepIndex = $trackingSteps[$mappedStatus] ?? 0;
                        $canConfirmPkg = in_array($pkg['status'], ['in_transit', 'ready_for_pickup']) && $o['payment_status'] === 'confirmed';

                        // Fetch package-specific tracking details
                        $trackStmt->execute([$pkg['id']]);
                        $allTrackHistory = $trackStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="p-4 md:p-6 bg-[var(--bg-card)] space-y-4">
                        <!-- Package Header / Group Code Meta -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 bg-[var(--bg-body)] p-3 rounded-xl border border-[var(--border)]">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-[var(--primary)]"></span>
                                <span class="text-xs font-bold text-[var(--text-main)]">
                                    Package <span class="text-[var(--primary)] font-extrabold"><?= htmlspecialchars($pkg['group_code']) ?></span> from <?= htmlspecialchars($pkg['farmer_name']) ?>
                                </span>
                            </div>
                            <span class="inline-flex items-center gap-1 text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider <?= $sc['color'] ?>">
                                <i class="<?= $sc['icon'] ?> text-[10px]"></i> <?= $sc['label'] ?>
                            </span>
                        </div>

                        <!-- Package Stepper Tracking -->
                        <?php if($pkg['status'] !== 'cancelled'): ?>
                        <div class="py-3 px-2 border border-[var(--border)] rounded-2xl bg-[var(--bg-body)]">
                            <?php
                            $steps = [
                                ['icon'=>'ri-wallet-3-line',      'label'=>'Paid'],
                                ['icon'=>'ri-check-double-line',  'label'=>'Verified'],
                                ['icon'=>'ri-box-3-line',         'label'=>'Prepping'],
                                ['icon'=>'ri-truck-line',         'label'=>'Dispatched'],
                                ['icon'=>'ri-home-heart-line',    'label'=>'Delivered'],
                            ];
                            ?>
                            <!-- Desktop Stepper view -->
                            <div class="hidden md:flex items-center relative py-2">
                                <?php foreach($steps as $si => $step): ?>
                                <?php $done = $si <= $stepIndex; $current = $si === $stepIndex; ?>
                                <div class="flex flex-col items-center flex-shrink-0 z-10">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300
                                        <?= $done ? 'bg-emerald-600 dark:bg-emerald-500 text-white shadow-md shadow-emerald-500/10' : 'bg-[var(--bg-card)] border border-[var(--border)] text-[var(--text-muted)]' ?>
                                        <?= $current ? 'ring-4 ring-emerald-500/20 dark:ring-emerald-500/10 scale-105' : '' ?>">
                                        <i class="<?= $step['icon'] ?> text-sm"></i>
                                    </div>
                                    <span class="text-[9px] mt-1.5 font-bold uppercase tracking-wide <?= $done?'text-emerald-600 dark:text-emerald-400':'text-[var(--text-muted)]' ?> text-center w-14">
                                        <?= $step['label'] ?>
                                    </span>
                                </div>
                                <?php if($si < count($steps)-1): ?>
                                <div class="step-line <?= $si < $stepIndex?'bg-emerald-600 dark:bg-emerald-500':'bg-[var(--border)]' ?> mx-1"></div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Mobile Progressive Stepper view -->
                            <div class="flex md:hidden flex-col gap-2">
                                <div class="grid grid-cols-5 gap-1.5">
                                    <?php foreach($steps as $si => $step): ?>
                                    <?php $done = $si <= $stepIndex; $current = $si === $stepIndex; ?>
                                    <div class="flex flex-col items-center text-center">
                                        <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300
                                            <?= $done ? 'bg-emerald-600 dark:bg-emerald-500 text-white shadow-sm' : 'bg-[var(--bg-card)] border border-[var(--border)] text-[var(--text-muted)]' ?>
                                            <?= $current ? 'ring-2 ring-emerald-500/20 scale-105' : '' ?>">
                                            <i class="<?= $step['icon'] ?> text-[10px]"></i>
                                        </div>
                                        <span class="text-[7px] mt-1 font-bold uppercase tracking-tight leading-none <?= $done?'text-emerald-600 dark:text-emerald-400':'text-[var(--text-muted)]' ?>">
                                            <?= $step['label'] ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Items in this Package -->
                        <div class="divide-y divide-[var(--border)] border-t border-b border-[var(--border)] py-2">
                            <?php foreach($pkg['items'] as $oi): ?>
                            <?php $img = !empty($oi['photo']) ? "../uploads/produce/".htmlspecialchars($oi['photo']) : "https://via.placeholder.com/60?text=?"; ?>
                            <div class="flex items-center gap-3 md:gap-4 py-3 first:pt-0 last:pb-0">
                                <div class="w-12 h-12 rounded-xl overflow-hidden bg-white dark:bg-slate-800 border border-[var(--border)] flex-shrink-0 flex items-center justify-center p-1">
                                    <img src="<?= $img ?>" alt="<?= htmlspecialchars($oi['produce_name']) ?>" class="w-full h-full object-cover rounded-lg">
                                </div>
                                <div class="flex-grow min-w-0">
                                    <p class="text-xs md:text-sm font-extrabold text-[var(--text-main)] truncate"><?= htmlspecialchars($oi['produce_name']) ?></p>
                                    <p class="text-[10px] md:text-xs text-[var(--text-muted)] mt-0.5 font-medium truncate">Qty: <span class="text-[var(--text-main)] font-semibold"><?= $oi['quantity'] ?></span></p>
                                </div>
                                <div class="text-xs md:text-sm font-extrabold text-[var(--text-main)] flex-shrink-0">₵<?= number_format($oi['subtotal'],2) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Package Confirmation Status / Timeline details -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-[var(--border)] pb-4">
                            <div class="text-[10px] text-[var(--text-muted)] font-semibold">
                                * Individual package milestones update independently depending on seller dispatch schedules.
                            </div>
                            <div>
                                <?php if($canConfirmPkg): ?>
                                <button onclick="confirmDelivery(<?= $gId ?>, this)"
                                    class="w-full sm:w-auto bg-green-600 text-white px-5 py-2 rounded-xl text-xs font-bold hover:bg-green-700 transition flex items-center justify-center gap-2 shadow-md">
                                    <i class="ri-checkbox-circle-line text-sm text-white"></i> Confirm Package Receipt
                                </button>
                                <?php elseif($pkg['status'] === 'delivered'): ?>
                                <span class="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400 font-extrabold uppercase tracking-wider py-1">
                                    <i class="ri-checkbox-circle-fill text-sm"></i> Package Received
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Scoped Activity Logs for this Package Group -->
                        <details class="group">
                            <summary class="text-[11px] text-[var(--text-muted)] cursor-pointer hover:text-emerald-600 dark:hover:text-emerald-400 font-bold flex items-center gap-2 transition-all duration-200">
                                <i class="ri-history-line"></i> Activity Logs & History
                                <i class="ri-arrow-down-s-line text-[var(--text-muted)] transition ml-auto group-open:rotate-180"></i>
                            </summary>
                            <div class="pb-2 pt-3 pl-2 space-y-3 bg-[var(--bg-body)] rounded-xl border border-dashed border-[var(--border)] mt-2">
                                <?php if(empty($allTrackHistory)): ?>
                                    <p class="text-[10px] text-[var(--text-muted)] italic pl-1">No transaction status updates logged yet.</p>
                                <?php else: ?>
                                    <?php foreach(array_reverse($allTrackHistory) as $th): ?>
                                    <div class="flex gap-2.5 text-[11px]">
                                        <div class="w-1.5 h-1.5 rounded-full bg-emerald-600 dark:bg-emerald-400 mt-1.5 flex-shrink-0"></div>
                                        <div>
                                            <p class="font-bold text-[var(--text-main)] capitalize"><?= str_replace('_',' ',$th['status']) ?></p>
                                            <?php if($th['notes']): ?><p class="text-[var(--text-muted)] font-medium mt-0.5"><?= htmlspecialchars($th['notes']) ?></p><?php endif; ?>
                                            <p class="text-[var(--text-muted)] text-[9px] mt-0.5 font-semibold"><?= date('d M Y, h:i A', strtotime($th['created_at'])) ?></p>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Shipping Metadata & Global Actions -->
                <div class="px-4 py-4 flex flex-col sm:flex-row gap-4 justify-between sm:items-center border-t border-[var(--border)] bg-[var(--bg-body)]">
                    <div class="text-[11px] md:text-xs text-[var(--text-muted)] flex items-start gap-2 max-w-md w-full">
                        <i class="ri-map-pin-line text-emerald-600 dark:text-emerald-400 text-sm mt-0.5 flex-shrink-0"></i>
                        <div class="min-w-0">
                            <p class="font-bold text-[var(--text-main)] truncate"><?= htmlspecialchars($o['delivery_name']) ?></p>
                            <p class="font-medium mt-0.5 text-[10px] md:text-[11px] leading-relaxed truncate"><?= htmlspecialchars($o['delivery_address']) ?> · <?= htmlspecialchars($o['delivery_phone']) ?></p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full sm:w-auto border-t sm:border-t-0 border-[var(--border)] pt-3 sm:pt-0">
                        <a href="market_disputes.php" class="text-xs text-red-600 dark:text-red-400 font-extrabold hover:text-red-700 dark:hover:text-red-300 uppercase tracking-wider flex items-center gap-1 justify-center py-2 sm:py-0 transition">
                            <i class="ri-alert-line text-current"></i> Dispute Order
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: PROFILE ===== -->
    <div id="panel-profile" class="<?= $activeTab!=='profile'?'hidden':'' ?> space-y-6 animate-fadeIn">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">

            <!-- Profile Overview Card -->
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm flex flex-col items-center justify-between text-center self-start transition-colors duration-300">
                <div class="w-full">
                    <div class="w-20 h-20 md:w-24 md:h-24 rounded-full bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-3xl md:text-4xl font-extrabold mx-auto mb-4 shadow-md relative">
                        <?= strtoupper(substr($buyer['name'],0,1)) ?>
                    </div>
                    <h2 class="font-extrabold text-lg md:text-xl text-[var(--text-main)] truncate"><?= htmlspecialchars($buyer['name']) ?></h2>
                    <p class="text-xs md:text-sm text-[var(--text-muted)] mt-1 font-medium truncate"><?= htmlspecialchars($buyer['email']) ?></p>
                    <span class="inline-block mt-3 text-[9px] md:text-[10px] bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 px-3.5 py-1 rounded-full font-bold uppercase tracking-wider border border-emerald-200/20">Buyer Account</span>

                    <?php if($buyer['location']): ?>
                    <p class="text-xs text-[var(--text-muted)] mt-4 flex items-center justify-center gap-1.5 font-medium">
                        <i class="ri-map-pin-line text-emerald-600 dark:text-emerald-400"></i> <?= htmlspecialchars($buyer['location']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div class="mt-6 w-full grid grid-cols-2 gap-3 md:gap-4 text-center border-t border-[var(--border)] pt-6">
                    <div class="bg-[var(--bg-body)] rounded-2xl p-3 md:p-4 border border-[var(--border)]">
                        <div class="text-xl md:text-2xl font-black text-[var(--text-main)]"><?= $stats['total_orders'] ?></div>
                        <div class="text-[9px] md:text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Orders</div>
                    </div>
                    <div class="bg-[var(--bg-body)] rounded-2xl p-3 md:p-4 border border-[var(--border)]">
                        <div class="text-xl md:text-2xl font-black text-[var(--text-main)]"><?= $stats['delivered'] ?></div>
                        <div class="text-[9px] md:text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Delivered</div>
                    </div>
                </div>
            </div>

            <!-- Profile Form Column -->
            <div class="lg:col-span-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 md:p-8 shadow-sm transition-colors duration-300">
                <h2 class="font-extrabold text-base md:text-lg text-[var(--text-main)] mb-6 flex items-center gap-2">
                    <i class="ri-user-settings-line text-emerald-600 dark:text-emerald-400 text-lg md:text-xl"></i> Personal Settings
                </h2>

                <?php if($profileError): ?>
                <div class="mb-5 p-4 bg-red-50 dark:bg-red-950/20 border border-red-200/50 rounded-xl text-red-700 dark:text-red-400 text-sm flex items-center gap-2">
                    <i class="ri-error-warning-line text-red-600 dark:text-red-400"></i> <?= htmlspecialchars($profileError) ?>
                </div>
                <?php endif; ?>
                <?php if($profileSuccess): ?>
                <div class="mb-5 p-4 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200/50 rounded-xl text-emerald-700 dark:text-emerald-400 text-sm flex items-center gap-2">
                    <i class="ri-checkbox-circle-line text-emerald-600 dark:text-emerald-400"></i> <?= htmlspecialchars($profileSuccess) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="buyer_dashboard.php?tab=profile" class="space-y-5 md:space-y-6">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-5">
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Full Name *</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($buyer['name']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Email Address (Read-only)</label>
                            <input type="email" value="<?= htmlspecialchars($buyer['email']??'') ?>" disabled
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] opacity-60 text-[var(--text-muted)] cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Phone Contact</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($buyer['phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="e.g. 0244000000">
                        </div>
                        <div>
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Mobile Money Number</label>
                            <input type="tel" name="momo_phone" value="<?= htmlspecialchars($buyer['momo_phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="For expedited checkout">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Delivery Location & Address</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($buyer['location']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="City / District / Landmark details">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] md:text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Profile Bio & Extra Notes</label>
                            <textarea name="profile_bio" rows="4"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-base md:text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition resize-none"
                                placeholder="Add preferences or shipping details for vendors..."><?= htmlspecialchars($buyer['profile_bio']??'') ?></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="w-full sm:w-auto bg-[var(--primary)] text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg">
                            Apply Updates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Nav -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bg-[var(--bg-card)] border-t border-[var(--border)] z-50 flex justify-between items-center px-6 py-2.5 text-[10px] font-bold tracking-wide uppercase shadow-2xl transition-colors duration-200">
    <a href="index.php" class="flex flex-col items-center gap-1 text-[var(--text-muted)] hover:text-[var(--primary)] transition">
        <i class="ri-home-4-line text-xl transition"></i>
        <span>Home</span>
    </a>
    <a href="shop.php" class="flex flex-col items-center gap-1 text-[var(--text-muted)] hover:text-[var(--primary)] transition">
        <i class="ri-store-2-line text-xl transition"></i>
        <span>Shop</span>
    </a>
    <a href="wishlist.php" class="flex flex-col items-center gap-1 text-[var(--text-muted)] hover:text-[var(--primary)] transition">
        <i class="ri-heart-3-line text-xl transition"></i>
        <span>Saved</span>
    </a>
    <a href="buyer_dashboard.php" class="flex flex-col items-center gap-1 text-[var(--primary)] transition">
        <i class="ri-user-fill text-xl transition"></i>
        <span>Account</span>
    </a>
</nav>

<script>
// LocalStorage Theme sync
if (localStorage.getItem('theme') === 'dark') {
    document.body.classList.add('dark');
}

const csrfToken = '<?= $_SESSION['csrf_token'] ?? (function_exists('get_csrf_token') ? get_csrf_token() : (function_exists('csrf_token') ? csrf_token() : '')) ?>';

if (typeof showToast !== 'function') {
    window.showToast = function(message, type) {
        alert(message);
    };
}

function setTab(tab) {
    ['overview','orders','profile'].forEach(t => {
        const panel = document.getElementById('panel-'+t);
        const button = document.getElementById('tab-'+t);
        if (panel) panel.classList.toggle('hidden', t !== tab);
        if (button) button.classList.toggle('active', t === tab);
    });
    history.replaceState(null,'','?tab='+tab);
}

async function confirmDelivery(groupId, btn) {
    if (!confirm('Are you sure you want to confirm receipt of this package? Confirming will finalize payment verification for this vendor.')) return;

    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin text-sm"></i> Wait...';

    const payload = {
        group_id: groupId,
        csrf_token: csrfToken
    };

    try {
        const res = await fetch('confirm_delivery.php', { 
            method: 'POST', 
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            showToast('Receipt confirmed. Transaction finalized.', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast(data.message || 'Error processing confirmation', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch(e) {
        showToast('Connection timed out', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>
</body>
</html>