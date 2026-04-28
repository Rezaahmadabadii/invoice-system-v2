<?php $title = 'ثبت نام در سیستم'; ?>
<?php ob_start(); ?>

<div class="auth-container">
    <div class="auth-card">
        <h2>ثبت نام در سیستم</h2>
        
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error">
                <?php 
                echo $_SESSION['flash_error']; 
                unset($_SESSION['flash_error']);
                ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="/register" class="auth-form">
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="email">ایمیل</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="full_name">نام و نام خانوادگی</label>
                <input type="text" id="full_name" name="full_name">
            </div>
            
            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">تکرار رمز عبور</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-block">ثبت نام</button>
            </div>
            
            <div class="auth-links">
                <a href="/login">قبلاً ثبت نام کرده‌اید؟ وارد شوید</a>
            </div>
        </form>
    </div>
</div>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>