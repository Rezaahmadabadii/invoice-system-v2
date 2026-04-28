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

$role_id = $_GET['id'] ?? 0;

// دریافت اطلاعات نقش
$stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$role_id]);
$role = $stmt->fetch();

if (!$role) {
    header('Location: roles.php');
    exit;
}

// پردازش ذخیره دسترسی‌ها
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_permissions'])) {
    // حذف دسترسی‌های قبلی
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
    
    // ذخیره دسترسی‌های جدید
    if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($_POST['permissions'] as $perm_id) {
            $stmt->execute([$role_id, $perm_id]);
        }
    }
    
    logActivity($_SESSION['user_id'], 'update_permissions', "دسترسی‌های نقش {$role['name']} به‌روزرسانی شد");
    $_SESSION['message'] = 'دسترسی‌ها با موفقیت ذخیره شدند';
    header("Location: role-permissions.php?id=$role_id");
    exit;
}

// دریافت دسترسی‌های فعلی
$current_perms = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
$current_perms->execute([$role_id]);
$current = $current_perms->fetchAll(PDO::FETCH_COLUMN);

// دریافت همه دسترسی‌ها
$permissions = $pdo->query("SELECT * FROM permissions ORDER BY category, id")->fetchAll();

// دسته‌بندی
$categories = [];
foreach ($permissions as $perm) {
    $categories[$perm['category']][] = $perm;
}

// نام دسته‌بندی‌ها به فارسی
$category_names = [
    'invoice' => '📄 فاکتور',
    'waybill' => '📦 بارنامه',
    'tax' => '🏛️ سامانه مودیان',
    'forward' => '🔄 ارجاع',
    'admin' => '⚙️ مدیریت'
];

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$page_title = 'مدیریت دسترسی‌ها';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">مدیریت دسترسی‌های نقش: <?php echo htmlspecialchars($role['name']); ?></h1>
    <a href="roles.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($message): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($role['name'] == 'super_admin'): ?>
    <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
        ⚠️ نقش super_admin به صورت پیش‌فرض همه دسترسی‌ها را دارد.
    </div>
<?php endif; ?>

<form method="POST">
    <div style="background: white; border-radius: 10px; padding: 20px;">
        <?php foreach ($categories as $cat => $perms): ?>
            <div style="margin-bottom: 30px;">
                <h3 style="color: #3498db; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                    <?php echo $category_names[$cat] ?? $cat; ?>
                </h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px;">
                    <?php foreach ($perms as $perm): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 5px;">
                            <input type="checkbox" name="permissions[]" value="<?php echo $perm['id']; ?>" 
                                <?php echo in_array($perm['id'], $current) ? 'checked' : ''; ?>
                                <?php echo $role['name'] == 'super_admin' ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($perm['display_name']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if ($role['name'] != 'super_admin'): ?>
            <div style="text-align: left; margin-top: 20px;">
                <button type="submit" name="save_permissions" style="background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px;">
                    <i class="fas fa-save"></i> ذخیره تغییرات
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>