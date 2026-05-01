<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// اتصال به دیتابیس
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
            // بررسی عدم استفاده در فاکتورها
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
            // بررسی عدم استفاده در فاکتورها
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
            // بررسی عدم استفاده در فاکتورها
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

// دریافت لیست‌ها (با short_name برای شرکت‌ها)
$companies = $pdo->query("SELECT * FROM companies ORDER BY name")->fetchAll();
$vendors = $pdo->query("SELECT * FROM vendors ORDER BY name")->fetchAll();
$workshops = $pdo->query("SELECT * FROM workshops ORDER BY name")->fetchAll();

$page_title = 'مدیریت';
ob_start();
?>

<style>
    .short-name-badge {
        background: #e8f4f8;
        color: #2c7da0;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        margin-right: 8px;
    }
    .edit-inline {
        display: none;
    }
    .edit-inline.show {
        display: inline-flex;
        gap: 5px;
    }
</style>

<!-- نمایش پیام‌ها -->
<?php if ($message): ?>
    <div style="padding: 15px; border-radius: 5px; margin-bottom: 20px; <?php echo $message_type == 'success' ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1 style="color: #2c3e50;">مدیریت</h1>
    <a href="dashboard.php" style="background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none;">
        <i class="fas fa-arrow-right"></i> بازگشت
    </a>
</div>

<!-- تب‌ها -->
<div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px;">
    <a href="?tab=companies" style="padding: 10px 20px; text-decoration: none; <?php echo $tab == 'companies' ? 'background: #3498db; color: white; border-radius: 5px;' : 'color: #666;'; ?>">شرکت‌ها</a>
    <a href="?tab=vendors" style="padding: 10px 20px; text-decoration: none; <?php echo $tab == 'vendors' ? 'background: #3498db; color: white; border-radius: 5px;' : 'color: #666;'; ?>">فروشندگان</a>
    <a href="?tab=workshops" style="padding: 10px 20px; text-decoration: none; <?php echo $tab == 'workshops' ? 'background: #3498db; color: white; border-radius: 5px;' : 'color: #666;'; ?>">کارگاه‌ها</a>
</div>

<!-- =============== تب شرکت‌ها =============== -->
<?php if ($tab == 'companies'): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <h3 style="margin-bottom: 20px;">مدیریت شرکت‌ها</h3>
    
    <!-- فرم افزودن شرکت (با فیلد مخفف) -->
    <form method="POST" style="margin-bottom: 30px;">
        <input type="hidden" name="action_company" value="add">
        <div style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px;">
            <input type="text" name="name" placeholder="نام شرکت" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="short_name" placeholder="نام اختصاری (مثال: kyhn)" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <button type="submit" style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">افزودن</button>
        </div>
        <small style="color: #7f8c8d; margin-top: 5px; display: block;">نام اختصاری شرکت در شماره فاکتورها استفاده می‌شود (مثال: kyhn-1234)</small>
    </form>
    
    <!-- لیست شرکت‌ها -->
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5;">
                    <th style="padding: 10px; text-align: right;">ردیف</th>
                    <th style="padding: 10px; text-align: right;">نام شرکت</th>
                    <th style="padding: 10px; text-align: right;">اختصار</th>
                    <th style="padding: 10px; text-align: right;">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $index => $company): ?>
                <tr style="border-bottom: 1px solid #eee;" id="company-row-<?php echo $company['id']; ?>">
                    <td style="padding: 10px;"><?php echo $index + 1; ?></td>
                    <td style="padding: 10px;">
                        <span class="company-name-<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></span>
                        <input type="text" class="edit-company-name-<?php echo $company['id']; ?>" value="<?php echo htmlspecialchars($company['name']); ?>" style="display: none; padding: 5px; border: 1px solid #ddd; border-radius: 3px;">
                    </td>
                    <td style="padding: 10px;">
                        <span class="company-short-<?php echo $company['id']; ?>">
                            <?php if ($company['short_name']): ?>
                                <span class="short-name-badge"><?php echo htmlspecialchars($company['short_name']); ?></span>
                            <?php else: ?>
                                <span style="color: #95a5a6;">-</span>
                            <?php endif; ?>
                        </span>
                        <input type="text" class="edit-company-short-<?php echo $company['id']; ?>" value="<?php echo htmlspecialchars($company['short_name'] ?? ''); ?>" style="display: none; padding: 5px; border: 1px solid #ddd; border-radius: 3px; width: 80px;">
                    </td>
                    <td style="padding: 10px;">
                        <button onclick="editCompany(<?php echo $company['id']; ?>)" class="btn-edit-<?php echo $company['id']; ?>" style="background: #f39c12; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">✏️ ویرایش</button>
                        <button onclick="saveCompany(<?php echo $company['id']; ?>)" style="display: none;" class="btn-save-<?php echo $company['id']; ?>" style="background: #27ae60; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">💾 ذخیره</button>
                        <button onclick="cancelEditCompany(<?php echo $company['id']; ?>)" style="display: none;" class="btn-cancel-<?php echo $company['id']; ?>" style="background: #95a5a6; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">❌ انصراف</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این شرکت اطمینان دارید؟')">
                            <input type="hidden" name="action_company" value="delete">
                            <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                            <button type="submit" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">🗑️ حذف</button>
                        </form>
                        <form method="POST" id="company_form_<?php echo $company['id']; ?>" style="display: none;">
                            <input type="hidden" name="action_company" value="edit">
                            <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                            <input type="hidden" name="name" id="company_name_input_<?php echo $company['id']; ?>" value="">
                            <input type="hidden" name="short_name" id="company_short_input_<?php echo $company['id']; ?>" value="">
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function editCompany(id) {
    // مخفی کردن span ها و نمایش input ها
    document.querySelector(`.company-name-${id}`).style.display = 'none';
    document.querySelector(`.edit-company-name-${id}`).style.display = 'inline-block';
    document.querySelector(`.company-short-${id}`).style.display = 'none';
    document.querySelector(`.edit-company-short-${id}`).style.display = 'inline-block';
    
    // تغییر دکمه‌ها
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
    // برگرداندن به حالت اولیه
    document.querySelector(`.company-name-${id}`).style.display = 'inline';
    document.querySelector(`.edit-company-name-${id}`).style.display = 'none';
    document.querySelector(`.company-short-${id}`).style.display = 'inline';
    document.querySelector(`.edit-company-short-${id}`).style.display = 'none';
    
    // بازنشانی دکمه‌ها
    document.querySelector(`.btn-edit-${id}`).style.display = 'inline-block';
    document.querySelector(`.btn-save-${id}`).style.display = 'none';
    document.querySelector(`.btn-cancel-${id}`).style.display = 'none';
}
</script>
<?php endif; ?>

