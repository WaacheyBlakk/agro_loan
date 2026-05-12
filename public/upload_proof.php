<?php
// public/upload_proof.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/users.php';

// Authentication Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['name'];
$farmer_id = $_SESSION['user_id'];
$pdo = getPDO();

// Message Logic
$message = "";
$msgType = ""; // 'success' or 'error'

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['proof'])) {
    $stage_id = $_POST['stage_id'] ?? null;
    $file = $_FILES['proof'];

    if ($stage_id && $file['error'] === UPLOAD_ERR_OK) {
        // Get loan ID for this stage
        $stmt = $pdo->prepare("SELECT application_id FROM loan_stages WHERE id = ?");
        $stmt->execute([$stage_id]);
        $loan_id = $stmt->fetchColumn();

        if (!$loan_id) {
            $message = "Invalid stage selected.";
            $msgType = "error";
        } else {
            // Validate file type
            $allowed = ['image/jpeg','image/png','application/pdf'];
            if (!in_array($file['type'], $allowed)) {
                $message = "Invalid file type. Only JPG, PNG, or PDF allowed.";
                $msgType = "error";
            } else {
                // Folder: uploads/app_{loan_id}/stage_{stage_id}/
                $target_dir = __DIR__ . "/../uploads/app_{$loan_id}/stage_{$stage_id}/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                $filename = time() . "_" . basename($file['name']);
                $target_path = $target_dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO stage_proofs (stage_id, farmer_id, file_path, file_type, status, uploaded_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                    ");
                    $fileType = strpos($file['type'], 'image') !== false ? 'image' : 'document';
                    $stmt->execute([$stage_id, $farmer_id, $filename, $fileType]);
                    $message = "Proof uploaded successfully! We will review it shortly.";
                    $msgType = "success";
                } else {
                    $message = "Failed to upload file. Please try again.";
                    $msgType = "error";
                }
            }
        }
    } else {
        $message = "Please select a stage and a valid file.";
        $msgType = "error";
    }
}

// Fetch farmer's stages for dropdown
$stmt = $pdo->prepare("
    SELECT ls.id AS stage_id, la.id AS loan_id, la.title, ls.stage_number
    FROM loan_stages ls
    JOIN loan_applications la ON la.id = ls.application_id
    WHERE la.farmer_id = ?
    ORDER BY la.id, ls.stage_number
");
$stmt->execute([$farmer_id]);
$stages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Proof | AgroLoan</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Feather Icons -->
<script src="https://unpkg.com/feather-icons"></script>

<style>
    /* --- DASHBOARD VARIABLES & BASE STYLES --- */
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
    .content { padding: 30px; max-width: 800px; margin: 0 auto; width: 100%; }
    .page-header { margin-bottom: 30px; text-align: center; }
    .page-title { font-size: 24px; font-weight: 700; color: var(--text-main); margin: 0; }
    .page-subtitle { color: var(--text-muted); margin-top: 5px; font-size: 14px; }

    /* --- FORM CARD STYLES --- */
    .card {
        background: var(--bg-card); padding: 30px; border-radius: 16px;
        box-shadow: var(--shadow); border: 1px solid #f0f0f0;
    }

    .form-group { margin-bottom: 20px; }
    
    label { display: block; font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
    
    select {
        width: 100%; padding: 12px; border-radius: 8px;
        border: 1px solid #d1d5db; background: #f9fafb;
        font-family: inherit; font-size: 14px; color: var(--text-main);
        outline: none; transition: 0.2s;
    }
    select:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }

    /* UPLOAD ZONE */
    .upload-zone {
        border: 2px dashed #d1d5db; border-radius: 12px;
        padding: 40px 20px; text-align: center;
        background: #f9fafb; cursor: pointer;
        position: relative; transition: all 0.2s;
    }

    .upload-zone:hover, .upload-zone.dragover {
        border-color: var(--primary); background: #ecfdf5;
    }

    .upload-icon { color: var(--text-muted); width: 48px; height: 48px; margin-bottom: 10px; }
    
    .upload-text { font-size: 14px; color: var(--text-muted); font-weight: 500; }
    .upload-text strong { color: var(--primary); }

    .file-input-hidden {
        position: absolute; width: 100%; height: 100%;
        top: 0; left: 0; opacity: 0; cursor: pointer;
    }

    /* BUTTONS */
    .btn-submit {
        background: var(--primary); color: white; width: 100%;
        padding: 14px; border-radius: 10px; border: none;
        font-weight: 600; font-size: 15px; cursor: pointer;
        transition: background 0.2s; display: flex; 
        justify-content: center; align-items: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--primary-dark); }

    /* ALERTS */
    .alert {
        padding: 15px; border-radius: 10px; margin-bottom: 20px;
        display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500;
    }
    .alert.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* RESPONSIVE */
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
                <h1 class="page-title">Upload Payment Proof</h1>
                <p class="page-subtitle">Verify your loan usage by uploading receipts or photos.</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert <?= $msgType === 'error' ? 'error' : 'success' ?>">
                    <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>"></i>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="stage_id">Select Loan Stage</label>
                        <select name="stage_id" id="stage_id" required>
                            <option value="">-- Choose a Stage --</option>
                            <?php foreach ($stages as $stage): ?>
                                <option value="<?= $stage['stage_id'] ?>">
                                    <?= htmlspecialchars($stage['title']) ?> (Stage <?= $stage['stage_number'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Proof Document</label>
                        <div class="upload-zone" id="dropArea">
                            <input type="file" name="proof" id="proof" class="file-input-hidden" accept=".jpg,.jpeg,.png,.pdf" required>
                            <i data-feather="upload-cloud" class="upload-icon"></i>
                            <div class="upload-text" id="fileMsg">
                                <strong>Click to upload</strong> or drag and drop<br>
                                <span style="font-size:12px; font-weight:400;">(JPG, PNG or PDF)</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i data-feather="check"></i> Submit Proof
                    </button>
                </form>
            </div>
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

        // File Upload Interaction
        const fileInput = document.getElementById('proof');
        const fileMsg = document.getElementById('fileMsg');
        const dropArea = document.getElementById('dropArea');
        const originalText = fileMsg.innerHTML;

        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                fileMsg.innerHTML = "<strong>Selected:</strong> " + this.files[0].name;
                fileMsg.style.color = "var(--primary)";
            } else {
                fileMsg.innerHTML = originalText;
                fileMsg.style.color = "var(--text-muted)";
            }
        });

        // Drag and Drop Visuals
        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropArea.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropArea.classList.remove('dragover');
            });
        });
    </script>
</body>
</html>