<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role_ids = $_SESSION['user_role_ids'] ?? []; // نقش‌های کاربر (بخش‌ها)

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

// شرط نمایش فاکتورها بر اساس دسترسی کاربر
$sql = "
    SELECT d.*, 
           c.name as company_name,
           v.name as vendor_name,
           u.full_name as creator_name,
           holder_dep.name as holder_department_name,
           holder_user.full_name as holder_user_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
    LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
    WHERE d.type = 'invoice'
    AND (
        d.created_by = ?
        OR d.current_holder_user_id = ?
        OR d.current_holder_department_id IN (" . implode(',', array_fill(0, count($user_role_ids), '?')) . ")
    )
    ORDER BY d.created_at DESC
";

$params = array_merge([$user_id, $user_id], $user_role_ids);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$page_title = 'همه فاکتورها';
ob_start();
?>

<div class="page-title"><h1>همه فاکتورها</h1></div>

<div class="card">
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>شماره</th>
                    <th>عنوان</th>
                    <th>شرکت</th>
                    <th>فروشنده</th>
                    <th>مبلغ (تومان)</th>
                    <th>تاریخ</th>
                    <th>وضعیت</th>
                    <th>در دست</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="9" style="text-align:center;">هیچ فاکتوری یافت نشد</td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($inv['document_number']); ?></td>
                            <td><?php echo htmlspecialchars($inv['title']); ?></td>
                            <td><?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></td>
                            <td><?php echo number_format($inv['amount']); ?> تومان</td>
                            <td><?php echo jdate('Y/m/d', strtotime($inv['created_at'])); ?></td>
                            <td>
                                <?php
                                $status_map = [
                                    'draft' => 'پیش‌نویس',
                                    'forwarded' => 'ارسال شده',
                                    'viewed' => 'مشاهده شده',
                                    'under_review' => 'در حال بررسی',
                                    'approved' => 'تایید شده',
                                    'rejected' => 'رد شده',
                                    'completed' => 'بسته شده'
                                ];
                                echo $status_map[$inv['status']] ?? $inv['status'];
                                ?>
                            </td>
                            <td>
                                <?php
                                if ($inv['holder_user_name']) {
                                    echo htmlspecialchars($inv['holder_user_name']) . ' (شخص)';
                                } elseif ($inv['holder_department_name']) {
                                    echo htmlspecialchars($inv['holder_department_name']) . ' (بخش)';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="btn-icon">مشاهده</a>
                                <?php if ($inv['status'] == 'draft' && $inv['created_by'] == $user_id): ?>
                                    <a href="invoice-edit.php?id=<?php echo $inv['id']; ?>">ویرایش</a>
                                    <a href="invoice-delete.php?id=<?php echo $inv['id']; ?>" onclick="return confirm('حذف شود؟')">حذف</a>
                                <?php endif; ?>
                            </td>
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