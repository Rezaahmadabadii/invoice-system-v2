<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('edit_tax')) {
    header('Location: tax.php');
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

$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'tax'");
$stmt->execute([$id]);
$tax = $stmt->fetch();

if (!$tax) {
    header('Location: tax.php');
    exit;
}

// فقط ایجادکننده می‌تواند ویرایش کند
if ($tax['created_by'] != $_SESSION['user_id']) {
    $_SESSION['error'] = 'شما مجاز به ویرایش این سند نیستید.';
    header('Location: tax-view.php?id=' . $id);
    exit;
}

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_tax'])) {
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $tax_detail_code = trim($_POST['tax_detail_code'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $to_invoice_number = trim($_POST['to_invoice_number'] ?? '');
    $record_date = trim($_POST['record_date'] ?? jdate('Y/m/d'));
    $followup_days = (int)($_POST['followup_days'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';
    
    if (empty($title) || empty($company_id) || empty($amount) || empty($tax_id)) {
        $error = 'لطفاً تمام فیلدهای اجباری را پر کنید';
    } elseif (empty($forward_to_department) && empty($forward_to_user)) {
        $error = 'لطفاً حداقل یکی از فیلدهای ارجاع را انتخاب کنید';
    } else {
        if (!empty($forward_to_user)) {
            $current_holder_user_id = $forward_to_user;
            $current_holder_department_id = null;
        } else {
            $current_holder_user_id = null;
            $current_holder_department_id = $forward_to_department;
        }
        
        $deadline = null;
        if ($followup_days > 0) {
            $deadline = date('Y-m-d H:i:s', strtotime('+' . $followup_days . ' days'));
        }
        
        $update = $pdo->prepare("
            UPDATE documents SET 
                title = ?,
                company_id = ?,
                amount = ?,
                tax_detail_code = ?,
                tax_id = ?,
                to_invoice_number = ?,
                document_date = ?,
                approval_deadline = ?,
                description = ?,
                current_holder_department_id = ?,
                current_holder_user_id = ?
            WHERE id = ?
        ");
        
        $result = $update->execute([
            $title,
            $company_id,
            $amount,
            $tax_detail_code,
            $tax_id,
            $to_invoice_number,
            $record_date,
            $deadline,
            $description,
            $current_holder_department_id,
            $current_holder_user_id,
            $id
        ]);
        
        if ($result) {
            $success = 'سند مالیاتی با موفقیت ویرایش شد.';
        } else {
            $error = 'خطا در ویرایش سند';
        }
    }
}

$page_title = 'ویرایش سند مالیاتی';
ob_start();
?>

<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
    .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .alert-danger { background: #f8d7da; color: #721c24; }
    .alert-success { background: #d4edda; color: #155724; }
    .required { color: red; }
    .forward-section { background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0; }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>✏️ ویرایش سند مالیاتی</h1>
    <a href="tax-view.php?id=<?php echo $id; ?>" class="btn-back">بازگشت</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <script>setTimeout(function() { window.location.href = 'tax-view.php?id=<?php echo $id; ?>'; }, 1500);</script>
<?php endif; ?>

<?php if (!$success): ?>
<div style="background: white; border-radius: 10px; padding: 20px;">
    <form method="POST">
        <div class="row-2col">
            <div class="form-group">
                <label>عنوان سند <span class="required">*</span></label>
                <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? $tax['title']); ?>">
            </div>
            <div class="form-group">
                <label>انتخاب شرکت <span class="required">*</span></label>
                <select name="company_id" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (($_POST['company_id'] ?? $tax['company_id']) == $c['id']) ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row-2col">
            <div class="form-group">
                <label>مبلغ (تومان) <span class="required">*</span></label>
                <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? number_format($tax['amount'])); ?>" required>
            </div>
            <div class="form-group">
                <label>تاریخ ثبت (شمسی)</label>
                <input type="text" name="record_date" value="<?php echo htmlspecialchars($_POST['record_date'] ?? $tax['document_date']); ?>">
            </div>
        </div>

        <div class="row-3col">
            <div class="form-group">
                <label>کد تفصیل حساب</label>
                <input type="text" name="tax_detail_code" value="<?php echo htmlspecialchars($_POST['tax_detail_code'] ?? $tax['tax_detail_code']); ?>">
            </div>
            <div class="form-group">
                <label>شناسه مالیاتی <span class="required">*</span></label>
                <input type="text" name="tax_id" required value="<?php echo htmlspecialchars($_POST['tax_id'] ?? $tax['tax_id']); ?>">
            </div>
            <div class="form-group">
                <label>به شماره فاکتور</label>
                <input type="text" name="to_invoice_number" value="<?php echo htmlspecialchars($_POST['to_invoice_number'] ?? $tax['to_invoice_number']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label>مهلت پیگیری (روز)</label>
            <input type="number" name="followup_days" min="0" value="<?php echo htmlspecialchars($_POST['followup_days'] ?? 0); ?>">
        </div>

        <div class="form-group">
            <label>توضیحات</label>
            <textarea name="description" rows="4"><?php echo htmlspecialchars($_POST['description'] ?? $tax['description']); ?></textarea>
        </div>

        <div class="forward-section">
            <div class="forward-title">🔁 ارجاع سند (حداقل یکی)</div>
            <div class="row-2col">
                <div class="form-group">
                    <label>📋 ارجاع به بخش</label>
                    <select name="forward_to_department">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo (($_POST['forward_to_department'] ?? $tax['current_holder_department_id']) == $dept['id']) ? 'selected' : ''; ?>><?php echo $dept['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>👤 ارجاع به شخص</label>
                    <select name="forward_to_user">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo (($_POST['forward_to_user'] ?? $tax['current_holder_user_id']) == $user['id']) ? 'selected' : ''; ?>><?php echo $user['full_name']; ?> (<?php echo $user['username']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <button type="submit" name="edit_tax" class="btn-submit">💾 ذخیره تغییرات</button>
    </form>
</div>
<?php endif; ?>

<script>
function formatNumber(input) {
    let val = input.value.replace(/,/g, '');
    if (!isNaN(val) && val !== '') input.value = Number(val).toLocaleString();
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>