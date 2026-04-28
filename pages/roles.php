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

// پردازش فرم‌ها
$message = '';
$error = '';

// افزودن نقش جدید
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_role'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
        if ($stmt->execute([$name, $description])) {
            $message = 'نقش جدید با موفقیت اضافه شد';
            logActivity($_SESSION['user_id'], 'add_role', "نقش جدید اضافه شد: $name");
        } else {
            $error = 'خطا در افزودن نقش';
        }
    }
}

// حذف نقش
if (isset($_GET['delete']) && isSuperAdmin()) { // فقط super_admin می‌تونه حذف کنه
    $id = $_GET['delete'];
    
    // چک نکردن super_admin
    $check = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $check->execute([$id]);
    $role = $check->fetch();
    
    if ($role && $role['name'] != 'super_admin') {
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'نقش با موفقیت حذف شد';
            logActivity($_SESSION['user_id'], 'delete_role', "نقش حذف شد: " . $role['name']);
        } else {
            $error = 'خطا در حذف نقش';
        }
    } else {
        $error = 'نمی‌توان نقش super_admin را حذف کرد';
    }
}

// دریافت لیست نقش‌ها
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();

// دریافت لیست دسترسی‌ها
$permissions = $pdo->query("SELECT * FROM permissions ORDER BY category, id")->fetchAll();
$permissions_by_category = [];
foreach ($permissions as $perm) {
    $permissions_by_category[$perm['category']][] = $perm;
}

$page_title = 'مدیریت نقش‌ها';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">مدیریت نقش‌ها</h1>
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

<!-- فرم افزودن نقش جدید -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <h3 style="margin-bottom: 15px;">افزودن نقش جدید</h3>
    <form method="POST">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="name" placeholder="نام نقش (مثال: مدیر پروژه)" required style="flex: 2; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="description" placeholder="توضیحات" style="flex: 3; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <button type="submit" name="add_role" style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">افزودن</button>
        </div>
    </form>
</div>

<!-- لیست نقش‌ها -->
<div style="background: white; border-radius: 10px; padding: 20px;">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 10px; text-align: right;">شناسه</th>
                <th style="padding: 10px; text-align: right;">نام نقش</th>
                <th style="padding: 10px; text-align: right;">توضیحات</th>
                <th style="padding: 10px; text-align: right;">تاریخ ایجاد</th>
                <th style="padding: 10px; text-align: right;">عملیات</th>
            </tr>
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
                <td style="padding: 10px;"><?php echo htmlspecialchars($role['description'] ?? '-'); ?></td>
                <td style="padding: 10px;"><?php echo jdate('Y/m/d', strtotime($role['created_at'])); ?></td>
                <td style="padding: 10px;">
                    <a href="role-permissions.php?id=<?php echo $role['id']; ?>" style="background: #3498db; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none; margin-left: 5px;">دسترسی‌ها</a>
                    
                    <?php if ($role['name'] != 'super_admin' && isSuperAdmin()): ?>
                        <a href="?delete=<?php echo $role['id']; ?>" onclick="return confirm('آیا از حذف این نقش اطمینان دارید؟')" style="background: #e74c3c; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none;">حذف</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>