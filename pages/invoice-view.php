<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    header('Location: inbox.php');
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

// دریافت اطلاعات فاکتور همراه با دارنده فعلی
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name, 
           v.name as vendor_name,
           dep_from.name as holder_department_name,
           u_from.full_name as holder_user_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN roles dep_from ON d.current_holder_department_id = dep_from.id
    LEFT JOIN users u_from ON d.current_holder_user_id = u_from.id
    WHERE d.id = ? AND d.type = 'invoice'
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

// نقش‌های کاربر فعلی
$user_roles = $_SESSION['user_roles'] ?? [];
$can_forward = false;
$can_approve = false;

// اگر کاربر دارنده فعلی است (بخش یا شخص) یا ادمین باشد
$is_holder = ($invoice['current_holder_user_id'] == $_SESSION['user_id']) ||
             ($invoice['current_holder_department_id'] && in_array($invoice['current_holder_department_id'], $_SESSION['user_role_ids'] ?? []));
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);

if ($is_holder || $is_admin) {
    $can_forward = true;
    $can_approve = in_array('approve_invoice', $_SESSION['permissions'] ?? []) || $is_admin;
}

// دریافت تاریخچه ارجاع
$history_stmt = $pdo->prepare("
    SELECT fh.*, 
           u_from.full_name as from_name,
           u_to.full_name as to_name,
           r_to.name as to_department_name
    FROM forwarding_history fh
    LEFT JOIN users u_from ON fh.from_user_id = u_from.id
    LEFT JOIN users u_to ON fh.to_user_id = u_to.id
    LEFT JOIN roles r_to ON fh.to_department_id = r_to.id
    WHERE fh.document_id = ?
    ORDER BY fh.created_at ASC
");
$history_stmt->execute([$id]);
$history = $history_stmt->fetchAll();

// دریافت لیست بخش‌ها و کاربران برای ارجاع
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

// پردازش اقدامات (ارجاع، مشاهده، بررسی، تایید، رد)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if ($action == 'forward') {
        $to_department = $_POST['to_department'] ?? '';
        $to_user = $_POST['to_user'] ?? '';
        if (empty($to_department) && empty($to_user)) {
            $error = 'حداقل یکی از فیلدهای ارجاع به بخش یا شخص را انتخاب کنید';
        } else {
            // به‌روزرسانی دارنده فعلی
            $update = $pdo->prepare("UPDATE documents SET status = 'forwarded', current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
            $update->execute([$to_department ?: null, $to_user ?: null, $id]);
            
            // ثبت در تاریخچه
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $to_department ?: null, $to_user ?: null, $notes]);
            
            $success = 'سند با موفقیت ارجاع شد.';
        }
    } elseif ($action == 'view') {
        // فقط ثبت مشاهده
        $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'view', ?)");
        $insert->execute([$id, $_SESSION['user_id'], $notes]);
        $success = 'مشاهده ثبت شد.';
    } elseif ($action == 'review') {
        $update = $pdo->prepare("UPDATE documents SET status = 'under_review' WHERE id = ?");
        $update->execute([$id]);
        $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'review', ?)");
        $insert->execute([$id, $_SESSION['user_id'], $notes]);
        $success = 'وضعیت سند به "در حال بررسی" تغییر کرد.';
    } elseif ($action == 'approve') {
        $update = $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = ?");
        $update->execute([$id]);
        $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
        $insert->execute([$id, $_SESSION['user_id'], $notes]);
        $success = 'سند تأیید نهایی شد.';
    } elseif ($action == 'reject') {
        if (empty($notes)) {
            $error = 'لطفاً دلیل رد را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $update->execute([$notes, $id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'reject', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'سند رد شد.';
        }
    }
}

$page_title = 'مشاهده فاکتور';
ob_start();
?>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>مشاهده فاکتور <?php echo htmlspecialchars($invoice['document_number']); ?></h1>
    <div>
        <a href="inbox.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (isset($success)): ?>
    <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>

