<?php
// src/security_headers.php
//
// USAGE: require_once this as the very first line of every public/*.php
// page (before any HTML/output and BEFORE session_start()), e.g.:
//   require_once __DIR__ . '/../src/security_headers.php';
//   session_start();

// --- Session cookie hardening ---
// Must run before session_start() is called by the page.
// SameSite=Lax also gives free CSRF protection on the AJAX/JSON endpoints
// (cart_add.php, wishlist_*.php, confirm_delivery.php, etc.) that can't
// easily carry a csrf_token field, since cross-site POSTs won't include
// this cookie at all.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Prevent the site from being framed by another site (clickjacking).
header("X-Frame-Options: DENY");

// Stop browsers from MIME-sniffing responses away from the declared content-type.
header("X-Content-Type-Options: nosniff");

// Don't leak the full referring URL to third parties.
header("Referrer-Policy: strict-origin-when-cross-origin");

// Restrict where scripts/styles/fonts can load from.
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "img-src 'self' data: https:; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
    "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; " .
    "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com cdn.jsdelivr.net unpkg.com; " .
    "frame-ancestors 'none';"
);

// Only send this once you're fully running over HTTPS in production,
// otherwise it can lock users out of an http:// staging site.
/*
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header("Strict-Transport-Security: max-age=63072000; includeSubDomains");
}
*/