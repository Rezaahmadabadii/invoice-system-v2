<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

// دریافت تب فعال
$tab = $_GET['tab'] ?? 'companies';

// =============== پردازش شرکت‌ها ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_company'])) {
    $action = $_POST['action_company'];
    $name = trim($_POST['name'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    $id = $_POST['id'] ?? 0;
    
    if ($action == 'add') {
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO companies (name, short_name) VALUES (?, ?)");
            if ($stmt->execute([$name, $short_name])) {
                $_SESSION['message'] = 'شرکت با موفقیت اضافه شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در افزودن شرکت';
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = 'نام شرکت نمی‌تواند خالی باشد';
            $_SESSION['message_type'] = 'error';
        }
        header('Location: manage.php?tab=companies');
        exit;
    } elseif ($action == 'edit') {
        if (!empty($name) && $id > 0) {
            $stmt = $pdo->prepare("UPDATE companies SET name = ?, short_name = ? WHERE id = ?");
            if ($stmt->execute([$name, $short_name, $id])) {
                $_SESSION['message'] = 'شرکت با موفقیت ویرایش شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در ویرایش شرکت';
                $_SESSION['message_type'] = 'error';
            }
        }
        header('Location: manage.php?tab=companies');
        exit;
    } elseif ($action == 'delete') {
        if ($id > 0) {
            $check = $pdo->prepare("SELECT id FROM documents WHERE company_id = ? LIMIT 1");
            $check->execute([$id]);
            if ($check->fetch()) {
                $_SESSION['message'] = 'این شرکت در فاکتورها استفاده شده و قابل حذف نیست';
                $_SESSION['message_type'] = 'error';
            } else {
                $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['message'] = 'شرکت با موفقیت حذف شد';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'خطا در حذف شرکت';
                    $_SESSION['message_type'] = 'error';
                }
            }
        }
        header('Location: manage.php?tab=companies');
        exit;
    }
}

// =============== پردازش فروشندگان ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_vendor'])) {
    $action = $_POST['action_vendor'];
    $name = trim($_POST['name'] ?? '');
    $contract = trim($_POST['contract_number'] ?? '');
    $id = $_POST['id'] ?? 0;
    
    if ($action == 'add') {
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO vendors (name, contract_number) VALUES (?, ?)");
            if ($stmt->execute([$name, $contract])) {
                $_SESSION['message'] = 'فروشنده با موفقیت اضافه شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در افزودن فروشنده';
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = 'نام فروشنده نمی‌تواند خالی باشد';
            $_SESSION['message_type'] = 'error';
        }
        header('Location: manage.php?tab=vendors');
        exit;
    } elseif ($action == 'edit') {
        if (!empty($name) && $id > 0) {
            $stmt = $pdo->prepare("UPDATE vendors SET name = ?, contract_number = ? WHERE id = ?");
            if ($stmt->execute([$name, $contract, $id])) {
                $_SESSION['message'] = 'فروشنده با موفقیت ویرایش شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در ویرایش فروشنده';
                $_SESSION['message_type'] = 'error';
            }
        }
        header('Location: manage.php?tab=vendors');
        exit;
    } elseif ($action == 'delete') {
        if ($id > 0) {
            $check = $pdo->prepare("SELECT id FROM documents WHERE vendor_id = ? LIMIT 1");
            $check->execute([$id]);
            if ($check->fetch()) {
                $_SESSION['message'] = 'این فروشنده در فاکتورها استفاده شده و قابل حذف نیست';
                $_SESSION['message_type'] = 'error';
            } else {
                $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['message'] = 'فروشنده با موفقیت حذف شد';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'خطا در حذف فروشنده';
                    $_SESSION['message_type'] = 'error';
                }
            }
        }
        header('Location: manage.php?tab=vendors');
        exit;
    }
}

