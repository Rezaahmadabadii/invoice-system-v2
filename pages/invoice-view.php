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

// ========== دریافت اطلاعات کاربر ==========
$user_id = $_SESSION['user_id'] ?? 0;
$user_department_id = $_SESSION['user_department_id'] ?? null;
$user_roles = $_SESSION['user_roles'] ?? [];

if (empty($user_roles) && $user_id > 0) {
    $stmt = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ?");
    $stmt->execute([$user_id]);
    $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['user_roles'] = $user_roles;
}

if (!$user_department_id && $user_id > 0) {
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_department_id = $stmt->fetchColumn();
    $_SESSION['user_department_id'] = $user_department_id;
}

// ========== دریافت اطلاعات فاکتور ==========
$stmt = $pdo->prepare("
    SELECT d.*, 
           c.name as company_name, 
           c.short_name,
           v.name as vendor_name,
           creator.full_name as creator_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    LEFT JOIN vendors v ON d.vendor_id = v.id
    LEFT JOIN users creator ON d.created_by = creator.id
    WHERE d.id = ? AND d.type = 'invoice'
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

// ========== دریافت وضعیت تأیید کاربران ==========
$approvals_stmt = $pdo->prepare("
    SELECT da.*, u.full_name, u.username 
    FROM document_approvals da
    JOIN users u ON da.user_id = u.id
    WHERE da.document_id = ?
    ORDER BY 
        FIELD(da.status, 'pending', 'viewed', 'approved', 'rejected'),
        da.created_at ASC
");
$approvals_stmt->execute([$id]);
$approvals = $approvals_stmt->fetchAll();

$status_labels = [
    'pending' => ['text' => 'در انتظار تأیید', 'class' => 'status-pending', 'icon' => '⏳'],
    'viewed' => ['text' => 'مشاهده و در حال بررسی', 'class' => 'status-viewed', 'icon' => '👀'],
    'approved' => ['text' => 'تأیید شده', 'class' => 'status-approved', 'icon' => '✅'],
    'rejected' => ['text' => 'رد شده', 'class' => 'status-rejected', 'icon' => '❌']
];

$total_approvers = count($approvals);
$approved_count = count(array_filter($approvals, fn($a) => $a['status'] == 'approved'));
$rejected_count = count(array_filter($approvals, fn($a) => $a['status'] == 'rejected'));
$pending_count = $total_approvers - ($approved_count + $rejected_count);
$all_approved = ($approved_count == $total_approvers && $total_approvers > 0);

// دریافت تأییدکنندگان (کسانی که فاکتور را تأیید کرده‌اند)
$approvers_list = array_filter($approvals, fn($a) => $a['status'] == 'approved');
$approvers_ids = array_column($approvers_list, 'user_id');

// دریافت درخواست برگشت از مالی
$unlock_request = null;
$unlock_votes = [];
$user_has_voted = false;
$all_approvers_voted = false;

if ($invoice['financial_delivered'] == 1) {
    $req_stmt = $pdo->prepare("SELECT * FROM unlock_requests WHERE document_id = ?");
    $req_stmt->execute([$id]);
    $unlock_request = $req_stmt->fetch();
    
    if ($unlock_request) {
        $vote_stmt = $pdo->prepare("SELECT * FROM unlock_votes WHERE request_id = ?");
        $vote_stmt->execute([$unlock_request['id']]);
        $unlock_votes = $vote_stmt->fetchAll();
        $user_has_voted = !empty(array_filter($unlock_votes, fn($v) => $v['user_id'] == $user_id));
        
        $voted_users = array_column($unlock_votes, 'user_id');
        $all_approvers_voted = !empty($approvers_ids) && empty(array_diff($approvers_ids, $voted_users));
    }
}

$current_status = $invoice['status'];
$is_creator = ($invoice['created_by'] == $user_id);
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);
$is_final_closed = in_array($current_status, ['final_approved', 'rejected']);
$is_financial_delivered = ($invoice['financial_delivered'] ?? 0) == 1;

// بررسی آیا کاربر جاری باید تأیید کند
$user_approval = null;
foreach ($approvals as $a) {
    if ($a['user_id'] == $user_id) {
        $user_approval = $a;
        break;
    }
}

// ========== به‌روزرسانی وضعیت viewed در هنگام مشاهده ==========
if ($user_approval && $user_approval['status'] == 'pending' && !$is_creator && !$is_final_closed && !$is_financial_delivered) {
    $update_viewed = $pdo->prepare("UPDATE document_approvals SET status = 'viewed', viewed_at = NOW() WHERE document_id = ? AND user_id = ?");
    $update_viewed->execute([$id, $user_id]);
    
    $user_approval['status'] = 'viewed';
    foreach ($approvals as &$a) {
        if ($a['user_id'] == $user_id) {
            $a['status'] = 'viewed';
            break;
        }
    }
}

$can_approve = ($user_approval && in_array($user_approval['status'], ['pending', 'viewed']) && !$is_creator && !$is_final_closed && !$is_financial_delivered);
$can_final_close = ($is_creator && $all_approved && $current_status != 'final_approved' && $current_status != 'rejected' && !$is_financial_delivered);
$can_financial_deliver = ($is_creator && $current_status == 'final_approved' && !$is_financial_delivered);
$can_request_unlock = ($is_admin && $is_financial_delivered && (!$unlock_request || $unlock_request['status'] == 'approved'));
$can_vote_unlock = ($is_financial_delivered && $unlock_request && $unlock_request['status'] == 'pending' && in_array($user_id, $approvers_ids) && !$user_has_voted);

$is_draft = ($current_status == 'draft');

// ========== پردازش POST ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // تأیید با رمز توسط کاربر دریافت‌کننده
    if ($action == 'approve_invoice' && $can_approve) {
        $check_pass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $check_pass->execute([$user_id]);
        $user_data = $check_pass->fetch();
        
        if (!password_verify($password, $user_data['password'])) {
            $error = 'رمز عبور اشتباه است. تأیید انجام نشد.';
        } else {
            $update = $pdo->prepare("UPDATE document_approvals SET status = 'approved', action_at = NOW(), comment = ? WHERE document_id = ? AND user_id = ?");
            $update->execute([$comment, $id, $user_id]);
            
            $new_approved_count = $approved_count + 1;
            $new_status = ($new_approved_count == $total_approvers) ? 'ready_to_close' : 'partially_approved';
            $pdo->prepare("UPDATE documents SET approved_count = ?, status = ? WHERE id = ?")->execute([$new_approved_count, $new_status, $id]);
            
            $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'approve', ?)");
            $history->execute([$id, $user_id, 'تأیید با امضای دیجیتال']);
            
            $success = 'فاکتور با موفقیت تأیید شد.';
            echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
        }
    }
    
    // رد توسط کاربر دریافت‌کننده
    elseif ($action == 'reject_invoice' && $can_approve) {
        if (empty($comment)) {
            $error = 'لطفاً دلیل رد را وارد کنید';
        } else {
            $update = $pdo->prepare("UPDATE document_approvals SET status = 'rejected', action_at = NOW(), comment = ? WHERE document_id = ? AND user_id = ?");
            $update->execute([$comment, $id, $user_id]);
            
            $new_rejected_count = $rejected_count + 1;
            $pdo->prepare("UPDATE documents SET rejected_count = ?, status = 'rejected' WHERE id = ?")->execute([$new_rejected_count, $id]);
            
            $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'reject', ?)");
            $history->execute([$id, $user_id, $comment]);
            
            $success = 'فاکتور رد شد.';
            echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
        }
    }
    
    // تأیید نهایی توسط ایجادکننده
    elseif ($action == 'final_approve' && $can_final_close) {
        $update = $pdo->prepare("UPDATE documents SET status = 'final_approved', final_closed_by = ?, final_closed_at = NOW() WHERE id = ?");
        $update->execute([$user_id, $id]);
        
        $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'final_approve', ?)");
        $history->execute([$id, $user_id, 'تأیید نهایی و بستن فاکتور']);
        
        $success = 'فاکتور با موفقیت تأیید نهایی و بسته شد.';
        echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
    }
    
    // تحویل به مالی
    elseif ($action == 'financial_deliver' && $can_financial_deliver) {
        $update = $pdo->prepare("
            UPDATE documents SET 
                financial_delivered = 1, 
                financial_delivered_at = NOW(),
                is_locked = 1
            WHERE id = ?
        ");
        $update->execute([$id]);
        
        $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'financial_deliver', ?)");
        $history->execute([$id, $user_id, 'تحویل به مالی']);
        
        $success = 'فاکتور با موفقیت به مالی تحویل شد.';
        echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
    }
    
    // درخواست برگشت از مالی توسط ادمین
    elseif ($action == 'request_unlock' && $can_request_unlock) {
        $check_pass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $check_pass->execute([$user_id]);
        $user_data = $check_pass->fetch();
        
        if (!password_verify($password, $user_data['password'])) {
            $error = 'رمز عبور اشتباه است. درخواست انجام نشد.';
        } else {
            // حذف درخواست قبلی اگر وجود داشته باشد
            $pdo->prepare("DELETE FROM unlock_requests WHERE document_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM unlock_votes WHERE request_id IN (SELECT id FROM unlock_requests WHERE document_id = ?)")->execute([$id]);
            
            // ایجاد درخواست جدید
            $insert = $pdo->prepare("INSERT INTO unlock_requests (document_id, requested_by, status) VALUES (?, ?, 'pending')");
            $insert->execute([$id, $user_id]);
            
            $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'request_unlock', ?)");
            $history->execute([$id, $user_id, 'درخواست برگشت از مالی توسط ادمین']);
            
            $success = 'درخواست برگشت از مالی ثبت شد. در انتظار تأیید کاربران.';
            echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
        }
    }
    
    // رأی دادن به برگشت از مالی توسط کاربر تأییدکننده
    elseif ($action == 'vote_unlock' && $can_vote_unlock) {
        $check_pass = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $check_pass->execute([$user_id]);
        $user_data = $check_pass->fetch();
        
        if (!password_verify($password, $user_data['password'])) {
            $error = 'رمز عبور اشتباه است. رأی شما ثبت نشد.';
        } else {
            $insert = $pdo->prepare("INSERT INTO unlock_votes (request_id, user_id, approved) VALUES (?, ?, 1)");
            $insert->execute([$unlock_request['id'], $user_id]);
            
            $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'vote_unlock', ?)");
            $history->execute([$id, $user_id, 'تأیید برگشت از مالی با امضای دیجیتال']);
            
            // بررسی آیا همه رأی داده‌اند
            $voted_users = array_column($unlock_votes, 'user_id');
            $voted_users[] = $user_id;
            $all_voted = empty(array_diff($approvers_ids, $voted_users));
            
            if ($all_voted) {
                $pdo->prepare("UPDATE unlock_requests SET status = 'approved', completed_at = NOW() WHERE id = ?")->execute([$unlock_request['id']]);
                $pdo->prepare("UPDATE documents SET financial_delivered = 0, financial_delivered_at = NULL, is_locked = 0, status = 'final_approved' WHERE id = ?")->execute([$id]);
                
                $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'unlock_approved', ?)");
                $history->execute([$id, $user_id, 'برگشت از مالی با تأیید همه کاربران']);
                
                $success = 'برگشت از مالی با موفقیت انجام شد. فاکتور به حالت تأیید نهایی بازگشت.';
                echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
            } else {
                $success = 'رأی شما ثبت شد. در انتظار تأیید سایر کاربران.';
                echo '<script>setTimeout(function() { window.location.reload(); }, 1500);</script>';
            }
        }
    }
    
    // ========== افزودن کامنت جدید ==========
    elseif ($action == 'add_comment' && isset($_POST['submit_comment'])) {
        $comment_text = trim($_POST['comment_text'] ?? '');
        if (!empty($comment_text)) {
            $insert = $pdo->prepare("INSERT INTO document_comments (document_id, user_id, comment) VALUES (?, ?, ?)");
            $insert->execute([$id, $user_id, $comment_text]);
            
            $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'add_comment', ?)");
            $history->execute([$id, $user_id, 'یادداشت اضافه شد: ' . mb_substr($comment_text, 0, 100)]);
            
            $success = 'یادداشت با موفقیت ثبت شد.';
            echo '<script>setTimeout(function() { window.location.reload(); }, 1000);</script>';
        } else {
            $error = 'لطفاً متن یادداشت را وارد کنید.';
        }
    }
    
    // ========== حذف کامنت ==========
    elseif ($action == 'delete_comment') {
        $comment_id = (int)($_POST['comment_id'] ?? 0);
        if ($comment_id > 0) {
            $check = $pdo->prepare("SELECT user_id FROM document_comments WHERE id = ? AND document_id = ?");
            $check->execute([$comment_id, $id]);
            $comment_owner = $check->fetchColumn();
            
            if ($is_admin || $comment_owner == $user_id) {
                $delete = $pdo->prepare("DELETE FROM document_comments WHERE id = ?");
                $delete->execute([$comment_id]);
                
                $history = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, action, notes) VALUES (?, ?, 'delete_comment', ?)");
                $history->execute([$id, $user_id, 'یادداشت حذف شد']);
                
                $success = 'یادداشت با موفقیت حذف شد.';
                echo '<script>setTimeout(function() { window.location.reload(); }, 1000);</script>';
            } else {
                $error = 'شما مجاز به حذف این یادداشت نیستید.';
            }
        }
    }
}

