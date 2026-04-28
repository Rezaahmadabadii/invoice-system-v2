<?php
/**
 * ثابت‌های سراسری سیستم
 */

// مسیرها
define('DS', DIRECTORY_SEPARATOR);
define('BASE_PATH', dirname(__DIR__, 2) . DS);
define('APP_PATH', BASE_PATH . 'app' . DS);
define('CONFIG_PATH', BASE_PATH . 'config' . DS);
define('PUBLIC_PATH', BASE_PATH . 'public' . DS);
define('STORAGE_PATH', BASE_PATH . 'storage' . DS);

// آدرس پایه سایت (مهم)
define('BASE_URL', 'http://localhost/invoice-system-v2');

// نقش‌های کاربری
define('ROLE_ADMIN', 'admin');
define('ROLE_SUPERVISOR', 'supervisor');
define('ROLE_FINANCE', 'finance');
define('ROLE_USER', 'user');

// وضعیت فاکتور
define('INVOICE_STATUS_DRAFT', 'draft');
define('INVOICE_STATUS_PENDING', 'pending');
define('INVOICE_STATUS_APPROVED', 'approved');
define('INVOICE_STATUS_REJECTED', 'rejected');
define('INVOICE_STATUS_PAID', 'paid');

// تنظیمات امنیتی
define('PASSWORD_MIN_LENGTH', 6);
define('SESSION_TIMEOUT', 3600);
define('MAX_LOGIN_ATTEMPTS', 5);