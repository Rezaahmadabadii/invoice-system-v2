<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('view_logs')) {
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

// فیلترها
$user_filter = $_GET['user'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? jdate('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? jdate('Y-m-d');

// تبدیل تاریخ شمسی به میلادی برای ذخیره در دیتابیس
$gregorian_from = $date_from; // در این مرحله ساده شده
$gregorian_to = $date_to;

// ساخت کوئری
$sql = "
    SELECT a.*, u.username, u.full_name
    FROM activities a
    JOIN users u ON a.user_id = u.id
    WHERE DATE(a.created_at) BETWEEN ? AND ?
";
$params = [$gregorian_from, $gregorian_to];

if ($user_filter) {
    $sql .= " AND a.user_id = ?";
    $params[] = $user_filter;
}
if ($action_filter) {
    $sql .= " AND a.action LIKE ?";
    $params[] = "%$action_filter%";
}

$sql .= " ORDER BY a.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// دریافت لیست کاربران برای فیلتر
$users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name")->fetchAll();

$page_title = 'گزارش فعالیت‌ها';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">📋 گزارش فعالیت‌ها</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<!-- فیلترها -->
<div style="background: white; border-radius: 10px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
    <form method="GET" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; align-items: end;">
        <div>
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #2c3e50;">از تاریخ</label>
            <input type="text" name="date_from" value="<?php echo $date_from; ?>" placeholder="۱۴۰۴/۱۲/۰۱" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        <div>
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #2c3e50;">تا تاریخ</label>
            <input type="text" name="date_to" value="<?php echo $date_to; ?>" placeholder="۱۴۰۴/۱۲/۰۸" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        <div>
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #2c3e50;">کاربر</label>
            <select name="user" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; background: white;">
                <option value="">همه کاربران</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['full_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #2c3e50;">عملیات</label>
            <input type="text" name="action" value="<?php echo htmlspecialchars($action_filter); ?>" placeholder="جستجو..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" style="background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">
                <i class="fas fa-filter"></i> اعمال فیلتر
            </button>
            <a href="logs.php" style="background: #95a5a6; color: white; border: none; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block;">
                <i class="fas fa-times"></i> پاک کردن
            </a>
        </div>
    </form>
</div>

<!-- لیست فعالیت‌ها -->
<div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: linear-gradient(135deg, #3498db, #2980b9); color: white;">
                    <th style="padding: 12px 15px; text-align: right; border-radius: 0 5px 0 0;">تاریخ</th>
                    <th style="padding: 12px 15px; text-align: right;">کاربر</th>
                    <th style="padding: 12px 15px; text-align: right;">عملیات</th>
                    <th style="padding: 12px 15px; text-align: right;">توضیحات</th>
                    <th style="padding: 12px 15px; text-align: right; border-radius: 5px 0 0 0;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" style="padding: 40px; text-align: center; color: #7f8c8d;">
                            <i class="fas fa-history" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                            هیچ فعالیتی یافت نشد
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr style="border-bottom: 1px solid #ecf0f1;">
                        <td style="padding: 12px 15px;"><?php echo jdate('Y/m/d H:i', strtotime($log['created_at'])); ?></td>
                        <td style="padding: 12px 15px;">
                            <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                            <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars($log['username']); ?></small>
                        </td>
                        <td style="padding: 12px 15px;">
                            <?php
                            $action_class = '';
                            $action_color = '#3498db';
                            if (strpos($log['action'], 'delete') !== false) {
                                $action_color = '#e74c3c';
                            } elseif (strpos($log['action'], 'add') !== false || strpos($log['action'], 'create') !== false) {
                                $action_color = '#27ae60';
                            } elseif (strpos($log['action'], 'edit') !== false || strpos($log['action'], 'update') !== false) {
                                $action_color = '#f39c12';
                            } elseif (strpos($log['action'], 'forward') !== false) {
                                $action_color = '#9b59b6';
                            }
                            ?>
                            <span style="background: <?php echo $action_color; ?>; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold;">
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                        </td>
                        <td style="padding: 12px 15px;"><?php echo htmlspecialchars($log['description']); ?></td>
                        <td style="padding: 12px 15px; font-family: monospace; direction: ltr;"><?php echo $log['ip_address']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>