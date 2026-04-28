<?php
namespace App\Controllers;

use App\Core\Session;
use App\Core\Database;
use App\Models\User;

class ProfileController
{
    public function __construct()
    {
        // بررسی لاگین بودن کاربر
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * نمایش پروفایل کاربر
     */
    public function index()
    {
        $userId = Session::get('user_id');
        $user = User::find($userId);
        
        if (!$user) {
            Session::setFlash('error', 'کاربر یافت نشد');
            header('Location: /dashboard');
            return;
        }
        
        require_once app_path('Views/profile/index.php');
    }

    /**
     * ویرایش پروفایل
     */
    public function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /profile');
            return;
        }

        $userId = Session::get('user_id');
        $user = User::find($userId);

        if (!$user) {
            Session::setFlash('error', 'کاربر یافت نشد');
            header('Location: /profile');
            return;
        }

        // اعتبارسنجی
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            Session::setFlash('error', 'ایمیل نمی‌تواند خالی باشد');
            header('Location: /profile');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::setFlash('error', 'ایمیل معتبر نیست');
            header('Location: /profile');
            return;
        }

        // بررسی تکراری نبودن ایمیل
        $existingUser = Database::fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId]);
        if ($existingUser) {
            Session::setFlash('error', 'این ایمیل قبلاً توسط کاربر دیگری استفاده شده است');
            header('Location: /profile');
            return;
        }

        // به‌روزرسانی اطلاعات
        $user->full_name = $fullName;
        $user->email = $email;
        
        // آپلود تصویر اگر وجود داشته باشد
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadAvatar($_FILES['avatar'], $userId);
            if ($uploadResult['success']) {
                $user->avatar = $uploadResult['filename'];
            } else {
                Session::setFlash('error', $uploadResult['message']);
                header('Location: /profile');
                return;
            }
        }

        $user->save();

        // به‌روزرسانی نام در سشن
        Session::set('user_name', $user->full_name ?? $user->username);
        Session::set('user_avatar', $user->avatar);

        Session::setFlash('success', 'پروفایل با موفقیت به‌روزرسانی شد');
        header('Location: /profile');
    }

    /**
     * تغییر رمز عبور
     */
    public function changePassword()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /profile');
            return;
        }

        $userId = Session::get('user_id');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // اعتبارسنجی
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            Session::setFlash('error', 'تمام فیلدها را پر کنید');
            header('Location: /profile');
            return;
        }

        if ($newPassword !== $confirmPassword) {
            Session::setFlash('error', 'رمز عبور جدید و تکرار آن مطابقت ندارند');
            header('Location: /profile');
            return;
        }

        if (strlen($newPassword) < 6) {
            Session::setFlash('error', 'رمز عبور باید حداقل ۶ کاراکتر باشد');
            header('Location: /profile');
            return;
        }

        // بررسی رمز فعلی
        $user = User::find($userId);
        if (!password_verify($currentPassword, $user->password)) {
            Session::setFlash('error', 'رمز عبور فعلی اشتباه است');
            header('Location: /profile');
            return;
        }

        // تغییر رمز عبور
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::update('users', 
            ['password' => $hashedPassword], 
            'id = ?', 
            [$userId]
        );

        Session::setFlash('success', 'رمز عبور با موفقیت تغییر کرد');
        header('Location: /profile');
    }

    /**
     * آپلود تصویر پروفایل
     */
    private function uploadAvatar($file, $userId)
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        // بررسی نوع فایل
        if (!in_array($file['type'], $allowedTypes)) {
            return ['success' => false, 'message' => 'فرمت فایل باید jpeg, png یا gif باشد'];
        }

        // بررسی حجم فایل
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'message' => 'حجم فایل باید کمتر از ۲ مگابایت باشد'];
        }

        // ایجاد پوشه اگر وجود نداشته باشد
        $uploadDir = public_path('uploads/avatars/');
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // ایجاد نام یکتا برای فایل
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        // آپلود فایل
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // حذف تصویر قبلی اگر وجود داشته باشد
            $user = User::find($userId);
            if ($user->avatar && file_exists($uploadDir . $user->avatar)) {
                unlink($uploadDir . $user->avatar);
            }
            
            return ['success' => true, 'filename' => $filename];
        }

        return ['success' => false, 'message' => 'خطا در آپلود فایل'];
    }
}