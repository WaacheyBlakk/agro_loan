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
.container {
    max-width: 1100px;
    margin: 60px auto;
    padding: 20px;
}
h2 {
    text-align: center;
    color: var(--primary);
    margin-bottom: 40px;
}
.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
}
.service-card {
    background: white;
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    box-shadow: 0 6px 15px rgba(0,0,0,0.1);
    opacity: 0;
    transform: translateY(40px);
    transition: opacity 0.8s ease, transform 0.8s ease;
}
.service-card.visible {
    opacity: 1;
    transform: translateY(0);
}
body.dark .service-card {
    background: #1f1f1f;
}
.service-card:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 0 12px 20px rgba(0,0,0,0.2);
}
.service-card i {
    font-size: 40px;
    color: var(--primary);
    margin-bottom: 15px;
}
.service-card h3 {
    color: var(--primary);
    margin-bottom: 10px;
}
.service-card p {
    line-height: 1.7;
    font-size: 15px;
    color: #555;
}
body.dark .service-card p {
    color: #ccc;
}
.cta {
    text-align: center;
    margin-top: 50px;
}
.cta a {
    display: inline-block;
    background: var(--primary);
    color: white;
    padding: 12px 28px;
    border-radius: 30px;
    font-weight: bold;
    text-decoration: none;
    transition: 0.3s;
}
.cta a:hover {
    background: var(--accent);
}
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
    <a href="services.php" style="color:#c8e6c9;">Services</a>
    <a href="login.php">Login</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact</a>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
  </nav>
</header>

<div class="container">
  <h2>Our Services</h2>

  <div class="services-grid">
    <div class="service-card">
      <i>💰</i>
      <h3>Farmer Loans</h3>
      <p>
        We provide accessible and low-interest loans tailored for farmers. Our system ensures quick approval and transparent repayment terms to help you invest in seeds, equipment, and farm expansion.
      </p>
    </div>

    <div class="service-card">
      <i>🤝</i>
      <h3>Agent Partnership</h3>
      <p>
        Become an Agro Loan agent and connect local farmers with financial opportunities. We equip agents with digital tools and real-time dashboards to streamline applications and support farmers effectively.
      </p>
    </div>

    <div class="service-card">
      <i>📱</i>
      <h3>Digital Tools</h3>
      <p>
        Our platform integrates modern technology from mobile tracking to AI-based credit scoring to simplify loan access and ensure financial inclusion for farmers in rural areas.
      </p>
    </div>

    <div class="service-card">
      <i>🌾</i>
      <h3>Training & Support</h3>
      <p>
        We offer capacity-building workshops and digital resources to improve financial literacy, sustainable farming, and record-keeping among our farmer communities.
      </p>
    </div>

    <div class="service-card">
      <i>📊</i>
      <h3>Data Insights</h3>
      <p>
        Agro Loan’s data-driven reports help monitor farm productivity, analyze loan impact, and provide actionable insights to improve operations and funding.
      </p>
    </div>

    <div class="service-card">
      <i>🌍</i>
      <h3>Community Growth</h3>
      <p>
        Beyond loans, we foster strong farmer networks promoting collective growth, sustainability, and shared agricultural success across regions.
      </p>
    </div>
  </div>

  <div class="cta">
    <h3>Ready to grow your farm?</h3>
    <a href="register.php">Get Started Today</a>
  </div>
</div>

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

// 🌾 Scroll Animation
const cards = document.querySelectorAll('.service-card');
const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.2 });

cards.forEach(card => observer.observe(card));
</script>

</body>
</html>
