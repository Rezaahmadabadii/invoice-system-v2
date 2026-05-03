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

$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name, 
           c.short_name,
           v.name as vendor_name,
           holder_dep.name as holder_department_name,
           holder_user.full_name as holder_user_name,
           creator.full_name as creator_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id
    LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id
    LEFT JOIN users creator ON d.created_by = creator.id
    WHERE d.id = ? AND d.type = 'invoice'
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['user_roles'] ?? [];
$is_creator = ($invoice['created_by'] == $user_id);
$is_holder_user = ($invoice['current_holder_user_id'] == $user_id);
$is_holder_department = ($invoice['current_holder_department_id'] && in_array($invoice['current_holder_department_id'], $_SESSION['user_role_ids'] ?? []));
$is_holder = $is_holder_user || $is_holder_department;
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);

$can_forward = $is_holder && !in_array($invoice['status'], ['approved', 'rejected']);
$can_approve_reject = ($is_creator || $is_admin) && !in_array($invoice['status'], ['approved', 'rejected']);

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
            $error = 'شما مجاز به ارجاع این فاکتور نیستید.';
        } elseif (empty($to_department) && empty($to_user)) {
            $error = 'لطفاً بخش یا شخص مقصد را انتخاب کنید';
        } elseif (empty($notes)) {
            $error = 'لطفاً توضیحات را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'forwarded', current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
            $update->execute([$to_department ?: null, $to_user ?: null, $id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $to_department ?: null, $to_user ?: null, $notes]);
            $success = 'فاکتور با موفقیت ارجاع شد.';
        }
    } elseif ($action == 'approve') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به تایید این فاکتور نیستید.';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = ?");
            $update->execute([$id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'فاکتور با موفقیت تایید نهایی شد.';
        }
    } elseif ($action == 'reject') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به رد این فاکتور نیستید.';
        } elseif (empty($notes)) {
            $error = 'لطفاً دلیل رد را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $update->execute([$notes, $id]);
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'reject', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            $success = 'فاکتور رد شد.';
        }
    } else {
        $error = 'اقدام نامعتبر است.';
    }
}

$page_title = 'مشاهده فاکتور';
ob_start();
?>

