<?php
session_start();
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/users.php';

$success = "";
$error = "";

// Ensure upload folders exist
$farmerDir = "../uploads/farmers/";
$agentDir  = "../uploads/agents/";

if (!file_exists($farmerDir)) mkdir($farmerDir, 0777, true);
if (!file_exists($agentDir)) mkdir($agentDir, 0777, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role   = $_POST['role'];
    $name   = trim($_POST['name']);
    $email  = trim($_POST['email']);
    $pass   = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {

        if ($role === 'farmer') {

          $id_card        = uploadFile('id_card', $farmerDir);
          $farmland_photos = uploadMultiple('farmland_photos', $farmerDir);
          $passport_photo = uploadFile('passport_photo', $farmerDir);
          $house_address  = $_POST['house_address'];
          $id_card_number  = $_POST['id_card_number'];

          $farmland_json = json_encode($farmland_photos);

          $stmt = $pdo->prepare(" 
                  INSERT INTO users (
                  name, email, password_hash, role, status,
                  id_card, id_card_number, house_address,
                  passport_photo, farmland_photos) 
                  VALUES (?, ?, ?, 'farmer', 'pending', ?, ?, ?, ?, ?)
                ");

          $stmt->execute([
              $name,
              $email,
              $password_hash,
              $id_card_path,
              $id_card_number,
              $house_address,
              $passport_path,
              json_encode($farmland_photo_paths)
          ]);

        }


        elseif ($role === 'agent') {

            $id_card   = uploadFile('id_card', $agentDir);
            $interior  = uploadFile('interior_photo', $agentDir);
            $exterior  = uploadFile('exterior_photo', $agentDir);
            $passport  = uploadFile('passport_photo', $agentDir);
            $certificate = uploadFile('certificate_photo', $agentDir);
            $tin_number = $_POST['tin_number'];
            $gps_address = $_POST['gps_address']; 
            $id_card_number  = $_POST['id_card_number'];

            $stmt = $pdo->prepare("
                INSERT INTO users (
                  name, email, password_hash, role, status,
                  id_card, id_card_number,
                  passport_photo, interior_photo, exterior_photo,
                  gps_address, tin_number, certificate_photo) 
                  VALUES (?, ?, ?, 'agent', 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $email,
                $password_hash,
                $id_card_path,
                $id_card_number,
                $passport_path,
                $interior_path,
                $exterior_path,
                $gps_address,
                $tin_number,
                $certificate_path
              ]);

          }


        $success = "Registration successful! Admin will verify your account.";

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
  --primary: #2e7d32;
  --accent: #66bb6a;
  --bg-light: #f7fbe9;
  --bg-dark: #121212;
  --text-light: #333;
  --text-dark: #f5f5f5;
}
body {
  font-family: 'Segoe UI', Arial, sans-serif;
  background: var(--bg-light);
  color: var(--text-light);
  margin: 0;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  transition: background 0.5s, color 0.5s;
}
body.dark {
  background: var(--bg-dark);
  color: var(--text-dark);
}

/* Navbar */
header {
  background: var(--primary);
  color: white;
  padding: 20px 40px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
header .logo-container {
    display: flex;
    align-items: center;
    gap: 12px;
}
header img.logo {
    height: 45px;
    width: auto;
    border-radius: 6px;
}
header h1 {
  font-size: 1.8rem;
  margin: 0;
}
nav {
  display: flex;
  align-items: center;
  gap: 20px;
}
nav a {
  color: white;
  text-decoration: none;
  font-weight: 500;
  transition: color 0.3s;
}
nav a:hover {
  color: #c8e6c9;
}
.theme-toggle {
  background: none;
  border: 2px solid white;
  border-radius: 50%;
  color: white;
  cursor: pointer;
  width: 35px;
  height: 35px;
  font-size: 16px;
  display: flex;
  justify-content: center;
  align-items: center;
  transition: 0.3s;
}
.theme-toggle:hover {
  background: white;
  color: var(--primary);
}

/* Form Styling */
.container {
  max-width: 700px;
  margin: 60px auto;
  padding: 40px;
  background: white;
  border-radius: 15px;
  box-shadow: 0 8px 25px rgba(0,0,0,0.1);
  transition: background 0.5s;
}
body.dark .container {
  background: #1e1e1e;
}
h2 {
  text-align: center;
  color: var(--primary);
  margin-bottom: 30px;
  font-size: 1.8rem;
}
form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
input, select, textarea {
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid #ccc;
  font-size: 15px;
  transition: border 0.3s, box-shadow 0.3s;
  width: 100%;
  box-sizing: border-box;
}
input:focus, select:focus, textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 6px rgba(102,187,106,0.4);
  outline: none;
}
button {
  background: var(--primary);
  color: white;
  border: none;
  padding: 14px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: bold;
  cursor: pointer;
  transition: 0.3s;
}
button:hover {
  background: var(--accent);
  transform: translateY(-2px);
}

.subsection {
  margin-top: 25px;
  padding: 20px;
  border-radius: 15px;
  background: #f1f8e9;
  border: 1px solid #dcedc8;
}
body.dark .subsection {
  background: #2c2c2c;
  border-color: #333;
}
.subsection h4 {
  color: var(--primary);
  margin-bottom: 15px;
  border-bottom: 1px solid #c8e6c9;
  padding-bottom: 5px;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 15px 20px;
}
@media (max-width: 600px) {
  .form-grid { grid-template-columns: 1fr; }
  .container { padding: 25px; margin: 20px; }
}

/* Success Popup (overlay) */
#successPopup {
  position: fixed;
  inset: 0;
  display: none; /* toggled via JS */
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.55);
  z-index: 9999;
}
#successPopup.visible { display: flex; }

