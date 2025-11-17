<?php 
session_start();
require_once __DIR__ . '/../src/functions.php'; // ensure this loads db.php and verify_user()

$error = '';
$login_success = false;
$redirect_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $user = verify_user($email, $password);

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];

            $login_success = true;

            if ($user['status'] !== 'verified') {
                echo "<script>alert('Your account is pending admin verification.'); window.location='login.php';</script>";
                exit;
            }

            if ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
                exit;
            }

            if ($user['role'] === 'farmer') {
                $redirect_url = 'farmer_dashboard.php';
            } elseif ($user['role'] === 'agent') {
                $redirect_url = 'agent_dashboard.php';
            } else {
                $error = "Unknown user role.";
            }
        } else {
            $error = "Invalid email or password.";
        }
   } else {
    $error = "Enter both email and password.";
}

// Only set session data if login was successful
if ($user) {
    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];
}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

/* HEADER */
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

/* LOGIN FORM */
.main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 60px 20px;
}

.container {
    background: #fff;
    padding: 35px 30px;
    border-radius: 15px;
    width: 360px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    animation: fadeIn 0.5s ease-in-out;
    box-sizing: border-box;
}

body.dark .container {
    background: #1f1f1f;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

h2 {
    text-align: center;
    margin-bottom: 25px;
    color: var(--primary);
}

/* FIXED INPUT FIELDS */
input[type="email"],
input[type="password"] {
    display: block;
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    margin: 10px 0;
    border: 1px solid #ccc;
    border-radius: 8px;
    font-size: 15px;
    background: #fafafa;
}
input[type="email"]:focus,
input[type="password"]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 6px rgba(46,125,50,0.3);
}

/* BUTTON */
button {
    width: 100%;
    padding: 12px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
    transition: background 0.3s;
}
button:hover {
    background: #246b45;
}

/* ERROR + LINK */
.error {
    color: red;
    text-align: center;
    margin-top: 12px;
}
.register-link {
    text-align: center;
    margin-top: 15px;
}
.register-link a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}
.register-link a:hover {
    text-decoration: underline;
}

/* FOOTER */
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
footer a:hover {
    text-decoration: underline;
}
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
    <a href="login.php" style="color:#c8e6c9;">Login</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact</a>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
  </nav>
</header>

<main class="main">
  <div class="container">
      <h2>Agro Loan Login</h2>

      <?php if ($error): ?>
          <p class="error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form method="POST">
          <input type="email" name="email" placeholder="Email address" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Login</button>
      </form>

      <div class="register-link">
          <p><a href="register.php">Register as Farmer or Agent</a></p>
      </div>

      <div class="register-link">
        <p><a href="reset_password.php">Forgot Password</a><p>
      </div>
  </div>
</main>

<footer>
  <p>© <?= date('Y'); ?> Agro Loan. All Rights Reserved. | <a href="contact.php">Contact Us</a></p>
</footer>

<script>
// 🌙 Dark Mode Toggle
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

// ✅ SweetAlert for login success
<?php if ($login_success && $redirect_url): ?>
Swal.fire({
    icon: 'success',
    title: '✅ Welcome!',
    text: 'Login successful!',
    showConfirmButton: false,
    timer: 1800
}).then(() => {
    window.location.href = '<?= $redirect_url ?>';
});
<?php endif; ?>
</script>

</body>
</html>
