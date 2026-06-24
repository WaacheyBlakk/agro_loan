<?php
// public/register.php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/users.php';

// Redirect if already logged in (using session flags from both configurations)
if (isset($_SESSION['id']) || isset($_SESSION['user_id'])) {
    header('Location: shop.php');
    exit;
}

$pdo = getPDO();
$success = "";
$error = "";

// Upload directories
$farmerDir = __DIR__ . '/../uploads/farmers/';
$agentDir  = __DIR__ . '/../uploads/agents/';
$buyerDir  = __DIR__ . '/../uploads/buyers/';

if (!is_dir($farmerDir)) @mkdir($farmerDir, 0777, true);
if (!is_dir($agentDir))  @mkdir($agentDir, 0777, true);
if (!is_dir($buyerDir))  @mkdir($buyerDir, 0777, true);

function uploadSingleFile(string $fieldName, string $targetDir): ?string {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $maxSize = 5 * 1024 * 1024; // 5MB
    $allowed = ['png','jpg','jpeg','gif','pdf'];

    if ($file['size'] > $maxSize) {
        throw new Exception("File {$fieldName} is too large (max 5MB).");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Unsupported file type. Allowed: " . implode(',', $allowed));
    }

    $safeName = bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
    $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    return 'uploads/' . basename($targetDir) . '/' . $safeName;
}