// ========== دریافت کامنت‌های فاکتور ==========
$comments_stmt = $pdo->prepare("
    SELECT dc.*, u.full_name 
    FROM document_comments dc
    JOIN users u ON dc.user_id = u.id
    WHERE dc.document_id = ?
    ORDER BY dc.created_at DESC
");
$comments_stmt->execute([$id]);
$comments = $comments_stmt->fetchAll();
// =======================================

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
    GROUP BY fh.id
    ORDER BY fh.created_at ASC
");
$history_stmt->execute([$id]);
$history = $history_stmt->fetchAll();

$page_title = 'مشاهده فاکتور';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #6366f1;
        --primary-light: #eef2ff;
        --secondary: #8b5cf6;
        --secondary-light: #f5f3ff;
        --accent: #f59e0b;
        --accent-light: #fffbeb;
        --success: #10b981;
        --danger: #ef4444;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
    }
    
    /* استایل کارت تحویل مالی */
    .financial-delivered-card {
        background: #e8f5e9;
        border-right: 4px solid #4caf50;
    }
    
    .financial-delivered-card .card-header {
        background: #c8e6c9;
    }
    
    /* استایل وضعیت درخواست برگشت */
    .unlock-request-pending {
        background: #fef3c7;
        border-right: 4px solid #f59e0b;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    .vote-progress {
        background: #e2e8f0;
        border-radius: 30px;
        height: 8px;
        overflow: hidden;
        margin-top: 10px;
    }
    
    .vote-progress-fill {
        background: linear-gradient(90deg, #f59e0b, #d97706);
        height: 100%;
        border-radius: 30px;
        transition: width 0.5s ease;
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
    .status-viewed { background: #e0f2fe; color: #0284c7; }
    .status-approved { background: #d4edda; color: #2ecc71; }
    .status-rejected { background: #f8d7da; color: #e74c3c; }
    .status-draft { background: #f5f5f5; color: #95a5a6; }
    .status-ready_to_close { background: #fef3c7; color: #d97706; }
    .status-final_approved { background: #10b981; color: white; }
    .status-partially_approved { background: #dbeafe; color: #1d4ed8; }
    .status-pending_approval { background: #fef3c7; color: #b45309; }
    
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
    
    .approval-section {
        margin-bottom: 24px;
    }
    .progress-bar-container {
        background: #e2e8f0;
        border-radius: 30px;
        height: 8px;
        overflow: hidden;
        margin-bottom: 16px;
    }
    .progress-fill {
        background: linear-gradient(90deg, var(--success), #34d399);
        height: 100%;
        border-radius: 30px;
        transition: width 0.5s ease;
    }
    .progress-stats {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .stat-item {
        font-size: 12px;
        color: var(--text-muted);
    }
    .approvers-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }
    .approver-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    .approver-item:hover {
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .approver-icon {
        font-size: 28px;
    }
    .approver-info {
        flex: 1;
    }
    .approver-name {
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 4px;
    }
    .approver-status-text {
        font-size: 10px;
        padding: 2px 8px;
        border-radius: 20px;
        display: inline-block;
    }
    .approver-comment {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 6px;
        padding-top: 4px;
        border-top: 1px dashed #eee;
    }
    .approver-time {
        font-size: 10px;
        color: var(--text-muted);
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
    
    .form-group textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
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
        transition: all 0.2s;
    }
    
    .btn-submit:hover {
        transform: translateY(-1px);
    }
    
    .btn-approve-custom {
        background: linear-gradient(135deg, #10b981, #059669);
    }
    
    .btn-reject-custom {
        background: linear-gradient(135deg, #ef4444, #dc2626);
    }
    
    .btn-final-custom {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    
    .btn-financial-custom {
        background: linear-gradient(135deg, #4caf50, #2e7d32);
    }
    
    .btn-unlock-custom {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }
    
    .btn-vote-custom {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    }
    
    /* مودال رمز عبور */
    .password-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
    }
    
    .password-modal-content {
        background: white;
        border-radius: 20px;
        padding: 25px;
        max-width: 350px;
        width: 90%;
        text-align: center;
    }
    
    .password-modal-content h3 {
        margin-bottom: 15px;
        color: var(--text-main);
    }
    
    .password-modal-content input {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 10px;
        margin-bottom: 15px;
        font-size: 16px;
        text-align: center;
    }
    
    .password-modal-content button {
        padding: 10px 20px;
        border-radius: 30px;
        border: none;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }
    
    .password-modal-content .confirm-btn {
        background: var(--success);
        color: white;
        margin-left: 10px;
    }
    
    .password-modal-content .cancel-btn {
        background: var(--danger);
        color: white;
    }
    
    .history-wrapper {
        max-height: 400px;
        overflow-y: auto;
        overflow-x: auto;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: white;
    }
    
    .history-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        min-width: 600px;
    }
    
    .history-table th {
        background: #f8fafc;
        padding: 12px 10px;
        text-align: center;
        font-weight: 600;
        color: #475569;
        position: sticky;
        top: 0;
        border-bottom: 2px solid #e2e8f0;
        border-left: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    
    .history-table th:first-child {
        border-left: none;
    }
    
    .history-table td {
        padding: 10px 8px;
        text-align: center;
        vertical-align: middle;
        border-bottom: 1px solid #f0f0f0;
        border-left: 1px solid #e2e8f0;
    }
    
    .history-table td:first-child {
        border-left: none;
    }
    
    .history-table td:last-child {
        text-align: right;
    }
    
    .action-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .action-badge.action-approve { background: #d1fae5; color: #065f46; }
    .action-badge.action-reject { background: #fee2e2; color: #b91c1c; }
    .action-badge.action-final { background: #fef3c7; color: #b45309; }
    .action-badge.action-financial { background: #c8e6c9; color: #2e7d32; }
    .action-badge.action-unlock-request { background: #fef3c7; color: #d97706; }
    .action-badge.action-vote-unlock { background: #ede9fe; color: #7c3aed; }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
    }
    .alert-danger { background: #fee8e8; color: var(--danger); border-right: 3px solid var(--danger); }
    .alert-success { background: #d4edda; color: #155724; border-right: 3px solid var(--success); }
    .alert-warning { background: #fef3c7; color: #92400e; border-right: 3px solid #f59e0b; }
    
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
        .approvers-list {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 768px) {
        .three-columns {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- مودال رمز عبور برای تأیید -->
<div id="passwordModal" class="password-modal">
    <div class="password-modal-content">
        <i class="fas fa-lock" style="font-size: 48px; color: var(--primary); margin-bottom: 15px;"></i>
        <h3 id="passwordModalTitle">تأیید امنیتی</h3>
        <p id="passwordModalMessage">لطفاً رمز عبور خود را وارد کنید.</p>
        <input type="password" id="actionPassword" placeholder="رمز عبور">
        <div>
            <button class="confirm-btn" id="confirmActionBtn">تأیید</button>
            <button class="cancel-btn" id="cancelActionBtn">انصراف</button>
        </div>
    </div>
</div>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-file-invoice"></i> مشاهده فاکتور</h1>
    <div style="display: flex; gap: 12px;">
        <?php if ($is_creator && $is_draft): ?>
            <a href="invoice-edit.php?id=<?php echo $id; ?>" style="background: var(--primary); color: white; padding: 8px 18px; border-radius: 30px; text-decoration: none; font-size: 13px;">
                <i class="fas fa-edit"></i> ویرایش
            </a>
        <?php endif; ?>
        <a href="inbox.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- نمایش وضعیت درخواست برگشت از مالی -->
<?php if ($is_financial_delivered && $unlock_request && $unlock_request['status'] == 'pending'): ?>
<div class="unlock-request-pending">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <i class="fas fa-clock" style="color: #d97706;"></i>
            <strong>درخواست برگشت از مالی</strong>
            <span style="font-size: 12px; color: #92400e; display: block;">تاریخ درخواست: <?php echo jdate('Y/m-d H:i', strtotime($unlock_request['requested_at'])); ?></span>
        </div>
        <div>
            <span>تعداد رأی‌ها: <?php echo count($unlock_votes); ?> از <?php echo count($approvers_ids); ?></span>
        </div>
    </div>
    <div class="vote-progress">
        <div class="vote-progress-fill" style="width: <?php echo count($approvers_ids) > 0 ? (count($unlock_votes) / count($approvers_ids)) * 100 : 0; ?>%;"></div>
    </div>
    <?php if ($can_vote_unlock): ?>
        <button type="button" class="btn-submit btn-vote-custom" id="voteUnlockBtn" style="margin-top: 15px; width: 100%;">
            <i class="fas fa-vote-yea"></i> تأیید برگشت از مالی
        </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="three-columns">
    
    <div class="info-card card-blue <?php echo $is_financial_delivered ? 'financial-delivered-card' : ''; ?>">
        <div class="card-header">
            <i class="fas fa-file-alt"></i>
            <h3>اطلاعات فاکتور</h3>
        </div>
        <div class="card-body">
            <div class="info-row">
                <span class="info-label">📄 شماره فاکتور</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['document_number']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">🏷️ عنوان</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['title']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">🏢 شرکت</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['company_name'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">🏪 فروشنده</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['vendor_name'] ?? '-'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">📅 تاریخ فاکتور</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['document_date']); ?></span>
            </div>
            <?php if ($invoice['financial_delivered_at']): ?>
            <div class="info-row" style="background: #e8f5e9; margin-top: 10px; padding: 8px; border-radius: 8px;">
                <span class="info-label" style="color: #2e7d32;">🚚 تاریخ تحویل به مالی</span>
                <span class="info-value" style="color: #2e7d32; font-weight: bold;"><?php echo jdate('Y/m-d', strtotime($invoice['financial_delivered_at'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($invoice['description']): ?>
            <div class="info-row">
                <span class="info-label">📝 توضیحات</span>
                <span class="info-value"><?php echo nl2br(htmlspecialchars($invoice['description'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="info-card card-purple">
        <div class="card-header">
            <i class="fas fa-chart-line"></i>
            <h3>اطلاعات مالی و وضعیت</h3>
        </div>
        <div class="card-body">
            <div class="info-row">
                <span class="info-label">💰 مبلغ</span>
                <span class="info-value amount-value"><?php echo number_format($invoice['amount']); ?> تومان</span>
            </div>
            <div class="info-row">
                <span class="info-label">📅 تاریخ ثبت</span>
                <span class="info-value"><?php echo jdate('Y/m/d', strtotime($invoice['created_at'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">📊 وضعیت</span>
                <span class="info-value">
                    <?php
                    $status_class = 'status-' . ($invoice['status'] ?? 'pending');
                    $status_texts = [
                        'draft' => '📝 پیش‌نویس',
                        'pending_approval' => '⏳ در انتظار تأیید کاربران',
                        'partially_approved' => '🔄 تأیید نسبی',
                        'rejected' => '❌ رد شده',
                        'ready_to_close' => '✅ آماده تأیید نهایی',
                        'final_approved' => '🎉 تأیید نهایی و بسته شده'
                    ];
                    if ($is_financial_delivered) {
                        echo '<span class="status-badge" style="background: #4caf50; color: white;">✅ تحویل مالی شده</span>';
                    } else {
                        echo '<span class="status-badge ' . $status_class . '">' . ($status_texts[$invoice['status']] ?? $invoice['status']) . '</span>';
                    }
                    ?>
                </span>
            </div>
            <?php if ($invoice['contract_number']): ?>
            <div class="info-row">
                <span class="info-label">📄 شماره قرارداد</span>
                <span class="info-value"><?php echo htmlspecialchars($invoice['contract_number']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="info-card card-green">
        <div class="card-header">
            <i class="fas fa-paperclip"></i>
            <h3>فایل فاکتور</h3>
        </div>
        <div class="card-body">
            <?php if ($invoice['file_path'] && file_exists(__DIR__ . '/../' . $invoice['file_path'])): 
                $web_path = str_replace('\\', '/', $invoice['file_path']);
                $file_url = '/invoice-system-v2/' . $web_path;
                $file_ext = strtolower(pathinfo($invoice['file_path'], PATHINFO_EXTENSION));
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
                        <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($invoice['file_name'] ?? basename($invoice['file_path'])); ?></div>
                        <a href="<?php echo $file_url; ?>" download class="file-link" style="margin-top: 5px; display: inline-block;">دانلود</a>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 15px; color: var(--text-muted);">
                    <i class="fas fa-cloud-upload-alt"></i> هیچ فایلی آپلود نشده است
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========== بخش وضعیت تأیید کاربران ========== -->
<?php if ($total_approvers > 0 && !$is_financial_delivered): ?>
<div class="info-card approval-section">
    <div class="card-header">
        <i class="fas fa-users"></i>
        <h3>وضعیت تأیید کاربران</h3>
    </div>
    <div class="card-body">
        <div class="progress-bar-container">
            <div class="progress-fill" style="width: <?php echo $total_approvers > 0 ? (($approved_count / $total_approvers) * 100) : 0; ?>%;"></div>
        </div>
        <div class="progress-stats">
            <span class="stat-item">✅ تأیید شده: <?php echo $approved_count; ?></span>
            <span class="stat-item">❌ رد شده: <?php echo $rejected_count; ?></span>
            <span class="stat-item">⏳ در انتظار: <?php echo $pending_count; ?></span>
            <span class="stat-item">👥 کل: <?php echo $total_approvers; ?></span>
        </div>
        
        <div class="approvers-list">
            <?php foreach ($approvals as $approver): 
                $status_info = $status_labels[$approver['status']] ?? ['text' => $approver['status'], 'class' => 'status-pending', 'icon' => '📌'];
            ?>
            <div class="approver-item">
                <div class="approver-icon"><?php echo $status_info['icon']; ?></div>
                <div class="approver-info">
                    <div class="approver-name"><?php echo htmlspecialchars($approver['full_name']); ?></div>
                    <span class="approver-status-text" style="background: <?php echo $approver['status'] == 'approved' ? '#d1fae5' : ($approver['status'] == 'rejected' ? '#fee2e2' : '#fef3c7'); ?>; color: <?php echo $approver['status'] == 'approved' ? '#065f46' : ($approver['status'] == 'rejected' ? '#b91c1c' : '#b45309'); ?>;">
                        <?php echo $status_info['text']; ?>
                    </span>
                    <?php if (!empty($approver['comment'])): ?>
                        <div class="approver-comment">💬 <?php echo nl2br(htmlspecialchars($approver['comment'])); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($approver['action_at']): ?>
                    <div class="approver-time"><?php echo jdate('H:i Y/m/d', strtotime($approver['action_at'])); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========== بخش کامنت/یادداشت (توضیحات اضافی) ========== -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="card-header">
        <i class="fas fa-pen-alt"></i>
        <h3>توضیحات اضافی</h3>
    </div>
    <div class="card-body">
        <!-- فرم ثبت کامنت جدید -->
        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="add_comment">
            <div class="form-group" style="margin-bottom: 12px;">
                <textarea name="comment_text" rows="2" placeholder="توضیحات اضافی خود را وارد کنید..." style="width: 100%; padding: 10px; border-radius: 10px; border: 1px solid var(--border); resize: vertical;"></textarea>
            </div>
            <div style="text-align: left;">
                <button type="submit" name="submit_comment" class="btn-submit" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); padding: 6px 16px;">
                    <i class="fas fa-save"></i> ثبت یادداشت
                </button>
            </div>
        </form>
        
        <!-- لیست یادداشت‌ها -->
        <?php if (!empty($comments)): ?>
            <div style="border-top: 1px solid var(--border); padding-top: 15px; margin-top: 10px;">
                <h4 style="font-size: 13px; margin-bottom: 12px; color: var(--text-muted);"><i class="fas fa-list"></i> یادداشت‌های ثبت شده</h4>
                <?php foreach ($comments as $comment): ?>
                    <div style="background: #f8fafc; border-radius: 12px; padding: 10px 15px; margin-bottom: 10px; position: relative;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <strong style="font-size: 12px;"><?php echo htmlspecialchars($comment['full_name']); ?></strong>
                                <span style="font-size: 10px; color: #94a3b8; margin-right: 10px;"><?php echo jdate('Y/m-d H:i', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <?php if ($is_admin || $comment['user_id'] == $user_id): ?>
                                <form method="POST" onsubmit="return confirm('آیا از حذف این یادداشت اطمینان دارید؟')">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                    <button type="submit" style="background: none; border: none; cursor: pointer; color: #ef4444; font-size: 14px;" title="حذف">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 12px; margin-top: 8px; margin-bottom: 0; color: var(--text-main);"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========== فرم تأیید/رد برای کاربر دریافت‌کننده (با مودال رمز) ========== -->
<?php if ($can_approve && !$is_financial_delivered): ?>
<div class="action-form" style="background: #f0fdf4;">
    <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-check-circle" style="color: var(--success);"></i> تأیید یا رد فاکتور</h3>
    <div class="form-group">
        <label>نظر شما <span style="color: var(--danger);">*</span></label>
        <textarea name="comment" id="commentText" rows="2" placeholder="لطفاً نظر خود را وارد کنید...(در صورت رد، توضیح دلیل الزامی است)" style="width: 100%; padding: 10px; border-radius: 10px; border: 1px solid var(--border);"></textarea>
    </div>
    <div style="display: flex; gap: 12px; justify-content: flex-end;">
        <button type="button" class="btn-submit btn-reject-custom" id="rejectBtn">
            <i class="fas fa-times"></i> رد فاکتور
        </button>
        <button type="button" class="btn-submit btn-approve-custom" id="approveBtn">
            <i class="fas fa-check"></i> تأیید فاکتور
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ========== دکمه تأیید نهایی برای ایجادکننده ========== -->
<?php if ($can_final_close && !$is_financial_delivered): ?>
<div class="action-form" style="background: #fffbeb;">
    <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-gavel" style="color: var(--accent);"></i> تأیید نهایی و بستن فاکتور</h3>
    <p style="margin-bottom: 16px; font-size: 13px; color: #92400e;">
        <i class="fas fa-info-circle"></i> 
        همه <?php echo $total_approvers; ?> کاربر این فاکتور را تأیید کرده‌اند. 
        پس از تأیید نهایی، فاکتور بسته می‌شود و هیچ تغییری در آن امکان‌پذیر نیست.
    </p>
    <form method="POST" style="text-align: left;">
        <input type="hidden" name="action" value="final_approve">
        <button type="submit" class="btn-submit btn-final-custom" onclick="return confirm('آیا از تأیید نهایی و بستن این فاکتور اطمینان دارید؟')">
            <i class="fas fa-lock"></i> تأیید نهایی و بستن فاکتور
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ========== دکمه تحویل به مالی برای ایجادکننده ========== -->
<?php if ($can_financial_deliver): ?>
<div class="action-form" style="background: #e8f5e9;">
    <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-truck" style="color: #4caf50;"></i> تحویل به مالی</h3>
    <p style="margin-bottom: 16px; font-size: 13px; color: #2e7d32;">
        <i class="fas fa-info-circle"></i> 
        فاکتور تأیید نهایی شده است. با زدن دکمه زیر، فاکتور به مالی تحویل می‌شود و قفل مطلق می‌شود.
    </p>
    <form method="POST" style="text-align: left;">
        <input type="hidden" name="action" value="financial_deliver">
        <button type="submit" class="btn-submit btn-financial-custom" onclick="return confirm('آیا از تحویل این فاکتور به مالی اطمینان دارید؟ پس از این عملیات، فاکتور قابل ویرایش یا حذف نیست.')">
            <i class="fas fa-check-double"></i> تحویل مالی شد
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ========== دکمه درخواست برگشت از مالی برای ادمین ========== -->
<?php if ($can_request_unlock): ?>
<div class="action-form" style="background: #fef3c7;">
    <h3 style="margin-bottom: 18px; font-size: 16px;"><i class="fas fa-undo-alt" style="color: #d97706;"></i> برگشت از مالی</h3>
    <p style="margin-bottom: 16px; font-size: 13px; color: #92400e;">
        <i class="fas fa-info-circle"></i> 
        این فاکتور قبلاً به مالی تحویل شده است. برای برگشت به حالت «تأیید نهایی»، درخواست بدهید و منتظر تأیید همه کاربران بمانید.
    </p>
    <button type="button" class="btn-submit btn-unlock-custom" id="requestUnlockBtn">
        <i class="fas fa-undo-alt"></i> درخواست برگشت از مالی
    </button>
</div>
<?php endif; ?>

<!-- ========== تاریخچه ========== -->
<div class="info-card">
    <div class="card-header">
        <i class="fas fa-history"></i>
        <h3>تاریخچه فاکتور</h3>
    </div>
    <div class="card-body">
        <?php if (empty($history)): ?>
            <p style="color: var(--text-muted); text-align: center; padding: 20px;">هیچ اقدامی ثبت نشده است.</p>
        <?php else: ?>
            <div class="history-wrapper">
                <table class="history-table">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569; border-left: 1px solid #e2e8f0;">زمان</th>
                            <th style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569; border-left: 1px solid #e2e8f0;">از</th>
                            <th style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569; border-left: 1px solid #e2e8f0;">به</th>
                            <th style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569; border-left: 1px solid #e2e8f0;">اقدام</th>
                            <th style="padding: 12px 10px; text-align: center; font-weight: 600; color: #475569;">توضیحات</th>
                        <tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 10px 8px; text-align: center; vertical-align: middle;"><?php echo jdate('Y/m-d', strtotime($h['created_at'])); ?></td>
                            <td style="padding: 10px 8px; text-align: center; vertical-align: middle;"><?php echo htmlspecialchars(trim($h['from_name'] ?? '-')); ?></td>
                            <td style="padding: 10px 8px; text-align: center; vertical-align: middle;"><?php echo htmlspecialchars(trim($h['to_name'] ?? ($h['to_department_name'] ?? '-'))); ?></td>
                            <td style="padding: 10px 8px; text-align: center; vertical-align: middle;">
                                <?php
                                $action_icon = '';
                                $action_class = '';
                                $action_text = '';
                                if ($h['action'] == 'approve') { $action_icon = '✅'; $action_class = 'action-approve'; $action_text = 'تأیید'; }
                                elseif ($h['action'] == 'reject') { $action_icon = '❌'; $action_class = 'action-reject'; $action_text = 'رد'; }
                                elseif ($h['action'] == 'final_approve') { $action_icon = '🔒'; $action_class = 'action-final'; $action_text = 'تأیید نهایی'; }
                                elseif ($h['action'] == 'financial_deliver') { $action_icon = '🚚'; $action_class = 'action-financial'; $action_text = 'تحویل به مالی'; }
                                elseif ($h['action'] == 'request_unlock') { $action_icon = '📢'; $action_class = 'action-unlock-request'; $action_text = 'درخواست برگشت'; }
                                elseif ($h['action'] == 'vote_unlock') { $action_icon = '🗳️'; $action_class = 'action-vote-unlock'; $action_text = 'رأی به برگشت'; }
                                elseif ($h['action'] == 'unlock_approved') { $action_icon = '🔓'; $action_class = 'action-final'; $action_text = 'برگشت از مالی'; }
                                elseif ($h['action'] == 'add_comment') { $action_icon = '💬'; $action_class = 'action-approve'; $action_text = 'یادداشت'; }
                                elseif ($h['action'] == 'delete_comment') { $action_icon = '🗑️'; $action_class = 'action-reject'; $action_text = 'حذف یادداشت'; }
                                else { $action_icon = '📌'; $action_text = $h['action']; }
                                ?>
                                <span class="action-badge <?php echo $action_class; ?>">
                                    <?php echo $action_icon . ' ' . $action_text; ?>
                                </span>
                            </td>
                            <td style="padding: 10px 8px; word-wrap: break-word; text-align: right; vertical-align: middle;"><?php echo nl2br(htmlspecialchars($h['notes'] ?? '-')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="fileModal" class="modal" onclick="closeModal()">
    <span class="close-modal" onclick="closeModal()">&times;</span>
    <div class="modal-content" id="modalContent"></div>
</div>

<script>
// ========== متغیرهای عمومی ==========
let pendingAction = '';
let pendingComment = '';

// ========== مودال رمز عبور ==========
const passwordModal = document.getElementById('passwordModal');
const passwordInput = document.getElementById('actionPassword');
const confirmBtn = document.getElementById('confirmActionBtn');
const cancelBtn = document.getElementById('cancelActionBtn');
const modalTitle = document.getElementById('passwordModalTitle');
const modalMessage = document.getElementById('passwordModalMessage');

function showPasswordModal(action, title, message, comment = '') {
    pendingAction = action;
    pendingComment = comment;
    modalTitle.textContent = title;
    modalMessage.textContent = message;
    passwordModal.style.display = 'flex';
    passwordInput.value = '';
}

function closePasswordModal() {
    passwordModal.style.display = 'none';
    pendingAction = '';
}

if (confirmBtn) {
    confirmBtn.addEventListener('click', function() {
        const password = passwordInput.value;
        if (!password) {
            alert('لطفاً رمز عبور خود را وارد کنید');
            return;
        }
        
        if (pendingAction === 'approve') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'approve_invoice';
            form.appendChild(actionInput);
            
            const commentInput = document.createElement('input');
            commentInput.name = 'comment';
            commentInput.value = pendingComment;
            form.appendChild(commentInput);
            
            const passwordField = document.createElement('input');
            passwordField.name = 'password';
            passwordField.value = password;
            form.appendChild(passwordField);
            
            document.body.appendChild(form);
            form.submit();
        } 
        else if (pendingAction === 'request_unlock') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'request_unlock';
            form.appendChild(actionInput);
            
            const passwordField = document.createElement('input');
            passwordField.name = 'password';
            passwordField.value = password;
            form.appendChild(passwordField);
            
            document.body.appendChild(form);
            form.submit();
        }
        else if (pendingAction === 'vote_unlock') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'vote_unlock';
            form.appendChild(actionInput);
            
            const passwordField = document.createElement('input');
            passwordField.name = 'password';
            passwordField.value = password;
            form.appendChild(passwordField);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        closePasswordModal();
    });
}

if (cancelBtn) {
    cancelBtn.addEventListener('click', closePasswordModal);
}

window.addEventListener('click', function(event) {
    if (event.target === passwordModal) {
        closePasswordModal();
    }
});

// ========== دکمه تأیید فاکتور ==========
const approveBtn = document.getElementById('approveBtn');
const commentText = document.getElementById('commentText');
if (approveBtn) {
    approveBtn.addEventListener('click', function() {
        const comment = commentText ? commentText.value : '';
        showPasswordModal('approve', 'تأیید امنیتی', 'لطفاً رمز عبور خود را برای تأیید این فاکتور وارد کنید.', comment);
    });
}

// ========== دکمه رد فاکتور ==========
const rejectBtn = document.getElementById('rejectBtn');
if (rejectBtn) {
    rejectBtn.addEventListener('click', function(e) {
        if (commentText && commentText.value.trim() === '') {
            e.preventDefault();
            alert('لطفاً دلیل رد را وارد کنید');
            return false;
        }
    });
}

// ========== دکمه درخواست برگشت از مالی ==========
const requestUnlockBtn = document.getElementById('requestUnlockBtn');
if (requestUnlockBtn) {
    requestUnlockBtn.addEventListener('click', function() {
        showPasswordModal('request_unlock', 'درخواست برگشت از مالی', 'لطفاً رمز عبور خود را برای ثبت درخواست برگشت از مالی وارد کنید.');
    });
}

// ========== دکمه رأی به برگشت از مالی ==========
const voteUnlockBtn = document.getElementById('voteUnlockBtn');
if (voteUnlockBtn) {
    voteUnlockBtn.addEventListener('click', function() {
        showPasswordModal('vote_unlock', 'تأیید برگشت از مالی', 'لطفاً رمز عبور خود را برای تأیید برگشت از مالی وارد کنید.');
    });
}

// ========== به‌روزرسانی شمارنده منو ==========
function updateCounters() {
    fetch('/invoice-system-v2/ajax/update_counter.php')
        .then(response => response.json())
        .then(data => {
            const invoiceBadge = document.getElementById('invoiceBadge');
            if (invoiceBadge) {
                const count = data.invoice_count || 0;
                invoiceBadge.textContent = count;
                invoiceBadge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        })
        .catch(error => console.error('خطا:', error));
}

// ========== بررسی تغییر وضعیت فاکتور ==========
let lastStatus = document.querySelector('.status-badge')?.innerHTML;
let lastAction = document.querySelector('.action-form')?.innerHTML;

setInterval(() => {
    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            const newStatus = doc.querySelector('.status-badge')?.innerHTML;
            const newAction = doc.querySelector('.action-form')?.innerHTML;
            
            if ((newStatus && lastStatus && newStatus !== lastStatus) ||
                (newAction && lastAction && newAction !== lastAction)) {
                window.location.reload();
            }
            
            lastStatus = newStatus;
            lastAction = newAction;
        })
        .catch(error => console.error('خطا:', error));
    
    updateCounters();
}, 3000);

// ========== توابع مودال فایل ==========
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

// ========== اجرای اولیه ==========
updateCounters();
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>