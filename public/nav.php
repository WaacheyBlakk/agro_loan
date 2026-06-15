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
        --primary:#16a34a; --primary-dark:#14532d; --accent:#22c55e; --accent-hover:#16a34a;
        --bg-body:#f8fafc; --bg-card:#ffffff; --text-main:#0f172a; --text-muted:#64748b;
        --border:#f1f5f9; --shadow:0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.05);
        --glass:rgba(255,255,255,0.85); --primary-light:#f0fdf4;
    }
    body.dark {
        --primary:#4ade80; --primary-dark:#14532d; --accent:#15803d;
        --bg-body:#090d16; --bg-card:#111827; --text-main:#f3f4f6; --text-muted:#9ca3af;
        --border:#1f2937; --shadow:0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.3);
        --glass:rgba(17,24,39,0.85); --primary-light:#14532d;
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
    
    .mobile-menu { position:fixed; top:0; right:-100%; width:85%; max-width:320px; height:100vh; background:var(--bg-card); z-index:1001; padding:30px 24px; box-shadow:-10px 0 30px rgba(0,0,0,.08); transition:right .35s cubic-bezier(0.16, 1, 0.3, 1); display:flex; flex-direction:column; gap:12px; }
    .mobile-menu.open { right:0; }
    
    .overlay { position:fixed; top:0; left:0; width:100%; height:100vh; background:rgba(15,23,42,.4); backdrop-filter:blur(4px); z-index:1000; opacity:0; visibility:hidden; transition:.3s; }
    .overlay.active { opacity:1; visibility:visible; }
    
    @media (max-width:992px) { .desktop-nav { display:none; } .mobile-toggle-btn { display:flex; } }
    .mobile-toggle-btn { display:none; width:38px; height:38px; align-items:center; justify-content:center; font-size:1.4rem; background:none; border:1px solid var(--border); border-radius:50%; color:var(--text-main); cursor:pointer; transition:.2s; }
    .mobile-toggle-btn:hover { border-color:var(--primary); color:var(--primary); }

    .cart-badge { position:absolute; top:-4px; right:-4px; background:#f68b1e; color:#fff; border-radius:9999px; min-width:18px; height:18px; padding:0 4px; font-size:9px; display:flex; align-items:center; justify-content:center; font-weight:800; box-shadow:0 2px 4px rgba(246,139,30,0.3); }
    </style>
    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="flex flex-col min-h-screen pt-16 md:pt-20">

<div class="overlay" id="overlay"></div>

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
            <!-- Cart Icon -->
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

            <?php if($is_logged): ?>
            <a href="logout.php" class="btn-login hidden md:inline-flex">Logout</a>
            <?php else: ?>
            <a href="buyers_login.php" class="btn-login hidden md:inline-flex">Login</a>
            <?php endif; ?>

            <button class="mobile-toggle-btn" id="mobileToggle" aria-label="Open Menu">
                <i class="ri-menu-5-line"></i>
            </button>
        </div>
    </div>
</header>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <div class="flex items-center justify-between mb-6 border-b border-[var(--border)] pb-4">
        <span class="font-extrabold text-lg text-[var(--primary)]">Menu</span>
        <button id="closeMenu" class="w-8 h-8 flex items-center justify-center rounded-full bg-[var(--border)] text-[var(--text-muted)] hover:text-[var(--text-main)] transition-colors">
            <i class="ri-close-line text-xl"></i>
        </button>
    </div>
    
    <div class="flex flex-col gap-1">
        <a href="shop.php" class="nav-link text-base px-4 py-3 <?= ($active_nav??'')==='shop'?'active':'' ?>">
            <i class="ri-store-2-line text-lg"></i> Shop
        </a>
        <?php if($is_logged): ?>
        <a href="wishlist.php" class="nav-link text-base px-4 py-3 <?= ($active_nav??'')==='wishlist'?'active':'' ?>">
            <i class="ri-heart-3-line text-lg"></i> Wishlist
        </a>
        <a href="cart.php" class="nav-link text-base px-4 py-3 <?= ($active_nav??'')==='cart'?'active':'' ?>">
            <i class="ri-shopping-bag-line text-lg"></i> Cart <?= ($cart_count??0)>0 ? "<span class='ml-auto bg-orange-500 text-white text-xs px-2 py-0.5 rounded-full font-bold'>$cart_count</span>" : '' ?>
        </a>
        <?php $dash = ($user_role==='farmer') ? 'seller_dashboard.php' : 'buyer_dashboard.php'; ?>
        <a href="<?= $dash ?>" class="nav-link text-base px-4 py-3 <?= ($active_nav??'')==='dashboard'?'active':'' ?>">
            <i class="ri-dashboard-3-line text-lg"></i> Dashboard
        </a>
        <div class="h-px bg-[var(--border)] my-4"></div>
        <a href="buyers_login.php" class="flex items-center gap-3 text-red-500 font-semibold px-4 py-3 hover:bg-red-50 dark:hover:bg-red-950/20 rounded-xl transition-colors">
            <i class="ri-logout-box-r-line text-lg"></i> Logout
        </a>
        <?php else: ?>
        <div class="mt-4">
            <a href="buyers_login.php" class="btn-login w-full text-center">Login / Register</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
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

    // Mobile menu
    const _mob = document.getElementById('mobileMenu');
    const _ov  = document.getElementById('overlay');
    function _toggleMenu(){ _mob.classList.toggle('open'); _ov.classList.toggle('active'); }
    document.getElementById('mobileToggle').addEventListener('click',_toggleMenu);
    document.getElementById('closeMenu').addEventListener('click',_toggleMenu);
    _ov.addEventListener('click',_toggleMenu);

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