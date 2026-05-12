<?php
// public/services.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Services | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Tailored financial and technological services for African farmers.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
:root {
    /* Variables from about.php/index.php */
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
    /* Dark Variables */
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

/* --- SERVICES PAGE SPECIFIC STYLES --- */

/* Hero Section */
.page-hero {
    position: relative;
    background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.5)), url('../assets/images/service-bg.jpg') center/cover;
    height: 50vh; 
    min-height: 400px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: white;
    margin-top: 70px; 
    padding: 20px;
}

.page-hero h2 {
    font-family: 'Merriweather', serif;
    font-size: 3.5rem;
    margin: 0 0 15px 0;
    text-shadow: 0 4px 12px rgba(0,0,0,0.4);
    animation: fadeInUp 1s ease-out;
}

.page-hero p {
    font-size: 1.2rem;
    max-width: 600px;
    opacity: 0.9;
    animation: fadeInUp 1s ease-out 0.2s backwards;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Container */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 60px 24px;
    width: 100%;
}

/* Section Header */
.section-header {
    text-align: center;
    margin-bottom: 60px;
}
.section-header h2 {
    font-family: 'Merriweather', serif;
    font-size: 2.5rem;
    color: var(--primary);
    margin-bottom: 15px;
}
.section-header p {
    color: var(--text-muted);
    font-size: 1.1rem;
    max-width: 700px;
    margin: 0 auto;
}

/* Services Grid */
.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}

.service-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px 30px;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
}

.service-card:hover {
    transform: translateY(-10px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}

.icon-box {
    width: 70px;
    height: 70px;
    background: var(--primary-light);
    border-radius: 50%; /* Circular icons for services */
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 2rem;
    color: var(--primary);
    transition: 0.3s;
}

.service-card:hover .icon-box {
    background: var(--primary);
    color: white;
}

.service-card h3 {
    color: var(--text-main);
    font-size: 1.35rem;
    margin-bottom: 15px;
    font-weight: 700;
}

.service-card p {
    color: var(--text-muted);
    line-height: 1.7;
    font-size: 0.95rem;
    margin: 0;
}

/* CTA Section (Matching about.php style) */
.cta-section {
    background: var(--primary-dark);
    color: white;
    text-align: center;
    padding: 80px 20px;
    border-radius: 30px;
    margin: 80px 0 40px;
    background-image: linear-gradient(135deg, var(--primary-dark) 0%, #064e3b 100%);
    position: relative;
    overflow: hidden;
}

.cta-section::before, .cta-section::after {
    content: ''; position: absolute; width: 300px; height: 300px; background: rgba(255,255,255,0.05); border-radius: 50%; z-index: 0;
}
.cta-section::before { top: -100px; left: -100px; }
.cta-section::after { bottom: -100px; right: -100px; }
.cta-content { position: relative; z-index: 1; }

.cta-section h2 { font-family: 'Merriweather', serif; font-size: 2.5rem; margin-bottom: 20px; }
.cta-section p { font-size: 1.1rem; opacity: 0.9; margin-bottom: 30px; max-width: 600px; margin-left: auto; margin-right: auto; }

.btn-cta {
    display: inline-block; background: var(--accent); color: #fff; padding: 15px 40px; border-radius: 50px; text-decoration: none; font-weight: 700; transition: 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.btn-cta:hover { background: #eab308; transform: translateY(-3px); }

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

/* Footer (Standard) */
footer {
    background: #064e3b; /* Deep forest green */
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
    /* Standardized breakpoints */
}

@media (max-width: 768px) {
    /* Hide desktop nav */
    nav { display: none; }
    /* Show mobile button */
    .mobile-toggle-btn { display: block; }
    
    .page-hero { height: auto; padding: 80px 20px; }
    .page-hero h2 { font-size: 2.2rem; }
    .section-header h2, .cta-section h2 { font-size: 2rem; }
    .cta-section { padding: 60px 20px; }
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
            <a href="services.php" class="active">Services</a>
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
    <a href="index.php">Home</a>
    <a href="about.php">About</a>
    <a href="services.php" style="color:var(--primary);">Services</a>
    <a href="shop.php">Shop</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact Us</a>
    <a href="login.php">Login</a>
</div>

<!-- Hero Header -->
<div class="page-hero">
    <h2>Comprehensive Solutions</h2>
    <p>Technology and finance working together for your harvest.</p>
</div>

<div class="container">
    
    <div class="section-header reveal">
        <h2>What We Offer</h2>
        <p>Tailored financial services designed for the unique needs of African agriculture.</p>
    </div>

    <div class="services-grid reveal">
        <!-- Card 1 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-hand-coin-line"></i></div>
            <h3>Farmer Loans</h3>
            <p>
                Accessible, low-interest loans tailored for seasonal farming. Our AI-driven approval system ensures you get funds for seeds, fertilizer, and equipment exactly when you need them.
            </p>
        </div>

        <!-- Card 2 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-user-star-line"></i></div>
            <h3>Agent Partnership</h3>
            <p>
                Join our network as a field agent. We provide digital tools, training, and a real-time dashboard to help you vet farmers, process applications, and earn commissions.
            </p>
        </div>

        <!-- Card 3 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-smartphone-line"></i></div>
            <h3>Digital Platform</h3>
            <p>
                A unified mobile-friendly platform connecting farmers, agents, and banks. Track loan status, upload proofs, and manage repayments seamlessly from anywhere.
            </p>
        </div>

        <!-- Card 4 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-plant-line"></i></div>
            <h3>Training & Support</h3>
            <p>
                Beyond money, we offer capacity building. Access digital resources on sustainable farming practices, financial literacy, and record-keeping.
            </p>
        </div>

        <!-- Card 5 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-bar-chart-grouped-line"></i></div>
            <h3>Data Insights</h3>
            <p>
                Leverage farm productivity data to make better decisions. Our reports help analyze loan impact and improve operational efficiency for future funding.
            </p>
        </div>

        <!-- Card 6 -->
        <div class="service-card">
            <div class="icon-box"><i class="ri-global-line"></i></div>
            <h3>Community Network</h3>
            <p>
                Connect with a broader ecosystem of buyers, suppliers, and fellow farmers. We foster networks that promote collective bargaining and shared success.
            </p>
        </div>
    </div>

    <!-- Call to Action -->
    <div class="cta-section reveal">
        <div class="cta-content">
            <h2>Ready to scale your operations?</h2>
            <p>Join thousands of farmers transforming their yield with Agro Loan.</p>
            <a href="register.php" class="btn-cta">
                Get Started Today <i class="ri-arrow-right-line"></i>
            </a>
        </div>
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
</script>

</body>
</html>