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

$role_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$role_id]);
$role = $stmt->fetch();

if (!$role) {
    header('Location: roles.php');
    exit;
}

// پردازش ذخیره دسترسی‌ها
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_permissions'])) {
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
    
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

$current_perms = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
$current_perms->execute([$role_id]);
$current = $current_perms->fetchAll(PDO::FETCH_COLUMN);

$permissions = $pdo->query("SELECT * FROM permissions ORDER BY category, id")->fetchAll();

$categories = [];
foreach ($permissions as $perm) {
    $categories[$perm['category']][] = $perm;
}

$category_names = [
    'invoice' => '📄 فاکتور',
    'waybill' => '📦 بارنامه',
    'tax' => '🏛️ سامانه مودیان',
    'forward' => '🔄 ارجاع',
    'admin' => '⚙️ مدیریت'
];

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$page_title = "مدیریت دسترسی‌ها - {$role['name']}";
ob_start();
?>

<style>
    .permission-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .permission-card {
        background: white;
        border-radius: 24px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        border: 1px solid rgba(52,152,219,0.1);
    }
    .category-title {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #3498db;
        display: inline-block;
    }
    .permissions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }
    .permission-item {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 10px 15px;
        transition: all 0.2s;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .permission-item:hover {
        background: #e8f4fd;
        transform: translateX(-3px);
    }
    .permission-item input {
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #3498db;
    }
    .permission-item label {
        flex: 1;
        cursor: pointer;
        font-size: 14px;
        color: #2c3e50;
    }
    .btn-save {
        background: linear-gradient(135deg, #27ae60, #219a52);
        color: white;
        border: none;
        padding: 14px 30px;
        border-radius: 40px;
        cursor: pointer;
        font-weight: 600;
        font-size: 16px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
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
        border-radius: 40px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    .btn-back:hover {
        background: #7f8c8d;
        transform: translateY(-2px);
    }
    .role-info {
        background: linear-gradient(135deg, #3498db10, #2980b910);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }
    .role-icon {
        font-size: 48px;
    }
    .role-details h2 {
        margin: 0 0 5px;
        color: #2c3e50;
    }
    .role-details p {
        margin: 0;
        color: #7f8c8d;
    }
    .alert-success {
        background: #d4edda;
        color: #155724;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .note-box {
        background: #fef9e6;
        border-radius: 12px;
        padding: 15px;
        margin-top: 20px;
        color: #e67e22;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    @media (max-width: 768px) {
        .permissions-grid {
            grid-template-columns: 1fr;
        }
        .permission-header {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<div class="permission-header">
    <div>
        <h1 style="margin: 0;">🔐 مدیریت دسترسی‌ها</h1>
        <p style="color: #7f8c8d; margin-top: 5px;">تنظیم سطح دسترسی برای نقش‌های مختلف</p>
    </div>
    <a href="roles.php" class="btn-back">
        <i class="fas fa-arrow-right"></i> بازگشت به نقش‌ها
    </a>
</div>

<?php if ($message): ?>
    <div class="alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($role['name'] == 'super_admin'): ?>
    <div class="note-box">
        <i class="fas fa-crown" style="font-size: 20px;"></i>
        نقش super_admin به صورت پیش‌فرض همه دسترسی‌ها را دارد و قابل تغییر نیست.
    </div>
<?php endif; ?>

<div class="role-info">
    <div class="role-icon">
        <?php 
        if ($role['is_department'] == 1) echo '🏢';
        elseif ($role['name'] == 'super_admin') echo '👑';
        else echo '👤';
        ?>
    </div>
    <div class="role-details">
        <h2><?php echo htmlspecialchars($role['name']); ?></h2>
        <p><?php echo htmlspecialchars($role['description'] ?? 'بدون توضیحات'); ?></p>
    </div>
</div>

<form method="POST">
    <?php foreach ($categories as $cat => $perms): ?>
        <div class="permission-card">
            <div class="category-title">
                <?php echo $category_names[$cat] ?? $cat; ?>
            </div>
            <div class="permissions-grid">
                <?php foreach ($perms as $perm): ?>
                    <div class="permission-item">
                        <input type="checkbox" name="permissions[]" value="<?php echo $perm['id']; ?>" 
                            id="perm_<?php echo $perm['id']; ?>"
                            <?php echo in_array($perm['id'], $current) ? 'checked' : ''; ?>
                            <?php echo $role['name'] == 'super_admin' ? 'disabled' : ''; ?>>
                        <label for="perm_<?php echo $perm['id']; ?>">
                            <?php 
                            $perm_icon = match($perm['name']) {
                                'view_invoices' => '👁️',
                                'create_invoice' => '➕',
                                'edit_invoice' => '✏️',
                                'delete_invoice' => '🗑️',
                                'view_waybills' => '👁️',
                                'create_waybill' => '➕',
                                'edit_waybill' => '✏️',
                                'delete_waybill' => '🗑️',
                                'view_tax' => '👁️',
                                'create_tax' => '➕',
                                'manage_users' => '👥',
                                'manage_roles' => '🔐',
                                default => '📌'
                            };
                            echo $perm_icon . ' ' . htmlspecialchars($perm['display_name']);
                            ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    
    <?php if ($role['name'] != 'super_admin'): ?>
        <div style="text-align: left; margin-top: 20px;">
            <button type="submit" name="save_permissions" class="btn-save">
                <i class="fas fa-save"></i> ذخیره دسترسی‌ها
            </button>
        </div>
    <?php endif; ?>
</form>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>