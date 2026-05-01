<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// فقط super_admin و admin می‌توانند وارد شوند
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

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

// فیلتر نوع نمایش
$filter_type = $_GET['filter_type'] ?? 'all'; // all, departments, roles

// پردازش فرم‌ها
$message = '';
$error = '';

// افزودن نقش جدید
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_role'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_department = isset($_POST['is_department']) ? 1 : 0;
    
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO roles (name, description, is_department, created_at) VALUES (?, ?, ?, NOW())");
        if ($stmt->execute([$name, $description, $is_department])) {
            $message = 'نقش جدید با موفقیت اضافه شد';
            logActivity($_SESSION['user_id'], 'add_role', "نقش جدید اضافه شد: $name");
        } else {
            $error = 'خطا در افزودن نقش';
        }
    }
}

// ویرایش نقش
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_role'])) {
    $id = $_POST['role_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_department = isset($_POST['is_department']) ? 1 : 0;
    
    // جلوگیری از تغییر نقش super_admin
    $check = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetch();
    
    if ($role && $role['name'] == 'super_admin') {
        $error = 'نقش super_admin قابل ویرایش نیست';
    } elseif (!empty($name)) {
        $stmt = $pdo->prepare("UPDATE roles SET name = ?, description = ?, is_department = ? WHERE id = ?");
        if ($stmt->execute([$name, $description, $is_department, $id])) {
            $message = 'نقش با موفقیت ویرایش شد';
            logActivity($_SESSION['user_id'], 'edit_role', "نقش ویرایش شد: $name");
        } else {
            $error = 'خطا در ویرایش نقش';
        }
    }
}

// حذف نقش
if (isset($_GET['delete']) && isSuperAdmin()) {
    $id = $_GET['delete'];
    
    $check = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetch();
    
    if ($role && $role['name'] == 'super_admin') {
        $error = 'نمی‌توان نقش super_admin را حذف کرد';
    } else {
        // بررسی اینکه آیا کاربری به این بخش متصل است
        $userCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
        $userCheck->execute([$id]);
        $userCount = $userCheck->fetchColumn();
        
        if ($userCount > 0) {
            $error = 'نمی‌توان این بخش/نقش را حذف کرد، زیرا ' . $userCount . ' کاربر به آن متصل هستند. ابتدا کاربران را به بخش/نقش دیگری منتقل کنید.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'نقش با موفقیت حذف شد';
                logActivity($_SESSION['user_id'], 'delete_role', "نقش حذف شد: " . $role['name']);
            } else {
                $error = 'خطا در حذف نقش';
            }
        }
    }
}

// ساخت کوئری دریافت نقش‌ها بر اساس فیلتر
$sql = "SELECT * FROM roles ORDER BY is_department DESC, name";
if ($filter_type == 'departments') {
    $sql = "SELECT * FROM roles WHERE is_department = 1 ORDER BY name";
} elseif ($filter_type == 'roles') {
    $sql = "SELECT * FROM roles WHERE is_department = 0 ORDER BY name";
}
$roles = $pdo->query($sql)->fetchAll();

// دریافت لیست دسترسی‌ها
$permissions = $pdo->query("SELECT * FROM permissions ORDER BY category, id")->fetchAll();
$permissions_by_category = [];
foreach ($permissions as $perm) {
    $permissions_by_category[$perm['category']][] = $perm;
}

$page_title = 'مدیریت نقش‌ها و بخش‌ها';
ob_start();
?>

