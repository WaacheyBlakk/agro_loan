<?php
session_start();
require_once __DIR__ . '/../src/db.php'; // adjust if db.php is elsewhere

$success = false;
$error = '';

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Option 1: Save to DB 
    if ($name && $email && $subject && $message) {
        try {
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $email, $subject, $message]);
            $success = true;
        } catch (Exception $e) {
            $error = "Error saving your message. Please try again later.";
        }
    } else {
        $error = "Please fill in all fields.";
    }

    // Option 2: Send an email
    if ($name && $email && $message) {
        $to = "youremail@example.com"; // Replace with admin email
        $subject = "New Contact Form Message from Agro Loan Website";
        $body = "Name: $name\nEmail: $email\nMessage:\n$message";
        $headers = "From: $email";

        // Send mail and set success flag
        /*if (mail($to, $subject, $body, $headers)) {
            $success = true;
        } */
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Contact Us | Agro Loan</title>
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

/* CONTACT FORM SECTION */
main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 60px 20px;
}
.contact-box {
    background: #fff;
    padding: 35px 30px;
    border-radius: 15px;
    width: 400px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    box-sizing: border-box;
}
body.dark .contact-box {
    background: #1f1f1f;
}
h2 {
    text-align: center;
    color: var(--primary);
    margin-bottom: 25px;
}
input, textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 14px;
    margin: 10px 0;
    border: 1px solid #ccc;
    border-radius: 8px;
    font-size: 15px;
    background: #fafafa;
}
textarea {
    resize: none;
    height: 100px;
}
input:focus, textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 6px rgba(46,125,50,0.3);
}
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
.error {
    color: red;
    text-align: center;
    margin-top: 12px;
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
    <a href="contact.php" style="color:#c8e6c9;">Contact</a>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">🌙</button>
  </nav>
</header>

<main>
  <div class="contact-box">
      <h2>Contact Us</h2>

      <?php if ($error): ?>
          <p class="error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form method="POST">
          <input type="text" name="name" placeholder="Your Name" required>
          <input type="email" name="email" placeholder="Your Email" required>
          <input type="text" name="subject" placeholder="Subject" required>
          <textarea name="message" placeholder="Your Message" required></textarea>
          <button type="submit">Send Message</button>
      </form>
  </div>
</main>

<footer>
  <p>© <?= date('Y'); ?> Agro Loan. All Rights Reserved. </p>
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

// ✅ SweetAlert messages
<?php if ($success): ?>
Swal.fire({
    icon: 'success',
    title: '✅ Message Sent!',
    text: 'Thank you for contacting us. We will get back to you soon.',
    showConfirmButton: false,
    timer: 2500
});
<?php endif; ?>
</script>

</body>
</html>
