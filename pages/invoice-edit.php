<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
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

// دریافت اطلاعات فاکتور
$stmt = $pdo->prepare("
    SELECT d.*, c.short_name as company_short_name
    FROM documents d
    LEFT JOIN companies c ON d.company_id = c.id
    WHERE d.id = ? AND d.type = 'invoice'
");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: inbox.php');
    exit;
}

// فقط ایجادکننده می‌تواند ویرایش کند
if ($invoice['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = 'شما مجاز به ویرایش این فاکتور نیستید.';
    header('Location: invoice-view.php?id=' . $id);
    exit;
}

// ========== دریافت وضعیت فعلی فاکتور ==========
$current_status = $invoice['status'];
$is_draft = ($current_status == 'draft');

// بررسی آیا کاربری قبلاً تأیید یا رد کرده است
$has_any_action = false;
if (!$is_draft && $current_status != 'rejected' && $current_status != 'final_approved') {
    $check_action = $pdo->prepare("
        SELECT COUNT(*) FROM document_approvals 
        WHERE document_id = ? AND status IN ('approved', 'rejected')
    ");
    $check_action->execute([$id]);
    $has_any_action = $check_action->fetchColumn() > 0;
}

// شرط ویرایش: پیش‌نویس باشد OR (هنوز کسی اقدامی نکرده باشد)
$can_edit = ($is_draft || (!$has_any_action && $current_status != 'final_approved' && $current_status != 'rejected'));

if (!$can_edit) {
    $_SESSION['error'] = 'این فاکتور به دلیل انجام اقدام (تأیید یا رد) توسط کاربران، قابل ویرایش نیست.';
    header('Location: invoice-view.php?id=' . $id);
    exit;
}
// =============================================

// دریافت لیست‌های مورد نیاز
$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT id, name, contract_number, national_id FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT id, name FROM workshops ORDER BY name")->fetchAll();

// دریافت لیست کاربران (به جز خود کاربر) برای ارجاع
$user_id = $_SESSION['user_id'];
$users_stmt = $pdo->prepare("
    SELECT id, full_name, username, department_id 
    FROM users 
    WHERE id != ?
    ORDER BY full_name
");
$users_stmt->execute([$user_id]);
$users = $users_stmt->fetchAll();

$error = '';
$success = '';
$duplicate_warning = '';

// استخراج شماره فاکتور ساده از document_number
$simple_invoice_number = preg_replace('/^[^-]+-/', '', $invoice['document_number']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // تشخیص نوع ذخیره
    $is_complete = isset($_POST['complete_invoice']); // دکمه تکمیل از صفحه view
    $is_save_draft = isset($_POST['save_draft']); // ذخیره به عنوان پیش‌نویس
    
    // اگر هیچ دکمه‌ای زده نشده، ادامه نده
    if (!$is_complete && !$is_save_draft) {
        // هیچ اقدامی نکن
    } else {
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
        
        // اعتبارسنجی
        if (empty($invoice_number)) $errors[] = 'شماره فاکتور الزامی است';
        if (empty($invoice_date)) $errors[] = 'تاریخ فاکتور الزامی است';
        if (empty($company_id)) $errors[] = 'انتخاب شرکت الزامی است';
        
        // برای تکمیل پیش‌نویس، اعتبارسنجی کامل
        if ($is_complete) {
            if (empty($vendor_id)) $errors[] = 'انتخاب فروشنده الزامی است';
            if (empty($amount) || $amount <= 0) $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
            if (empty($selected_users)) $errors[] = 'لطفاً حداقل یک کاربر را به عنوان دریافت‌کننده انتخاب کنید';
            
            // بررسی آپلود فایل
            if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] != UPLOAD_ERR_OK) {
                if (empty($invoice['file_path'])) {
                    $errors[] = 'آپلود فایل فاکتور الزامی است';
                }
            } elseif (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                $max_size = 5 * 1024 * 1024;
                if (!in_array($_FILES['invoice_file']['type'], $allowed_types)) {
                    $errors[] = 'نوع فایل باید JPEG، PNG یا PDF باشد';
                }
                if ($_FILES['invoice_file']['size'] > $max_size) {
                    $errors[] = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
                }
            }
        }
        
        // دریافت مخفف شرکت
        $companyInfo = $pdo->prepare("SELECT short_name FROM companies WHERE id = ?");
        $companyInfo->execute([$company_id]);
        $companyData = $companyInfo->fetch();
        $short_name = $companyData['short_name'] ?? '';
        $final_invoice_number = !empty($short_name) ? $short_name . '-' . $invoice_number : $invoice_number;
        
        // بررسی آپلود فایل جدید
        $file_path = $invoice['file_path'];
        $file_name = $invoice['file_name'];
        
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/invoices/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_ext = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
            $new_file_name = $final_invoice_number . '.' . $file_ext;
            $file_path = 'uploads/invoices/' . $new_file_name;
            $file_name = $_FILES['invoice_file']['name'];
            
            if ($invoice['file_path'] && file_exists(__DIR__ . '/../' . $invoice['file_path'])) {
                unlink(__DIR__ . '/../' . $invoice['file_path']);
            }
            
            if (!move_uploaded_file($_FILES['invoice_file']['tmp_name'], $upload_dir . $new_file_name)) {
                $errors[] = 'خطا در آپلود فایل';
            }
        }
        
        // بررسی تکراری بودن (فقط برای تکمیل)
        if ($is_complete && empty($errors)) {
            $check = $pdo->prepare("SELECT id FROM documents WHERE document_number = ? AND type = 'invoice' AND id != ?");
            $check->execute([$final_invoice_number, $id]);
            $existing = $check->fetch();
            if ($existing && !$force_save) {
                $duplicate_warning = 'این شماره فاکتور قبلاً ثبت شده است. آیا مطمئن هستید؟';
            }
        }
        
        if (empty($errors) && (empty($duplicate_warning) || $force_save)) {
            $amount_clean = floatval(str_replace(',', '', $amount));
            $discount_clean = floatval(str_replace(',', '', $discount));
            $final_amount = $amount_clean - $discount_clean;
            if ($vat) {
                $final_amount = $final_amount + ($amount_clean * 0.1);
            }
            
            // ========== تعیین وضعیت بر اساس دکمه ==========
            if ($is_complete) {
                $new_status = 'pending_approval';
                $total_approvers = count($selected_users);
            } else {
                $new_status = 'draft';
                $total_approvers = 0;
            }
            // =============================================
            
            $update_sql = "
                UPDATE documents SET 
                    document_number = ?,
                    title = ?,
                    description = ?,
                    amount = ?,
                    company_id = ?,
                    workshop_id = ?,
                    vendor_id = ?,
                    document_date = ?,
                    contract_number = ?,
                    vat = ?,
                    vat_amount = ?,
                    file_path = ?,
                    file_name = ?,
                    status = ?,
                    total_approvers = ?,
                    approved_count = 0,
                    rejected_count = 0,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $pdo->prepare($update_sql);
            $result = $stmt->execute([
                $final_invoice_number,
                'فاکتور شماره ' . $invoice_number,
                $description,
                $total > 0 ? $total : $final_amount,
                $company_id,
                $workshop_id ?: null,
                $vendor_id ?: null,
                $invoice_date,
                $contract_number ?: null,
                $vat,
                $vat ? ($amount_clean * 0.1) : 0,
                $file_path,
                $file_name,
                $new_status,
                $total_approvers,
                $id
            ]);
            
            if ($result) {
                // اگر فاکتور تکمیل شد، برای کاربران انتخاب شده رکورد ثبت کن
                if ($is_complete && !empty($selected_users)) {
                    // حذف رکوردهای قبلی (اگر وجود داشته باشد)
                    $pdo->prepare("DELETE FROM document_approvals WHERE document_id = ?")->execute([$id]);
                    
                    $insert_approval = $pdo->prepare("
                        INSERT INTO document_approvals (document_id, user_id, status) 
                        VALUES (?, ?, 'pending')
                    ");
                    
                    $insert_history = $pdo->prepare("
                        INSERT INTO forwarding_history (document_id, from_user_id, to_user_id, action, notes) 
                        VALUES (?, ?, ?, 'forward', ?)
                    ");
                    
                    foreach ($selected_users as $uid) {
                        $insert_approval->execute([$id, $uid]);
                        $insert_history->execute([$id, $_SESSION['user_id'], $uid, "ارسال فاکتور برای تأیید"]);
                    }
                }
                
                logActivity($_SESSION['user_id'], 'edit_invoice', "فاکتور ویرایش شد: $invoice_number", $id);
                
                if ($is_complete) {
                    $_SESSION['message'] = '✅ فاکتور با موفقیت تکمیل و برای ' . count($selected_users) . ' نفر ارسال شد.';
                } else {
                    $_SESSION['message'] = '📝 پیش‌نویس با موفقیت ذخیره شد.';
                }
                $_SESSION['message_type'] = 'success';
                header('Location: inbox.php');
                exit;
            } else {
                $errors[] = 'خطا در ویرایش فاکتور';
            }
        }
        
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
    }
}

$page_title = 'ویرایش فاکتور';
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
    
    /* ========== استایل لیست کاربران ========== */
    .users-select-list {
        max-height: 350px;
        overflow-y: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: white;
    }
    
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
    /* ===================================== */
    
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
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(99,102,241,0.4);
    }

    .btn-draft {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white;
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
    }

    .btn-draft:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
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
    
    .current-file {
        background: #f0f7ff;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 15px;
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
    
    .alert-warning {
        background: var(--accent-light);
        color: var(--accent);
        border-right: 3px solid var(--accent);
    }
    
    @media (max-width: 1000px) {
        .three-columns {
            grid-template-columns: repeat(2, 1fr);
        }
        .users-grid-list-vertical {
            grid-template-columns: 1fr;
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
</style>

<div class="form-wrapper">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-edit" style="color: var(--primary);"></i> ویرایش پیش‌نویس فاکتور</h1>
        <a href="invoice-view.php?id=<?php echo $id; ?>" class="back-link"><i class="fas fa-arrow-right"></i> بازگشت</a>
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
                <button type="submit" name="complete_invoice" style="background: var(--accent); color: white; border: none; padding: 8px 20px; border-radius: 8px; cursor: pointer;">بله، ثبت شود</button>
                <a href="invoice-edit.php?id=<?php echo $id; ?>" style="background: #95a5a6; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; margin-left: 10px;">خیر، انصراف</a>
            </form>
        </div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" id="invoiceForm">
        <!-- ردیف اول: اطلاعات اصلی فاکتور + شرکت و فروشنده (2 ستون) -->
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-file-alt"></i>
                    <h3>اطلاعات اصلی فاکتور</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>شماره فاکتور <span class="required">*</span></label>
                        <input type="text" name="invoice_number" required value="<?php echo htmlspecialchars($_POST['invoice_number'] ?? $simple_invoice_number); ?>" placeholder="مثال: 1234">
                        <small class="help-text">با مخفف شرکت ترکیب می‌شود (مثال: kyhn-1234)</small>
                    </div>
                    <div class="form-group">
                        <label>تاریخ فاکتور (شمسی) <span class="required">*</span></label>
                        <input type="text" name="invoice_date" value="<?php echo htmlspecialchars($_POST['invoice_date'] ?? $invoice['document_date']); ?>" placeholder="۱۴۰۴/۱۲/۰۷">
                    </div>
                    <div class="form-group">
                        <label>شماره قرارداد</label>
                        <input type="text" name="contract_number" value="<?php echo htmlspecialchars($_POST['contract_number'] ?? $invoice['contract_number']); ?>" placeholder="اختیاری">
                        <small class="help-text">در صورت وجود قرارداد، شماره آن را وارد کنید</small>
                    </div>
                    <div class="form-group">
                        <label>توضیحات فاکتور</label>
                        <textarea name="description" rows="3" placeholder="توضیحات اضافی (اختیاری)..."><?php echo htmlspecialchars($_POST['description'] ?? $invoice['description']); ?></textarea>
                    </div>
                </div>
            </div>
            
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
                                <option value="<?php echo $comp['id']; ?>" data-short="<?php echo $comp['short_name']; ?>" <?php echo (($_POST['company_id'] ?? $invoice['company_id']) == $comp['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($comp['name']); ?>
                                    <?php if ($comp['short_name']): ?>
                                        (<?php echo htmlspecialchars($comp['short_name']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>فروشنده/فروشگاه <span class="required" id="vendorRequired">*</span></label>
                        <select name="vendor_id" id="vendorSelect" required>
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>" data-national="<?php echo $vendor['national_id'] ?? ''; ?>" <?php echo (($_POST['vendor_id'] ?? $invoice['vendor_id']) == $vendor['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($vendor['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>کارگاه/بخش پروژه</label>
                        <select name="workshop_id">
                            <option value="">انتخاب کنید (اختیاری)</option>
                            <?php foreach ($workshops as $ws): ?>
                                <option value="<?php echo $ws['id']; ?>" <?php echo (($_POST['workshop_id'] ?? $invoice['workshop_id']) == $ws['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ws['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف دوم: اطلاعات مالی + جمع کل (2 ستون) -->
        <div class="two-columns">
            
            <div class="form-card card-primary">
                <div class="card-header">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>اطلاعات مالی</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>مبلغ پایه (تومان) <span class="required" id="amountRequired">*</span></label>
                        <input type="text" name="amount" id="amountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['amount'] ?? number_format($invoice['amount'] ?? 0)); ?>" placeholder="۰">
                    </div>
                    <div class="form-group">
                        <label>تخفیف (تومان)</label>
                        <input type="text" name="discount" id="discountInput" onkeyup="formatNumber(this); calculateTotal();" value="<?php echo htmlspecialchars($_POST['discount'] ?? number_format($invoice['discount'] ?? 0)); ?>" placeholder="۰">
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="vat" id="vatCheckbox" onchange="calculateTotal();" <?php echo (($_POST['vat'] ?? $invoice['vat']) ? 'checked' : ''); ?>> 
                            ارزش افزوده (۱۰٪)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-card card-secondary">
                <div class="card-header">
                    <i class="fas fa-calculator"></i>
                    <h3>جمع کل فاکتور</h3>
                </div>
                <div class="card-body">
                    <div class="total-box">
                        <div class="label">💰 جمع کل قابل پرداخت</div>
                        <div class="value" id="totalDisplay">0 تومان</div>
                        <input type="hidden" name="total" id="totalInput" value="0">
                        <small class="help-text" style="margin-top: 8px;">(مبلغ پایه - تخفیف + ارزش افزوده)</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ردیف سوم: فایل فاکتور + ارجاع به چند نفر + توضیحات -->
        <div class="two-columns">
            
            <div class="form-card card-accent">
                <div class="card-header">
                    <i class="fas fa-paperclip"></i>
                    <h3>فایل فاکتور</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($invoice['file_path']) && file_exists(__DIR__ . '/../' . $invoice['file_path'])): 
                        $web_path = str_replace('\\', '/', $invoice['file_path']);
                        $file_url = '/invoice-system-v2/' . $web_path;
                        $file_ext = strtolower(pathinfo($invoice['file_path'], PATHINFO_EXTENSION));
                        $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    ?>
                        <div class="current-file">
                            <strong>📎 فایل ضمیمه فعلی:</strong>
                            <?php if ($is_image): ?>
                                <div style="margin-top: 8px;">
                                    <img src="<?php echo $file_url; ?>" alt="پیش‌نمایش" style="max-width: 100px; border-radius: 8px;">
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 8px;">
                                    <a href="<?php echo $file_url; ?>" target="_blank">📄 <?php echo htmlspecialchars($invoice['file_name'] ?? basename($invoice['file_path'])); ?></a>
                                </div>
                            <?php endif; ?>
                            <small class="help-text">در صورت آپلود فایل جدید، فایل قبلی جایگزین می‌شود</small>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>آپلود فایل جدید <span class="required" id="fileRequired">*</span></label>
                        <input type="file" name="invoice_file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="help-text">فرمت‌های مجاز: jpg, jpeg, png, pdf | حداکثر حجم: ۵ مگابایت</small>
                        <div id="filePreview" class="file-preview" style="display: none;"></div>
                    </div>
                </div>
            </div>
            
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
                        
                        <div class="users-grid-list-vertical">
                            <?php if (empty($users)): ?>
                                <div style="padding: 20px; text-align: center; color: #64748b; grid-column: span 2;">
                                    <i class="fas fa-user-slash"></i> کاربر دیگری یافت نشد
                                </div>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <label class="user-checkbox-item-vertical">
                                        <input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" 
                                               class="user-checkbox">
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
            
            <div class="form-card card-accent" style="visibility: hidden;"></div>
        </div>
        
        <div class="form-actions">
            <button type="submit" name="save_draft" value="1" class="btn btn-draft" id="draftBtn">
                <i class="fas fa-save"></i> ذخیره پیش‌نویس
            </button>
            <button type="submit" name="complete_invoice" value="1" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-paper-plane"></i> تکمیل و ارسال برای کاربران
            </button>
            <a href="invoice-view.php?id=<?php echo $id; ?>" class="btn btn-outline">انصراف</a>
        </div>
    </form>
</div>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}

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

// پیش‌نمایش فایل جدید
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

// حذف required برای فایل و ارجاع در حالت پیش‌نویس
document.getElementById('draftBtn').addEventListener('click', function() {
    document.getElementById('fileRequired').style.display = 'none';
    document.getElementById('forwardRequired').style.display = 'none';
    document.getElementById('vendorRequired').style.display = 'none';
    document.getElementById('amountRequired').style.display = 'none';
    
    checkboxes.forEach(cb => {
        cb.required = false;
    });
});

// اعتبارسنجی برای تکمیل و ارسال
document.getElementById('submitBtn').addEventListener('click', function(e) {
    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
    if (checkedCount === 0) {
        e.preventDefault();
        alert('لطفاً حداقل یک کاربر را به عنوان دریافت‌کننده انتخاب کنید');
        return false;
    }
    
    const vendorSelect = document.getElementById('vendorSelect');
    if (!vendorSelect.value) {
        e.preventDefault();
        alert('لطفاً فروشنده را انتخاب کنید');
        return false;
    }
    
    const amountInput = document.getElementById('amountInput');
    if (!amountInput.value || parseFloat(amountInput.value.replace(/,/g, '')) <= 0) {
        e.preventDefault();
        alert('لطفاً مبلغ معتبر وارد کنید');
        return false;
    }
    
    const fileInput = document.getElementById('fileInput');
    const currentFile = <?php echo !empty($invoice['file_path']) ? 'true' : 'false'; ?>;
    if ((!fileInput.files || !fileInput.files[0]) && !currentFile) {
        e.preventDefault();
        alert('لطفاً فایل فاکتور را آپلود کنید');
        return false;
    }
});

calculateTotal();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>