<?php
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Agro Loan App</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
  <header class="navbar">
    <h2>Agro Loan</h2>
    <nav>
      <a href="logout.php">Logout</a>
    </nav>
  </header>
  <main class="container">