function uploadMultipleFiles(string $fieldName, string $targetDir): array {
    if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]['name'])) {
        return [];
    }

    $paths = [];
    $count = count($_FILES[$fieldName]['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$fieldName]['error'][$i] !== UPLOAD_ERR_OK) continue;

        $tmp = [
            'name' => $_FILES[$fieldName]['name'][$i],
            'type' => $_FILES[$fieldName]['type'][$i],
            'tmp_name' => $_FILES[$fieldName]['tmp_name'][$i],
            'error' => $_FILES[$fieldName]['error'][$i],
            'size' => $_FILES[$fieldName]['size'][$i],
        ];

        $key = $fieldName . "_multi_" . $i;
        $_FILES[$key] = $tmp;

        $paths[] = uploadSingleFile($key, $targetDir);
        unset($_FILES[$key]);
    }

    return array_values(array_filter($paths));
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $role = $_POST['role'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Standard Common Field Validation
        if (!$role) throw new Exception("Please select a registration role.");
        if (!$name) throw new Exception("Please enter your full legal name.");
        if (!$phone) throw new Exception("Please enter your phone number.");
        if (!preg_match('/^\d{10}$/', $phone)) {
            throw new Exception("Phone number must be exactly 10 digits (e.g., 0201234567).");
        }
        if (!$email) throw new Exception("Please enter your email address.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }
        if (!$password) throw new Exception("Please enter a password.");
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters long.");
        }
        if (!$confirm_password) throw new Exception("Please confirm your password.");
        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }

        // Uniqueness checks based on role destination table
        if ($role === 'buyer') {
            $check = $pdo->prepare("SELECT id FROM buyers WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                throw new Exception("An account with that email already exists in our buyers directory.");
            }

            $checkPhone = $pdo->prepare("SELECT id FROM buyers WHERE phone = ?");
            $checkPhone->execute([$phone]);
            if ($checkPhone->fetch()) {
                throw new Exception("An account with that phone number already exists in our buyers directory.");
            }
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                throw new Exception("An account with that email already exists.");
            }

            $checkPhone = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $checkPhone->execute([$phone]);
            if ($checkPhone->fetch()) {
                throw new Exception("An account with that phone number already exists.");
            }
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Insertion Logic
        if ($role === 'buyer') {
            $id_card_number = trim($_POST['id_card_number'] ?? '');
            $digital_address = trim($_POST['digital_address'] ?? '');
            $city = trim($_POST['city'] ?? '');

            if (!$digital_address) throw new Exception("Please enter your digital address.");
            if (!$city) throw new Exception("Please enter your city.");
            if (!$id_card_number) throw new Exception("Please enter your Ghana Card number.");
            
            // Format check for Ghana Card GHA-XXXXXXXXX-X (exactly 15 chars)
            if (!preg_match('/^[Gg][Hh][Aa]-\d{9}-\d$/', $id_card_number)) {
                throw new Exception("Ghana Card number format is invalid. It must be exactly 15 characters (e.g. GHA-123456789-0).");
            }

            $id_card_path = uploadSingleFile('id_card', $buyerDir);
            $passport_path = uploadSingleFile('passport_photo', $buyerDir);

            if ($id_card_path === null) throw new Exception("Ghana Card upload is required.");
            if ($passport_path === null) throw new Exception("Passport photo upload is required.");

            // Register as Buyer in buyers table
            $stmt = $pdo->prepare("
                INSERT INTO buyers 
                    (name, email, phone, password, status, id_card, id_card_number, passport_photo, digital_address, city) 
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $email, $phone, $password_hash, 
                $id_card_path, $id_card_number, $passport_path, $digital_address, $city
            ]);
            
            $success = "Registration successful as a Buyer. Your account is pending verification.";
        } else {
            // Register as Farmer or Agent in users & profiles table
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO users (name, phone, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$name, $phone, $email, $password_hash, $role]);
            $userId = (int)$pdo->lastInsertId();

            // Farmer Profile
            if ($role === 'farmer') {
                $id_card_number = trim($_POST['id_card_number'] ?? '');
                $house_address  = trim($_POST['house_address'] ?? '');
                $farm_type = trim($_POST['farm_type'] ?? '');
                $crop_type = trim($_POST['crop_type'] ?? '');
                $crop_expected_duration_days = trim($_POST['crop_expected_duration_days'] ?? '');
                $livestock_type = trim($_POST['livestock_type'] ?? '');
                $livestock_production_days = trim($_POST['livestock_production_days'] ?? '');
                $acreage = trim($_POST['acreage'] ?? '');
                $gps_coordinates = trim($_POST['gps_coordinates'] ?? '');

                if (!$farm_type) throw new Exception("Please select your farm type.");
                if ($farm_type === 'crop') {
                    if (!$crop_type) throw new Exception("Please enter your crop types.");
                    if (!$crop_expected_duration_days) throw new Exception("Please enter the crop expected duration.");
                } elseif ($farm_type === 'livestock') {
                    if (!$livestock_type) throw new Exception("Please enter your livestock type.");
                    if (!$livestock_production_days) throw new Exception("Please enter the livestock production cycle days.");
                }

                if (!$house_address) throw new Exception("Please enter your house address.");
                if (!$gps_coordinates) throw new Exception("Please enter your GPS coordinates.");
                if (!$acreage) throw new Exception("Please enter your farm acreage.");
                if (!$id_card_number) throw new Exception("Please enter your Ghana Card number.");
                
                if (!preg_match('/^[Gg][Hh][Aa]-\d{9}-\d$/', $id_card_number)) {
                    throw new Exception("Ghana Card number format is invalid. It must be exactly 15 characters (e.g. GHA-123456789-0).");
                }

                $id_card_path = uploadSingleFile('id_card', $farmerDir);
                $passport_path = uploadSingleFile('passport_photo', $farmerDir);
                $farmland_paths = uploadMultipleFiles('farmland_photos', $farmerDir);

                if ($id_card_path === null) throw new Exception("ID card upload is required.");
                if ($passport_path === null) throw new Exception("Passport photo upload is required.");
                if (empty($farmland_paths)) throw new Exception("At least one farmland photo is required.");

                $farmland_json = json_encode($farmland_paths);

                $stmt = $pdo->prepare("
                    INSERT INTO farmer_profiles
                        (user_id, id_card, id_card_number, house_address, farm_type, crop_type, crop_expected_duration_days, livestock_type, livestock_production_days, acreage, gps_coordinates, passport_photo, farmland_photos, created_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId, $id_card_path, $id_card_number, $house_address, $farm_type, 
                    $crop_type, $crop_expected_duration_days, $livestock_type, 
                    $livestock_production_days, $acreage, $gps_coordinates, $passport_path, $farmland_json
                ]);
            }

            // Agent Profile
            if ($role === 'agent') {
                $id_card_number = trim($_POST['id_card_number'] ?? '');
                $gps_address = trim($_POST['gps_address'] ?? '');
                $tin_number = trim($_POST['tin_number'] ?? '');
                $interest_rate = trim($_POST['interest_rate'] ?? '');
                $loan_terms = trim($_POST['loan_terms'] ?? '');
                $qualifications = trim($_POST['qualifications'] ?? '');

                if (!$qualifications) throw new Exception("Please enter your qualifications.");
                if (!$tin_number) throw new Exception("Please enter your TIN number.");
                if (!$interest_rate) throw new Exception("Please enter your interest rate.");
                if (!$loan_terms) throw new Exception("Please enter your loan terms.");
                if (!$gps_address) throw new Exception("Please enter your GPS address.");
                if (!$id_card_number) throw new Exception("Please enter your Ghana Card number.");
                
                if (!preg_match('/^[Gg][Hh][Aa]-\d{9}-\d$/', $id_card_number)) {
                    throw new Exception("Ghana Card number format is invalid. It must be exactly 15 characters (e.g. GHA-123456789-0).");
                }

                $id_card_path = uploadSingleFile('id_card', $agentDir);
                $passport_path = uploadSingleFile('passport_photo', $agentDir);
                $certificate_path = uploadSingleFile('certificate_photo', $agentDir);
                $interior_path = uploadSingleFile('interior_photo', $agentDir);
                $exterior_path = uploadSingleFile('exterior_photo', $agentDir);

                if ($id_card_path === null) throw new Exception("ID card upload is required.");
                if ($passport_path === null) throw new Exception("Passport photo upload is required.");
                if ($certificate_path === null) throw new Exception("Certificate photo upload is required.");
                if ($interior_path === null) throw new Exception("Interior photo upload is required.");
                if ($exterior_path === null) throw new Exception("Exterior photo upload is required.");

                $stmt = $pdo->prepare("
                    INSERT INTO agent_profiles
                      (user_id, id_card, id_card_number, passport_photo, certificate_photo, interior_photo, exterior_photo, gps_address, tin_number, interest_rate, loan_terms, qualifications, created_at)
                    VALUES
                      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId, $id_card_path, $id_card_number, $passport_path, $certificate_path,
                    $interior_path, $exterior_path, $gps_address, $tin_number, $interest_rate,
                    $loan_terms, $qualifications
                ]);
            }

            $pdo->commit();
            $success = "Registration successful as a " . ucfirst($role) . ". Your account is pending verification.";
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register | Agro Market & Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Create an account to access agricultural financing and products.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

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
    --glass: rgba(255, 255, 255, 0.85);
    
    --primary-light: #dcfce7;
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --focus-ring: rgba(21, 128, 61, 0.2);
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
    --glass: rgba(15, 23, 42, 0.85);
    
    --primary-light: #14532d;
    --focus-ring: rgba(34, 197, 94, 0.2);
}

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg-body);
    color: var(--text-main);
    margin: 0;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    transition: background 0.3s ease, color 0.3s ease;
    overflow-x: hidden;
}