// =============== پردازش کارگاه‌ها ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_workshop'])) {
    $action = $_POST['action_workshop'];
    $name = trim($_POST['name'] ?? '');
    $id = $_POST['id'] ?? 0;
    
    if ($action == 'add') {
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO workshops (name) VALUES (?)");
            if ($stmt->execute([$name])) {
                $_SESSION['message'] = 'کارگاه با موفقیت اضافه شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در افزودن کارگاه';
                $_SESSION['message_type'] = 'error';
            }
        } else {
            $_SESSION['message'] = 'نام کارگاه نمی‌تواند خالی باشد';
            $_SESSION['message_type'] = 'error';
        }
        header('Location: manage.php?tab=workshops');
        exit;
    } elseif ($action == 'edit') {
        if (!empty($name) && $id > 0) {
            $stmt = $pdo->prepare("UPDATE workshops SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $_SESSION['message'] = 'کارگاه با موفقیت ویرایش شد';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'خطا در ویرایش کارگاه';
                $_SESSION['message_type'] = 'error';
            }
        }
        header('Location: manage.php?tab=workshops');
        exit;
    } elseif ($action == 'delete') {
        if ($id > 0) {
            $check = $pdo->prepare("SELECT id FROM documents WHERE workshop_id = ? LIMIT 1");
            $check->execute([$id]);
            if ($check->fetch()) {
                $_SESSION['message'] = 'این کارگاه در فاکتورها استفاده شده و قابل حذف نیست';
                $_SESSION['message_type'] = 'error';
            } else {
                $stmt = $pdo->prepare("DELETE FROM workshops WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['message'] = 'کارگاه با موفقیت حذف شد';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'خطا در حذف کارگاه';
                    $_SESSION['message_type'] = 'error';
                }
            }
        }
        header('Location: manage.php?tab=workshops');
        exit;
    }
}

