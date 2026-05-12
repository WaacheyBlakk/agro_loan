<aside class="sidebar" id="adminSidebar">
    <div class="brand">
        <img src="../assets/images/logo.jpg" alt="Agro Loan Logo" class="logo">
        <h2>AgroLoan Admin</h2>
    </div>

    <nav class="nav">
        <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
            <span class="icon">📊</span>
            <span class="label">Dashboard</span>
        </a>

        <a href="admin_verifications.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_verifications.php' ? 'active' : '' ?>">
            <span class="icon">📝</span>
            <span class="label">Verification Center</span>
        </a>

        <a href="admin_profile.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_profile.php' ? 'active' : '' ?>">
            <span class="icon">⚙️</span>
            <span class="label">Profile</span>
        </a>
    </nav>

    <div class="spacer"></div>

    <form action="logout.php" method="POST">
        <button class="logout-btn">
            <span class="icon">🚪</span>
            <span class="label">Logout</span>
        </button>
    </form>
</aside>
