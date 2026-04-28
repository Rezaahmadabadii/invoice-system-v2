<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

// اگر کاربر لاگین است، به داشبورد هدایت نشود، بلکه پیام نمایش دهد (اختیاری)
if (isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-warning">شما قبلاً وارد شده‌اید. برای ثبت‌نام کاربر جدید، ابتدا خارج شوید.</div>';
    echo '<a href="dashboard.php">بازگشت به داشبورد</a>';
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

// دریافت لیست بخش‌ها (نقش‌هایی که is_department = 1)
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $department_id = $_POST['department_id'] ?? '';

    if (empty($fullname) || empty($email) || empty($username) || empty($password) || empty($department_id)) {
        $error = 'لطفا تمام فیلدها را پر کنید';
    } elseif ($password !== $confirm) {
        $error = 'رمز عبور و تکرار آن مطابقت ندارند';
    } elseif (strlen($password) < 6) {
        $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->execute([$username, $email]);
        if ($check->rowCount() > 0) {
            $error = 'این نام کاربری یا ایمیل قبلاً ثبت شده است';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (username, email, full_name, password) VALUES (?, ?, ?, ?)");
            if ($insert->execute([$username, $email, $fullname, $hashed])) {
                $user_id = $pdo->lastInsertId();
                // نقش اصلی کاربر = همان بخش انتخابی
                $role_stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
                $role_stmt->execute([$user_id, $department_id, $user_id]);

                // اگر اولین کاربر است، نقش super_admin را هم اضافه کن
                $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                if ($user_count == 1) {
                    $super_admin_role = $pdo->query("SELECT id FROM roles WHERE name = 'super_admin'")->fetchColumn();
                    if ($super_admin_role) {
                        $role_stmt->execute([$user_id, $super_admin_role, $user_id]);
                    }
                }

                $success = 'ثبت نام با موفقیت انجام شد. اکنون می‌توانید وارد شوید.';
                header('refresh:2;url=login.php');
                exit;
            } else {
                $error = 'خطا در ثبت نام';
            }
        }
    }
}

$page_title = 'ثبت نام';
ob_start();
?>

<div class="container">
    <div class="circle-container" id="circleContainer"></div>
    <div class="login-box">
        <div class="auth-panels show-signup">
            <div class="panel panel-signup">
                <h2>ثبت نام</h2>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="fullname" placeholder="نام و نام خانوادگی" required>
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                    </div>
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="email" name="email" placeholder="ایمیل" required>
                            <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        </div>
                    </div>
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="text" name="username" placeholder="نام کاربری" required>
                            <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                        </div>
                    </div>
                    <!-- منوی کشویی بخش/واحد -->
                    <div class="input-group">
                        <div class="input-wrap">
                            <select name="department_id" required style="width:100%; padding:12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:25px; color:#fff; font-family:inherit;">
                                <option value="">انتخاب بخش/واحد</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="input-icon"><i class="fas fa-building"></i></span>
                        </div>
                    </div>
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="password" name="password" placeholder="رمز عبور" required>
                            <span class="input-icon toggle-password"><i class="fas fa-lock"></i></span>
                        </div>
                    </div>
                    <div class="input-group">
                        <div class="input-wrap">
                            <input type="password" name="confirm_password" placeholder="تکرار رمز عبور" required>
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                        </div>
                    </div>
                    <button type="submit" name="register" class="login-btn">ثبت نام</button>
                </form>
                <div class="signup-link">
                    <a href="login.php">قبلاً ثبت نام کرده‌اید؟ وارد شوید</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // دایره‌های متحرک (همان اسکریپت قبلی)
    const circleContainer = document.getElementById('circleContainer');
    if (circleContainer) {
        for (let i = 0; i < 50; i++) {
            let bar = document.createElement('div');
            bar.className = 'bar';
            bar.style.transform = 'rotate(' + (360/50)*i + 'deg) translateY(-170px)';
            circleContainer.appendChild(bar);
        }
        let active = 0;
        setInterval(() => {
            let bars = document.querySelectorAll('.bar');
            bars[active%50]?.classList.add('active');
            if (active>8) bars[(active-8)%50]?.classList.remove('active');
            active++;
        }, 100);
    }
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/auth.php';
?>