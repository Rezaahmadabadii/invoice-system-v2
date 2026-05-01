<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header('Location: login.php');
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

// ریست رمز
$successMessage = '';
$errorMessage = '';

if (isset($_GET['reset_password']) && is_numeric($_GET['reset_password']) && isSuperAdmin()) {
    $user_id_to_reset = $_GET['reset_password'];
    $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
    
    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    if ($updateStmt->execute([$hashed_password, $user_id_to_reset])) {
        $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $userStmt->execute([$user_id_to_reset]);
        $user = $userStmt->fetch();
        $successMessage = "رمز عبور کاربر {$user['full_name']} با موفقیت ریست شد. رمز موقت جدید: <strong>{$temp_password}</strong>";
    } else {
        $errorMessage = "خطایی در ریست کردن رمز عبور رخ داد.";
    }
}

// حذف کاربر
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isSuperAdmin()) {
    $id = $_GET['delete'];
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
    header('Location: users.php');
    exit;
}

// فیلترها
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$department_filter = $_GET['department'] ?? '';

$sql = "SELECT u.id, u.full_name, u.username, u.department_id, u.created_at,
        (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) as role_names,
        (SELECT GROUP_CONCAT(r.id) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) as role_ids
        FROM users u WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($role_filter) {
    $sql .= " AND EXISTS (SELECT 1 FROM user_roles WHERE user_id = u.id AND role_id = ?)";
    $params[] = $role_filter;
}

if ($department_filter) {
    $sql .= " AND u.department_id = ?";
    $params[] = $department_filter;
}

$sql .= " ORDER BY u.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = $pdo->query("SELECT id, name FROM roles WHERE is_department = 0 ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();

// افزودن کاربر جدید
$add_error = '';
$add_success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $department_id = $_POST['department_id'] ?? '';
    
    if (empty($fullname) || empty($username) || empty($password) || empty($role_id)) {
        $add_error = 'لطفاً تمام فیلدهای الزامی را پر کنید';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->rowCount() > 0) {
            $add_error = 'این نام کاربری قبلاً ثبت شده است';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (full_name, username, password, department_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            if ($insert->execute([$fullname, $username, $hashed, $department_id ?: null])) {
                $user_id = $pdo->lastInsertId();
                $role_stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
                $role_stmt->execute([$user_id, $role_id, $_SESSION['user_id']]);
                $add_success = 'کاربر جدید با موفقیت اضافه شد';
                logActivity($_SESSION['user_id'], 'add_user', "کاربر جدید اضافه شد: $fullname");
            } else {
                $add_error = 'خطا در افزودن کاربر';
            }
        }
    }
}

