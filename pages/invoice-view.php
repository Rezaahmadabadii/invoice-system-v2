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

// دریافت اطلاعات فاکتور همراه با دارنده فعلی و ایجادکننده
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name, 
           v.name as vendor_name,
           dep_from.name as holder_department_name,
           u_from.full_name as holder_user_name,
           creator.full_name as creator_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN roles dep_from ON d.current_holder_department_id = dep_from.id
    LEFT JOIN users u_from ON d.current_holder_user_id = u_from.id
    LEFT JOIN users creator ON d.created_by = creator.id
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
$user_id = $_SESSION['user_id'];

// تشخیص وضعیت کاربر نسبت به سند
$is_creator = ($invoice['created_by'] == $user_id);
$is_holder_user = ($invoice['current_holder_user_id'] == $user_id);
$is_holder_department = ($invoice['current_holder_department_id'] && in_array($invoice['current_holder_department_id'], $_SESSION['user_role_ids'] ?? []));
$is_holder = $is_holder_user || $is_holder_department;
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);

// قانون نهایی: فقط گیرنده فعلی می‌تواند اقدام کند (ایجادکننده حتی اگر ادمین باشد هم نمی‌تواند اقدام کند مگر اینکه گیرنده باشد)
// اما ادمین می‌تواند تایید نهایی کند حتی اگر گیرنده نباشد
$can_forward_action = $is_holder && !$is_creator; // فقط گیرنده (که ایجادکننده نباشد)
$can_approve_action = $is_admin && !$is_creator; // ادمین (که ایجادکننده نباشد) می‌تواند تایید نهایی کند

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