/* --- Header / Navbar --- */
header {
    position: fixed;
    top: 0;
    width: 100%;
    background: var(--glass);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 15px 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1000;
    border-bottom: 1px solid var(--border);
    transition: all 0.3s ease;
}

header.scrolled {
    padding: 10px 5%;
    box-shadow: var(--shadow);
}

.logo-container {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--primary-dark);
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
}

nav {
    display: flex;
    align-items: center;
    gap: 30px;
}

nav a {
    color: var(--text-main);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    transition: color 0.3s;
    position: relative;
}

nav a:hover {
    color: var(--primary);
}

nav a::after {
    content: '';
    position: absolute;
    width: 0;
    height: 2px;
    bottom: -4px;
    left: 0;
    background-color: var(--primary);
    transition: width 0.3s;
}
nav a:hover::after { width: 100%; }
nav a.active { color: var(--primary); }
nav a.active::after { width: 100%; }

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
    text-decoration: none;
}
.btn-login::after { display: none; } 

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
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.theme-toggle:hover {
    transform: rotate(15deg) scale(1.1);
    border-color: var(--primary);
}

/* --- Mobile Components --- */
.mobile-toggle-btn {
    display: none;
    font-size: 1.5rem;
    background: none;
    border: none;
    color: var(--text-main);
    cursor: pointer;
    margin-left: 10px;
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
.mobile-menu a {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    text-decoration: none;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
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

/* --- REGISTER FORM SPECIFIC STYLES --- */
.container {
    max-width: 1100px;
    margin: 120px auto 60px; 
    padding: 0 24px;
    width: 100%;
    flex: 1;
}

.auth-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 40px;
    align-items: start;
}

.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    box-shadow: var(--shadow);
}

.page-title { margin: 0 0 8px; font-size: 1.8rem; font-weight: 800; color: var(--text-main); font-family: 'Merriweather', serif; }
.page-subtitle { margin: 0 0 30px; color: var(--text-muted); font-size: 1.05rem; }

.form-section { display: flex; flex-direction: column; gap: 20px; }

.role-selector { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 8px; }
.role-option { position: relative; }
.role-option input { position: absolute; opacity: 0; cursor: pointer; }

.role-label {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; padding: 16px 8px; border: 2px solid var(--border); border-radius: 16px;
    cursor: pointer; transition: 0.3s; font-weight: 600; color: var(--text-muted);
    font-size: 0.9rem;
}
.role-label i { font-size: 22px; margin-bottom: 2px; }
.role-option input:checked + .role-label {
    border-color: var(--primary); background: var(--primary-light); color: var(--primary);
}

.input-group label { display: block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600; color: var(--text-main); }
.input-wrapper { position: relative; }
.input-wrapper i {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: 1.1rem; pointer-events: none;
}
.input-wrapper input, .input-wrapper select {
    width: 100%; padding: 12px 12px 12px 42px;
    border: 1px solid var(--border); border-radius: 12px;
    font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.95rem;
    background: var(--bg-body); color: var(--text-main); transition: 0.2s;
}
.input-wrapper input:focus, .input-wrapper select:focus {
    outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--focus-ring); background: var(--bg-card);
}

.file-upload-box {
    border: 2px dashed var(--border); border-radius: 12px; padding: 24px; text-align: center;
    transition: 0.3s; background: var(--bg-body); cursor: pointer; position: relative;
}
.file-upload-box:hover { border-color: var(--primary); background: var(--primary-light); }
.file-upload-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; }
.upload-content { display: flex; flex-direction: column; align-items: center; gap: 8px; pointer-events: none; }
.upload-content i { font-size: 24px; color: var(--text-muted); }
.upload-content span { font-size: 0.9rem; font-weight: 600; color: var(--text-main); }
.upload-content small { font-size: 0.8rem; color: var(--text-muted); }

.preview-area { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.preview-item { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); }
.preview-name { font-size: 0.8rem; color: var(--primary); background: var(--primary-light); padding: 4px 8px; border-radius: 4px; }

.btn-primary {
    background: var(--primary); color: white; border: none; padding: 16px;
    border-radius: 50px; font-weight: 700; font-size: 1rem; width: 100%;
    cursor: pointer; transition: 0.3s; box-shadow: 0 4px 12px var(--focus-ring);
}
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }

