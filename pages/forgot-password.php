<?php
session_start();
require_once __DIR__ . '/../app/Helpers/functions.php';

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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'لطفا ایمیل خود را وارد کنید';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $insert = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $insert->execute([$email, $token, $expires_at]);
            
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/invoice-system-v2/pages/reset-password.php?token=" . $token;
            
            $emailSent = sendResetPasswordEmail($email, $user['full_name'], $reset_link);
            
            if ($emailSent) {
                $message = 'لینک بازیابی رمز عبور به ایمیل شما ارسال شد. لطفاً صندوق ایمیل خود را بررسی کنید.';
            } else {
                error_log("Reset link for $email: " . $reset_link);
                $message = 'لینک بازیابی ساخته شد اما ایمیل ارسال نشد. <br><small>(برای تست: ' . $reset_link . ')</small>';
            }
        } else {
            $message = 'اگر ایمیل شما در سیستم ثبت شده باشد، لینک بازیابی برای شما ارسال خواهد شد.';
        }
    }
}

$page_title = 'بازیابی رمز عبور';
ob_start();
?>

<div class="container">
    <div class="circle-container" id="circleContainer"></div>

    <div class="login-box">
        <div class="auth-panels">
            <div class="panel panel-login">
                <h2>🔐 بازیابی رمز عبور</h2>
                
                <?php if ($error): ?>
                    <div style="background:#ff6b6b; color:white; padding:10px; border-radius:5px; margin-bottom:20px; text-align:center;"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($message): ?>
                    <div style="background:#27ae60; color:white; padding:10px; border-radius:5px; margin-bottom:20px; text-align:center;"><?php echo $message; ?></div>
                <?php endif; ?>
                
                <?php if (!$message): ?>
                <form method="POST">
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="email" name="email" placeholder="ایمیل خود را وارد کنید" required>
                            <span class="input-icon"><i class="fa-solid fa-envelope"></i></span>
                        </div>
                    </div>
                    <button type="submit" class="login-btn">ارسال لینک بازیابی</button>
                </form>
                <?php endif; ?>
                
                <div class="signup-link">
                    <a href="/invoice-system-v2/pages/login.php">← بازگشت به صفحه ورود</a>
                </div>
            </div>
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
            bar.style.transform = 'rotate(' + (360 / numBars) * i + 'deg) translateY(-170px)';
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