<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$pdo = getPDO();

$buyer_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$role = $_SESSION['role'] ?? null;

if (!$buyer_id || $role !== 'buyer') {
    header("Location: buyers_login.php");
    exit;
}

$username = $_SESSION['name'] ?? 'Buyer';

$successMessage = '';
$errorMessage = '';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($amount) {
    return 'GH₵ ' . number_format((float)$amount, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $region = trim($_POST['region'] ?? '');
            $gps_address = trim($_POST['gps_address'] ?? '');
            $digital_address = trim($_POST['digital_address'] ?? '');
            $alternate_phone = trim($_POST['alternate_phone'] ?? '');

            if ($name === '' || $email === '') {
                throw new Exception("Name and email are required.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, email = ?, phone = ?
                WHERE id = ? AND role = 'buyer'
            ");
            $stmt->execute([$name, $email, $phone, $buyer_id]);

            $check = $pdo->prepare("SELECT id FROM buyer_profiles WHERE user_id = ?");
            $check->execute([$buyer_id]);

            if ($check->fetch()) {
                $stmt = $pdo->prepare("
                    UPDATE buyer_profiles
                    SET address = ?, city = ?, region = ?, gps_address = ?, digital_address = ?, alternate_phone = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $address,
                    $city,
                    $region,
                    $gps_address,
                    $digital_address,
                    $alternate_phone,
                    $buyer_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO buyer_profiles 
                    (user_id, address, city, region, gps_address, digital_address, alternate_phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $buyer_id,
                    $address,
                    $city,
                    $region,
                    $gps_address,
                    $digital_address,
                    $alternate_phone
                ]);
            }

            $pdo->commit();

            $_SESSION['name'] = $name;
            $username = $name;

            $successMessage = "Profile updated successfully.";
        }

        if ($action === 'update_cart') {
            $quantities = $_POST['quantity'] ?? [];

            foreach ($quantities as $cart_id => $qty) {
                $cart_id = (int)$cart_id;
                $qty = max(1, (int)$qty);

                $stmt = $pdo->prepare("
                    SELECT c.product_id, p.bags
                    FROM cart c
                    JOIN products p ON c.product_id = p.id
                    WHERE c.id = ? AND c.user_id = ?
                ");
                $stmt->execute([$cart_id, $buyer_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($item) {
                    $max_qty = max(1, (int)$item['bags']);
                    $qty = min($qty, $max_qty);

                    $update = $pdo->prepare("
                        UPDATE cart 
                        SET quantity = ?
                        WHERE id = ? AND user_id = ?
                    ");
                    $update->execute([$qty, $cart_id, $buyer_id]);
                }
            }

            $successMessage = "Cart updated successfully.";
        }

        if ($action === 'remove_cart_item') {
            $cart_id = (int)($_POST['cart_id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
            $stmt->execute([$cart_id, $buyer_id]);

            $successMessage = "Item removed from cart.";
        }

        if ($action === 'remove_wishlist_item') {
            $wishlist_id = (int)($_POST['wishlist_id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM wishlist_items WHERE id = ? AND user_id = ?");
            $stmt->execute([$wishlist_id, $buyer_id]);

            $successMessage = "Item removed from wishlist.";
        }

        if ($action === 'move_wishlist_to_cart') {
            $product_id = (int)($_POST['product_id'] ?? 0);

            $stock = $pdo->prepare("SELECT bags FROM products WHERE id = ?");
            $stock->execute([$product_id]);
            $available = (int)$stock->fetchColumn();

            if ($available <= 0) {
                throw new Exception("This product is currently out of stock.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO cart (user_id, product_id, quantity)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE quantity = quantity + 1
            ");
            $stmt->execute([$buyer_id, $product_id]);

            $stmt = $pdo->prepare("DELETE FROM wishlist_items WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$buyer_id, $product_id]);

            $successMessage = "Wishlist item moved to cart.";
        }

        if ($action === 'cancel_order') {
            $order_id = (int)($_POST['order_id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'cancelled'
                WHERE id = ? 
                  AND user_id = ?
                  AND status IN ('pending', 'processing')
            ");
            $stmt->execute([$order_id, $buyer_id]);

            $successMessage = "Order cancelled successfully.";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errorMessage = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Buyer Profile
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.name,
        u.email,
        u.phone,
        u.role,
        u.status,
        bp.address,
        bp.city,
        bp.region,
        bp.gps_address,
        bp.digital_address,
        bp.alternate_phone
    FROM users u
    LEFT JOIN buyer_profiles bp ON bp.user_id = u.id
    WHERE u.id = ? AND u.role = 'buyer'
");
$stmt->execute([$buyer_id]);
$buyer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$buyer) {
    session_destroy();
    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Dashboard Statistics
|--------------------------------------------------------------------------
*/
$cartCountStmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?");
$cartCountStmt->execute([$buyer_id]);
$cart_count = (int)$cartCountStmt->fetchColumn();

$wishlistCountStmt = $pdo->prepare("SELECT COUNT(*) FROM wishlist_items WHERE user_id = ?");
$wishlistCountStmt->execute([$buyer_id]);
$wishlist_count = (int)$wishlistCountStmt->fetchColumn();

$pendingOrderStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM orders 
    WHERE user_id = ? AND status IN ('pending', 'processing', 'paid')
");
$pendingOrderStmt->execute([$buyer_id]);
$pending_orders_count = (int)$pendingOrderStmt->fetchColumn();

$totalSpentStmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE user_id = ? AND status IN ('completed', 'delivered')
");
$totalSpentStmt->execute([$buyer_id]);
$total_spent = (float)$totalSpentStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Fetch Cart
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        c.id AS cart_id,
        c.quantity,
        p.id AS product_id,
        p.name,
        p.price,
        p.bags,
        p.category,
        (
            SELECT image_path 
            FROM product_images 
            WHERE product_id = p.id 
            LIMIT 1
        ) AS image_path
    FROM cart c
    JOIN products p ON p.id = c.product_id
    WHERE c.user_id = ?
    ORDER BY c.id DESC
");
$stmt->execute([$buyer_id]);
$cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cart_total = 0;
foreach ($cart_items as $item) {
    $cart_total += ((float)$item['price'] * (int)$item['quantity']);
}

/*
|--------------------------------------------------------------------------
| Fetch Wishlist
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        w.id AS wishlist_id,
        p.id AS product_id,
        p.name,
        p.price,
        p.bags,
        p.category,
        (
            SELECT image_path 
            FROM product_images 
            WHERE product_id = p.id 
            LIMIT 1
        ) AS image_path
    FROM wishlist_items w
    JOIN products p ON p.id = w.product_id
    WHERE w.user_id = ?
    ORDER BY w.id DESC
");
$stmt->execute([$buyer_id]);
$wishlist_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Fetch Pending Orders
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.total_amount,
        o.status,
        o.created_at,
        COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = ?
      AND o.status IN ('pending', 'processing', 'paid')
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$stmt->execute([$buyer_id]);
$pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Fetch Order History
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.total_amount,
        o.status,
        o.created_at,
        COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = ?
      AND o.status NOT IN ('pending', 'processing', 'paid')
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 10
");
$stmt->execute([$buyer_id]);
$order_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Buyer Dashboard | AgroLoan Market</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root {
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #0f766e;
    --brand-dark: #0d9488;
    --danger: #e53e3e;
    --success: #16a34a;
    --warning: #f59e0b;
    --bg: #f6f8fa;
    --text: #1f2937;
    --muted: #6b7280;
    --card-bg: #ffffff;
    --border: #e5e7eb;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    display: flex;
    overflow: hidden;
}

/* SIDEBAR */
.sidebar {
    width: var(--sidebar-width);
    min-width: var(--sidebar-width);
    background: var(--brand);
    color: #fff;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding: 18px;
    gap: 10px;
    transition: width .28s ease, padding .2s ease;
}

.sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
    min-width: var(--sidebar-collapsed-width);
    padding-left: 10px;
    padding-right: 10px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}

.brand .logo {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
}

.brand h2 {
    font-size: 18px;
    margin: 0;
    font-weight: 600;
    white-space: nowrap;
    transition: opacity .18s ease;
}

.sidebar.collapsed .brand h2 {
    opacity: 0;
    width: 0;
    overflow: hidden;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    transition: background .15s, transform .08s;
    white-space: nowrap;
}

.nav a .icon {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: rgba(255,255,255,0.09);
    border-radius: 6px;
}

.nav a:hover {
    background: var(--brand-dark);
    transform: translateY(-1px);
}

.nav a.active {
    background: rgba(0,0,0,0.12);
}

.sidebar.collapsed .nav a {
    justify-content: center;
    padding: 8px;
}

.sidebar.collapsed .nav a .label {
    display: none;
}

.sidebar .spacer {
    flex: 1;
}

.logout-btn {
    background: var(--danger);
    border: none;
    padding: 10px;
    color: #fff;
    font-weight: 600;
    border-radius: 8px;
    width: 100%;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    justify-content: left;
}

.logout-btn .icon {
    background: rgba(255,255,255,0.15);
    width: 34px;
    height: 34px;
    display: flex;
    border-radius: 6px;
    justify-content: center;
    align-items: center;
}

.sidebar.collapsed .logout-btn {
    justify-content: center;
    width: 48px;
    height: 48px;
    padding: 8px;
}

.sidebar.collapsed .logout-btn .label {
    display: none;
}

/* MAIN */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: auto;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 19px 24px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    background: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
}

.toggle-btn {
    background: var(--brand);
    color: #fff;
    border: none;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 18px;
}

.user-greet {
    font-size: 18px;
    font-weight: 600;
}

/* CONTENT */
.dashboard-content {
    padding: 30px 40px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
}

.page-header h1 {
    margin: 0;
    font-size: 28px;
}

.page-header p {
    margin: 6px 0 0;
    color: var(--muted);
}

.quick-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn-link {
    display: inline-block;
    background: var(--brand);
    color: #fff;
    padding: 10px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.btn-link.secondary {
    background: #334155;
}

/* ALERTS */
.alert-success,
.alert-error {
    padding: 13px 16px;
    border-radius: 10px;
    font-weight: 600;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

/* STAT CARDS */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 18px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.03);
}

.stat-card h3 {
    margin: 0;
    font-size: 14px;
    color: var(--muted);
}

.stat-card p {
    margin: 10px 0 0;
    font-size: 28px;
    font-weight: 800;
    color: var(--brand);
}

/* SECTIONS */
.card {
    background: var(--card-bg);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    border: 1px solid rgba(0,0,0,0.04);
}

.card h2 {
    margin: 0 0 16px;
    font-size: 21px;
    color: var(--brand);
}

/* GRID LAYOUT */
.two-column {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 20px;
}

@media (max-width: 980px) {
    .two-column {
        grid-template-columns: 1fr;
    }
}

/* TABLES */
.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 760px;
}

th,
td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    font-size: 14px;
}

th {
    color: var(--brand);
    text-transform: uppercase;
    font-size: 12px;
    background: #f8fafc;
}

tr:hover td {
    background: #f8fafc;
}

.product-mini {
    display: flex;
    align-items: center;
    gap: 10px;
}

.product-mini img {
    width: 54px;
    height: 54px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border);
}

.empty {
    color: var(--muted);
    padding: 12px 0;
}

/* FORMS */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}

@media (max-width: 740px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

label {
    font-weight: 600;
    font-size: 14px;
    display: block;
    margin-bottom: 6px;
}

input,
textarea,
select {
    width: 100%;
    padding: 10px 11px;
    border: 1px solid var(--border);
    border-radius: 9px;
    font-size: 14px;
    background: #fff;
}

textarea {
    min-height: 90px;
    resize: vertical;
}

.submit-btn,
.small-btn,
.danger-btn {
    border: none;
    cursor: pointer;
    border-radius: 8px;
    padding: 9px 12px;
    font-weight: 700;
    color: #fff;
}

.submit-btn {
    background: var(--brand);
    margin-top: 14px;
}

.small-btn {
    background: var(--brand);
    font-size: 13px;
}

.danger-btn {
    background: var(--danger);
    font-size: 13px;
}

.muted {
    color: var(--muted);
    font-size: 13px;
}

.status {
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}

.status.pending {
    background: #fef3c7;
    color: #92400e;
}

.status.processing {
    background: #dbeafe;
    color: #1e40af;
}

.status.paid {
    background: #e0f2fe;
    color: #075985;
}

.status.completed,
.status.delivered {
    background: #dcfce7;
    color: #166534;
}

.status.cancelled,
.status.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.qty-input {
    width: 75px;
}

.actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
</style>
</head>

<body>

<aside class="sidebar" id="buyerSidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo">
        <h2>AgroLoan Buyer</h2>
    </div>

    <nav class="nav">
        <a href="buyer_dashboard.php" class="active">
            <span class="icon">📊</span>
            <span class="label">Dashboard</span>
        </a>

        <a href="shop.php">
            <span class="icon">🛒</span>
            <span class="label">Shop</span>
        </a>

        <a href="#wishlist">
            <span class="icon">❤️</span>
            <span class="label">Wishlist</span>
        </a>

        <a href="#cart">
            <span class="icon">🧺</span>
            <span class="label">Cart</span>
        </a>

        <a href="#orders">
            <span class="icon">📦</span>
            <span class="label">Orders</span>
        </a>

        <a href="#profile">
            <span class="icon">⚙️</span>
            <span class="label">Profile</span>
        </a>
    </nav>

    <div class="spacer"></div>

    <form action="logout.php" method="POST">
        <button class="logout-btn">
            <span class="icon">🚪</span>
            <span class="label">Logout</span>
        </button>
    </form>
</aside>

<main class="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn">☰</button>
        <div class="user-greet">Welcome, <?= h($username) ?> 🛍️</div>
    </div>

    <div class="dashboard-content">

        <div class="page-header">
            <div>
                <h1>Buyer Dashboard</h1>
                <p>Manage your marketplace activity, cart, wishlist, orders, and delivery profile.</p>
            </div>

            <div class="quick-actions">
                <a href="shop.php" class="btn-link">Continue Shopping</a>
                <a href="checkout.php" class="btn-link secondary">Checkout</a>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert-success"><?= h($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert-error"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Cart Items</h3>
                <p><?= $cart_count ?></p>
            </div>

            <div class="stat-card">
                <h3>Wishlist Items</h3>
                <p><?= $wishlist_count ?></p>
            </div>

            <div class="stat-card">
                <h3>Pending Orders</h3>
                <p><?= $pending_orders_count ?></p>
            </div>

            <div class="stat-card">
                <h3>Total Spent</h3>
                <p><?= money($total_spent) ?></p>
            </div>
        </div>

        <div class="two-column">

            <!-- CART -->
            <section class="card" id="cart">
                <h2>🧺 Cart</h2>

                <?php if (empty($cart_items)): ?>
                    <p class="empty">Your cart is empty. Visit the shop to add produce.</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_cart">

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Produce</th>
                                        <th>Price</th>
                                        <th>Qty</th>
                                        <th>Subtotal</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($cart_items as $item): ?>
                                        <?php
                                            $image = $item['image_path'] 
                                                ? "../uploads/" . $item['image_path'] 
                                                : "../assets/images/placeholder.jpg";
                                            $subtotal = (float)$item['price'] * (int)$item['quantity'];
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="product-mini">
                                                    <img src="<?= h($image) ?>" alt="Product">
                                                    <div>
                                                        <strong><?= h($item['name']) ?></strong>
                                                        <div class="muted"><?= h($item['category']) ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td><?= money($item['price']) ?></td>

                                            <td>
                                                <input 
                                                    type="number" 
                                                    class="qty-input" 
                                                    name="quantity[<?= (int)$item['cart_id'] ?>]" 
                                                    value="<?= (int)$item['quantity'] ?>" 
                                                    min="1" 
                                                    max="<?= (int)$item['bags'] ?>"
                                                >
                                            </td>

                                            <td><?= money($subtotal) ?></td>

                                            <td>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="remove_cart_item">
                                                    <input type="hidden" name="cart_id" value="<?= (int)$item['cart_id'] ?>">
                                                    <button class="danger-btn" type="submit">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <p><strong>Total:</strong> <?= money($cart_total) ?></p>

                        <div class="actions-row">
                            <button class="small-btn" type="submit">Update Cart</button>
                            <a href="checkout.php" class="btn-link">Proceed to Checkout</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <!-- WISHLIST -->
            <section class="card" id="wishlist">
                <h2>❤️ Wishlist</h2>

                <?php if (empty($wishlist_items)): ?>
                    <p class="empty">No saved produce yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Produce</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($wishlist_items as $item): ?>
                                    <?php
                                        $image = $item['image_path'] 
                                            ? "../uploads/" . $item['image_path'] 
                                            : "../assets/images/placeholder.jpg";
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="product-mini">
                                                <img src="<?= h($image) ?>" alt="Product">
                                                <div>
                                                    <strong><?= h($item['name']) ?></strong>
                                                    <div class="muted"><?= h($item['category']) ?></div>
                                                </div>
                                            </div>
                                        </td>

                                        <td><?= money($item['price']) ?></td>
                                        <td><?= (int)$item['bags'] ?> bags</td>

                                        <td>
                                            <div class="actions-row">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="move_wishlist_to_cart">
                                                    <input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
                                                    <button class="small-btn" type="submit">Move to Cart</button>
                                                </form>

                                                <form method="POST">
                                                    <input type="hidden" name="action" value="remove_wishlist_item">
                                                    <input type="hidden" name="wishlist_id" value="<?= (int)$item['wishlist_id'] ?>">
                                                    <button class="danger-btn" type="submit">Remove</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- PENDING ORDERS -->
        <section class="card" id="orders">
            <h2>📦 Pending Orders</h2>

            <?php if (empty($pending_orders)): ?>
                <p class="empty">You currently have no pending orders.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($pending_orders as $order): ?>
                                <tr>
                                    <td>#<?= (int)$order['id'] ?></td>
                                    <td><?= (int)$order['item_count'] ?> item(s)</td>
                                    <td><?= money($order['total_amount']) ?></td>
                                    <td>
                                        <span class="status <?= h($order['status']) ?>">
                                            <?= h($order['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($order['created_at']) ?></td>
                                    <td>
                                        <div class="actions-row">
                                            <a href="buyer_order_details.php?id=<?= (int)$order['id'] ?>" class="btn-link">View</a>

                                            <?php if (in_array($order['status'], ['pending', 'processing'], true)): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="cancel_order">
                                                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                                    <button class="danger-btn" type="submit">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- ORDER HISTORY -->
        <section class="card">
            <h2>📜 Order History</h2>

            <?php if (empty($order_history)): ?>
                <p class="empty">No completed or cancelled orders yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Details</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($order_history as $order): ?>
                                <tr>
                                    <td>#<?= (int)$order['id'] ?></td>
                                    <td><?= (int)$order['item_count'] ?> item(s)</td>
                                    <td><?= money($order['total_amount']) ?></td>
                                    <td>
                                        <span class="status <?= h($order['status']) ?>">
                                            <?= h($order['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($order['created_at']) ?></td>
                                    <td>
                                        <a href="buyer_order_details.php?id=<?= (int)$order['id'] ?>" class="btn-link">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- PROFILE -->
        <section class="card" id="profile">
            <h2>⚙️ Buyer Profile</h2>

            <form method="POST">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-grid">
                    <div>
                        <label>Full Name</label>
                        <input type="text" name="name" value="<?= h($buyer['name'] ?? '') ?>" required>
                    </div>

                    <div>
                        <label>Email</label>
                        <input type="email" name="email" value="<?= h($buyer['email'] ?? '') ?>" required>
                    </div>

                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?= h($buyer['phone'] ?? '') ?>">
                    </div>

                    <div>
                        <label>Alternate Phone</label>
                        <input type="text" name="alternate_phone" value="<?= h($buyer['alternate_phone'] ?? '') ?>">
                    </div>

                    <div>
                        <label>City / Town</label>
                        <input type="text" name="city" value="<?= h($buyer['city'] ?? '') ?>">
                    </div>

                    <div>
                        <label>Region</label>
                        <input type="text" name="region" value="<?= h($buyer['region'] ?? '') ?>">
                    </div>

                    <div>
                        <label>GPS Address</label>
                        <input type="text" name="gps_address" value="<?= h($buyer['gps_address'] ?? '') ?>" placeholder="e.g. AK-234-5678">
                    </div>

                    <div>
                        <label>Digital Address</label>
                        <input type="text" name="digital_address" value="<?= h($buyer['digital_address'] ?? '') ?>" placeholder="optional">
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <label>Delivery Address</label>
                    <textarea name="address" placeholder="Enter full delivery address"><?= h($buyer['address'] ?? '') ?></textarea>
                </div>

                <button class="submit-btn" type="submit">Save Profile</button>
            </form>
        </section>

    </div>
</main>

<script>
document.getElementById("toggleBtn").addEventListener("click", function () {
    document.getElementById("buyerSidebar").classList.toggle("collapsed");
});
</script>

</body>
</html>