<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

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

// دریافت اطلاعات کاربر
$stmt = $pdo->prepare("
    SELECT u.*, 
           d.name as department_name,
           (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) as role_names
    FROM users u
    LEFT JOIN roles d ON u.department_id = d.id
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$password_error = '';
$password_success = '';

// به‌روزرسانی اطلاعات پروفایل
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    
    if (empty($full_name) || empty($email) || empty($username)) {
        $error = 'لطفاً تمام فیلدها را پر کنید';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
        $check->execute([$email, $username, $user_id]);
        if ($check->rowCount() > 0) {
            $error = 'این ایمیل یا نام کاربری قبلاً ثبت شده است';
        } else {
            $update = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, username = ? WHERE id = ?");
            if ($update->execute([$full_name, $email, $username, $user_id])) {
                $_SESSION['username'] = $username;
                $_SESSION['user_fullname'] = $full_name;
                $success = 'اطلاعات با موفقیت به‌روزرسانی شد';
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $error = 'خطا در به‌روزرسانی اطلاعات';
            }
        }
    }
}

// تغییر رمز عبور
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // بررسی رمز فعلی
    $check_pass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $check_pass->execute([$user_id]);
    $user_pass = $check_pass->fetch();
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $password_error = 'لطفاً تمام فیلدهای رمز عبور را پر کنید';
    } elseif (!password_verify($current_password, $user_pass['password'])) {
        $password_error = 'رمز عبور فعلی اشتباه است';
    } elseif (strlen($new_password) < 6) {
        $password_error = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد';
    } elseif ($new_password !== $confirm_password) {
        $password_error = 'رمز عبور جدید و تکرار آن مطابقت ندارند';
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        if ($update->execute([$hashed_password, $user_id])) {
            $password_success = 'رمز عبور با موفقیت تغییر کرد';
        } else {
            $password_error = 'خطا در تغییر رمز عبور';
        }
    }
}

$page_title = 'پروفایل کاربری';
ob_start();
?>

<style>
    .profile-container {
        max-width: 900px;
        margin: 0 auto;
    }
    .profile-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        margin-bottom: 25px;
    }
    .profile-header {
        background: linear-gradient(135deg, #2c3e50, #3498db);
        padding: 30px;
        text-align: center;
        color: white;
    }
    .profile-avatar {
        margin-bottom: 15px;
    }
    .profile-avatar .avatar-circle {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        font-weight: bold;
        color: white;
        text-transform: uppercase;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    .profile-header h2 {
        margin: 0;
        font-size: 22px;
    }
    .profile-header p {
        margin: 5px 0 0;
        opacity: 0.9;
    }
    .profile-body {
        padding: 30px;
    }
    .info-group {
        margin-bottom: 25px;
    }
    .info-group label {
        display: block;
        font-weight: bold;
        color: #2c3e50;
        margin-bottom: 8px;
        font-size: 13px;
    }
    .info-group input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s;
    }
    .info-group input:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    }
    .info-group .readonly-field {
        background: #f8f9fa;
        padding: 12px;
        border: 1px solid #eee;
        border-radius: 10px;
        color: #7f8c8d;
    }
    .info-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    .btn-save {
        background: #27ae60;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
        transition: all 0.3s;
    }
    .btn-save:hover {
        background: #219a52;
        transform: translateY(-2px);
    }
    .btn-change-password {
        background: #3498db;
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
        transition: all 0.3s;
        margin-top: 15px;
    }
    .btn-change-password:hover {
        background: #2980b9;
        transform: translateY(-2px);
    }
    .alert {
        padding: 15px;
        border-radius: 10px;
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
    .role-badge {
        display: inline-block;
        background: #3498db;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        margin-left: 8px;
        margin-bottom: 5px;
    }
    .section-title {
        font-size: 18px;
        font-weight: bold;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #3498db;
        display: inline-block;
    }
</style>

<div class="profile-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="margin: 0;">👤 پروفایل کاربری</h1>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- کارت اطلاعات کاربری -->
    <div class="profile-card">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php
                $first_char = mb_substr($user['full_name'] ?? $user['username'], 0, 1, 'UTF-8');
                $colors = ['#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6'];
                $color = $colors[abs(crc32($user['username']) % count($colors))];
                ?>
                <div class="avatar-circle" style="background: <?php echo $color; ?>;">
                    <?php echo htmlspecialchars($first_char); ?>
                </div>
            </div>
            <h2><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h2>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
        </div>

        <div class="profile-body">
            <form method="POST">
                <div class="info-row">
                    <div class="info-group">
                        <label>نام و نام خانوادگی</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                    </div>
                    <div class="info-group">
                        <label>نام کاربری</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-group">
                        <label>ایمیل</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="info-group">
                        <label>بخش / واحد سازمانی</label>
                        <div class="readonly-field">
                            <?php echo htmlspecialchars($user['department_name'] ?? 'ثبت نشده'); ?>
                        </div>
                    </div>
                </div>

                <div class="info-group">
                    <label>نقش‌های کاربری</label>
                    <div class="readonly-field">
                        <?php
                        $role_names = explode(',', $user['role_names'] ?? '');
                        foreach ($role_names as $role) {
                            if (trim($role)) {
                                echo '<span class="role-badge">' . htmlspecialchars(trim($role)) . '</span>';
                            }
                        }
                        if (empty($role_names) || empty($role_names[0])) {
                            echo 'نقشی تعریف نشده';
                        }
                        ?>
                    </div>
                </div>

                <div class="info-group">
                    <label>تاریخ عضویت</label>
                    <div class="readonly-field">
                        <?php echo jdate('Y/m/d', strtotime($user['created_at'])); ?>
                    </div>
                </div>

                <button type="submit" name="update_profile" class="btn-save">
                    <i class="fas fa-save"></i> ذخیره تغییرات
                </button>
            </form>
        </div>
    </div>

    <!-- کارت تغییر رمز عبور -->
    <div class="profile-card">
        <div class="profile-body">
            <h3 class="section-title">🔒 تغییر رمز عبور</h3>
            
            <?php if ($password_success): ?>
                <div class="alert alert-success"><?php echo $password_success; ?></div>
            <?php endif; ?>
            <?php if ($password_error): ?>
                <div class="alert alert-error"><?php echo $password_error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="info-group">
                    <label>رمز عبور فعلی</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="info-row">
                    <div class="info-group">
                        <label>رمز عبور جدید</label>
                        <input type="password" name="new_password" required>
                        <small>حداقل ۶ کاراکتر</small>
                    </div>
                    <div class="info-group">
                        <label>تکرار رمز عبور جدید</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit" name="change_password" class="btn-change-password">
                    <i class="fas fa-key"></i> تغییر رمز عبور
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>