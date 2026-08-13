<?php
/**
 * nav.php
 * Include this at the top of every marketplace page AFTER setting:
 *   $page_title   (string)
 *   $active_nav   (string) — 'shop' | 'wishlist' | 'cart' | 'dashboard'
 *   $is_logged    (bool)
 *   $user_role    (string|null) — 'buyer' | 'farmer'
 *   $cart_count   (int)        — pulled from DB before include
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title ?? 'AgroMarket') ?></title>
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
        --border:#f1f5f9; --shadow:0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.05);
        --glass:rgba(255,255,255,0.85); --primary-light:#ecfdf5;
    }
    body.dark {
        --primary:#4ade80; --primary-dark:#064e3b; --accent:#15803d;
        --bg-body:#090d16; --bg-card:#111827; --text-main:#f3f4f6; --text-muted:#9ca3af;
        --border:#1f2937; --shadow:0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3);
        --glass:rgba(17,24,39,0.85); --primary-light:#064e3b;
    }
    body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--bg-body); color:var(--text-main); transition:background .3s,color .3s; }
    
    header { position:fixed; top:0; width:100%; background:var(--glass); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); z-index:1000; border-bottom:1px solid var(--border); transition:all .3s ease; }
    header.scrolled { box-shadow:var(--shadow); }

    .logo-container { display:flex; align-items:center; gap:10px; text-decoration:none; flex-shrink:0; }
    .logo-container h1 { font-size:1.35rem; font-weight:800; margin:0; background:linear-gradient(135deg,var(--primary),var(--accent)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; letter-spacing:-0.025em; }
    
    .nav-link { color:var(--text-muted); text-decoration:none; font-weight:600; font-size:.9rem; padding:8px 16px; border-radius:9999px; display:flex; align-items:center; gap:6px; transition:all .2s ease-in-out; }
    .nav-link:hover { color:var(--primary); background:var(--primary-light); }
    .nav-link.active { color:var(--primary); background:var(--primary-light); }
    
    .btn-login { padding:8px 20px; background:transparent; border:1.5px solid var(--primary); border-radius:9999px; color:var(--primary); font-weight:600; transition:.2s ease-in-out; text-decoration:none; font-size:.85rem; display:inline-flex; align-items:center; justify-content:center; }
    .btn-login:hover { background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(22,163,74,0.15); }
    
    .theme-toggle { background:var(--bg-card); border:1px solid var(--border); border-radius:50%; color:var(--text-main); cursor:pointer; width:38px; height:38px; font-size:1.1rem; display:flex; justify-content:center; align-items:center; transition:.2s ease-in-out; }
    .theme-toggle:hover { border-color:var(--primary); color:var(--primary); box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    
    .cart-badge { position:absolute; top:-4px; right:-4px; background:#f68b1e; color:#fff; border-radius:9999px; min-width:18px; height:18px; padding:0 4px; font-size:9px; display:flex; align-items:center; justify-content:center; font-weight:800; box-shadow:0 2px 4px rgba(246,139,30,0.3); }

    /* MOBILE HAMBURGER BUTTON (Right top corner in mobile view) */
    .mobile-hamburger-btn {
        display: none;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        color: var(--text-main);
        font-size: 22px;
        cursor: pointer;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        transition: all 0.2s ease;
    }
    .mobile-hamburger-btn:hover {
        background: var(--primary-light);
        color: var(--primary);
        border-color: var(--primary);
    }

    /* MOBILE DRAWER BACKDROP OVERLAY */
    .mobile-drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.4);
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
        padding: 18px 20px;
        background: var(--primary-dark);
        color: #ffffff;
    }
    .drawer-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #ffffff;
    }
    .drawer-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: #ffffff;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        transition: 0.2s;
    }
    .drawer-close-btn:hover { background: rgba(255,255,255,0.3); }

    .drawer-user-card {
        padding: 16px 20px;
        background: var(--primary-light);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .drawer-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: var(--primary);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
        flex-shrink: 0;
    }
    .drawer-user-info { overflow: hidden; }
    .drawer-user-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--text-main);
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
    }
    .drawer-user-role { font-size: 12px; color: var(--text-muted); text-transform: capitalize; }

    .drawer-menu {
        padding: 12px 0;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .drawer-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        color: var(--text-main);
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: background 0.2s ease, color 0.2s ease;
    }
    .drawer-link i {
        font-size: 18px;
        color: var(--primary);
        width: 22px;
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
        margin: 8px 0;
    }

    @media (max-width: 768px) {
        .desktop-nav { display: none; }
        .mobile-hamburger-btn { display: inline-flex; }
    }
    </style>
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="flex flex-col min-h-screen pt-16 md:pt-20">

