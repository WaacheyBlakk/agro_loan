<?php
require_once __DIR__ . '/../src/security_headers.php';
require_once __DIR__ . '/../src/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../src/db.php';

// Check authorization: Must be logged in as a farmer (seller)
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    header('Location: login.php'); 
    exit; 
}

$user_role = $_SESSION['role'] ?? 'farmer';
if ($user_role !== 'farmer') { 
    header('Location: buyer_dashboard.php'); 
    exit; 
}

$pdo = getPDO();

// Validate and fetch the product
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: seller_dashboard.php?tab=listings');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM produce_listings WHERE id = ? AND farmer_id = ?");
$stmt->execute([$id, $user_id]);
$produce = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$produce) {
    header('Location: seller_dashboard.php?tab=listings');
    exit;
}

// Fetch categories for the select dropdown
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim(filter_input(INPUT_POST, 'produce_name', FILTER_SANITIZE_SPECIAL_CHARS));
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $price = filter_input(INPUT_POST, 'price_per_bag', FILTER_VALIDATE_FLOAT);
    $bags = filter_input(INPUT_POST, 'bags_available', FILTER_VALIDATE_INT);
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$name || !$category_id || $price === false || $price < 0 || $bags === false || $bags < 0) {
        $error = 'Please fill out all fields with valid information.';
    } else {
        $photo_filename = $produce['photo']; // Retain existing photo by default

        // Process file upload if a new photo is provided
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['photo']['tmp_name'];
            $fileName = $_FILES['photo']['name'];
            $fileSize = $_FILES['photo']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($fileExtension, $allowedExtensions)) {
                if ($fileSize < 5 * 1024 * 1024) { // 5MB limit
                    $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                    $uploadFileDir = __DIR__ . '/../uploads/produce/';
                    
                    if (!is_dir($uploadFileDir)) {
                        mkdir($uploadFileDir, 0755, true);
                    }
                    
                    $dest_path = $uploadFileDir . $newFileName;
                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        if (!empty($produce['photo']) && file_exists($uploadFileDir . $produce['photo'])) {
                            @unlink($uploadFileDir . $produce['photo']);
                        }
                        $photo_filename = $newFileName;
                    } else {
                        $error = 'There was an error saving the uploaded image.';
                    }
                } else {
                    $error = 'The image file size is too large. Max limit is 5MB.';
                }
            } else {
                $error = 'Invalid image format. Allowed formats: JPG, JPEG, PNG, WEBP.';
            }
        }

        // Save modifications to database if no validation errors occurred
        if (empty($error)) {
            $updateStmt = $pdo->prepare("
                UPDATE produce_listings 
                SET produce_name = ?, category_id = ?, price_per_bag = ?, bags_available = ?, description = ?, photo = ?
                WHERE id = ? AND farmer_id = ?
            ");
            
            if ($updateStmt->execute([$name, $category_id, $price, $bags, $description, $photo_filename, $id, $user_id])) {
                $success = 'Produce listing updated successfully.';
                // Refresh local model cache
                $produce['produce_name'] = $name;
                $produce['category_id'] = $category_id;
                $produce['price_per_bag'] = $price;
                $produce['bags_available'] = $bags;
                $produce['description'] = $description;
                $produce['photo'] = $photo_filename;
            } else {
                $error = 'Could not update database entry.';
            }
        }
    }
}

