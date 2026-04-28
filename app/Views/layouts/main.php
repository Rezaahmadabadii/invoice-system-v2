<?php
use App\Core\Session;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'سیستم مدیریت فاکتورها'; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/css/main.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/css/auth.css">
</head>
<body>
    <div class="wrapper">
        <!-- هدر -->
        <header class="header">
            <div class="container">
                <h1 class="logo">سیستم مدیریت فاکتورها</h1>
                <nav class="nav">
                    <a href="<?php echo BASE_URL; ?>/">خانه</a>
                    <?php if (Session::has('user_id')): ?>
                        <a href="<?php echo BASE_URL; ?>/dashboard">داشبورد</a>
                        <a href="<?php echo BASE_URL; ?>/profile">پروفایل</a>
                        <a href="<?php echo BASE_URL; ?>/logout">خروج</a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>/login.php">ورود</a>
                        <a href="<?php echo BASE_URL; ?>/register.php">ثبت نام</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <!-- محتوای اصلی -->
        <main class="main">
            <div class="container">
                <?php echo $content; ?>
            </div>
        </main>

        <!-- فوتر -->
        <footer class="footer">
            <div class="container">
                <p>تمامی حقوق محفوظ است &copy; <?php echo date('Y'); ?></p>
            </div>
        </footer>
    </div>

    <script src="<?php echo BASE_URL; ?>/js/main.js"></script>
</body>
</html>