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
header {
    background: var(--primary);
    color: white;
    padding: 15px 40px;
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

/* --- Hero Section --- */
.hero {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 80px 20px;
    background: url('../assets/images/farm-bg.jpg') center/cover no-repeat;
    color: white;
    position: relative;
}
.hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
}
.hero-content {
    position: relative;
    z-index: 1;
    max-width: 700px;
    animation: fadeInUp 1.5s ease-out;
}
@keyframes fadeInUp {
    0% { opacity: 0; transform: translateY(30px); }
    100% { opacity: 1; transform: translateY(0); }
}
.hero h2 {
    font-size: 2.5rem;
    margin-bottom: 15px;
}
.hero p {
    font-size: 1.2rem;
    margin-bottom: 25px;
}
.hero .btn {
    background: var(--accent);
    color: white;
    padding: 12px 30px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    transition: background 0.3s;
}
.hero .btn:hover { background: #43a047; }

/* --- Features --- */
section {
    padding: 60px 40px;
    text-align: center;
    transition: background 0.5s;
}
.features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    background: #ffffff;
}
body.dark .features { background: #1e1e1e; }
.feature {
    background: #f9fbe7;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}
body.dark .feature { background: #2c2c2c; }
.feature:hover { transform: translateY(-5px); }
.feature h3 { color: var(--primary); margin-bottom: 10px; }

/* --- Testimonials --- */
.testimonials {
    background: #e8f5e9;
}
body.dark .testimonials { background: #1a1a1a; }
#testimonial-wrapper {
    position: relative;
    max-width: 700px;
    margin: 40px auto 0;
    min-height: 160px;
}
.testimonial {
    position: absolute;
    left: 50%;
    transform: translateX(-50%) translateY(10px);
    width: calc(100% - 40px);
    background: #fff;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 6px 18px rgba(16,24,40,0.06);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.8s ease, transform 0.8s ease, filter 0.8s ease;
    filter: blur(2px);
}
.testimonial.active {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    pointer-events: auto;
    filter: blur(0);
}
.testimonial p { font-style: italic; margin: 0 0 10px; color: #333; }
.testimonial strong { color: var(--primary); display: block; }
body.dark .testimonial { background: #222; }

/* --- Partners --- */
.partners {
    background: #ffffff;
}
body.dark .partners { background: #1e1e1e; }
.partner-logos {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 40px;
    margin-top: 25px;
}
.partner-logos img {
    width: 120px;
    height: auto;
    opacity: 0.8;
    transition: all 0.3s;
}
.partner-logos img:hover {
    filter: grayscale(0);
    opacity: 1;
    transform: scale(1.05);
}

/* --- Footer --- */
footer {
    background: #1b5e20;
    color: #c8e6c9;
    text-align: center;
    padding: 20px;
}
footer a {
    color: #a5d6a7;
    text-decoration: none;
}
footer a:hover { text-decoration: underline; }

@media (max-width: 600px) {
    nav { flex-wrap: wrap; gap: 10px; justify-content: center; }
    .hero h2 { font-size: 1.8rem; }
    .hero p { font-size: 1rem; }
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
        <a href="login.php">Login</a>
        <a href="register.php">Register</a>
        <a href="contact.php">Contact</a>
        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
    </nav>
</header>

<section class="hero">
    <div class="hero-content">
        <h2>Empowering Farmers Through Easy Access to Loans</h2>
        <p>Join Agro Loan and secure financing to grow your agricultural business. We connect farmers and agents to create a thriving farming ecosystem.</p>
        <a href="register.php" class="btn">Get Started</a>
    </div>
</section>

<section class="features">
    <div class="feature">
        <h3>Fast Loan Approvals</h3>
        <p>Our streamlined application process helps farmers receive funding quickly and efficiently.</p>
    </div>
    <div class="feature">
        <h3>Secure Platform</h3>
        <p>Built with top-notch security to protect your data and ensure trustworthy transactions.</p>
    </div>
    <div class="feature">
        <h3>Agent Support</h3>
        <p>Agents guide farmers through every step — from loan applications to repayment.</p>
    </div>
</section>

<section class="testimonials">
  <h2>What Our Users Say 🌾</h2>
  <div id="testimonial-wrapper">
    <div class="testimonial active">
      “Agro Loan helped me expand my maize farm. The process was simple, and the agent guided me all the way.”
      <strong>– Amina, Farmer (Tamale)</strong>
    </div>
    <div class="testimonial">
      “Thanks to Agro Loan, I was able to buy fertilizers and irrigation tools for my rice farm. The repayment plan is flexible and suits the farming season perfectly.”
      <strong>– Joseph, Farmer (Sunyani)</strong>
    </div>
    <div class="testimonial">
      “Before joining Agro Loan, I struggled to get financial support from traditional banks. Now, I can easily apply for loans and track everything online.”
      <strong>– Adjoa, Farmer (Ho)</strong>
    </div>
    <div class="testimonial">
      “The agents are very supportive and understand the needs of farmers. Agro Loan has really changed the way we grow food in our community.”
      <strong>– Bashiru, Farmer (Wa)</strong>
    </div>
    <div class="testimonial">
      “Thanks to Agro Loan, I was able to buy fertilizers and irrigation tools for my rice farm. The repayment plan is flexible and suits the farming season perfectly.”
    <strong>– Joseph, Farmer (Sunyani)</strong>
    </div>

    <div class="testimonial">
      “Before joining Agro Loan, I struggled to get financial support from traditional banks. Now, I can easily apply for loans and track everything online.”
      <strong>– Adjoa, Farmer (Ho)</strong>
    </div>

    <div class="testimonial">
      “The agents are very supportive and understand the needs of farmers. Agro Loan has really changed the way we grow food in our community.”
      <strong>– Bashiru, Farmer (Wa)</strong>
    </div>

    <div class="testimonial">
      “Working as an agent with Agro Loan has been rewarding. I help farmers grow while earning commissions fairly.”
      <strong>– Kwesi, Agent (Kumasi)</strong>
    </div>
    <div class="testimonial">
      “As an agent, I’ve connected dozens of farmers to life-changing funding. Agro Loan’s digital platform makes the whole process smooth and transparent.”
    <strong>– Efua, Agent (Cape Coast)</strong>
    </div>

    <div class="testimonial">
      “I earn commissions while helping farmers get access to credit. It’s a win-win partnership that’s empowering local agriculture.”
      <strong>– Daniel, Agent (Techiman)</strong>
    </div>

    <div class="testimonial">
      “The training and support Agro Loan provides to agents is top-notch. I feel confident helping farmers with their applications and follow-ups.”
      <strong>– Stephen, Agent (Bolgatanga)</strong>
    </div>

    <div class="testimonial">
      “We’re proud to collaborate with Agro Loan to support smallholder farmers with affordable financing and financial education.”
      <strong>– Nana Akua, Regional Manager, AgriFund</strong>
    </div>

    <div class="testimonial">
      “Agro Loan’s innovative approach is bridging the gap between finance and agriculture in rural communities.”
      <strong>– Kwame Mensah, Partnerships Lead, EcoBank Ghana</strong>
    </div>
  </div>
</section>

<section class="partners">
    <h2>Our Trusted Partners 🤝</h2>
    <div class="partner-logos">
        <img src="../assets/images/ecobank.png" alt="EcoBank">
        <img src="../assets/images/agrifund.png" alt="AgriFund">
        <img src="../assets/images/agrotech.png" alt="Ghana AgroTech">
        <img src="../assets/images/adb.png" alt="Agric Development Bank">
    </div>
</section>

<footer>
    <p>© <?= date('Y'); ?> Agro Loan. All Rights Reserved. | <a href="contact.php">Contact Us</a></p>
</footer>

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

// Testimonial Rotator
document.addEventListener('DOMContentLoaded', () => {
  const testimonials = Array.from(document.querySelectorAll('#testimonial-wrapper .testimonial'));
  if (!testimonials.length) return;
  let idx = 0;
  const showDuration = 4000;
  const transitionTime = 800;

  function show(index) {
    testimonials.forEach((t, i) => t.classList.toggle('active', i === index));
  }

  setInterval(() => {
    idx = (idx + 1) % testimonials.length;
    show(idx);
  }, showDuration + transitionTime);
});
</script>
</body>
</html>
