<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
session_start();

$error = '';
$success = '';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;
if (!$id) {
    header('Location: waybills.php');
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
    WHERE d.id = ? AND d.type = 'waybill'
");
$stmt->execute([$id]);
$waybill = $stmt->fetch();

if (!$waybill) {
    header('Location: waybills.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['user_roles'] ?? [];
$user_department_id = $_SESSION['user_department_id'] ?? null;

$is_creator = ($waybill['created_by'] == $user_id);
$is_holder_user = ($waybill['current_holder_user_id'] == $user_id);
$is_holder_department = ($waybill['current_holder_department_id'] == $user_department_id);
$is_holder = ($is_holder_user || $is_holder_department);
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);

$is_locked = in_array($waybill['status'], ['approved', 'rejected', 'forwarded']);
$can_forward = $is_holder && !$is_locked;
$can_cancel_forward = ($is_creator && $waybill['status'] == 'forwarded' && !$is_holder && !$is_locked);
$can_approve_reject = ($is_creator || $is_admin) && !in_array($waybill['status'], ['approved', 'rejected']);

$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users WHERE id != ? ORDER BY full_name")->fetchAll([$user_id]);

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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $to_department = $_POST['to_department'] ?? '';
    $to_user = $_POST['to_user'] ?? '';
    
    if ($action == 'forward') {
        if (!$can_forward) {
            $error = 'شما مجاز به ارجاع این بارنامه نیستید.';
        } elseif (empty($to_department) && empty($to_user)) {
            $error = 'لطفاً بخش یا شخص مقصد را انتخاب کنید';
        } elseif (empty($notes)) {
            $error = 'لطفاً توضیحات را وارد کنید';
        } else {
            if (!empty($to_user)) {
                $new_holder_user_id = $to_user;
                $new_holder_department_id = null;
            } else {
                $new_holder_user_id = null;
                $new_holder_department_id = $to_department;
            }
            
            $update = $pdo->prepare("UPDATE documents SET status = 'forwarded', current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
            $update->execute([$new_holder_department_id, $new_holder_user_id, $id]);
            
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, 'forward', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $new_holder_department_id, $new_holder_user_id, $notes]);
            
            $success = 'بارنامه با موفقیت ارجاع شد.';
            echo '<script>if(typeof updateCounters === "function") updateCounters();</script>';
        }
    } elseif ($action == 'cancel_forward') {
        if (!$can_cancel_forward) {
            $error = 'شما مجاز به برگشت این ارجاع نیستید.';
        } elseif (empty($notes)) {
            $error = 'لطفاً دلیل برگشت ارجاع را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET current_holder_user_id = ?, current_holder_department_id = NULL, status = 'pending' WHERE id = ?");
            $update->execute([$waybill['created_by'], $id]);
            
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_user_id, action, notes) VALUES (?, ?, ?, 'cancel_forward', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $waybill['created_by'], $notes]);
            
            $success = 'ارجاع بارنامه با موفقیت برگشت داده شد.';
            echo '<script>setTimeout(function() { window.location.href = "waybills.php"; }, 1500);</script>';
        }
    } elseif ($action == 'approve') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به تایید این بارنامه نیستید.';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = ?");
            $update->execute([$id]);
            
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            
            $success = 'بارنامه با موفقیت تایید نهایی شد.';
            echo '<script>setTimeout(function() { window.location.href = "waybills.php"; }, 1500);</script>';
        }
    } elseif ($action == 'reject') {
        if (!$can_approve_reject) {
            $error = 'شما مجاز به رد این بارنامه نیستید.';
        } elseif (empty($notes)) {
            $error = 'لطفاً دلیل رد را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE documents SET status = 'rejected', rejection_reason = ? WHERE id = ?");
            $update->execute([$notes, $id]);
            
            $insert = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'reject', ?)");
            $insert->execute([$id, $_SESSION['user_id'], $notes]);
            
            $success = 'بارنامه رد شد.';
            echo '<script>setTimeout(function() { window.location.href = "waybills.php"; }, 1500);</script>';
        }
    } else {
        $error = 'اقدام نامعتبر است.';
    }
}

