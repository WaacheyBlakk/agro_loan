<?php
// public/farmer_profile.php
session_start();
require_once __DIR__ . '/../src/db.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Farmer'; // Fallback for header
$message = "";
$msgType = "";

// Fetch User + Farmer Profile
$stmt = $pdo->prepare("
    SELECT 
        u.name, u.email, u.phone,
        p.id_card, p.id_card_number,
        p.passport_photo, p.house_address,
        p.farmland_photos, p.gps_coordinates, 
        p.farm_type, p.acreage, p.crop_type, p.livestock_type,
        p.crop_expected_duration_days, p.livestock_production_days
    FROM users u
    LEFT JOIN farmer_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// FILE UPLOAD FUNCTION
function uploadFile($fieldName, $prevFile) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return $prevFile;
    }

    $dir = "../uploads/farmers/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
    $fileName = $fieldName . "_" . time() . "." . $ext;

    move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $fileName);

    return $fileName;
}

// HANDLE UPDATE (Edit Mode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === "edit") {

    $phone = trim($_POST['phone']);
    $acreage = trim($_POST['acreage']);
    $crop_type = trim($_POST['crop_type']);
    $livestock_type = trim($_POST['livestock_type']);
    $gps_coordinates = trim($_POST['gps_coordinates']);
    $farm_type = trim($_POST['farm_type']);
    $id_card_number = trim($_POST['id_card_number']);
    $house_address = trim($_POST['house_address']);
    $password = trim($_POST['password']);
    $crop_expected_duration_days = trim($_POST['crop_expected_duration_days'] ?? '');
    $livestock_production_days = trim($_POST['livestock_production_days'] ?? '');

    if (!preg_match('/^\d{10}$/', $phone)) {
        throw new Exception("Phone number must be exactly 10 digits.");
    }

    /* Upload files */
    $id_card = uploadFile("id_card", $user['id_card']);
    $passport_photo = uploadFile("passport_photo", $user['passport_photo']);
    $farmland_photos = uploadFile("farmland_photos", $user['farmland_photos']);

    try {
        $pdo->beginTransaction();

        // Update USERS table
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $query = "UPDATE users SET phone=?, password=? WHERE id=? AND role='farmer'";
            $params = [$phone, $hashed, $user_id];
        } else {
            $query = "UPDATE users SET phone=? WHERE id=? AND role='farmer'";
            $params = [$phone, $user_id];
        }

        $updateUser = $pdo->prepare($query);
        $updateUser->execute($params);

        // Update or Insert into farmer_profiles
        $check = $pdo->prepare("SELECT id FROM farmer_profiles WHERE user_id=?");
        $check->execute([$user_id]);

        if ($check->rowCount() > 0) {
            $updateProfile = $pdo->prepare("
                UPDATE farmer_profiles SET 
                    gps_coordinates=?, farm_type=?, crop_type=?, livestock_type=?, acreage=?, id_card=?, id_card_number=?, 
                    passport_photo=?, farmland_photos=?, house_address=?, crop_expected_duration_days=?, livestock_production_days=?
                WHERE user_id=?
            ");
            $updateProfile->execute([
                $gps_coordinates, $farm_type, $crop_type, $livestock_type, $acreage, $id_card, $id_card_number,
                $passport_photo, $farmland_photos, $house_address, $crop_expected_duration_days, $livestock_production_days, $user_id
            ]);
        } else {
            $insertProfile = $pdo->prepare("
                INSERT INTO farmer_profiles 
                    (user_id, gps_coordinates, farm_type, crop_type, livestock_type, acreage, id_card, id_card_number, 
                    passport_photo, farmland_photos, house_address, crop_expected_duration_days, livestock_production_days)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertProfile->execute([
                $user_id, $gps_coordinates, $farm_type, $crop_type, $livestock_type, $acreage, $id_card,
                $id_card_number, $passport_photo, $farmland_photos, $house_address, $crop_expected_duration_days, $livestock_production_days
            ]);
        }

        $pdo->commit();
        $message = "Profile updated successfully!";
        $msgType = "success";

        // Refresh data
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $mode = "view";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $msgType = "error";
    }
}

