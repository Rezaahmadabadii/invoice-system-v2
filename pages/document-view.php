<?php
session_start();

// بررسی لاگین بودن
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

$document_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'];

// دریافت اطلاعات سند
$stmt = $pdo->prepare("
    SELECT d.*, 
           u.full_name as creator_name,
           u.username as creator_username,
           dep.name as department_name,
           dep.id as department_id
    FROM documents d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE d.id = ?
");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    header('Location: inbox.php');
    exit;
}

// بررسی دسترسی
$has_access = ($document['department_id'] == $department_id || 
               $document['created_by'] == $user_id);

if (!$has_access) {
    // بررسی دسترسی مستقیم
    $access = $pdo->prepare("SELECT id FROM document_access WHERE document_id = ? AND user_id = ?");
    $access->execute([$document_id, $user_id]);
    $has_access = $access->fetch();
}

if (!$has_access) {
    http_response_code(403);
    die("شما به این سند دسترسی ندارید");
}

// دریافت تاریخچه تغییرات
$history = $pdo->prepare("
    SELECT h.*, u.full_name as user_name
    FROM document_history h
    LEFT JOIN users u ON h.user_id = u.id
    WHERE h.document_id = ?
    ORDER BY h.created_at DESC
");
$history->execute([$document_id]);
$history_items = $history->fetchAll();

// دریافت دسترسی‌ها
$access_list = $pdo->prepare("
    SELECT a.*, u.full_name as user_name, u.username
    FROM document_access a
    JOIN users u ON a.user_id = u.id
    WHERE a.document_id = ?
");
$access_list->execute([$document_id]);
$accesses = $access_list->fetchAll();

$page_title = 'مشاهده سند - ' . $document['document_number'];
ob_start();
?>

<div class="dashboard-header" style="justify-content: space-between;">
    <h1><i class="fas fa-file-alt"></i> مشاهده سند</h1>
    <div>
        <a href="inbox.php" class="btn-link" style="margin-left:15px;"><i class="fas fa-arrow-right"></i> بازگشت</a>
        <?php if ($document['created_by'] == $user_id && $document['status'] == 'draft'): ?>
            <a href="document-edit.php?id=<?php echo $document_id; ?>" class="btn-link"><i class="fas fa-edit"></i> ویرایش</a>
        <?php endif; ?>
    </div>
</div>

<!-- اطلاعات اصلی سند -->
<div class="card" style="margin-bottom:20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="color:#00b4a0;"><?php echo htmlspecialchars($document['title']); ?></h2>
        <span class="badge badge-<?php 
            echo $document['status'] == 'approved' ? 'success' : 
                ($document['status'] == 'rejected' ? 'danger' : 
                ($document['status'] == 'submitted' ? 'warning' : 'secondary')); ?>">
            <?php 
            $statuses = ['draft'=>'پیش‌نویس', 'submitted'=>'ارسال شده', 'approved'=>'تایید شده', 'rejected'=>'رد شده'];
            echo $statuses[$document['status']] ?? $document['status'];
            ?>
        </span>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
        <div>
            <small style="color:#7f8c8d;">شماره سند</small>
            <div style="font-weight:bold;"><?php echo $document['document_number']; ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">نوع سند</small>
            <div>
                <?php 
                $types = [
                    'invoice_with_contract' => '📄 فاکتور با قرارداد',
                    'invoice_without_contract' => '📄 فاکتور بدون قرارداد',
                    'waybill' => '📦 بارنامه',
                    'tax' => '🏛️ سامانه مودیان'
                ];
                echo $types[$document['type']] ?? $document['type'];
                ?>
            </div>
        </div>
        <div>
            <small style="color:#7f8c8d;">تاریخ ایجاد</small>
            <div><?php echo jdate('Y/m/d', strtotime($document['created_at'])); ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">تاریخ سند</small>
            <div><?php echo jdate('Y/m/d', strtotime($document['document_date'])); ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">ایجاد کننده</small>
            <div><?php echo htmlspecialchars($document['creator_name']); ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">بخش</small>
            <div><?php echo htmlspecialchars($document['department_name']); ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">مبلغ</small>
            <div><?php echo $document['amount'] ? number_format($document['amount']) . ' تومان' : '-'; ?></div>
        </div>
    </div>
    
    <?php if (!empty($document['description'])): ?>
    <div style="margin-top:20px; padding-top:20px; border-top:1px solid rgba(255,255,255,0.1);">
        <small style="color:#7f8c8d;">توضیحات</small>
        <div style="margin-top:10px;"><?php echo nl2br(htmlspecialchars($document['description'])); ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- فیلدهای اختصاصی بر اساس نوع سند -->
<?php if ($document['type'] == 'invoice_with_contract' || $document['type'] == 'invoice_without_contract'): ?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="color:#00b4a0; margin-bottom:15px;">اطلاعات فاکتور</h3>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
        <?php if ($document['type'] == 'invoice_with_contract'): ?>
        <div>
            <small style="color:#7f8c8d;">شماره قرارداد</small>
            <div><?php echo $document['contract_number'] ?? '-'; ?></div>
        </div>
        <?php endif; ?>
        <div>
            <small style="color:#7f8c8d;">نام پیمانکار</small>
            <div><?php echo htmlspecialchars($document['vendor_name'] ?? '-'); ?></div>
        </div>
        <div>
            <small style="color:#7f8c8d;">کد اقتصادی</small>
            <div><?php echo $document['vendor_economic_code'] ?? '-'; ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($document['type'] == 'waybill'): ?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="color:#00b4a0; margin-bottom:15px;">اطلاعات بارنامه</h3>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
        <div><small>شماره بارنامه:</small> <?php echo $document['waybill_number'] ?? '-'; ?></div>
        <div><small>فرستنده:</small> <?php echo htmlspecialchars($document['sender_name'] ?? '-'); ?></div>
        <div><small>گیرنده:</small> <?php echo htmlspecialchars($document['receiver_name'] ?? '-'); ?></div>
        <div><small>محموله:</small> <?php echo htmlspecialchars($document['cargo_description'] ?? '-'); ?></div>
        <div><small>مبدا:</small> <?php echo htmlspecialchars($document['loading_origin'] ?? '-'); ?></div>
        <div><small>مقصد:</small> <?php echo htmlspecialchars($document['discharge_destination'] ?? '-'); ?></div>
        <div><small>راننده اول:</small> <?php echo htmlspecialchars($document['driver1_name'] ?? '-'); ?></div>
        <div><small>راننده دوم:</small> <?php echo htmlspecialchars($document['driver2_name'] ?? '-'); ?></div>
        <div><small>پلاک:</small> <?php echo $document['vehicle_plate'] ?? '-'; ?></div>
        <div><small>تعداد:</small> <?php echo $document['quantity'] ?? '-'; ?></div>
        <div><small>وزن:</small> <?php echo $document['weight'] ?? '-'; ?></div>
        <div><small>مسئول حمل:</small> <?php echo htmlspecialchars($document['carrier_responsible'] ?? '-'); ?></div>
        <div><small>بیمه:</small> <?php echo htmlspecialchars($document['insurance_company'] ?? '-'); ?></div>
        <div style="grid-column: span 3;"><small>توضیحات:</small> <?php echo nl2br(htmlspecialchars($document['waybill_notes'] ?? '-')); ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ($document['type'] == 'tax'): ?>
<div class="card" style="margin-bottom:20px;">
    <h3 style="color:#00b4a0; margin-bottom:15px;">اطلاعات سامانه مودیان</h3>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
        <div><small>شناسه مالیاتی:</small> <?php echo $document['tax_id'] ?? '-'; ?></div>
        <div><small>شماره صورتحساب:</small> <?php echo $document['tax_invoice_number'] ?? '-'; ?></div>
        <div><small>وضعیت ارسال:</small> 
            <?php 
            $tax_statuses = ['pending'=>'در انتظار', 'sent'=>'ارسال شده', 'failed'=>'خطا'];
            echo $tax_statuses[$document['tax_status']] ?? $document['tax_status'];
            ?>
        </div>
        <?php if ($document['tax_sent_date']): ?>
        <div><small>تاریخ ارسال:</small> <?php echo jdate('Y/m/d H:i', strtotime($document['tax_sent_date'])); ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- تاریخچه تغییرات -->
<div class="card" style="margin-bottom:20px;">
    <h3 style="color:#00b4a0; margin-bottom:15px;">تاریخچه تغییرات</h3>
    <?php if (empty($history_items)): ?>
        <p style="color:#7f8c8d;">تاریخچه‌ای ثبت نشده است</p>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <?php foreach ($history_items as $item): ?>
            <div style="padding:15px; background:rgba(255,255,255,0.05); border-radius:8px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <span style="font-weight:bold;"><?php echo htmlspecialchars($item['user_name']); ?></span>
                    <span style="color:#7f8c8d; font-size:12px;"><?php echo jdate('Y/m/d H:i', strtotime($item['created_at'])); ?></span>
                </div>
                <div><?php echo htmlspecialchars($item['description']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- دسترسی‌ها (فقط برای سازنده) -->
<?php if ($document['created_by'] == $user_id): ?>
<div class="card">
    <h3 style="color:#00b4a0; margin-bottom:15px;">دسترسی‌ها</h3>
    <?php if (empty($accesses)): ?>
        <p style="color:#7f8c8d;">دسترسی خاصی تعریف نشده است</p>
    <?php else: ?>
        <ul style="list-style:none;">
            <?php foreach ($accesses as $access): ?>
            <li style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.1);">
                <?php echo htmlspecialchars($access['user_name']); ?> (<?php echo $access['username']; ?>) - 
                <?php echo $access['access_type'] == 'view' ? 'فقط مشاهده' : ($access['access_type'] == 'edit' ? 'ویرایش' : 'تایید'); ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// تابع ثبت تغییر وضعیت (در صورت نیاز)
function updateStatus(status, comment = '') {
    if (confirm('آیا از تغییر وضعیت اطمینان دارید؟')) {
        fetch('document-update-status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                document_id: <?php echo $document_id; ?>,
                status: status,
                comment: comment
            })
        }).then(response => response.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('خطا: ' + data.message);
            }
        });
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>