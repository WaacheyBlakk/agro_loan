<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { header('Location: buyers_login.php'); exit; }

$pdo       = getPDO();
$user_role = $_SESSION['role'] ?? 'farmer';
$is_logged = true;
if ($user_role !== 'farmer') { header('Location: buyer_dashboard.php'); exit; }

// Nav cart count (farmers won't have one, but needed for partial)
$cart_count = 0;

// Farmer profile
$farmer = $pdo->prepare("SELECT id,name,email,phone,momo_phone,location,profile_bio,created_at FROM users WHERE id=?");
$farmer->execute([$user_id]);
$farmer = $farmer->fetch(PDO::FETCH_ASSOC);

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT oi.order_id)                                                      AS total_orders,
        COALESCE(SUM(oi.subtotal),0)                                                     AS gross_revenue,
        COALESCE(SUM(CASE WHEN e.status='held'     THEN e.amount END),0)                AS escrow_held,
        COALESCE(SUM(CASE WHEN e.status='released' THEN e.amount END),0)                AS paid_out,
        SUM(o.order_status IN ('payment_confirmed','preparing','in_transit','ready_for_pickup')) AS active_orders,
        SUM(o.order_status='delivered')                                                  AS completed_orders
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN escrow e ON e.order_item_id = oi.id
    WHERE oi.farmer_id = ?
