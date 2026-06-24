<?php
session_start();
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

// Status config
$statusConfig = [
    'pending_payment'   => ['label'=>'Pending Payment',   'color'=>'bg-amber-50 text-amber-700 border border-amber-200/30', 'icon'=>'ri-time-line'],
    'payment_confirmed' => ['label'=>'Confirmed', 'color'=>'bg-blue-50 text-blue-700 border border-blue-200/30',    'icon'=>'ri-check-double-line'],
    'preparing'         => ['label'=>'Preparing',   'color'=>'bg-purple-50 text-purple-700 border border-purple-200/30','icon'=>'ri-box-3-line'],
    'in_transit'        => ['label'=>'In Transit',        'color'=>'bg-orange-50 text-orange-700 border border-orange-200/30','icon'=>'ri-truck-line'],
    'ready_for_pickup'  => ['label'=>'Ready for Pickup',  'color'=>'bg-cyan-50 text-cyan-700 border border-cyan-200/30',    'icon'=>'ri-store-line'],
    'delivered'         => ['label'=>'Delivered',         'color'=>'bg-emerald-50 text-emerald-700 border border-emerald-200/30',  'icon'=>'ri-checkbox-circle-line'],
    'cancelled'         => ['label'=>'Cancelled',         'color'=>'bg-rose-50 text-rose-700 border border-rose-200/30',      'icon'=>'ri-close-circle-line'],
];

$trackingSteps = [
    'pending_payment'   => 0,
    'payment_confirmed' => 1,
    'preparing'         => 2,
    'in_transit'        => 3,
    'ready_for_pickup'  => 3,
    'delivered'         => 4,
];

$page_title = 'My Dashboard | AgroMarket';
$active_nav = 'dashboard';
include 'nav.php';
?>

<style>
.tab-btn { 
    padding: 0.625rem 1.25rem; 
    font-size: 0.875rem; 
    font-weight: 600; 
    border-radius: 0.75rem; 
    transition: all 0.2s ease-in-out; 
    display: inline-flex; 
    align-items: center; 
    gap: 0.5rem;
}
.tab-btn.active { 
    background: var(--primary); 
    color: #fff; 
    box-shadow: 0 4px 14px rgba(22, 163, 74, 0.15); 
}
.tab-btn:not(.active) { 
    color: var(--text-muted); 
}
.tab-btn:not(.active):hover { 
    background: var(--border); 
    color: var(--text-main); 
}
.step-line { 
    flex: 1; 
    height: 3px; 
    border-radius: 9999px;
}
</style>