<!-- =============== تب فروشندگان (بدون تغییر) =============== -->
<?php if ($tab == 'vendors'): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <h3 style="margin-bottom: 20px;">مدیریت فروشندگان</h3>
    
    <!-- فرم افزودن فروشنده -->
    <form method="POST" style="margin-bottom: 30px;">
        <input type="hidden" name="action_vendor" value="add">
        <div style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px;">
            <input type="text" name="name" placeholder="نام فروشنده" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="contract_number" placeholder="شماره قرارداد" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <button type="submit" style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">افزودن</button>
        </div>
    </form>
    
    <!-- لیست فروشندگان -->
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 10px; text-align: right;">ردیف</th>
                <th style="padding: 10px; text-align: right;">نام فروشنده</th>
                <th style="padding: 10px; text-align: right;">شماره قرارداد</th>
                <th style="padding: 10px; text-align: right;">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vendors as $index => $vendor): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 10px;"><?php echo $index + 1; ?></td>
                <td style="padding: 10px;"><?php echo htmlspecialchars($vendor['name']); ?></td>
                <td style="padding: 10px;"><?php echo htmlspecialchars($vendor['contract_number'] ?? '-'); ?></td>
                <td style="padding: 10px;">
                    <button onclick="editVendor(<?php echo $vendor['id']; ?>, '<?php echo addslashes($vendor['name']); ?>', '<?php echo addslashes($vendor['contract_number'] ?? ''); ?>')" style="background: #f39c12; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">ویرایش</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این فروشنده اطمینان دارید؟')">
                        <input type="hidden" name="action_vendor" value="delete">
                        <input type="hidden" name="id" value="<?php echo $vendor['id']; ?>">
                        <button type="submit" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">حذف</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- =============== تب کارگاه‌ها (بدون تغییر) =============== -->
<?php if ($tab == 'workshops'): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <h3 style="margin-bottom: 20px;">مدیریت کارگاه‌ها</h3>
    
    <form method="POST" style="display: flex; gap: 10px; margin-bottom: 30px;">
        <input type="hidden" name="action_workshop" value="add">
        <input type="text" name="name" placeholder="نام کارگاه جدید" required style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        <button type="submit" style="background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">افزودن</button>
    </form>
    
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 10px; text-align: right;">ردیف</th>
                <th style="padding: 10px; text-align: right;">نام کارگاه</th>
                <th style="padding: 10px; text-align: right;">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($workshops as $index => $ws): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 10px;"><?php echo $index + 1; ?></td>
                <td style="padding: 10px;">
                    <span id="workshop_name_<?php echo $ws['id']; ?>"><?php echo htmlspecialchars($ws['name']); ?></span>
                    <input type="text" id="workshop_edit_<?php echo $ws['id']; ?>" value="<?php echo htmlspecialchars($ws['name']); ?>" style="display: none; padding: 5px; border: 1px solid #ddd; border-radius: 3px; width: 80%;">
                </td>
                <td style="padding: 10px;">
                    <button onclick="editWorkshop(<?php echo $ws['id']; ?>)" style="background: #f39c12; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 5px;">ویرایش</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این کارگاه اطمینان دارید؟')">
                        <input type="hidden" name="action_workshop" value="delete">
                        <input type="hidden" name="id" value="<?php echo $ws['id']; ?>">
                        <button type="submit" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">حذف</button>
                    </form>
                    <form method="POST" id="workshop_form_<?php echo $ws['id']; ?>" style="display: none;">
                        <input type="hidden" name="action_workshop" value="edit">
                        <input type="hidden" name="id" value="<?php echo $ws['id']; ?>">
                        <input type="hidden" name="name" id="workshop_input_<?php echo $ws['id']; ?>" value="">
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
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

function editVendor(id, name, contract) {
    alert('ویرایش فروشنده - در حال توسعه');
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>