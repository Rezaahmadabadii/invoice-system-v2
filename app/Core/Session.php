<?php
namespace App\Core;

class Session
{
    /**
     * شروع سشن
     */
    public static function start()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // تنظیم نام سشن
            $session_name = self::getSessionName();
            session_name($session_name);
            
            // تنظیم پارامترهای کوکی سشن
            self::setSessionParams();
            
            // شروع سشن
            session_start();
            
            // بررسی انقضای سشن
            self::checkExpiration();
        }
    }

    /**
     * دریافت نام سشن
     */
    private static function getSessionName()
    {
        // اول از ثابت استفاده کن، بعد از env، بعد مقدار پیش‌فرض
        if (defined('SESSION_NAME')) {
            return SESSION_NAME;
        }
        
        if (function_exists('env')) {
            return env('SESSION_NAME', 'invoice_session');
        }
        
        return 'invoice_session';
    }

    /**
     * تنظیم پارامترهای کوکی سشن
     */
    private static function setSessionParams()
    {
        $lifetime = 0; // تا بسته شدن مرورگر
        
        if (function_exists('env')) {
            $lifetime = (int) env('SESSION_LIFETIME', 0);
        }
        
        $params = [
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        
        session_set_cookie_params($params);
    }

    /**
     * بررسی انقضای سشن
     */
    private static function checkExpiration()
    {
        $timeout = 3600; // 1 ساعت پیش‌فرض
        
        if (function_exists('env')) {
            $timeout = (int) env('SESSION_TIMEOUT', 3600);
        }
        
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::destroy();
            self::start();
        }
        
        $_SESSION['last_activity'] = time();
    }

    /**
     * تنظیم مقدار در سشن
     */
    public static function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    /**
     * دریافت مقدار از سشن
     */
    public static function get($key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * بررسی وجود کلید در سشن
     */
    public static function has($key)
    {
        return isset($_SESSION[$key]);
    }

    /**
     * حذف یک کلید از سشن
     */
    public static function remove($key)
    {
        unset($_SESSION[$key]);
    }

    /**
     * پاک کردن کامل سشن
     */
    public static function destroy()
    {
        if (session_status() !== PHP_SESSION_NONE) {
            $_SESSION = [];
            
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
            
            session_destroy();
        }
    }

    /**
     * تنظیم پیام فلش (یکبار مصرف)
     */
    public static function setFlash($key, $message)
    {
        $_SESSION['_flash'][$key] = $message;
    }

    /**
     * دریافت پیام فلش
     */
    public static function getFlash($key)
    {
        if (isset($_SESSION['_flash'][$key])) {
            $message = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $message;
        }
        return null;
    }

    /**
     * دریافت همه پیام‌های فلش
     */
    public static function getAllFlash()
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    /**
     * بازسازی ID سشن (برای امنیت بیشتر)
     */
    public static function regenerate()
    {
        if (session_status() !== PHP_SESSION_NONE) {
            session_regenerate_id(true);
        }
    }

    /**
     * دریافت ID سشن
     */
    public static function getId()
    {
        return session_id();
    }
}