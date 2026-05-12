<?php
// public/about.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>About Us | Agro Loan</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Empowering African farmers with accessible financial solutions.">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,700;1,300&display=swap" rel="stylesheet">
<!-- Icons -->
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
:root {
    /* Exact Variables from index.php */
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
    
    /* Additional vars needed for specific about page elements */
    --primary-light: #dcfce7;
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}

body.dark {
    /* Exact Dark Variables from index.php */
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

/* --- Header / Navbar  --- */
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

/* --- About Page Content Styles --- */
.page-hero {
    position: relative;
    background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.5)), url('../assets/images/farmers.jpg') center/cover;
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

.reveal {
    opacity: 0;
    transform: translateY(30px);
    transition: all 0.8s ease-out;
}
.reveal.active {
    opacity: 1;
    transform: translateY(0);
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 24px;
    width: 100%;
}

.section-padding { padding: 100px 0; }

.about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    align-items: center;
}

.about-text h3 {
    color: var(--primary);
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.about-text h3::before {
    content: '';
    width: 30px;
    height: 2px;
    background: var(--primary);
}

.about-text h2 {
    font-family: 'Merriweather', serif;
    font-size: 2.5rem;
    margin-top: 0;
    margin-bottom: 24px;
    line-height: 1.2;
}

.about-text p {
    color: var(--text-muted);
    line-height: 1.8;
    font-size: 1.1rem;
    margin-bottom: 24px;
}

.image-stack {
    position: relative;
    padding: 20px;
}
.image-stack img {
    width: 100%;
    border-radius: 24px;
    box-shadow: var(--shadow-lg);
    z-index: 2;
    position: relative;
}
.image-stack::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100%;
    height: 100%;
    background: var(--primary-light);
    border-radius: 24px;
    transform: rotate(3deg);
    z-index: 1;
}

/* Stats */
.stats-container {
    background: var(--bg-card);
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    padding: 60px 0;
    margin: 80px 0;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 30px;
    text-align: center;
}
.stat-item h3 {
    font-size: 2.5rem;
    color: var(--primary);
    margin: 0;
    font-weight: 800;
}
.stat-item p {
    color: var(--text-muted);
    font-weight: 600;
    margin-top: 5px;
}

/* Mission */
.mission-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    margin-bottom: 100px;
}
.mission-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px;
    transition: all 0.3s ease;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
}
.mission-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
    border-color: var(--primary);
}
.icon-box {
    width: 60px;
    height: 60px;
    background: var(--primary-light);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: var(--primary);
    margin-bottom: 20px;
}
.mission-card h3 { font-size: 1.5rem; margin: 0 0 15px; color: var(--text-main); }
.mission-card p { color: var(--text-muted); line-height: 1.7; margin: 0; }

/* Team */
.team-section { text-align: center; margin-bottom: 100px; }
.section-header { max-width: 700px; margin: 0 auto 60px; }
.section-header h2 { font-family: 'Merriweather', serif; font-size: 2.5rem; margin-bottom: 15px; }
.section-header p { color: var(--text-muted); font-size: 1.1rem; }
.team-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 30px; }
.team-card {
    background: var(--bg-card);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: transform 0.3s ease;
    border: 1px solid var(--border);
}
.team-card:hover { transform: translateY(-10px); }
.team-img-wrapper { height: 280px; overflow: hidden; position: relative; }
.team-img-wrapper img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
.team-card:hover .team-img-wrapper img { transform: scale(1.05); }
.team-info { padding: 25px; text-align: left; }
.team-info h4 { margin: 0; font-size: 1.25rem; color: var(--text-main); font-weight: 700; }
.team-role { color: var(--primary); font-weight: 600; font-size: 0.9rem; margin: 5px 0 15px; display: block; }
.team-info p { font-size: 0.95rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px; }
.social-links { display: flex; gap: 15px; }
.social-links a { color: var(--text-muted); transition: color 0.3s; font-size: 1.1rem; }
.social-links a:hover { color: var(--primary); }

/* CTA */
.cta-section {
    background: var(--primary-dark);
    color: white;
    text-align: center;
    padding: 80px 20px;
    border-radius: 30px;
    margin: 0 20px 80px;
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

/* --- Footer (Updated to Match Landing Page) --- */
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
    .about-grid { grid-template-columns: 1fr; gap: 40px; }
    .image-stack { max-width: 500px; margin: 0 auto; }
}

