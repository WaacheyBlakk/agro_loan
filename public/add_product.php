<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: ../login.php"); 
    exit;
}

$username = $_SESSION['name'] ?? 'Farmer'; 
$farmer_id = $_SESSION['user_id']; 

$pdo = getPDO();
$errors = [];
$success = false;

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load Categories
try {
    $catStmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "Error loading categories: " . $e->getMessage();
    $categories = [];
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid Request");
    }

    $name        = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS));
    $category_id = filter_input(INPUT_POST, 'category', FILTER_VALIDATE_INT);
    $price       = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $bags        = filter_input(INPUT_POST, 'bags', FILTER_VALIDATE_INT);
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$name || !$category_id || !$price || !$bags) {
        $errors[] = "Please fill in all required fields.";
    }

    $imageFilename = null;
    
    // File Upload Logic
    if (empty($errors) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $allowedTypes)) {
            $errors[] = "Invalid file type. Only JPG, PNG, and WebP allowed.";
        } elseif ($file['size'] > $maxSize) {
            $errors[] = "File size too large. Max 5MB.";
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $imageFilename = uniqid('prod_', true) . '.' . $ext;
            $uploadDir = "../uploads/produce/";
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $imageFilename)) {
                $errors[] = "Failed to save image.";
            }
        }
    } elseif (empty($errors)) {
        $errors[] = "Product image is required.";
    }

    if (empty($errors) && $imageFilename) {
        try {
            $sql = "INSERT INTO produce_listings (farmer_id, category_id, produce_name, price_per_bag, bags_available, description, photo, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            
            $stmt->execute([$farmer_id, $category_id, $name, $price, $bags, $description, $imageFilename]);

            $success = true;

            // Email Notification Logic
            $userStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
            $userStmt->execute([$farmer_id]);
            $farmer = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($farmer && !empty($farmer['email'])) {
                $to = $farmer['email'];
                $subject = "Produce Listed Successfully";
                $message = "Hello " . htmlspecialchars($farmer['name']) . ",\n\nYour produce '" . $name . "' has been successfully listed on Agro Market.\n\nGood luck with your sales!";
                $headers = "From: no-reply@agromarket.com";
                @mail($to, $subject, $message, $headers);
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Produce | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts (Poppins from apply_loan.php) -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Tailwind CSS Configured with Poppins -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    agro: { 50: '#f0fdf4', 100: '#dcfce7', 500: '#10b981', 600: '#059669', 700: '#047857', 900: '#064e3b' }
                },
                fontFamily: { sans: ['"Poppins"', 'sans-serif'] }
            }
        },
        corePlugins: {
            preflight: false,
        }
    }
</script>

