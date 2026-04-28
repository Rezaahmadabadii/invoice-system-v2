<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header('Location: login.php');
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

// پردازش ذخیره نقش‌ها
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_user_roles'])) {
    $user_id = $_POST['user_id'] ?? 0;
    $roles = $_POST['roles'] ?? [];
    
    // حذف نقش‌های قبلی
    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$user_id]);
    
    // ذخیره نقش‌های جدید
    if (!empty($roles)) {
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
        foreach ($roles as $role_id) {
            $stmt->execute([$user_id, $role_id, $_SESSION['user_id']]);
        }
    }
    
    logActivity($_SESSION['user_id'], 'update_user_roles', "نقش‌های کاربر $user_id به‌روزرسانی شد");
    $_SESSION['message'] = 'نقش‌های کاربر با موفقیت ذخیره شدند';
    header('Location: users.php');
    exit;
}

// دریافت کاربر برای ویرایش
$edit_user_id = $_GET['user_id'] ?? 0;
$edit_user = null;
$user_roles = [];

if ($edit_user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$edit_user_id]);
    $edit_user = $stmt->fetch();
    
    if ($edit_user) {
        $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
        $stmt->execute([$edit_user_id]);
        $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// دریافت لیست همه نقش‌ها
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();

$page_title = 'مدیریت نقش کاربران';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">اختصاص نقش به کاربر</h1>
    <a href="users.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت به لیست کاربران
    </a>
</div>

<?php if (!$edit_user): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
        کاربر مورد نظر یافت نشد.
    </div>
<?php else: ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
        <h3 style="color: #2c3e50;"><?php echo htmlspecialchars($edit_user['full_name']); ?></h3>
        <p style="color: #7f8c8d;">نام کاربری: <?php echo htmlspecialchars($edit_user['username']); ?> | ایمیل: <?php echo htmlspecialchars($edit_user['email']); ?></p>
    </div>
    
    <form method="POST">
        <input type="hidden" name="user_id" value="<?php echo $edit_user_id; ?>">
        
        <div style="margin-bottom: 20px;">
            <h4 style="margin-bottom: 15px;">نقش‌های قابل انتخاب:</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ($roles as $role): ?>
                    <label style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; <?php echo $role['name'] == 'super_admin' && $edit_user_id == $_SESSION['user_id'] ? 'opacity: 0.5;' : ''; ?>">
                        <input type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" 
                            <?php echo in_array($role['id'], $user_roles) ? 'checked' : ''; ?>
                            <?php echo $role['name'] == 'super_admin' && $edit_user_id == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                        <div>
                            <strong><?php echo htmlspecialchars($role['name']); ?></strong>
                            <?php if ($role['description']): ?>
                                <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars($role['description']); ?></small>
                            <?php endif; ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php if ($edit_user_id == $_SESSION['user_id']): ?>
            <div style="background: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
                ⚠️ شما در حال ویرایش نقش‌های خودتان هستید. نقش super_admin قابل تغییر نیست.
            </div>
        <?php endif; ?>
        
        <div style="text-align: left; margin-top: 20px;">
            <button type="submit" name="save_user_roles" style="background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px;">
                <i class="fas fa-save"></i> ذخیره نقش‌ها
            </button>
        </div>
    </form>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>