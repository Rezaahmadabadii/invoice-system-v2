<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
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

$stmt = $pdo->prepare("
    SELECT d.*, c.name as company_name, v.name as vendor_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    WHERE d.type = 'invoice' AND d.status = 'approved'
    ORDER BY d.created_at DESC
");
$stmt->execute();
$invoices = $stmt->fetchAll();

$page_title = 'فاکتورهای تایید شده';
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">✅ فاکتورهای تایید شده</h1>
    <a href="inbox.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<div style="background: white; border-radius: 10px; padding: 20px;">
    <?php if (empty($invoices)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-file-invoice" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
            <p>هیچ فاکتور تایید شده‌ای یافت نشد</p>
        </div>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5;">
                    <th style="padding: 10px;">شماره</th>
                    <th style="padding: 10px;">عنوان</th>
                    <th style="padding: 10px;">شرکت</th>
                    <th style="padding: 10px;">فروشنده</th>
                    <th style="padding: 10px;">تاریخ</th>
                    <th style="padding: 10px;">مبلغ</th>
                    <th style="padding: 10px;">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px;"><?php echo $inv['document_number']; ?></td>
                    <td style="padding: 10px;"><?php echo $inv['title']; ?></td>
                    <td style="padding: 10px;"><?php echo $inv['company_name'] ?? '-'; ?></td>
                    <td style="padding: 10px;"><?php echo $inv['vendor_name'] ?? '-'; ?></td>
                    <td style="padding: 10px;"><?php echo jdate('Y/m/d', strtotime($inv['created_at'])); ?></td>
                    <td style="padding: 10px;"><?php echo number_format($inv['amount']); ?> تومان</td>
                    <td style="padding: 10px;">
                        <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" style="color: #3498db; text-decoration: none;">مشاهده</a>
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