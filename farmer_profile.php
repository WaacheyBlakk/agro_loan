<?php
session_start();
require_once '../src/db.php';

$pdo = getPDO();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";

// Fetch user + farmer profile
$stmt = $pdo->prepare("
    SELECT 
        u.name, 
        u.email, 
        u.phone, 
        u.id_card,
        u.id_card_number,
        u.passport_photo,
        u.house_address,
        u.farmland_photos,
        p.gps_coordinates, 
        p.farm_type
    FROM users u
    LEFT JOIN farmer_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$name = $user['name'];
$email = $user['email'];

/* ======================================================
   FILE UPLOAD FUNCTION
====================================================== */
function uploadFile($fieldName, $prevFile)
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return $prevFile;
    }

    $dir = "../uploads/farmer_docs/";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
    $fileName = $fieldName . "_" . time() . "." . $ext;

    move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $fileName);

    return $fileName;
}

/* ======================================================
   HANDLE FORM SUBMISSION
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phone = trim($_POST['phone']);
    $gps_coordinates = trim($_POST['gps_coordinates']);
    $farm_type = trim($_POST['farm_type']);
    $id_card_number = trim($_POST['id_card_number']);
    $house_address = trim($_POST['house_address']);
    $password = trim($_POST['password']);

    /* Upload files */
    $id_card = uploadFile("id_card", $user['id_card']);
    $passport_photo = uploadFile("passport_photo", $user['passport_photo']);
    $farmland_photos = uploadFile("farmland_photos", $user['farmland_photos']);

    try {
        $pdo->beginTransaction();

        /* Update USERS table */
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $query = "
                UPDATE users SET 
                    phone=?, password=?, id_card=?, id_card_number=?, 
                    passport_photo=?, farmland_photos=?, house_address=?
                WHERE id=? AND role='farmer'
            ";
            $params = [
                $phone, $hashed, $id_card, $id_card_number,
                $passport_photo, $farmland_photos, $house_address,
                $user_id
            ];
        } else {
            $query = "
                UPDATE users SET 
                    phone=?, id_card=?, id_card_number=?, 
                    passport_photo=?, farmland_photos=?, house_address=?
                WHERE id=? AND role='farmer'
            ";
            $params = [
                $phone, $id_card, $id_card_number,
                $passport_photo, $farmland_photos, $house_address,
                $user_id
            ];
        }

        $updateUser = $pdo->prepare($query);
        $updateUser->execute($params);

        /* Update or Insert farmer_profiles */
        $checkProfile = $pdo->prepare("SELECT id FROM farmer_profiles WHERE user_id=?");
        $checkProfile->execute([$user_id]);

        if ($checkProfile->rowCount() > 0) {
            $updateProfile = $pdo->prepare("
                UPDATE farmer_profiles 
                SET gps_coordinates=?, farm_type=? 
                WHERE user_id=?
            ");
            $updateProfile->execute([$gps_coordinates, $farm_type, $user_id]);
        } else {
            $insertProfile = $pdo->prepare("
                INSERT INTO farmer_profiles (user_id, gps_coordinates, farm_type) 
                VALUES (?, ?, ?)
            ");
            $insertProfile->execute([$user_id, $gps_coordinates, $farm_type]);
        }

        $pdo->commit();

        $message = "<p class='success'>Profile updated successfully!</p>";

        // Refresh user data
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Profile - AgroLoan Farmer</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root {
    --sidebar-width: 240px;
    --sidebar-collapsed-width: 72px;
    --brand: #2f855a;
    --brand-dark: #276749;
    --danger: #e53e3e;
    --bg: #f6f8fa;
    --text: #1f2937;
    --muted: #6b7280;
    --card-bg: #fff;
}
* { box-sizing: border-box; }
body {
    margin:0;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height:100vh;
    overflow:hidden;
    display:flex;
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
    overflow: hidden;
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
.brand .logo {
    width:44px;
    height:44px;
    background: rgba(255,255,255,0.12);
    border-radius:8px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:18px;
}
.brand h2 {
    font-size:18px;
    margin:0;
    line-height:1;
    font-weight:600;
    transition:opacity .18s ease;
}
.sidebar.collapsed .brand h2 { opacity:0; width:0; margin:0; }

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
    overflow:hidden;
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
    align-self:center;
    display:flex;
    justify-content:center;
    align-items:center;
}
.sidebar.collapsed .logout-btn span.label { display:none; }

/* MAIN AREA */
.main {
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    height:100vh;
    overflow:auto;
}
.topbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:20px;
    border-bottom:1px solid rgba(15,23,42,0.04);
}
.toggle-btn {
    background: var(--brand);
    color:white;
    border:none;
    padding:8px 10px;
    border-radius:8px;
    font-size:18px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
}
.user-greet {
    font-size:18px;
    font-weight:600;
}
.content-area {
    padding:28px 40px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:22px;
}

/* Form Card */
.form-card {
    background: var(--card-bg);
    padding:30px;
    border-radius:12px;
    box-shadow: 0 4px 18px rgba(16,24,40,0.06);
    width:100%;
    max-width:700px;
    text-align:left;
}
.form-card h2 {
    color: var(--brand);
    margin-bottom:20px;
}
.form-card label{
    display:block;
    margin-top:12px;
    font-weight:600;
}
.form-card input {
    width:100%;
    padding:10px;
    border-radius:8px;
    border:1px solid #ccc;
    font-size:15px;
}
.form-card button {
    background: var(--brand);
    color:white;
    font-weight:600;
    cursor:pointer;
    border:none;
    padding:12px;
    border-radius:8px;
    transition: background .2s;
}
.form-card button:hover { background: var(--brand-dark); }

.success {
    background:#d1fae5;
    color:#065f46;
    padding:10px;
    border-radius:8px;
    text-align:center;
}
</style>
</head>

<body>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo"></a>
        <h2>AgroLoan Farmer</h2>
    </div>

    <nav class="nav">
        <a href="farmer_dashboard.php" class="<?= basename($_SERVER['PHP_SELF'])==='farmer_dashboard.php'?'active':'' ?>">
            <span class="icon">🏠</span><span class="label">Dashboard</span>
        </a>
        <a href="apply_loan.php" class="<?= basename($_SERVER['PHP_SELF'])==='apply_loan.php'?'active':'' ?>">
            <span class="icon">💰</span><span class="label">Apply for Loan</span>
        </a>
        <a href="view_application.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'view_application.php' ? 'active' : '' ?>">
            <span class="icon">📄</span>
            <span class="label">View Applications</span>
        </a>
        <a href="upload_proof.php" class="<?= basename($_SERVER['PHP_SELF'])==='upload_proof.php'?'active':'' ?>">
            <span class="icon">📸</span><span class="label">Upload Proof</span>
        </a>
        <a href="farmer_profile.php" class="<?= basename($_SERVER['PHP_SELF'])==='farmer_profile.php'?'active':'' ?>">
            <span class="icon">⚙️</span><span class="label">Profile</span>
        </a>
    </nav>

    <div class="spacer"></div>
    <form method="POST" action="logout.php">
        <button class="logout-btn"><span class="icon">🚪</span><span class="label">Logout</span></button>
    </form>
</aside>

<main class="main" id="main">
    <div class="topbar">
        <button id="toggleBtn" class="toggle-btn"><span class="icon">☰</span></button>
        <div class="user-greet">Welcome, <?= htmlspecialchars($user['name']) ?> 👩‍🌾</div>
    </div>

    <div class="content-area">
        <div class="form-card">
            <h2>👨‍🌾 My Profile</h2>
            <?= $message ?>

            <form method="POST" enctype="multipart/form-data">

                <label>Full Name</label>
                <input type="text" value="<?= htmlspecialchars($user['name']) ?>" readonly>

                <label>Email</label>
                <input type="email" value="<?= htmlspecialchars($user['email']) ?>" readonly>

                <label>Phone Number</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>">

                <label>ID Card Number</label>
                <input type="text" name="id_card_number" value="<?= htmlspecialchars($user['id_card_number']) ?>">

                <label>ID Card</label>
                <input type="file" name="id_card">

                <label>Passport Photo</label>
                <input type="file" name="passport_photo">

                <label>Farmland Photos</label>
                <input type="file" name="farmland_photos">

                <label>GPS Coordinates</label>
                <input type="text" name="gps_coordinates" value="<?= htmlspecialchars($user['gps_coordinates']) ?>">

                <label>House Address</label>
                <input type="text" name="house_address" value="<?= htmlspecialchars($user['house_address']) ?>">

                <label>Farm Type</label>
                <input type="text" name="farm_type" value="<?= htmlspecialchars($user['farm_type']) ?>">

                <label>New Password (optional)</label>
                <input type="password" name="password">

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
