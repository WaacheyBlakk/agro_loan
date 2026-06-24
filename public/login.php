<?php 
// public/login.php
session_start();
require_once __DIR__ . '/../src/functions.php'; 

// Conditionally require database connection helper if it exists
if (file_exists(__DIR__ . '/../src/db.php')) {
    require_once __DIR__ . '/../src/db.php';
}

// 1. Redirect users who are already logged in to their respective portals
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
        exit;
    } elseif ($_SESSION['role'] === 'farmer') {
        header('Location: farmer_dashboard.php');
        exit;
    } elseif ($_SESSION['role'] === 'agent') {
        header('Location: agent_dashboard.php');
        exit;
    } elseif ($_SESSION['role'] === 'buyer') {
        header('Location: shop.php');
        exit;
    }
}

$error = '';
$login_success = false;
$redirect_url = '';

// Check if function exists to prevent fatal errors if functions.php is missing in this context
if (!function_exists('verify_user')) {
    function verify_user($e, $p) { return null; } 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        // Step 1: Attempt to verify user against general accounts (Admin, Farmer, Agent)
        $user = verify_user($email, $password);

        if ($user) {
            // Check verification status for general users
            if ($user['status'] !== 'verified') {
                $error = "Your account is pending admin verification.";
            } else {
                // Set Session Data for general accounts
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['id'] = $user['id']; // compatibility key
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];

                $login_success = true;

                // Determine Redirect Target
                if ($user['role'] === 'admin') {
                    $redirect_url = 'admin_dashboard.php';
                } elseif ($user['role'] === 'farmer') {
                    $redirect_url = 'farmer_dashboard.php';
                } elseif ($user['role'] === 'agent') {
                    $redirect_url = 'agent_dashboard.php';
                } else {
                    $error = "Unknown user role.";
                    $login_success = false;
                    session_destroy();
                }
            }
        } else {
            // Step 2: Fallback to authenticating as a Buyer if database function is available
            if (function_exists('getPDO')) {
                try {
                    $pdo = getPDO();
                    $stmt = $pdo->prepare("SELECT * FROM buyers WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $buyer = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($buyer && password_verify($password, $buyer['password'])) {
                        // Check verification status for Buyers
                        if ($buyer['status'] !== 'approved') {
                            if (in_array($buyer['status'], ['pending', 'submitted', 'unverified'])) {
                                $error = "Your profile is awaiting administrator verification. Access is restricted until approved.";
                            } elseif (in_array($buyer['status'], ['denied', 'rejected'])) {
                                $error = "Your account application has been declined. Please contact administration support.";
                            } else {
                                $error = "Your account is unverified. Please check back later.";
                            }
                        } else {
                            session_regenerate_id(true); 
                            $_SESSION['user_id'] = $buyer['id'];
                            $_SESSION['id'] = $buyer['id']; // compatibility key
                            $_SESSION['name'] = $buyer['name']; 
                            $_SESSION['email'] = $buyer['email'] ?? $email;
                            $_SESSION['role'] = 'buyer';      // Explicitly set role
                            
                            $login_success = true;
                            $redirect_url = 'shop.php';
                        }
                    } else {
                        $error = "Invalid email or password.";
                    }
                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            } else {
                $error = "Invalid email or password.";
            }
        }
    } else {
        $error = "Please enter both email and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | Agro Loan & Market</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:wght@700&display=swap" rel="stylesheet">

<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<!-- SweetAlert -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    /* --- SYSTEM VARIABLES --- */
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
    --focus-ring: rgba(21, 128, 61, 0.2);

    /* Login Specific Extras */
    --card-bg: rgba(255, 255, 255, 0.95);
    --card-border: rgba(255, 255, 255, 0.5);
}

body.dark {
    /* Dark Theme */
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

    /* Login Specific Extras */
    --card-bg: rgba(30, 41, 59, 0.9);
    --card-border: rgba(255, 255, 255, 0.1);
}

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--text-main);
    margin: 0;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    transition: background 0.3s ease, color 0.3s ease;
    
    /* Login Background */
    background-image: url('../assets/images/login.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
}

/* Dark Overlay for Login Background */
body::before {
    content: '';
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.8), rgba(20, 83, 45, 0.7));
    z-index: 0;
    pointer-events: none;
}

/* --- HEADER / NAVBAR --- */
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

.logo-container img { height: 40px; width: 40px; border-radius: 8px; object-fit: cover; }

.logo-container h1 {
    font-size: 1.5rem; font-weight: 800; margin: 0; letter-spacing: -0.5px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}

.header-right { display: flex; align-items: center; gap: 15px; }

nav { display: flex; align-items: center; gap: 30px; }

nav a {
    color: var(--text-main); text-decoration: none; font-weight: 600;
    font-size: 0.95rem; transition: color 0.3s; position: relative;
}
nav a:hover { color: var(--primary); }
nav a::after {
    content: ''; position: absolute; width: 0; height: 2px;
    bottom: -4px; left: 0; background-color: var(--primary);
    transition: width 0.3s;
}
nav a:hover::after { width: 100%; }

