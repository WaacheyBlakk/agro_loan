<?php
// public/agent_profile.php
session_start();
require_once '../src/db.php';

$pdo = getPDO();

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['name'] ?? 'Agent'; // Added for Topbar
$message = "";
$msgType = "";

// Fetch User + Agent Profile
$stmt = $pdo->prepare("
    SELECT 
        u.name, u.email, u.phone,
        p.id_card, p.id_card_number,
        p.passport_photo, p.gps_address,
        p.certificate_photo, p.interior_photo, p.exterior_photo, p.tin_number,
        p.interest_rate, p.loan_terms, p.qualifications
    FROM users u
    LEFT JOIN agent_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// FILE UPLOAD FUNCTION
function uploadFile($fieldName, $prevFile) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return $prevFile;
    }

    $dir = "../uploads/agents/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
    $fileName = $fieldName . "_" . time() . "." . $ext;

    move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $fileName);

    return $fileName;
}

// HANDLE UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === "edit") {

    $phone = trim($_POST['phone']);
    $gps_address = trim($_POST['gps_address']);
    $id_card_number = trim($_POST['id_card_number']);
    $interest_rate = trim($_POST['interest_rate']);
    $loan_terms = trim($_POST['loan_terms']);
    $qualifications = trim($_POST['qualifications']);
    $password = trim($_POST['password']);

    // New uploaded files
    $id_card = uploadFile("id_card", $user['id_card']);
    $passport_photo = uploadFile("passport_photo", $user['passport_photo']);
    $certificate_photo = uploadFile("certificate_photo", $user['certificate_photo']);
    $interior_photo = uploadFile("interior_photo", $user['interior_photo']);
    $exterior_photo = uploadFile("exterior_photo", $user['exterior_photo']);

    try {
        $pdo->beginTransaction();

        // Update USERS table
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $query = "UPDATE users SET phone=?, password=? WHERE id=? AND role='agent'";
            $params = [$phone, $hashed, $user_id];
        } else {
            $query = "UPDATE users SET phone=? WHERE id=? AND role='agent'";
            $params = [$phone, $user_id];
        }
        $updateUser = $pdo->prepare($query);
        $updateUser->execute($params);

        // Update or Insert agent profile
        $check = $pdo->prepare("SELECT id FROM agent_profiles WHERE user_id=?");
        $check->execute([$user_id]);

        if ($check->rowCount() > 0) {
            $updateProfile = $pdo->prepare("
                UPDATE agent_profiles SET 
                    id_card=?, id_card_number=?, passport_photo=?, gps_address=?, 
                    certificate_photo=?, interior_photo=?, exterior_photo=?, interest_rate=?, 
                    loan_terms=?, qualifications=?
                WHERE user_id=?
            ");
            $updateProfile->execute([
                $id_card, $id_card_number, $passport_photo, $gps_address,
                $certificate_photo, $interior_photo, $exterior_photo, $interest_rate,
                $loan_terms, $qualifications, $user_id
            ]);
        } else {
            $insertProfile = $pdo->prepare("
                INSERT INTO agent_profiles 
                    (user_id, id_card, id_card_number, passport_photo, gps_address, 
                    certificate_photo, interior_photo, exterior_photo, interest_rate, loan_terms, qualifications)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertProfile->execute([
                $user_id, $id_card, $id_card_number, $passport_photo, $gps_address,
                $certificate_photo, $interior_photo, $exterior_photo, $interest_rate, $loan_terms, $qualifications
            ]);
        }

        $pdo->commit();
        $message = "Profile updated successfully!";
        $msgType = "success";

        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Reset mode to view after success
        $mode = "view";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
        $msgType = "error";
        $mode = "edit"; // Stay on edit if error
    }
}

