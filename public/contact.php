<?php
// public/contact.php
session_start();
require_once __DIR__ . '/../src/db.php'; 

$success = false;
$error = '';

// Fallback if db.php is missing functions
if (!function_exists('getPDO')) {
    function getPDO() { return null; } 
}

$pdo = getPDO();

// Initialize variables for sticky form inputs
$name = '';
$email = '';
$subject = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validation
    if ($name && $email && $subject && $message) {
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $email, $subject, $message]);
                $success = true;
                
                // Reset inputs on success
                $name = $email = $subject = $message = ''; 
            } catch (Exception $e) {
                $error = "Error saving your message. Please try again later.";
            }
        } else {
            // Error if DB is down but form submitted
            $error = "Database connection unavailable. Please contact support via phone.";
        }
    } else {
        $error = "Please fill in all fields.";
    }

    // Optional: Send Email Logic (Commented out if no mail server configured)
    /*
    if ($name && $email && $message && !$error && $success) {
        $to = "waacheyblack@gmail.com"; 
        $mail_subject = "New Contact Form Message: $subject";
        $body = "Name: $name\nEmail: $email\nMessage:\n$message";
        $headers = "From: $email";
        mail($to, $mail_subject, $body, $headers);
    }
    */
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Contact Us | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Get in touch with Agro Loan for support and inquiries.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<!-- SweetAlert -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    /* Exact Variables from register.php */
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
    
    /* Additional vars */
    --primary-light: #dcfce7;
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --focus-ring: rgba(21, 128, 61, 0.2);

    /* Chatbot Variables */
    --chat-bg-user: #15803d;
    --chat-text-user: #ffffff;
    --chat-bg-bot: #f1f5f9;
    --chat-text-bot: #334155;
}

body.dark {
    /* Exact Dark Variables */
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

    /* Dark Mode Chat */
    --chat-bg-user: #22c55e;
    --chat-bg-bot: #334155;
    --chat-text-bot: #e2e8f0;
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

nav a:hover { color: var(--primary); }

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

/* --- PAGE LAYOUT & FORM (Matching register.php) --- */
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

/* Cards */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    box-shadow: var(--shadow);
}

.page-title { margin: 0 0 8px; font-size: 1.8rem; font-weight: 800; color: var(--text-main); font-family: 'Merriweather', serif; }
.page-subtitle { margin: 0 0 30px; color: var(--text-muted); font-size: 1.05rem; }

/* Form Elements */
.form-section { display: flex; flex-direction: column; gap: 20px; }

.input-group { margin-bottom: 0; }
.input-group label { display: block; margin-bottom: 8px; font-size: 0.9rem; font-weight: 600; color: var(--text-main); }
.input-wrapper { position: relative; }
.input-wrapper i {
    position: absolute; left: 14px; top: 14px;
    color: var(--text-muted); font-size: 1.1rem; pointer-events: none;
}
.input-wrapper input, .input-wrapper textarea {
    width: 100%; padding: 12px 12px 12px 42px;
    border: 1px solid var(--border); border-radius: 12px;
    font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.95rem;
    background: var(--bg-body); color: var(--text-main); transition: 0.2s;
}
.input-wrapper textarea { height: 140px; resize: vertical; }

.input-wrapper input:focus, .input-wrapper textarea:focus {
    outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--focus-ring); background: var(--bg-card);
}

/* Buttons */
.btn-primary {
    background: var(--primary); color: white; border: none; padding: 16px;
    border-radius: 50px; font-weight: 700; font-size: 1rem; width: 100%;
    cursor: pointer; transition: 0.3s; box-shadow: 0 4px 12px var(--focus-ring);
    display: flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }

