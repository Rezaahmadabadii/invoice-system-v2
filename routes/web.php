<?php
// صفحه اصلی
$url = $_SERVER['REQUEST_URI'];

if ($url == '/invoice-system-v2/' || $url == '/invoice-system-v2') {
    echo '<!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>سیستم مدیریت فاکتورها</title>
    </head>
    <body>
        <h1>سیستم مدیریت فاکتورها</h1>
        <p>
            <a href="login.php">ورود</a> | 
            <a href="register.php">ثبت نام</a>
        </p>
    </body>
    </html>';
    exit;
}

// برگردوندن یه آرایه خالی به جای آبجکت
return [];