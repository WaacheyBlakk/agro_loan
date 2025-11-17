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
    padding: 20px 25px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: background 0.5s;
}
body.dark .container {
    background: #1e1e1e;
}
h2 {
    text-align: center;
    color: var(--primary);
    margin-bottom: 25px;
}
.about-content {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 30px;
}
.about-content img {
    width: 45%;
    border-radius: 15px;
    box-shadow: 0 5px 10px rgba(0,0,0,0.1);
}
.about-text {
    flex: 1;
}
.about-text p {
    line-height: 1.8;
    margin-bottom: 15px;
}
.mission {
    background: #e8f5e9;
    padding: 30px;
    border-radius: 10px;
    margin-top: 40px;
}
body.dark .mission {
    background: #2a2a2a;
}
.mission h3 {
    color: var(--primary);
}
/* Team Section */
.team {
    text-align: center;
    margin-top: 60px;
}
.team h2 {
    color: var(--primary);
    margin-bottom: 30px;
}
.team-members {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 30px;
}
.member {
    background: #f1f8e9;
    border-radius: 15px;
    width: 250px;
    padding: 20px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}
body.dark .member {
    background: #2b2b2b;
}
.member:hover {
    transform: translateY(-8px);
    box-shadow: 0 8px 18px rgba(0,0,0,0.2);
}
.member img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 50%;
    margin-bottom: 15px;
}
.member h4 {
    margin: 10px 0 5px;
    color: var(--primary);
}
.member p {
    font-size: 14px;
    color: #555;
    margin: 0;
}
body.dark .member p {
    color: #ccc;
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
@media (max-width: 768px) {
    .about-content {
        flex-direction: column;
    }
    .about-content img {
        width: 100%;
    }
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
    <a href="about.php" style="color:#c8e6c9;">About</a>
    <a href="services.php">Services</a>
    <a href="login.php">Login</a>
    <a href="register.php">Register</a>
    <a href="contact.php">Contact</a>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
  </nav>
</header>

<div class="container">
  <h2>About Agro Loan</h2>
  <div class="about-content">
    <img src="../assets/images/farmers.jpg" alt="Farmers working together">
    <div class="about-text">
      <p>
        Agro Loan was founded with a simple but powerful vision to empower farmers across Africa with easy access to financial support, helping them grow their businesses, improve productivity, and ensure food security.
      </p>
      <p>
        We understand that many smallholder farmers face challenges in accessing affordable credit. Agro Loan bridges this gap by connecting farmers with trusted agents and financial partners who share our belief in sustainable agricultural development.
      </p>
    </div>
  </div>

  <div class="mission">
    <h3>Our Mission</h3>
    <p>To promote agricultural growth by providing farmers with financial solutions, training, and technological tools to increase productivity and sustainability.</p>

    <h3>Our Vision</h3>
    <p>A world where every farmer, no matter how small, has the opportunity to thrive and contribute to global food security.</p>
  </div>

  <section class="team">
    <h2>Meet Our Team</h2>
    <div class="team-members">
      <div class="member">
        <img src="../assets/images/team1.jpg" alt="Founder">
        <h4>Abdul-Rahaman Salifu</h4>
        <p>Founder & CEO</p>
        <p>Visionary leader with a passion for empowering farmers through technology and finance.</p>
      </div>
      <div class="member">
        <img src="../assets/images/team2.jpg" alt="Co-founder">
        <h4>Grace Mensah</h4>
        <p>Co-Founder & Operations Lead</p>
        <p>Ensures seamless farmer-agent operations and drives the platform’s growth strategy.</p>
      </div>
      <div class="member">
        <img src="../assets/images/team3.jpg" alt="Technical Director">
        <h4>Michael Owusu</h4>
        <p>Technical Director</p>
        <p>Heads system development and data security for all Agro Loan applications.</p>
      </div>
      <div class="member">
        <img src="../assets/images/team4.jpg" alt="Financial Analyst">
        <h4>Linda Addo</h4>
        <p>Financial Analyst</p>
        <p>Specializes in agricultural finance, helping tailor affordable loan packages for farmers.</p>
      </div>
    </div>
  </section>
</div>

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
</script>

</body>
</html>