<style>
    .role-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .badge-department {
        background: #3498db;
        color: white;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
        margin-right: 8px;
    }
    .badge-role {
        background: #95a5a6;
        color: white;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
        margin-right: 8px;
    }
    .filter-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    .filter-btn {
        padding: 8px 16px;
        border-radius: 20px;
        text-decoration: none;
        background: #ecf0f1;
        color: #2c3e50;
    }
    .filter-btn.active {
        background: #2c7da0;
        color: white;
    }
    .edit-form {
        display: none;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #eee;
    }
    .edit-form.show {
        display: block;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">👥 مدیریت نقش‌ها و بخش‌ها</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($message): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- فیلترها -->
<div class="filter-buttons">
    <a href="?filter_type=all" class="filter-btn <?php echo $filter_type == 'all' ? 'active' : ''; ?>">📋 همه</a>
    <a href="?filter_type=departments" class="filter-btn <?php echo $filter_type == 'departments' ? 'active' : ''; ?>">🏢 فقط بخش‌ها</a>
    <a href="?filter_type=roles" class="filter-btn <?php echo $filter_type == 'roles' ? 'active' : ''; ?>">👤 فقط نقش‌ها</a>
</div>

<!-- فرم افزودن نقش جدید -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <h3 style="margin-bottom: 15px;">➕ افزودن نقش/بخش جدید</h3>
    <form method="POST">
        <div style="display: grid; grid-template-columns: 2fr 3fr 1fr 1fr; gap: 10px; align-items: end;">
            <input type="text" name="name" placeholder="نام (مثال: مالی، مدیر پروژه)" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="description" placeholder="توضیحات" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <label style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                <input type="checkbox" name="is_department"> این نقش یک <strong>بخش</strong> است
            </label>
            <button type="submit" name="add_role" style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">افزودن</button>
        </div>
        <small style="color: #7f8c8d;">نقش‌هایی که «بخش» هستند، در لیست ارجاع و ثبت‌نام نمایش داده می‌شوند.</small>
    </form>
</div>

<!-- لیست نقش‌ها -->
<div style="background: white; border-radius: 10px; padding: 20px;">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 10px; text-align: right;">شناسه</th>
                <th style="padding: 10px; text-align: right;">نام</th>
                <th style="padding: 10px; text-align: right;">نوع</th>
                <th style="padding: 10px; text-align: right;">توضیحات</th>
                <th style="padding: 10px; text-align: right;">تاریخ ایجاد</th>
                <th style="padding: 10px; text-align: right;">عملیات</th>
            </td>
        </thead>
        <tbody>
            <?php foreach ($roles as $role): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 10px;"><?php echo $role['id']; ?></td>
                <td style="padding: 10px;">
                    <strong><?php echo htmlspecialchars($role['name']); ?></strong>
                    <?php if ($role['name'] == 'super_admin'): ?>
                        <span style="background: #e74c3c; color: white; padding: 2px 5px; border-radius: 3px; font-size: 10px;">غیرقابل تغییر</span>
                    <?php endif; ?>
                </td>
                <td style="padding: 10px;">
                    <?php if ($role['is_department'] == 1): ?>
                        <span class="badge-department">🏢 بخش</span>
                    <?php else: ?>
                        <span class="badge-role">👤 عادی </span>
                    <?php endif; ?>
                </td>
                <td style="padding: 10px;"><?php echo htmlspecialchars($role['description'] ?? '-'); ?></td>
                <td style="padding: 10px;"><?php echo jdate('Y/m/d', strtotime($role['created_at'])); ?></td>
                <td style="padding: 10px;">
                    <a href="role-permissions.php?id=<?php echo $role['id']; ?>" style="background: #3498db; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none; margin-left: 5px;">🔐 دسترسی‌ها</a>
                    
                    <?php if ($role['name'] != 'super_admin'): ?>
                        <button onclick="toggleEditForm(<?php echo $role['id']; ?>)" style="background: #f39c12; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">✏️ ویرایش</button>
                        
                        <?php if (isSuperAdmin()): ?>
                            <a href="?delete=<?php echo $role['id']; ?>&filter_type=<?php echo $filter_type; ?>" onclick="return confirm('آیا از حذف این نقش/بخش اطمینان دارید؟')" style="background: #e74c3c; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none;">🗑️ حذف</a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <!-- فرم ویرایش (مخفی در ابتدا) -->
                    <div id="edit-form-<?php echo $role['id']; ?>" class="edit-form">
                        <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                            <input type="text" name="name" value="<?php echo htmlspecialchars($role['name']); ?>" required style="padding: 8px; border: 1px solid #ddd; border-radius: 5px; width: 150px;">
                            <input type="text" name="description" value="<?php echo htmlspecialchars($role['description'] ?? ''); ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 5px; width: 200px;">
                            <label style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <input type="checkbox" name="is_department" <?php echo $role['is_department'] == 1 ? 'checked' : ''; ?>> بخش
                            </label>
                            <button type="submit" name="edit_role" style="background: #27ae60; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">ذخیره</button>
                            <button type="button" onclick="toggleEditForm(<?php echo $role['id']; ?>)" style="background: #95a5a6; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">انصراف</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function toggleEditForm(id) {
    var form = document.getElementById('edit-form-' + id);
    form.classList.toggle('show');
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>