<header id="mainHeader">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 md:h-20 flex items-center justify-between transition-all duration-300" id="headerContainer">
        
        <a href="index.php" class="logo-container">
            <img src="../assets/images/logo.jpg" alt="Logo" style="height:38px;width:38px;border-radius:10px;object-fit:cover;" onerror="this.style.display='none'">
            <h1>AgroMarket</h1>
        </a>

        <!-- Desktop Nav -->
        <nav class="desktop-nav flex items-center gap-2">
            <a href="shop.php" class="nav-link <?= ($active_nav??'')==='shop'?'active':'' ?>">
                <i class="ri-store-2-line text-base"></i> Shop
            </a>
            <?php if($is_logged): ?>
            <a href="wishlist.php" class="nav-link <?= ($active_nav??'')==='wishlist'?'active':'' ?>">
                <i class="ri-heart-3-line text-base"></i> Wishlist
            </a>
            <?php $dash = ($user_role==='farmer') ? 'seller_dashboard.php' : 'buyer_dashboard.php'; ?>
            <a href="<?= $dash ?>" class="nav-link <?= ($active_nav??'')==='dashboard'?'active':'' ?>">
                <i class="ri-dashboard-3-line text-base"></i> Dashboard
            </a>
            <?php endif; ?>
        </nav>

        <div class="flex items-center gap-3">
            <!-- Cart Icon (Desktop) -->
            <?php if($is_logged): ?>
            <a href="cart.php" class="relative items-center justify-center w-10 h-10 rounded-full border border-[var(--border)] text-[var(--text-main)] hover:text-[var(--primary)] hover:border-[var(--primary)] transition-all hidden md:flex">
                <i class="ri-shopping-bag-line text-lg"></i>
                <?php if(($cart_count??0)>0): ?>
                <span class="cart-badge"><?= min($cart_count, 99) ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <button class="theme-toggle" id="themeToggle" aria-label="Toggle Theme">
                <i class="ri-moon-line"></i>
            </button>

            <!-- Login / Logout Button (strictly Desktop only) -->
            <div class="hidden md:block">
                <?php if($is_logged): ?>
                <a href="logout.php" class="btn-login">Logout</a>
                <?php else: ?>
                <a href="buyers_login.php" class="btn-login">Login</a>
                <?php endif; ?>
            </div>

            <!-- Mobile Hamburger Menu Button (Top right corner on mobile) -->
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
        <h3>Menu Options</h3>
        <button class="drawer-close-btn" onclick="toggleMobileMenu()" aria-label="Close Menu">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <?php if ($is_logged): ?>
    <?php
        $userName = $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? ($farmer['name'] ?? 'User Account');
        $userInitial = strtoupper(substr($userName, 0, 1));
    ?>
    <div class="drawer-user-card">
        <div class="drawer-avatar">
            <?= $userInitial ?>
        </div>
        <div class="drawer-user-info">
            <div class="drawer-user-name"><?= htmlspecialchars($userName) ?></div>
            <div class="drawer-user-role"><?= htmlspecialchars($user_role ?? 'User') ?> Account</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="drawer-menu">
        <a href="shop.php" class="drawer-link">
            <i class="ri-store-3-line"></i> Shop
        </a>

        <?php if($is_logged): ?>
        <a href="cart.php" class="drawer-link">
            <i class="ri-shopping-cart-2-line"></i> My Cart
            <?php if(($cart_count??0)>0): ?>
            <span class="ml-auto bg-orange-500 text-white text-xs px-2 py-0.5 rounded-full font-bold"><?= min($cart_count, 99) ?></span>
            <?php endif; ?>
        </a>
        <a href="wishlist.php" class="drawer-link">
            <i class="ri-heart-line"></i> Wishlist
        </a>

        <div class="drawer-divider"></div>

        <?php if(($user_role??'') === 'farmer'): ?>
        <a href="seller_dashboard.php" class="drawer-link">
            <i class="ri-dashboard-line"></i> Seller Dashboard
        </a>
        <a href="add_product.php" class="drawer-link">
            <i class="ri-add-circle-line"></i> Add New Listing
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
        <?php else: ?>
        <a href="buyer_dashboard.php" class="drawer-link">
            <i class="ri-dashboard-line"></i> Buyer Dashboard
        </a>
        <?php endif; ?>

        <div class="drawer-divider"></div>

        <a href="logout.php" class="drawer-link logout">
            <i class="ri-logout-box-r-line"></i> Logout
        </a>
        <?php else: ?>
        <div class="p-4 mt-auto">
            <a href="buyers_login.php" class="btn-login w-full text-center">Login / Register</a>
        </div>
        <?php endif; ?>
    </div>
</div>

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

    // Dark Mode
    const _toggleBtn = document.getElementById('themeToggle');
    const _icon      = _toggleBtn.querySelector('i');
    if (localStorage.getItem('theme')==='dark') { document.body.classList.add('dark'); _icon.className='ri-sun-line'; }
    _toggleBtn.addEventListener('click',()=>{
        document.body.classList.toggle('dark');
        const d = document.body.classList.contains('dark');
        localStorage.setItem('theme', d?'dark':'light');
        _icon.className = d ? 'ri-sun-line' : 'ri-moon-line';
    });

    // Header scroll animation
    window.addEventListener('scroll',()=>{
        const header = document.getElementById('mainHeader');
        const container = document.getElementById('headerContainer');
        const isScrolled = window.scrollY > 15;
        header.classList.toggle('scrolled', isScrolled);
        if (isScrolled) {
            container.classList.remove('h-16', 'md:h-20');
            container.classList.add('h-14', 'md:h-16');
        } else {
            container.classList.remove('h-14', 'md:h-16');
            container.classList.add('h-16', 'md:h-20');
        }
    });

    // Toast helper (global)
    function showToast(msg, type='success'){
        let c = document.getElementById('toastContainer');
        if(!c){ c=document.createElement('div'); c.id='toastContainer'; c.className='fixed top-4 right-4 z-[9999] space-y-2 pointer-events-none'; document.body.appendChild(c); }
        const t = document.createElement('div');
        const col = type==='success'?'bg-green-600':type==='error'?'bg-red-600':'bg-blue-600';
        const ic  = type==='success'?'ri-check-line':'ri-error-warning-line';
        t.className = `pointer-events-auto flex items-center gap-3 ${col} text-white px-5 py-3 rounded-lg shadow-lg transform translate-x-full transition-all duration-300`;
        t.innerHTML = `<i class="${ic} text-lg"></i><span class="font-semibold text-sm">${msg}</span>`;
        c.appendChild(t);
        requestAnimationFrame(()=>t.classList.remove('translate-x-full'));
        setTimeout(()=>{ t.classList.add('translate-x-full','opacity-0'); setTimeout(()=>t.remove(),300); },3500);
    }
</script>