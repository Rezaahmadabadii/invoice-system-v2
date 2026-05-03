<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    header('Location: tax.php');
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
    SELECT d.*, 
           c.name as company_name, 
           c.short_name,
           holder_dep.name as holder_department_name,
           holder_user.full_name as holder_user_name,
           creator.full_name as creator_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
    LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
    LEFT JOIN users creator ON d.created_by = creator.id
    WHERE d.id = ? AND d.type = 'tax'
");
$stmt->execute([$id]);
$tax = $stmt->fetch();

if (!$tax) {
    header('Location: tax.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['user_roles'] ?? [];
$is_creator = ($tax['created_by'] == $user_id);
$is_holder_user = ($tax['current_holder_user_id'] == $user_id);
$is_holder_department = ($tax['current_holder_department_id'] && in_array($tax['current_holder_department_id'], $_SESSION['user_role_ids'] ?? []));
$is_holder = $is_holder_user || $is_holder_department;
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);

$can_forward = $is_holder && !in_array($tax['status'], ['approved', 'rejected']);
$can_approve_reject = ($is_creator || $is_admin) && !in_array($tax['status'], ['approved', 'rejected']);

$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

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

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $to_department = $_POST['to_department'] ?? '';
    $to_user = $_POST['to_user'] ?? '';
    
    if ($action == 'forward') {
        if (!$can_forward) {
            $error = 'شما مجاز به ارجاع این سند نیستید.';
        } elseif (empty($to_department) && empty($to_user)) {
            $error = 'لطفاً بخش یا شخص مقصد را انتخاب کنید';
        } elseif (empty($notes)) {
            $error = 'لطفاً توضیحات را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'forwarded', current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
            $update->execute([$to_department ?: null, $to_user ?: null, $id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $to_department ?: null, $to_user ?: null, $notes]);
            $success = 'سند با موفقیت ارجاع شد.';
        }
    } elseif ($action == 'approve') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به تایید این سند نیستید.';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = ?");
            $update->execute([$id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'سند با موفقیت تایید نهایی شد.';
        }
    } elseif ($action == 'reject') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به رد این سند نیستید.';
        } elseif (empty($notes)) {
            $error = 'لطفاً دلیل رد را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $update->execute([$notes, $id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'reject', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'سند رد شد.';
        }
    } else {
        $error = 'اقدام نامعتبر است.';
    }
}

$page_title = 'مشاهده سند مالیاتی';
ob_start();
?>