<div class="pt-28 md:pt-32 pb-16 min-h-screen px-4 sm:px-6 max-w-6xl mx-auto">

    <!-- Header Section -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 md:p-8 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-2xl font-extrabold shadow-md">
                <?= strtoupper(substr($buyer['name'],0,1)) ?>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-[var(--text-main)] leading-tight flex items-center gap-2">
                    Welcome back, <?= htmlspecialchars(explode(' ',$buyer['name'])[0]) ?>
                </h1>
                <p class="text-[var(--text-muted)] text-xs mt-1 font-medium flex items-center gap-1.5">
                    <i class="ri-calendar-line text-[var(--primary)]"></i> Member since <?= date('F Y', strtotime($buyer['created_at'])) ?>
                </p>
            </div>
        </div>
        <a href="shop.php" class="bg-[var(--primary)] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg flex items-center justify-center gap-2">
            <i class="ri-store-2-line text-base"></i> Shop Products
        </a>
    </div>

    <!-- Tab Container -->
    <div class="flex gap-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-1.5 mb-8 w-full sm:w-auto overflow-x-auto shadow-sm">
        <button onclick="setTab('overview')"  id="tab-overview"  class="tab-btn <?= $activeTab==='overview'?'active':'' ?>">
            <i class="ri-dashboard-line text-base"></i>Overview
        </button>
        <button onclick="setTab('orders')"    id="tab-orders"    class="tab-btn <?= $activeTab==='orders'?'active':'' ?>">
            <i class="ri-file-list-3-line text-base"></i>My Orders
        </button>
        <button onclick="setTab('profile')"   id="tab-profile"   class="tab-btn <?= $activeTab==='profile'?'active':'' ?>">
            <i class="ri-user-line text-base"></i>Profile Settings
        </button>

        <a href="market_disputes.php" class="tab-btn text-red-600 hover:text-red-700 font-semibold">
            <i class="ri-scales-3-line text-base"></i> Dispute Center
        </a>
    </div>

    <!-- ===== TAB: OVERVIEW ===== -->
    <div id="panel-overview" class="<?= $activeTab!=='overview'?'hidden':'' ?> space-y-6 animate-fadeIn">
        <!-- Stat Cards Grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
            <?php
            $cards = [
                ['icon'=>'ri-shopping-bag-3-line','color'=>'text-blue-600 bg-blue-50 dark:bg-blue-950/20 dark:text-blue-400','label'=>'Total Orders','value'=>$stats['total_orders']],
                ['icon'=>'ri-wallet-3-line','color'=>'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/20 dark:text-emerald-400','label'=>'Total Spent','value'=>'₵ '.number_format($stats['total_spent'],2)],
                ['icon'=>'ri-truck-line','color'=>'text-orange-600 bg-orange-50 dark:bg-orange-950/20 dark:text-orange-400','label'=>'Active Shipments','value'=>$stats['active']],
                ['icon'=>'ri-checkbox-circle-line','color'=>'text-purple-600 bg-purple-50 dark:bg-purple-950/20 dark:text-purple-400','label'=>'Completed Delivery','value'=>$stats['delivered']],
            ];
            foreach($cards as $c): ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-5 shadow-sm hover:shadow-md transition-shadow duration-300">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs text-[var(--text-muted)] font-bold uppercase tracking-wider"><?= $c['label'] ?></span>
                    <div class="w-10 h-10 rounded-xl <?= $c['color'] ?> flex items-center justify-center">
                        <i class="<?= $c['icon'] ?> text-lg"></i>
                    </div>
                </div>
                <div class="text-xl md:text-2xl font-black text-[var(--text-main)] tracking-tight"><?= $c['value'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Activity Section -->
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b border-[var(--border)] flex justify-between items-center">
                <h2 class="font-extrabold text-[var(--text-main)] text-lg flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-[var(--primary)]"></span> Recent Orders
                </h2>
                <button onclick="setTab('orders')" class="text-sm text-[var(--primary)] font-bold hover:underline">View All Activity</button>
            </div>
            
            <?php if(empty($orders)): ?>
            <div class="p-12 text-center text-[var(--text-muted)]">
                <div class="w-16 h-16 rounded-full bg-slate-50 dark:bg-slate-900/40 flex items-center justify-center mx-auto mb-4">
                    <i class="ri-shopping-bag-3-line text-2xl text-[var(--text-muted)] opacity-40"></i>
                </div>
                <p class="font-semibold text-sm">No purchases recorded yet</p>
                <a href="shop.php" class="inline-block mt-4 bg-[var(--primary-light)] text-[var(--primary)] px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--primary)] hover:text-white transition-all duration-200">Start Shopping</a>
            </div>
            <?php else: ?>
            <div class="divide-y divide-[var(--border)]">
                <?php foreach(array_slice($orders,0,5) as $o): ?>
                <?php $sc = $statusConfig[$o['order_status']] ?? ['label'=>$o['order_status'],'color'=>'bg-gray-50 text-gray-700','icon'=>'ri-circle-line']; ?>
                <div class="px-6 py-4 flex items-center justify-between gap-4 hover:bg-[var(--primary-light)]/20 transition-all duration-200">
                    <div class="flex items-center gap-4 min-w-0">
                        <div class="w-12 h-12 bg-[var(--bg-body)] border border-[var(--border)] rounded-xl flex items-center justify-center flex-shrink-0 text-[var(--text-main)]">
                            <i class="<?= $sc['icon'] ?> text-lg"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-[var(--text-main)] flex items-center gap-2">
                                Order #<?= $o['id'] ?>
                            </div>
                            <div class="text-xs text-[var(--text-muted)] mt-0.5 flex items-center gap-1.5 font-medium">
                                <span><?= $o['item_count'] ?> item<?= $o['item_count']!=1?'s':'' ?></span>
                                <span class="text-slate-300 dark:text-slate-700">•</span>
                                <span><?= date('d M Y', strtotime($o['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                        <div class="text-sm font-extrabold text-[var(--text-main)]">₵<?= number_format($o['total_amount'],2) ?></div>
                        <span class="inline-flex items-center gap-1 text-[10px] px-2.5 py-1 rounded-full font-bold uppercase tracking-wider <?= $sc['color'] ?>">
                            <i class="<?= $sc['icon'] ?> text-[10px]"></i> <?= $sc['label'] ?>
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
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-16 text-center shadow-sm">
            <div class="w-16 h-16 rounded-full bg-slate-50 dark:bg-slate-900/40 flex items-center justify-center mx-auto mb-4">
                <i class="ri-shopping-cart-2-line text-2xl text-[var(--text-muted)] opacity-40"></i>
            </div>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-1">Your Order Shelf is Empty</h3>
            <p class="text-[var(--text-muted)] text-sm mb-6 max-w-sm mx-auto">Your physical produce shipments will show up here as soon as you place an order.</p>
            <a href="shop.php" class="bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition-all">Browse Produce</a>
        </div>
        <?php else: ?>
        <div class="space-y-6">
            <?php foreach($orders as $o): ?>
            <?php
                $sc        = $statusConfig[$o['order_status']] ?? ['label'=>$o['order_status'],'color'=>'bg-gray-100 text-gray-700','icon'=>'ri-circle-line'];
                $stepIndex = $trackingSteps[$o['order_status']] ?? 0;
                $isNew     = ($highlight == $o['id']);

                // Fetch order items for this order
                $oiStmt = $pdo->prepare("
                    SELECT oi.*, p.produce_name, p.photo, u.name AS farmer_name
                    FROM order_items oi
                    JOIN produce_listings p ON oi.produce_id=p.id
                    JOIN users u ON oi.farmer_id=u.id
                    WHERE oi.order_id=?
                ");
                $oiStmt->execute([$o['id']]);
                $oItems = $oiStmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch tracking history
                $trackStmt = $pdo->prepare("SELECT * FROM order_tracking WHERE order_id=? ORDER BY created_at ASC");
                $trackStmt->execute([$o['id']]);
                $trackHistory = $trackStmt->fetchAll(PDO::FETCH_ASSOC);

                $canConfirm = in_array($o['order_status'],['in_transit','ready_for_pickup']) && $o['payment_status']==='confirmed';
            ?>
            <div class="bg-[var(--bg-card)] border-2 <?= $isNew?'border-[var(--primary)] shadow-md':'border-[var(--border)] shadow-sm' ?> rounded-2xl overflow-hidden transition-all duration-300">
                <!-- Order Header -->
                <div class="p-5 border-b border-[var(--border)] bg-slate-50/50 dark:bg-slate-900/10 flex flex-wrap gap-4 justify-between items-center">
                    <div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="font-black text-[var(--text-main)] text-lg">Order ID: #<?= $o['id'] ?></span>
                            <span class="inline-flex items-center gap-1.5 text-[10px] px-2.5 py-1 rounded-full font-extrabold uppercase tracking-wider <?= $sc['color'] ?>">
                                <i class="<?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
                            </span>
                            <?php if($isNew): ?><span class="text-xs bg-[var(--primary)] text-white px-2 py-0.5 rounded-full font-black tracking-wide animate-pulse">HIGHLIGHT</span><?php endif; ?>
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-1.5 font-medium">
                            Date: <span class="text-[var(--text-main)]"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></span>
                            <span class="text-slate-300 dark:text-slate-700 mx-1.5">|</span>
                            Items: <span class="text-[var(--text-main)]"><?= count($oItems) ?></span>
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-black text-[var(--text-main)]">₵<?= number_format($o['total_amount'],2) ?></div>
                        <div class="text-xs text-[var(--text-muted)] mt-0.5 font-bold uppercase tracking-wider"><?= str_replace('_',' ',$o['payment_method']) ?></div>
                    </div>
                </div>

                <!-- Tracking Stepper -->
                <?php if($o['order_status'] !== 'cancelled'): ?>
                <div class="px-6 py-6 border-b border-[var(--border)] bg-[var(--bg-body)]">
                    <?php
                    $steps = [
                        ['icon'=>'ri-wallet-3-line',      'label'=>'Paid'],
                        ['icon'=>'ri-check-double-line',  'label'=>'Verified'],
                        ['icon'=>'ri-box-3-line',         'label'=>'Prepping'],
                        ['icon'=>'ri-truck-line',         'label'=>'Dispatched'],
                        ['icon'=>'ri-home-heart-line',    'label'=>'Delivered'],
                    ];
                    ?>
                    <div class="flex items-center relative">
                        <?php foreach($steps as $si => $step): ?>
                        <?php $done = $si <= $stepIndex; $current = $si === $stepIndex; ?>
                        <div class="flex flex-col items-center flex-shrink-0 z-10">
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold transition-all duration-300
                                <?= $done ? 'bg-[var(--primary)] text-white shadow-md shadow-emerald-500/10' : 'bg-[var(--border)] text-[var(--text-muted)]' ?>
                                <?= $current ? 'ring-4 ring-emerald-500/20 scale-110' : '' ?>">
                                <i class="<?= $step['icon'] ?> text-base"></i>
                            </div>
                            <span class="text-[10px] mt-2 font-bold uppercase tracking-wide <?= $done?'text-[var(--primary)]':'text-[var(--text-muted)]' ?> hidden sm:block text-center w-16">
                                <?= $step['label'] ?>
                            </span>
                        </div>
                        <?php if($si < count($steps)-1): ?>
                        <div class="step-line <?= $si < $stepIndex?'bg-[var(--primary)]':'bg-[var(--border)]' ?> mx-1"></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Order Products -->
                <div class="p-5 divide-y divide-[var(--border)]">
                    <?php foreach($oItems as $oi): ?>
                    <?php $img = !empty($oi['photo']) ? "../uploads/produce/".htmlspecialchars($oi['photo']) : "https://via.placeholder.com/60?text=?"; ?>
                    <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                        <div class="w-14 h-14 rounded-xl overflow-hidden bg-slate-50 dark:bg-slate-900 border border-[var(--border)] flex-shrink-0 flex items-center justify-center p-1">
                            <img src="<?= $img ?>" alt="<?= htmlspecialchars($oi['produce_name']) ?>" class="w-full h-full object-cover rounded-lg">
                        </div>
                        <div class="flex-grow min-w-0">
                            <p class="text-sm font-extrabold text-[var(--text-main)] truncate"><?= htmlspecialchars($oi['produce_name']) ?></p>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5 font-medium">Vendor: <span class="text-[var(--text-main)] font-semibold"><?= htmlspecialchars($oi['farmer_name']) ?></span> · Qty: <span class="text-[var(--text-main)] font-semibold"><?= $oi['quantity'] ?></span></p>
                        </div>
                        <div class="text-sm font-extrabold text-[var(--text-main)] flex-shrink-0">₵<?= number_format($oi['subtotal'],2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Shipping Metadata & Action Bar -->
                <div class="px-5 py-4 flex flex-wrap gap-4 justify-between items-center border-t border-[var(--border)] bg-slate-50/50 dark:bg-slate-900/10">
                    <div class="text-xs text-[var(--text-muted)] flex items-start gap-2 max-w-md">
                        <i class="ri-map-pin-line text-[var(--primary)] text-sm mt-0.5"></i>
                        <div>
                            <p class="font-bold text-[var(--text-main)]"><?= htmlspecialchars($o['delivery_name']) ?></p>
                            <p class="font-medium mt-0.5 text-[11px] leading-relaxed"><?= htmlspecialchars($o['delivery_address']) ?> · <?= htmlspecialchars($o['delivery_phone']) ?></p>
                        </div>
                    </div>
                    <div class="flex-shrink-0">
                        <a href="market_disputes.php" class="text-xs text-red-600 font-extrabold hover:underline uppercase tracking-wider flex items-center gap-1">
                            <i class="ri-alert-line"></i> Dispute Order
                        </a>

                        <?php if($canConfirm): ?>
                        <button onclick="confirmDelivery(<?= $o['id'] ?>, this)"
                            class="bg-green-600 text-white px-6 py-2.5 rounded-xl text-xs font-bold hover:bg-green-700 transition flex items-center gap-2 shadow-md hover:shadow-lg">
                            <i class="ri-checkbox-circle-line text-sm"></i> Confirm Receipt
                        </button>
                        <?php elseif($o['order_status']==='delivered'): ?>
                        <span class="inline-flex items-center gap-1.5 text-xs text-emerald-600 font-extrabold uppercase tracking-wider">
                            <i class="ri-checkbox-circle-fill text-sm"></i> Order Fulfilled
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Accordion Timeline -->
                <details class="border-t border-[var(--border)] group">
                    <summary class="px-5 py-3.5 text-xs text-[var(--text-muted)] cursor-pointer hover:text-[var(--primary)] hover:bg-[var(--primary-light)]/20 font-bold flex items-center gap-2 transition-colors duration-200">
                        <i class="ri-history-line"></i> Activity Logs & History
                        <i class="ri-arrow-down-s-line group-open:rotate-180 transition ml-auto"></i>
                    </summary>
                    <div class="px-5 pb-5 pt-3 space-y-4 bg-slate-50/20 dark:bg-slate-900/5">
                        <?php foreach(array_reverse($trackHistory) as $th): ?>
                        <div class="flex gap-3 text-xs">
                            <div class="w-2 h-2 rounded-full bg-[var(--primary)] mt-1.5 flex-shrink-0 ring-4 ring-emerald-500/10"></div>
                            <div>
                                <p class="font-bold text-[var(--text-main)] capitalize"><?= str_replace('_',' ',$th['status']) ?></p>
                                <?php if($th['notes']): ?><p class="text-[var(--text-muted)] font-medium mt-0.5"><?= htmlspecialchars($th['notes']) ?></p><?php endif; ?>
                                <p class="text-[var(--text-muted)] text-[10px] mt-1 font-semibold"><?= date('d M Y, h:i A', strtotime($th['created_at'])) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: PROFILE ===== -->
    <div id="panel-profile" class="<?= $activeTab!=='profile'?'hidden':'' ?> space-y-6 animate-fadeIn">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <!-- Profile Overview Card -->
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm flex flex-col items-center justify-between text-center self-start">
                <div class="w-full">
                    <div class="w-24 h-24 rounded-full bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-4xl font-extrabold mx-auto mb-4 shadow-md relative">
                        <?= strtoupper(substr($buyer['name'],0,1)) ?>
                    </div>
                    <h2 class="font-extrabold text-xl text-[var(--text-main)]"><?= htmlspecialchars($buyer['name']) ?></h2>
                    <p class="text-sm text-[var(--text-muted)] mt-1 font-medium"><?= htmlspecialchars($buyer['email']) ?></p>
                    <span class="inline-block mt-3 text-[10px] bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 px-3.5 py-1 rounded-full font-bold uppercase tracking-wider border border-emerald-200/20">Buyer Account</span>

                    <?php if($buyer['location']): ?>
                    <p class="text-xs text-[var(--text-muted)] mt-4 flex items-center justify-center gap-1.5 font-medium">
                        <i class="ri-map-pin-line text-[var(--primary)]"></i> <?= htmlspecialchars($buyer['location']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <div class="mt-6 w-full grid grid-cols-2 gap-4 text-center border-t border-[var(--border)] pt-6">
                    <div class="bg-[var(--bg-body)] rounded-2xl p-4">
                        <div class="text-2xl font-black text-[var(--text-main)]"><?= $stats['total_orders'] ?></div>
                        <div class="text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Orders</div>
                    </div>
                    <div class="bg-[var(--bg-body)] rounded-2xl p-4">
                        <div class="text-2xl font-black text-[var(--text-main)]"><?= $stats['delivered'] ?></div>
                        <div class="text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider mt-1">Delivered</div>
                    </div>
                </div>
            </div>

            <!-- Profile Form Column -->
            <div class="lg:col-span-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 md:p-8 shadow-sm">
                <h2 class="font-extrabold text-lg text-[var(--text-main)] mb-6 flex items-center gap-2">
                    <i class="ri-user-settings-line text-[var(--primary)] text-xl"></i> Personal Settings
                </h2>

                <?php if($profileError): ?>
                <div class="mb-5 p-4 bg-red-50 dark:bg-red-950/10 border border-red-200/50 rounded-xl text-red-700 dark:text-red-400 text-sm flex items-center gap-2">
                    <i class="ri-error-warning-line"></i> <?= htmlspecialchars($profileError) ?>
                </div>
                <?php endif; ?>
                <?php if($profileSuccess): ?>
                <div class="mb-5 p-4 bg-emerald-50 dark:bg-emerald-950/10 border border-emerald-200/50 rounded-xl text-emerald-700 dark:text-emerald-400 text-sm flex items-center gap-2">
                    <i class="ri-checkbox-circle-line"></i> <?= htmlspecialchars($profileSuccess) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="buyer_dashboard.php?tab=profile" class="space-y-6">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Full Name *</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($buyer['name']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Email Address (Read-only)</label>
                            <input type="email" value="<?= htmlspecialchars($buyer['email']??'') ?>" disabled
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-slate-50 dark:bg-slate-900/60 text-[var(--text-muted)] cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Phone Contact</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($buyer['phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="e.g. 0244000000">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Mobile Money Number</label>
                            <input type="tel" name="momo_phone" value="<?= htmlspecialchars($buyer['momo_phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="For expedited checkout">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Delivery Location & Address</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($buyer['location']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition"
                                placeholder="City / District / Landmark details">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider mb-2">Profile Bio & Extra Notes</label>
                            <textarea name="profile_bio" rows="4"
                                class="w-full border border-[var(--border)] rounded-xl px-4 py-3 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition resize-none"
                                placeholder="Add preferences or shipping details for vendors..."><?= htmlspecialchars($buyer['profile_bio']??'') ?></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="submit"
                            class="bg-[var(--primary)] text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow-md hover:shadow-lg">
                            Apply Updates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Bottom Nav -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bg-[var(--bg-card)] border-t border-[var(--border)] z-50 flex justify-between items-center px-6 py-2.5 text-[10px] font-bold tracking-wide uppercase text-[var(--text-muted)] shadow-2xl">
    <a href="index.php"          class="flex flex-col items-center gap-1.5 hover:text-[var(--primary)] transition"><i class="ri-home-4-line text-xl"></i>Home</a>
    <a href="shop.php"           class="flex flex-col items-center gap-1.5 hover:text-[var(--primary)] transition"><i class="ri-store-2-line text-xl"></i>Shop</a>
    <a href="wishlist.php"       class="flex flex-col items-center gap-1.5 hover:text-[var(--primary)] transition"><i class="ri-heart-3-line text-xl"></i>Saved</a>
    <a href="buyer_dashboard.php" class="flex flex-col items-center gap-1.5 text-[var(--primary)] transition"><i class="ri-user-fill text-xl"></i>Account</a>
</nav>

<script>
if (typeof showToast !== 'function') {
    window.showToast = function(message, type) {
        alert((type === 'error' ? '❌ ' : '✅ ') + message);
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

async function confirmDelivery(orderId, btn) {
    if (!confirm('Are you sure you want to confirm receipt of this order? Confirming will finalize payment verification.')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin text-sm"></i> Wait...';

    const form = new FormData();
    form.append('order_id', orderId);

    try {
        const res  = await fetch('confirm_delivery.php', { method:'POST', body:form });
        const data = await res.json();

        if (data.success) {
            showToast('Receipt confirmed. Transaction finalized.', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast(data.message || 'Error processing confirmation', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="ri-checkbox-circle-line"></i> Confirm Receipt';
        }
    } catch(e) {
        showToast('Connection timed out', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="ri-checkbox-circle-line"></i> Confirm Receipt';
    }
}
</script>
</body>
</html>