/* Sidebar Info */
.info-box {
    background: var(--primary-light); border: 1px solid transparent;
    border-radius: 16px; padding: 24px; margin-bottom: 24px;
}
.info-box h4 { margin: 0 0 10px; color: var(--primary); font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.info-box p { font-size: 0.9rem; line-height: 1.6; color: var(--text-main); margin: 0; opacity: 0.9; }

/* Contact Details in Sidebar */
.contact-detail {
    display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px;
}
.contact-detail:last-child { margin-bottom: 0; }
.contact-detail i {
    font-size: 1.2rem; color: var(--primary); margin-top: 2px;
}
.contact-detail div strong { display: block; font-size: 0.9rem; color: var(--text-main); margin-bottom: 2px; }
.contact-detail div span { font-size: 0.9rem; color: var(--text-muted); }

/* Alerts */
.alert { padding: 16px; border-radius: 12px; margin-bottom: 20px; font-size: 0.95rem; display: flex; gap: 10px; align-items: flex-start; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* --- CHATBOT STYLES --- */
.chatbot-toggler {
    position: fixed; bottom: 30px; right: 35px;
    height: 55px; width: 55px; border: none;
    background: var(--primary); color: #fff;
    border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: transform 0.2s, background 0.3s; z-index: 1001;
}
.chatbot-toggler:hover { background: var(--primary-dark); transform: scale(1.1); }
.chatbot-toggler span { position: absolute; transition: all 0.3s ease; }
.chatbot-toggler span:last-child, .show-chat .chatbot-toggler span:first-child { opacity: 0; transform: rotate(90deg); }
.show-chat .chatbot-toggler span:last-child { opacity: 1; transform: rotate(0); }

.chatbot-window {
    position: fixed; bottom: 95px; right: 35px; width: 350px;
    background: var(--bg-card); border-radius: 16px;
    box-shadow: var(--shadow-lg); overflow: hidden;
    transform: scale(0.5); opacity: 0; pointer-events: none;
    transform-origin: bottom right; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    border: 1px solid var(--border); z-index: 1002; display: flex; flex-direction: column;
}
.show-chat .chatbot-window { transform: scale(1); opacity: 1; pointer-events: auto; }

.chat-header {
    background: var(--primary); padding: 16px; color: #fff;
    display: flex; justify-content: center; align-items: center; position: relative;
}
.chat-header h2 { margin: 0; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.chat-close-btn { 
    position: absolute; right: 15px; font-size: 1.4rem; cursor: pointer; color: rgba(255,255,255,0.8); 
}
.chat-close-btn:hover { color: #fff; }

.chatbox {
    padding: 20px 15px; height: 350px; overflow-y: auto; background: var(--bg-body);
    display: flex; flex-direction: column; gap: 15px;
}
.chat-entry { display: flex; list-style: none; }
.chat-entry.outgoing { justify-content: flex-end; }
.chat-entry.incoming span {
    width: 32px; height: 32px; color: #fff; background: var(--primary);
    text-align: center; line-height: 32px; border-radius: 50%; 
    align-self: flex-end; margin-right: 10px; flex-shrink: 0;
}
.chat-bubble {
    max-width: 75%; padding: 10px 14px; font-size: 0.9rem;
    line-height: 1.4; border-radius: 12px 12px 12px 0; word-wrap: break-word;
}
.chat-entry.outgoing .chat-bubble {
    background: var(--chat-bg-user); color: var(--chat-text-user); border-radius: 12px 12px 0 12px;
}
.chat-entry.incoming .chat-bubble {
    background: var(--chat-bg-bot); color: var(--chat-text-bot); border: 1px solid var(--border);
}

.chat-input {
    padding: 10px 15px; border-top: 1px solid var(--border);
    display: flex; gap: 10px; background: var(--bg-card); align-items: center;
}
.chat-input textarea {
    height: 45px; width: 100%; border: none; outline: none; resize: none;
    font-size: 0.95rem; background: transparent; color: var(--text-main); 
    padding: 12px 0; font-family: inherit;
}
.send-chat-btn {
    color: var(--primary); font-size: 1.4rem; cursor: pointer; 
    transition: 0.2s; visibility: hidden; opacity: 0;
}
.chat-input textarea:valid ~ .send-chat-btn { visibility: visible; opacity: 1; }
.send-chat-btn:hover { color: var(--primary-dark); transform: scale(1.1); }

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
            <a href="contact.php" class="active">Contact Us</a>
            <a href="login.php" class="btn-login">Login</a>
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
    <a href="contact.php" style="color:var(--primary);">Contact Us</a>
    <a href="login.php">Login</a>
</div>

<main class="container">
    <div class="auth-grid">
        
        <!-- Main Form Card (Left/Main) -->
        <div class="card">
            <h1 class="page-title">Get in Touch</h1>
            <p class="page-subtitle">Fill out the form below and our team will get back to you.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="ri-error-warning-fill" style="margin-top:2px"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-section">
                    <div class="input-group">
                        <label>Full Name</label>
                        <div class="input-wrapper">
                            <i class="ri-user-line"></i>
                            <input type="text" name="name" placeholder="Your Name" value="<?= htmlspecialchars($name ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <i class="ri-mail-line"></i>
                            <input type="email" name="email" placeholder="example@mail.com" value="<?= htmlspecialchars($email ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Subject</label>
                        <div class="input-wrapper">
                            <i class="ri-price-tag-3-line"></i>
                            <input type="text" name="subject" placeholder="What is this regarding?" value="<?= htmlspecialchars($subject ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Message</label>
                        <div class="input-wrapper">
                            <i class="ri-chat-1-line"></i>
                            <textarea name="message" placeholder="How can we help you?" required><?= htmlspecialchars($message ?? '') ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        Send Message <i class="ri-send-plane-fill"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Sidebar Info (Right) -->
        <aside class="side-panel">
            <div style="position:sticky; top:120px;">
                <!-- Contact Info Box -->
                <div class="info-box">
                    <h4><i class="ri-map-pin-user-line"></i> Head Office</h4>
                    <p style="margin-bottom:20px;">123 Farmers Avenue, Agro City<br>Greater Accra, Ghana</p>
                    
                    <h4><i class="ri-contacts-line"></i> Reach Us</h4>
                    <div class="contact-detail">
                        <i class="ri-phone-line"></i>
                        <div>
                            <strong>Phone</strong>
                            <span>+233 20 123 4567</span>
                        </div>
                    </div>
                    <div class="contact-detail">
                        <i class="ri-mail-send-line"></i>
                        <div>
                            <strong>Email</strong>
                            <span>support@agroloan.com</span>
                        </div>
                    </div>
                </div>

                <!-- Help Box -->
                <div class="info-box" style="background:var(--bg-card); border-color:var(--border);">
                    <h4><i class="ri-customer-service-2-line"></i> Support</h4>
                    <p>Our support team is available Monday to Friday, 8:00 AM to 5:00 PM. <br><br>You can also use the chatbot for instant answers.</p>
                    
                    <div style="margin-top:20px; display:flex; gap:15px; font-size:1.3rem; color:var(--text-muted);">
                        <a href="#" style="color:var(--text-muted); transition:0.3s;"><i class="ri-facebook-circle-fill"></i></a>
                        <a href="#" style="color:var(--text-muted); transition:0.3s;"><i class="ri-twitter-x-fill"></i></a>
                        <a href="#" style="color:var(--text-muted); transition:0.3s;"><i class="ri-linkedin-box-fill"></i></a>
                    </div>
                </div>
            </div>
        </aside>

    </div>
</main>

<!-- CHATBOT INTERFACE -->
<button class="chatbot-toggler" id="chatbotToggler">
    <span class="ri-message-3-line"></span>
    <span class="ri-close-line"></span>
</button>

<div class="chatbot-window">
    <div class="chat-header">
        <h2><i class="ri-robot-line"></i> Agro Assistant</h2>
        <span class="chat-close-btn" onclick="document.body.classList.remove('show-chat')">×</span>
    </div>
    <ul class="chatbox">
        <li class="chat-entry incoming">
            <span class="ri-customer-service-2-fill"></span>
            <div class="chat-bubble">Hi! I'm your Agro Assistant. Ask me about loan interest rates, requirements, or how to register!</div>
        </li>
    </ul>
    <div class="chat-input">
        <textarea placeholder="Type a message..." required></textarea>
        <span id="send-chat-btn" class="ri-send-plane-fill send-chat-btn"></span>
    </div>
</div>

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

    // --- SweetAlert for PHP Form ---
    <?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Message Sent!',
        text: 'Thank you for contacting us. We will get back to you soon.',
        confirmButtonColor: '#15803d',
        timer: 3000
    });
    <?php endif; ?>

    // --- CHATBOT LOGIC ---
    const chatInput = document.querySelector(".chat-input textarea");
    const sendChatBtn = document.querySelector(".chat-input span");
    const chatbox = document.querySelector(".chatbox");
    const chatbotToggler = document.querySelector(".chatbot-toggler");

    let userMessage;

    // Smart Response Database
    const knowledgeBase = {
        "hello": "Hello! Welcome to AgroLoan. How can I assist you with your farming finances today?",
        "hi": "Hi there! Looking for a loan or have questions about registration?",
        "loan": "We offer Equipment Loans, Seed Funding, and Harvest Loans. Interest rates start at 5%. Do you need requirements?",
        "interest": "Our interest rates are very low for farmers, ranging from 5% to 12% depending on the season duration.",
        "apply": "To apply, please Register an account first using the button in the menu, then visit your dashboard.",
        "register": "Registration is free! Click 'Register' in the menu, enter your details, and verify your phone number.",
        "requirements": "You need a valid Ghana Card, a registered Mobile Money number, and proof of farmland ownership.",
        "location": "We are located at 123 Farmers Avenue, Agro City, Greater Accra.",
        "contact": "You are on the contact page! Fill out the form here or email support@agroloan.com.",
        "money": "Loans are disbursed directly to your Mobile Money wallet (MTN/Vodafone/AirtelTigo).",
        "bye": "Goodbye! Happy farming!",
        "thank": "You're very welcome!",
        "default": "I'm not sure about that specific detail. Please contact our support team using the form on this page or call +233 20 123 4567."
    };

    const createChatLi = (message, className) => {
        const chatLi = document.createElement("li");
        chatLi.classList.add("chat-entry", className);
        let chatContent = className === "outgoing" ? 
            `<div class="chat-bubble"></div>` : 
            `<span class="ri-customer-service-2-fill"></span><div class="chat-bubble"></div>`;
        chatLi.innerHTML = chatContent;
        chatLi.querySelector(".chat-bubble").textContent = message;
        return chatLi;
    }

    const generateResponse = (incomingChatLi) => {
        const messageElement = incomingChatLi.querySelector(".chat-bubble");
        
        // Convert to lowercase and remove punctuation
        const cleanInput = userMessage.toLowerCase().replace(/[^\w\s]/gi, '');
        let responseText = knowledgeBase["default"];

        // Keyword matching logic
        const words = cleanInput.split(' ');
        for (let word of words) {
            if (knowledgeBase[cleanInput]) {
                responseText = knowledgeBase[cleanInput];
                break;
            }
            for (let key in knowledgeBase) {
                if (cleanInput.includes(key)) {
                    responseText = knowledgeBase[key];
                    break;
                }
            }
        }

        // Set response after slight delay
        setTimeout(() => {
            messageElement.textContent = responseText;
            chatbox.scrollTo(0, chatbox.scrollHeight);
        }, 600); 
    }

    const handleChat = () => {
        userMessage = chatInput.value.trim();
        if(!userMessage) return;

        // Reset height and value
        chatInput.value = "";
        
        // Add User Message
        chatbox.appendChild(createChatLi(userMessage, "outgoing"));
        chatbox.scrollTo(0, chatbox.scrollHeight);

        // Add Bot Thinking Placeholder
        setTimeout(() => {
            const incomingChatLi = createChatLi("Thinking...", "incoming");
            chatbox.appendChild(incomingChatLi);
            chatbox.scrollTo(0, chatbox.scrollHeight);
            generateResponse(incomingChatLi);
        }, 400);
    }

    // Event Listeners
    chatbotToggler.addEventListener("click", () => document.body.classList.toggle("show-chat"));
    sendChatBtn.addEventListener("click", handleChat);
    chatInput.addEventListener("keyup", (e) => {
        if(e.key === "Enter" && !e.shiftKey) {
            e.preventDefault(); 
            handleChat();
        }
    });
</script>
</body>
</html>