<style>
    /* استایل اصلی */
    .info-card {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .info-grid-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }
    .info-section {
        background: #f8f9fa;
        border-radius: 16px;
        padding: 20px;
    }
    .info-section h4 {
        margin: 0 0 15px 0;
        color: #2c3e50;
        font-size: 16px;
        border-bottom: 2px solid #3498db;
        padding-bottom: 8px;
        display: inline-block;
    }
    .info-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        font-size: 14px;
        flex-wrap: wrap;
    }
    .info-row .label {
        width: 100px;
        color: #7f8c8d;
        font-weight: 500;
    }
    .info-row .value {
        color: #2c3e50;
        font-weight: 500;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    .status-pending { background: #fef9e6; color: #d4a017; }
    .status-forwarded { background: #fff3cd; color: #f39c12; }
    .status-approved { background: #d4edda; color: #2ecc71; }
    .status-rejected { background: #f8d7da; color: #e74c3c; }
    
    /* پیش‌نمایش فایل */
    .file-preview {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
        margin-top: 15px;
    }
    .thumbnail {
        width: 80px;
        height: 80px;
        background: #f0f2f5;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        overflow: hidden;
        transition: all 0.3s;
        border: 1px solid #e0e0e0;
    }
    .thumbnail:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .thumbnail .pdf-icon {
        font-size: 32px;
        color: #e74c3c;
    }
    .file-info {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .file-link {
        background: #3498db;
        color: white;
        padding: 6px 12px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        width: fit-content;
    }
    .file-link:hover {
        background: #2980b9;
    }
    .preview-hint {
        font-size: 11px;
        color: #7f8c8d;
    }
    
    /* تاریخچه جدولی فشرده */
    .history-wrapper {
        max-height: 400px;
        overflow-y: auto;
        border-radius: 12px;
        border: 1px solid #eef2f5;
    }
    .history-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .history-table th {
        background: #f8f9fa;
        padding: 12px 10px;
        text-align: right;
        font-weight: 600;
        color: #2c3e50;
        position: sticky;
        top: 0;
        z-index: 10;
        border-bottom: 1px solid #e0e0e0;
    }
    .history-table td {
        padding: 10px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: top;
    }
    .history-table tr:hover {
        background: #f8f9fa;
    }
    .action-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
    }
    .action-forward { background: #fff3cd; color: #f39c12; }
    .action-approve { background: #d4edda; color: #2ecc71; }
    .action-reject { background: #f8d7da; color: #e74c3c; }
    
    /* در دست */
    .holder-box {
        background: #f0f7ff;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 24px;
        border-right: 4px solid #3498db;
    }
    
    /* اقدامات */
    .action-form {
        background: white;
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #2c3e50;
        font-size: 13px;
    }
    .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 14px;
    }
    .btn-submit {
        background: #27ae60;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
    }
    .btn-submit:hover {
        background: #219a52;
    }
    .alert-error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    .alert-success { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
    
    /* مودال */
    .modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.9);
    }
    .modal-content {
        margin: auto;
        display: block;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        max-width: 90%;
        max-height: 90%;
    }
    .modal-content img {
        max-width: 100%;
        max-height: 90vh;
        border-radius: 8px;
    }
    .modal-content iframe {
        width: 80vw;
        height: 80vh;
        border: none;
        border-radius: 8px;
    }
    .close-modal {
        position: absolute;
        top: 20px;
        right: 35px;
        color: white;
        font-size: 40px;
        cursor: pointer;
    }
    
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 768px) {
        .info-grid-2col { grid-template-columns: 1fr; }
        .row-2col { grid-template-columns: 1fr; }
        .info-row .label { width: 80px; }
    }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="margin: 0;">🧾 مشاهده فاکتور <?php echo htmlspecialchars($invoice['document_number']); ?></h1>
    <div style="display: flex; gap: 10px;">
        <a href="invoice-create.php" style="background: #27ae60; color: white; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 14px;">
            <i class="fas fa-plus"></i> فاکتور جدید
        </a>
        <a href="inbox.php" style="background: #95a5a6; color: white; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 14px;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert-error"><?php echo $error; ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- اطلاعات فاکتور در دو ستون -->
<div class="info-card">
    <div class="info-grid-2col">
        <!-- ستون راست: اطلاعات اصلی -->
        <div class="info-section">
            <h4>📋 اطلاعات اصلی</h4>
            <div class="info-row"><span class="label">📄 شماره فاکتور:</span><span class="value"><?php echo htmlspecialchars($invoice['document_number']); ?></span></div>
            <div class="info-row"><span class="label">🏷️ عنوان:</span><span class="value"><?php echo htmlspecialchars($invoice['title']); ?></span></div>
            <div class="info-row"><span class="label">🏢 شرکت:</span><span class="value"><?php echo htmlspecialchars($invoice['company_name'] ?? '-'); ?></span></div>
            <div class="info-row"><span class="label">🏪 فروشنده:</span><span class="value"><?php echo htmlspecialchars($invoice['vendor_name'] ?? '-'); ?></span></div>
            <div class="info-row"><span class="label">📅 تاریخ فاکتور:</span><span class="value"><?php echo htmlspecialchars($invoice['document_date']); ?></span></div>
            <div class="info-row"><span class="label">📅 تاریخ ثبت:</span><span class="value"><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></span></div>
        </div>
        
        <!-- ستون چپ: اطلاعات مالی و وضعیت -->
        <div class="info-section">
            <h4>💰 اطلاعات مالی</h4>
            <div class="info-row"><span class="label">💵 مبلغ:</span><span class="value"><?php echo number_format($invoice['amount']); ?> تومان</span></div>
            <div class="info-row"><span class="label">📊 وضعیت:</span>
                <span class="status-badge status-<?php echo $invoice['status'] ?? 'pending'; ?>">
                    <?php
                    $status_texts = ['pending' => '⏳ در انتظار اقدام', 'forwarded' => '📨 ارسال شده', 'approved' => '✅ تایید شده', 'rejected' => '❌ رد شده'];
                    echo $status_texts[$invoice['status']] ?? $invoice['status'];
                    ?>
                </span>
            </div>
            <?php if ($invoice['description']): ?>
                <div class="info-row"><span class="label">📝 توضیحات:</span><span class="value"><?php echo nl2br(htmlspecialchars($invoice['description'])); ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- فایل ضمیمه با پیش‌نمایش -->
    <?php if ($invoice['file_path']): ?>
        <?php
        $file_ext = strtolower(pathinfo($invoice['file_name'], PATHINFO_EXTENSION));
        $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $file_url = '/invoice-system-v2/' . $invoice['file_path'];
        ?>
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eef2f5;">
            <small style="color: #7f8c8d;">📎 فایل ضمیمه</small>
            <div class="file-preview">
                <div class="thumbnail" onclick="openModal('<?php echo $file_url; ?>', '<?php echo $is_image ? 'image' : 'pdf'; ?>')">
                    <?php if ($is_image): ?>
                        <img src="<?php echo $file_url; ?>" alt="پیش‌نمایش">
                    <?php else: ?>
                        <div class="pdf-icon">📄</div>
                    <?php endif; ?>
                </div>
                <div class="file-info">
                    <div class="preview-hint">🔍 برای مشاهده در اندازه واقعی کلیک کنید</div>
                    <a href="<?php echo $file_url; ?>" download class="file-link">📥 دانلود فایل</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- وضعیت در دست -->
<div class="holder-box">
    <i class="fas fa-user-check" style="color: #3498db; margin-left: 10px;"></i>
    <strong>📍 در دست:</strong> 
    <?php if ($invoice['holder_user_name']): ?>
        👤 <?php echo htmlspecialchars($invoice['holder_user_name']); ?>
    <?php elseif ($invoice['holder_department_name']): ?>
        🏢 <?php echo htmlspecialchars($invoice['holder_department_name']); ?>
    <?php else: ?>
        هیچ
    <?php endif; ?>
</div>

<!-- فرم اقدامات -->
<?php if ($can_forward || $can_approve_reject): ?>
<div class="action-form">
    <h3><i class="fas fa-bolt" style="color: #f39c12;"></i> اقدامات روی فاکتور</h3>
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
                    <option value="reject">❌ رد فاکتور</option>
                <?php endif; ?>
            </select>
        </div>
        
        <div id="forwardFields" style="display: none;">
            <hr>
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
        if (actionNotes.value.trim() === '') {
            e.preventDefault();
            alert('لطفاً توضیحات را وارد کنید');
            return false;
        }
    }
    
    if (selectedValue === 'reject' && actionNotes.value.trim() === '') {
        e.preventDefault();
        alert('لطفاً دلیل رد را وارد کنید');
        return false;
    }
});

toggleFields();
</script>
<?php endif; ?>

<!-- تاریخچه ارجاع (جدولی فشرده با اسکرول) -->
<div class="info-card">
    <h3 style="margin-bottom: 15px;"><i class="fas fa-history" style="color: #3498db;"></i> 📜 تاریخچه ارجاع</h3>
    <?php if (empty($history)): ?>
        <p style="color: #7f8c8d; text-align: center; padding: 20px;">هیچ اقدامی ثبت نشده است.</p>
    <?php else: ?>
        <div class="history-wrapper">
            <table class="history-table">
                <thead>
                    <tr>
                        <th style="width: 110px;">زمان</th>
                        <th style="width: 100px;">از</th>
                        <th style="width: 100px;">به</th>
                        <th style="width: 70px;">اقدام</th>
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
                                echo '👤 ' . htmlspecialchars($h['to_name']);
                            } elseif ($h['to_department_name']) {
                                echo '🏢 ' . htmlspecialchars($h['to_department_name']);
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $action_class = '';
                            $action_icon = '';
                            if ($h['action'] == 'forward') { $action_class = 'action-forward'; $action_icon = '🔄'; $action_text = 'ارجاع'; }
                            elseif ($h['action'] == 'approve') { $action_class = 'action-approve'; $action_icon = '✅'; $action_text = 'تایید'; }
                            elseif ($h['action'] == 'reject') { $action_class = 'action-reject'; $action_icon = '❌'; $action_text = 'رد'; }
                            else { $action_icon = '📌'; $action_text = $h['action']; }
                            ?>
                            <span class="action-badge <?php echo $action_class; ?>">
                                <?php echo $action_icon . ' ' . $action_text; ?>
                            </span>
                        </td>
                        <td style="max-width: 200px; word-wrap: break-word;"><?php echo nl2br(htmlspecialchars($h['notes'] ?? '-')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- مودال -->
<div id="fileModal" class="modal" onclick="closeModal()">
    <span class="close-modal" onclick="closeModal()">&times;</span>
    <div class="modal-content" id="modalContent"></div>
</div>

<script>
function openModal(fileUrl, type) {
    const modal = document.getElementById('fileModal');
    const modalContent = document.getElementById('modalContent');
    
    if (type === 'image') {
        modalContent.innerHTML = '<img src="' + fileUrl + '" alt="تصویر فاکتور">';
    } else if (type === 'pdf') {
        modalContent.innerHTML = '<iframe src="' + fileUrl + '"></iframe>';
    }
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    const modal = document.getElementById('fileModal');
    const modalContent = document.getElementById('modalContent');
    modal.style.display = 'none';
    modalContent.innerHTML = '';
    document.body.style.overflow = 'auto';
}

document.getElementById('fileModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>