.btn-login-nav {
    padding: 8px 20px;
    border: 2px solid var(--primary);
    border-radius: 50px;
    color: white;
    background: var(--primary);
    font-weight: 600;
    transition: 0.3s;
    text-decoration: none;
}
.btn-login-nav:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
.btn-login-nav::after { display: none; } 

.theme-toggle {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 50%;
    color: var(--text-main);
    cursor: pointer;
    width: 40px; height: 40px;
    font-size: 1.2rem;
    display: flex; justify-content: center; align-items: center;
    transition: 0.3s;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.theme-toggle:hover {
    transform: rotate(15deg) scale(1.1);
    border-color: var(--primary);
}

/* --- MOBILE MENU --- */
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

/* --- LOGIN CONTENT --- */
main {
    position: relative;
    z-index: 10;
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 120px 20px 60px; 
}

.login-card {
    background: var(--card-bg);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid var(--card-border);
    padding: 45px 40px;
    border-radius: 24px;
    width: 100%;
    max-width: 440px;
    box-shadow: var(--shadow);
    transform: translateY(0);
    animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.card-header { text-align: center; margin-bottom: 30px; }
.icon-circle {
    width: 60px; height: 60px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 15px;
    font-size: 1.8rem;
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.card-header h2 { 
    font-family: 'Merriweather', serif; font-size: 1.8rem; margin: 0 0 5px; color: var(--text-main);
}
.card-header p { color: var(--text-muted); font-size: 0.95rem; margin: 0; }

/* Form Styles */
.form-group { margin-bottom: 20px; }
.form-group label {
    display: block; font-size: 0.9rem; font-weight: 600; 
    margin-bottom: 8px; color: var(--text-main);
}
.input-wrapper { position: relative; }
.input-wrapper i.field-icon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: 1.1rem; pointer-events: none;
    transition: 0.2s;
}
.input-wrapper input {
    width: 100%;
    padding: 12px 12px 12px 42px;
    border: 1px solid var(--border);
    border-radius: 12px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.95rem;
    background: var(--bg-body);
    color: var(--text-main);
    transition: 0.2s;
}
.input-wrapper input:focus {
    outline: none; border-color: var(--primary); 
    box-shadow: 0 0 0 4px var(--focus-ring);
    background: var(--bg-card);
}
.input-wrapper input:focus + i.field-icon { color: var(--primary); }

.toggle-password {
    position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
    cursor: pointer; color: var(--text-muted); font-size: 1.1rem;
    transition: 0.2s;
}
.toggle-password:hover { color: var(--text-main); }

.btn-primary {
    background: var(--primary); color: white; border: none; padding: 14px;
    border-radius: 50px; font-weight: 700; font-size: 1rem; width: 100%;
    cursor: pointer; transition: 0.3s; box-shadow: 0 4px 12px var(--focus-ring);
    display: flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }

.alert-error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
    padding: 14px; border-radius: 12px; margin-bottom: 20px;
    font-size: 0.9rem; display: flex; align-items: center; gap: 10px;
}
body.dark .alert-error {
    background: #7f1d1d; color: #fecaca; border-color: #991b1b;
}

.alert-success {
    background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;
    padding: 14px; border-radius: 12px; margin-bottom: 20px;
    font-size: 0.9rem; display: flex; align-items: center; gap: 10px;
}
body.dark .alert-success {
    background: #064e3b; color: #a7f3d0; border-color: #047857;
}

.links {
    margin-top: 25px;
    display: flex; flex-direction: column; gap: 12px;
    font-size: 0.9rem;
}
.links-top {
    display: flex; justify-content: space-between; align-items: center; width: 100%;
}
.links-bottom {
    text-align: center; font-size: 0.85rem; border-top: 1px solid var(--border); padding-top: 15px; margin-top: 5px;
}
.links a { color: var(--text-muted); text-decoration: none; transition: 0.2s; }
.links a:hover { color: var(--primary); }
.links .register-link { color: var(--primary); font-weight: 600; }

/* --- FOOTER --- */
footer {
    background: #064e3b; 
    color: #ecfdf5;
    padding: 60px 5% 30px;
    position: relative; 
    z-index: 10;
}

.footer-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 40px;
    margin-bottom: 40px;
}

