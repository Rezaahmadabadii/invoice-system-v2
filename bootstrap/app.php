<?php
/**
 * بوت‌استرپ برنامه
 */

require_once __DIR__ . '/../vendor/autoload.php';

// تعریف ثابت‌های مسیر
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . 'app' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . 'config' . DIRECTORY_SEPARATOR);
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . 'storage' . DIRECTORY_SEPARATOR);
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . 'public' . DIRECTORY_SEPARATOR);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/invoice-system-v2');
}

// تنظیمات منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// تنظیمات خطاها
error_reporting(E_ALL);
ini_set('display_errors', 1);

// بارگذاری مستقیم توابع کمکی
if (file_exists(APP_PATH . 'Helpers/functions.php')) {
    require_once APP_PATH . 'Helpers/functions.php';
} else {
    die("خطا: فایل functions.php در مسیر " . APP_PATH . "Helpers/functions.php یافت نشد");
}