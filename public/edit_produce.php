<?php
session_start();
require_once __DIR__ . '/../src/db.php';

// Check authorization: Must be logged in as a farmer (seller)
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
if (!$user_id) { 
    header('Location: buyers_login.php'); 
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
    // Produce listing not found or doesn't belong to this seller
    header('Location: seller_dashboard.php?tab=listings');
    exit;
}

// Fetch categories for the select dropdown
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                        // Optional: delete old image file if it exists and is different
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
$is_logged = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?></title>
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
                        agro: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' }
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
    nav a:hover { color: var(--primary); }
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
    </style>
</head>
<body class="flex flex-col min-h-screen pb-20 md:pb-0">

<header id="mainHeader">
    <a href="index.php" class="logo-container">
        <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.style.display='none'">
        <h1>AgroMarket</h1>
    </a>
    
    <div class="header-right">
        <nav>
            <a href="seller_dashboard.php?tab=overview">Dashboard</a>
            <a href="seller_dashboard.php?tab=listings" class="text-[var(--primary)]">My Listings</a>
            <a href="add_product.php">Add New</a>
            <a href="logout.php" class="text-red-500 hover:text-red-600"><i class="ri-logout-box-r-line text-xl"></i></a>
        </nav>
        
        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode"><i class="ri-moon-line"></i></button>
    </div>
</header>

<div class="w-full max-w-3xl mx-auto px-4 pt-28 pb-12 flex-grow">
    
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

                <!-- Image Upload and Preview -->
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
</div>

<!-- Standardized Seller Mobile Bottom Nav -->
<nav class="md:hidden fixed bottom-0 left-0 w-full z-50 flex justify-between items-center px-4 py-2 text-[10px] font-semibold bg-[var(--bg-card)] border-t border-[var(--border)] shadow-lg">
    <a href="seller_dashboard.php?tab=overview" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-dashboard-line text-xl"></i>Overview
    </a>
    <a href="seller_dashboard.php?tab=orders" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-shopping-bag-3-line text-xl"></i>Orders
    </a>
    <a href="add_product.php" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-add-circle-line text-xl"></i>New
    </a>
    <a href="seller_dashboard.php?tab=listings" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--primary)]">
        <i class="ri-store-2-fill text-xl"></i>My Listings
    </a>
    <a href="seller_dashboard.php?tab=profile" class="flex flex-col items-center gap-1 transition w-full py-1 text-[var(--text-muted)]">
        <i class="ri-user-line text-xl"></i>Profile
    </a>
</nav>

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

    // --- Image Preview Handler ---
    const photoInput = document.getElementById('photoInput');
    const imagePreview = document.getElementById('imagePreview');

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
</script>

</body>
</html>