// Default mode logic
if (!isset($mode)) {
    $mode = isset($_GET['edit']) && $_GET['edit'] === "1" ? "edit" : "view";
}
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
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* --- EXACT STYLES FROM AGENT_DASHBOARD.PHP --- */
    :root {
        --primary: #1e40af;       /* Blue 800 */
        --primary-dark: #172554;  /* Blue 950 */
        --secondary: #3b82f6;     /* Blue 500 */
        
        --bg-body: #f3f4f6;
        --bg-card: #ffffff;
        --text-main: #1f2937;
        --text-muted: #6b7280;
        --danger: #ef4444;
        --warning: #f59e0b;
        --success: #10b981;
        
        --sidebar-width: 260px;
        --sidebar-collapsed: 80px;
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    * { box-sizing: border-box; outline: none; }

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
        padding: 12px 15px; color: #dbeafe;
        text-decoration: none; border-radius: 10px;
        transition: all 0.2s ease; white-space: nowrap; font-weight: 500;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1); color: #fff;
        transform: translateX(4px);
    }

    .nav-link.active {
        background: var(--secondary); color: #fff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
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

    /* --- PROFILE SPECIFIC STYLES --- */
    
    .profile-banner {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        padding: 30px;
        border-radius: 16px;
        color: white;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 30px;
        box-shadow: 0 10px 15px -3px rgba(30, 64, 175, 0.2);
    }
    
    .avatar-large {
        width: 90px; height: 90px; border-radius: 50%;
        border: 4px solid rgba(255,255,255,0.3);
        object-fit: cover; background: white;
    }

    .profile-info h2 { margin: 0; font-size: 22px; font-weight: 600; }
    .profile-info p { margin: 5px 0 0; opacity: 0.9; font-size: 14px; }

    .card {
        background: var(--bg-card); border-radius: 16px;
        padding: 24px; box-shadow: var(--shadow);
        border: 1px solid #f0f0f0; margin-bottom: 24px;
    }

    .card-title {
        font-size: 16px; font-weight: 600; color: var(--text-main);
        margin-bottom: 20px; padding-bottom: 10px;
        border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 10px;
    }

    .grid-view {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
    }

    .data-group label {
        display: block; font-size: 12px; font-weight: 600;
        color: var(--text-muted); text-transform: uppercase; margin-bottom: 5px;
    }
    .data-group .value {
        font-size: 15px; color: var(--text-main); font-weight: 500;
    }

    /* Doc Preview */
    .doc-preview {
        display: flex; align-items: center; gap: 10px;
        background: #f8fafc; padding: 8px 12px; border-radius: 8px;
        border: 1px solid #e2e8f0; width: fit-content; text-decoration: none;
        color: var(--text-main); transition: 0.2s;
    }
    .doc-preview:hover { border-color: var(--secondary); background: #eff6ff; }
    .doc-preview img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; }

    /* Forms */
    .form-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
    }
    .input-group { margin-bottom: 15px; }
    .input-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-main); margin-bottom: 6px; }
    
    .form-control {
        width: 100%; padding: 10px 12px; border-radius: 8px;
        border: 1px solid #e5e7eb; font-family: inherit; font-size: 14px;
        transition: 0.2s;
    }
    .form-control:focus { border-color: var(--secondary); outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

    .btn {
        padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 14px;
        cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px;
        text-decoration: none; transition: 0.2s;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-primary:hover { background: var(--primary-dark); }
    
    .btn-light { background: white; color: var(--text-main); border: 1px solid #e5e7eb; }
    .btn-light:hover { background: #f9fafb; }

    .btn-white-outline {
        background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);
    }
    .btn-white-outline:hover { background: rgba(255,255,255,0.3); }

    /* RESPONSIVE */
    @media (max-width: 768px) {
        .sidebar { position: fixed; height: 100%; width: 0; padding: 0; overflow: hidden; }
        .sidebar.active { width: var(--sidebar-width); padding: 20px; }
        .main { margin-left: 0; }
        .form-grid { grid-template-columns: 1fr; }
        .profile-banner { flex-direction: column; text-align: center; }
    }
</style>
</head>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <img src="../assets/images/logo.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/40'">
            <h2>AgroLoan Agent</h2>
        </div>

        <nav class="nav">
            <a href="agent_dashboard.php" class="nav-link">
                <i data-feather="home"></i>
                <span>Dashboard</span>
            </a>
            <a href="farmer_vetting.php" class="nav-link">
                <i data-feather="users"></i>
                <span>Farmer Vetting</span>
            </a>
            <a href="proof_verification.php" class="nav-link">
                <i data-feather="check-square"></i>
                <span>Proof Verify</span>
            </a>
            <a href="agent_repayments.php" class="nav-link">
                <i data-feather="credit-card"></i>
                <span>Repayments</span>
            </a>
            <a href="agent_profile.php" class="nav-link active">
                <i data-feather="user"></i>
                <span>My Profile</span>
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
                    <div style="font-size:12px; color:var(--text-muted);">Agent</div>
                </div>
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
            </div>
        </header>

        <!-- DASHBOARD CONTENT -->
        <div class="content">
            
            <!-- Profile Banner -->
            <div class="profile-banner">
                <?php 
                    $ppSrc = $user['passport_photo'] 
                        ? "../".$user['passport_photo'] 
                        : "https://ui-avatars.com/api/?name=".urlencode($user['name'])."&background=random&color=fff"; 
                ?>
                <img src="<?= $ppSrc ?>" alt="Profile" class="avatar-large">
                <div class="profile-info" style="flex:1;">
                    <h2><?= htmlspecialchars($user['name']) ?></h2>
                    <p><i data-feather="mail" style="width:14px; vertical-align:middle;"></i> <?= htmlspecialchars($user['email']) ?></p>
                    <p><i data-feather="phone" style="width:14px; vertical-align:middle;"></i> <?= htmlspecialchars($user['phone']) ?></p>
                </div>
                <?php if ($mode === "view"): ?>
                    <a href="?edit=1" class="btn btn-white-outline">
                        <i data-feather="edit-2"></i> Edit Profile
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($mode === "view"): ?>
                <!-- VIEW MODE -->
                <div class="card">
                    <div class="card-title"><i data-feather="user"></i> Personal Information</div>
                    <div class="grid-view">
                        <div class="data-group">
                            <label>Phone Number</label>
                            <div class="value"><?= htmlspecialchars($user['phone'] ?: 'Not set') ?></div>
                        </div>
                        <div class="data-group">
                            <label>GPS Address</label>
                            <div class="value"><?= htmlspecialchars($user['gps_address'] ?: 'Not set') ?></div>
                        </div>
                        <div class="data-group">
                            <label>ID Card Number</label>
                            <div class="value"><?= htmlspecialchars($user['id_card_number'] ?: 'Not set') ?></div>
                        </div>
                        <div class="data-group">
                            <label>ID Document</label>
                            <?php if ($user['id_card']): ?>
                                <a href="../<?= $user['id_card'] ?>" target="_blank" class="doc-preview">
                                    <i data-feather="file-text"></i> <span>View ID Card</span>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px; font-style:italic;">No document</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title"><i data-feather="briefcase"></i> Business & Loan Info</div>
                    <div class="grid-view">
                        <div class="data-group">
                            <label>Interest Rate</label>
                            <div class="value"><?= htmlspecialchars($user['interest_rate'] ?: '0') ?>%</div>
                        </div>
                        <div class="data-group">
                            <label>Loan Terms</label>
                            <div class="value"><?= htmlspecialchars($user['loan_terms'] ?: 'N/A') ?></div>
                        </div>
                        <div class="data-group" style="grid-column: 1 / -1;">
                            <label>Qualifications</label>
                            <div class="value"><?= htmlspecialchars($user['qualifications'] ?: 'N/A') ?></div>
                        </div>
                        
                        <div class="data-group">
                            <label>Business Certificate</label>
                            <?php if ($user['certificate_photo']): ?>
                                <a href="../<?= $user['certificate_photo'] ?>" target="_blank" class="doc-preview">
                                    <img src="../<?= $user['certificate_photo'] ?>" alt="Cert"> <span>Certificate</span>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px;">Not uploaded</span>
                            <?php endif; ?>
                        </div>

                        <div class="data-group">
                            <label>Interior Photo</label>
                            <?php if ($user['interior_photo']): ?>
                                <a href="../<?= $user['interior_photo'] ?>" target="_blank" class="doc-preview">
                                    <img src="../<?= $user['interior_photo'] ?>" alt="Interior"> <span>View Interior</span>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px;">Not uploaded</span>
                            <?php endif; ?>
                        </div>

                        <div class="data-group">
                            <label>Exterior Photo</label>
                            <?php if ($user['exterior_photo']): ?>
                                <a href="../<?= $user['exterior_photo'] ?>" target="_blank" class="doc-preview">
                                    <img src="../<?= $user['exterior_photo'] ?>" alt="Exterior"> <span>View Exterior</span>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px;">Not uploaded</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- EDIT MODE -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="mode" value="edit">

                    <div class="card">
                        <div class="card-title"><i data-feather="edit"></i> Edit Personal Details</div>
                        <div class="form-grid">
                            <div class="input-group">
                                <label>Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']) ?>">
                            </div>
                            <div class="input-group">
                                <label>GPS Address</label>
                                <input type="text" name="gps_address" class="form-control" value="<?= htmlspecialchars($user['gps_address']) ?>">
                            </div>
                            <div class="input-group">
                                <label>ID Card Number</label>
                                <input type="text" name="id_card_number" class="form-control" value="<?= htmlspecialchars($user['id_card_number']) ?>">
                            </div>
                            <div class="input-group">
                                <label>New Password (Optional)</label>
                                <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="input-group">
                                <label>Passport Photo</label>
                                <input type="file" name="passport_photo" class="form-control" accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="input-group">
                                <label>ID Card Document</label>
                                <input type="file" name="id_card" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title"><i data-feather="layers"></i> Loan & Business Info</div>
                        <div class="form-grid">
                            <div class="input-group">
                                <label>Interest Rate (%)</label>
                                <input type="text" name="interest_rate" class="form-control" value="<?= htmlspecialchars($user['interest_rate']) ?>">
                            </div>
                            <div class="input-group">
                                <label>Loan Terms</label>
                                <input type="text" name="loan_terms" class="form-control" value="<?= htmlspecialchars($user['loan_terms']) ?>">
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label>Qualifications</label>
                            <input type="text" name="qualifications" class="form-control" value="<?= htmlspecialchars($user['qualifications']) ?>">
                        </div>

                        <div class="form-grid">
                            <div class="input-group">
                                <label>Business Certificate</label>
                                <input type="file" name="certificate_photo" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                            <div class="input-group">
                                <label>Interior Photo</label>
                                <input type="file" name="interior_photo" class="form-control" accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="input-group">
                                <label>Exterior Photo</label>
                                <input type="file" name="exterior_photo" class="form-control" accept=".jpg,.jpeg,.png">
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                            <a href="agent_profile.php" class="btn btn-light">Cancel</a>
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

        // SweetAlert
        <?php if (!empty($message)): ?>
            Swal.fire({
                icon: '<?= $msgType === "success" ? "success" : "error" ?>',
                title: '<?= $msgType === "success" ? "Success" : "Error" ?>',
                text: '<?= addslashes($message) ?>',
                timer: 2000,
                showConfirmButton: false,
                confirmButtonColor: '#1e40af'
            }).then(() => {
                if ('<?= $msgType ?>' === 'success') {
                    // Remove ?edit=1 from URL cleanly after save if needed, though PHP redirect is better
                    window.location.href = 'agent_profile.php'; 
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>