$page_title = 'Edit Produce | AgroMarket';
$active_nav = 'dashboard';
$is_logged = true;
$cart_count = $_SESSION['cart_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        agro: { 50:'#ecfdf5',100:'#d1fae5',500:'#22c55e',600:'#16a34a',700:'#15803d',900:'#064e3b' },
                        jumia: { orange:'#f68b1e', blue:'#264996' }
                    },
                    fontFamily: { sans: ['Plus Jakarta Sans','sans-serif'] }
                }
            }
        }
    </script>
    <style>
    :root {
        --primary:#16a34a; --primary-dark:#064e3b; --accent:#22c55e; --accent-hover:#16a34a;
        --bg-body:#f8fafc; --bg-card:#ffffff; --text-main:#0f172a; --text-muted:#64748b;
        --border:#e2e8f0; --shadow:0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.05);
        --glass:rgba(255,255,255,0.88); --primary-light:#ecfdf5;
    }
    body.dark {
        --primary:#4ade80; --primary-dark:#064e3b; --accent:#15803d;
        --bg-body:#090d16; --bg-card:#111827; --text-main:#f3f4f6; --text-muted:#9ca3af;
        --border:#1f2937; --shadow:0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3);
        --glass:rgba(17,24,39,0.88); --primary-light:#064e3b;
    }
    
    *, *::before, *::after {
        box-sizing: border-box;
    }

    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    body { 
        font-family:'Plus Jakarta Sans',sans-serif; 
        background:var(--bg-body); 
        color:var(--text-main); 
        transition:background .3s,color .3s;
        margin:0;
        padding-top: 70px;
        padding-bottom: 80px; /* space for mobile bottom bar */
    }
    
    header { 
        position:fixed; 
        top:0; 
        left:0;
        width:100%; 
        background:var(--glass); 
        backdrop-filter:blur(16px); 
        -webkit-backdrop-filter:blur(16px); 
        z-index:1000; 
        border-bottom:1px solid var(--border); 
        transition:all .3s ease; 
    }
    header.scrolled { box-shadow:var(--shadow); }

    .logo-container { display:flex; align-items:center; gap:8px; text-decoration:none; flex-shrink:0; }
    .logo-container h1 { font-size:1.25rem; font-weight:800; margin:0; background:linear-gradient(135deg,var(--primary),var(--accent)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; letter-spacing:-0.025em; }
    
    .nav-link { color:var(--text-muted); text-decoration:none; font-weight:600; font-size:.9rem; padding:8px 16px; border-radius:9999px; display:flex; align-items:center; gap:6px; transition:all .2s ease-in-out; }
    .nav-link:hover { color:var(--primary); background:var(--primary-light); }
    .nav-link.active { color:var(--primary); background:var(--primary-light); }
    
    .btn-login { padding:8px 20px; background:transparent; border:1.5px solid var(--primary); border-radius:9999px; color:var(--primary); font-weight:600; transition:.2s ease-in-out; text-decoration:none; font-size:.85rem; display:inline-flex; align-items:center; justify-content:center; }
    .btn-login:hover { background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(22,163,74,0.15); }
    
    .theme-toggle { background:var(--bg-card); border:1px solid var(--border); border-radius:50%; color:var(--text-main); cursor:pointer; width:38px; height:38px; font-size:1.1rem; display:flex; justify-content:center; align-items:center; transition:.2s ease-in-out; flex-shrink: 0; }
    .theme-toggle:hover { border-color:var(--primary); color:var(--primary); box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    
    .cart-badge { position:absolute; top:-4px; right:-4px; background:#f68b1e; color:#fff; border-radius:9999px; min-width:18px; height:18px; padding:0 4px; font-size:9px; display:flex; align-items:center; justify-content:center; font-weight:800; box-shadow:0 2px 4px rgba(246,139,30,0.3); }

    /* MOBILE HAMBURGER BUTTON */
    .mobile-hamburger-btn {
        display: none;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-main);
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    .mobile-hamburger-btn:hover {
        background: var(--primary-light);
        color: var(--primary);
        border-color: var(--primary);
    }

    /* MOBILE DRAWER OVERLAY */
    .mobile-drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.45);
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

    /* MOBILE SLIDE-OUT DRAWER PANEL */
    .mobile-drawer {
        position: fixed;
        top: 0;
        right: 0;
        width: 300px;
        max-width: 85vw;
        height: 100vh;
        background: var(--bg-card);
        z-index: 1001;
        box-shadow: -5px 0 25px rgba(0,0,0,0.15);
        transform: translateX(100%);
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }
    .mobile-drawer.active {
        transform: translateX(0);
    }

    .drawer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 18px;
        background: var(--primary-dark);
        color: #ffffff;
    }
    .drawer-header h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #ffffff;
    }
    .drawer-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: #ffffff;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        cursor: pointer;
        transition: 0.2s;
    }
    .drawer-close-btn:hover { background: rgba(255,255,255,0.3); }

    .drawer-user-card {
        padding: 14px 18px;
        background: var(--primary-light);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .drawer-avatar {
        width: 38px;
        height: 38px;
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
        font-size: 13.5px;
        color: var(--text-main);
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
    }
    .drawer-user-role { font-size: 11px; color: var(--text-muted); text-transform: capitalize; }

    .drawer-menu {
        padding: 10px 0;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .drawer-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 18px;
        color: var(--text-main);
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s ease, color 0.2s ease;
    }
    .drawer-link i {
        font-size: 18px;
        color: var(--primary);
        width: 20px;
        text-align: center;
    }
    .drawer-link:hover {
        background: var(--primary-light);
        color: var(--primary);
    }
    .drawer-link.logout {
        color: #ef4444;
        margin-top: auto;
    }
    .drawer-link.logout i { color: #ef4444; }
    .drawer-link.logout:hover { background: rgba(239, 68, 68, 0.1); }

    .drawer-divider {
        height: 1px;
        background: var(--border);
        margin: 6px 0;
    }

    /* Mobile Bottom Navigation Bar */
    .mobile-bottom-nav {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 62px;
        background: var(--bg-card);
        border-top: 1px solid var(--border);
        z-index: 999;
        justify-content: space-around;
        align-items: center;
        padding-bottom: env(safe-area-inset-bottom);
        box-shadow: 0 -4px 15px rgba(0,0,0,0.04);
    }
    .mobile-nav-btn {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 10px;
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
        font-size: 18px;
    }
    .mobile-nav-btn.active, .mobile-nav-btn:hover {
        color: var(--primary);
    }

    @media (max-width: 768px) {
        .desktop-nav { 
            display: none !important; 
        }
        .mobile-hamburger-btn { 
            display: inline-flex; 
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
    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 h-16 md:h-20 flex items-center justify-between transition-all duration-300" id="headerContainer">
        
        <a href="index.php" class="logo-container">
            <img src="../assets/images/logo.jpg" alt="Logo" style="height:34px;width:34px;border-radius:8px;object-fit:cover;" onerror="this.style.display='none'">
            <h1>AgroMarket</h1>
        </a>

        <!-- Desktop Navigation -->
        <nav class="desktop-nav flex items-center gap-2">
            <a href="shop.php" class="nav-link <?= ($active_nav === 'shop') ? 'active' : '' ?>">
                <i class="ri-store-2-line text-base"></i> Shop
            </a>
            <?php if($is_logged): ?>
            <a href="wishlist.php" class="nav-link <?= ($active_nav === 'wishlist') ? 'active' : '' ?>">
                <i class="ri-heart-3-line text-base"></i> Wishlist
            </a>
            <a href="seller_dashboard.php" class="nav-link <?= ($active_nav === 'dashboard') ? 'active' : '' ?>">
                <i class="ri-dashboard-3-line text-base"></i> Dashboard
            </a>
            <?php endif; ?>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Desktop Cart Icon -->
            <?php if($is_logged): ?>
            <a href="cart.php" class="relative items-center justify-center w-10 h-10 rounded-full border border-[var(--border)] text-[var(--text-main)] hover:text-[var(--primary)] hover:border-[var(--primary)] transition-all hidden md:flex">
                <i class="ri-shopping-bag-line text-lg"></i>
                <?php if(($cart_count ?? 0) > 0): ?>
                <span class="cart-badge"><?= min($cart_count, 99) ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <!-- Theme Toggle -->
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle Theme">
                <i class="ri-moon-line"></i>
            </button>

            <!-- Desktop Login / Logout Button -->
            <div class="hidden md:block">
                <?php if($is_logged): ?>
                <a href="logout.php" class="btn-login">Logout</a>
                <?php else: ?>
                <a href="buyers_login.php" class="btn-login">Login</a>
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
        <h3>Navigation Menu</h3>
        <button class="drawer-close-btn" onclick="toggleMobileMenu()" aria-label="Close Menu">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <?php if ($is_logged): ?>
    <?php
        $userName = $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Farmer Account';
        $userInitial = strtoupper(substr($userName, 0, 1));
    ?>
    <div class="drawer-user-card">
        <div class="drawer-avatar">
            <?= $userInitial ?>
        </div>
        <div class="drawer-user-info">
            <div class="drawer-user-name"><?= htmlspecialchars($userName) ?></div>
            <div class="drawer-user-role"><?= htmlspecialchars($user_role) ?> Account</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="drawer-menu">
        <a href="shop.php" class="drawer-link">
            <i class="ri-store-3-line"></i> Shop Produce
        </a>

        <?php if($is_logged): ?>
        <a href="cart.php" class="drawer-link">
            <i class="ri-shopping-cart-2-line"></i> My Cart
            <?php if(($cart_count ?? 0) > 0): ?>
            <span class="ml-auto bg-orange-500 text-white text-xs px-2 py-0.5 rounded-full font-bold"><?= min($cart_count, 99) ?></span>
            <?php endif; ?>
        </a>
        <a href="wishlist.php" class="drawer-link">
            <i class="ri-heart-line"></i> Saved Wishlist
        </a>

        <div class="drawer-divider"></div>

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
        <a href="market_disputes.php" class="drawer-link">
            <i class="ri-scales-3-line"></i> Order Disputes
        </a>

        <div class="drawer-divider"></div>

        <a href="logout.php" class="drawer-link logout">
            <i class="ri-logout-box-r-line"></i> Log Out
        </a>
        <?php else: ?>
        <div class="p-4 mt-auto">
            <a href="buyers_login.php" class="btn-login w-full text-center">Login / Register</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- FORM CONTENT CONTAINER -->
<main class="w-full max-w-3xl mx-auto px-4 py-6 flex-grow">
    
    <!-- Navigation Back Link -->
    <div class="mb-5">
        <a href="seller_dashboard.php?tab=listings" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--primary)] hover:underline">
            <i class="ri-arrow-left-line"></i> Back to Listings
        </a>
    </div>

    <!-- Edit Produce Form Card -->
    <div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl p-6 shadow-sm">
        <div class="border-b border-[var(--border)] pb-4 mb-6">
            <h2 class="text-xl font-bold text-[var(--text-main)]">Edit Listing</h2>
            <p class="text-xs text-[var(--text-muted)] mt-1">Modify information or adjust stock for your agricultural produce.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-5 p-4 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl flex items-center gap-2">
                <i class="ri-checkbox-circle-fill text-lg"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-5">
            <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <?php elseif (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                
                <!-- Produce Name -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Produce Name *</label>
                    <input type="text" name="produce_name" required value="<?= htmlspecialchars($produce['produce_name']) ?>"
                           class="w-full border border-[var(--border)] rounded-xl px-4 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Category *</label>
                    <select name="category_id" required 
                            class="w-full border border-[var(--border)] rounded-xl px-4 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                        <option value="">Select Category</option>
                        <?php foreach($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $produce['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Price per Bag -->
                <div>
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Price per Bag (₵) *</label>
                    <input type="number" step="0.01" min="0" name="price_per_bag" required value="<?= htmlspecialchars($produce['price_per_bag']) ?>"
                           class="w-full border border-[var(--border)] rounded-xl px-4 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                </div>

                <!-- Bags Available -->
                <div>
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Bags Available *</label>
                    <input type="number" min="0" name="bags_available" required value="<?= htmlspecialchars($produce['bags_available']) ?>"
                           class="w-full border border-[var(--border)] rounded-xl px-4 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition">
                </div>

                <!-- Image Upload -->
                <div>
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Produce Image</label>
                    <input type="file" name="photo" id="photoInput" accept="image/*"
                           class="w-full text-sm text-[var(--text-muted)] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[var(--primary-light)] file:text-[var(--primary)] hover:file:bg-[var(--primary)] hover:file:text-white transition">
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold uppercase text-[var(--text-muted)] tracking-wide mb-1.5">Description</label>
                    <textarea name="description" rows="5" 
                              class="w-full border border-[var(--border)] rounded-xl px-4 py-2.5 text-sm bg-[var(--bg-body)] text-[var(--text-main)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent resize-none transition"
                              placeholder="Tell buyers about your product quality, size, harvesting dates..."><?= htmlspecialchars($produce['description'] ?? '') ?></textarea>
                </div>

                <!-- Image Preview Section -->
                <div class="md:col-span-2 bg-[var(--bg-body)] rounded-xl p-4 border border-[var(--border)] flex flex-col sm:flex-row items-center gap-4">
                    <div class="w-24 h-24 rounded-lg border border-[var(--border)] bg-white overflow-hidden flex-shrink-0 flex items-center justify-center">
                        <?php 
                        $imgSrc = !empty($produce['photo']) ? "../uploads/produce/" . htmlspecialchars($produce['photo']) : "https://via.placeholder.com/300?text=No+Image";
                        ?>
                        <img id="imagePreview" src="<?= $imgSrc ?>" alt="Preview" class="object-contain w-full h-full p-1">
                    </div>
                    <div>
                        <h4 class="text-sm font-semibold text-[var(--text-main)]">Image Preview</h4>
                        <p class="text-xs text-[var(--text-muted)] mt-1">Shows the current image unless updated above. JPG, PNG, or WEBP allowed.</p>
                    </div>
                </div>

            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-[var(--border)]">
                <a href="seller_dashboard.php?tab=listings" class="px-6 py-2.5 text-sm font-semibold border border-[var(--border)] rounded-xl text-[var(--text-muted)] hover:bg-[var(--bg-body)] transition text-center">
                    Cancel
                </a>
                <button type="submit" class="px-8 py-2.5 text-sm font-bold bg-[var(--primary)] text-white hover:bg-[var(--primary-dark)] rounded-xl shadow-md transition transform active:scale-[0.98]">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</main>

<!-- MOBILE BOTTOM NAVIGATION BAR -->
<nav class="mobile-bottom-nav">
    <a href="seller_dashboard.php?tab=overview" class="mobile-nav-btn">
        <i class="ri-dashboard-line"></i>
        <span>Overview</span>
    </a>
    <a href="seller_dashboard.php?tab=orders" class="mobile-nav-btn">
        <i class="ri-shopping-bag-3-line"></i>
        <span>Orders</span>
    </a>
    <a href="add_product.php" class="mobile-nav-btn">
        <i class="ri-add-circle-line"></i>
        <span>Add Produce</span>
    </a>
    <a href="seller_dashboard.php?tab=listings" class="mobile-nav-btn active">
        <i class="ri-store-2-line"></i>
        <span>Listings</span>
    </a>
    <a href="seller_dashboard.php?tab=profile" class="mobile-nav-btn">
        <i class="ri-user-line"></i>
        <span>Profile</span>
    </a>
</nav>

<script>
// Toggle Mobile Drawer Menu
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

// Dark Mode Toggle
const _toggleBtn = document.getElementById('themeToggle');
const _icon      = _toggleBtn ? _toggleBtn.querySelector('i') : null;
if (localStorage.getItem('theme') === 'dark') { 
    document.body.classList.add('dark'); 
    if (_icon) _icon.className = 'ri-sun-line'; 
}
if (_toggleBtn) {
    _toggleBtn.addEventListener('click', () => {
        document.body.classList.toggle('dark');
        const isDark = document.body.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        if (_icon) _icon.className = isDark ? 'ri-sun-line' : 'ri-moon-line';
    });
}

// Header Scroll Shadow
window.addEventListener('scroll', () => {
    const header = document.getElementById('mainHeader');
    const container = document.getElementById('headerContainer');
    const isScrolled = window.scrollY > 15;
    if (header && container) {
        header.classList.toggle('scrolled', isScrolled);
        if (isScrolled) {
            container.classList.remove('h-16', 'md:h-20');
            container.classList.add('h-14', 'md:h-16');
        } else {
            container.classList.remove('h-14', 'md:h-16');
            container.classList.add('h-16', 'md:h-20');
        }
    }
});

// Image Preview Handler
const photoInput = document.getElementById('photoInput');
const imagePreview = document.getElementById('imagePreview');

if (photoInput && imagePreview) {
    photoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    });
}
</script>
</body>
</html>