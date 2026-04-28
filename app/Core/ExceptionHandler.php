<?php
namespace App\Core;

class ExceptionHandler
{
    /**
     * مدیریت خطاها
     */
    public static function handle($exception)
    {
        // لاگ کردن خطا
        error_log($exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        
        // در محیط توسعه، خطا را نمایش بده
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo "<h1>خطا</h1>";
            echo "<p><strong>پیام:</strong> " . $exception->getMessage() . "</p>";
            echo "<p><strong>فایل:</strong> " . $exception->getFile() . "</p>";
            echo "<p><strong>خط:</strong> " . $exception->getLine() . "</p>";
            echo "<pre>" . $exception->getTraceAsString() . "</pre>";
            exit;
        }
        
        // در محیط تولید، صفحه خطا نشان بده
        http_response_code(500);
        
        // اگر درخواست AJAX بود
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => 'خطای داخلی سرور رخ داده است.'
            ]);
            exit;
        }
        
        // صفحه خطای ۵۰۰ را نشان بده
        $errorView = dirname(__DIR__) . '/Views/errors/500.php';
        if (file_exists($errorView)) {
            require_once $errorView;
        } else {
            echo "<h1>خطای ۵۰۰ - خطای داخلی سرور</h1>";
        }
        exit;
    }
}