@media (max-width: 768px) {
    /* Hide desktop nav */
    nav { display: none; }
    /* Show mobile button */
    .mobile-toggle-btn { display: block; }
    
    .page-hero { height: auto; padding: 80px 20px; }
    .page-hero h2 { font-size: 2.2rem; }
    .about-text h2, .section-header h2, .cta-section h2 { font-size: 2rem; }
    .cta-section { margin: 0 10px 60px; padding: 60px 20px; }
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
            <a href="about.php" class="active">About</a>
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

        <!-- Mobile Menu Toggle (Only visible on small screens) -->
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
    <a href="about.php" style="color:var(--primary);">About</a>
    <a href="services.php">Services</a>
    <a href="shop.php">Shop</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact Us</a>
    <a href="login.php">Login</a>
</div>

<!-- Hero Header -->
<div class="page-hero">
    <h2>Our Story & Vision</h2>
    <p>Cultivating a brighter future for African agriculture through accessible finance and technology.</p>
</div>

<div class="container">
    
    <!-- About Story -->
    <section class="section-padding reveal">
        <div class="about-grid">
            <div class="about-text">
                <h3>Who We Are</h3>
                <h2>Empowering African Agriculture Since 2023</h2>
                <p>
                    Agro Loan was founded with a simple but powerful vision: to empower farmers across Africa with easy access to financial support. We believe that when farmers thrive, communities flourish.
                </p>
                <p>
                    Many smallholder farmers face significant barriers to accessing affordable credit. We bridge this gap by connecting farmers with trusted agents and financial partners who share our commitment to sustainable agricultural development.
                </p>
            </div>
            <div class="image-stack">
                <img src="../assets/images/farmers.jpg" alt="Farmers in field">
                <img src="../assets/images/poultry.jpg" alt="Poultry">
            </div>
        </div>
    </section>

</div>

<!-- Stats Section -->
<div class="stats-container reveal">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <h3>5k+</h3>
                <p>Farmers Supported</p>
            </div>
            <div class="stat-item">
                <h3>GHc 2M+</h3>
                <p>Loans Disbursed</p>
            </div>
            <div class="stat-item">
                <h3>98%</h3>
                <p>Repayment Rate</p>
            </div>
            <div class="stat-item">
                <h3>16</h3>
                <p>Partner Regions</p>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <!-- Mission & Vision -->
    <section class="reveal">
        <div class="mission-grid">
            <div class="mission-card">
                <div class="icon-box"><i class="ri-rocket-2-fill"></i></div>
                <h3>Our Mission</h3>
                <p>To catalyze agricultural growth by providing farmers with tailored financial solutions, educational resources, and technological tools that increase productivity and ensure long-term sustainability.</p>
            </div>
            <div class="mission-card">
                <div class="icon-box"><i class="ri-eye-2-fill"></i></div>
                <h3>Our Vision</h3>
                <p>A future where every farmer, regardless of scale, has the opportunity to access fair capital, expand their business, and contribute meaningfully to global food security.</p>
            </div>
            <div class="mission-card">
                <div class="icon-box"><i class="ri-shake-hands-fill"></i></div>
                <h3>Our Values</h3>
                <p>Integrity, Innovation, and Inclusivity drive everything we do. We prioritize transparent relationships with our farmers and partners to build a resilient agricultural ecosystem.</p>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="team-section reveal">
        <div class="section-header">
            <h2>Meet The Leadership</h2>
            <p>Our diverse team combines expertise in agronomy, fintech, and operations to serve our farming communities better.</p>
        </div>
        
        <div class="team-grid">
            
            <div class="team-card">
                <div class="team-img-wrapper">
                    <img src="../assets/images/team1.jpg" alt="CEO">
                </div>
                <div class="team-info">
                    <h4>Abdul-Rahaman Salifu</h4>
                    <span class="team-role">Founder & CEO</span>
                    <p>Visionary leader leveraging tech to solve financial exclusion in rural agriculture.</p>
                    <div class="social-links">
                        <a href="#"><i class="ri-linkedin-fill"></i></a>
                        <a href="#"><i class="ri-twitter-x-fill"></i></a>
                        <a href="#"><i class="ri-mail-fill"></i></a>
                    </div>
                </div>
            </div>

            <div class="team-card">
                <div class="team-img-wrapper">
                    <img src="../assets/images/team2.jpg" alt="COO">
                </div>
                <div class="team-info">
                    <h4>Grace Mensah</h4>
                    <span class="team-role">Co-Founder & COO</span>
                    <p>Drives operational excellence, ensuring seamless collaboration between stakeholders.</p>
                    <div class="social-links">
                        <a href="#"><i class="ri-linkedin-fill"></i></a>
                        <a href="#"><i class="ri-mail-fill"></i></a>
                    </div>
                </div>
            </div>

            <div class="team-card">
                <div class="team-img-wrapper">
                    <img src="../assets/images/team3.jpg" alt="CTO">
                </div>
                <div class="team-info">
                    <h4>Michael Owusu</h4>
                    <span class="team-role">Technical Director</span>
                    <p>Architect behind our secure platform, ensuring data integrity and UX.</p>
                    <div class="social-links">
                        <a href="#"><i class="ri-github-fill"></i></a>
                        <a href="#"><i class="ri-linkedin-fill"></i></a>
                    </div>
                </div>
            </div>

            <div class="team-card">
                <div class="team-img-wrapper">
                    <img src="../assets/images/team4.jpg" alt="Analyst">
                </div>
                <div class="team-info">
                    <h4>Linda Addo</h4>
                    <span class="team-role">Financial Analyst</span>
                    <p>Expert in agri-finance, designing flexible loan products for harvest cycles.</p>
                    <div class="social-links">
                        <a href="#"><i class="ri-linkedin-fill"></i></a>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- CTA Section -->
    <div class="cta-section reveal">
        <div class="cta-content">
            <h2>Ready to Grow Your Farm?</h2>
            <p>Join thousands of farmers who have transformed their yield with Agro Loan's financial support. Fast approval, flexible terms.</p>
            <a href="register.php" class="btn-cta">Apply for a Loan</a>
        </div>
    </div>

</div>

<!-- Footer (Exact Structure from Index.php) -->
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

// --- Scroll Effects ---
// Navbar Shadow
window.addEventListener('scroll', () => {
    const header = document.getElementById('mainHeader');
    if (window.scrollY > 50) {
        header.classList.add('scrolled');
    } else {
        header.classList.remove('scrolled');
    }
});

// Reveal Elements on Scroll
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
// Trigger once on load
scrollReveal();
</script>

</body>
</html>