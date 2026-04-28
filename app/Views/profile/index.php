<?php
use App\Core\Session;

$title = 'پروفایل کاربری'; 
ob_start(); 
?>

<div class="profile-container">
    <div class="profile-header">
        <h1>پروفایل کاربری</h1>
        <p>مدیریت اطلاعات شخصی و تنظیمات حساب کاربری</p>
    </div>

    <div class="profile-grid">
        <!-- بخش اطلاعات شخصی -->
        <div class="profile-card">
            <div class="card-header">
                <h3>اطلاعات شخصی</h3>
            </div>
            <div class="card-body">
                <?php if (Session::has('flash_success')): ?>
                    <div class="alert alert-success">
                        <?php echo Session::getFlash('success'); ?>
                    </div>
                <?php endif; ?>

                <?php if (Session::has('flash_error')): ?>
                    <div class="alert alert-error">
                        <?php echo Session::getFlash('error'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/profile/update" enctype="multipart/form-data" class="profile-form">
                    <div class="form-group">
                        <label for="username">نام کاربری</label>
                        <input type="text" id="username" value="<?php echo htmlspecialchars($user->username); ?>" disabled>
                        <small>نام کاربری قابل تغییر نیست</small>
                    </div>

                    <div class="form-group">
                        <label for="full_name">نام و نام خانوادگی</label>
                        <input type="text" id="full_name" name="full_name" 
                               value="<?php echo htmlspecialchars($user->full_name ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">ایمیل</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user->email); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="avatar">تصویر پروفایل</label>
                        <div class="avatar-upload">
                            <div class="current-avatar">
                                <?php if ($user->avatar): ?>
                                    <img src="/uploads/avatars/<?php echo $user->avatar; ?>" alt="Avatar">
                                <?php else: ?>
                                    <div class="default-avatar">
                                        <?php echo strtoupper(substr($user->username, 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" id="avatar" name="avatar" accept="image/*">
                            <small>فرمت‌های مجاز: jpeg, png, gif - حداکثر حجم: ۲ مگابایت</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- بخش تغییر رمز عبور -->
        <div class="profile-card">
            <div class="card-header">
                <h3>تغییر رمز عبور</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="/profile/change-password" class="password-form">
                    <div class="form-group">
                        <label for="current_password">رمز عبور فعلی</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">رمز عبور جدید</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">تکرار رمز عبور جدید</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- بخش اطلاعات حساب -->
        <div class="profile-card">
            <div class="card-header">
                <h3>اطلاعات حساب</h3>
            </div>
            <div class="card-body">
                <div class="info-item">
                    <label>نقش کاربری:</label>
                    <span class="badge badge-<?php echo $user->role; ?>">
                        <?php 
                        $roles = [
                            'admin' => 'مدیر سیستم',
                            'supervisor' => 'ناظر',
                            'finance' => 'مدیر مالی',
                            'user' => 'کاربر عادی'
                        ];
                        echo $roles[$user->role] ?? $user->role;
                        ?>
                    </span>
                </div>

                <div class="info-item">
                    <label>تاریخ عضویت:</label>
                    <span><?php echo date('Y/m/d', strtotime($user->created_at)); ?></span>
                </div>

                <div class="info-item">
                    <label>آخرین ورود:</label>
                    <span><?php echo $user->last_login ? date('Y/m/d H:i', strtotime($user->last_login)) : 'هنوز وارد نشده'; ?></span>
                </div>

                <div class="info-item">
                    <label>آخرین IP:</label>
                    <span><?php echo $user->last_ip ?? 'نامشخص'; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.profile-container {
    padding: 20px;
}

.profile-header {
    margin-bottom: 30px;
}

.profile-header h1 {
    color: #333;
    margin-bottom: 5px;
}

.profile-header p {
    color: #666;
}

.profile-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
}

.profile-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.card-header {
    padding: 15px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.card-header h3 {
    color: #333;
    font-size: 16px;
}

.card-body {
    padding: 20px;
}

/* فرم‌ها */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: #555;
    font-weight: 500;
}

.form-group input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.form-group input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 5px rgba(102,126,234,0.3);
}

.form-group input:disabled {
    background: #f8f9fa;
    cursor: not-allowed;
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: #888;
    font-size: 12px;
}

/* آپلود آواتار */
.avatar-upload {
    display: flex;
    align-items: center;
    gap: 20px;
}

.current-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    background: #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.current-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.default-avatar {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    font-size: 32px;
    font-weight: bold;
}

/* اطلاعات حساب */
.info-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.info-item:last-child {
    border-bottom: none;
}

.info-item label {
    color: #666;
    font-weight: 500;
}

.info-item span {
    color: #333;
}

/* بج‌ها */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
}

.badge-admin {
    background: #dc3545;
    color: white;
}

.badge-supervisor {
    background: #28a745;
    color: white;
}

.badge-finance {
    background: #17a2b8;
    color: white;
}

.badge-user {
    background: #6c757d;
    color: white;
}

/* آلرت‌ها */
.alert {
    padding: 12px 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* واکنش‌گرایی */
@media (max-width: 768px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
    
    .avatar-upload {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>