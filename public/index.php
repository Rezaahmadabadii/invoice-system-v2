<?php
/**
 * نقطه ورود اصلی برنامه
 */

// بارگذاری مستقیم توابع کمکی برای اطمینان
require_once __DIR__ . '/../app/Helpers/functions.php';

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Router;
use App\Core\Session;

// شروع سشن
Session::start();

// دریافت URL از آدرس
$url = isset($_GET['url']) ? $_GET['url'] : '/';

// بارگذاری مسیرها
$router = require base_path('routes/web.php');

// اجرای مسیر
$router->dispatch($url);