");
$statsStmt->execute([$user_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ─── Orders for this farmer
$ordersStmt = $pdo->prepare("
    SELECT
        o.id AS order_id, o.order_status, o.payment_status, o.created_at AS order_date,
        o.delivery_name, o.delivery_phone, o.delivery_address, o.buyer_notes,
        b.name AS buyer_name, b.phone AS buyer_phone,
        oi.id AS item_id, oi.produce_id, oi.quantity, oi.unit_price, oi.subtotal, oi.item_status,
        p.produce_name, p.photo,
        e.status AS escrow_status, e.amount AS escrow_amount
    FROM order_items oi
    JOIN orders o  ON oi.order_id  = o.id
    JOIN buyers  b  ON o.buyer_id   = b.id
    JOIN produce_listings p ON oi.produce_id = p.id
    LEFT JOIN escrow e ON e.order_item_id = oi.id
    WHERE oi.farmer_id = ?
    ORDER BY o.created_at DESC
");
$ordersStmt->execute([$user_id]);
$rawItems = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by order_id
$orders = [];
foreach ($rawItems as $row) {
    $oid = $row['order_id'];
    if (!isset($orders[$oid])) {
        $orders[$oid] = [
            'order_id'        => $oid,
            'order_status'    => $row['order_status'],
            'payment_status'  => $row['payment_status'],
            'order_date'      => $row['order_date'],
            'delivery_name'   => $row['delivery_name'],
            'delivery_phone'  => $row['delivery_phone'],
            'delivery_address'=> $row['delivery_address'],
            'buyer_notes'     => $row['buyer_notes'],
            'buyer_name'      => $row['buyer_name'],
            'buyer_phone'     => $row['buyer_phone'],
            'items'           => [],
        ];
    }
    $orders[$oid]['items'][] = $row;
}

// ─── Produce listings 
$listingsStmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name,
           (SELECT COUNT(*) FROM order_items oi WHERE oi.produce_id=p.id) AS total_orders
    FROM produce_listings p
    JOIN categories c ON p.category_id = c.id
    WHERE p.farmer_id = ?
    ORDER BY p.created_at DESC
");
$listingsStmt->execute([$user_id]);
$listings = $listingsStmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Handle profile update ────────────────────────────────────────────────────
$profileError = ''; $profileSuccess = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_profile'])) {
    $name     = trim(filter_input(INPUT_POST,'name',FILTER_SANITIZE_SPECIAL_CHARS));
    $phone    = trim(filter_input(INPUT_POST,'phone',FILTER_SANITIZE_SPECIAL_CHARS));
    $momo     = preg_replace('/\D/','',($_POST['momo_phone']??''));
    $location = trim(filter_input(INPUT_POST,'location',FILTER_SANITIZE_SPECIAL_CHARS));
    $bio      = trim(filter_input(INPUT_POST,'profile_bio',FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$name) { $profileError = 'Name is required.'; }
    elseif (!$momo || strlen($momo) < 9) { $profileError = 'A valid MoMo number is required for receiving payments.'; }
    else {
        $pdo->prepare("UPDATE users SET name=?,phone=?,momo_phone=?,location=?,profile_bio=? WHERE id=?")
            ->execute([$name,$phone,$momo,$location,$bio,$user_id]);
        $profileSuccess = 'Profile updated!';
        $farmer['name']=$name; $farmer['phone']=$phone; $farmer['momo_phone']=$momo;
        $farmer['location']=$location; $farmer['profile_bio']=$bio;
    }
}

$activeTab = $_GET['tab'] ?? 'overview';

$statusConfig = [
    'pending_payment'   => ['label'=>'Pending Payment',   'color'=>'bg-yellow-100 text-yellow-700', 'icon'=>'ri-time-line'],
    'payment_confirmed' => ['label'=>'Payment Confirmed', 'color'=>'bg-blue-100 text-blue-700',    'icon'=>'ri-check-double-line'],
    'preparing'         => ['label'=>'Preparing',         'color'=>'bg-purple-100 text-purple-700','icon'=>'ri-box-3-line'],
    'in_transit'        => ['label'=>'In Transit',        'color'=>'bg-orange-100 text-orange-700','icon'=>'ri-truck-line'],
    'ready_for_pickup'  => ['label'=>'Ready for Pickup',  'color'=>'bg-cyan-100 text-cyan-700',    'icon'=>'ri-store-line'],
    'delivered'         => ['label'=>'Delivered',         'color'=>'bg-green-100 text-green-700',  'icon'=>'ri-checkbox-circle-line'],
    'cancelled'         => ['label'=>'Cancelled',         'color'=>'bg-red-100 text-red-700',      'icon'=>'ri-close-circle-line'],
];

$escrowConfig = [
    'held'     => ['label'=>'In Escrow','color'=>'text-yellow-600','icon'=>'ri-lock-line'],
    'released' => ['label'=>'Paid Out', 'color'=>'text-green-600', 'icon'=>'ri-check-line'],
    'refunded' => ['label'=>'Refunded', 'color'=>'text-red-600',   'icon'=>'ri-arrow-go-back-line'],
];

$page_title = 'Seller Dashboard | AgroMarket';
$active_nav = 'dashboard';
include 'nav.php';
?>

<div class="pt-24 pb-12 min-h-screen px-4 md:px-6 max-w-6xl mx-auto">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-3">
        <div>
            <h1 class="text-2xl font-bold text-[var(--text-main)]">
                Seller Dashboard
            </h1>
            <p class="text-[var(--text-muted)] text-sm mt-0.5">
                Welcome, <?= htmlspecialchars(explode(' ',$farmer['name'])[0]) ?> ·
                Member since <?= date('M Y', strtotime($farmer['created_at'])) ?>
            </p>
        </div>
        <a href="add_product.php" class="bg-[var(--primary)] text-white px-5 py-2.5 rounded-xl font-semibold text-sm hover:bg-[var(--primary-dark)] transition shadow w-fit flex items-center gap-2">
            <i class="ri-add-circle-line"></i> Add New Listing
        </a>
    </div>

    <!-- MoMo Warning (if not set) -->
    <?php if (empty($farmer['momo_phone'])): ?>
    <div class="mb-5 p-4 bg-amber-50 border border-amber-300 rounded-2xl flex gap-3 items-start">
        <i class="ri-alert-line text-amber-600 text-xl flex-shrink-0 mt-0.5"></i>
        <div>
            <p class="text-sm font-bold text-amber-800">MoMo number not set!</p>
            <p class="text-xs text-amber-700 mt-0.5">You need a Mobile Money number to receive payments when buyers confirm delivery.
               <button onclick="setTab('profile')" class="underline font-semibold ml-1">Add it now →</button>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="flex gap-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-xl p-1.5 mb-6 overflow-x-auto w-fit max-w-full">
        <button onclick="setTab('overview')"  id="tab-overview"  class="tab-btn <?= $activeTab==='overview'?'active':'' ?> whitespace-nowrap"><i class="ri-dashboard-line mr-1"></i>Overview</button>
        <button onclick="setTab('orders')"    id="tab-orders"    class="tab-btn <?= $activeTab==='orders'?'active':'' ?> whitespace-nowrap">
            <i class="ri-shopping-bag-3-line mr-1"></i>Orders
            <?php if($stats['active_orders']>0): ?>
            <span class="ml-1 inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold bg-red-500 text-white rounded-full"><?= $stats['active_orders'] ?></span>
            <?php endif; ?>
        </button>
        <button onclick="setTab('listings')"  id="tab-listings"  class="tab-btn <?= $activeTab==='listings'?'active':'' ?> whitespace-nowrap"><i class="ri-store-2-line mr-1"></i>My Listings</button>
        <button onclick="setTab('profile')"   id="tab-profile"   class="tab-btn <?= $activeTab==='profile'?'active':'' ?> whitespace-nowrap"><i class="ri-user-line mr-1"></i>Profile</button>
                
        <a href="market_disputes.php" class="tab-btn whitespace-nowrap text-red-600 hover:text-red-700 flex items-center gap-1">
            <i class="ri-scales-3-line"></i> Order Disputes
        </a>
    </div>

    <!-- ===== TAB: OVERVIEW ===== -->
    <div id="panel-overview" class="<?= $activeTab!=='overview'?'hidden':'' ?>">
        <!-- Stat Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <?php
            $cards = [
                ['icon'=>'ri-shopping-bag-3-line','color'=>'text-blue-600 bg-blue-50','label'=>'Total Orders','value'=>$stats['total_orders']],
                ['icon'=>'ri-money-cedi-circle-line','color'=>'text-green-600 bg-green-50','label'=>'Gross Revenue','value'=>'₵ '.number_format($stats['gross_revenue'],2)],
                ['icon'=>'ri-lock-line','color'=>'text-yellow-600 bg-yellow-50','label'=>'In Escrow','value'=>'₵ '.number_format($stats['escrow_held'],2)],
                ['icon'=>'ri-bank-line','color'=>'text-emerald-600 bg-emerald-50','label'=>'Paid Out','value'=>'₵ '.number_format($stats['paid_out'],2)],
                ['icon'=>'ri-time-line','color'=>'text-orange-600 bg-orange-50','label'=>'Active Orders','value'=>$stats['active_orders']],
                ['icon'=>'ri-checkbox-circle-line','color'=>'text-purple-600 bg-purple-50','label'=>'Completed','value'=>$stats['completed_orders']],
            ];
            foreach($cards as $c): ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-4 shadow-sm">
                <div class="w-10 h-10 rounded-xl <?= $c['color'] ?> flex items-center justify-center mb-3">
                    <i class="<?= $c['icon'] ?> text-xl"></i>
                </div>
                <div class="text-2xl font-bold text-[var(--text-main)] mb-0.5"><?= $c['value'] ?></div>
                <div class="text-xs text-[var(--text-muted)] font-medium"><?= $c['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Escrow Explanation -->
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 border border-green-200 dark:border-green-800 rounded-2xl p-5 shadow-sm">
            <h3 class="font-bold text-[var(--text-main)] mb-2 flex items-center gap-2">
                <i class="ri-shield-check-fill text-green-600 text-xl"></i> How Escrow Works
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm text-[var(--text-muted)]">
                <div class="flex gap-2">
                    <span class="w-6 h-6 rounded-full bg-green-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">1</span>
                    <p>Buyer places an order and pays via Mobile Money. Funds are held securely in escrow.</p>
                </div>
                <div class="flex gap-2">
                    <span class="w-6 h-6 rounded-full bg-green-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">2</span>
                    <p>You prepare and deliver the order. Update the status so the buyer can track progress.</p>
                </div>
                <div class="flex gap-2">
                    <span class="w-6 h-6 rounded-full bg-green-600 text-white flex items-center justify-center text-xs font-bold flex-shrink-0">3</span>
                    <p>Buyer confirms delivery. Funds are instantly released to your MoMo number.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== TAB: ORDERS ===== -->
    <div id="panel-orders" class="<?= $activeTab!=='orders'?'hidden':'' ?>">
        <?php if(empty($orders)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-12 text-center shadow-sm">
            <i class="ri-inbox-line text-6xl text-[var(--text-muted)] opacity-30 mb-4 block"></i>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-2">No orders yet</h3>
            <p class="text-[var(--text-muted)] text-sm mb-5">Orders from buyers will appear here. Make sure your listings are active!</p>
            <a href="add_product.php" class="bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition">Add a Listing</a>
        </div>

        <?php else: ?>

        <!-- Filter bar -->
        <div class="flex gap-2 mb-4 overflow-x-auto pb-1">
            <?php
            $filters = ['all'=>'All','payment_confirmed'=>'New','preparing'=>'Preparing','in_transit'=>'In Transit','ready_for_pickup'=>'Pickup','delivered'=>'Delivered'];
            foreach($filters as $fk => $fl):
            ?>
            <button onclick="filterOrders('<?= $fk ?>')" id="filter-<?= $fk ?>"
                class="whitespace-nowrap text-xs px-3 py-1.5 rounded-full border border-[var(--border)] font-semibold text-[var(--text-muted)] hover:border-[var(--primary)] hover:text-[var(--primary)] transition <?= $fk==='all'?'!bg-[var(--primary)] !text-white !border-[var(--primary)]':'' ?>">
                <?= $fl ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="space-y-4" id="ordersContainer">
            <?php foreach ($orders as $o): ?>
            <?php $sc = $statusConfig[$o['order_status']] ?? ['label'=>$o['order_status'],'color'=>'bg-gray-100 text-gray-700','icon'=>'ri-circle-line']; ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl overflow-hidden shadow-sm order-card" data-status="<?= $o['order_status'] ?>">

                <!-- Header -->
                <div class="p-4 border-b border-[var(--border)] flex flex-wrap gap-3 justify-between items-start bg-[var(--bg-body)]">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-[var(--text-main)]">Order #<?= $o['order_id'] ?></span>
                            <span class="inline-flex items-center gap-1 text-xs px-2.5 py-1 rounded-full font-semibold <?= $sc['color'] ?>">
                                <i class="<?= $sc['icon'] ?>"></i> <?= $sc['label'] ?>
                            </span>
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-1">
                            <i class="ri-user-line"></i> <?= htmlspecialchars($o['buyer_name']) ?>
                            · <?= date('d M Y, H:i', strtotime($o['order_date'])) ?>
                        </p>
                    </div>
                    <div class="text-right text-sm">
                        <div class="font-bold text-[var(--text-main)]">
                            ₵ <?= number_format(array_sum(array_column($o['items'],'subtotal')),2) ?>
                        </div>
                        <div class="text-[var(--text-muted)] text-xs"><?= count($o['items']) ?> item<?= count($o['items'])!=1?'s':'' ?></div>
                    </div>
                </div>

                <!-- Items -->
                <div class="p-4 space-y-3">
                    <?php foreach($o['items'] as $oi): ?>
                    <?php
                        $img = !empty($oi['photo']) ? "../uploads/produce/".htmlspecialchars($oi['photo']) : "https://via.placeholder.com/60?text=?";
                        $ec  = $escrowConfig[$oi['escrow_status']] ?? ['label'=>'N/A','color'=>'text-gray-500','icon'=>'ri-circle-line'];
                    ?>
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-lg overflow-hidden bg-gray-50 flex-shrink-0">
                            <img src="<?= $img ?>" alt="<?= htmlspecialchars($oi['produce_name']) ?>" class="w-full h-full object-contain p-1">
                        </div>
                        <div class="flex-grow min-w-0">
                            <p class="text-sm font-semibold text-[var(--text-main)] line-clamp-1"><?= htmlspecialchars($oi['produce_name']) ?></p>
                            <p class="text-xs text-[var(--text-muted)]">Qty: <?= $oi['quantity'] ?> bag<?= $oi['quantity']!=1?'s':'' ?> · ₵<?= number_format($oi['unit_price'],2) ?>/bag</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <div class="text-sm font-bold text-[var(--text-main)]">₵ <?= number_format($oi['subtotal'],2) ?></div>
                            <?php if($oi['escrow_status']): ?>
                            <div class="text-[10px] font-semibold <?= $ec['color'] ?> flex items-center justify-end gap-0.5 mt-0.5">
                                <i class="<?= $ec['icon'] ?>"></i> <?= $ec['label'] ?>
                                <?php if($oi['escrow_status']==='held'): ?>
                                <span class="text-[var(--text-muted)]">(₵<?= number_format($oi['escrow_amount'],2) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Delivery info -->
                <div class="px-4 pb-3 border-t border-[var(--border)] pt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-[var(--text-muted)]">
                    <div><i class="ri-map-pin-line mr-1"></i><span class="font-medium text-[var(--text-main)]">Deliver to:</span> <?= htmlspecialchars($o['delivery_name']) ?>, <?= htmlspecialchars($o['delivery_address']) ?></div>
                    <div><i class="ri-phone-line mr-1"></i><?= htmlspecialchars($o['delivery_phone']) ?></div>
                    <?php if($o['buyer_notes']): ?>
                    <div class="sm:col-span-2 italic"><i class="ri-chat-3-line mr-1"></i>"<?= htmlspecialchars($o['buyer_notes']) ?>"</div>
                    <?php endif; ?>
                </div>

                <!-- Status Actions -->
                <?php if($o['payment_status']==='confirmed' && $o['order_status'] !== 'delivered' && $o['order_status'] !== 'cancelled'): ?>
                <div class="px-4 pb-4 border-t border-[var(--border)] pt-3 flex flex-wrap gap-2 items-center">
                    <span class="text-xs text-[var(--text-muted)] font-medium mr-1">Update Status:</span>

                    <?php if($o['order_status']==='payment_confirmed'): ?>
                    <button onclick="updateStatus(<?= $o['order_id'] ?>,'preparing',this)"
                        class="bg-purple-600 text-white text-xs px-4 py-2 rounded-lg font-semibold hover:bg-purple-700 transition flex items-center gap-1.5">
                        <i class="ri-box-3-line"></i> Mark as Preparing
                    </button>

                    <?php elseif($o['order_status']==='preparing'): ?>
                    <button onclick="updateStatus(<?= $o['order_id'] ?>,'in_transit',this)"
                        class="bg-orange-500 text-white text-xs px-4 py-2 rounded-lg font-semibold hover:bg-orange-600 transition flex items-center gap-1.5">
                        <i class="ri-truck-line"></i> Mark In Transit
                    </button>
                    <button onclick="updateStatus(<?= $o['order_id'] ?>,'ready_for_pickup',this)"
                        class="bg-cyan-600 text-white text-xs px-4 py-2 rounded-lg font-semibold hover:bg-cyan-700 transition flex items-center gap-1.5">
                        <i class="ri-store-line"></i> Ready for Pickup
                    </button>

                    <?php elseif(in_array($o['order_status'],['in_transit','ready_for_pickup'])): ?>
                    <span class="text-xs text-[var(--text-muted)] italic flex items-center gap-1">
                        <i class="ri-hourglass-line animate-spin"></i>
                        Awaiting buyer confirmation of delivery…
                    </span>
                    <?php endif; ?>
                </div>
                <?php elseif($o['order_status']==='delivered'): ?>
                <div class="px-4 pb-3 border-t border-[var(--border)] pt-3">
                    <span class="text-xs text-green-600 font-semibold flex items-center gap-1">
                        <i class="ri-checkbox-circle-fill text-base"></i>
                        Delivered & Payment Released to Your MoMo Account
                    </span>
                </div>
                <?php elseif($o['payment_status']==='pending'): ?>
                <div class="px-4 pb-3 border-t border-[var(--border)] pt-3">
                    <span class="text-xs text-yellow-600 font-semibold flex items-center gap-1">
                        <i class="ri-time-line"></i> Awaiting payment from buyer
                    </span>
                </div>

                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <a href="market_disputes.php" class="text-xs text-red-600 font-semibold hover:underline flex items-center gap-1 py-2">
                    <i class="ri-alert-line"></i> Dispute Order
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: LISTINGS ===== -->
    <div id="panel-listings" class="<?= $activeTab!=='listings'?'hidden':'' ?>">
        <div class="flex justify-between items-center mb-4">
            <p class="text-sm text-[var(--text-muted)]"><?= count($listings) ?> listing<?= count($listings)!=1?'s':'' ?></p>
            <a href="add_product.php" class="text-sm text-[var(--primary)] font-semibold hover:underline flex items-center gap-1">
                <i class="ri-add-line"></i> Add New
            </a>
        </div>

        <?php if(empty($listings)): ?>
        <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-12 text-center shadow-sm">
            <i class="ri-plant-line text-6xl text-[var(--text-muted)] opacity-30 mb-4 block"></i>
            <h3 class="text-lg font-bold text-[var(--text-main)] mb-2">No listings yet</h3>
            <p class="text-[var(--text-muted)] text-sm mb-5">Start selling by listing your agricultural produce.</p>
            <a href="add_product.php" class="bg-[var(--primary)] text-white px-6 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition">List Your Produce</a>
        </div>

        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach($listings as $l): ?>
            <?php
                $img     = !empty($l['photo']) ? "../uploads/produce/".htmlspecialchars($l['photo']) : "https://via.placeholder.com/200?text=No+Image";
                $inStock = $l['bags_available'] > 0;
            ?>
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition">
                <div class="relative aspect-video overflow-hidden bg-gray-50">
                    <img src="<?= $img ?>" alt="<?= htmlspecialchars($l['produce_name']) ?>" class="w-full h-full object-contain p-4">
                    <div class="absolute top-2 left-2">
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold <?= $inStock?'bg-green-100 text-green-700':'bg-red-100 text-red-600' ?>">
                            <?= $inStock ? $l['bags_available'].' bags left' : 'Out of Stock' ?>
                        </span>
                    </div>
                    <div class="absolute top-2 right-2 text-[10px] bg-white/80 backdrop-blur-sm px-2 py-0.5 rounded-full text-[var(--text-muted)] font-medium">
                        <?= htmlspecialchars($l['category_name']) ?>
                    </div>
                </div>

                <div class="p-4">
                    <h3 class="font-bold text-[var(--text-main)] text-sm mb-1 line-clamp-1"><?= htmlspecialchars($l['produce_name']) ?></h3>
                    <div class="flex items-baseline justify-between mb-3">
                        <span class="text-lg font-bold text-[var(--text-main)]">₵ <?= number_format($l['price_per_bag'],2) ?></span>
                        <span class="text-xs text-[var(--text-muted)]">per bag</span>
                    </div>

                    <div class="flex gap-3 text-center text-xs mb-3">
                        <div class="flex-1 bg-[var(--bg-body)] rounded-lg py-2">
                            <div class="font-bold text-[var(--text-main)]"><?= $l['total_orders'] ?></div>
                            <div class="text-[var(--text-muted)]">Orders</div>
                        </div>
                        <div class="flex-1 bg-[var(--bg-body)] rounded-lg py-2">
                            <div class="font-bold text-[var(--text-main)]"><?= $l['bags_available'] ?></div>
                            <div class="text-[var(--text-muted)]">Bags Left</div>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <a href="edit_produce.php?id=<?= $l['id'] ?>"
                            class="flex-1 text-center border border-[var(--primary)] text-[var(--primary)] text-xs font-bold py-2 rounded-lg hover:bg-[var(--primary)] hover:text-white transition">
                            <i class="ri-edit-line"></i> Edit
                        </a>
                        <button onclick="toggleListing(<?= $l['id'] ?>, <?= $inStock?1:0 ?>, this)"
                            class="flex-1 text-center border border-[var(--border)] text-[var(--text-muted)] text-xs font-bold py-2 rounded-lg hover:bg-[var(--bg-body)] transition">
                            <i class="ri-eye-<?= $inStock?'off':'line' ?>-line"></i> <?= $inStock?'Deactivate':'Activate' ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===== TAB: PROFILE ===== -->
    <div id="panel-profile" class="<?= $activeTab!=='profile'?'hidden':'' ?>">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Profile Card -->
            <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm text-center">
                <div class="w-20 h-20 rounded-full bg-gradient-to-br from-[var(--primary)] to-[var(--accent)] flex items-center justify-center text-white text-3xl font-bold mx-auto mb-3">
                    <?= strtoupper(substr($farmer['name'],0,1)) ?>
                </div>
                <h2 class="font-bold text-lg text-[var(--text-main)]"><?= htmlspecialchars($farmer['name']) ?></h2>
                <p class="text-sm text-[var(--text-muted)]"><?= htmlspecialchars($farmer['email']) ?></p>
                <span class="inline-block mt-2 text-xs bg-green-100 text-green-700 px-3 py-1 rounded-full font-semibold">Farmer Account</span>
                <?php if($farmer['location']): ?>
                <p class="text-xs text-[var(--text-muted)] mt-3 flex items-center justify-center gap-1">
                    <i class="ri-map-pin-line"></i> <?= htmlspecialchars($farmer['location']) ?>
                </p>
                <?php endif; ?>
                <?php if($farmer['momo_phone']): ?>
                <div class="mt-3 p-2 bg-yellow-50 rounded-lg">
                    <p class="text-xs text-yellow-700 font-medium flex items-center justify-center gap-1">
                        <i class="ri-smartphone-line"></i> MoMo: <?= htmlspecialchars($farmer['momo_phone']) ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="mt-3 p-2 bg-red-50 rounded-lg">
                    <p class="text-xs text-red-600 font-medium">No MoMo number — add one to receive payments!</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Edit Form -->
            <div class="lg:col-span-2 bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm">
                <h2 class="font-bold text-[var(--text-main)] mb-5 flex items-center gap-2">
                    <i class="ri-edit-line text-[var(--primary)]"></i> Edit Profile
                </h2>
                <?php if($profileError): ?>
                <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm"><?= htmlspecialchars($profileError) ?></div>
                <?php endif; ?>
                <?php if($profileSuccess): ?>
                <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-xl text-green-700 text-sm flex items-center gap-2">
                    <i class="ri-check-fill"></i> <?= htmlspecialchars($profileSuccess) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="seller_dashboard.php?tab=profile" class="space-y-4">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Full Name *</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($farmer['name']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Phone Number</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($farmer['phone']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="0XX XXX XXXX">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">
                                MoMo Number * <span class="font-normal text-yellow-600">(Required to receive payments)</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] font-bold text-sm">+233</span>
                                <input type="tel" name="momo_phone" required value="<?= htmlspecialchars($farmer['momo_phone']??'') ?>"
                                    class="w-full border border-[var(--border)] rounded-xl pl-14 pr-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                    placeholder="XX XXX XXXX">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">Farm Location / Region</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($farmer['location']??'') ?>"
                                class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent"
                                placeholder="e.g. Brong-Ahafo, Ashanti Region">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-[var(--text-muted)] mb-1">About Your Farm</label>
                            <textarea name="profile_bio" rows="3"
                                class="w-full border border-[var(--border)] rounded-xl px-3 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent resize-none"
                                placeholder="Tell buyers about your farm, your produce quality, delivery methods…"><?= htmlspecialchars($farmer['profile_bio']??'') ?></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-[var(--primary)] text-white px-8 py-2.5 rounded-xl font-bold text-sm hover:bg-[var(--primary-dark)] transition shadow">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Standardized Mobile Bottom Nav (Syncs with Seller Tabs) -->
<nav class="md:hidden fixed bottom-0 left-0 w-full z-50 flex justify-between items-center px-4 py-2 text-[10px] font-semibold bg-[var(--bg-card)] border-t border-[var(--border)] shadow-lg">
    <button onclick="setTab('overview')" id="btn-nav-overview" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-dashboard-line text-xl"></i>Overview
    </button>
    <button onclick="setTab('orders')" id="btn-nav-orders" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-shopping-bag-3-line text-xl"></i>Orders
    </button>
    <a href="add_product.php" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)] hover:text-[var(--primary)]">
        <i class="ri-add-circle-line text-xl"></i>New
    </a>
    <button onclick="setTab('listings')" id="btn-nav-listings" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-store-2-line text-xl"></i>My Listings
    </button>
    <button onclick="setTab('profile')" id="btn-nav-profile" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-user-line text-xl"></i>Profile
    </button>
</nav>

<style>
.tab-btn { padding:.5rem 1rem; font-size:.85rem; font-weight:600; border-radius:.5rem; transition:all .2s; cursor:pointer; border:none; background:none; }
.tab-btn.active { background:var(--primary); color:#fff; }
.tab-btn:not(.active) { color:var(--text-muted); }
.tab-btn:not(.active):hover { background:var(--bg-body); color:var(--text-main); }
</style>

<script>
if (typeof showToast !== 'function') {
    window.showToast = function(message, type) {
        alert((type === 'error' ? ' ' : ' ') + message);
    };
}

function setTab(tab) {
    const tabs = ['overview', 'orders', 'listings', 'profile'];
    
    tabs.forEach(t => {
        const p = document.getElementById('panel-'+t);
        const b = document.getElementById('tab-'+t);
        const mb = document.getElementById('btn-nav-'+t);
        
        // Toggle panel visibility
        if(p) p.classList.toggle('hidden', t !== tab);
        
        // Update top desktop tabs active states
        if(b) b.classList.toggle('active', t === tab);
        
        // Update mobile bottom nav active colors dynamically
        if(mb) {
            if (t === tab) {
                mb.classList.add('text-[var(--primary)]');
                mb.classList.remove('text-[var(--text-muted)]');
            } else {
                mb.classList.remove('text-[var(--primary)]');
                mb.classList.add('text-[var(--text-muted)]');
            }
        }
    });
    history.replaceState(null,'','?tab='+tab);
}

// Initial active tab styling call
document.addEventListener('DOMContentLoaded', () => {
    const currentTab = new URLSearchParams(window.location.search).get('tab') || 'overview';
    setTab(currentTab);
});

async function updateStatus(orderId, newStatus, btn) {
    const labels = { preparing:'Preparing',in_transit:'In Transit',ready_for_pickup:'Ready for Pickup' };
    if (!confirm(`Mark Order #${orderId} as "${labels[newStatus]}"?`)) return;

    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i> Updating…';

    const form = new FormData();
    form.append('order_id', orderId);
    form.append('status', newStatus);

    try {
        const res  = await fetch('update_order_status.php', { method:'POST', body:form });
        const data = await res.json();
        if (data.success) {
            showToast('Order status updated!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Update failed', 'error');
            btn.disabled = false; btn.innerHTML = orig;
        }
    } catch(e) {
        showToast('Connection error', 'error');
        btn.disabled = false; btn.innerHTML = orig;
    }
}

async function toggleListing(listingId, currentlyActive, btn) {
    const action = currentlyActive ? 'deactivate' : 'activate';
    if (!confirm(`${action.charAt(0).toUpperCase()+action.slice(1)} this listing?`)) return;

    btn.disabled = true;
    const form = new FormData();
    form.append('listing_id', listingId);
    form.append('action', action);

    try {
        const res  = await fetch('api/toggle_listing.php', { method:'POST', body:form });
        const data = await res.json();
        if (data.success) {
            showToast(`Listing ${action}d!`, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Action failed', 'error');
            btn.disabled = false;
        }
    } catch(e) {
        showToast('Error', 'error'); btn.disabled = false;
    }
}

function filterOrders(status) {
    // Update filter button styles
    document.querySelectorAll('[id^="filter-"]').forEach(b => {
        b.classList.remove('!bg-[var(--primary)]','!text-white','!border-[var(--primary)]');
    });
    document.getElementById('filter-'+status).classList.add('!bg-[var(--primary)]','!text-white','!border-[var(--primary)]');

    // Show/hide order cards
    document.querySelectorAll('.order-card').forEach(card => {
        const show = status==='all' || card.dataset.status===status;
        card.style.display = show ? 'block' : 'none';
    });
}
</script>
</body>
</html>