<style>
    .info-card { background: white; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
    .info-item small { display: block; color: #7f8c8d; font-size: 11px; margin-bottom: 5px; }
    .info-item strong { font-size: 14px; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
    .status-pending { background: #fef9e6; color: #d4a017; }
    .status-forwarded { background: #fff3cd; color: #f39c12; }
    .status-approved { background: #d4edda; color: #2ecc71; }
    .status-rejected { background: #f8d7da; color: #e74c3c; }
    .holder-box { background: #f0f7ff; border-radius: 12px; padding: 16px; margin-bottom: 24px; border-right: 4px solid #3498db; }
    .action-form { background: white; border-radius: 16px; padding: 24px; margin-bottom: 24px; border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .action-form h3 { margin-bottom: 20px; color: #2c3e50; font-size: 18px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; font-size: 13px; }
    .form-group label .required-star { color: #e74c3c; margin-right: 3px; }
    .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; font-size: 14px; transition: all 0.3s; }
    .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.1); }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 24px; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s; }
    .btn-submit:hover { background: #219a52; transform: translateY(-2px); }
    .alert-error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    .history-table { width: 100%; border-collapse: collapse; }
    .history-table th, .history-table td { padding: 12px; text-align: right; border-bottom: 1px solid #eee; }
    .history-table th { background: #f8f9fa; font-weight: 600; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    hr { margin: 20px 0; border: none; border-top: 1px solid #eee; }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="margin: 0;">🏛️ مشاهده سند مالیاتی <?php echo htmlspecialchars($tax['document_number']); ?></h1>
    <a href="tax.php" style="background: #95a5a6; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; font-size: 14px;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<?php if ($error): ?>
    <div class="alert-error"><?php echo $error; ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="info-card">
    <div class="info-grid">
        <div class="info-item"><small>شماره سند</small><strong><?php echo htmlspecialchars($tax['document_number']); ?></strong></div>
        <div class="info-item"><small>عنوان</small><strong><?php echo htmlspecialchars($tax['title']); ?></strong></div>
        <div class="info-item"><small>شرکت</small><strong><?php echo htmlspecialchars($tax['company_name'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>مبلغ</small><strong><?php echo number_format($tax['amount']); ?> تومان</strong></div>
        <div class="info-item"><small>کد تفصیل حساب</small><strong><?php echo htmlspecialchars($tax['tax_detail_code'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>شناسه مالیاتی</small><strong><?php echo htmlspecialchars($tax['tax_id'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>به شماره فاکتور</small><strong><?php echo htmlspecialchars($tax['to_invoice_number'] ?? '-'); ?></strong></div>
        <div class="info-item"><small>وضعیت</small>
            <strong>
                <?php
                $status_class = 'status-' . ($tax['status'] ?? 'pending');
                $status_texts = [
                    'pending' => 'در انتظار اقدام',
                    'forwarded' => 'ارسال شده',
                    'approved' => 'تایید شده',
                    'rejected' => 'رد شده'
                ];
                $status_text = $status_texts[$tax['status']] ?? $tax['status'];
                echo '<span class="status-badge ' . $status_class . '">' . $status_text . '</span>';
                ?>
            </strong>
        </div>
    </div>
    <div class="info-grid" style="margin-top:15px;">
        <div class="info-item"><small>تاریخ ثبت</small><strong><?php echo jdate('Y/m/d', strtotime($tax['created_at'])); ?></strong></div>
        <div class="info-item"><small>تاریخ مهلت پیگیری</small><strong><?php echo $tax['approval_deadline'] ? jdate('Y/m/d', strtotime($tax['approval_deadline'])) : 'نامشخص'; ?></strong></div>
        <div class="info-item"><small>وضعیت ارسال</small>
            <strong>
                <?php
                $tax_status_class = '';
                $tax_status_text = '';
                if ($tax['tax_status'] == 'sent') {
                    $tax_status_class = 'status-approved';
                    $tax_status_text = 'ارسال شده';
                } elseif ($tax['tax_status'] == 'pending') {
                    $tax_status_class = 'status-pending';
                    $tax_status_text = 'در انتظار';
                } else {
                    $tax_status_class = 'status-rejected';
                    $tax_status_text = 'خطا';
                }
                echo '<span class="status-badge ' . $tax_status_class . '">' . $tax_status_text . '</span>';
                ?>
            </strong>
        </div>
        <div class="info-item"><small>در دست</small><strong><?php echo htmlspecialchars($tax['holder_user_name'] ?? $tax['holder_department_name'] ?? '-'); ?></strong></div>
    </div>
    <?php if ($tax['description']): ?>
        <div style="margin-top: 15px;"><small>توضیحات</small><div><?php echo nl2br(htmlspecialchars($tax['description'])); ?></div></div>
    <?php endif; ?>
    <?php if ($tax['file_path']): ?>
        <div style="margin-top: 15px;"><small>فایل ضمیمه</small><div><a href="/invoice-system-v2/<?php echo $tax['file_path']; ?>" target="_blank">📎 دانلود فایل</a></div></div>
    <?php endif; ?>
</div>

<div class="holder-box">
    <i class="fas fa-user-check" style="color: #3498db; margin-left: 10px;"></i>
    <strong>در دست:</strong> 
    <?php if ($tax['holder_user_name']): ?>
        <?php echo htmlspecialchars($tax['holder_user_name']); ?>
    <?php elseif ($tax['holder_department_name']): ?>
        <?php echo htmlspecialchars($tax['holder_department_name']); ?> (بخش)
    <?php else: ?>
        هیچ
    <?php endif; ?>
</div>

<?php if ($can_forward || $can_approve_reject): ?>
<div class="action-form">
    <h3><i class="fas fa-bolt" style="color: #f39c12;"></i> اقدامات روی سند</h3>
    <form method="POST" id="actionForm">
        <div class="form-group">
            <label>انتخاب اقدام <span class="required-star">*</span></label>
            <select name="action" id="actionSelect" required>
                <option value="">--- انتخاب کنید ---</option>
                <?php if ($can_forward): ?>
                    <option value="forward">🔍 بررسی و پیگیری</option>
                <?php endif; ?>
                <?php if ($can_approve_reject): ?>
                    <option value="approve">✅ تایید نهایی</option>
                    <option value="reject">❌ رد سند</option>
                <?php endif; ?>
            </select>
        </div>
        
        <div id="forwardFields" style="display: none;">
            <div class="row-2col">
                <div class="form-group">
                    <label>📋 ارجاع به بخش</label>
                    <select name="to_department">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>👤 ارجاع به شخص</label>
                    <select name="to_user">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['username']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>📝 توضیحات <span id="notesRequiredStar" class="required-star">*</span></label>
            <textarea name="notes" id="actionNotes" rows="2" placeholder="توضیحات خود را وارد کنید..."></textarea>
        </div>
        
        <button type="submit" class="btn-submit" id="submitBtn">
            <i class="fas fa-check"></i> ثبت اقدام
        </button>
    </form>
</div>

<script>
const actionSelect = document.getElementById('actionSelect');
const forwardFields = document.getElementById('forwardFields');
const actionNotes = document.getElementById('actionNotes');
const notesRequiredStar = document.getElementById('notesRequiredStar');

function toggleFields() {
    const selectedValue = actionSelect.value;
    forwardFields.style.display = 'none';
    
    if (selectedValue === 'forward') {
        forwardFields.style.display = 'block';
        actionNotes.required = true;
        notesRequiredStar.style.display = 'inline';
        actionNotes.placeholder = 'لطفاً دلیل بررسی و پیگیری را وارد کنید...';
    } else if (selectedValue === 'reject') {
        forwardFields.style.display = 'none';
        actionNotes.required = true;
        notesRequiredStar.style.display = 'inline';
        actionNotes.placeholder = 'لطفاً دلیل رد را وارد کنید...';
    } else if (selectedValue === 'approve') {
        forwardFields.style.display = 'none';
        actionNotes.required = false;
        notesRequiredStar.style.display = 'none';
        actionNotes.placeholder = '(اختیاری) توضیحات...';
    } else {
        actionNotes.required = false;
        notesRequiredStar.style.display = 'none';
    }
}

actionSelect.addEventListener('change', toggleFields);

document.getElementById('submitBtn').addEventListener('click', function(e) {
    const selectedValue = actionSelect.value;
    
    if (selectedValue === '') {
        e.preventDefault();
        alert('لطفاً یک اقدام را انتخاب کنید');
        return false;
    }
    
    if (selectedValue === 'forward') {
        const dept = document.querySelector('select[name="to_department"]').value;
        const user = document.querySelector('select[name="to_user"]').value;
        if (dept === '' && user === '') {
            e.preventDefault();
            alert('لطفاً بخش یا شخص مقصد را انتخاب کنید');
            return false;
        }
    }
    
    if ((selectedValue === 'forward' || selectedValue === 'reject') && actionNotes.value.trim() === '') {
        e.preventDefault();
        alert('لطفاً توضیحات را وارد کنید');
        return false;
    }
});

toggleFields();
</script>
<?php endif; ?>

<div class="info-card">
    <h3 style="margin-bottom: 15px;"><i class="fas fa-history" style="color: #3498db;"></i> تاریخچه ارجاع</h3>
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
                </tr>
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
                        $action_icon = ['forward' => '🔄', 'approve' => '✅', 'reject' => '❌'];
                        $action_text = ['forward' => 'ارجاع', 'approve' => 'تایید', 'reject' => 'رد'];
                        echo ($action_icon[$h['action']] ?? '📌') . ' ' . ($action_text[$h['action']] ?? $h['action']);
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