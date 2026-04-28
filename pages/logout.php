<?php
session_start();

// پاک کردن نشست
$_SESSION = array();

// حذف کوکی نشست
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// نابودی نشست
session_destroy();

// هدایت به صفحه ورود با آدرس کامل
header('Location: /invoice-system-v2/pages/login.php');
exit;
?>