.info-box {
    background: var(--primary-light); border: 1px solid transparent;
    border-radius: 16px; padding: 24px; margin-bottom: 24px;
}
.info-box h4 { margin: 0 0 10px; color: var(--primary); font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.info-box p { font-size: 0.9rem; line-height: 1.6; color: var(--text-main); margin: 0; opacity: 0.9; }

.alert { padding: 16px; border-radius: 12px; margin-bottom: 20px; font-size: 0.95rem; display: flex; gap: 10px; align-items: flex-start; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

.dynamic-section { display: none; animation: fadeIn 0.4s ease-out; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

#successPopup {
    position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 2000;
    display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px);
}
.popup-card {
    background: var(--bg-card); padding: 40px; border-radius: 24px; text-align: center;
    max-width: 400px; width: 90%; box-shadow: var(--shadow-lg);
    transform: scale(0.9); opacity: 0; transition: 0.3s; border: 1px solid var(--border);
}
.popup-card.show { transform: scale(1); opacity: 1; }
.check-circle {
    width: 80px; height: 80px; background: var(--primary-light); color: var(--primary);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 3rem; margin: 0 auto 20px;
}

/* Footer (Standard) */
footer {
    background: #064e3b; 
    color: #ecfdf5;
    padding: 60px 5% 30px;
}

.footer-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 40px;
    margin-bottom: 40px;
}

.footer-col h4 {
    color: #fff;
    font-size: 1.2rem;
    margin-bottom: 20px;
}

.footer-col ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.footer-col ul li {
    margin-bottom: 12px;
}

.footer-col a {
    color: #a7f3d0;
    text-decoration: none;
    transition: 0.3s;
}

.footer-col a:hover {
    color: #fff;
    padding-left: 5px;
}

.footer-bottom {
    text-align: center;
    border-top: 1px solid rgba(255,255,255,0.1);
    padding-top: 20px;
    font-size: 0.9rem;
    color: #6ee7b7;
}

@media (max-width: 992px) {
    .auth-grid { grid-template-columns: 1fr; }
    .side-panel { order: 2; }
}

@media (max-width: 768px) {
    nav { display: none; }
    .mobile-toggle-btn { display: block; }
    .container { padding: 0 20px; }
    .card { padding: 30px 20px; }
}
</style>
</head>
<body>

<div class="overlay" id="overlay"></div>

<!-- Header -->
<header id="mainHeader">
    <a href="index.php" class="logo-container">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" onerror="this.style.display='none'">
        <h1>AgroMarket</h1>
    </a>
    
    <div class="header-right">
        <nav>
            <a href="index.php">Home</a>
            <a href="about.php">About</a>
            <a href="services.php">Services</a>
            <a href="shop.php">Shop</a>
            <a href="register.php" class="active">Register</a>
            <a href="contact.php">Contact Us</a>
            <a href="login.php" class="btn-login">Login</a>
        </nav>
        
        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
            <i class="ri-moon-line"></i>
        </button>

        <button class="mobile-toggle-btn" id="mobileToggle">
            <i class="ri-menu-3-line"></i>
        </button>
    </div>
</header>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-weight:800; font-size:1.2rem; color:var(--primary);">Menu</span>
        <i class="ri-close-line" id="closeMenu" style="font-size:1.5rem; cursor:pointer;"></i>
    </div>
    <a href="index.php">Home</a>
    <a href="about.php">About</a>
    <a href="services.php">Services</a>
    <a href="shop.php">Shop</a>
    <a href="register.php" style="color:var(--primary);">Register</a>
    <a href="contact.php">Contact Us</a>
    <a href="login.php">Login</a>
</div>

