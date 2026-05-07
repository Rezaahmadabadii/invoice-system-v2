<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
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

// تابع تولید شناسه مالیاتی فرمت شده
function generateFormattedTaxId($companyShortName, $taxId) {
    $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $companyShortName));
    if (empty($code)) $code = 'TAX';
    $numericPart = preg_replace('/[^0-9]/', '', $taxId);
    $lastSix = substr(str_pad($numericPart, 6, '0', STR_PAD_LEFT), -6);
    if (empty($lastSix)) $lastSix = '000001';
    return 'Tax-' . $code . '-' . $lastSix;
}

$displayTaxId = !empty($tax['tax_id']) ? $tax['tax_id'] : generateFormattedTaxId($tax['short_name'] ?? '', $tax['original_tax_id'] ?? '');

$page_title = 'مشاهده سند مالیاتی';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #3b82f6;
        --primary-light: #eff6ff;
        --purple-light: #f5f3ff;
        --green-light: #ecfdf5;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --danger: #ef4444;
        --success: #10b981;
        --warning: #f59e0b;
    }
    
    .view-wrapper {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px 24px;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .page-title {
        font-size: 24px;
        font-weight: 700;
        background: linear-gradient(135deg, var(--text-main), var(--primary));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        margin: 0;
    }
    
    .back-link {
        color: var(--text-muted);
        text-decoration: none;
        padding: 8px 20px;
        background: white;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .back-link:hover {
        background: var(--primary);
        color: white;
    }
    
    /* سه ستونه برای اطلاعات بالا */
    .three-columns {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }
    
    /* کارت‌ها */
    .info-card {
        background: var(--card-bg);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border: 1px solid var(--border);
        height: fit-content;
    }
    
    .card-header {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .card-header i {
        font-size: 18px;
    }
    
    .card-header h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-main);
        margin: 0;
    }
    
    .card-body {
        padding: 16px 18px;
    }
    
    .card-blue .card-header { background: var(--primary-light); }
    .card-blue .card-header i { color: var(--primary); }
    .card-purple .card-header { background: var(--purple-light); }
    .card-purple .card-header i { color: #8b5cf6; }
    .card-green .card-header { background: var(--green-light); }
    .card-green .card-header i { color: var(--success); }
    
    /* اطلاعات درون کارت */
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 12px;
        font-size: 13px;
        padding-bottom: 8px;
        border-bottom: 1px dashed #f0f0f0;
    }
    .info-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    .info-label {
        color: var(--text-muted);
        font-size: 12px;
    }
    .info-value {
        font-weight: 600;
        color: var(--text-main);
        text-align: left;
        direction: ltr;
    }
    .amount-value {
        color: var(--success);
        font-size: 16px;
    }
    .tax-id-value {
        font-family: monospace;
        background: #f0f2f5;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 13px;
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
    .status-draft { background: #f5f5f5; color: #95a5a6; }
    
    .tax-status-sent { background: #d4edda; color: #2ecc71; }
    .tax-status-pending { background: #fff3cd; color: #f39c12; }
    .tax-status-failed { background: #f8d7da; color: #e74c3c; }
    
    /* پیش‌نمایش فایل فشرده */
    .file-preview-mini {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 10px;
    }
    .thumbnail-mini {
        width: 50px;
        height: 50px;
        background: #f0f2f5;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        overflow: hidden;
    }
    .thumbnail-mini img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .file-link {
        background: var(--primary);
        color: white;
        padding: 4px 12px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 11px;
    }
    
    /* وضعیت در دست */
    .holder-box {
        background: var(--primary-light);
        border-radius: 12px;
        padding: 12px;
        text-align: center;
        margin-top: 12px;
    }
    
    /* سه گانه وضعیت فشرده */
    .status-steps {
        display: flex;
        justify-content: space-between;
        margin: 15px 0;
        background: #f8f9fa;
        border-radius: 30px;
        padding: 8px 12px;
    }
    .step-item {
        font-size: 11px;
        color: #bbb;
        text-align: center;
        flex: 1;
    }
    .step-item.active { color: var(--warning); font-weight: bold; }
    .step-item.completed { color: var(--success); }
    .step-item i { font-size: 14px; display: block; margin-bottom: 4px; }
    
    /* فرم اقدامات */
    .action-form {
        background: white;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
        border: 1px solid var(--border);
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 6px;
        color: var(--text-main);
    }
    .form-group select, .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
    }
    .row-2col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    .btn-submit {
        background: linear-gradient(135deg, var(--success), #059669);
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }
    
    /* تاریخچه جدولی فشرده */
    .history-wrapper {
        max-height: 250px;
        overflow-y: auto;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    .history-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .history-table th {
        background: #f8f9fa;
        padding: 10px 8px;
        text-align: right;
        font-weight: 600;
        position: sticky;
        top: 0;
    }
    .history-table td {
        padding: 8px;
        border-bottom: 1px solid #f0f0f0;
    }
    .action-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
    }
    .action-forward { background: #fff3cd; color: #f39c12; }
    .action-approve { background: #d4edda; color: #2ecc71; }
    .action-reject { background: #f8d7da; color: #e74c3c; }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    .alert-danger { background: #fee8e8; color: var(--danger); border-right: 3px solid var(--danger); }
    .alert-success { background: #d4edda; color: #155724; border-right: 3px solid var(--success); }
    
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
    .modal-content img, .modal-content iframe {
        max-width: 100%;
        max-height: 90vh;
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
    
    @media (max-width: 1000px) {
        .three-columns {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 768px) {
        .three-columns {
            grid-template-columns: 1fr;
        }
        .view-wrapper {
            padding: 16px;
        }
        .row-2col {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="view-wrapper">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-invoice-dollar"></i> مشاهده سند مالیاتی</h1>
        <div style="display: flex; gap: 12px;">
            <?php if ($is_creator && $tax['status'] == 'draft'): ?>
                <a href="tax-edit.php?id=<?php echo $id; ?>" style="background: var(--warning); color: white; padding: 8px 18px; border-radius: 30px; text-decoration: none; font-size: 13px;">
                    <i class="fas fa-edit"></i> تکمیل سند
                </a>
            <?php elseif ($is_creator): ?>
                <a href="tax-edit.php?id=<?php echo $id; ?>" style="background: var(--primary); color: white; padding: 8px 18px; border-radius: 30px; text-decoration: none; font-size: 13px;">
                    <i class="fas fa-edit"></i> ویرایش
                </a>
            <?php endif; ?>
            <a href="tax.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <script>setTimeout(function() { window.location.reload(); }, 2000);</script>
    <?php endif; ?>
    
    <!-- سه ستونه اطلاعات اصلی -->
    <div class="three-columns">
        
        <!-- ستون 1: اطلاعات سند -->
        <div class="info-card card-blue">
            <div class="card-header">
                <i class="fas fa-file-alt"></i>
                <h3>اطلاعات سند</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">🏷️ شناسه مالیاتی</span>
                    <span class="info-value tax-id-value"><?php echo htmlspecialchars($displayTaxId); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📄 عنوان سند</span>
                    <span class="info-value"><?php echo htmlspecialchars($tax['title']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏢 شرکت</span>
                    <span class="info-value"><?php echo htmlspecialchars($tax['company_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏪 فروشنده</span>
                    <span class="info-value"><?php echo htmlspecialchars($tax['vendor_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🆔 شناسه ملی فروشنده</span>
                    <span class="info-value"><?php echo htmlspecialchars($tax['vendor_national_id'] ?? '-'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- ستون 2: اطلاعات مالی و وضعیت -->
        <div class="info-card card-purple">
            <div class="card-header">
                <i class="fas fa-chart-line"></i>
                <h3>اطلاعات مالی و وضعیت</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">💰 مبلغ</span>
                    <span class="info-value amount-value"><?php echo number_format($tax['amount']); ?> تومان</span>
                </div>
                <div class="info-row">
                    <span class="info-label">📅 تاریخ ثبت</span>
                    <span class="info-value"><?php echo jdate('Y/m/d', strtotime($tax['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📆 تاریخ سند</span>
                    <span class="info-value"><?php echo htmlspecialchars($tax['document_date'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">⏰ مهلت پیگیری</span>
                    <span class="info-value"><?php echo $tax['approval_deadline'] ? jdate('Y/m/d', strtotime($tax['approval_deadline'])) : 'نامشخص'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📊 وضعیت سند</span>
                    <span class="info-value">
                        <?php
                        $status_class = 'status-' . ($tax['status'] ?? 'pending');
                        $status_texts = ['pending' => '⏳ در انتظار', 'forwarded' => '🔄 در حال بررسی', 'approved' => '✅ تایید شده', 'rejected' => '❌ رد شده', 'draft' => '📝 پیش‌نویس'];
                        echo '<span class="status-badge ' . $status_class . '">' . ($status_texts[$tax['status']] ?? $tax['status']) . '</span>';
                        ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">📡 وضعیت ارسال</span>
                    <span class="info-value">
                        <?php
                        $tax_status_class = $tax['tax_status'] == 'sent' ? 'tax-status-sent' : ($tax['tax_status'] == 'pending' ? 'tax-status-pending' : 'tax-status-failed');
                        $tax_status_text = $tax['tax_status'] == 'sent' ? '✅ ارسال شده' : ($tax['tax_status'] == 'pending' ? '⏳ در انتظار' : '❌ خطا');
                        echo '<span class="status-badge ' . $tax_status_class . '">' . $tax_status_text . '</span>';
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- ستون 3: فایل ضمیمه + در دست -->
        <div class="info-card card-green">
            <div class="card-header">
                <i class="fas fa-paperclip"></i>
                <h3>ضمیمه و وضعیت در دست</h3>
            </div>
            <div class="card-body">
                <!-- فایل ضمیمه -->
                <?php if ($tax['file_path'] && file_exists(__DIR__ . '/../' . $tax['file_path'])): 
                    $file_url = '/invoice-system-v2/' . $tax['file_path'];
                    $file_ext = strtolower(pathinfo($tax['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                ?>
                    <div class="file-preview-mini">
                        <div class="thumbnail-mini" onclick="openModal('<?php echo $file_url; ?>', '<?php echo $is_image ? 'image' : 'pdf'; ?>')">
                            <?php if ($is_image): ?>
                                <img src="<?php echo $file_url; ?>" alt="پیش‌نمایش">
                            <?php else: ?>
                                <i class="fas fa-file-pdf" style="font-size: 24px; color: #e74c3c;"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($tax['file_name'] ?? basename($tax['file_path'])); ?></div>
                            <a href="<?php echo $file_url; ?>" download class="file-link" style="margin-top: 5px; display: inline-block;">دانلود</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 15px; color: var(--text-muted);">
                        <i class="fas fa-cloud-upload-alt"></i> هیچ فایلی آپلود نشده است
                    </div>
                <?php endif; ?>
                
                <!-- در دست -->
                <div class="holder-box">
                    <i class="fas fa-location-dot"></i>
                    <strong>📍 در دست:</strong><br>
                    <?php 
                    if ($tax['holder_user_name']) {
                        echo '👤 ' . htmlspecialchars($tax['holder_user_name']);
                    } elseif ($tax['holder_department_name']) {
                        echo '🏢 ' . htmlspecialchars($tax['holder_department_name']) . ' (بخش)';
                    } else {
                        echo '📭 بدون متصدی';
                    }
                    ?>
                </div>
                
                <!-- سه گانه وضعیت فشرده -->
                <div class="status-steps">
                    <div class="step-item <?php echo ($tax['status'] != 'draft') ? 'completed' : 'active'; ?>">
                        <i class="fas fa-pen"></i> ایجاد
                    </div>
                    <div class="step-item <?php echo ($tax['status'] == 'forwarded' || $tax['status'] == 'approved') ? 'completed' : ($tax['status'] == 'forwarded' ? 'active' : ''); ?>">
                        <i class="fas fa-search"></i> بررسی
                    </div>
                    <div class="step-item <?php echo ($tax['status'] == 'approved') ? 'completed active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> تایید
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- توضیحات (در صورت وجود) -->
    <?php if ($tax['description']): ?>
    <div class="info-card" style="margin-bottom: 24px;">
        <div class="card-header">
            <i class="fas fa-align-right"></i>
            <h3>توضیحات سند</h3>
        </div>
        <div class="card-body">
            <div style="font-size: 13px; color: var(--text-main); line-height: 1.6;"><?php echo nl2br(htmlspecialchars($tax['description'])); ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- فرم اقدامات (در صورت مجاز بودن) -->
    <?php if ($can_forward || $can_approve_reject): ?>
    <div class="action-form">
        <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-bolt" style="color: var(--warning);"></i> اقدامات روی سند</h3>
        <form method="POST" id="actionForm">
            <div class="form-group">
                <label>انتخاب اقدام <span style="color: var(--danger);">*</span></label>
                <select name="action" id="actionSelect" required>
                    <option value="">--- انتخاب کنید ---</option>
                    <?php if ($can_forward): ?>
                        <option value="forward">🔄 بررسی و پیگیری (ارجاع)</option>
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
                                <option value="<?php echo $dept['id']; ?>">🏢 <?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>👤 ارجاع به شخص</label>
                        <select name="to_user">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">👤 <?php echo htmlspecialchars($user['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>📝 توضیحات <span id="notesRequiredStar" style="color: var(--danger);">*</span></label>
                <textarea name="notes" id="actionNotes" rows="2" placeholder="توضیحات خود را وارد کنید..."></textarea>
            </div>
            
            <button type="submit" class="btn-submit" id="submitBtn">ثبت اقدام</button>
        </form>
    </div>
    
    <script>
    const actionSelect = document.getElementById('actionSelect');
    const forwardFields = document.getElementById('forwardFields');
    const actionNotes = document.getElementById('actionNotes');
    const notesRequiredStar = document.getElementById('notesRequiredStar');
    
    function toggleFields() {
        const val = actionSelect.value;
        forwardFields.style.display = 'none';
        
        if (val === 'forward') {
            forwardFields.style.display = 'block';
            actionNotes.required = true;
            notesRequiredStar.style.display = 'inline';
            actionNotes.placeholder = 'لطفاً دلیل بررسی و پیگیری را وارد کنید...';
        } else if (val === 'reject') {
            forwardFields.style.display = 'none';
            actionNotes.required = true;
            notesRequiredStar.style.display = 'inline';
            actionNotes.placeholder = 'لطفاً دلیل رد را وارد کنید...';
        } else if (val === 'approve') {
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
        const val = actionSelect.value;
        if (val === '') {
            e.preventDefault();
            alert('لطفاً یک اقدام را انتخاب کنید');
            return false;
        }
        if (val === 'forward') {
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
        if (val === 'reject' && actionNotes.value.trim() === '') {
            e.preventDefault();
            alert('لطفاً دلیل رد را وارد کنید');
            return false;
        }
    });
    
    toggleFields();
    </script>
    <?php endif; ?>
    
    <!-- تاریخچه ارجاع -->
    <div class="info-card">
        <div class="card-header">
            <i class="fas fa-history"></i>
            <h3>تاریخچه ارجاع</h3>
        </div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 20px;">هیچ اقدامی ثبت نشده است.</p>
            <?php else: ?>
                <div class="history-wrapper">
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
    </div>
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
        modalContent.innerHTML = '<img src="' + fileUrl + '" alt="تصویر سند">';
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