// پردازش اقدامات
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    
    // بررسی دسترسی بر اساس نقش
    if ($action == 'forward') {
        // فقط گیرنده فعلی (که ایجادکننده نباشد) می‌تواند ارجاع دهد
        if (!$can_forward_action) {
            $error = 'شما مجاز به ارجاع این فاکتور نیستید. فقط شخص/بخش گیرنده می‌تواند ارجاع دهد.';
        } else {
            $to_department = $_POST['to_department'] ?? '';
            $to_user = $_POST['to_user'] ?? '';
            
            // ارجاع اجباری به بخش یا شخص + توضیحات اجباری
            if (empty($to_department) && empty($to_user)) {
                $error = 'لطفاً حداقل یکی از فیلدهای ارجاع (بخش یا شخص) را انتخاب کنید';
            } elseif (empty($notes)) {
                $error = 'لطفاً توضیحات را وارد کنید (دلیل ارجاع)';
            } else {
                // به‌روزرسانی دارنده فعلی
                $update = $pdo->prepare("UPDATE documents SET status = 'forwarded', current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
                $update->execute([$to_department ?: null, $to_user ?: null, $id]);
                
                // ثبت در تاریخچه
                $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
                $insert->execute([$id, $_SESSION['user_id'], $to_department ?: null, $to_user ?: null, $notes]);
                
                $success = 'سند با موفقیت ارجاع شد.';
            }
        }
    } elseif ($action == 'approve') {
        // فقط ادمین (که ایجادکننده نباشد) می‌تواند تایید نهایی کند
        if (!$can_approve_action) {
            $error = 'شما مجاز به تایید نهایی این فاکتور نیستید. فقط ادمین می‌تواند تایید نهایی کند.';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = ?");
            $update->execute([$id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'سند تأیید نهایی شد.';
        }
    } else {
        $error = 'اقدام نامعتبر است. فقط ارجاع (برای گیرنده) یا تایید نهایی (برای ادمین) مجاز است.';
    }
}

$page_title = 'مشاهده فاکتور';
ob_start();
?>

<style>
    .info-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }
    .info-item small {
        display: block;
        color: #7f8c8d;
        font-size: 11px;
        margin-bottom: 5px;
    }
    .info-item strong {
        font-size: 14px;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
    }
    .status-forwarded { background: #f39c12; color: white; }
    .status-approved { background: #27ae60; color: white; }
    .status-rejected { background: #e74c3c; color: white; }
    .status-under_review { background: #3498db; color: white; }
    .holder-box {
        background: #e8f4f8;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        border-right: 4px solid #3498db;
    }
    .action-form {
        background: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border: 1px solid #e0e0e0;
    }
    .history-table {
        width: 100%;
        border-collapse: collapse;
    }
    .history-table th, .history-table td {
        padding: 12px;
        text-align: right;
        border-bottom: 1px solid #eee;
    }
    .history-table th {
        background: #f5f5f5;
        font-weight: bold;
    }
    .btn-submit {
        background: #27ae60;
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 16px;
    }
    .btn-submit:hover {
        background: #219a52;
    }
    .alert-error {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .alert-success {
        background: #d4edda;
        color: #155724;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .info-note {
        background: #fef9e6;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
        text-align: center;
        color: #e67e22;
    }
    .required-star {
        color: red;
    }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>🧾 مشاهده فاکتور <?php echo htmlspecialchars($invoice['document_number']); ?></h1>
    <a href="inbox.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert-error"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- اطلاعات اصلی فاکتور -->
<div class="info-card">
    <div class="info-grid">
        <div class="info-item"><small>شماره فاکتور</small><strong><?php echo $invoice['document_number']; ?></strong></div>
        <div class="info-item"><small>عنوان</small><strong><?php echo htmlspecialchars($invoice['title']); ?></strong></div>
        <div class="info-item"><small>شرکت</small><strong><?php echo htmlspecialchars($invoice['company_name'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>فروشنده</small><strong><?php echo htmlspecialchars($invoice['vendor_name'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>تاریخ فاکتور</small><strong><?php echo htmlspecialchars($invoice['document_date']); ?></strong></div>
        <div class="info-item"><small>تاریخ ثبت</small><strong><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></strong></div>
        <div class="info-item"><small>مبلغ</small><strong><?php echo number_format($invoice['amount']); ?> تومان</strong></div>
        <div class="info-item"><small>وضعیت</small>
            <strong>
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
                $status_class = 'status-' . ($invoice['status'] ?? 'draft');
                echo '<span class="status-badge ' . $status_class . '">' . ($status_text[$invoice['status']] ?? $invoice['status']) . '</span>';
                ?>
            </strong>
        </div>
    </div>
    <?php if ($invoice['description']): ?>
        <div style="margin-top: 15px;"><small>توضیحات</small><div><?php echo nl2br(htmlspecialchars($invoice['description'])); ?></div></div>
    <?php endif; ?>
    <?php if ($invoice['file_path']): ?>
        <div style="margin-top: 15px;"><small>فایل ضمیمه</small><div><a href="/invoice-system-v2/<?php echo $invoice['file_path']; ?>" target="_blank">📎 دانلود فایل</a></div></div>
    <?php endif; ?>
</div>

<!-- وضعیت دارنده فعلی -->
<div class="holder-box">
    <strong>📍 در دست:</strong>
    <?php if ($invoice['holder_user_name']): ?>
        <?php echo htmlspecialchars($invoice['holder_user_name']); ?>
        <?php if ($is_creator): ?>
            <span style="background: #95a5a6; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-right: 10px;">(ایجادکننده)</span>
        <?php endif; ?>
    <?php elseif ($invoice['holder_department_name']): ?>
        <?php echo htmlspecialchars($invoice['holder_department_name']); ?> (بخش)
    <?php else: ?>
        هیچ
    <?php endif; ?>
    
    <?php if ($is_creator && !$is_holder): ?>
        <span style="background: #e74c3c; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-right: 10px;">⚠️ شما ایجادکننده هستید، نمی‌توانید اقدامی انجام دهید</span>
    <?php endif; ?>
</div>

<!-- پیام برای ایجادکننده -->
<?php if ($is_creator && !$is_holder): ?>
    <div class="info-note">
        🔒 شما ایجادکننده این فاکتور هستید. پس از ارجاع، فقط می‌توانید آن را مشاهده کنید. اقدامات بعدی توسط گیرنده انجام خواهد شد.
    </div>
<?php endif; ?>

<!-- فرم اقدامات (فقط برای گیرنده فعلی) -->
<?php if ($can_forward_action): ?>
<div class="action-form">
    <h3>⚡ ارجاع فاکتور به بخش/شخص دیگر</h3>
    <form method="POST">
        <input type="hidden" name="action" value="forward">
        
        <div style="margin-bottom: 20px;">
            <label>📋 ارجاع به بخش <span class="required-star">*</span></label>
            <select name="to_department" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ddd; margin-top:5px;">
                <option value="">--- انتخاب کنید ---</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label>👤 ارجاع به شخص <span class="required-star">*</span></label>
            <select name="to_user" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ddd; margin-top:5px;">
                <option value="">--- انتخاب کنید ---</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label>📝 توضیحات (دلیل ارجاع) <span class="required-star">*</span></label>
            <textarea name="notes" rows="3" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ddd; margin-top:5px;" required></textarea>
        </div>
        
        <button type="submit" class="btn-submit">🔄 ارجاع فاکتور</button>
    </form>
</div>
<?php endif; ?>

<!-- فرم تایید نهایی (فقط برای ادمین) -->
<?php if ($can_approve_action && !$can_forward_action): ?>
<div class="action-form">
    <h3>✅ تایید نهایی فاکتور (ادمین)</h3>
    <form method="POST">
        <input type="hidden" name="action" value="approve">
        
        <div style="margin-bottom: 20px;">
            <label>📝 توضیحات (اختیاری)</label>
            <textarea name="notes" rows="3" style="width:100%; padding:10px; border-radius:5px; border:1px solid #ddd; margin-top:5px;"></textarea>
        </div>
        
        <button type="submit" class="btn-submit">✅ تایید نهایی</button>
    </form>
</div>
<?php endif; ?>

<!-- تاریخچه ارجاع -->
<div class="info-card">
    <h3 style="margin-bottom: 15px;">📜 تاریخچه ارجاع و اقدامات</h3>
    <?php if (empty($history)): ?>
        <p style="color: #7f8c8d; text-align: center;">هیچ اقدامی ثبت نشده است.</p>
    <?php else: ?>
        <table class="history-table">
            <thead>
                <tr>
                    <th>زمان</th>
                    <th>از</th>
                    <th>به</th>
                    <th>اقدام</th>
                    <th>توضیحات</th>
                </td>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo jdate('Y/m/d H:i', strtotime($h['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($h['from_name']); ?></td>
                    <td>
                        <?php 
                        if ($h['to_name']) {
                            echo '<span style="color:#27ae60;">👤 ' . htmlspecialchars($h['to_name']) . '</span>';
                        } elseif ($h['to_department_name']) {
                            echo '<span style="color:#3498db;">🏢 ' . htmlspecialchars($h['to_department_name']) . '</span>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        $action_icon = [
                            'forward' => '🔄',
                            'view' => '👁️',
                            'review' => '📝',
                            'approve' => '✅',
                            'reject' => '❌',
                            'complete' => '✔️'
                        ];
                        $action_text = [
                            'forward' => 'ارجاع',
                            'view' => 'مشاهده',
                            'review' => 'بررسی',
                            'approve' => 'تایید',
                            'reject' => 'رد',
                            'complete' => 'بسته'
                        ];
                        $icon = $action_icon[$h['action']] ?? '📌';
                        $text = $action_text[$h['action']] ?? $h['action'];
                        echo $icon . ' ' . $text;
                        ?>
                    </td>
                    <td style="max-width: 250px; word-wrap: break-word;"><?php echo nl2br(htmlspecialchars($h['notes'] ?? '-')); ?></td>
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