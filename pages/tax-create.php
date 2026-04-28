<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id']) || !hasPermission('send_to_tax')) {
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

$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1 ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_tax'])) {
    $title = trim($_POST['title'] ?? '');
    $company_id = $_POST['company_id'] ?? '';
    $amount = str_replace(',', '', $_POST['amount'] ?? 0);
    $tax_detail_code = trim($_POST['tax_detail_code'] ?? '');
    $record_date = trim($_POST['record_date'] ?? jdate('Y/m/d'));
    $followup_days = (int)($_POST['followup_days'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $forward_to_department = $_POST['forward_to_department'] ?? '';
    $forward_to_user = $_POST['forward_to_user'] ?? '';

    if (empty($title) || empty($company_id) || empty($amount) || empty($tax_detail_code)) {
        $error = 'لطفاً عنوان، شرکت، مبلغ و کد تفصیل را وارد کنید';
    } elseif (empty($forward_to_department) && empty($forward_to_user)) {
        $error = 'لطفاً حداقل یکی از فیلدهای ارجاع را انتخاب کنید';
    } else {
        // محاسبه مهلت نهایی به میلادی
        $deadline = null;
        if ($followup_days > 0) {
            $deadline = date('Y-m-d H:i:s', strtotime('+' . $followup_days . ' days'));
        }

        // آپلود فایل
        $file_path = null;
        $file_name = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $max_size = 5 * 1024 * 1024;
            if (in_array($_FILES['attachment']['type'], $allowed) && $_FILES['attachment']['size'] <= $max_size) {
                $upload_dir = __DIR__ . '/../uploads/tax/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $file_name = time() . '_tax_' . rand(1000, 9999) . '.' . $ext;
                $file_path = 'uploads/tax/' . $file_name;
                move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $file_name);
            } else {
                $error = 'فرمت فایل نامعتبر یا حجم بیش از حد مجاز (۵ مگابایت)';
            }
        }

        if (!isset($error)) {
            $doc_number = 'TAX-' . date('Ymd') . '-' . rand(100, 999);
            $data = [
                'document_number' => $doc_number,
                'type' => 'tax',
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'created_by' => $_SESSION['user_id'],
                'company_id' => $company_id,
                'document_date' => $record_date,
                'status' => 'forwarded',
                'tax_id' => $tax_detail_code,
                'tax_status' => 'pending',
                'approval_deadline' => $deadline,
                'file_path' => $file_path,
                'file_name' => $_FILES['attachment']['name'] ?? null,
                'current_holder_department_id' => $forward_to_department ?: null,
                'current_holder_user_id' => $forward_to_user ?: null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $stmt = $pdo->prepare("INSERT INTO documents ($fields) VALUES ($placeholders)");
            if ($stmt->execute($data)) {
                $doc_id = $pdo->lastInsertId();
                $forward = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action) VALUES (?, ?, ?, ?, 'forward')");
                $forward->execute([$doc_id, $_SESSION['user_id'], $forward_to_department ?: null, $forward_to_user ?: null]);
                $success = 'سند مالیاتی با موفقیت ثبت و ارسال شد. شماره: ' . $doc_number;
            } else {
                $error = 'خطا در ثبت سند';
            }
        }
    }
}

$page_title = 'ایجاد سند مالیاتی جدید';
ob_start();
?>

<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
    .row-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .btn-submit { background: #27ae60; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
    .btn-back { background: #95a5a6; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; }
    .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    .alert-danger { background: #f8d7da; color: #721c24; }
    .alert-success { background: #d4edda; color: #155724; }
</style>

<div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
    <h1>ایجاد سند مالیاتی جدید</h1>
    <a href="tax.php" class="btn-back">بازگشت</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <div class="row-2col">
        <div class="form-group">
            <label>عنوان سند <span style="color:red;">*</span></label>
            <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>شرکت <span style="color:red;">*</span></label>
            <select name="company_id" required>
                <option value="">انتخاب کنید</option>
                <?php foreach ($companies as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo ($_POST['company_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo $c['name']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row-2col">
        <div class="form-group">
            <label>کد تفصیل (شناسه مالیاتی) <span style="color:red;">*</span></label>
            <input type="text" name="tax_detail_code" required value="<?php echo htmlspecialchars($_POST['tax_detail_code'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>تاریخ ثبت (شمسی)</label>
            <input type="text" name="record_date" value="<?php echo htmlspecialchars($_POST['record_date'] ?? jdate('Y/m/d')); ?>" placeholder="۱۴۰۴/۱۲/۰۷">
        </div>
    </div>

    <div class="row-2col">
        <div class="form-group">
            <label>مبلغ (تومان) <span style="color:red;">*</span></label>
            <input type="text" name="amount" id="amount" onkeyup="formatNumber(this)" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label>مهلت پیگیری (روز)</label>
            <input type="number" name="followup_days" id="followup_days" min="0" value="<?php echo htmlspecialchars($_POST['followup_days'] ?? 0); ?>">
            <small>تعداد روز تا مهلت پیگیری (۰ = بدون مهلت)</small>
        </div>
    </div>

    <div class="form-group">
        <label>ضمیمه (عکس یا PDF)</label>
        <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">
        <small>حداکثر حجم ۵ مگابایت</small>
    </div>

    <div class="form-group">
        <label>توضیحات</label>
        <textarea name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
    </div>

    <!-- ارجاع اجباری -->
    <div style="background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0;">
        <h3>🔁 ارجاع سند (اجباری)</h3>
        <div class="row-2col">
            <div>
                <label>ارجاع به بخش</label>
                <select name="forward_to_department">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo $d['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>ارجاع به شخص</label>
                <select name="forward_to_user">
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>"><?php echo $u['full_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <small>* حداقل یکی از دو گزینه باید انتخاب شود</small>
    </div>

    <button type="submit" name="create_tax" class="btn-submit">ثبت و ارسال سند</button>
</form>

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