function formatWaybillNumber($document_number, $short_name) {
    if (strpos($document_number, '-B/L-') !== false) {
        return $document_number;
    }
    $short = !empty($short_name) ? strtolower($short_name) : 'wbl';
    $number = preg_replace('/[^0-9]/', '', $document_number);
    if (empty($number)) {
        $number = substr($document_number, -6);
    }
    return $short . '-B/L-' . $number;
}

$display_number = formatWaybillNumber($waybill['document_number'], $waybill['short_name']);

$page_title = 'مشاهده بارنامه';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #0284c7;
        --primary-light: #e0f2fe;
        --secondary: #0d9488;
        --secondary-light: #f0fdfa;
        --accent: #d97706;
        --accent-light: #fffbeb;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --danger: #ef4444;
        --success: #10b981;
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
        background: linear-gradient(135deg, var(--primary), var(--secondary));
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
    
    .three-columns {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .info-card {
        background: var(--card-bg);
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border: 1px solid var(--border);
        height: fit-content;
    }
    
    .card-header {
        padding: 14px 20px;
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
        padding: 16px 20px;
    }
    
    .card-blue .card-header { background: var(--primary-light); }
    .card-blue .card-header i { color: var(--primary); }
    .card-purple .card-header { background: var(--secondary-light); }
    .card-purple .card-header i { color: var(--secondary); }
    .card-green .card-header { background: var(--accent-light); }
    .card-green .card-header i { color: var(--accent); }
    
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
        word-break: break-word;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }
    .amount-value {
        color: var(--success);
        font-size: 16px;
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
    
    .holder-box {
        background: var(--primary-light);
        border-radius: 12px;
        padding: 12px;
        text-align: center;
        margin-top: 12px;
    }
    
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
    .step-item.active { color: var(--accent); font-weight: bold; }
    .step-item.completed { color: var(--success); }
    .step-item i { font-size: 14px; display: block; margin-bottom: 4px; }
    
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
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }
    .btn-cancel {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }
    
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
    .action-cancel { background: #fee2e2; color: #dc2626; }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    .alert-danger { background: #fee8e8; color: var(--danger); border-right: 3px solid var(--danger); }
    .alert-success { background: #d4edda; color: #155724; border-right: 3px solid var(--success); }
    
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
        <h1 class="page-title"><i class="fas fa-truck"></i> مشاهده بارنامه</h1>
        <div style="display: flex; gap: 12px;">
            <?php if ($is_creator): ?>
                <a href="waybill-edit.php?id=<?php echo $id; ?>" style="background: var(--primary); color: white; padding: 8px 18px; border-radius: 30px; text-decoration: none; font-size: 13px;">
                    <i class="fas fa-edit"></i> ویرایش
                </a>
            <?php endif; ?>
            <a href="waybills.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <script>setTimeout(function() { window.location.reload(); }, 2000);</script>
    <?php endif; ?>
    
    <div class="three-columns">
        
        <div class="info-card card-blue">
            <div class="card-header">
                <i class="fas fa-file-alt"></i>
                <h3>اطلاعات بارنامه</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">📄 شماره بارنامه</span>
                    <span class="info-value"><?php echo htmlspecialchars($display_number); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏷️ عنوان</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['title']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏢 شرکت</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['company_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏪 فروشنده</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['vendor_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📅 تاریخ ثبت</span>
                    <span class="info-value"><?php echo jdate('Y/m/d', strtotime($waybill['created_at'])); ?></span>
                </div>
            </div>
        </div>
        
        <div class="info-card card-purple">
            <div class="card-header">
                <i class="fas fa-location-dot"></i>
                <h3>اطلاعات حمل</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">👤 فرستنده</span>
                    <span class="info-value" title="<?php echo htmlspecialchars($waybill['sender_name'] ?? '-'); ?>"><?php echo htmlspecialchars(mb_substr($waybill['sender_name'] ?? '-', 0, 30)) . (mb_strlen($waybill['sender_name'] ?? '') > 30 ? '...' : ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">👤 گیرنده</span>
                    <span class="info-value" title="<?php echo htmlspecialchars($waybill['receiver_name'] ?? '-'); ?>"><?php echo htmlspecialchars(mb_substr($waybill['receiver_name'] ?? '-', 0, 30)) . (mb_strlen($waybill['receiver_name'] ?? '') > 30 ? '...' : ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📍 مبدا</span>
                    <span class="info-value" title="<?php echo htmlspecialchars($waybill['loading_origin'] ?? '-'); ?>"><?php echo htmlspecialchars(mb_substr($waybill['loading_origin'] ?? '-', 0, 30)) . (mb_strlen($waybill['loading_origin'] ?? '') > 30 ? '...' : ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📍 مقصد</span>
                    <span class="info-value" title="<?php echo htmlspecialchars($waybill['discharge_destination'] ?? '-'); ?>"><?php echo htmlspecialchars(mb_substr($waybill['discharge_destination'] ?? '-', 0, 30)) . (mb_strlen($waybill['discharge_destination'] ?? '') > 30 ? '...' : ''); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">💰 مبلغ</span>
                    <span class="info-value amount-value"><?php echo number_format($waybill['amount'] ?? 0); ?> تومان</span>
                </div>
            </div>
        </div>
        
        <div class="info-card card-green">
            <div class="card-header">
                <i class="fas fa-truck"></i>
                <h3>وضعیت بارنامه</h3>
            </div>
            <div class="card-body">
                <?php if ($waybill['file_path'] && file_exists(__DIR__ . '/../' . $waybill['file_path'])): 
                    $file_url = '/invoice-system-v2/' . $waybill['file_path'];
                    $file_ext = strtolower(pathinfo($waybill['file_path'], PATHINFO_EXTENSION));
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
                            <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($waybill['file_name'] ?? basename($waybill['file_path'])); ?></div>
                            <a href="<?php echo $file_url; ?>" download class="file-link" style="margin-top: 5px; display: inline-block;">دانلود</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 15px; color: var(--text-muted);">
                        <i class="fas fa-cloud-upload-alt"></i> هیچ فایلی آپلود نشده است
                    </div>
                <?php endif; ?>
                
                <div class="holder-box">
                    <i class="fas fa-location-dot"></i>
                    <strong>📍 در دست:</strong><br>
                    <?php 
                    if ($waybill['holder_user_name']) {
                        echo '👤 ' . htmlspecialchars($waybill['holder_user_name']);
                    } elseif ($waybill['holder_department_name']) {
                        echo '🏢 ' . htmlspecialchars($waybill['holder_department_name']);
                    } else {
                        echo '📭 بدون متصدی';
                    }
                    ?>
                </div>
                
                <div class="info-row">
                    <span class="info-label">📊 وضعیت</span>
                    <span class="info-value">
                        <?php
                        $status_class = 'status-' . ($waybill['status'] ?? 'pending');
                        $status_texts = ['pending' => '⏳ در انتظار', 'forwarded' => '🔄 در حال بررسی', 'approved' => '✅ تایید شده', 'rejected' => '❌ رد شده', 'draft' => '📝 پیش‌نویس'];
                        echo '<span class="status-badge ' . $status_class . '">' . ($status_texts[$waybill['status']] ?? $waybill['status']) . '</span>';
                        ?>
                    </span>
                </div>
                
                <div class="status-steps">
                    <div class="step-item <?php echo ($waybill['status'] != 'draft') ? 'completed' : 'active'; ?>">
                        <i class="fas fa-pen"></i> ایجاد
                    </div>
                    <div class="step-item <?php echo ($waybill['status'] == 'forwarded' || $waybill['status'] == 'approved') ? 'completed' : ($waybill['status'] == 'forwarded' ? 'active' : ''); ?>">
                        <i class="fas fa-search"></i> بررسی
                    </div>
                    <div class="step-item <?php echo ($waybill['status'] == 'approved') ? 'completed active' : ''; ?>">
                        <i class="fas fa-check-circle"></i> تایید
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- اطلاعات راننده و وسیله نقلیه و بیمه (در صورت وجود) -->
    <?php if (!empty($waybill['driver1_name']) || !empty($waybill['vehicle_plate']) || !empty($waybill['insurance_company'])): ?>
    <div class="info-card" style="margin-bottom: 24px;">
        <div class="card-header">
            <i class="fas fa-truck-moving"></i>
            <h3>اطلاعات تکمیلی حمل</h3>
        </div>
        <div class="card-body">
            <div class="row-2col">
                <?php if (!empty($waybill['driver1_name'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">🚛 راننده اول</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['driver1_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['driver2_name'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">🚛 راننده دوم</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['driver2_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['vehicle_plate'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">🔢 شماره پلاک</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['vehicle_plate']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['quantity'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">📦 تعداد</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['quantity']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['weight'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">⚖️ وزن</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['weight']); ?> کیلوگرم</span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['carrier_responsible'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">👔 مسئول حمل</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['carrier_responsible']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($waybill['insurance_company'])): ?>
                <div class="info-row" style="border-bottom: none; padding-bottom: 0;">
                    <span class="info-label">🏢 شرکت بیمه</span>
                    <span class="info-value"><?php echo htmlspecialchars($waybill['insurance_company']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($waybill['description']): ?>
    <div class="info-card" style="margin-bottom: 24px;">
        <div class="card-header">
            <i class="fas fa-align-right"></i>
            <h3>توضیحات بارنامه</h3>
        </div>
        <div class="card-body">
            <div style="font-size: 13px; color: var(--text-main); line-height: 1.6;"><?php echo nl2br(htmlspecialchars($waybill['description'])); ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($can_forward || $can_approve_reject || $can_cancel_forward): ?>
    <div class="action-form">
        <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-bolt" style="color: var(--accent);"></i> اقدامات روی بارنامه</h3>
        <form method="POST" id="actionForm">
            <div class="form-group">
                <label>انتخاب اقدام <span style="color: var(--danger);">*</span></label>
                <select name="action" id="actionSelect" required>
                    <option value="">--- انتخاب کنید ---</option>
                    <?php if ($can_forward): ?>
                        <option value="forward">🔄 بررسی و پیگیری (ارجاع)</option>
                    <?php endif; ?>
                    <?php if ($can_cancel_forward): ?>
                        <option value="cancel_forward">↩️ برگشت ارجاع</option>
                    <?php endif; ?>
                    <?php if ($can_approve_reject): ?>
                        <option value="approve">✅ تایید نهایی</option>
                        <option value="reject">❌ رد بارنامه</option>
                    <?php endif; ?>
                </select>
            </div>
            
            <div id="forwardFields" style="display: none;">
                <div class="form-group">
                    <label>نوع ارجاع <span style="color: var(--danger);">*</span></label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="forward_type" value="department" id="forwardDeptRadio" checked>
                            <i class="fas fa-building"></i> ارجاع به بخش
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="forward_type" value="user" id="forwardUserRadio">
                            <i class="fas fa-user"></i> ارجاع به شخص
                        </label>
                    </div>
                </div>
                
                <div class="row-2col">
                    <div class="form-group" id="departmentSelect">
                        <label>📋 انتخاب بخش</label>
                        <select name="to_department" id="forwardDepartment">
                            <option value="">--- انتخاب کنید ---</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">🏢 <?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="userSelect" style="display: none;">
                        <label>👤 انتخاب شخص</label>
                        <select name="to_user" id="forwardUser">
                            <option value="">--- انتخاب کنید ---</option>
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
                actionNotes.placeholder = 'لطفاً توضیحات بررسی و پیگیری را وارد کنید...';
            } else if (val === 'cancel_forward') {
                forwardFields.style.display = 'none';
                actionNotes.required = true;
                notesRequiredStar.style.display = 'inline';
                actionNotes.placeholder = 'لطفاً دلیل برگشت ارجاع را وارد کنید...';
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
        toggleFields();
        
        const forwardDeptRadio = document.getElementById('forwardDeptRadio');
        const forwardUserRadio = document.getElementById('forwardUserRadio');
        const departmentSelectDiv = document.getElementById('departmentSelect');
        const userSelectDiv = document.getElementById('userSelect');
        const forwardDepartment = document.getElementById('forwardDepartment');
        const forwardUser = document.getElementById('forwardUser');
        
        function updateForwardTypeFields() {
            if (forwardDeptRadio.checked) {
                departmentSelectDiv.style.display = 'block';
                userSelectDiv.style.display = 'none';
                forwardDepartment.disabled = false;
                forwardUser.disabled = true;
                forwardUser.value = '';
            } else {
                departmentSelectDiv.style.display = 'none';
                userSelectDiv.style.display = 'block';
                forwardDepartment.disabled = true;
                forwardUser.disabled = false;
                forwardDepartment.value = '';
            }
        }
        
        if (forwardDeptRadio && forwardUserRadio) {
            forwardDeptRadio.addEventListener('change', updateForwardTypeFields);
            forwardUserRadio.addEventListener('change', updateForwardTypeFields);
            updateForwardTypeFields();
        }
        
        document.getElementById('submitBtn').addEventListener('click', function(e) {
            const selectedAction = actionSelect.value;
            
            if (selectedAction === '') {
                e.preventDefault();
                alert('لطفاً یک اقدام را انتخاب کنید');
                return false;
            }
            
            if (selectedAction === 'forward') {
                const forwardType = document.querySelector('input[name="forward_type"]:checked');
                if (!forwardType) {
                    e.preventDefault();
                    alert('لطفاً نوع ارجاع را انتخاب کنید (بخش یا شخص)');
                    return false;
                }
                
                if (forwardType.value === 'department') {
                    const dept = forwardDepartment.value;
                    if (!dept) {
                        e.preventDefault();
                        alert('لطفاً بخش مقصد را انتخاب کنید');
                        return false;
                    }
                } else {
                    const user = forwardUser.value;
                    if (!user) {
                        e.preventDefault();
                        alert('لطفاً شخص مقصد را انتخاب کنید');
                        return false;
                    }
                }
                
                const notes = actionNotes.value.trim();
                if (notes === '') {
                    e.preventDefault();
                    alert('لطفاً توضیحات را وارد کنید');
                    return false;
                }
            }
            
            if ((selectedAction === 'cancel_forward' || selectedAction === 'reject') && actionNotes.value.trim() === '') {
                e.preventDefault();
                alert('لطفاً توضیحات را وارد کنید');
                return false;
            }
        });
    </script>
    <?php endif; ?>
    
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
                                    elseif ($h['action'] == 'cancel_forward') { $action_class = 'action-cancel'; $action_icon = '↩️'; $action_text = 'برگشت ارجاع'; }
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

<div id="fileModal" class="modal" onclick="closeModal()">
    <span class="close-modal" onclick="closeModal()">&times;</span>
    <div class="modal-content" id="modalContent"></div>
</div>

<script>
function openModal(fileUrl, type) {
    const modal = document.getElementById('fileModal');
    const modalContent = document.getElementById('modalContent');
    if (type === 'image') {
        modalContent.innerHTML = '<img src="' + fileUrl + '" alt="تصویر بارنامه">';
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

function updateCounters() {
    fetch('/invoice-system-v2/ajax/update_counter.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            type: 'waybill', 
            mark_as_viewed: true, 
            doc_id: <?php echo $id; ?> 
        })
    })
    .then(response => response.json())
    .then(data => {
        const invoiceBadge = document.getElementById('invoiceBadge');
        if (invoiceBadge) {
            if (data.invoice_count > 0) {
                invoiceBadge.textContent = data.invoice_count;
                invoiceBadge.style.display = 'inline-block';
            } else {
                invoiceBadge.style.display = 'none';
            }
        }
        const waybillBadge = document.getElementById('waybillBadge');
        if (waybillBadge) {
            if (data.waybill_count > 0) {
                waybillBadge.textContent = data.waybill_count;
                waybillBadge.style.display = 'inline-block';
            } else {
                waybillBadge.style.display = 'none';
            }
        }
        const taxBadge = document.getElementById('taxBadge');
        if (taxBadge) {
            if (data.tax_count > 0) {
                taxBadge.textContent = data.tax_count;
                taxBadge.style.display = 'inline-block';
            } else {
                taxBadge.style.display = 'none';
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

<?php if (($waybill['status'] == 'pending' || $waybill['status'] == 'forwarded') && $is_holder): ?>
    updateCounters();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>