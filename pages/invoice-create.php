<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('create_invoice')) {
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

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, contract_number, national_id FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT id, name FROM workshops ORDER BY name")->fetchAll();

// دریافت لیست کاربران فعال (به جز خود کاربر) برای ارجاع چندنفره
$user_id = $_SESSION['user_id'];
$users_stmt = $pdo->prepare("
    SELECT id, full_name, username, department_id 
    FROM users 
    WHERE id != ? AND (status = 'active' OR status IS NULL)
    ORDER BY full_name
");
$users_stmt->execute([$user_id]);
$users = $users_stmt->fetchAll();

$error = '';
$success = '';
$duplicate_warning = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['create_invoice']) || isset($_POST['save_draft']))) {
    $is_draft = isset($_POST['save_draft']);
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $vendor_id = $_POST['vendor_id'] ?? '';
    $workshop_id = $_POST['workshop_id'] ?? '';
    $contract_number = trim($_POST['contract_number'] ?? '');
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $discount = str_replace(',', '', $_POST['discount'] ?? 0);
    $total = str_replace(',', '', $_POST['total'] ?? 0);
    $vat = isset($_POST['vat']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    $force_save = isset($_POST['force_save']) ? 1 : 0;
    
    // دریافت کاربران انتخاب شده برای ارجاع
    $selected_users = $_POST['selected_users'] ?? [];
    
    $errors = [];
    
    // اعتبارسنجی (در حالت پیش‌نویس، فقط عنوان لازم است)
    if (empty($invoice_number)) $errors[] = 'شماره فاکتور الزامی است';
    if (empty($invoice_date)) $errors[] = 'تاریخ فاکتور الزامی است';
    if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
    if (!$is_draft && empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
    if (!$is_draft && (empty($amount) || $amount <= 0)) $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
    
    // اعتبارسنجی برای حالت غیر پیش‌نویس: حداقل یک کاربر باید انتخاب شود
    if (!$is_draft && empty($selected_users)) {
        $errors[] = 'لطفاً حداقل یک کاربر را به عنوان دریافت‌کننده انتخاب کنید';
    }
    
    // بررسی آپلود فایل (در حالت پیش‌نویس اختیاری است)
    if (!$is_draft && (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] != UPLOAD_ERR_OK)) {
        $errors[] = 'آپلود فایل فاکتور الزامی است';
    } elseif (!$is_draft && isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024;
        if (!in_array($_FILES['invoice_file']['type'], $allowed_types)) {
            $errors[] = 'نوع فایل باید JPEG، PNG یا PDF باشد';
        }
        if ($_FILES['invoice_file']['size'] > $max_size) {
            $errors[] = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
        }
    }
    
    $companyInfo = $pdo->prepare("SELECT short_name FROM companies WHERE id = ?");
    $companyInfo->execute([$company_id]);
    $companyData = $companyInfo->fetch();
    $short_name = $companyData['short_name'] ?? '';
    $final_invoice_number = !empty($short_name) ? $short_name . '-' . $invoice_number : $invoice_number;
    
    // بررسی تکراری بودن (فقط برای حالت غیر پیش‌نویس)
    if (!$is_draft && (empty($errors) || $force_save)) {
        $check = $pdo->prepare("SELECT id FROM documents WHERE document_number = ? AND type = 'invoice'");
        $check->execute([$final_invoice_number]);
        $existing = $check->fetch();
        if ($existing && !$force_save) {
            $duplicate_warning = 'این شماره فاکتور قبلاً ثبت شده است. آیا مطمئن هستید؟';
        }
    }
    
    if (empty($errors) && (empty($duplicate_warning) || $force_save || $is_draft)) {
        $upload_dir = __DIR__ . '/../uploads/invoices/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file_path = null;
        $file_name = null;
        
        // آپلود فایل (در صورت وجود)
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
            $file_ext = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
            $new_file_name = $final_invoice_number . '.' . $file_ext;
            $file_path = 'uploads/invoices/' . $new_file_name;
            $file_name = $_FILES['invoice_file']['name'];
            move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $new_file_name);
        }
        
        // شماره سند داخلی
        $doc_number = $is_draft ? 'DRAFT-' . $invoice_number : $final_invoice_number;
        
        // تعیین وضعیت اولیه فاکتور
        if ($is_draft) {
            $status = 'draft';
            $total_approvers = 0;
        } else {
            $status = 'pending_approval';
            $total_approvers = count($selected_users);
        }
        
        // محاسبه مبلغ نهایی
        $amount_clean = floatval(str_replace(',', '', $amount));
        $discount_clean = floatval(str_replace(',', '', $discount));
        $final_amount = $amount_clean - $discount_clean;
        if ($vat && !$is_draft) {
            $final_amount = $final_amount + ($amount_clean * 0.1);
        }
        
        $data = [
            'document_number' => $doc_number,
            'type' => 'invoice',
            'title' => 'فاکتور شماره ' . $invoice_number,
            'description' => $description,
            'amount' => $total > 0 ? $total : $final_amount,
            'created_by' => $_SESSION['user_id'],
            'company_id' => $company_id,
            'workshop_id' => $workshop_id ?: null,
            'vendor_id' => $vendor_id ?: null,
            'department_id' => 1,
            'document_date' => $invoice_date,
            'status' => $status,
            'contract_number' => $contract_number ?: null,
            'vat' => $vat,
            'vat_amount' => $vat ? ($amount_clean * 0.1) : 0,
            'file_path' => $file_path,
            'file_name' => $file_name,
            'total_approvers' => $total_approvers,
            'approved_count' => 0,
            'rejected_count' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // حذف کلیدهای null
        $data = array_filter($data, fn($v) => $v !== null);
        
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO documents ($fields) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute($data)) {
            $doc_id = $pdo->lastInsertId();
            
            // ثبت وضعیت برای هر کاربر انتخاب شده (در حالت غیر پیش‌نویس)
            if (!$is_draft && !empty($selected_users)) {
                $insert_approval = $pdo->prepare("
                    INSERT INTO document_approvals (document_id, user_id, status) 
                    VALUES (?, ?, 'pending')
                ");
                
                $insert_history = $pdo->prepare("
                    INSERT INTO forwarding_history (document_id, from_user_id, to_user_id, action, notes) 
                    VALUES (?, ?, ?, 'forward', ?)
                ");
                
                foreach ($selected_users as $uid) {
                    $insert_approval->execute([$doc_id, $uid]);
                    $insert_history->execute([$doc_id, $_SESSION['user_id'], $uid, "ارسال فاکتور برای تأیید"]);
                }
            }
            
            logActivity($_SESSION['user_id'], $is_draft ? 'create_invoice_draft' : 'create_invoice', 
                      ($is_draft ? 'پیش‌نویس فاکتور: ' : 'فاکتور جدید: ') . $invoice_number, $doc_id);
            
            if ($is_draft) {
                $_SESSION['message'] = 'پیش‌نویس فاکتور با موفقیت ذخیره شد.';
                $_SESSION['message_type'] = 'success';
                header('Location: inbox.php');
                exit;
            } else {
                $success = 'فاکتور با موفقیت ثبت و برای ' . count($selected_users) . ' نفر ارسال شد. شماره فاکتور: ' . $final_invoice_number;
                echo '<script>setTimeout(function() { window.location.href = "inbox.php"; }, 2000);</script>';
            }
        } else {
            $errors[] = 'خطا در ثبت فاکتور';
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$page_title = 'ایجاد فاکتور جدید';
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
    
    .form-wrapper {
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
    
    .two-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .three-columns {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
        margin-bottom: 24px;
    }
    
    .full-width-card {
        width: 100%;
        margin-bottom: 24px;
    }
    
    .full-width-card .form-card {
        width: 100%;
    }
    
    .form-card {
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
        padding: 18px 20px;
    }
    
    .card-primary .card-header { background: var(--primary-light); }
    .card-primary .card-header i { color: var(--primary); }
    .card-secondary .card-header { background: var(--secondary-light); }
    .card-secondary .card-header i { color: var(--secondary); }
    .card-accent .card-header { background: var(--accent-light); }
    .card-accent .card-header i { color: var(--accent); }
    
    .form-group {
        margin-bottom: 14px;
    }
    
    .form-group label {
        display: block;
        font-size: 12px;
        font-weight: 500;
        color: var(--text-main);
        margin-bottom: 4px;
    }
    
    .form-group label .required {
        color: var(--danger);
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
        font-family: inherit;
        transition: all 0.2s;
        background: white;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(99,102,241,0.1);
    }
    
    .help-text {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 4px;
        display: block;
    }
    
    .total-box {
        background: linear-gradient(135deg, var(--primary-light), var(--secondary-light));
        padding: 12px;
        border-radius: 12px;
        text-align: center;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    
    .total-box .label {
        font-size: 11px;
        color: var(--text-muted);
    }
    
    .total-box .value {
        font-size: 20px;
        font-weight: bold;
        color: var(--primary);
    }
    
    /* ========== گرید چندستونه برای لیست کاربران (برای ردیف سوم قدیمی) ========== */
    .users-grid-list {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        max-height: 350px;
        overflow-y: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: white;
        padding: 12px;
    }
    
    .user-checkbox-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    
    .user-checkbox-item:hover {
        background: #eef2ff;
        border-color: var(--primary);
        transform: translateX(2px);
    }
    
    .user-checkbox-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin: 0;
        accent-color: var(--primary);
        flex-shrink: 0;
    }
    
    .user-checkbox-item .user-info {
        flex: 1;
        min-width: 0;
    }
    
    .user-checkbox-item .user-name {
        font-weight: 600;
        font-size: 13px;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .user-checkbox-item .user-username {
        font-size: 10px;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* ========== گرید عمودی 2 ستونی برای لیست کاربران (نسخه جدید) ========== */
    .users-grid-list-vertical {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        max-height: 400px;
        overflow-y: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: white;
        padding: 12px;
    }
    
    .user-checkbox-item-vertical {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    
    .user-checkbox-item-vertical:hover {
        background: #eef2ff;
        border-color: var(--primary);
        transform: translateX(2px);
    }
    
    .user-checkbox-item-vertical input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin: 0;
        accent-color: var(--primary);
        flex-shrink: 0;
    }
    
    .user-checkbox-item-vertical .user-info {
        flex: 1;
        min-width: 0;
    }
    
    .user-checkbox-item-vertical .user-name {
        font-weight: 600;
        font-size: 13px;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .user-checkbox-item-vertical .user-username {
        font-size: 10px;
        color: var(--text-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* ===================================================== */
    
    .selected-count-badge {
        background: var(--primary-light);
        color: var(--primary);
        padding: 10px 16px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 16px;
        transition: all 0.2s;
    }
    
    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 16px;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        font-family: inherit;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        box-shadow: 0 2px 8px rgba(99,102,241,0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(99,102,241,0.4);
    }
    
    .btn-draft {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
    }
    
    .btn-draft:hover {
        transform: translateY(-1px);
    }
    
    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text-muted);
    }
    
    .btn-outline:hover {
        background: #f8fafc;
        border-color: var(--primary);
        color: var(--primary);
    }
    
    .file-preview {
        margin-top: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        background: var(--bg-page);
        border-radius: 10px;
    }
    
    .file-preview img {
        width: 50px;
        height: 50px;
        border-radius: 8px;
        object-fit: cover;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 500;
    }
    
    .alert-danger {
        background: #fee8e8;
        color: var(--danger);
        border-right: 3px solid var(--danger);
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border-right: 3px solid var(--success);
    }
    
    .alert-warning {
        background: var(--accent-light);
        color: var(--accent);
        border-right: 3px solid var(--accent);
    }
    
    /* انیمیشن */
    .coin-area {
        display: inline-block;
        margin-left: 10px;
        vertical-align: middle;
        overflow: hidden;
        width: 30px;
    }
    
    .coin-icon {
        font-size: 22px;
        display: inline-block;
        transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        cursor: pointer;
    }
    
    .coin-spin-move {
        animation: spinAndMove 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
    }
    
    @keyframes spinAndMove {
        0% { transform: rotate(0deg) translateX(0); opacity: 1; }
        100% { transform: rotate(360deg) translateX(50px); opacity: 0; }
    }
    
    /* ========== واکنش‌گرا ========== */
    @media (max-width: 1000px) {
        .three-columns {
            grid-template-columns: repeat(2, 1fr);
        }
        .users-grid-list {
            grid-template-columns: repeat(2, 1fr);
        }
        .users-grid-list-vertical {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 900px) {
        .two-columns {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .three-columns {
            grid-template-columns: 1fr;
        }
        .form-wrapper {
            padding: 16px;
        }
        .form-actions {
            flex-direction: column;
        }
        .btn {
            width: 100%;
            text-align: center;
        }
    }
    
    @media (max-width: 600px) {
        .users-grid-list {
            grid-template-columns: 1fr;
        }
        .users-grid-list-vertical {
            grid-template-columns: 1fr;
        }
    }
    /* ===================================== */
</style>

<div class="form-wrapper">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-invoice" style="color: var(--primary);"></i> ایجاد فاکتور جدید</h1>
        <a href="inbox.php" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if ($duplicate_warning): ?>
        <div class="alert alert-warning">
            <p><?php echo $duplicate_warning; ?></p>
            <form method="POST" enctype="multipart/form-data" style="margin-top: 10px;">
                <?php foreach ($_POST as $key => $value): if (is_array($value)) continue; ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endforeach; ?>
                <input type="hidden" name="force_save" value="1">
                <button type="submit" name="create_invoice" style="background: var(--accent); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer;">بله، ثبت شود</button>
                <a href="invoice-create.php" style="background: #95a5a6; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; margin-left: 10px;">خیر، انصراف</a>
            </form>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" id="invoiceForm">
        <!-- ردیف اول: اطلاعات اصلی فاکتور + شرکت و فروشنده (2 ستون) -->
        <div class="two-columns">
            
            <!-- کارت 1: اطلاعات اصلی فاکتور -->
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-file-alt"></i>
                    <h3>اطلاعات اصلی فاکتور</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>شماره فاکتور <span class="required">*</span></label>
                        <input type="text" name="invoice_number" required value="<?php echo htmlspecialchars($_POST['invoice_number'] ?? ''); ?>" placeholder="مثال: 1234">
                        <small class="help-text">با مخفف شرکت ترکیب می‌شود (مثال: kyhn-1234)</small>
                    </div>
                    <div class="form-group">
                        <label>تاریخ فاکتور (شمسی) <span class="required">*</span></label>
                        <input type="text" name="invoice_date" value="<?php echo htmlspecialchars($_POST['invoice_date'] ?? jdate('Y/m/d')); ?>" placeholder="۱۴۰۴/۱۲/۰۷">
                    </div>
                    <div class="form-group">
                        <label>شماره قرارداد</label>
                        <input type="text" name="contract_number" value="<?php echo htmlspecialchars($_POST['contract_number'] ?? ''); ?>" placeholder="اختیاری">
                        <small class="help-text">در صورت وجود قرارداد، شماره آن را وارد کنید</small>
                    </div>
                    <div class="form-group">
                        <label>توضیحات فاکتور</label>
                        <textarea name="description" rows="3" placeholder="توضیحات اضافی (اختیاری)..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        <small class="help-text">توضیحات مربوط به فاکتور را وارد کنید</small>
                    </div>
                </div>
            </div>
            
            <!-- کارت 2: شرکت و فروشنده -->
            <div class="form-card card-secondary">
                <div class="card-header">
                    <i class="fas fa-building"></i>
                    <h3>شرکت و فروشنده</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>انتخاب شرکت <span class="required">*</span></label>
                        <select name="company_id" id="companySelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?php echo $comp['id']; ?>" data-short="<?php echo $comp['short_name']; ?>" <?php echo ($_POST['company_id'] ?? '') == $comp['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($comp['name']); ?>
                                    <?php if ($comp['short_name']): ?>
                                        (<?php echo htmlspecialchars($comp['short_name']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>فروشنده/فروشگاه <span class="required">*</span></label>
                        <select name="vendor_id" id="vendorSelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>" data-national="<?php echo $vendor['national_id'] ?? ''; ?>" <?php echo ($_POST['vendor_id'] ?? '') == $vendor['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($vendor['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>کارگاه/بخش پروژه</label>
                        <select name="workshop_id">
                            <option value="">انتخاب کنید (اختیاری)</option>
                            <?php foreach ($workshops as $ws): ?>
                                <option value="<?php echo $ws['id']; ?>" <?php echo ($_POST['workshop_id'] ?? '') == $ws['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ws['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف دوم: اطلاعات مالی + جمع کل (در یک باکس) و لیست دریافت‌کنندگان -->
        <div class="two-columns">
            
            <!-- کارت 3: اطلاعات مالی + جمع کل -->
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>اطلاعات مالی</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>مبلغ پایه (تومان) <span class="required">*</span></label>
                        <input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" placeholder="۰">
                    </div>
                    <div class="form-group">
                        <label>تخفیف (تومان)</label>
                        <input type="text" name="discount" id="discountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['discount'] ?? '0'); ?>" placeholder="۰">
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="vat" id="vatCheckbox" onchange="calculateTotal();" <?php echo isset($_POST['vat']) ? 'checked' : ''; ?>> 
                            ارزش افزوده (۱۰٪)
                        </label>
                    </div>
                    
                    <!-- خط جداکننده -->
                    <hr style="margin: 20px 0; border: none; border-top: 2px dashed var(--border);">
                    
                    <!-- جمع کل -->
                    <div class="total-box" style="background: transparent; padding: 0; text-align: right;">
                        <div class="label" style="font-size: 13px; color: var(--text-muted);">💰 جمع کل قابل پرداخت</div>
                        <div class="value" id="totalDisplay" style="font-size: 24px; color: var(--success);">0 تومان</div>
                        <input type="hidden" name="total" id="totalInput" value="0">
                    </div>
                </div>
            </div>
            
            <!-- کارت 4: انتخاب دریافت‌کنندگان (عمودی 2 ستونی) -->
            <div class="form-card card-accent">
                <div class="card-header">
                    <i class="fas fa-users"></i>
                    <h3>انتخاب دریافت‌کنندگان <span class="required" id="forwardRequired">*</span></h3>
                </div>
                <div class="card-body">
                    <div class="forward-section" style="background: #f0f9ff; padding: 16px; border-radius: 16px;">
                        <p style="margin-bottom: 16px; font-size: 12px; color: #0369a1;">
                            <i class="fas fa-info-circle"></i> 
                            کاربران انتخاب شده می‌توانند این فاکتور را تأیید یا رد کنند. 
                            پس از تأیید همه کاربران، شما می‌توانید فاکتور را نهایی کنید.
                        </p>
                        
                        <!-- لیست کاربران با گرید عمودی 2 ستونی -->
                        <div class="users-grid-list-vertical">
                            <?php if (empty($users)): ?>
                                <div style="padding: 20px; text-align: center; color: #64748b; grid-column: span 2;">
                                    <i class="fas fa-user-slash"></i> کاربر دیگری یافت نشد
                                </div>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <label class="user-checkbox-item-vertical">
                                        <input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" 
                                               class="user-checkbox"
                                               <?php echo (isset($_POST['selected_users']) && in_array($user['id'], $_POST['selected_users'])) ? 'checked' : ''; ?>>
                                        <div class="user-info">
                                            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                            <div class="user-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="selected-count-badge" id="selectedCountBadge">
                            <i class="fas fa-check-circle"></i> <span id="selectedCount">0</span> کاربر انتخاب شده است
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف سوم: فایل فاکتور -->
        <div class="two-columns">
            
            <!-- کارت 5: فایل فاکتور -->
            <div class="form-card card-accent" style="width: 100%;">
                <div class="card-header">
                    <i class="fas fa-paperclip"></i>
                    <h3>فایل فاکتور</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>آپلود فایل فاکتور <span class="required" id="fileRequired">*</span></label>
                        <input type="file" name="invoice_file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf" <?php echo !isset($_POST['save_draft']) ? 'required' : ''; ?>>
                        <small class="help-text">فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
                        <div id="filePreview" class="file-preview" style="display: none;"></div>
                    </div>
                </div>
            </div>
            
            <!-- فضای خالی برای تعادل -->
            <div style="visibility: hidden;"></div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="save_draft" value="1" class="btn btn-draft" id="draftBtn">
                <i class="fas fa-save"></i> ذخیره پیش‌نویس
            </button>
            <button type="submit" name="create_invoice" value="1" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-paper-plane"></i> ثبت و ارسال برای کاربران
            </button>
            <a href="inbox.php" class="btn btn-outline">انصراف</a>
        </div>
    </form>
</div>

<script>
// فرمت اعداد
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}

// محاسبه جمع کل
function calculateTotal() {
    let amount = parseFloat(document.getElementById('amountInput').value.replace(/,/g, '')) || 0;
    let discount = parseFloat(document.getElementById('discountInput').value.replace(/,/g, '')) || 0;
    let vat = document.getElementById('vatCheckbox').checked;
    
    let total = amount - discount;
    if (vat) {
        total = total + (amount * 0.1);
    }
    
    document.getElementById('totalDisplay').innerHTML = total.toLocaleString() + ' تومان';
    document.getElementById('totalInput').value = total;
}

// ========== شمارش کاربران انتخاب شده ==========
const checkboxes = document.querySelectorAll('.user-checkbox');
const selectedCountSpan = document.getElementById('selectedCount');
const selectedCountBadge = document.getElementById('selectedCountBadge');

function updateSelectedCount() {
    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
    selectedCountSpan.textContent = checkedCount;
    
    if (checkedCount === 0) {
        selectedCountBadge.style.background = '#fee2e2';
        selectedCountBadge.style.color = '#dc2626';
    } else {
        selectedCountBadge.style.background = '#eef2ff';
        selectedCountBadge.style.color = '#4f46e5';
    }
}

checkboxes.forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});
updateSelectedCount();
// =============================================

// پیش‌نمایش فایل
document.getElementById('fileInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('filePreview');
    if (file) {
        const size = (file.size / 1024).toFixed(1);
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = ev => { preview.innerHTML = `<img src="${ev.target.result}"><span style="font-size:11px;">${file.name} (${size} KB)</span>`; preview.style.display = 'flex'; };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `<span>📎 ${file.name} (${size} KB)</span>`;
            preview.style.display = 'flex';
        }
    } else { preview.style.display = 'none'; }
});

// اعتبارسنجی برای ثبت نهایی
document.getElementById('submitBtn').addEventListener('click', function(e) {
    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
    if (checkedCount === 0) {
        e.preventDefault();
        alert('لطفاً حداقل یک کاربر را به عنوان دریافت‌کننده انتخاب کنید');
        return false;
    }
    
    const fileInput = document.getElementById('fileInput');
    if (!fileInput.files || !fileInput.files[0]) {
        e.preventDefault();
        alert('لطفاً فایل فاکتور را آپلود کنید');
        return false;
    }
});

// حذف required برای فایل و ارجاع در حالت پیش‌نویس
document.getElementById('draftBtn').addEventListener('click', function() {
    document.getElementById('fileInput').removeAttribute('required');
    document.getElementById('fileRequired').style.display = 'none';
    document.getElementById('forwardRequired').style.display = 'none';
    
    // برای پیش‌نویس، نیازی به انتخاب کاربر نیست
    checkboxes.forEach(cb => {
        cb.required = false;
    });
});

// محاسبه اولیه
calculateTotal();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>