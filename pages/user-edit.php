<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    header('Location: users.php');
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

// دریافت اطلاعات کاربر
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

// دریافت نقش‌های فعلی کاربر
$user_roles_stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$user_roles_stmt->execute([$id]);
$user_roles = $user_roles_stmt->fetchAll(PDO::FETCH_COLUMN);

// دریافت لیست نقش‌های عادی (برای نمایش در فرم)
$roles = $pdo->query("SELECT id, name FROM roles WHERE is_department = 0 ORDER BY name")->fetchAll();

// دریافت لیست بخش‌ها
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role_id = $_POST['role_id'] ?? '';
    $department_id = $_POST['department_id'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($fullname) || empty($username) || empty($role_id)) {
        $error = 'لطفاً تمام فیلدهای الزامی را پر کنید';
    } else {
        // بررسی تکراری نبودن نام کاربری برای سایر کاربران
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $id]);
        if ($check->rowCount() > 0) {
            $error = 'این نام کاربری قبلاً ثبت شده است';
        } else {
            // به‌روزرسانی اطلاعات پایه
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, password = ?, department_id = ? WHERE id = ?");
                $update->execute([$fullname, $username, $hashed, $department_id ?: null, $id]);
            } else {
                $update = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, department_id = ? WHERE id = ?");
                $update->execute([$fullname, $username, $department_id ?: null, $id]);
            }
            
            // به‌روزرسانی نقش کاربر (حذف نقش‌های قبلی و اضافه کردن نقش جدید)
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
            $role_stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
            $role_stmt->execute([$id, $role_id, $_SESSION['user_id']]);
            
            $success = 'اطلاعات کاربر با موفقیت به‌روزرسانی شد';
            logActivity($_SESSION['user_id'], 'edit_user', "کاربر ویرایش شد: $fullname", $id);
            
            // بازخوانی اطلاعات کاربر
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            $user_roles = [$role_id];
        }
    }
}

$page_title = 'ویرایش کاربر';
ob_start();
?>

<style>
    .form-card {
        background: white;
        border-radius: 20px;
        padding: 30px;
        max-width: 600px;
        margin: 0 auto;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    .form-title {
        font-size: 22px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: 500;
        color: #2c3e50;
        margin-bottom: 8px;
        font-size: 14px;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    .form-group input:focus, .form-group select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    }
    .form-group small {
        display: block;
        font-size: 11px;
        color: #7f8c8d;
        margin-top: 5px;
    }
    .btn-save {
        background: linear-gradient(135deg, #27ae60, #219a52);
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        width: 100%;
        transition: all 0.3s;
    }
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(39,174,96,0.3);
    }
    .btn-back {
        background: #95a5a6;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 10px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    .alert {
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .alert-success {
        background: #d4edda;
        color: #155724;
    }
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
    }
    .header-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
</style>

<div class="header-bar">
    <h1 style="margin: 0;">✏️ ویرایش کاربر</h1>
    <a href="users.php" class="btn-back">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <script>
        setTimeout(function() { window.location.href = 'users.php'; }, 1500);
    </script>
<?php endif; ?>

<div class="form-card">
    <div class="form-title">
        <i class="fas fa-user-edit" style="color: #3498db;"></i>
        اطلاعات کاربر: <?php echo htmlspecialchars($user['full_name']); ?>
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label>👤 نام و نام خانوادگی</label>
            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
        </div>
        
        <div class="form-group">
            <label>🔑 نام کاربری</label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
        </div>
        
        <div class="form-group">
            <label>🔒 رمز عبور جدید</label>
            <input type="password" name="password" placeholder="در صورت تمایل به تغییر، وارد کنید">
            <small>برای تغییر رمز عبور، مقدار جدید را وارد کنید. در غیر این صورت خالی بگذارید.</small>
        </div>
        
        <div class="form-group">
            <label>⭐ نقش اصلی</label>
            <select name="role_id" required>
                <option value="">انتخاب کنید</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>" <?php echo in_array($role['id'], $user_roles) ? 'selected' : ''; ?>>
                        <?php echo $role['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>🏢 بخش (اختیاری)</label>
            <select name="department_id">
                <option value="">بدون بخش</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo ($user['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                        <?php echo $dept['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>📅 تاریخ ثبت</label>
            <input type="text" value="<?php echo jdate('Y/m/d', strtotime($user['created_at'])); ?>" disabled style="background: #f8f9fa;">
        </div>
        
        <button type="submit" name="edit_user" class="btn-save">
            <i class="fas fa-save"></i> ذخیره تغییرات
        </button>
    </form>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>