<!-- Remix Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- EXACT CORE THEME (From apply_loan.php) --- */
    :root {
        --primary: #059669; /* Emerald 600 */
        --primary-dark: #064e3b; /* Emerald 900 */
        --secondary: #10b981; /* Emerald 500 */
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #111827;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        font-family: 'Poppins', sans-serif;
        background: var(--bg-body);
        color: var(--text-main);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }

    /* --- SIDEBAR (From apply_loan.php) --- */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--primary-dark);
        color: #fff;
        display: flex;
        flex-direction: column;
        padding: 20px;
        transition: width 0.3s ease;
        z-index: 100;
        box-shadow: 4px 0 10px rgba(0,0,0,0.1);
    }

    .sidebar.collapsed { width: var(--sidebar-collapsed); padding: 20px 10px; }

    .brand {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 40px;
        padding-left: 5px;
        overflow: hidden;
    }

    .brand img {
        width: 40px; height: 40px; border-radius: 8px;
        object-fit: cover; border: 2px solid rgba(255,255,255,0.2);
    }

    .brand h2 {
        font-size: 20px; font-weight: 600; white-space: nowrap;
        opacity: 1; transition: opacity 0.2s; margin: 0;
    }

    .sidebar.collapsed .brand h2 { opacity: 0; width: 0; }
    
    .nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }

    .nav-link {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 15px; color: #d1fae5; text-decoration: none;
        border-radius: 10px; transition: all 0.2s ease;
        white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .nav-link svg { width: 20px; height: 20px; }

    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px; }
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link:hover { transform: none; }

    .logout-btn {
        background: rgba(239, 68, 68, 0.1); color: #fca5a5;
        border: 1px solid rgba(239, 68, 68, 0.2);
        padding: 12px; border-radius: 10px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        gap: 10px; font-family: inherit; font-weight: 600;
        transition: 0.2s; width: 100%;
    }

    .logout-btn:hover { background: var(--danger); color: white; }
    .sidebar.collapsed .logout-btn span { display: none; }

    /* --- MAIN CONTENT --- */
    .main {
        flex: 1; display: flex; flex-direction: column;
        overflow-y: auto; position: relative;
    }

    /* TOPBAR (From apply_loan.php) */
    .topbar {
        background: var(--bg-card); padding: 15px 30px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: var(--shadow); position: sticky; top: 0; z-index: 50;
    }

    .toggle-btn {
        background: transparent; border: none; color: var(--text-muted);
        cursor: pointer; padding: 5px;
    }
    .toggle-btn:hover { color: var(--primary); }

    .user-profile { display: flex; align-items: center; gap: 10px; }
    .user-avatar {
        width: 35px; height: 35px; background: var(--primary);
        color: white; border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-weight: bold; font-size: 14px;
    }

    /* CONTENT VIEWPORT */
    .content { padding: 32px; max-width: 1350px; width: 100%; margin: 0 auto; }
    .page-header { margin-bottom: 32px; }
    .page-title { font-size: 26px; font-weight: 700; color: var(--text-main); margin: 0; letter-spacing: -0.5px; }
    .page-subtitle { color: var(--text-muted); margin-top: 6px; font-size: 14px; }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .content { padding: 20px; }
        .topbar { padding: 16px; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Farmer</h2>
        </div>

        <nav class="nav">
            <a href="farmer_dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_dashboard.php' ? 'active' : '' ?>">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="add_product.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'add_product.php' ? 'active' : '' ?>">
                <i data-feather="shopping-bag"></i>
                <span>Add Produce</span>
            </a>

            <a href="apply_loan.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'apply_loan.php' ? 'active' : '' ?>">
                <i data-feather="dollar-sign"></i>
                <span>Apply for Loan</span>
            </a>
            <a href="view_application.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'view_application.php' ? 'active' : '' ?>">
                <i data-feather="file-text"></i>
                <span>Applications</span>
            </a>
            <a href="upload_proof.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'upload_proof.php' ? 'active' : '' ?>">
                <i data-feather="upload-cloud"></i>
                <span>Upload Proof</span>
            </a>
            <a href="farmer_repayment.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_repayment.php' ? 'active' : '' ?>">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="farmer_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i>
                <span>Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST" class="mt-auto">
            <button class="logout-btn">
                <i data-feather="log-out"></i>
                <span>Logout</span>
            </button>
        </form>
    </aside>

    <!-- MAIN AREA -->
    <main class="main">
        <!-- TOPBAR -->
        <header class="topbar">
            <button id="toggleBtn" class="toggle-btn">
                <i data-feather="menu"></i>
            </button>
            
            <!-- Centered "Visit Shop" Navigation Container -->
            <div class="flex-grow flex justify-center">
                <a href="../public/shop.php" class="inline-flex items-center gap-2 bg-agro-600 hover:bg-agro-700 text-white px-5 py-2.5 rounded-xl font-semibold text-sm shadow-sm transition duration-150" style="text-decoration: none;">
                    <i class="ri-store-2-line text-base"></i>
                    <span>Visit Shop</span>
                </a>
            </div>

            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Farmer</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DASHBOARD CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">Add New Produce</h1>
                <p class="page-subtitle">Add your harvest details and preview how your listing will appear to buyers.</p>
            </div>

            <?php if ($success): ?>
            <div class="mb-8 bg-emerald-50 border border-emerald-100 text-emerald-800 rounded-2xl p-5 flex items-start gap-4 shadow-sm max-w-7xl">
                <div class="bg-emerald-500 text-white rounded-full p-1 flex items-center justify-center">
                    <i class="ri-checkbox-circle-fill text-xl"></i>
                </div>
                <div>
                    <p class="font-bold text-emerald-950">Successfully Listed!</p>
                    <p class="text-sm mt-1 text-emerald-700">Your produce record was stored. It is now active and displaying on the public market store.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="mb-8 bg-rose-50 border border-rose-100 text-rose-800 rounded-2xl p-5 shadow-sm max-w-7xl">
                <div class="flex items-center gap-2.5 mb-2">
                    <i class="ri-error-warning-fill text-xl text-rose-600"></i>
                    <p class="font-bold text-rose-950">Please address the following:</p>
                </div>
                <ul class="list-disc list-inside text-sm pl-2 space-y-1 text-rose-700">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- TWO COLUMN SPLIT WORKSPACE -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                
                <!-- LEFT PANEL: Dynamic Form Input -->
                <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden transition-all duration-300 hover:shadow-md">
                    <div class="p-6 sm:p-8">
                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-6" id="produceForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                            <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
                                <i class="ri-file-edit-line text-agro-600"></i>
                                Listing Specifications
                            </h3>

                            <!-- Name Field -->
                            <div>
                                <label for="name" class="block text-sm font-semibold text-slate-700 mb-2">Produce Name <span class="text-rose-500">*</span></label>
                                <div class="relative">
                                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                        <i class="ri-shopping-bag-3-line"></i>
                                    </span>
                                    <input type="text" name="name" id="name" placeholder="e.g., Organic Plantain Bags" required
                                        class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 focus:border-agro-500 focus:ring-4 focus:ring-agro-50/50 outline-none transition duration-150 text-sm placeholder-slate-400"
                                        oninput="updatePreview()">
                                </div>
                            </div>

                            <!-- Grid Input Blocks -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                                
                                <div>
                                    <label for="category" class="block text-sm font-semibold text-slate-700 mb-2">Category <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 z-10">
                                            <i class="ri-grid-fill"></i>
                                        </span>
                                        <select name="category" id="category" required 
                                            class="w-full pl-11 pr-10 py-3 rounded-xl border border-slate-200 focus:border-agro-500 focus:ring-4 focus:ring-agro-50/50 outline-none appearance-none bg-white text-sm relative"
                                            onchange="updatePreview()">
                                            <option value="" disabled selected>Select...</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>" data-name="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <i class="ri-arrow-down-s-line absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-lg"></i>
                                    </div>
                                </div>

                                <div>
                                    <label for="price" class="block text-sm font-semibold text-slate-700 mb-2">Price per Bag (₵) <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                            <i class="ri-money-dollar-circle-line"></i>
                                        </span>
                                        <input type="number" step="0.01" name="price" id="price" placeholder="0.00" required
                                            class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 focus:border-agro-500 focus:ring-4 focus:ring-agro-50/50 outline-none transition duration-150 text-sm"
                                            oninput="updatePreview()">
                                    </div>
                                </div>

                                <div>
                                    <label for="bags" class="block text-sm font-semibold text-slate-700 mb-2">Bags Available <span class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                            <i class="ri-scales-3-line"></i>
                                        </span>
                                        <input type="number" name="bags" id="bags" placeholder="Qty" required
                                            class="w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 focus:border-agro-500 focus:ring-4 focus:ring-agro-50/50 outline-none transition duration-150 text-sm"
                                            oninput="updatePreview()">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label for="description" class="block text-sm font-semibold text-slate-700 mb-2">Description</label>
                                <textarea name="description" id="description" rows="3" placeholder="Describe quality, harvest date, variety, delivery notes..."
                                    class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-agro-500 focus:ring-4 focus:ring-agro-50/50 outline-none transition duration-150 text-sm placeholder-slate-400"
                                    oninput="updatePreview()"></textarea>
                            </div>

                            <!-- Image Upload Box -->
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Product Image <span class="text-rose-500">*</span></label>
                                <div class="relative group">
                                    <input type="file" name="image" id="image" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-20" onchange="previewImage(event)">
                                    
                                    <div id="drop-zone" class="border-2 border-dashed border-slate-200 rounded-xl p-6 text-center transition duration-200 group-hover:border-agro-500 group-hover:bg-agro-50/50">
                                        
                                        <!-- Image Preview Block -->
                                        <div id="preview-container" class="hidden mb-3 relative w-24 h-24 mx-auto">
                                            <img id="preview" src="#" alt="Preview" class="w-full h-full object-cover rounded-xl shadow-sm border border-slate-100">
                                            <button type="button" onclick="resetImage(event)" class="absolute -top-1.5 -right-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-full w-6 h-6 flex items-center justify-center shadow-md z-30 transition">
                                                <i class="ri-close-line text-sm"></i>
                                            </button>
                                        </div>
                                        
                                        <div id="upload-prompt">
                                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-slate-100 text-slate-500 mb-2">
                                                <i class="ri-image-add-line text-xl"></i>
                                            </div>
                                            <p class="text-sm font-semibold text-slate-700">Click to upload or drag image here</p>
                                            <p class="text-[11px] text-slate-400 mt-1">PNG, JPG or WEBP (MAX. 5MB)</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-2">
                                <button type="submit" class="w-full bg-agro-600 hover:bg-agro-700 text-white font-semibold py-3.5 rounded-xl shadow-sm hover:shadow-md transition duration-150 flex items-center justify-center gap-2">
                                    <i class="ri-checkbox-circle-line text-lg"></i> Complete Listing
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- RIGHT PANEL: Live Preview Deck -->
                <div class="lg:col-span-5 sticky top-28">
                    <div class="mb-4">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold text-agro-700 bg-agro-100 uppercase tracking-wide">
                            <span class="w-2 h-2 rounded-full bg-agro-500 animate-pulse"></span> Live Store Preview
                        </span>
                    </div>

                    <!-- Marketplace Card Representation -->
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-md overflow-hidden transition-transform duration-300 hover:-translate-y-1">
                        
                        <!-- Header Graphic Mock -->
                        <div class="relative h-64 bg-slate-100 overflow-hidden flex items-center justify-center">
                            <img id="card-preview-img" src="https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&q=80&w=800" 
                                class="w-full h-full object-cover" alt="Product Image Preview">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent"></div>
                            
                            <span id="card-preview-category" class="absolute top-4 left-4 bg-slate-900/80 backdrop-blur-sm text-white text-xs font-bold px-3 py-1.5 rounded-lg tracking-wide uppercase">
                                Category
                            </span>
                        </div>

                        <!-- Card Meta Space -->
                        <div class="p-6">
                            <div class="flex items-center justify-between gap-4 mb-2">
                                <h3 id="card-preview-title" class="text-xl font-bold text-slate-800 truncate">
                                    My Product Title
                                </h3>
                                <span class="bg-agro-50 text-agro-600 text-xs font-bold px-2.5 py-1 rounded-md shrink-0 flex items-center gap-1 border border-agro-100">
                                    <i class="ri-shield-check-line text-sm"></i> Certified Farmer
                                </span>
                            </div>

                            <!-- Crop Short Intro -->
                            <p id="card-preview-desc" class="text-sm text-slate-500 line-clamp-2 h-10 mb-5 leading-relaxed">
                                Write a description for this produce. It helps inform buyers of the quality of your harvest.
                            </p>

                            <hr class="border-slate-100 mb-4" />

                            <!-- Product Pricing Row -->
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Price per bag</p>
                                    <p id="card-preview-price" class="text-2xl font-extrabold text-agro-700 tracking-tight mt-0.5">₵ 0.00</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Stock Available</p>
                                    <p id="card-preview-bags" class="text-sm font-bold text-slate-700 mt-1">0 Bags</p>
                                </div>
                            </div>

                            <!-- Buy Button Mock -->
                            <div class="mt-6">
                                <button type="button" class="w-full bg-slate-100 text-slate-400 font-bold py-3 rounded-xl text-sm pointer-events-none cursor-default flex items-center justify-center gap-2">
                                    <i class="ri-shopping-cart-line text-base"></i> Buy Now
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        feather.replace();

        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        // Live Preview Syncer
        function updatePreview() {
            const defaultTitle = "My Product Title";
            const defaultDesc = "Write a description for this produce. It helps inform buyers of the quality of your harvest.";
            
            // Get inputs
            const name = document.getElementById('name').value;
            const price = document.getElementById('price').value;
            const bags = document.getElementById('bags').value;
            const desc = document.getElementById('description').value;
            
            const categorySelect = document.getElementById('category');
            const selectedOpt = categorySelect.options[categorySelect.selectedIndex];
            const categoryName = selectedOpt && selectedOpt.value ? selectedOpt.getAttribute('data-name') : 'Category';

            // Sync visual elements
            document.getElementById('card-preview-title').innerText = name.trim() !== '' ? name : defaultTitle;
            document.getElementById('card-preview-desc').innerText = desc.trim() !== '' ? desc : defaultDesc;
            document.getElementById('card-preview-category').innerText = categoryName;
            
            if (price) {
                const formattedPrice = parseFloat(price).toFixed(2);
                document.getElementById('card-preview-price').innerText = `₵ ${formattedPrice}`;
            } else {
                document.getElementById('card-preview-price').innerText = '₵ 0.00';
            }

            document.getElementById('card-preview-bags').innerText = bags ? `${parseInt(bags)} Bags` : '0 Bags';
        }

        function previewImage(event) {
            const input = event.target;
            const dropZone = document.getElementById('drop-zone');
            const previewContainer = document.getElementById('preview-container');
            const preview = document.getElementById('preview');
            const uploadPrompt = document.getElementById('upload-prompt');
            const cardPreviewImg = document.getElementById('card-preview-img');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    cardPreviewImg.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                    uploadPrompt.classList.add('hidden');
                    dropZone.classList.add('border-agro-500', 'bg-agro-50/50');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function resetImage(event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            
            document.getElementById('image').value = '';
            document.getElementById('preview-container').classList.add('hidden');
            document.getElementById('upload-prompt').classList.remove('hidden');
            document.getElementById('drop-zone').classList.remove('border-agro-500', 'bg-agro-50/50');
            document.getElementById('card-preview-img').src = "https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&q=80&w=800";
        }
    </script>
</body>
</html>