// دریافت پیام‌ها
$message = $_SESSION['message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['message'], $_SESSION['message_type']);

// دریافت لیست‌ها
$companies = $pdo->query("SELECT * FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT * FROM workshops ORDER BY name")->fetchAll();

$page_title = 'مدیریت اطلاعات پایه';
ob_start();
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h1 {
        font-size: 24px;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* تب‌ها */
    .tabs-container {
        background: white;
        border-radius: 16px;
        padding: 8px;
        margin-bottom: 25px;
        display: flex;
        gap: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border: 1px solid #eef2f5;
    }
    
    .tab-btn {
        flex: 1;
        padding: 12px 20px;
        border: none;
        background: transparent;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        color: #64748b;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .tab-btn i {
        font-size: 16px;
    }
    
    .tab-btn.active {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        box-shadow: 0 4px 12px rgba(59,130,246,0.3);
    }
    
    .tab-btn:hover:not(.active) {
        background: #f1f5f9;
        color: #1e293b;
    }
    
    /* کارت محتوا */
    .content-card {
        background: white;
        border-radius: 24px;
        padding: 24px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.04);
        border: 1px solid #f1f5f9;
    }
    
    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
    }
    
    /* فرم افزودن */
    .add-form {
        background: #f8fafc;
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 30px;
        border: 1px solid #e2e8f0;
    }
    
    .form-row {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    
    .form-group {
        flex: 1;
        min-width: 180px;
    }
    
    .form-group label {
        display: block;
        font-size: 12px;
        color: #64748b;
        margin-bottom: 6px;
    }
    
    .form-group input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
    }
    
    .form-group input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }
    
    .btn-add {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 12px;
        cursor: pointer;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    
    .btn-add:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16,185,129,0.3);
    }
    
    /* جدول */
    .table-wrapper {
        overflow-x: auto;
        border-radius: 16px;
        border: 1px solid #eef2f5;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .data-table th {
        background: #f8fafc;
        padding: 14px 16px;
        text-align: right;
        font-weight: 600;
        color: #475569;
        font-size: 13px;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .data-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 14px;
    }
    
    .data-table tr:last-child td {
        border-bottom: none;
    }
    
    .data-table tr:hover td {
        background: #f8fafc;
    }
    
    .short-badge {
        background: #e0f2fe;
        color: #0284c7;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .action-btn {
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .action-btn.edit {
        background: #fef3c7;
        color: #d97706;
    }
    
    .action-btn.edit:hover {
        background: #d97706;
        color: white;
    }
    
    .action-btn.delete {
        background: #fee2e2;
        color: #dc2626;
    }
    
    .action-btn.delete:hover {
        background: #dc2626;
        color: white;
    }
    
    .action-btn.save {
        background: #d1fae5;
        color: #059669;
    }
    
    .action-btn.save:hover {
        background: #059669;
        color: white;
    }
    
    .action-btn.cancel {
        background: #f1f5f9;
        color: #64748b;
    }
    
    .action-btn.cancel:hover {
        background: #64748b;
        color: white;
    }
    
    .inline-edit {
        display: none;
    }
    
    .inline-edit.active {
        display: inline-flex;
        gap: 8px;
    }
    
    .inline-edit input {
        padding: 6px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 13px;
        width: 120px;
    }
    
    .alert {
        padding: 14px 20px;
        border-radius: 16px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border-right: 4px solid #10b981;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border-right: 4px solid #ef4444;
    }
    
    .empty-state {
        text-align: center;
        padding: 50px;
        color: #94a3b8;
    }
    
    @media (max-width: 768px) {
        .content-card {
            padding: 16px;
        }
        .form-row {
            flex-direction: column;
        }
        .form-group {
            width: 100%;
        }
        .btn-add {
            width: 100%;
            justify-content: center;
        }
        .tab-btn {
            padding: 10px 12px;
            font-size: 12px;
        }
    }
</style>

<div>
    <div class="page-header">
        <h1>
            <i class="fas fa-database" style="color: #3b82f6;"></i>
            مدیریت اطلاعات پایه
        </h1>
        <a href="dashboard.php" style="background: #64748b; color: white; padding: 10px 20px; border-radius: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-arrow-right"></i> بازگشت
        </a>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type == 'success' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- تب‌ها -->
    <div class="tabs-container">
        <button class="tab-btn <?php echo $tab == 'companies' ? 'active' : ''; ?>" data-tab="companies">
            <i class="fas fa-building"></i> شرکت‌ها
        </button>
        <button class="tab-btn <?php echo $tab == 'vendors' ? 'active' : ''; ?>" data-tab="vendors">
            <i class="fas fa-store"></i> فروشندگان
        </button>
        <button class="tab-btn <?php echo $tab == 'workshops' ? 'active' : ''; ?>" data-tab="workshops">
            <i class="fas fa-hard-hat"></i> کارگاه‌ها
        </button>
    </div>
    
    <!-- =============== تب شرکت‌ها =============== -->
    <div id="tab-companies" class="content-card" style="display: <?php echo $tab == 'companies' ? 'block' : 'none'; ?>;">
        <div class="section-title">
            <i class="fas fa-building" style="color: #3b82f6;"></i>
            مدیریت شرکت‌ها
        </div>
        
        <div class="add-form">
            <form method="POST">
                <input type="hidden" name="action_company" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام شرکت</label>
                        <input type="text" name="name" placeholder="مثال: شرکت کیهان" required>
                    </div>
                    <div class="form-group">
                        <label>نام اختصاری</label>
                        <input type="text" name="short_name" placeholder="مثال: kyhn">
                    </div>
                    <button type="submit" class="btn-add">
                        <i class="fas fa-plus"></i> افزودن شرکت
                    </button>
                </div>
                <small style="color: #94a3b8; margin-top: 10px; display: block;">نام اختصاری در شماره فاکتورها استفاده می‌شود (مثال: kyhn-1234)</small>
            </form>
        </div>
        
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>نام شرکت</th>
                        <th>نام اختصاری</th>
                        <th style="width: 150px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($companies)): ?>
                        <tr><td colspan="4" class="empty-state">هیچ شرکتی یافت نشد</td></tr>
                    <?php else: foreach ($companies as $index => $company): ?>
                    <tr id="company-row-<?php echo $company['id']; ?>">
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <span class="company-name-<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></span>
                            <input type="text" class="edit-company-name-<?php echo $company['id']; ?>" value="<?php echo htmlspecialchars($company['name']); ?>" style="display: none; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px;">
                        </td>
                        <td>
                            <span class="company-short-<?php echo $company['id']; ?>">
                                <?php if ($company['short_name']): ?>
                                    <span class="short-badge"><?php echo htmlspecialchars($company['short_name']); ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </span>
                            <input type="text" class="edit-company-short-<?php echo $company['id']; ?>" value="<?php echo htmlspecialchars($company['short_name'] ?? ''); ?>" style="display: none; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; width: 100px;">
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button onclick="editCompany(<?php echo $company['id']; ?>)" class="action-btn edit btn-edit-<?php echo $company['id']; ?>">
                                    <i class="fas fa-edit"></i> ویرایش
                                </button>
                                <button onclick="saveCompany(<?php echo $company['id']; ?>)" style="display: none;" class="action-btn save btn-save-<?php echo $company['id']; ?>">
                                    <i class="fas fa-save"></i> ذخیره
                                </button>
                                <button onclick="cancelEditCompany(<?php echo $company['id']; ?>)" style="display: none;" class="action-btn cancel btn-cancel-<?php echo $company['id']; ?>">
                                    <i class="fas fa-times"></i> انصراف
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این شرکت اطمینان دارید؟')">
                                    <input type="hidden" name="action_company" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                                    <button type="submit" class="action-btn delete">
                                        <i class="fas fa-trash-alt"></i> حذف
                                    </button>
                                </form>
                                <form method="POST" id="company_form_<?php echo $company['id']; ?>" style="display: none;">
                                    <input type="hidden" name="action_company" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                                    <input type="hidden" name="name" id="company_name_input_<?php echo $company['id']; ?>" value="">
                                    <input type="hidden" name="short_name" id="company_short_input_<?php echo $company['id']; ?>" value="">
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- =============== تب فروشندگان =============== -->
    <div id="tab-vendors" class="content-card" style="display: <?php echo $tab == 'vendors' ? 'block' : 'none'; ?>;">
        <div class="section-title">
            <i class="fas fa-store" style="color: #10b981;"></i>
            مدیریت فروشندگان
        </div>
        
        <div class="add-form">
            <form method="POST">
                <input type="hidden" name="action_vendor" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام فروشنده</label>
                        <input type="text" name="name" placeholder="مثال: فروشگاه الف" required>
                    </div>
                    <div class="form-group">
                        <label>شماره قرارداد</label>
                        <input type="text" name="contract_number" placeholder="اختیاری">
                    </div>
                    <button type="submit" class="btn-add">
                        <i class="fas fa-plus"></i> افزودن فروشنده
                    </button>
                </div>
            </form>
        </div>
        
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>نام فروشنده</th>
                        <th>شماره قرارداد</th>
                        <th style="width: 120px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendors)): ?>
                        <tr><td colspan="4" class="empty-state">هیچ فروشنده‌ای یافت نشد</td></tr>
                    <?php else: foreach ($vendors as $index => $vendor): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($vendor['name']); ?></td>
                        <td><?php echo htmlspecialchars($vendor['contract_number'] ?? '—'); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button onclick="editVendor(<?php echo $vendor['id']; ?>, '<?php echo addslashes($vendor['name']); ?>', '<?php echo addslashes($vendor['contract_number'] ?? ''); ?>')" class="action-btn edit">
                                    <i class="fas fa-edit"></i> ویرایش
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این فروشنده اطمینان دارید؟')">
                                    <input type="hidden" name="action_vendor" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $vendor['id']; ?>">
                                    <button type="submit" class="action-btn delete">
                                        <i class="fas fa-trash-alt"></i> حذف
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- =============== تب کارگاه‌ها =============== -->
    <div id="tab-workshops" class="content-card" style="display: <?php echo $tab == 'workshops' ? 'block' : 'none'; ?>;">
        <div class="section-title">
            <i class="fas fa-hard-hat" style="color: #f59e0b;"></i>
            مدیریت کارگاه‌ها
        </div>
        
        <div class="add-form">
            <form method="POST">
                <input type="hidden" name="action_workshop" value="add">
                <div class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label>نام کارگاه</label>
                        <input type="text" name="name" placeholder="مثال: کارگاه مرکزی" required>
                    </div>
                    <button type="submit" class="btn-add">
                        <i class="fas fa-plus"></i> افزودن کارگاه
                    </button>
                </div>
            </form>
        </div>
        
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>نام کارگاه</th>
                        <th style="width: 150px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workshops)): ?>
                        <tr><td colspan="3" class="empty-state">هیچ کارگاهی یافت نشد</td></tr>
                    <?php else: foreach ($workshops as $index => $ws): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <span id="workshop_name_<?php echo $ws['id']; ?>"><?php echo htmlspecialchars($ws['name']); ?></span>
                            <input type="text" id="workshop_edit_<?php echo $ws['id']; ?>" value="<?php echo htmlspecialchars($ws['name']); ?>" style="display: none; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; width: 200px;">
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button onclick="editWorkshop(<?php echo $ws['id']; ?>)" class="action-btn edit">
                                    <i class="fas fa-edit"></i> ویرایش
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این کارگاه اطمینان دارید؟')">
                                    <input type="hidden" name="action_workshop" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $ws['id']; ?>">
                                    <button type="submit" class="action-btn delete">
                                        <i class="fas fa-trash-alt"></i> حذف
                                    </button>
                                </form>
                                <form method="POST" id="workshop_form_<?php echo $ws['id']; ?>" style="display: none;">
                                    <input type="hidden" name="action_workshop" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $ws['id']; ?>">
                                    <input type="hidden" name="name" id="workshop_input_<?php echo $ws['id']; ?>" value="">
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// تغییر تب‌ها
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.getAttribute('data-tab');
        window.location.href = 'manage.php?tab=' + tabId;
    });
});

