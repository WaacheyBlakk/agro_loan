<?php
// public/index.php — Landing Page
session_start();

// Redirect logged-in users automatically
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === 'farmer') {
        header('Location: farmer_dashboard.php');
        exit;
    } elseif ($role === 'agent') {
        header('Location: agent_dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Agro Loan | Empowering Farmers</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Secure affordable agricultural loans and connect with verified agents in Ghana.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
:root {
    /* Variables from about.php */
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
}

body.dark {
    /* Dark Variables from about.php */
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

/* --- Header / Navbar (Exact from about.php) --- */
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

/* Header Right Side Wrapper */
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

/* --- LANDING PAGE SPECIFIC STYLES --- */

/* Hero Section */
.hero {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 120px 20px 80px;
    background: 
        linear-gradient(to bottom, rgba(0,0,0,0.4), rgba(0,0,0,0.7)),
        url('../assets/images/farm-bg.jpg') center/cover no-repeat;
    color: white;
    position: relative;
    margin-top: 0; /* Override about.php hero logic */
}

.hero-content {
    max-width: 800px;
    animation: slideUp 1s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(40px); }
    to { opacity: 1; transform: translateY(0); }
}

.hero h2 {
    font-family: 'Merriweather', serif;
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 20px;
    line-height: 1.1;
    letter-spacing: -1px;
}

.hero p {
    font-size: 1.25rem;
    margin-bottom: 40px;
    opacity: 0.9;
    line-height: 1.6;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.hero .btn {
    background: var(--accent);
    color: white;
    padding: 16px 40px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 700;
    font-size: 1.1rem;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(34, 197, 94, 0.3);
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.hero .btn:hover {
    background: var(--accent-hover);
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(34, 197, 94, 0.4);
}

/* Features */
section {
    padding: 100px 5%; /* Matches section-padding */
}

.section-title {
    text-align: center;
    font-size: 2.25rem;
    font-weight: 800;
    margin-bottom: 60px;
    color: var(--text-main);
    font-family: 'Merriweather', serif;
}
.section-title span {
    color: var(--primary);
}

.features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    max-width: 1200px;
    margin: 0 auto;
}

.feature {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    text-align: left;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow);
}

.feature:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}

.feature-icon {
    font-size: 2.5rem;
    color: var(--primary);
    margin-bottom: 20px;
    background: var(--primary-light);
    width: 70px;
    height: 70px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.feature h3 {
    color: var(--text-main);
    font-size: 1.5rem;
    margin-bottom: 15px;
    font-weight: 700;
}

.feature p {
    color: var(--text-muted);
    line-height: 1.6;
    margin: 0;
}

/* Testimonials */
.testimonials {
    background: var(--bg-card);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    text-align: center;
}

#testimonial-wrapper {
    position: relative;
    max-width: 800px;
    margin: 0 auto;
    min-height: 250px; /* Keeps layout stable */
    perspective: 1000px;
}

.testimonial {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    opacity: 0;
    transform: translateY(20px) scale(0.95);
    transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
    pointer-events: none;
    padding: 10px;
}

.testimonial.active {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}

.quote-icon {
    font-size: 3rem;
    color: var(--primary);
    opacity: 0.2;
    margin-bottom: 10px;
    display: block;
}

.testimonial p {
    font-size: 1.3rem;
    font-weight: 500;
    line-height: 1.6;
    color: var(--text-main);
    margin-bottom: 20px;
}

.testimonial strong {
    color: var(--primary);
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: block;
}

/* Partners */
.partners {
    background: var(--bg-body);
}

.partner-logos {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 60px;
    align-items: center;
}

.partner-logos img {
    height: 80px;
    width: auto;
    opacity: 0.7;
    transition: all 0.4s ease;
}

.partner-logos img:hover {
    opacity: 1;
    transform: scale(1.1);
}

/* Footer */
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

/* Responsive */
@media (max-width: 992px) {
    .hero h2 { font-size: 3rem; }
}

@media (max-width: 768px) {
    /* Hide desktop nav */
    nav { display: none; }
    /* Show mobile button */
    .mobile-toggle-btn { display: block; }
    
    .hero { padding: 100px 20px 60px; text-align: center; }
    .hero h2 { font-size: 2.2rem; }
    .hero p { font-size: 1.1rem; }
    
    .section-title { font-size: 2rem; }
    .testimonial p { font-size: 1.1rem; }
}

/* Animation Reveal */
.reveal {
    opacity: 0;
    transform: translateY(30px);
    transition: all 0.8s ease-out;
}
.reveal.active {
    opacity: 1;
    transform: translateY(0);
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
            <a href="index.php" class="active">Home</a>
            <a href="about.php">About</a>
            <a href="services.php">Services</a>
            <a href="shop.php">Shop</a>
            <a href="register.php">Register</a>
            <a href="contact.php">Contact Us</a>
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
    <a href="index.php" style="color:var(--primary);">Home</a>
    <a href="about.php">About</a>
    <a href="services.php">Services</a>
    <a href="shop.php">Shop</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact Us</a>
    <a href="login.php">Login</a>
</div>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-content">
        <h2>Grow Your Farm with <br><span style="color: var(--accent);">Instant Financing</span></h2>
        <p>We bridge the gap between ambition and harvest. Secure affordable loans, connect with verified agents, and transform your agricultural business today.</p>
        <a href="register.php" class="btn">
            Get Started Now <i class="ri-arrow-right-line"></i>
        </a>
    </div>
</section>

<!-- Features Section -->
<section class="features reveal">
    <div class="feature">
        <div class="feature-icon"><i class="ri-flashlight-fill"></i></div>
        <h3>Fast Approvals</h3>
        <p>Skip the paperwork of traditional banks. Our AI-driven process approves loans in record time so you don't miss the season.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><i class="ri-shield-check-fill"></i></div>
        <h3>Secure Platform</h3>
        <p>Bank-grade security protocols ensuring your personal data and financial transactions are safe and encrypted.</p>
    </div>
    <div class="feature">
        <div class="feature-icon"><i class="ri-user-voice-fill"></i></div>
        <h3>Agent Support</h3>
        <p>Access our network of verified field agents who guide you through application, farming advice, and repayment.</p>
    </div>
</section>

<!-- Testimonials Section -->
<section class="testimonials reveal">
  <h2 class="section-title">Trusted by <span>Farmers</span> Across Ghana</h2>
  
  <div id="testimonial-wrapper">
    <div class="testimonial active">
      <i class="ri-double-quotes-l quote-icon"></i>
      <p>“Agro Loan helped me expand my maize farm. The process was simple, and the agent guided me all the way. I doubled my harvest this year!”</p>
      <strong>– Amina, Farmer (Tamale)</strong>
    </div>
    <div class="testimonial">
      <i class="ri-double-quotes-l quote-icon"></i>
      <p>“Before joining Agro Loan, I struggled to get financial support. Now, I can easily apply for loans and buy fertilizers right when I need them.”</p>
      <strong>– Adjoa, Farmer (Ho)</strong>
    </div>
    <div class="testimonial">
      <i class="ri-double-quotes-l quote-icon"></i>
      <p>“As an agent, I’ve connected dozens of farmers to life-changing funding. The digital platform makes the whole process transparent.”</p>
      <strong>– Efua, Agent (Cape Coast)</strong>
    </div>
    <div class="testimonial">
      <i class="ri-double-quotes-l quote-icon"></i>
      <p>“Agro Loan’s innovative approach is bridging the gap between finance and agriculture in rural communities. A game changer.”</p>
      <strong>– Kwame Mensah, EcoBank Ghana</strong>
    </div>
  </div>
</section>

<!-- Partners Section -->
<section class="partners reveal">
    <div style="text-align:center; margin-bottom:40px;">
        <h2 class="section-title" style="font-size: 1.8rem; margin-bottom:10px;">Our Ecosystem Partners</h2>
    </div>
    <div class="partner-logos">
        <img src="../assets/images/ecobank.png" alt="EcoBank" onerror="this.style.display='none'">
        <img src="../assets/images/agrifund.png" alt="AgriFund" onerror="this.style.display='none'">
        <img src="../assets/images/agrotech.png" alt="Ghana AgroTech" onerror="this.style.display='none'">
        <img src="../assets/images/adb.png" alt="ADB" onerror="this.style.display='none'">
    </div>
</section>

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

// Check LocalStorage
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

// --- Scroll Effects (Navbar Shadow) ---
window.addEventListener('scroll', () => {
    const header = document.getElementById('mainHeader');
    if (window.scrollY > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
});

// --- Scroll Reveal Animation ---
const revealElements = document.querySelectorAll('.reveal');
const scrollReveal = () => {
    const windowHeight = window.innerHeight;
    const elementVisible = 150;

    revealElements.forEach((reveal) => {
        const elementTop = reveal.getBoundingClientRect().top;
        if (elementTop < windowHeight - elementVisible) {
            reveal.classList.add('active');
        }
    });
};
window.addEventListener('scroll', scrollReveal);
scrollReveal(); // Trigger once on load

// --- Testimonial Rotator ---
document.addEventListener('DOMContentLoaded', () => {
  const testimonials = Array.from(document.querySelectorAll('#testimonial-wrapper .testimonial'));
  if (!testimonials.length) return;
  
  let idx = 0;
  const intervalTime = 5000; // 5 seconds

  setInterval(() => {
    // Remove active from current
    testimonials[idx].classList.remove('active');
    
    // Increment index
    idx = (idx + 1) % testimonials.length;
    
    // Add active to new
    testimonials[idx].classList.add('active');
  }, intervalTime);
});
</script>

</body>
</html>