<!-- اطلاعات اصلی فاکتور -->
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px;">
        <div><small>شماره فاکتور</small><div><strong><?php echo $invoice['document_number']; ?></strong></div></div>
        <div><small>عنوان</small><div><?php echo htmlspecialchars($invoice['title']); ?></div></div>
        <div><small>شرکت</small><div><?php echo htmlspecialchars($invoice['company_name'] ?? '-'); ?></div></div>
        <div><small>فروشنده</small><div><?php echo htmlspecialchars($invoice['vendor_name'] ?? '-'); ?></div></div>
        <div><small>تاریخ فاکتور</small><div><?php echo htmlspecialchars($invoice['document_date']); ?></div></div>
        <div><small>تاریخ ثبت</small><div><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></div></div>
        <div><small>مبلغ</small><div><?php echo number_format($invoice['amount']); ?> تومان</div></div>
        <div><small>وضعیت</small>
            <div>
                <?php
                $status_text = [
                    'draft' => 'پیش‌نویس',
                    'forwarded' => 'ارسال شده',
                    'viewed' => 'مشاهده شده',
                    'under_review' => 'در حال بررسی',
                    'approved' => 'تایید شده',
                    'rejected' => 'رد شده',
                    'completed' => 'بسته شده'
                ];
                echo $status_text[$invoice['status']] ?? $invoice['status'];
                ?>
            </div>
        </div>
    </div>
    <?php if ($invoice['description']): ?>
        <div><small>توضیحات</small><div><?php echo nl2br(htmlspecialchars($invoice['description'])); ?></div></div>
    <?php endif; ?>
    <?php if ($invoice['file_path']): ?>
        <div><small>فایل ضمیمه</small><div><a href="/invoice-system-v2/<?php echo $invoice['file_path']; ?>" target="_blank">دانلود فایل</a></div></div>
    <?php endif; ?>
</div>

<!-- وضعیت دارنده فعلی -->
<div style="background: #e8f4f8; border-radius: 10px; padding: 15px; margin-bottom: 20px;">
    <strong>در دست:</strong>
    <?php if ($invoice['holder_user_name']): ?>
        <?php echo htmlspecialchars($invoice['holder_user_name']); ?> (شخص)
    <?php elseif ($invoice['holder_department_name']): ?>
        <?php echo htmlspecialchars($invoice['holder_department_name']); ?> (بخش)
    <?php else: ?>
        هیچ
    <?php endif; ?>
</div>

<!-- فرم اقدامات (فقط برای دارنده فعلی یا ادمین) -->
<?php if ($can_forward): ?>
<div style="background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;">
    <h3>اقدامات روی فاکتور</h3>
    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <label>انتخاب اقدام:</label>
                <select name="action" id="actionSelect" required style="width:100%; padding:10px;">
                    <option value="view">مشاهده شد (ثبت مشاهده)</option>
                    <option value="review">بررسی شد</option>
                    <option value="forward">ارجاع به بخش/شخص</option>
                    <option value="approve">تایید نهایی</option>
                    <option value="reject">رد با ذکر دلیل</option>
                </select>
            </div>
            <div id="forwardFields" style="display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label>ارجاع به بخش</label>
                        <select name="to_department" style="width:100%; padding:10px;">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>ارجاع به شخص</label>
                        <select name="to_user" style="width:100%; padding:10px;">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div>
            <label>توضیحات (در صورت نیاز)</label>
            <textarea name="notes" rows="3" style="width:100%; padding:10px;"></textarea>
        </div>
        <button type="submit" style="margin-top:15px; background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">ثبت اقدام</button>
    </form>
</div>

<script>
    document.getElementById('actionSelect').addEventListener('change', function() {
        const forwardDiv = document.getElementById('forwardFields');
        if (this.value === 'forward') {
            forwardDiv.style.display = 'block';
        } else {
            forwardDiv.style.display = 'none';
        }
    });
</script>
<?php endif; ?>

<!-- تاریخچه ارجاع -->
<div style="background: white; border-radius: 10px; padding: 20px;">
    <h3>تاریخچه ارجاع</h3>
    <?php if (empty($history)): ?>
        <p>هیچ ارجاعی ثبت نشده است.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="background:#f5f5f5;">
                    <th style="padding:8px;">زمان</th>
                    <th style="padding:8px;">از</th>
                    <th style="padding:8px;">به</th>
                    <th style="padding:8px;">اقدام</th>
                    <th style="padding:8px;">توضیحات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:8px;"><?php echo jdate('Y/m/d H:i', strtotime($h['created_at'])); ?></td>
                    <td style="padding:8px;"><?php echo htmlspecialchars($h['from_name']); ?></td>
                    <td style="padding:8px;">
                        <?php echo htmlspecialchars($h['to_name'] ?? $h['to_department_name'] ?? '-'); ?>
                    </td>
                    <td style="padding:8px;">
                        <?php
                        $action_text = [
                            'forward' => 'ارجاع',
                            'view' => 'مشاهده',
                            'review' => 'بررسی',
                            'approve' => 'تایید',
                            'reject' => 'رد',
                            'complete' => 'بسته'
                        ];
                        echo $action_text[$h['action']] ?? $h['action'];
                        ?>
                    </td>
                    <td style="padding:8px;"><?php echo nl2br(htmlspecialchars($h['notes'] ?? '')); ?></td>
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