<?php
// autoloader ساده

spl_autoload_register(function ($class) {
    // برای کتابخانه jdatetime
    if ($class === 'jDateTime') {
        $file = __DIR__ . '/sallar/jdatetime/jdatetime.class.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    // برای namespace پروژه
    if (strpos($class, 'App\\') === 0) {
        $class = str_replace('App\\', '', $class);
        $file = __DIR__ . '/../app/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
});

// بارگذاری توابع کمکی
if (file_exists(__DIR__ . '/../app/Helpers/functions.php')) {
    require_once __DIR__ . '/../app/Helpers/functions.php';
}