<main class="container">
    <div class="auth-grid">
        
        <!-- Main Form Card -->
        <div class="card">
            <h1 class="page-title">Create Account</h1>
            <p class="page-subtitle">Join us today to access agricultural products and financing.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="ri-error-warning-fill" style="margin-top:2px"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="regForm">
                
                <div class="form-section">
                    <!-- Expanded Role Selector with Buyer Option -->
                    <div class="input-group">
                        <label>I am registering as a:</label>
                        <div class="role-selector">
                            <label class="role-option">
                                <input type="radio" name="role" value="farmer" onchange="switchRole()" required <?= (isset($_POST['role']) && $_POST['role'] === 'farmer') ? 'checked' : '' ?>>
                                <div class="role-label">
                                    <i class="ri-plant-fill"></i>
                                    Farmer
                                </div>
                            </label>
                            <label class="role-option">
                                <input type="radio" name="role" value="agent" onchange="switchRole()" <?= (isset($_POST['role']) && $_POST['role'] === 'agent') ? 'checked' : '' ?>>
                                <div class="role-label">
                                    <i class="ri-briefcase-4-fill"></i>
                                    Agent
                                </div>
                            </label>
                            <label class="role-option">
                                <input type="radio" name="role" value="buyer" onchange="switchRole()" <?= (isset($_POST['role']) && $_POST['role'] === 'buyer') ? 'checked' : '' ?>>
                                <div class="role-label">
                                    <i class="ri-shopping-bag-3-fill"></i>
                                    Buyer
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Common Fields -->
                    <div class="input-group">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="ri-user-line"></i>
                            <input type="text" name="name" placeholder="Enter your full legal name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                        <div class="input-group">
                            <label>Phone Number</label>
                            <div class="input-wrapper">
                                <i class="ri-phone-line"></i>
                                <input type="tel" name="phone" id="phoneInput" maxlength="10" placeholder="020xxxxxxx" pattern="\d{10}" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Email Address</label>
                            <div class="input-wrapper">
                                <i class="ri-mail-line"></i>
                                <input type="email" name="email" placeholder="example@mail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                        <div class="input-group">
                            <label>Password</label>
                            <div class="input-wrapper">
                                <i class="ri-lock-password-line"></i>
                                <input type="password" name="password" id="pass1" placeholder="Create a password" required>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Confirm Password</label>
                            <div class="input-wrapper">
                                <i class="ri-lock-check-line"></i>
                                <input type="password" name="confirm_password" id="pass2" placeholder="Confirm password" required>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; color: var(--text-muted); padding: 0 4px; margin-top: -8px;">
                        <span>Min. 6 characters</span>
                        <button type="button" onclick="togglePasswords()" style="background:none; border:none; cursor:pointer; color:var(--primary); font-weight:600; display:flex; align-items:center; gap:4px;">
                            <i class="ri-eye-line" id="eyeIcon"></i> Show Passwords
                        </button>
                    </div>
                </div>

                <!-- BUYER FIELDS -->
                <div id="buyerSection" class="dynamic-section" style="margin-top:24px; border-top:1px solid var(--border); padding-top:24px;">
                    <h3 style="margin:0 0 16px; font-size:1.2rem; font-weight:700; color:var(--primary);">Buyer Details</h3>
                    
                    <div class="form-section">
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Digital Address (GhanaPost GPS)</label>
                                <div class="input-wrapper">
                                    <i class="ri-map-pin-2-line"></i>
                                    <input type="text" name="digital_address" placeholder="e.g. GA-123-4567" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>City</label>
                                <div class="input-wrapper">
                                    <i class="ri-building-line"></i>
                                    <input type="text" name="city" placeholder="e.g. Accra" required>
                                </div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Ghana Card Number</label>
                                <div class="input-wrapper">
                                    <i class="ri-id-card-line"></i>
                                    <input type="text" name="id_card_number" class="gha-card-input" placeholder="e.g. GHA-123456789-0" minlength="15" maxlength="15" pattern="[Gg][Hh][Aa]-\d{9}-\d" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Upload Ghana Card</label>
                                <div class="file-upload-box">
                                    <input type="file" name="id_card" accept="image/*,.pdf" onchange="previewFile(this, 'buyerIdPrev')" required>
                                    <div class="upload-content">
                                        <i class="ri-upload-cloud-2-line"></i>
                                        <span>Click or Drop ID</span>
                                    </div>
                                </div>
                                <div id="buyerIdPrev" class="preview-area"></div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Passport Photo</label>
                            <div class="file-upload-box">
                                <input type="file" name="passport_photo" accept="image/*" onchange="previewFile(this, 'buyerPassPrev')" required>
                                <div class="upload-content">
                                    <i class="ri-user-smile-line"></i>
                                    <span>Upload Photo</span>
                                </div>
                            </div>
                            <div id="buyerPassPrev" class="preview-area"></div>
                        </div>
                    </div>
                </div>

                <!-- FARMER FIELDS -->
                <div id="farmerSection" class="dynamic-section" style="margin-top:24px; border-top:1px solid var(--border); padding-top:24px;">
                    <h3 style="margin:0 0 16px; font-size:1.2rem; font-weight:700; color:var(--primary);">Farmer Details</h3>
                    
                    <div class="form-section">
                        <div class="input-group">
                            <label>Farm Type</label>
                            <div class="input-wrapper">
                                <i class="ri-seedling-line"></i>
                                <select name="farm_type" id="farm_type" onchange="toggleFarm()" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="crop">Crop Farming</option>
                                    <option value="livestock">Livestock Rearing</option>
                                </select>
                            </div>
                        </div>

                        <!-- Dynamic Farm Sub-fields -->
                        <div id="crop_fields" style="display:none; background: var(--bg-body); padding:16px; border-radius:12px; border:1px solid var(--border);">
                            <div class="form-section">
                                <div class="input-group">
                                    <label>Crop Types</label>
                                    <div class="input-wrapper">
                                        <i class="ri-leaf-line"></i>
                                        <input type="text" name="crop_type" placeholder="e.g. Maize, Cassava" required>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>Expected Duration (Days)</label>
                                    <div class="input-wrapper">
                                        <i class="ri-time-line"></i>
                                        <input type="text" name="crop_expected_duration_days" placeholder="e.g. 90" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="livestock_fields" style="display:none; background: var(--bg-body); padding:16px; border-radius:12px; border:1px solid var(--border);">
                            <div class="form-section">
                                <div class="input-group">
                                    <label>Livestock Type</label>
                                    <div class="input-wrapper">
                                        <i class="ri-goblet-line"></i>
                                        <input type="text" name="livestock_type" placeholder="e.g. Poultry, Goats" required>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <label>Production Cycle (Days)</label>
                                    <div class="input-wrapper">
                                        <i class="ri-time-line"></i>
                                        <input type="text" name="livestock_production_days" placeholder="e.g. 45" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>House Address</label>
                                <div class="input-wrapper">
                                    <i class="ri-home-4-line"></i>
                                    <input type="text" name="house_address" placeholder="e.g. GH-000-0000" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>GPS Coordinates</label>
                                <div class="input-wrapper">
                                    <i class="ri-map-pin-line"></i>
                                    <input type="text" name="gps_coordinates" placeholder="Lat, Long" required>
                                </div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Total Acreage</label>
                            <div class="input-wrapper">
                                <i class="ri-layout-grid-line"></i>
                                <input type="text" name="acreage" placeholder="e.g. 5" required>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Ghana Card Number</label>
                                <div class="input-wrapper">
                                    <i class="ri-id-card-line"></i>
                                    <input type="text" name="id_card_number" class="gha-card-input" placeholder="e.g. GHA-123456789-0" minlength="15" maxlength="15" pattern="[Gg][Hh][Aa]-\d{9}-\d" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Upload Ghana Card</label>
                                <div class="file-upload-box">
                                    <input type="file" name="id_card" accept="image/*,.pdf" onchange="previewFile(this, 'farmerIdPrev')" required>
                                    <div class="upload-content">
                                        <i class="ri-upload-cloud-2-line"></i>
                                        <span>Click or Drop Ghana Card</span>
                                    </div>
                                </div>
                                <div id="farmerIdPrev" class="preview-area"></div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Passport Photo</label>
                            <div class="file-upload-box">
                                <input type="file" name="passport_photo" accept="image/*" onchange="previewFile(this, 'farmerPassPrev')" required>
                                <div class="upload-content">
                                    <i class="ri-user-smile-line"></i>
                                    <span>Upload Photo</span>
                                </div>
                            </div>
                            <div id="farmerPassPrev" class="preview-area"></div>
                        </div>

                        <div class="input-group">
                            <label>Farmland Photos (Multiple)</label>
                            <div class="file-upload-box">
                                <input type="file" name="farmland_photos" multiple accept="image/*" onchange="previewFiles(this, 'farmPhotosPrev')" required>
                                <div class="upload-content">
                                    <i class="ri-image-add-line"></i>
                                    <span>Select Farm Photos</span>
                                    <small>Max 5MB each</small>
                                </div>
                            </div>
                            <div id="farmPhotosPrev" class="preview-area"></div>
                        </div>
                    </div>
                </div>

                <!-- AGENT FIELDS -->
                <div id="agentSection" class="dynamic-section" style="margin-top:24px; border-top:1px solid var(--border); padding-top:24px;">
                    <h3 style="margin:0 0 16px; font-size:1.2rem; font-weight:700; color:var(--primary);">Agent Details</h3>
                    
                    <div class="form-section">
                        <div class="input-group">
                            <label>Qualifications</label>
                            <div class="input-wrapper">
                                <i class="ri-graduation-cap-line"></i>
                                <input type="text" name="qualifications" placeholder="Degrees or Certifications" required>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>TIN Number</label>
                                <div class="input-wrapper">
                                    <i class="ri-hashtag"></i>
                                    <input type="text" name="tin_number" placeholder="e.g. A00000000000" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Interest Rate</label>
                                <div class="input-wrapper">
                                    <i class="ri-percent-line"></i>
                                    <input type="text" name="interest_rate" placeholder="e.g. 15%" required>
                                </div>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Loan Terms</label>
                            <div class="input-wrapper">
                                <i class="ri-file-list-3-line"></i>
                                <input type="text" name="loan_terms" placeholder="Brief summary of terms" required>
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label>GPS Address</label>
                            <div class="input-wrapper">
                                <i class="ri-map-pin-2-line"></i>
                                <input type="text" name="gps_address" placeholder="e.g. GA-123-4567" required>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Ghana Card Number</label>
                                <div class="input-wrapper">
                                    <i class="ri-id-card-line"></i>
                                    <input type="text" name="id_card_number" class="gha-card-input" placeholder="e.g. GHA-123456789-0" minlength="15" maxlength="15" pattern="[Gg][Hh][Aa]-\d{9}-\d" required>
                                </div>
                            </div>
                            <div class="input-group">
                                <label>Upload Ghana Card</label>
                                <div class="file-upload-box">
                                    <input type="file" name="id_card" accept="image/*,.pdf" onchange="previewFile(this, 'agentIdPrev')" required>
                                    <div class="upload-content">
                                        <i class="ri-upload-cloud-2-line"></i>
                                        <span>Upload Ghana Card</span>
                                    </div>
                                </div>
                                <div id="agentIdPrev" class="preview-area"></div>
                            </div>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Passport Photo</label>
                                <div class="file-upload-box">
                                    <input type="file" name="passport_photo" accept="image/*" onchange="previewFile(this, 'agentPassPrev')" required>
                                    <div class="upload-content"><i class="ri-user-smile-line"></i><span>Photo</span></div>
                                </div>
                                <div id="agentPassPrev" class="preview-area"></div>
                            </div>
                            
                            <div class="input-group">
                                <label>Certificate</label>
                                <div class="file-upload-box">
                                    <input type="file" name="certificate_photo" accept="image/*,.pdf" onchange="previewFile(this, 'certPrev')" required>
                                    <div class="upload-content"><i class="ri-award-line"></i><span>Cert</span></div>
                                </div>
                                <div id="certPrev" class="preview-area"></div>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div class="input-group">
                                <label>Interior Photo</label>
                                <div class="file-upload-box">
                                    <input type="file" name="interior_photo" accept="image/*" onchange="previewFile(this, 'intPrev')" required>
                                    <div class="upload-content"><i class="ri-building-line"></i><span>Inside</span></div>
                                </div>
                                <div id="intPrev" class="preview-area"></div>
                            </div>
                            
                            <div class="input-group">
                                <label>Exterior Photo</label>
                                <div class="file-upload-box">
                                    <input type="file" name="exterior_photo" accept="image/*" onchange="previewFile(this, 'extPrev')" required>
                                    <div class="upload-content"><i class="ri-store-2-line"></i><span>Outside</span></div>
                                </div>
                                <div id="extPrev" class="preview-area"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:32px">
                    <button type="submit" class="btn-primary" id="submitBtn">Complete Registration</button>
                    <p style="text-align:center; font-size:0.9rem; margin-top:16px; color:var(--text-muted)">
                        Already have an account? <a href="login.php" style="color:var(--primary); font-weight:700; text-decoration:none;">Login</a>
                    </p>
                </div>
            </form>
        </div>

        <!-- Sidebar Info -->
        <aside class="side-panel">
            <div style="position:sticky; top:120px;">
                <div class="info-box">
                    <h4><i class="ri-shield-check-line"></i> Trust & Compliance</h4>
                    <p>To ensure a highly secure experience for farmers, lenders, and buyers, we verify physical profiles and identity documents. All uploaded assets are securely handled in compliance with regional privacy frameworks.</p>
                </div>

                <div class="info-box" style="background:var(--bg-card); border-color:var(--border);">
                    <h4><i class="ri-question-line"></i> Support Channel</h4>
                    <p>Encountering issues while submitting your file attachments? Connect with our desk at <strong>support@agroloan.com</strong> or our dedicated help line.</p>
                </div>
            </div>
        </aside>

    </div>