.footer-col h4 { color: #fff; font-size: 1.2rem; margin-bottom: 20px; }
.footer-col ul { list-style: none; padding: 0; margin: 0; }
.footer-col ul li { margin-bottom: 12px; }
.footer-col a { color: #a7f3d0; text-decoration: none; transition: 0.3s; }
.footer-col a:hover { color: #fff; padding-left: 5px; }

.footer-bottom {
    text-align: center;
    border-top: 1px solid rgba(255,255,255,0.1);
    padding-top: 20px;
    font-size: 0.9rem;
    color: #6ee7b7;
}

/* Responsive */
@media (max-width: 768px) {
    nav { display: none; }
    .mobile-toggle-btn { display: block; }
    .login-card { padding: 30px 25px; }
}
</style>
</head>
<body>

<!-- Mobile Overlay -->
<div class="overlay" id="overlay"></div>

<!-- Header -->
<header id="mainHeader">
    <a href="index.php" class="logo-container">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" onerror="this.style.display='none'">
        <h1>AgroLoan</h1>
    </a>
    
    <div class="header-right">
        <!-- Desktop Nav -->
        <nav>
            <a href="index.php">Home</a>
            <a href="about.php">About</a>
            <a href="services.php">Services</a>
            <a href="shop.php">Shop</a>
            <a href="register.php">Register</a>
            <a href="contact.php">Contact Us</a>
            <a href="login.php" class="btn-login-nav">Login</a>
        </nav>
        
        <!-- Theme Toggle -->
        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
            <i class="ri-moon-line"></i>
        </button>

        <!-- Mobile Menu Toggle -->
        <button class="mobile-toggle-btn" id="mobileToggle">
            <i class="ri-menu-3-line"></i>
        </button>
    </div>
</header>

<!-- Mobile Menu (Functional) -->
<div class="mobile-menu" id="mobileMenu">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <span style="font-weight:800; font-size:1.2rem; color:var(--primary);">Menu</span>
        <i class="ri-close-line" id="closeMenu" style="font-size:1.5rem; cursor:pointer;"></i>
    </div>
    <a href="index.php">Home</a>
    <a href="about.php">About</a>
    <a href="services.php">Services</a>
    <a href="shop.php">Shop</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact Us</a>
    <a href="login.php" style="color:var(--primary);">Login</a>
</div>

<main>
    <div class="login-card">
        <div class="card-header">
            <div class="icon-circle">
                <i class="ri-plant-line"></i>
            </div>
            <h2>Welcome Back</h2>
            <p>Access your account to continue</p>
        </div>

        <!-- Buyer Registration Success Alert -->
        <?php if (isset($_GET['registered']) && $_GET['registered'] == 1): ?>
            <div class="alert-success">
                <i class="ri-checkbox-circle-fill"></i>
                <span>Registration complete. Your profile has been sent to the administrator for verification.</span>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="ri-error-warning-fill"></i> 
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" onsubmit="document.getElementById('submitBtn').disabled = true;">
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="ri-mail-line field-icon"></i>
                    <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <div class="input-wrapper">
                    <i class="ri-lock-2-line field-icon"></i>
                    <input type="password" name="password" id="passwordInput" placeholder="Enter your password" required>
                    <i class="ri-eye-off-line toggle-password" id="togglePassword"></i>
                </div>
            </div>

            <button type="submit" id="submitBtn" class="btn-primary">
                Sign In <i class="ri-arrow-right-line"></i>
            </button>
        </form>

        <div class="links">
            <div class="links-top">
                <a href="forgot_password.php">Forgot Password?</a>
                <a href="shop.php"><i class="ri-arrow-left-line"></i> Back to Shop</a>
            </div>
            <div class="links-bottom">
                <div>New User? <a href="register.php" class="register-link">Create Account</a></div>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer>
    <div class="footer-content">
        <div class="footer-col">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
                <img src="../assets/images/logo.jpg" alt="Logo" style="width:30px; border-radius:4px;">
                <h3 style="margin:0; color:#fff;">AgroLoan</h3>
            </div>
            <p style="opacity:0.7; line-height:1.6;">Empowering smallholder farmers with financial inclusion and technology to feed the future.</p>
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
        <p>© <?= date('Y'); ?> Agro Loan. All Rights Reserved.</p>
    </div>
</footer>

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

    // --- Password Toggle ---
    const passInput = document.getElementById('passwordInput');
    const passIcon = document.getElementById('togglePassword');

    passIcon.addEventListener('click', () => {
        const type = passInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passInput.setAttribute('type', type);
        passIcon.className = type === 'password' ? 'ri-eye-off-line toggle-password' : 'ri-eye-line toggle-password';
    });

    // --- Login Success Animation ---
    <?php if ($login_success && $redirect_url): ?>
        const isDarkTheme = body.classList.contains('dark');
        
        Swal.fire({
            title: 'Login Successful!',
            text: 'Redirecting...',
            icon: 'success',
            timer: 2000,
            timerProgressBar: true,
            showConfirmButton: false,
            backdrop: `rgba(0,0,0,0.8)`, 
            background: isDarkTheme ? '#1e293b' : '#ffffff',
            color: isDarkTheme ? '#ffffff' : '#1e293b',
            iconColor: '#22c55e',
            willClose: () => {
                window.location.href = '<?= $redirect_url ?>';
            }
        });
    <?php endif; ?>
</script>

</body>
</html>