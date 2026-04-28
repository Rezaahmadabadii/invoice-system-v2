<?php
namespace App\Services;

use App\Models\User;
use App\Core\Session;
use App\Core\Database;

class AuthService
{
    /**
     * ورود کاربر
     */
    public function login($username, $password)
    {
        // پیدا کردن کاربر
        $user = User::findByUsername($username);

        if (!$user) {
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است'];
        }

        // بررسی رمز عبور
        if (!password_verify($password, $user->password)) {
            $this->logActivity(0, 'failed_login', 'تلاش ناموفق برای ورود با نام کاربری: ' . $username);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است'];
        }

        // ذخیره در سشن
        Session::set('user_id', $user->id);
        Session::set('username', $user->username);
        Session::set('user_role', $user->role);
        Session::set('user_name', $user->full_name ?? $user->username);
        Session::set('user_avatar', $user->avatar ?? null);

        // به‌روزرسانی آخرین ورود
        $user->updateLastLogin($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        // ثبت فعالیت
        $this->logActivity($user->id, 'login', 'ورود به سیستم');

        return ['success' => true, 'user' => $user];
    }

    /**
     * ثبت‌نام کاربر جدید
     */
    public function register($data)
    {
        // اعتبارسنجی
        if (strlen($data['password']) < 6) {
            return ['success' => false, 'message' => 'رمز عبور باید حداقل ۶ کاراکتر باشد'];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'ایمیل معتبر نیست'];
        }

        // بررسی تکراری بودن نام کاربری
        $existing = Database::fetch("SELECT id FROM users WHERE username = ?", [$data['username']]);
        if ($existing) {
            return ['success' => false, 'message' => 'این نام کاربری قبلاً ثبت شده است'];
        }

        // بررسی تکراری بودن ایمیل
        $existing = Database::fetch("SELECT id FROM users WHERE email = ?", [$data['email']]);
        if ($existing) {
            return ['success' => false, 'message' => 'این ایمیل قبلاً ثبت شده است'];
        }

        // هش کردن رمز عبور
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        // درج کاربر جدید
        $userId = Database::insert('users', [
            'username' => $data['username'],
            'password' => $hashedPassword,
            'email' => $data['email'],
            'full_name' => $data['full_name'] ?? '',
            'role' => 'user',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if ($userId) {
            $this->logActivity($userId, 'register', 'ثبت نام در سیستم');
            return ['success' => true, 'user_id' => $userId];
        }

        return ['success' => false, 'message' => 'خطا در ثبت نام'];
    }

    /**
     * خروج از سیستم
     */
    public function logout()
    {
        if (Session::has('user_id')) {
            $this->logActivity(Session::get('user_id'), 'logout', 'خروج از سیستم');
        }
        
        Session::destroy();
    }

    /**
     * تغییر رمز عبور
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        $user = User::find($userId);
        
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }
        
        if (!password_verify($currentPassword, $user->password)) {
            $this->logActivity($userId, 'failed_change_password', 'تلاش ناموفق برای تغییر رمز عبور');
            return ['success' => false, 'message' => 'رمز عبور فعلی اشتباه است'];
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        Database::update('users', 
            ['password' => $hashedPassword], 
            'id = ?', 
            [$userId]
        );
        
        $this->logActivity($userId, 'change_password', 'تغییر رمز عبور');
        
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد'];
    }

    /**
     * فراموشی رمز عبور - ارسال ایمیل بازیابی
     */
    public function forgotPassword($email)
    {
        $user = Database::fetch("SELECT id, username, email FROM users WHERE email = ?", [$email]);
        
        if (!$user) {
            // برای امنیت، همین پیام رو برمی‌گردونیم حتی اگر ایمیل وجود نداشته باشه
            return ['success' => true, 'message' => 'لینک بازیابی رمز عبور به ایمیل شما ارسال شد'];
        }
        
        // ایجاد توکن یکتا
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // ذخیره توکن در دیتابیس
        Database::insert('password_resets', [
            'user_id' => $user['id'],
            'token' => $token,
            'expires' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // ارسال ایمیل (در نسخه بعدی)
        // $this->sendResetEmail($user['email'], $token);
        
        $this->logActivity($user['id'], 'forgot_password', 'درخواست بازیابی رمز عبور');
        
        return ['success' => true, 'message' => 'لینک بازیابی رمز عبور به ایمیل شما ارسال شد'];
    }

    /**
     * بررسی توکن بازیابی رمز
     */
    public function validateResetToken($token)
    {
        $reset = Database::fetch(
            "SELECT * FROM password_resets WHERE token = ? AND expires > NOW()", 
            [$token]
        );
        
        if ($reset) {
            return User::find($reset['user_id']);
        }
        
        return null;
    }

    /**
     * بازنشانی رمز عبور با توکن
     */
    public function resetPassword($token, $newPassword)
    {
        $reset = Database::fetch(
            "SELECT * FROM password_resets WHERE token = ? AND expires > NOW()", 
            [$token]
        );
        
        if (!$reset) {
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است'];
        }
        
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        Database::update('users', 
            ['password' => $hashedPassword], 
            'id = ?', 
            [$reset['user_id']]
        );
        
        // حذف توکن‌های استفاده شده
        Database::delete('password_resets', 'user_id = ?', [$reset['user_id']]);
        
        $this->logActivity($reset['user_id'], 'reset_password', 'بازیابی رمز عبور');
        
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد'];
    }

    /**
     * بررسی "مرا به خاطر بسپار"
     */
    public function checkRememberMe()
    {
        if (!Session::has('user_id') && isset($_COOKIE['remember_token'])) {
            $token = $_COOKIE['remember_token'];
            
            $user = Database::fetch(
                "SELECT * FROM users WHERE remember_token = ?", 
                [$token]
            );
            
            if ($user) {
                Session::set('user_id', $user['id']);
                Session::set('username', $user['username']);
                Session::set('user_role', $user['role']);
                Session::set('user_name', $user['full_name'] ?? $user['username']);
                Session::set('user_avatar', $user['avatar'] ?? null);
                
                $this->logActivity($user['id'], 'login', 'ورود با مرا به خاطر بسپار');
                return true;
            }
        }
        return false;
    }

    /**
     * تنظیم توکن "مرا به خاطر بسپار"
     */
    public function setRememberMe($userId)
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 24 * 60 * 60); // 30 روز
        
        Database::update('users', 
            ['remember_token' => $token], 
            'id = ?', 
            [$userId]
        );
        
        setcookie('remember_token', $token, $expires, '/', '', false, true);
    }

    /**
     * پاک کردن توکن "مرا به خاطر بسپار"
     */
    public function clearRememberMe($userId)
    {
        Database::update('users', 
            ['remember_token' => null], 
            'id = ?', 
            [$userId]
        );
        
        setcookie('remember_token', '', time() - 3600, '/');
    }

    /**
     * ثبت فعالیت
     */
    protected function logActivity($userId, $action, $description)
    {
        try {
            // ایجاد جدول activities اگر وجود نداشته باشد
            $this->ensureActivitiesTable();
            
            Database::insert('activities', [
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // خطا را لاگ کن اما برنامه رو متوقف نکن
            error_log("Error logging activity: " . $e->getMessage());
        }
    }

    /**
     * اطمینان از وجود جدول activities
     */
    private function ensureActivitiesTable()
    {
        try {
            Database::query("SELECT 1 FROM activities LIMIT 1");
        } catch (\Exception $e) {
            // جدول وجود ندارد، آن را ایجاد کن
            $sql = "CREATE TABLE IF NOT EXISTS activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(100) NOT NULL,
                description TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME,
                INDEX idx_user (user_id),
                INDEX idx_action (action),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;";
            
            Database::query($sql);
        }
    }

    /**
     * دریافت فعالیت‌های اخیر کاربر
     */
    public function getUserActivities($userId, $limit = 10)
    {
        try {
            return Database::fetchAll(
                "SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
                [$userId, $limit]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * دریافت آمار کاربر
     */
    public function getUserStats($userId)
    {
        try {
            $stats = [];
            
            // تعداد لاگین‌ها
            $stats['login_count'] = Database::fetch(
                "SELECT COUNT(*) as count FROM activities WHERE user_id = ? AND action = 'login'",
                [$userId]
            )['count'] ?? 0;
            
            // آخرین فعالیت
            $stats['last_activity'] = Database::fetch(
                "SELECT created_at FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
                [$userId]
            )['created_at'] ?? null;
            
            return $stats;
        } catch (\Exception $e) {
            return ['login_count' => 0, 'last_activity' => null];
        }
    }
}