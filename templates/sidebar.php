<div class="sidebar">
    <div class="sidebar-header">
        <h2>سیستم مدیریت فاکتورها</h2>
    </div>
    <ul class="menu">
        <li><a href="dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>📊 داشبورد</a></li>
        <li><a href="invoices.php" <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'class="active"' : ''; ?>>📋 فاکتورها</a></li>
        <li><a href="customers.php" <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'class="active"' : ''; ?>>👥 مشتریان</a></li>
        <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <li><a href="users.php" <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'class="active"' : ''; ?>>👤 کاربران</a></li>
            <li><a href="settings.php" <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'class="active"' : ''; ?>>⚙️ تنظیمات</a></li>
        <?php endif; ?>
        <li><a href="reports.php" <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'class="active"' : ''; ?>>📈 گزارش‌ها</a></li>
        <li><a href="profile.php" <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'class="active"' : ''; ?>>👤 پروفایل</a></li>
        <li><a href="logout.php">🚪 خروج</a></li>
    </ul>
</div>