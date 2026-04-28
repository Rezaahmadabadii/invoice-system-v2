<?php
session_start();
require_once __DIR__ . '/../app/Helpers/functions.php';

// اتصال به دیتابیس
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

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'لطفا نام کاربری و رمز عبور را وارد کنید';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // دریافت نقش‌های کاربر
            $roles_stmt = $pdo->prepare("
                SELECT r.* FROM roles r
                JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = ?
            ");
            $roles_stmt->execute([$user['id']]);
            $roles = $roles_stmt->fetchAll();
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_fullname'] = $user['full_name'];
            $_SESSION['user_roles'] = array_column($roles, 'name');
            $_SESSION['user_role_ids'] = array_column($roles, 'id');
            $_SESSION['is_super_admin'] = in_array('super_admin', $_SESSION['user_roles']);
            $_SESSION['is_admin'] = $_SESSION['is_super_admin'] || in_array('admin', $_SESSION['user_roles']);
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'نام کاربری یا رمز عبور اشتباه است';
        }
    }
}

$page_title = 'ورود';
ob_start();
?>

<div class="container">
    <div class="circle-container" id="circleContainer"></div>

    <div class="login-box">
        <div class="auth-panels" id="authPanels">
            <!-- پنل ورود -->
            <div class="panel panel-login">
                <h2>ورود</h2>
                
                <?php if ($error): ?>
                    <div style="background:#ff6b6b; color:white; padding:10px; border-radius:5px; margin-bottom:20px; text-align:center;"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="username" placeholder="ایمیل یا نام کاربری" required>
                            <span class="input-icon"><i class="fa-solid fa-user"></i></span>
                        </div>
                    </div>
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="password" name="password" placeholder="رمز عبور" required>
                            <span class="input-icon toggle-password" data-target="loginPassword"><i class="fa-solid fa-lock"></i></span>
                        </div>
                    </div>
                    <div class="forgot-password">
                        <a href="#">رمز عبور را فراموش کرده‌اید؟</a>
                    </div>
                    <button type="submit" name="login" class="login-btn">ورود</button>
                </form>
                
                <div class="social-login">
                    <p>ورود با</p>
                    <div class="social-icons">
                        <div class="social-icon facebook">f</div>
                        <div class="social-icon twitter">𝕏</div>
                        <div class="social-icon google">G</div>
                    </div>
                </div>
                <div class="signup-link">
                    <a href="#" id="goSignup">ثبت‌نام</a>
                </div>
            </div>

            <!-- پنل ثبت‌نام (همون صفحه register.php رو صدا می‌زنه) -->
            <div class="panel panel-signup">
                <h2>ثبت‌نام</h2>
                <form method="POST" action="register.php">
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="fullname" placeholder="نام و نام خانوادگی" required>
                            <span class="input-icon"><i class="fa-solid fa-user"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="email" name="email" placeholder="ایمیل" required>
                            <span class="input-icon"><i class="fa-solid fa-envelope"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="username" placeholder="نام کاربری" required>
                            <span class="input-icon"><i class="fa-solid fa-user-tag"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="phone" placeholder="شماره تماس">
                            <span class="input-icon"><i class="fa-solid fa-phone"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="position" placeholder="سمت سازمانی">
                            <span class="input-icon"><i class="fa-solid fa-briefcase"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="password" name="password" placeholder="رمز عبور" required>
                            <span class="input-icon toggle-password" data-target="signupPassword"><i class="fa-solid fa-lock"></i></span>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="password" name="confirm_password" placeholder="تکرار رمز عبور" required>
                            <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        </div>
                    </div>
                    
                    <button type="submit" name="register" class="login-btn">ثبت‌نام</button>
                </form>
                
                <div class="social-login">
                    <p>ثبت‌نام با</p>
                    <div class="social-icons">
                        <div class="social-icon facebook">f</div>
                        <div class="social-icon twitter">𝕏</div>
                        <div class="social-icon google">G</div>
                    </div>
                </div>
                <div class="signup-link">
                    <a href="#" id="goLogin">بازگشت به صفحه ورود</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- اسکریپت‌ها (همون قبلی) -->
<script>
    const authPanels = document.getElementById('authPanels');
    const goSignup = document.getElementById('goSignup');
    const goLogin = document.getElementById('goLogin');

    goSignup?.addEventListener('click', (e) => {
        e.preventDefault();
        authPanels.classList.add('show-signup');
    });
    goLogin?.addEventListener('click', (e) => {
        e.preventDefault();
        authPanels.classList.remove('show-signup');
    });

    document.querySelectorAll('.toggle-password').forEach(el => {
        el.addEventListener('click', function() {
            const input = this.closest('.input-wrap').querySelector('input');
            const type = input.getAttribute('type');
            input.setAttribute('type', type === 'password' ? 'text' : 'password');
        });
    });

    // دایره‌های متحرک
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