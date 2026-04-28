<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
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

// دریافت ارجاع‌ها برای نقش‌های کاربر
$user_role_ids = $_SESSION['user_role_ids'] ?? [];

$placeholders = implode(',', array_fill(0, count($user_role_ids), '?'));

$sql = "
    SELECT f.*, 
           d.document_number, d.title, d.type, d.status as doc_status,
           u.full_name as from_name,
           r.name as role_name,
           TIMESTAMPDIFF(HOUR, NOW(), f.deadline) as hours_left,
           CASE 
               WHEN f.status = 'pending' AND f.deadline < NOW() THEN 'overdue'
               WHEN f.status = 'pending' THEN 'pending'
               ELSE f.status
           END as real_status
    FROM forwarding f
    JOIN documents d ON f.document_id = d.id
    JOIN users u ON f.from_user = u.id
    JOIN roles r ON f.to_role = r.id
    WHERE f.to_role IN ($placeholders)
    ORDER BY 
        CASE 
            WHEN f.status = 'pending' AND f.deadline < NOW() THEN 0
            WHEN f.status = 'pending' THEN 1
            ELSE 2
        END,
        f.deadline ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($user_role_ids);
$forwards = $stmt->fetchAll();

// ثبت مشاهده
if (isset($_GET['view']) && isset($_GET['id'])) {
    $forward_id = $_GET['id'];
    $stmt = $pdo->prepare("UPDATE forwarding SET status = 'viewed', viewed_at = NOW() WHERE id = ? AND status = 'pending'");
    $stmt->execute([$forward_id]);
    header('Location: forward-list.php');
    exit;
}

$page_title = 'ارجاع‌های دریافتی';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">ارجاع‌های دریافتی</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <?php if (empty($forwards)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px; display: block;"></i>
            <p>هیچ ارجاع جدیدی ندارید</p>
        </div>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5;">
                    <th style="padding: 12px; text-align: right;">وضعیت</th>
                    <th style="padding: 12px; text-align: right;">شماره سند</th>
                    <th style="padding: 12px; text-align: right;">عنوان</th>
                    <th style="padding: 12px; text-align: right;">نوع</th>
                    <th style="padding: 12px; text-align: right;">ارجاع‌دهنده</th>
                    <th style="padding: 12px; text-align: right;">نقش</th>
                    <th style="padding: 12px; text-align: right;">مهلت</th>
                    <th style="padding: 12px; text-align: right;">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forwards as $f): ?>
                <tr style="border-bottom: 1px solid #eee; <?php echo $f['real_status'] == 'overdue' ? 'background: #fff3cd;' : ''; ?>">
                    <td style="padding: 12px;">
                        <?php if ($f['real_status'] == 'pending'): ?>
                            <span style="background: #f39c12; color: white; padding: 3px 8px; border-radius: 3px;">در انتظار</span>
                        <?php elseif ($f['real_status'] == 'overdue'): ?>
                            <span style="background: #e74c3c; color: white; padding: 3px 8px; border-radius: 3px;">تأخیر</span>
                        <?php elseif ($f['real_status'] == 'viewed'): ?>
                            <span style="background: #3498db; color: white; padding: 3px 8px; border-radius: 3px;">مشاهده شده</span>
                        <?php elseif ($f['real_status'] == 'forwarded'): ?>
                            <span style="background: #9b59b6; color: white; padding: 3px 8px; border-radius: 3px;">ارجاع شده</span>
                        <?php elseif ($f['real_status'] == 'approved'): ?>
                            <span style="background: #27ae60; color: white; padding: 3px 8px; border-radius: 3px;">تأیید شده</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($f['document_number']); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($f['title']); ?></td>
                    <td style="padding: 12px;">
                        <?php
                        $type_names = [
                            'invoice' => 'فاکتور',
                            'waybill' => 'بارنامه',
                            'tax' => 'مودیان'
                        ];
                        echo $type_names[$f['type']] ?? $f['type'];
                        ?>
                    </td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($f['from_name']); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($f['role_name']); ?></td>
                    <td style="padding: 12px;">
                        <?php echo jdate('Y/m/d', strtotime($f['deadline'])); ?>
                        <?php if ($f['hours_left'] < 0): ?>
                            <br><small style="color: #e74c3c;"><?php echo abs($f['hours_left']); ?> ساعت قبل</small>
                        <?php elseif ($f['hours_left'] < 24): ?>
                            <br><small style="color: #f39c12;"><?php echo $f['hours_left']; ?> ساعت باقی</small>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px;">
                        <?php if ($f['status'] == 'pending'): ?>
                            <a href="?view&id=<?php echo $f['id']; ?>" style="background: #3498db; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none;">مشاهده</a>
                        <?php endif; ?>
                        <a href="invoice-view.php?id=<?php echo $f['document_id']; ?>" style="color: #3498db; text-decoration: none; margin-right: 5px;">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>