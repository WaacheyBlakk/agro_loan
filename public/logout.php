<?php
require_once __DIR__ . '/../src/security_headers.php';
// public/logout.php
session_start();
session_unset();
session_destroy();

header("Location: login.php");
exit;
