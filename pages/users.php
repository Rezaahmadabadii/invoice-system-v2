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

// دریافت لیست کاربران
$users = $pdo->query("
    SELECT u.*, GROUP_CONCAT(r.name SEPARATOR '، ') as role_names
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll();

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

$page_title = 'مدیریت کاربران';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">مدیریت کاربران</h1>
    <div>
        <a href="register.php" style="background: #27ae60; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; margin-left: 10px;">
            <i class="fas fa-plus"></i> کاربر جدید
        </a>
        <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $message; ?></div>
<?php endif; ?>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 12px; text-align: right;">شناسه</th>
                <th style="padding: 12px; text-align: right;">نام و نام خانوادگی</th>
                <th style="padding: 12px; text-align: right;">نام کاربری</th>
                <th style="padding: 12px; text-align: right;">ایمیل</th>
                <th style="padding: 12px; text-align: right;">شماره تماس</th>
                <th style="padding: 12px; text-align: right;">نقش‌ها</th>
                <th style="padding: 12px; text-align: right;">تاریخ عضویت</th>
                <th style="padding: 12px; text-align: right;">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 12px;"><?php echo $user['id']; ?></td>
                <td style="padding: 12px;"><?php echo htmlspecialchars($user['full_name']); ?></td>
                <td style="padding: 12px;"><?php echo htmlspecialchars($user['username']); ?></td>
                <td style="padding: 12px;"><?php echo htmlspecialchars($user['email']); ?></td>
                <td style="padding: 12px;"><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                <td style="padding: 12px;">
                    <?php 
                    $role_names = explode('، ', $user['role_names'] ?? '');
                    foreach ($role_names as $role):
                        if ($role == 'super_admin'): ?>
                            <span style="background: #e74c3c; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin: 2px;">super_admin</span>
                        <?php elseif ($role == 'admin'): ?>
                            <span style="background: #f39c12; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin: 2px;">admin</span>
                        <?php elseif ($role): ?>
                            <span style="background: #3498db; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; display: inline-block; margin: 2px;"><?php echo $role; ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
                <td style="padding: 12px;"><?php echo jdate('Y/m/d', strtotime($user['created_at'])); ?></td>
                <td style="padding: 12px;">
                    <a href="user-roles.php?user_id=<?php echo $user['id']; ?>" style="color: #3498db; text-decoration: none; margin-left: 10px;" title="مدیریت نقش‌ها">
                        <i class="fas fa-user-tag"></i>
                    </a>
                    <a href="#" style="color: #f39c12; text-decoration: none; margin-left: 10px;" title="ویرایش">
                        <i class="fas fa-edit"></i>
                    </a>
                    <?php if ($user['id'] != $_SESSION['user_id'] && !str_contains($user['role_names'] ?? '', 'super_admin')): ?>
                        <a href="#" onclick="return confirm('آیا از حذف این کاربر اطمینان دارید؟')" style="color: #e74c3c; text-decoration: none;" title="حذف">
                            <i class="fas fa-trash"></i>
                        </a>
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