// ویرایش شرکت
function editCompany(id) {
    document.querySelector(`.company-name-${id}`).style.display = 'none';
    document.querySelector(`.edit-company-name-${id}`).style.display = 'inline-block';
    document.querySelector(`.company-short-${id}`).style.display = 'none';
    document.querySelector(`.edit-company-short-${id}`).style.display = 'inline-block';
    document.querySelector(`.btn-edit-${id}`).style.display = 'none';
    document.querySelector(`.btn-save-${id}`).style.display = 'inline-block';
    document.querySelector(`.btn-cancel-${id}`).style.display = 'inline-block';
}

function saveCompany(id) {
    let newName = document.querySelector(`.edit-company-name-${id}`).value;
    let newShort = document.querySelector(`.edit-company-short-${id}`).value;
    document.getElementById(`company_name_input_${id}`).value = newName;
    document.getElementById(`company_short_input_${id}`).value = newShort;
    document.getElementById(`company_form_${id}`).submit();
}

function cancelEditCompany(id) {
    document.querySelector(`.company-name-${id}`).style.display = 'inline';
    document.querySelector(`.edit-company-name-${id}`).style.display = 'none';
    document.querySelector(`.company-short-${id}`).style.display = 'inline';
    document.querySelector(`.edit-company-short-${id}`).style.display = 'none';
    document.querySelector(`.btn-edit-${id}`).style.display = 'inline-block';
    document.querySelector(`.btn-save-${id}`).style.display = 'none';
    document.querySelector(`.btn-cancel-${id}`).style.display = 'none';
}

// ویرایش کارگاه
function editWorkshop(id) {
    const span = document.getElementById('workshop_name_' + id);
    const input = document.getElementById('workshop_edit_' + id);
    const form = document.getElementById('workshop_form_' + id);
    const hiddenInput = document.getElementById('workshop_input_' + id);
    
    if (span.style.display !== 'none') {
        span.style.display = 'none';
        input.style.display = 'inline-block';
        input.focus();
    } else {
        hiddenInput.value = input.value;
        form.submit();
    }
}

// ویرایش فروشنده (ساده)
function editVendor(id, name, contract) {
    const newName = prompt('نام جدید فروشنده را وارد کنید:', name);
    if (newName && newName !== name) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action_vendor" value="edit">
            <input type="hidden" name="id" value="${id}">
            <input type="hidden" name="name" value="${newName}">
            <input type="hidden" name="contract_number" value="${contract}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>