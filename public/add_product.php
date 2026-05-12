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

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    agro: { 500: '#22c55e', 600: '#16a34a', 700: '#15803d', 900: '#064e3b' }
                },
                fontFamily: { sans: ['Poppins', 'sans-serif'] }
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
    /* --- EXACT DASHBOARD CSS --- */
    :root {
        --primary: #059669; 
        --primary-dark: #064e3b; 
        --secondary: #10b981; 
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

    /* --- SIDEBAR --- */
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

    /* TOPBAR */
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

    /* DASHBOARD CONTENT */
    .content { padding: 30px; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* Tailwind Compatibility Overrides */
    .grid { display: grid; }
    .hidden { display: none; }
    .absolute { position: absolute; }
    .relative { position: relative; }
    .block { display: block; }
    .flex { display: flex; }
    .w-full { width: 100%; }
    
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
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
            <a href="farmer_profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'farmer_profile.php' ? 'active' : '' ?>">
                <i data-feather="user"></i>
                <span>Profile</span>
            </a>
        </nav>

        <form action="logout.php" method="POST">
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
            
            <!-- NEW SHOP BUTTON ADDED HERE -->
            <a href="../public/shop.php" class="hidden sm:flex items-center gap-2 bg-agro-600 hover:bg-agro-700 text-white px-5 py-2 rounded-full font-medium shadow-md transition transform hover:-translate-y-0.5">
                <i class="ri-store-2-line text-lg"></i>
                <span>Visit Shop</span>
            </a>

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
                <p class="page-subtitle">List your harvest for sale in the marketplace.</p>
            </div>

            <div class="max-w-4xl">
                
                <?php if ($success): ?>
                <div class="mb-6 bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 flex items-center gap-3">
                    <i class="ri-checkbox-circle-fill text-xl text-green-600"></i>
                    <div>
                        <p class="font-bold">Success!</p>
                        <p class="text-sm">Your produce has been listed successfully.</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-800 rounded-lg p-4">
                    <div class="flex items-center gap-2 mb-1">
                        <i class="ri-error-warning-fill text-xl text-red-600"></i>
                        <p class="font-bold">Please correct the following:</p>
                    </div>
                    <ul class="list-disc list-inside text-sm pl-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Form Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-6 sm:p-8">
                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                            <!-- Name Field -->
                            <div>
                                <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">Produce Name</label>
                                <input type="text" name="name" id="name" placeholder="e.g., Fresh Organic Tomatoes" required
                                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-agro-500 focus:border-agro-500 outline-none transition placeholder-gray-400 bg-white">
                            </div>

                            <!-- Grid: Category, Price, Bags -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                
                                <div>
                                    <label for="category" class="block text-sm font-semibold text-gray-700 mb-2">Category</label>
                                    <div class="relative">
                                        <select name="category" id="category" required class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-agro-500 focus:border-agro-500 outline-none appearance-none bg-white">
                                            <option value="" disabled selected>Select...</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <i class="ri-arrow-down-s-line absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                                    </div>
                                </div>

                                <div>
                                    <label for="price" class="block text-sm font-semibold text-gray-700 mb-2">Price per Bag (₵)</label>
                                    <input type="number" step="0.01" name="price" id="price" placeholder="0.00" required
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-agro-500 focus:border-agro-500 outline-none transition bg-white">
                                </div>

                                <div>
                                    <label for="bags" class="block text-sm font-semibold text-gray-700 mb-2">Available Bags</label>
                                    <input type="number" name="bags" id="bags" placeholder="Qty" required
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-agro-500 focus:border-agro-500 outline-none transition bg-white">
                                </div>
                            </div>

                            <div>
                                <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                                <textarea name="description" id="description" rows="4" placeholder="Describe quality, harvest date, variety..."
                                    class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-agro-500 focus:border-agro-500 outline-none transition bg-white"></textarea>
                            </div>

                            <!-- Image Upload -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Product Image</label>
                                <div class="relative group">
                                    <input type="file" name="image" id="image" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" onchange="previewImage(event)">
                                    
                                    <div id="drop-zone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center transition group-hover:border-agro-500 group-hover:bg-agro-50">
                                        <div id="preview-container" class="hidden mb-4 relative w-32 h-32 mx-auto">
                                            <img id="preview" src="#" alt="Preview" class="w-full h-full object-cover rounded-lg shadow-sm">
                                            <button type="button" onclick="resetImage()" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs z-20 shadow-md"><i class="ri-close-line"></i></button>
                                        </div>
                                        
                                        <div id="upload-prompt">
                                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-green-100 text-green-600 mb-3">
                                                <i class="ri-upload-cloud-2-line text-2xl"></i>
                                            </div>
                                            <p class="text-sm font-medium text-gray-900">Click to upload or drag and drop</p>
                                            <p class="text-xs text-gray-500 mt-1">SVG, PNG, JPG or WEBP (MAX. 5MB)</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-4">
                                <button type="submit" class="w-full bg-agro-600 hover:bg-agro-700 text-white font-bold py-3.5 rounded-lg shadow-lg hover:shadow-xl transition transform active:scale-[0.99] flex items-center justify-center gap-2">
                                    <i class="ri-add-circle-line text-xl"></i> List Produce
                                </button>
                            </div>
                        </form>
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

        function previewImage(event) {
            const input = event.target;
            const dropZone = document.getElementById('drop-zone');
            const previewContainer = document.getElementById('preview-container');
            const preview = document.getElementById('preview');
            const uploadPrompt = document.getElementById('upload-prompt');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                    uploadPrompt.classList.add('hidden');
                    dropZone.classList.add('border-agro-500', 'bg-agro-50');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function resetImage() {
            document.getElementById('image').value = '';
            document.getElementById('preview-container').classList.add('hidden');
            document.getElementById('upload-prompt').classList.remove('hidden');
            document.getElementById('drop-zone').classList.remove('border-agro-500', 'bg-agro-50');
        }
    </script>
</body>
</html>