/* box */
.success-box {
  width: 360px;
  padding: 28px 30px;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 18px 40px rgba(6, 20, 0, 0.25);
  text-align: center;
  transform: scale(.92);
  opacity: 0;
}
/* animate pop */
.success-box.visible {
  animation: popUp 460ms cubic-bezier(.2,.9,.3,1) forwards;
}
@keyframes popUp {
  to { transform: scale(1); opacity: 1; }
}

/* check circle + stroke */
.checkmark {
  width: 86px;
  height: 86px;
  border-radius: 50%;
  margin: 0 auto 16px;
  display: inline-block;
  border: 6px solid var(--accent);
  box-sizing: border-box;
  position: relative;
  transform: scale(.9);
  opacity: 0;
}
/* animate circle in */
.checkmark.visible {
  animation: circleIn 360ms ease-out forwards;
}
@keyframes circleIn {
  to { transform: scale(1); opacity: 1; }
}

/* the tick is drawn with pseudo-element - start with 0 width/height */
.checkmark::after {
  content: '';
  position: absolute;
  left: 28px;
  top: 22px;
  width: 0;
  height: 0;
  border-right: 5px solid var(--primary);
  border-bottom: 5px solid var(--primary);
  transform: rotate(45deg);
  transform-origin: left top;
  opacity: 0;
  box-sizing: border-box;
}
/* draw the tick */
.checkmark.draw::after {
  animation: drawTick 520ms cubic-bezier(.2,.9,.3,1) forwards 180ms;
}
@keyframes drawTick {
  0% { width: 0; height: 0; opacity: 0; }
  60% { width: 18px; height: 0; opacity: 1; }
  100% { width: 18px; height: 36px; opacity: 1; }
}

