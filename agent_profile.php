<?php
session_start();
require_once __DIR__ . '/../src/db.php';

$pdo = getPDO();

// Ensure logged-in agent
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: login.php");
    exit();
}

$agent_id = $_SESSION['user_id'];
$username = $_SESSION['name'];
$successMessage = $errorMessage = "";

/* =======================
   FETCH AGENT DATA
======================= */
$stmt = $pdo->prepare("
    SELECT 
        u.name,
        u.email,
        u.phone,
        u.id_card,
        u.id_card_number,
        u.passport_photo,
        u.interior_photo,
        u.exterior_photo,
        u.gps_address,
        u.tin_number,
        u.certificate_photo,
        ap.interest_rate,
        ap.loan_terms,
        ap.qualifications
    FROM users u
    LEFT JOIN agent_profiles ap ON u.id = ap.user_id
    WHERE u.id = ? AND u.role = 'agent'
");
$stmt->execute([$agent_id]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

/* =======================
   HANDLE FORM SUBMISSION
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']);
    $email  = trim($_POST['email']);
    $phone  = trim($_POST['phone']);
    $interest_rate = trim($_POST['interest_rate']);
    $loan_terms = trim($_POST['loan_terms']);
    $qualifications = trim($_POST['qualifications']);
    $gps_address = trim($_POST['gps_address']);
    $tin_number = trim($_POST['tin_number']);
    $password = $_POST['password'];

    /* =======================
       IMAGE UPLOAD HANDLER
    ======================= */
    function uploadFile($fieldName, $prevFile)
    {
        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return $prevFile; // keep old file
        }

        $dir = "../uploads/agent_docs/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
        $fileName = $fieldName . "_" . time() . "." . $ext;

        $path = $dir . $fileName;
        move_uploaded_file($_FILES[$fieldName]['tmp_name'], $path);

        return $path;
    }

    // Upload updated files
    $id_card          = uploadFile("id_card",          $agent['id_card']);
    $passport_photo   = uploadFile("passport_photo",   $agent['passport_photo']);
    $interior_photo   = uploadFile("interior_photo",   $agent['interior_photo']);
    $exterior_photo   = uploadFile("exterior_photo",   $agent['exterior_photo']);
    $certificate_photo = uploadFile("certificate_photo", $agent['certificate_photo']);

    /* =======================
       VALIDATION
    ======================= */
    if (empty($name) || empty($email)) {
        $errorMessage = "Name and Email are required.";
    } else {
        try {
            $pdo->beginTransaction();

            /* =======================
               UPDATE USER RECORD
            ======================= */
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET name = ?, email = ?, phone = ?, password = ?, 
                        id_card = ?, id_card_number = ?, passport_photo = ?, 
                        interior_photo = ?, exterior_photo = ?, 
                        gps_address = ?, tin_number = ?, certificate_photo = ?
                    WHERE id = ? AND role = 'agent'
                ");
                $updateUser->execute([
                    $name, $email, $phone, $hashed,
                    $id_card, $_POST['id_card_number'], $passport_photo,
                    $interior_photo, $exterior_photo,
                    $gps_address, $tin_number, $certificate_photo,
                    $agent_id
                ]);
            } else {
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET name = ?, email = ?, phone = ?, 
                        id_card = ?, id_card_number = ?, passport_photo = ?, 
                        interior_photo = ?, exterior_photo = ?, 
                        gps_address = ?, tin_number = ?, certificate_photo = ?
                    WHERE id = ? AND role = 'agent'
                ");
                $updateUser->execute([
                    $name, $email, $phone,
                    $id_card, $_POST['id_card_number'], $passport_photo,
                    $interior_photo, $exterior_photo,
                    $gps_address, $tin_number, $certificate_photo,
                    $agent_id
                ]);
            }

            /* =======================
               UPDATE OR INSERT agent_profiles
            ======================= */
            $checkProfile = $pdo->prepare("SELECT id FROM agent_profiles WHERE user_id = ?");
            $checkProfile->execute([$agent_id]);

            if ($checkProfile->rowCount() > 0) {
                $updateProfile = $pdo->prepare("
                    UPDATE agent_profiles 
                    SET interest_rate = ?, loan_terms = ?, qualifications = ?
                    WHERE user_id = ?
                ");
                $updateProfile->execute([$interest_rate, $loan_terms, $qualifications, $agent_id]);
            } else {
                $insertProfile = $pdo->prepare("
                    INSERT INTO agent_profiles (user_id, interest_rate, loan_terms, qualifications)
                    VALUES (?, ?, ?, ?)
                ");
                $insertProfile->execute([$agent_id, $interest_rate, $loan_terms, $qualifications]);
            }

            $pdo->commit();
            $successMessage = "Profile updated successfully!";
            $stmt->execute([$agent_id]);
            $agent = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "Error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Agent Profile | AgroLoan</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #1e40af;
    --brand-dark: #1d4ed8;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --card-bg: #fff;
}

/* General */
*{box-sizing:border-box;}
body {
    margin:0;
    font-family:"Segoe UI",Roboto,Arial,sans-serif;
    background:var(--bg);
    color:var(--text);
    height:100vh;
    display:flex;
    overflow:hidden;
}

/* SIDEBAR */
.sidebar {
    width: var(--sidebar-width);
    min-width: var(--sidebar-width);
    background: var(--brand);
    color: #fff;
    display: flex;
    flex-direction: column;
    padding: 18px;
    gap: 8px;
    transition: width .28s ease, padding .2s ease;
}
.sidebar.collapsed {
    width: var(--sidebar-collapsed-width);
    min-width: var(--sidebar-collapsed-width);
    padding-left: 10px;
    padding-right: 10px;
}
.brand {
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:6px;
}
.brand img {
    width:44px;
    height:44px;
    border-radius:8px;
    object-fit:cover;
}
.brand h2 {
    font-size:18px;
    margin:0;
    line-height:1;
    font-weight:600;
    transition:opacity .18s ease;
}
.sidebar.collapsed .brand h2 { opacity:0; width:0; margin:0; }

/* NAVIGATION */
.nav {
    display:flex;
    flex-direction:column;
    gap:6px;
    margin-top:8px;
}
.nav a {
    display:flex;
    align-items:center;
    gap:12px;
    padding:10px;
    border-radius:8px;
    color:#fff;
    text-decoration:none;
    font-weight:500;
    transition: background .15s, transform .08s;
    white-space:nowrap;
}
.nav a .icon {
    width:36px;
    height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    border-radius:6px;
    background: rgba(255,255,255,0.06);
}
.nav a:hover { background: var(--brand-dark); transform: translateY(-1px); }
.nav a.active { background: rgba(0,0,0,0.12); }
.sidebar.collapsed .nav a { justify-content:center; padding:8px; }
.sidebar.collapsed .nav a .label { display:none; }
.sidebar .spacer { flex:1 1 auto; }

.logout-btn {
    background: var(--danger);
    border: none;
    color:#fff;
    padding:10px;
    border-radius:8px;
    cursor:pointer;
    width:100%;
    font-weight:600;
}
.sidebar.collapsed .logout-btn {
    padding:8px;
    width:48px;
    height:48px;
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.sidebar.collapsed .logout-btn span.label { display:none; }

/* MAIN AREA */
.main{
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    height:100vh;
    overflow:auto;
}
.topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px;
    border-bottom:1px solid rgba(15,23,42,0.04);
    background: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
}
.toggle-btn{
    background:var(--brand);
    color:#fff;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    font-size:18px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.user-greet{font-size:18px;font-weight:600;}
.content-area{
    padding:28px 40px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:22px;
}

/* PROFILE CARD */
.form-card{
    background:var(--card-bg);
    border-radius:12px;
    padding:30px;
    box-shadow:0 4px 18px rgba(16,24,40,0.06);
    width:100%;
    max-width:700px;
}
.form-card h2{
    color:var(--brand);
    margin-bottom:20px;
    font-size:22px;
}
.form-card label{
    display:block;
    margin-top:12px;
    font-weight:600;
}
.form-card input{
    width:100%;
    padding:10px;
    border:1px solid #ccc;
    border-radius:8px;
    font-size:15px;
}
.form-card button{
    margin-top:18px;
    background:var(--brand);
    color:#fff;
    font-weight:600;
    cursor:pointer;
    border:none;
    padding:12px;
    border-radius:8px;
    transition:background .2s;
}
.form-card button:hover{ background:var(--brand-dark); }
.success{background:#d1fae5;color:#065f46;padding:10px;border-radius:8px;text-align:center;}
.error{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;text-align:center;}
</style>

</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Logo" class="logo">
        <h2>AgroLoan Agent</h2>
    </div>
    <nav class="nav">
        <a href="agent_dashboard.php"><span class="icon">🏠</span><span class="label">Dashboard</span></a>
        <a href="farmer_vetting.php"><span class="icon">👨‍🌾</span><span class="label">Farmer Vetting</span></a>
        <a href="proof_verification.php"><span class="icon">📸</span><span class="label">Proof Verification</span></a>
        <a href="agent_approve_stage.php"><span class="icon">💰</span><span class="label">Disbursement</span></a>
        <a href="agent_profile.php" class="active"><span class="icon">⚙️</span><span class="label">Profile</span></a>
    </nav>
    <div class="spacer"></div>

    <form method="POST" action="logout.php">
        <button class="logout-btn"><span class="icon">🚪</span><span class="label">Logout</span></button>
    </form>
</aside>

<main class="main" id="main">

    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($username) ?> 👨‍💼</div>
    </div>

    <div class="content-area">
        <div class="form-card">
            <h2>👨‍💼 Update Profile</h2>

            <?php if ($successMessage): ?>
                <div class="success"><?= $successMessage ?></div>
            <?php elseif ($errorMessage): ?>
                <div class="error"><?= $errorMessage ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">

                <label>Full Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($agent['name']) ?>" required>

                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($agent['email']) ?>" required>

                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($agent['phone']) ?>">

                <label>ID Card Number</label>
                <input type="text" name="id_card_number" value="<?= htmlspecialchars($agent['id_card_number']) ?>">

                <label>GPS Address</label>
                <input type="text" name="gps_address" value="<?= htmlspecialchars($agent['gps_address']) ?>">

                <label>TIN Number</label>
                <input type="text" name="tin_number" value="<?= htmlspecialchars($agent['tin_number']) ?>">

                <label>ID Card (Upload new to replace)</label>
                <input type="file" name="id_card">

                <label>Passport Photo</label>
                <input type="file" name="passport_photo">

                <label>Interior Photo</label>
                <input type="file" name="interior_photo">

                <label>Exterior Photo</label>
                <input type="file" name="exterior_photo">

                <label>Business Certificate</label>
                <input type="file" name="certificate_photo">

                <label>Interest Rate (%)</label>
                <input type="text" name="interest_rate" value="<?= htmlspecialchars($agent['interest_rate']) ?>">

                <label>Loan Terms</label>
                <input type="text" name="loan_terms" value="<?= htmlspecialchars($agent['loan_terms']) ?>">

                <label>Qualifications</label>
                <input type="text" name="qualifications" value="<?= htmlspecialchars($agent['qualifications']) ?>">

                <label>New Password (leave blank to keep current)</label>
                <input type="password" name="password" placeholder="Enter new password">

                <button type="submit">Save Changes</button>

            </form>
        </div>
    </div>
</main>

<script>
document.getElementById('toggleBtn').addEventListener('click', ()=>{
    document.getElementById('sidebar').classList.toggle('collapsed');
});
<?php if (!empty($successMessage)): ?>
Swal.fire({
    icon: 'success',
    title: 'Success!',
    text: '<?= $successMessage ?>',
    timer: 2000,
    showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>
