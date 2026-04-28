<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$page_title = 'گزارش مالیات';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">🏛️ گزارش مالیات (سامانه مودیان)</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<div style="background: white; border-radius: 10px; padding: 40px; text-align: center;">
    <i class="fas fa-chart-line" style="font-size: 64px; color: #3498db; margin-bottom: 20px; display: block;"></i>
    <h3 style="color: #2c3e50;">در حال توسعه</h3>
    <p style="color: #7f8c8d; margin-top: 10px;">گزارش مالیات به زودی اضافه خواهد شد.</p>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>