/* success text */
.success-box h3 { margin: 0 0 8px; color: var(--primary); font-size: 20px; }
.success-box p { margin:0 0 8px; color:#444; font-size:14px; }

/* small responsive */
@media(max-width:420px){
  .success-box { width: 92%; padding:22px; }
  .checkmark { width:66px; height:66px; }
}

/* Footer */
footer {
  background: #1b5e20;
  color: #c8e6c9;
  text-align: center;
  padding: 20px;
  margin-top: auto;
}
footer a {
  color: #a5d6a7;
  text-decoration: none;
}
footer a:hover { text-decoration: underline; }
</style>
</head>
<body>

<header>
  <div class="logo-container">
      <a href="index.php"><img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo"></a>
      <h1>Agro Loan</h1>
  </div>
  <nav>
    <a href="index.php">Home</a>
    <a href="about.php">About</a>
    <a href="services.php">Services</a>
    <a href="login.php">Login</a>
    <a href="register.php" style="color:#c8e6c9;">Register</a>
    <a href="contact.php">Contact</a>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
  </nav>
</header>

<div class="container">
  <h2>Create Your Account</h2>
  <form method="POST" enctype="multipart/form-data">

    <h3>Register as:</h3>
    <select name="role" id="role" required onchange="toggleRoleSections()">
        <option value="">-- Select Role --</option>
        <option value="farmer">Farmer</option>
        <option value="agent">Agent</option>
    </select>

    <input type="text" name="name" placeholder="Full Name" required>
    <input type="email" name="email" placeholder="Email Address" required>
    <input type="password" name="password" placeholder="Password" required>

    <!-- Farmer Section -->
    <div id="farmerFields" style="display:none; border:1px solid #ccc; padding:15px; margin-top:10px;">
        <h3>Farmer Requirements</h3>

        <label>Valid ID Card</label>
        <input type="file" name="id_card" required>

        <input type="text" name="id_card_number"  placeholder="Enter ID Card Number" required>

        <input type="text" name="house_address" placeholder="Enter Your House Address" required>

        <label>Farmland Photos (multiple allowed)</label>
        <input type="file" name="farmland_photos[]" multiple required>

        <label>Passport Photo</label>
        <input type="file" name="passport_photo" required>
    </div>

    <!-- Agent Section -->
    <div id="agentFields" style="display:none; border:1px solid #ccc; padding:15px; margin-top:10px;">
        <h3>Agent Requirements</h3>

        <label>Valid ID Card</label>
        <input type="file" name="id_card" required>

        <input type="text" name="id_card_number"  placeholder="Enter ID Card Number" required>

        <label>Institution Interior Photos</label>
        <input type="file" name="interior_photo" required>

        <label>Institution Exterior Photos</label>
        <input type="file" name="exterior_photo" required>

        <label>GPS Address</label>
        <input type="text" name="gps_address" placeholder="Enter GPS location (e.g. AK-234-5678)" required>

        <input type="text" name="tin_number"  placeholder="Enter TIN Number" required>

        <label>Passport Photo</label>
        <input type="file" name="passport_photo" required>

        <label>Business Certificate</label>
        <input type="file" name="certificate_photo" required>
    </div>
    <button type="submit">Register</button>
  </form>
</div>

<footer>
  <p>© <?= date('Y'); ?> Agro Loan. All Rights Reserved. | <a href="contact.php">Contact Us</a></p>
</footer>

<!-- Success popup overlay -->
<div id="successPopup" aria-hidden="true">
  <div class="success-box" id="successBox">
    <div class="checkmark" id="checkmark"></div>
    <h3>Registration Successful!</h3>
    <p>You’ll be redirected to the login page shortly.</p>
    <p style="font-size:13px; color:#666; margin-top:8px;">If you are not redirected automatically, <a href="login.php">click here to login</a>.</p>
  </div>
</div>

<script>
// Dark Mode Toggle
const toggle = document.getElementById('themeToggle');
const body = document.body;
if (localStorage.getItem('theme') === 'dark') {
  body.classList.add('dark');
  toggle.textContent = '☀️';
}
toggle.addEventListener('click', () => {
  body.classList.toggle('dark');
  const isDark = body.classList.contains('dark');
  localStorage.setItem('theme', isDark ? 'dark' : 'light');
  toggle.textContent = isDark ? '☀️' : '🌙';
});

// Dynamic form toggles
function toggleRole() {
  const role = document.getElementById('role').value;
  document.getElementById('agent_fields').style.display = role === 'agent' ? 'block' : 'none';
  document.getElementById('farmer_fields').style.display = role === 'farmer' ? 'block' : 'none';
}
function toggleFarm() {
  const farm = document.getElementById('farm_type').value;
  document.getElementById('crop_fields').style.display = farm === 'crop' ? 'grid' : 'none';
  document.getElementById('livestock_fields').style.display = farm === 'livestock' ? 'grid' : 'none';
}

// Show success popup after registration
<?php if ($success): ?>
window.addEventListener('DOMContentLoaded', () => {
  const popup = document.getElementById('successPopup');
  const box = document.getElementById('successBox');
  const check = document.getElementById('checkmark');

  popup.classList.add('visible');
  setTimeout(() => box.classList.add('visible'), 100);
  setTimeout(() => {
    check.classList.add('visible');
    setTimeout(() => check.classList.add('draw'), 400);
  }, 400);

  // Redirect after 3 seconds
  setTimeout(() => window.location.href = "login.php", 3000);
});
<?php endif; ?>
</script>
</body>
</html>

