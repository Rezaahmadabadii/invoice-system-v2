<?php
session_start();
require_once __DIR__ . '/../app/Helpers/functions.php';

// اگر کاربر وارد شده، به داشبورد برود
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$host = 'localhost';
$dbname = 'invoice_system';
$username_db = 'root';
$password_db = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
}

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

// بررسی اعتبار توکن
if (empty($token)) {
    header('Location: login.php');
    exit;
}

// بررسی توکن در دیتابیس
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = 'لینک بازیابی نامعتبر یا منقضی شده است. لطفاً دوباره درخواست دهید.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $reset && !$error) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 6) {
        $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد';
    } elseif ($password !== $confirm) {
        $error = 'رمز عبور و تکرار آن مطابقت ندارند';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        // به‌روزرسانی رمز عبور کاربر
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $update->execute([$hashed, $reset['email']]);
        
        // علامت‌گذاری توکن به عنوان استفاده شده
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
        
        $success = 'رمز عبور شما با موفقیت تغییر کرد. اکنون می‌توانید وارد شوید.';
        
        // هدایت به صفحه ورود بعد از 3 ثانیه
        header('refresh:3;url=login.php');
    }
}

$page_title = 'تنظیم رمز عبور جدید';
ob_start();
?>

<style>
    .container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        background: linear-gradient(135deg, #1a1a2e, #16213e);
        position: relative;
        overflow: hidden;
    }
    .circle-container {
        position: absolute;
        width: 100%;
        height: 100%;
        overflow: hidden;
    }
    .bar {
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 4px;
        height: 300px;
        background: rgba(255,255,255,0.05);
        transform-origin: top center;
        transition: all 0.3s ease;
    }
    .bar.active {
        background: linear-gradient(to top, #2c7da0, #61dafb);
        box-shadow: 0 0 15px rgba(97,218,251,0.5);
    }
    .login-box {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(12px);
        border-radius: 24px;
        padding: 40px;
        width: 420px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        border: 1px solid rgba(255,255,255,0.1);
        position: relative;
        z-index: 10;
    }
    .login-box h2 {
        color: white;
        text-align: center;
        margin-bottom: 30px;
        font-weight: 600;
    }
    .input-group {
        margin-bottom: 20px;
    }
    .input-wrap {
        position: relative;
    }
    .input-wrap input {
        width: 100%;
        padding: 14px 45px 14px 20px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 30px;
        color: white;
        font-size: 15px;
        transition: all 0.3s;
    }
    .input-wrap input:focus {
        outline: none;
        border-color: #2c7da0;
        background: rgba(255,255,255,0.12);
    }
    .input-wrap input::placeholder {
        color: rgba(255,255,255,0.6);
    }
    .input-icon {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(255,255,255,0.6);
    }
    .login-btn {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #2c7da0, #1f5e7a);
        border: none;
        border-radius: 30px;
        color: white;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s;
        margin-top: 10px;
    }
    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(44,125,160,0.4);
    }
    .alert {
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 20px;
        text-align: center;
    }
    .alert-danger {
        background: #ff6b6b;
        color: white;
    }
    .alert-success {
        background: #27ae60;
        color: white;
    }
    .back-link {
        text-align: center;
        margin-top: 20px;
    }
    .back-link a {
        color: rgba(255,255,255,0.7);
        text-decoration: none;
    }
    .back-link a:hover {
        color: white;
    }
</style>

<div class="container">
    <div class="circle-container" id="circleContainer"></div>
    <div class="login-box">
        <h2>🔐 تنظیم رمز عبور جدید</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!$error && !$success && $reset): ?>
        <form method="POST">
            <div class="input-group">
                <div class="input-wrap">
                    <input type="password" name="password" placeholder="رمز عبور جدید" required>
                    <span class="input-icon">🔒</span>
                </div>
            </div>
            <div class="input-group">
                <div class="input-wrap">
                    <input type="password" name="confirm_password" placeholder="تکرار رمز عبور جدید" required>
                    <span class="input-icon">🔒</span>
                </div>
            </div>
            <button type="submit" class="login-btn">تغییر رمز عبور</button>
        </form>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="login.php">← بازگشت به صفحه ورود</a>
        </div>
    </div>
</div>

<script>
    const circleContainer = document.getElementById('circleContainer');
    if (circleContainer) {
        const numBars = 50;
        for (let i = 0; i < numBars; i++) {
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.style.transform = 'rotate(' + (360 / numBars) * i + 'deg) translateY(-250px)';
            circleContainer.appendChild(bar);
        }
        let active = 0;
        setInterval(() => {
            const bars = document.querySelectorAll('.bar');
            bars[active % numBars]?.classList.add('active');
            if (active > 8) bars[(active - 8) % numBars]?.classList.remove('active');
            active++;
        }, 100);
    }
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/auth.php';
?>