</main>

<!-- Footer -->
<footer>
    <div class="footer-content">
        <div class="footer-col">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
                <img src="../assets/images/logo.jpg" alt="Logo" style="width:30px; border-radius:4px;">
                <h3 style="margin:0; color:#fff;">AgroMarket</h3>
            </div>
            <p style="opacity:0.7; line-height:1.6;">Empowering local networks with complete digital tooling and crop-to-consumer integration.</p>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="about.php">About Us</a></li>
                <li><a href="services.php">Our Services</a></li>
                <li><a href="contact.php">Contact</a></li>
                <li><a href="register.php">Register</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Legal</h4>
            <ul>
                <li><a href="#">Privacy Policy</a></li>
                <li><a href="#">Terms of Service</a></li>
                <li><a href="#">Agent Agreement</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Connect</h4>
            <div style="display:flex; gap:15px; font-size:1.2rem;">
                <a href="#"><i class="ri-facebook-circle-fill"></i></a>
                <a href="#"><i class="ri-twitter-x-fill"></i></a>
                <a href="#"><i class="ri-linkedin-box-fill"></i></a>
                <a href="#"><i class="ri-instagram-fill"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <p>© <?= date('Y'); ?> Agro Loan & Market. All Rights Reserved.</p>
    </div>
</footer>