// Default mode is view unless explicitly editing
$mode = isset($_GET['edit']) && $_GET['edit'] === "1" ? "edit" : "view";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- DASHBOARD STYLES (EXACTLY MATCHING SOURCE) --- */
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

    /* SIDEBAR */
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

    /* MAIN CONTENT */
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
    .content { padding: 30px; max-width: 1200px; margin: 0 auto; width: 100%; }
    .page-header { margin-bottom: 30px; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* --- PROFILE SPECIFIC STYLES (ADAPTED TO THEME) --- */
    
    /* Cards */
    .card {
        background: var(--bg-card);
        border-radius: 16px;
        padding: 24px;
        box-shadow: var(--shadow);
        border: 1px solid #f0f0f0;
        margin-bottom: 24px;
    }
    
    .card h3 {
        margin: 0 0 20px 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--primary);
        border-bottom: 1px solid #f3f4f6;
        padding-bottom: 12px;
    }

    /* Alerts */
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: 500;
        display: flex; align-items: center; gap: 10px;
    }
    .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Profile Header Section */
    .profile-banner {
        display: flex; align-items: center; gap: 20px;
    }
    
    .profile-img-container {
        width: 100px; height: 100px; flex-shrink: 0;
        border-radius: 50%; border: 4px solid #ecfdf5;
        overflow: hidden;
    }
    .profile-img-container img {
        width: 100%; height: 100%; object-fit: cover;
    }

    .profile-info h2 { font-size: 22px; font-weight: 700; margin: 0; color: var(--text-main); }
    .profile-info p { margin: 5px 0 0; color: var(--text-muted); }

    /* Data Grids */
    .info-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }

    .info-item label {
        display: block; font-size: 12px; font-weight: 600;
        color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.5px;
    }
    .info-item .value { font-size: 16px; font-weight: 500; color: var(--text-main); }

    /* Documents */
    .doc-preview {
        display: inline-flex; align-items: center; gap: 10px;
        background: #f9fafb; padding: 10px; border-radius: 8px; border: 1px solid #e5e7eb;
        text-decoration: none; color: var(--text-main); font-size: 14px; font-weight: 500;
        transition: 0.2s;
    }
    .doc-preview:hover { border-color: var(--primary); background: #f0fdf4; }
    .doc-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; }

    /* Forms */
    .form-group { margin-bottom: 16px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
    
    input[type="text"], input[type="password"], input[type="number"], select {
        width: 100%; padding: 12px; border-radius: 8px;
        border: 1px solid #d1d5db; font-family: inherit; font-size: 14px;
        background: #f9fafb; transition: all 0.2s;
    }
    input:focus, select:focus {
        outline: none; border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); background: #fff;
    }

    input[type="file"] {
        width: 100%; padding: 10px; background: #fff;
        border: 1px dashed #d1d5db; border-radius: 8px; cursor: pointer;
    }

    /* Buttons */
    .btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px;
        cursor: pointer; transition: 0.2s; border: none; text-decoration: none;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }
    
    .btn-outline { background: transparent; border: 1px solid #d1d5db; color: var(--text-main); }
    .btn-outline:hover { background: #f3f4f6; }
    
    .btn-info { background: #eff6ff; color: #2563eb; }
    .btn-info:hover { background: #dbeafe; }

    .gps-wrap { display: flex; gap: 10px; }

    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .form-row { grid-template-columns: 1fr; }
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
            
            <div class="user-profile">
                <div style="text-align:right; margin-right:8px;">
                    <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($user['name'] ?? $username) ?></div>
                    <div style="font-size:12px; color:var(--text-muted);">Farmer</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($user['name'] ?? $username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- CONTENT -->
        <div class="content">
            <div class="page-header">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Manage your personal information and farm details.</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert <?= $msgType === 'error' ? 'error' : 'success' ?>">
                    <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>"></i>
                    <span><?= $message ?></span>
                </div>
            <?php endif; ?>

            <!-- PROFILE HEADER CARD -->
            <div class="card">
                <div class="profile-banner">
                    <div class="profile-img-container">
                        <?php 
                            $ppSrc = $user['passport_photo'] ? "../uploads/farmers/".$user['passport_photo'] : "https://ui-avatars.com/api/?name=".urlencode($user['name'])."&background=059669&color=fff"; 
                        ?>
                        <img src="<?= $ppSrc ?>" alt="Profile Photo">
                    </div>
                    <div class="profile-info" style="flex:1;">
                        <h2><?= htmlspecialchars($user['name']) ?></h2>
                        <p><?= htmlspecialchars($user['email']) ?></p>
                        <p><i data-feather="phone" style="width:14px; height:14px;"></i> <?= htmlspecialchars($user['phone'] ?? 'No phone added') ?></p>
                    </div>
                    <?php if ($mode === "view"): ?>
                        <div>
                            <a href="?edit=1" class="btn btn-primary">
                                <i data-feather="edit-2"></i> Edit Profile
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mode === "view"): ?>
                <!-- VIEW MODE -->
                
                <div class="card">
                    <h3>Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>House Address</label>
                            <div class="value"><?= htmlspecialchars($user['house_address'] ?: 'Not set') ?></div>
                        </div>
                        <div class="info-item">
                            <label>ID Card Number</label>
                            <div class="value"><?= htmlspecialchars($user['id_card_number'] ?: 'Not set') ?></div>
                        </div>
                        <div class="info-item">
                            <label>ID Document</label>
                            <?php if ($user['id_card']): ?>
                                <a href="../uploads/farmers/<?= $user['id_card'] ?>" target="_blank" class="doc-preview">
                                    <i data-feather="file-text"></i> View Document
                                </a>
                            <?php else: ?>
                                <div class="value" style="color:var(--text-muted); font-size:14px;">Not uploaded</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>Farm Details</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Farm Type</label>
                            <div class="value" style="text-transform: capitalize;"><?= htmlspecialchars($user['farm_type'] ?: 'Not set') ?></div>
                        </div>
                        <div class="info-item">
                            <label>Specific Type</label>
                            <div class="value">
                                <?php
                                if ($user['farm_type'] === 'crop') {
                                    echo htmlspecialchars($user['crop_type'] ?: '-');
                                } elseif ($user['farm_type'] === 'livestock') {
                                    echo htmlspecialchars($user['livestock_type'] ?: '-');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label>GPS Coordinates</label>
                            <div class="value"><?= htmlspecialchars($user['gps_coordinates'] ?: 'Not set') ?></div>
                        </div>
                        <div class="info-item">
                            <label>Acreage</label>
                            <div class="value"><?= htmlspecialchars($user['acreage'] ?: 'Not set') ?></div>
                        </div>
                        <div class="info-item">
                            <label>Farmland Photo</label>
                            <?php if ($user['farmland_photos']): ?>
                                <a href="../uploads/farmers/<?= $user['farmland_photos'] ?>" target="_blank" class="doc-preview">
                                    <i data-feather="image"></i> View Photo
                                </a>
                            <?php else: ?>
                                <div class="value" style="color:var(--text-muted); font-size:14px;">Not uploaded</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- EDIT MODE -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="mode" value="edit">

                    <div class="card">
                        <h3>Edit Personal Details</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" maxlength="10" pattern="\d{10}" value="<?= htmlspecialchars($user['phone']) ?>">
                            </div>
                            <div class="form-group">
                                <label>House Address</label>
                                <input type="text" name="house_address" value="<?= htmlspecialchars($user['house_address']) ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>ID Card Number</label>
                                <input type="text" name="id_card_number" value="<?= htmlspecialchars($user['id_card_number']) ?>">
                            </div>
                            <div class="form-group">
                                <label>New Password (Optional)</label>
                                <input type="password" name="password" placeholder="Leave blank to keep current">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Update Passport Photo</label>
                                <input type="file" name="passport_photo" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                            <div class="form-group">
                                <label>Update ID Card Document</label>
                                <input type="file" name="id_card" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3>Edit Farm Details</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Farm Type</label>
                                <select id="farm_type" name="farm_type" onchange="toggleFarm()">
                                    <option value="">-- Select Type --</option>
                                    <option value="crop" <?= $user['farm_type'] === 'crop' ? 'selected' : '' ?>>Crop</option>
                                    <option value="livestock" <?= $user['farm_type'] === 'livestock' ? 'selected' : '' ?>>Livestock</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Acreage</label>
                                <input type="text" name="acreage" value="<?= htmlspecialchars($user['acreage']) ?>" placeholder="e.g. 10 acres">
                            </div>
                        </div>

                        <!-- Dynamic Fields -->
                        <div id="crop_fields" style="display:none; background:#f9fafb; padding:15px; border-radius:8px; margin-bottom:20px;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Crop Types (e.g. Maize, Cassava)</label>
                                    <input type="text" name="crop_type" value="<?= htmlspecialchars($user['crop_type']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Expected Duration (Days)</label>
                                    <input type="number" name="crop_expected_duration_days" value="<?= htmlspecialchars($user['crop_expected_duration_days'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div id="livestock_fields" style="display:none; background:#f9fafb; padding:15px; border-radius:8px; margin-bottom:20px;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Livestock Types (e.g. Poultry, Goats)</label>
                                    <input type="text" name="livestock_type" value="<?= htmlspecialchars($user['livestock_type']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Production Cycle (Days)</label>
                                    <input type="number" name="livestock_production_days" value="<?= htmlspecialchars($user['livestock_production_days'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>GPS Coordinates</label>
                                <div class="gps-wrap">
                                    <input type="text" name="gps_coordinates" id="gpsInput" oninput="validateGPS()" value="<?= htmlspecialchars($user['gps_coordinates']) ?>" placeholder="Lat, Long">
                                    <button type="button" onclick="getGPS()" class="btn btn-info">
                                        <i data-feather="map-pin"></i> Locate Me
                                    </button>
                                </div>
                                <div id="gpsError" style="color:var(--danger); font-size:12px; display:none; margin-top:4px;">Invalid coordinate format.</div>
                            </div>
                            <div class="form-group">
                                <label>Update Farmland Photo</label>
                                <input type="file" name="farmland_photos" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>

                        <div style="margin-top:20px; text-align:right; border-top:1px solid #f0f0f0; padding-top:20px;">
                            <a href="farmer_profile.php" class="btn btn-outline" style="margin-right:10px;">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i data-feather="save"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </main>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Sidebar Toggle Logic
        const toggleBtn = document.getElementById("toggleBtn");
        const sidebar = document.getElementById("sidebar");

        toggleBtn.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle("active");
            } else {
                sidebar.classList.toggle("collapsed");
            }
        });

        // Farm Type Toggle Logic
        function toggleFarm(){
            const farm = document.getElementById('farm_type') ? document.getElementById('farm_type').value : '';
            const cropDiv = document.getElementById('crop_fields');
            const livestockDiv = document.getElementById('livestock_fields');
            
            if(cropDiv) cropDiv.style.display = farm === 'crop' ? 'block' : 'none';
            if(livestockDiv) livestockDiv.style.display = farm === 'livestock' ? 'block' : 'none';
        }

        // Run on page load
        document.addEventListener('DOMContentLoaded', function(){
            toggleFarm();
        });

        // GPS Logic
        function validateGPS(){
            const val = document.getElementById('gpsInput').value;
            const errorDiv = document.getElementById('gpsError');
            // Simple regex for Coordinate pair
            const regex = /^-?\d{1,3}\.\d+,\s*-?\d{1,3}\.\d+$/;
            if(val && !regex.test(val)){
                errorDiv.style.display = 'block';
            } else {
                errorDiv.style.display = 'none';
            }
        }

        function getGPS(){
            if(navigator.geolocation){
                navigator.geolocation.getCurrentPosition(pos => {
                    const lat = pos.coords.latitude.toFixed(6);
                    const long = pos.coords.longitude.toFixed(6);
                    document.getElementById('gpsInput').value = lat + ', ' + long;
                    validateGPS();
                }, err => alert("Could not get location. Permission denied or unavailable."));
            } else alert("Geolocation not supported by this browser.");
        }
    </script>
</body>
</html>