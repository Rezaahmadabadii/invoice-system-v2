<?php
echo "<h1>تست نهایی</h1>";

// مسیرهای دقیق
$functions_path = __DIR__ . '/app/Helpers/functions.php';
$library_path = __DIR__ . '/vendor/sallar/jdatetime/jdatetime.class.php';

echo "<h3>بررسی مسیرها:</h3>";
echo "مسیر functions: " . $functions_path . "<br>";
echo "وجود دارد؟ " . (file_exists($functions_path) ? '✅ بله' : '❌ خیر') . "<br><br>";

echo "مسیر کتابخانه: " . $library_path . "<br>";
echo "وجود دارد؟ " . (file_exists($library_path) ? '✅ بله' : '❌ خیر') . "<br><br>";

// لود کتابخانه
if (file_exists($library_path)) {
    require_once $library_path;
    echo "✅ کتابخانه لود شد.<br>";
    echo "کلاس jDateTime وجود دارد؟ " . (class_exists('jDateTime') ? '✅ بله' : '❌ خیر') . "<br><br>";
}

// لود functions
if (file_exists($functions_path)) {
    require_once $functions_path;
    echo "✅ functions.php لود شد.<br>";
    echo "تابع jdate وجود دارد؟ " . (function_exists('jdate') ? '✅ بله' : '❌ خیر') . "<br><br>";
}

// تست نهایی
if (function_exists('jdate')) {
    echo "✅ تاریخ شمسی: " . jdate('Y/m/d H:i');
} else {
    echo "❌ تابع jdate وجود ندارد!";
}