<!-- Success Popup -->
<?php if (!empty($success)): ?>
<div id="successPopup" style="display:flex">
    <div class="popup-card" id="popupCard">
        <div class="check-circle">
            <i class="ri-check-line"></i>
        </div>
        <h2 style="margin:0 0 10px; color:var(--text-main); font-family:'Merriweather',serif;">Registration Complete!</h2>
        <p style="color:var(--text-muted); margin-bottom:20px"><?= htmlspecialchars($success) ?></p>
        <p style="font-size:0.9rem; color:var(--primary); font-weight:600">Redirecting to login...</p>
    </div>
</div>
<?php endif; ?>

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

    // --- Mobile Menu Logic ---
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

    // --- Navbar Scroll Effect ---
    window.addEventListener('scroll', () => {
        const header = document.getElementById('mainHeader');
        if (window.scrollY > 20) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });

    // --- Passwords Visibility toggle ---
    function togglePasswords() {
        const p1 = document.getElementById('pass1');
        const p2 = document.getElementById('pass2');
        const icon = document.getElementById('eyeIcon');
        
        if (p1.type === 'password') {
            p1.type = 'text';
            p2.type = 'text';
            icon.className = 'ri-eye-off-line';
        } else {
            p1.type = 'password';
            p2.type = 'password';
            icon.className = 'ri-eye-line';
        }
    }

    // --- Form Specific Logic ---
    function switchRole() {
        const roles = document.getElementsByName('role');
        let selected = '';
        for(let r of roles) if(r.checked) selected = r.value;
        
        const sections = {
            'buyer': document.getElementById('buyerSection'),
            'farmer': document.getElementById('farmerSection'),
            'agent': document.getElementById('agentSection')
        };

        for (let key in sections) {
            const sec = sections[key];
            if (sec) {
                if (key === selected) {
                    sec.style.display = 'block';
                    // Enable inputs so they are validated and submitted by the browser
                    sec.querySelectorAll('input, select, textarea').forEach(input => {
                        input.disabled = false;
                    });
                } else {
                    sec.style.display = 'none';
                    // Disable inputs so hidden inputs do not block form submissions
                    sec.querySelectorAll('input, select, textarea').forEach(input => {
                        input.disabled = true;
                    });
                }
            }
        }
    }

    function toggleFarm() {
        const val = document.getElementById('farm_type').value;
        const cropFields = document.getElementById('crop_fields');
        const livestockFields = document.getElementById('livestock_fields');
        
        if (val === 'crop') {
            cropFields.style.display = 'block';
            cropFields.querySelectorAll('input').forEach(el => el.disabled = false);
            
            livestockFields.style.display = 'none';
            livestockFields.querySelectorAll('input').forEach(el => el.disabled = true);
        } else if (val === 'livestock') {
            cropFields.style.display = 'none';
            cropFields.querySelectorAll('input').forEach(el => el.disabled = true);
            
            livestockFields.style.display = 'block';
            livestockFields.querySelectorAll('input').forEach(el => el.disabled = false);
        } else {
            cropFields.style.display = 'none';
            cropFields.querySelectorAll('input').forEach(el => el.disabled = true);
            
            livestockFields.style.display = 'none';
            livestockFields.querySelectorAll('input').forEach(el => el.disabled = true);
        }
    }

    // Run switcher once on initialization to handle any form state retention
    switchRole();

    // File Preview Logic
    function previewFile(input, targetId) {
        const wrap = document.getElementById(targetId);
        wrap.innerHTML = '';
        if(input.files && input.files[0]) {
            const f = input.files[0];
            const div = document.createElement('div');
            if(f.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.className = 'preview-item';
                img.src = URL.createObjectURL(f);
                wrap.appendChild(img);
            } else {
                const span = document.createElement('span');
                span.className = 'preview-name';
                span.textContent = f.name;
                wrap.appendChild(span);
            }
        }
    }

    function previewFiles(input, targetId) {
        const wrap = document.getElementById(targetId);
        wrap.innerHTML = '';
        if(input.files) {
            Array.from(input.files).forEach(f => {
                if(f.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.className = 'preview-item';
                    img.src = URL.createObjectURL(f);
                    wrap.appendChild(img);
                } else {
                    const span = document.createElement('span');
                    span.className = 'preview-name';
                    span.textContent = f.name;
                    wrap.appendChild(span);
                }
            });
        }
    }

    // Custom Client-Side Validity Messages for explicit field reminders
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.getElementById('regForm');
        
        // Strip emojis from all text input and textarea fields in real-time
        const stripEmojis = (e) => {
            const emojiRegex = /[\u{1F300}-\u{1F9FF}]|[\u{2700}-\u{27BF}]|[\u{1F600}-\u{1F64F}]|[\u{1F680}-\u{1F6FF}]|[\u{2600}-\u{26FF}]|\p{Extended_Pictographic}/gu;
            e.target.value = e.target.value.replace(emojiRegex, '');
        };

        const textInputs = form.querySelectorAll('input:not([type="file"]):not([type="radio"]):not([type="checkbox"]), textarea');
        textInputs.forEach(input => {
            input.addEventListener('input', stripEmojis);
        });

        // Setup validation for Phone input
        const phoneInput = document.getElementById('phoneInput');
        if (phoneInput) {
            // Instantly strip away any non-numeric characters on type/paste (also handles emojis implicitly)
            phoneInput.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/\D/g, '');
            });

            const validatePhone = () => {
                const value = phoneInput.value.trim();
                if (!value) {
                    phoneInput.setCustomValidity("Please enter your phone number.");
                } else if (value.length !== 10) {
                    phoneInput.setCustomValidity("Phone number must be exactly 10 digits long (currently " + value.length + " digits).");
                } else {
                    phoneInput.setCustomValidity("");
                }
            };
            phoneInput.addEventListener('input', validatePhone);
            phoneInput.addEventListener('invalid', validatePhone);
        }

        // Setup validation for all Ghana Card input fields
        const ghaInputs = form.querySelectorAll('.gha-card-input');
        ghaInputs.forEach(input => {
            const validateGha = () => {
                if (input.disabled) {
                    input.setCustomValidity("");
                    return;
                }
                const value = input.value.trim();
                const pattern = /^[Gg][Hh][Aa]-\d{9}-\d$/;
                if (!value) {
                    input.setCustomValidity("Please enter your Ghana Card number.");
                } else if (value.length !== 15) {
                    input.setCustomValidity("Ghana Card number must be exactly 15 characters long (currently " + value.length + " characters). Format: GHA-123456789-0");
                } else if (!pattern.test(value)) {
                    input.setCustomValidity("Please follow the Ghana Card format: GHA-XXXXXXXXX-X (e.g. GHA-123456789-0)");
                } else {
                    input.setCustomValidity("");
                }
            };
            input.addEventListener('input', validateGha);
            input.addEventListener('invalid', validateGha);
        });

        // Set descriptive messages for other required fields
        const allRequired = form.querySelectorAll('input[required], select[required]');
        allRequired.forEach(input => {
            if (input.id === 'phoneInput' || input.classList.contains('gha-card-input')) return;

            const handleRequired = () => {
                if (input.disabled) {
                    input.setCustomValidity("");
                    return;
                }
                if (input.validity.valueMissing) {
                    const labelText = input.closest('.input-group')?.querySelector('label')?.textContent || "this field";
                    const cleanLabel = labelText.replace(/\([^)]*\)/g, "").trim();
                    input.setCustomValidity(`Please fill out the ${cleanLabel} field.`);
                } else {
                    input.setCustomValidity("");
                }
            };
            input.addEventListener('input', handleRequired);
            input.addEventListener('invalid', handleRequired);
        });
    });

    // Form submission processing toggle
    document.getElementById('regForm').addEventListener('submit', function(e) {
        const btn = document.getElementById('submitBtn');
        if (this.checkValidity()) {
            btn.disabled = true;
            btn.innerText = "Processing registration...";
        }
    });

    // Success Animation & Redirect
    <?php if(!empty($success)): ?>
    window.addEventListener('load', () => {
        setTimeout(() => {
            document.getElementById('popupCard').classList.add('show');
        }, 50);
        setTimeout(() => {
            // Redirect to login page with registered query parameter
            window.location.href = 'login.php?registered=1';
        }, 3000);
    });
    <?php endif; ?>
</script>
</body>
</html>