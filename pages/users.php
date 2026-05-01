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

// پردازش ریست رمز توسط ادمین
$successMessage = '';
$errorMessage = '';

if (isset($_GET['reset_password']) && is_numeric($_GET['reset_password']) && isSuperAdmin()) {
    $user_id_to_reset = $_GET['reset_password'];
    
    // تولید رمز عبور موقت 8 کاراکتری
    $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
    
    $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    if ($updateStmt->execute([$hashed_password, $user_id_to_reset])) {
        $userStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $userStmt->execute([$user_id_to_reset]);
        $user = $userStmt->fetch();
        
        $successMessage = "رمز عبور کاربر {$user['full_name']} با موفقیت ریست شد. رمز موقت جدید: <strong>{$temp_password}</strong>";
        // ارسال ایمیل به کاربر (اختیاری)
        // sendTemporaryPasswordEmail($user['email'], $user['full_name'], $temp_password);
    } else {
        $errorMessage = "خطایی در ریست کردن رمز عبور رخ داد.";
    }
}

// حذف کاربر
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isSuperAdmin()) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: users.php');
    exit;
}

// دریافت فیلترها
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$department_filter = $_GET['department'] ?? '';

// ساخت کوئری
$sql = "SELECT u.*, 
        (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) as role_names,
        (SELECT GROUP_CONCAT(r.id) FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = u.id) as role_ids
        FROM users u WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
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

// دریافت لیست نقش‌ها برای فیلتر
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll();

// دریافت لیست بخش‌ها برای فیلتر
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();

// پردازش افزودن کاربر جدید
$add_error = '';
$add_success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = $_POST['role_id'] ?? '';
    $department_id = $_POST['department_id'] ?? '';
    
    if (empty($fullname) || empty($email) || empty($username) || empty($password) || empty($role_id)) {
        $add_error = 'لطفاً تمام فیلدهای الزامی را پر کنید';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->execute([$username, $email]);
        if ($check->rowCount() > 0) {
            $add_error = 'این نام کاربری یا ایمیل قبلاً ثبت شده است';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare("INSERT INTO users (full_name, email, username, password, department_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if ($insert->execute([$fullname, $email, $username, $hashed, $department_id ?: null])) {
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
    .user-table {
        width: 100%;
        border-collapse: collapse;
    }
    .user-table th, .user-table td {
        padding: 12px;
        text-align: right;
        border-bottom: 1px solid #eee;
    }
    .user-table th {
        background: #f5f5f5;
        font-weight: bold;
    }
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        margin: 2px;
    }
    .badge-role {
        background: #3498db;
        color: white;
    }
    .badge-department {
        background: #2ecc71;
        color: white;
    }
    .btn-reset {
        background: #e67e22;
        color: white;
        padding: 5px 10px;
        border-radius: 5px;
        text-decoration: none;
        font-size: 12px;
        margin-left: 5px;
    }
    .btn-reset:hover {
        background: #d35400;
    }
    .filter-form {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: end;
    }
    .filter-form input, .filter-form select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
    .add-user-form {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
    .btn-submit {
        background: #27ae60;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
    }
    .alert {
        padding: 12px;
        border-radius: 5px;
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
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>👥 مدیریت کاربران</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">بازگشت</a>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?php echo $successMessage; ?></div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
<?php endif; ?>

<!-- فرم افزودن کاربر جدید -->
<div class="add-user-form">
    <h3>➕ افزودن کاربر جدید</h3>
    <?php if ($add_error): ?>
        <div class="alert alert-danger"><?php echo $add_error; ?></div>
    <?php endif; ?>
    <?php if ($add_success): ?>
        <div class="alert alert-success"><?php echo $add_success; ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-row">
            <div class="form-group">
                <label>نام کامل *</label>
                <input type="text" name="fullname" required>
            </div>
            <div class="form-group">
                <label>ایمیل *</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>نام کاربری *</label>
                <input type="text" name="username" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>رمز عبور *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>نقش اصلی *</label>
                <select name="role_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo $role['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>بخش</label>
                <select name="department_id">
                    <option value="">بدون بخش</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" name="add_user" class="btn-submit">افزودن کاربر</button>
    </form>
</div>

<!-- فرم فیلتر -->
<div class="filter-form">
    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; width: 100%;">
        <input type="text" name="search" placeholder="جستجو..." value="<?php echo htmlspecialchars($search); ?>" style="flex: 2;">
        <select name="role">
            <option value="">همه نقش‌ها</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['id']; ?>" <?php echo $role_filter == $role['id'] ? 'selected' : ''; ?>><?php echo $role['name']; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department">
            <option value="">همه بخش‌ها</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>><?php echo $dept['name']; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" style="background: #3498db; color: white; border: none; padding: 8px 20px; border-radius: 5px;">فیلتر</button>
        <a href="users.php" style="background: #95a5a6; color: white; padding: 8px 20px; border-radius: 5px; text-decoration: none;">پاک کردن</a>
    </form>
</div>

<!-- جدول کاربران -->
<div style="background: white; border-radius: 10px; padding: 20px; overflow-x: auto;">
    <table class="user-table">
        <thead>
            <tr>
                <th>شناسه</th>
                <th>نام و نام خانوادگی</th>
                <th>نام کاربری</th>
                <th>ایمیل</th>
                <th>بخش</th>
                <th>نقش‌ها</th>
                <th>تاریخ ثبت</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="8" style="text-align: center;">هیچ کاربری یافت نشد</td>
                </tr>
            <?php else: foreach ($users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td>
                        <?php
                        $deptName = '';
                        foreach ($departments as $dept) {
                            if ($dept['id'] == $user['department_id']) {
                                $deptName = $dept['name'];
                                break;
                            }
                        }
                        echo $deptName ? '<span class="badge badge-department">🏢 ' . htmlspecialchars($deptName) . '</span>' : '-';
                        ?>
                    </td>
                    <td>
                        <?php
                        $roleNames = explode(',', $user['role_names']);
                        foreach ($roleNames as $rn) {
                            echo '<span class="badge badge-role">' . htmlspecialchars($rn) . '</span> ';
                        }
                        ?>
                    </td>
                    <td><?php echo jdate('Y/m/d', strtotime($user['created_at'])); ?></td>
                    <td>
                        <a href="user-edit.php?id=<?php echo $user['id']; ?>" style="color: #f39c12; text-decoration: none; margin-left: 10px;">✏️ ویرایش</a>
                        <?php if (isSuperAdmin() && $user['id'] != $_SESSION['user_id']): ?>
                            <a href="?delete=<?php echo $user['id']; ?>" onclick="return confirm('آیا از حذف این کاربر اطمینان دارید؟')" style="color: #e74c3c; text-decoration: none; margin-left: 10px;">🗑️ حذف</a>
                        <?php endif; ?>
                        <?php if (isSuperAdmin() && $user['id'] != $_SESSION['user_id']): ?>
                            <a href="?reset_password=<?php echo $user['id']; ?>" onclick="return confirm('رمز عبور کاربر <?php echo htmlspecialchars($user['full_name']); ?> ریست شود؟ رمز موقت 8 کاراکتری نمایش داده خواهد شد.')" class="btn-reset">🔄 ریست رمز</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>