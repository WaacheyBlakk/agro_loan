<?php
session_start();

require_once __DIR__ . '/../src/db.php';

$pdo = getPDO(); 

// 1. HANDLE PARAMS & SANITIZATION
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page && $page > 0) ? $page : 1;

$keyword     = trim(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$min_price   = filter_input(INPUT_GET, 'min_price', FILTER_VALIDATE_FLOAT);
$max_price   = filter_input(INPUT_GET, 'max_price', FILTER_VALIDATE_FLOAT);
$sort_by     = $_GET['sort'] ?? 'newest';

if ($min_price !== false && $min_price < 0) $min_price = 0;
if ($max_price !== false && $max_price < 0) $max_price = 0;

// 2. BUILD QUERY CONDITIONS
$conditions = [];
$params = [];

if (!empty($keyword)) {
    $conditions[] = "(p.produce_name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}

if (!empty($category_id)) {
    $conditions[] = "p.category_id = ?"; 
    $params[] = $category_id;
}

if (!empty($min_price)) {
    $conditions[] = "p.price_per_bag >= ?";
    $params[] = $min_price;
}
if (!empty($max_price)) {
    $conditions[] = "p.price_per_bag <= ?";
    $params[] = $max_price;
}

$whereSQL = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

// 3. SORTING LOGIC
$valid_sorts = [
    'price_asc'  => "ORDER BY p.price_per_bag ASC",
    'price_desc' => "ORDER BY p.price_per_bag DESC",
    'newest'     => "ORDER BY p.id DESC",
    'popularity' => "ORDER BY p.bags_available ASC"
];

$orderSQL = $valid_sorts[$sort_by] ?? $valid_sorts['newest'];

// 4. PAGINATION
$items_per_page = 20; 
$offset = ($page - 1) * $items_per_page;

$countSql = "
    SELECT COUNT(*) 
    FROM produce_listings p
    JOIN users u ON p.farmer_id = u.id
    JOIN categories c ON p.category_id = c.id
    $whereSQL
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total_items = $countStmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

// 5. FETCH DATA
$sql = "
    SELECT 
        p.id, 
        p.produce_name AS name, 
        p.photo AS image, 
        p.price_per_bag, 
        p.bags_available, 
        p.description, 
        p.category_id,
        u.name AS farmer_name, 
        c.name AS category_name,
        p.created_at
    FROM produce_listings p
    JOIN users u ON p.farmer_id = u.id
    JOIN categories c ON p.category_id = c.id
    $whereSQL
    $orderSQL
    LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);

$i = 1;
foreach ($params as $val) {
    $stmt->bindValue($i, $val);
    $i++;
}

$stmt->bindValue($i, $items_per_page, PDO::PARAM_INT);
$stmt->bindValue($i + 1, $offset, PDO::PARAM_INT);

$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. FILTER DATA & HELPER
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function buildUrl($newParams = []) {
    $queryParams = $_GET;
    foreach ($newParams as $key => $value) {
        $queryParams[$key] = $value;
    }
    $queryParams = array_filter($queryParams, function($v) { return $v !== null && $v !== ''; });
    if (isset($newParams['category']) || isset($newParams['search']) || isset($newParams['min_price']) || isset($newParams['sort'])) {
        unset($queryParams['page']);
    }
    return '?' . http_build_query($queryParams);
}

$is_logged = isset($_SESSION['user_id']) || isset($_SESSION['id']);
$user_role = $_SESSION['role'] ?? null;

function getStarRating($id) {
    $rating = 3 + ($id % 3); 
    $count = ($id * 12) % 200;
    return ['stars' => $rating, 'count' => $count];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agro Market | Shop</title>
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
        --glass: rgba(255, 255, 255, 0.95); 
        --primary-light: #dcfce7;
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
        --glass: rgba(15, 23, 42, 0.95);
        --primary-light: #14532d;
    }
    body {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        transition: background 0.3s ease, color 0.3s ease;
    }
    header {
        position: fixed;
        top: 0;
        width: 100%;
        background: var(--glass);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        padding: 15px 20px; 
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1000;
        border-bottom: 1px solid var(--border);
        transition: all 0.3s ease;
    }
    header.scrolled {
        padding: 10px 20px;
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
        height: 40px;
        width: 40px;
        border-radius: 8px;
        object-fit: cover;
    }
    .logo-container h1 {
        font-size: 1.5rem;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.5px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .header-search-container {
        flex-grow: 1;
        max-w: 600px;
        margin: 0 30px;
        display: none; 
    }
    @media (min-width: 768px) {
        .header-search-container { display: block; }
    }
    .header-right {
        display: flex; 
        align-items: center; 
        gap: 15px;
        flex-shrink: 0;
    }
    nav {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    nav a {
        color: var(--text-main);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        transition: color 0.3s;
    }
    nav a:hover, nav a.active { color: var(--primary); }
    .btn-login {
        padding: 8px 20px;
        border: 2px solid var(--primary);
        border-radius: 50px;
        color: var(--primary);
        font-weight: 600;
        transition: 0.3s;
    }
    .btn-login:hover {
        background: var(--primary);
        color: white !important;
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
    }
    .theme-toggle:hover {
        transform: rotate(15deg) scale(1.1);
        border-color: var(--primary);
    }
    .mobile-toggle-btn {
        display: none;
        font-size: 1.5rem;
        background: none;
        border: none;
        color: var(--text-main);
        cursor: pointer;
    }
    .mobile-menu {
        position: fixed;
        top: 0;
        right: -100%;
        width: 75%;
        max-width: 300px;
        height: 100vh;
        background: var(--bg-card);
        z-index: 1001;
        padding: 80px 30px;
        box-shadow: -5px 0 15px rgba(0,0,0,0.1);
        transition: right 0.4s ease;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .mobile-menu.open { right: 0; }
    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transition: 0.3s;
    }
    .overlay.active { opacity: 1; visibility: visible; }
    @media (max-width: 992px) {
        nav { display: none; }
        .mobile-toggle-btn { display: block; }
        .header-search-container { margin: 0 10px; }
    }
    .product-card { transition: all 0.2s ease; background: var(--bg-card); color: var(--text-main); }
    .product-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background-color: var(--border); border-radius: 20px; }
    </style>
</head>
<body class="flex flex-col min-h-screen pb-16 md:pb-0">

<div class="overlay" id="overlay"></div>

<header id="mainHeader">
    <a href="index.php" class="logo-container">
        <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.style.display='none'">
        <h1>AgroMarket</h1>
    </a>

    <div class="header-search-container">
        <div class="relative flex w-full">
            <input type="text" id="desktopSearchInput" name="search" value="<?= htmlspecialchars($keyword) ?>" 
                   class="block w-full pl-4 pr-12 py-2.5 rounded-full border border-[var(--border)] leading-5 bg-[var(--bg-body)] text-[var(--text-main)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent transition-shadow sm:text-sm" 
                   placeholder="Search produce instantly...">
            <button type="button" class="absolute right-0 top-0 h-full px-4 text-[var(--primary)]">
                <i class="ri-search-line text-lg"></i>
            </button>
        </div>
    </div>
    
    <div class="header-right">
        <nav>
            <a href="index.php">Home</a>
            <a href="shop.php" class="active">Shop</a>
            
            <?php if ($is_logged): ?>
                <a href="wishlist.php" title="Saved"><i class="ri-heart-line text-xl"></i></a>
                <a href="cart.php" class="relative">
                    <i class="ri-shopping-cart-2-line text-xl"></i>
                    <span id="cartBadgeCount" class="absolute -top-2 -right-2 bg-jumia-orange text-white text-[10px] font-bold h-4 w-4 rounded-full flex items-center justify-center">0</span>
                </a>
                <a href="buyer_dashboard.php" title="Dashboard"><i class="ri-user-line text-xl"></i></a>
                <a href="logout.php" class="text-red-500 hover:text-red-600"><i class="ri-logout-box-r-line text-xl"></i></a>
            <?php else: ?>
                <a href="buyers_login.php" class="btn-login">Login</a>
                <a href="buyers_registration.php" class="btn-login">Register</a>
            <?php endif; ?>
        </nav>
        
        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode"><i class="ri-moon-line"></i></button>
        <button class="mobile-toggle-btn" id="mobileToggle"><i class="ri-menu-3-line"></i></button>
    </div>
</header>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <div class="flex justify-between items-center mb-6">
        <span class="font-extrabold text-xl text-[var(--primary)]">Menu</span>
        <i class="ri-close-line text-2xl cursor-pointer text-[var(--text-main)]" id="closeMenu"></i>
    </div>
    <div class="mb-4 relative">
        <input type="text" id="mobileSearchInput" name="search" value="<?= htmlspecialchars($keyword) ?>" placeholder="Search..." 
               class="w-full p-2.5 rounded border border-[var(--border)] bg-[var(--bg-body)] text-[var(--text-main)]">
    </div>
    <a href="index.php">Home</a>
    <a href="shop.php" class="text-[var(--primary)] font-bold">Shop</a>
    
    <?php if ($is_logged): ?>
        <a href="wishlist.php">Saved Items</a>
        <a href="cart.php">My Cart</a>
        <a href="buyer_dashboard.php">Dashboard</a>
        <a href="logout.php" class="text-red-500">Logout</a>
    <?php else: ?>
        <a href="buyers_login.php">Login</a>
    <?php endif; ?>
</div>

<div class="w-full px-4 sm:px-6 pt-24 sm:pt-32 pb-8 flex items-start gap-6">

    <!-- SIDEBAR FILTERS (DYNAMICAL) -->
    <aside class="hidden md:block w-64 flex-shrink-0 bg-[var(--bg-card)] rounded-md shadow-sm border border-[var(--border)] p-4 sticky top-28 h-[calc(100vh-8rem)] overflow-y-auto custom-scrollbar">
        <div class="flex justify-between items-center mb-4 border-b border-[var(--border)] pb-2">
            <h3 class="font-bold text-[var(--text-main)] uppercase text-xs tracking-wider">Filters</h3>
            <a href="shop.php" class="text-xs text-[var(--primary)] hover:underline">Reset</a>
        </div>

        <!-- Categories -->
        <div class="mb-6">
            <h4 class="font-bold text-sm text-[var(--text-main)] mb-2">Category</h4>
            <div class="space-y-1">
                <a href="<?= buildUrl(['category' => '']) ?>" 
                   class="block text-sm px-2 py-1 rounded hover:bg-[var(--primary-light)] transition <?= !$category_id ? 'text-[var(--primary)] font-bold bg-[var(--primary-light)]' : 'text-[var(--text-muted)]' ?>">
                    All Products
                </a>
                <?php foreach($categories as $c): ?>
                    <a href="<?= buildUrl(['category' => $c['id']]) ?>" 
                       class="block text-sm px-2 py-1 rounded hover:bg-[var(--primary-light)] transition <?= $category_id == $c['id'] ? 'text-[var(--primary)] font-bold bg-[var(--primary-light)]' : 'text-[var(--text-muted)]' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Price -->
        <div class="mb-6">
            <h4 class="font-bold text-sm text-[var(--text-main)] mb-2">Price (₵)</h4>
            <form action="shop.php" method="GET" class="space-y-2">
                <input type="hidden" name="search" value="<?= htmlspecialchars($keyword) ?>">
                <?php if($category_id): ?><input type="hidden" name="category" value="<?= $category_id ?>"><?php endif; ?>
                <?php if($sort_by): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>"><?php endif; ?>
                
                <div class="flex gap-2 items-center">
                    <input type="number" name="min_price" placeholder="Min" value="<?= $min_price ?>" class="w-full px-2 py-1 text-sm border border-[var(--border)] rounded bg-[var(--bg-body)] text-[var(--text-main)]">
                    <span class="text-[var(--text-muted)]">-</span>
                    <input type="number" name="max_price" placeholder="Max" value="<?= $max_price ?>" class="w-full px-2 py-1 text-sm border border-[var(--border)] rounded bg-[var(--bg-body)] text-[var(--text-main)]">
                </div>
                <button type="submit" class="w-full bg-[var(--bg-card)] border border-[var(--primary)] text-[var(--primary)] text-xs font-bold py-1.5 rounded uppercase hover:bg-[var(--primary-light)] transition">
                    Apply
                </button>
            </form>
        </div>
    </aside>

    <!-- PRODUCT LISTING AREA -->
    <main class="flex-grow w-full">
        
        <!-- Sorting & Count Bar -->
        <div class="bg-[var(--bg-card)] p-3 rounded-md shadow-sm border border-[var(--border)] mb-4 flex justify-between items-center">
            <h2 class="font-bold text-[var(--text-main)] text-sm sm:text-base" id="searchTitle">
                <?= $keyword ? 'Search: "'.htmlspecialchars($keyword).'"' : 'All Products' ?>
            </h2>

            <div class="flex items-center gap-2">
                <span class="hidden sm:inline text-sm text-[var(--text-muted)]">Sort by:</span>
                <form action="shop.php" method="GET" id="sortForm">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($keyword) ?>">
                    <?php if($category_id): ?><input type="hidden" name="category" value="<?= $category_id ?>"><?php endif; ?>
                    <?php if($min_price): ?><input type="hidden" name="min_price" value="<?= $min_price ?>"><?php endif; ?>
                    <?php if($max_price): ?><input type="hidden" name="max_price" value="<?= $max_price ?>"><?php endif; ?>
                    
                    <select name="sort" onchange="this.form.submit()" class="border-[var(--border)] text-sm rounded cursor-pointer focus:ring-[var(--primary)] py-1 pl-2 pr-8 bg-[var(--bg-body)] text-[var(--text-main)]">
                        <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>Newest In</option>
                        <option value="popularity" <?= $sort_by == 'popularity' ? 'selected' : '' ?>>Popularity</option>
                        <option value="price_asc" <?= $sort_by == 'price_asc' ? 'selected' : '' ?>>Lowest Price</option>
                        <option value="price_desc" <?= $sort_by == 'price_desc' ? 'selected' : '' ?>>Highest Price</option>
                    </select>
                </form>
                
                <button onclick="toggleFilterDrawer()" class="md:hidden ml-2 p-2 text-[var(--text-main)] bg-[var(--bg-body)] border border-[var(--border)] rounded">
                    <i class="ri-filter-3-line"></i>
                </button>
            </div>
        </div>

        <!-- Dynamic Product Grid Wrapper -->
        <div id="productGridContainer">
            <?php if (count($products) > 0): ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 sm:gap-4">
                    <?php foreach ($products as $p): ?>
                        <?php 
                            $imgSrc = !empty($p['image']) ? "../uploads/produce/" . htmlspecialchars($p['image']) : "https://via.placeholder.com/300?text=No+Image";
                            $inStock = $p['bags_available'] > 0;
                            $ratingData = getStarRating($p['id']);
                        ?>
                        
                        <div class="product-card rounded hover:shadow-lg border border-[var(--border)] relative group flex flex-col h-full">
                            
                            <button onclick="addToWishlistDirect(<?= (int)$p['id'] ?>)" class="absolute top-2 right-2 z-10 w-8 h-8 bg-white/80 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-white shadow-sm transition">
                                <i class="ri-heart-line"></i>
                            </button>

                            <a href="product_details.php?id=<?= $p['id'] ?>" class="block relative aspect-square overflow-hidden bg-[var(--bg-body)]">
                                <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="w-full h-full object-contain mix-blend-multiply p-4 hover:scale-105 transition duration-500">
                                <?php if(!$inStock): ?>
                                    <div class="absolute inset-0 bg-white/60 flex items-center justify-center">
                                        <span class="bg-gray-800 text-white text-xs px-2 py-1 rounded">Out of Stock</span>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute bottom-0 left-0 bg-[var(--primary)] text-white text-[10px] px-2 py-0.5 uppercase font-bold tracking-wider">
                                    Official Store
                                </div>
                            </a>

                            <div class="p-3 flex flex-col flex-grow bg-[var(--bg-card)]">
                                <a href="product_details.php?id=<?= $p['id'] ?>" class="text-sm text-[var(--text-main)] font-medium line-clamp-2 mb-1 hover:underline">
                                    <?= htmlspecialchars($p['name']) ?>
                                </a>
                                
                                <div class="mt-auto">
                                    <div class="font-bold text-lg text-[var(--text-main)]">₵ <?= number_format($p['price_per_bag'], 2) ?></div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="text-[var(--text-muted)] line-through">₵ <?= number_format($p['price_per_bag'] * 1.2, 2) ?></span>
                                        <span class="text-jumia-orange bg-orange-50 px-1 rounded font-bold">-20%</span>
                                    </div>
                                </div>

                                <div class="flex items-center mt-2 mb-2">
                                    <div class="flex text-jumia-orange text-xs">
                                        <?php for($r=0; $r<5; $r++): ?>
                                            <i class="<?= $r < $ratingData['stars'] ? 'ri-star-fill' : 'ri-star-line' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="text-xs text-[var(--text-muted)] ml-1">(<?= $ratingData['count'] ?>)</span>
                                </div>

                                <div class="mt-2 space-y-2">
                                    <?php if ($user_role === 'farmer'): ?>
                                        <a href="edit_produce.php?id=<?= (int)$p['id'] ?>" 
                                           class="block text-center w-full border border-[var(--border)] text-[var(--text-muted)] text-sm font-bold py-2 rounded hover:bg-[var(--bg-body)]">
                                            Edit
                                        </a>
                                    <?php else: ?>
                                        <?php if ($inStock): ?>
                                            <button onclick="addToCartDirect(<?= (int)$p['id'] ?>, this)" 
                                                    class="w-full bg-[var(--primary)] hover:bg-[var(--primary-dark)] text-white text-sm font-bold py-2 rounded shadow-md uppercase tracking-wide transition transform active:scale-95">
                                                Add To Cart
                                            </button>
                                        <?php else: ?>
                                            <button disabled class="w-full bg-gray-200 text-gray-400 text-sm font-bold py-2 rounded cursor-not-allowed">
                                                Sold Out
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination inside the dynamic container -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-8 flex justify-center" id="paginationWrapper">
                    <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="relative inline-flex items-center rounded-l-md px-2 py-2 text-[var(--text-muted)] ring-1 ring-inset ring-[var(--border)] hover:bg-[var(--bg-body)]">
                                <i class="ri-arrow-left-s-line"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="<?= buildUrl(['page' => $i]) ?>" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold <?= $i == $page ? 'bg-[var(--primary)] text-white' : 'text-[var(--text-main)] ring-1 ring-inset ring-[var(--border)] hover:bg-[var(--bg-body)]' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="relative inline-flex items-center rounded-r-md px-2 py-2 text-[var(--text-muted)] ring-1 ring-inset ring-[var(--border)] hover:bg-[var(--bg-body)]">
                                <i class="ri-arrow-right-s-line"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="flex flex-col items-center justify-center bg-[var(--bg-card)] rounded-lg py-16 px-4 shadow-sm text-center border border-[var(--border)]">
                    <div class="w-20 h-20 bg-[var(--bg-body)] rounded-full flex items-center justify-center mb-4">
                        <i class="ri-search-eye-line text-4xl text-[var(--text-muted)]"></i>
                    </div>
                    <h3 class="text-lg font-bold text-[var(--text-main)]">No products found</h3>
                    <p class="text-[var(--text-muted)] mb-6">Try refining your filter preferences or search query.</p>
                    <a href="shop.php" class="bg-[var(--primary)] text-white px-6 py-2 rounded font-bold hover:bg-[var(--primary-dark)] transition">Reset All Filters</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- MOBILE BOTTOM NAV -->
<nav class="md:hidden fixed bottom-0 left-0 w-full bg-[var(--bg-card)] border-t border-[var(--border)] z-50 flex justify-between items-center px-6 py-2 bottom-nav text-[10px] font-medium text-[var(--text-muted)]">
    <a href="index.php" class="flex flex-col items-center gap-1 hover:text-[var(--primary)]">
        <i class="ri-home-4-line text-xl"></i> Home
    </a>
    <a href="shop.php" class="flex flex-col items-center gap-1 text-[var(--primary)]">
        <i class="ri-store-2-fill text-xl"></i> Shop
    </a>
    <?php if($is_logged): ?>
    <a href="cart.php" class="flex flex-col items-center gap-1 hover:text-[var(--primary)] relative">
        <i class="ri-shopping-cart-2-line text-xl"></i> 
        <span class="absolute top-0 right-2 bg-jumia-orange w-2 h-2 rounded-full"></span>
        Cart
    </a>
    <?php endif; ?>
    <a href="<?= $is_logged ? 'logout.php' : 'buyers_login.php' ?>" class="flex flex-col items-center gap-1 hover:text-[var(--primary)]">
        <i class="ri-user-line text-xl"></i> <?= $is_logged ? 'Logout' : 'Account' ?>
    </a>
</nav>

<!-- MOBILE FILTER DRAWER -->
<div id="filterOverlay" onclick="toggleFilterDrawer()" class="fixed inset-0 bg-black/50 z-[60] hidden transition-opacity opacity-0"></div>
<aside id="filterDrawer" class="fixed top-0 left-0 h-full w-4/5 max-w-xs bg-[var(--bg-card)] z-[70] shadow-2xl transform -translate-x-full transition-transform duration-300 overflow-y-auto text-[var(--text-main)]">
    <div class="p-4 border-b border-[var(--border)] flex justify-between items-center">
        <h3 class="font-bold text-lg">Filter Products</h3>
        <button onclick="toggleFilterDrawer()" class="text-2xl text-[var(--text-muted)]"><i class="ri-close-line"></i></button>
    </div>
    <div class="p-4">
        <form action="shop.php" method="GET" class="space-y-6">
            <input type="hidden" name="search" value="<?= htmlspecialchars($keyword) ?>">
            
            <div>
                <h4 class="font-bold mb-2">Category</h4>
                <div class="space-y-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="category" value="" class="text-[var(--primary)] focus:ring-[var(--primary)]" <?= !$category_id ? 'checked' : '' ?>>
                        All
                    </label>
                    <?php foreach($categories as $c): ?>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="category" value="<?= $c['id'] ?>" class="text-[var(--primary)] focus:ring-[var(--primary)]" <?= $category_id == $c['id'] ? 'checked' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <h4 class="font-bold mb-2">Price Range</h4>
                <div class="flex gap-2">
                    <input type="number" name="min_price" placeholder="Min" value="<?= $min_price ?>" class="w-full border border-[var(--border)] rounded p-2 bg-[var(--bg-body)]">
                    <input type="number" name="max_price" placeholder="Max" value="<?= $max_price ?>" class="w-full border border-[var(--border)] rounded p-2 bg-[var(--bg-body)]">
                </div>
            </div>

            <button type="submit" class="w-full bg-[var(--primary)] text-white font-bold py-3 rounded shadow-md hover:bg-[var(--primary-dark)]">Apply Filters</button>
        </form>
    </div>
</aside>

<div id="toastContainer" class="fixed top-4 right-4 z-[100] space-y-2 pointer-events-none"></div>

<script>
    // --- Dark Mode Logic ---
    const toggleBtn = document.getElementById('themeToggle');
    const icon = toggleBtn.querySelector('i');
    const body = document.body;

    if (localStorage.getItem('theme') === 'dark') {
        body.classList.add('dark');
        icon.className = 'ri-sun-line';
    }
    toggleBtn.addEventListener('click', () => {
        body.classList.toggle('dark');
        const isDark = body.classList.contains('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        icon.className = isDark ? 'ri-sun-line' : 'ri-moon-line';
    });

    // --- Header Scroll Effect ---
    window.addEventListener('scroll', () => {
        const header = document.getElementById('mainHeader');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // --- Mobile Menu ---
    const mobileBtn = document.getElementById('mobileToggle');
    const closeBtn = document.getElementById('closeMenu');
    const mobileMenu = document.getElementById('mobileMenu');
    const overlay = document.getElementById('overlay');

    function toggleMenu() {
        mobileMenu.classList.toggle('open');
        overlay.classList.toggle('active');
    }
    mobileBtn.addEventListener('click', toggleMenu);
    closeBtn.addEventListener('click', toggleMenu);
    overlay.addEventListener('click', toggleMenu);

    // --- Filter Drawer Logic ---
    function toggleFilterDrawer() {
        const drawer = document.getElementById('filterDrawer');
        const overlay = document.getElementById('filterOverlay');
        const isClosed = drawer.classList.contains('-translate-x-full');
        
        if (isClosed) {
            drawer.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.remove('opacity-0'), 10);
        } else {
            drawer.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0');
            setTimeout(() => { overlay.classList.add('hidden'); }, 300);
        }
    }

    // --- Instantly search as typing logic ---
    const desktopSearch = document.getElementById('desktopSearchInput');
    const mobileSearch = document.getElementById('mobileSearchInput');
    let searchDebounceTimer;

    function handleInstantSearch(e) {
        clearTimeout(searchDebounceTimer);
        const query = e.target.value;
        searchDebounceTimer = setTimeout(() => {
            fetchLiveResults(query);
        }, 300);
    }

    desktopSearch.addEventListener('input', handleInstantSearch);
    mobileSearch.addEventListener('input', handleInstantSearch);

    function fetchLiveResults(query) {
        const url = new URL(window.location.href);
        url.searchParams.set('search', query);
        url.searchParams.delete('page'); // Reset pagination on new search term

        fetch(url.toString())
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Update product grid content
                const originalContainer = document.getElementById('productGridContainer');
                const fetchedContainer = doc.getElementById('productGridContainer');
                if (originalContainer && fetchedContainer) {
                    originalContainer.innerHTML = fetchedContainer.innerHTML;
                }

                // Update search heading
                const originalTitle = document.getElementById('searchTitle');
                const fetchedTitle = doc.getElementById('searchTitle');
                if (originalTitle && fetchedTitle) {
                    originalTitle.innerHTML = fetchedTitle.innerHTML;
                }

                window.history.pushState({}, '', url.toString());
            })
            .catch(err => console.error('Error fetching live search results:', err));
    }

    // --- Direct actions ---
    function addToCartDirect(productId, btn) {
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="ri-loader-4-line animate-spin"></i>';

        const formData = new FormData();
        formData.append('product_id', productId);
        formData.append('quantity', 1);

        fetch('cart_add.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Added to cart!', 'success');
                const badge = document.getElementById('cartBadgeCount');
                if (badge && data.cart_count !== undefined) {
                    badge.textContent = data.cart_count;
                }
            } else {
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showToast(data.message || 'Error occurred', 'error');
                }
            }
        })
        .catch(() => showToast('Connection failed', 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    }

    function addToWishlistDirect(productId) {
        const formData = new FormData();
        formData.append('product_id', productId);

        fetch('wishlist_add.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showToast(data.message, 'error');
                }
            }
        })
        .catch(() => showToast('Could not save to wishlist', 'error'));
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        
        const colors = type === 'success' ? 'bg-green-600' : 'bg-red-600';
        const icon = type === 'success' ? 'ri-check-line' : 'ri-error-warning-line';

        toast.className = `pointer-events-auto flex items-center gap-3 ${colors} text-white px-6 py-3 rounded shadow-lg transform translate-x-full transition-all duration-300`;
        toast.innerHTML = `<i class="${icon} text-xl"></i> <span class="font-bold text-sm">${message}</span>`;
        
        container.appendChild(toast);
        requestAnimationFrame(() => { toast.classList.remove('translate-x-full'); });
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            toast.classList.add('opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
</script>

</body>
</html>