$page_title = 'مدیریت کاربران';
ob_start();
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .filter-buttons {
        display: flex;
        gap: 12px;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }
    .filter-btn {
        padding: 10px 24px;
        border-radius: 40px;
        text-decoration: none;
        background: #f0f2f5;
        color: #2c3e50;
        font-weight: 500;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .filter-btn.active {
        background: linear-gradient(135deg, #3498db, #2980b9);
        color: white;
        box-shadow: 0 4px 12px rgba(52,152,219,0.3);
    }
    .filter-btn:hover { transform: translateY(-2px); }
    
    .add-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        border: 1px solid rgba(52,152,219,0.1);
    }
    .add-title {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .add-form {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .add-form .field {
        flex: 1;
        min-width: 180px;
    }
    .add-form .field label {
        display: block;
        font-size: 12px;
        color: #7f8c8d;
        margin-bottom: 6px;
    }
    .add-form .field input, .add-form .field select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
    }
    .add-form .field input:focus, .add-form .field select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
    }
    .add-btn {
        background: linear-gradient(135deg, #27ae60, #219a52);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    .add-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(39,174,96,0.3);
    }
    
    .users-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .user-card {
        background: white;
        border-radius: 20px;
        padding: 18px 15px;
        text-align: center;
        transition: all 0.3s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #eef2f5;
    }
    .user-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.12);
        border-color: #3498db20;
    }
    .user-avatar {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        font-size: 32px;
        background: linear-gradient(135deg, #3498db20, #2980b920);
    }
    .user-name {
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 4px;
        font-size: 15px;
    }
    .user-username {
        font-size: 12px;
        color: #7f8c8d;
        margin-bottom: 10px;
    }
    .user-roles {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 5px;
        margin-bottom: 10px;
    }
    .role-badge {
        background: #3498db20;
        color: #3498db;
        padding: 3px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 500;
    }
    .user-department {
        font-size: 11px;
        color: #7f8c8d;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }
    .user-actions {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #f0f0f0;
    }
    .user-action {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 16px;
        padding: 6px 10px;
        border-radius: 8px;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .user-action.edit { color: #f39c12; }
    .user-action.delete { color: #e74c3c; }
    .user-action.reset { color: #e67e22; }
    .user-action.permission { color: #3498db; }
    .user-action:hover { background: #f8f9fa; transform: scale(1.05); }
    
    .filter-bar {
        background: white;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .filter-row {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .filter-group {
        flex: 1;
        min-width: 150px;
    }
    .filter-group label {
        display: block;
        font-size: 12px;
        color: #7f8c8d;
        margin-bottom: 6px;
    }
    .filter-group input, .filter-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 12px;
        font-size: 14px;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    @media (max-width: 768px) {
        .users-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
        .add-form { flex-direction: column; }
        .add-form .field { width: 100%; }
        .filter-row { flex-direction: column; }
        .filter-group { width: 100%; }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="margin: 0; color: #2c3e50;">👥 مدیریت کاربران</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 12px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($successMessage): ?>
    <div class="alert-success"><?php echo $successMessage; ?></div>
<?php endif; ?>
<?php if ($errorMessage): ?>
    <div class="alert-danger"><?php echo $errorMessage; ?></div>
<?php endif; ?>
<?php if ($add_error): ?>
    <div class="alert-danger"><?php echo $add_error; ?></div>
<?php endif; ?>
<?php if ($add_success): ?>
    <div class="alert-success"><?php echo $add_success; ?></div>
<?php endif; ?>

<!-- فرم افزودن کاربر جدید -->
<div class="add-card">
    <div class="add-title">
        <i class="fas fa-user-plus" style="color: #27ae60;"></i>
        افزودن کاربر جدید
    </div>
    <form method="POST" class="add-form">
        <div class="field">
            <label>👤 نام و نام خانوادگی</label>
            <input type="text" name="fullname" placeholder="مثال: علی محمدی" required>
        </div>
        <div class="field">
            <label>🔑 نام کاربری</label>
            <input type="text" name="username" placeholder="مثال: alimohammadi" required>
        </div>
        <div class="field">
            <label>🔒 رمز عبور</label>
            <input type="password" name="password" placeholder="حداقل ۶ کاراکتر" required>
        </div>
        <div class="field">
            <label>⭐ نقش اصلی</label>
            <select name="role_id" required>
                <option value="">انتخاب کنید</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>"><?php echo $role['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>🏢 بخش (اختیاری)</label>
            <select name="department_id">
                <option value="">بدون بخش</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="add_user" class="add-btn">
            <i class="fas fa-save"></i> افزودن کاربر
        </button>
    </form>
</div>

<!-- فیلترها -->
<div class="filter-bar">
    <form method="GET" class="filter-row">
        <div class="filter-group">
            <label>🔍 جستجو</label>
            <input type="text" name="search" placeholder="نام، نام کاربری..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
            <label>⭐ نقش</label>
            <select name="role">
                <option value="">همه نقش‌ها</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>><?php echo $role['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>🏢 بخش</label>
            <select name="department">
                <option value="">همه بخش‌ها</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>><?php echo $dept['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 12px; cursor: pointer;">
            <i class="fas fa-filter"></i> اعمال فیلتر
        </button>
        <a href="users.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 12px; text-decoration: none;">
            <i class="fas fa-times"></i> پاک کردن
        </a>
    </form>
</div>

<!-- کارت‌های کاربران -->
<div class="users-grid">
    <?php if (empty($users)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 20px; color: #95a5a6;">
            <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
            <p>هیچ کاربری یافت نشد</p>
        </div>
    <?php else: foreach ($users as $user): 
        $role_names = explode(',', $user['role_names'] ?? '');
        $role_ids = explode(',', $user['role_ids'] ?? '');
        $primary_role_id = $role_ids[0] ?? 0;
        $dept_name = '';
        foreach ($departments as $dept) {
            if ($dept['id'] == $user['department_id']) {
                $dept_name = $dept['name'];
                break;
            }
        }
    ?>
    <div class="user-card">
        <div class="user-avatar">
            <span style="font-size: 32px;">👤</span>
        </div>
        <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
        <div class="user-username"><?php echo htmlspecialchars($user['username']); ?></div>
        <div class="user-roles">
            <?php foreach ($role_names as $rn): 
                if (trim($rn)): ?>
                    <span class="role-badge"><?php echo htmlspecialchars(trim($rn)); ?></span>
                <?php endif; 
            endforeach; ?>
        </div>
        <?php if ($dept_name): ?>
            <div class="user-department">
                <i class="fas fa-building"></i> <?php echo htmlspecialchars($dept_name); ?>
            </div>
        <?php endif; ?>
        <div class="user-actions">
            <a href="user-edit.php?id=<?php echo $user['id']; ?>" class="user-action edit" title="ویرایش">
                <i class="fas fa-edit"></i>
            </a>
            <a href="role-permissions.php?id=<?php echo $primary_role_id; ?>" class="user-action permission" title="دسترسی‌های نقش">
                🔐
            </a>
            <?php if (isSuperAdmin() && $user['id'] != $_SESSION['user_id']): ?>
                <a href="?delete=<?php echo $user['id']; ?>" class="user-action delete" title="حذف" onclick="return confirm('آیا از حذف این کاربر اطمینان دارید؟')">
                    <i class="fas fa-trash-alt"></i>
                </a>
                <a href="?reset_password=<?php echo $user['id']; ?>" class="user-action reset" title="ریست رمز" onclick="return confirm('رمز عبور کاربر <?php echo htmlspecialchars($user['full_name']); ?> ریست شود؟')">
                    🔓
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>