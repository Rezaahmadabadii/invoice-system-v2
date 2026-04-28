<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
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

$stmt = $pdo->prepare("SELECT d.*, c.name as company_name, holder_dep.name as holder_department_name, holder_user.full_name as holder_user_name FROM documents d LEFT JOIN companies c ON d.company_id = c.id LEFT JOIN roles holder_dep ON d.current_holder_department_id = holder_dep.id LEFT JOIN users holder_user ON d.current_holder_user_id = holder_user.id WHERE d.id = ? AND d.type = 'tax'");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: tax.php');
    exit;
}

$user_roles = $_SESSION['user_role_ids'] ?? [];
$is_holder = ($doc['current_holder_user_id'] == $_SESSION['user_id']) || ($doc['current_holder_department_id'] && in_array($doc['current_holder_department_id'], $user_roles));
$is_admin = in_array('admin', $_SESSION['user_roles'] ?? []) || in_array('super_admin', $_SESSION['user_roles'] ?? []);
$can_action = $is_holder || $is_admin;

// پردازش اقدامات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $can_action) {
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $to_department = $_POST['to_department'] ?? '';
    $to_user = $_POST['to_user'] ?? '';
    $new_status = null;

    if ($action == 'view') $new_status = 'viewed';
    elseif ($action == 'review') $new_status = 'under_review';
    elseif ($action == 'forward') {
        if (empty($to_department) && empty($to_user)) $error = 'ارجاع به بخش یا شخص الزامی است';
        else {
            $upd = $pdo->prepare("UPDATE documents SET current_holder_department_id = ?, current_holder_user_id = ? WHERE id = ?");
            $upd->execute([$to_department ?: null, $to_user ?: null, $id]);
            $new_status = 'forwarded';
        }
    } elseif ($action == 'approve') $new_status = 'approved';
    elseif ($action == 'reject') {
        if (empty($notes)) $error = 'دلیل رد را وارد کنید';
        else {
            $upd = $pdo->prepare("UPDATE documents SET rejection_reason = ? WHERE id = ?");
            $upd->execute([$notes, $id]);
            $new_status = 'rejected';
        }
    }

    if ($new_status && !isset($error)) {
        $upd = $pdo->prepare("UPDATE documents SET status = ? WHERE id = ?");
        $upd->execute([$new_status, $id]);
        $act = $pdo->prepare("INSERT INTO forwarding_history (document_id, from_user_id, to_department_id, to_user_id, action, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $act->execute([$id, $_SESSION['user_id'], $to_department ?: null, $to_user ?: null, $action, $notes]);
        $success = 'اقدام با موفقیت ثبت شد.';
    }
}

$departments = $pdo->query("SELECT id, name FROM roles WHERE is_department = 1")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
$history = $pdo->prepare("SELECT fh.*, u_from.full_name as from_name, u_to.full_name as to_name, r_to.name as to_department_name FROM forwarding_history fh LEFT JOIN users u_from ON fh.from_user_id = u_from.id LEFT JOIN users u_to ON fh.to_user_id = u_to.id LEFT JOIN roles r_to ON fh.to_department_id = r_to.id WHERE fh.document_id = ? ORDER BY fh.created_at ASC");
$history->execute([$id]);
$history_list = $history->fetchAll();

$page_title = 'مشاهده سند مالیاتی';
ob_start();
?>

<div style="display: flex; justify-content: space-between; margin-bottom:20px;">
    <h1>🏛️ سند مالیاتی <?php echo htmlspecialchars($doc['document_number']); ?></h1>
    <a href="tax.php" class="btn btn-secondary">بازگشت</a>
</div>

<!-- اطلاعات اصلی -->
<div style="background:white; border-radius:10px; padding:20px; margin-bottom:20px;">
    <div class="row-2col">
        <div><strong>عنوان:</strong> <?php echo htmlspecialchars($doc['title']); ?></div>
        <div><strong>شرکت:</strong> <?php echo htmlspecialchars($doc['company_name']??'-'); ?></div>
        <div><strong>شناسه مالیاتی:</strong> <?php echo htmlspecialchars($doc['tax_id']??'-'); ?></div>
        <div><strong>شماره صورتحساب:</strong> <?php echo htmlspecialchars($doc['tax_invoice_number']??'-'); ?></div>
        <div><strong>مبلغ:</strong> <?php echo number_format($doc['amount']); ?> تومان</div>
        <div><strong>تاریخ ثبت:</strong> <?php echo jdate('Y/m/d', strtotime($doc['created_at'])); ?></div>
        <div><strong>وضعیت سند:</strong> <?php echo $doc['status']; ?></div>
        <div><strong>وضعیت ارسال:</strong> <?php echo $doc['tax_status']; ?></div>
        <div><strong>در دست:</strong> <?php echo htmlspecialchars($doc['holder_user_name']??$doc['holder_department_name']??'-'); ?></div>
    </div>
    <?php if($doc['description']): ?><div><strong>توضیحات:</strong> <?php echo nl2br(htmlspecialchars($doc['description'])); ?></div><?php endif; ?>
</div>

<!-- فرم اقدامات (برای دارنده فعلی یا ادمین) -->
<?php if($can_action): ?>
<div style="background:#f9f9f9; border-radius:10px; padding:20px; margin-bottom:20px;">
    <h3>اقدامات روی سند</h3>
    <form method="POST">
        <select name="action" id="actionSelect" required>
            <option value="view">مشاهده شد</option>
            <option value="review">بررسی شد</option>
            <option value="forward">ارجاع به بخش/شخص</option>
            <option value="approve">تایید نهایی</option>
            <option value="reject">رد با ذکر دلیل</option>
        </select>
        <div id="forwardFields" style="display:none; margin-top:15px;">
            <div class="row-2col">
                <select name="to_department"><option value="">انتخاب بخش</option><?php foreach($departments as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo $d['name']; ?></option><?php endforeach; ?></select>
                <select name="to_user"><option value="">انتخاب شخص</option><?php foreach($users as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo $u['full_name']; ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div><textarea name="notes" rows="2" placeholder="توضیحات (در صورت رد، اجباری)" style="width:100%; margin-top:10px;"></textarea></div>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">ثبت اقدام</button>
    </form>
</div>
<?php endif; ?>

<!-- تاریخچه ارجاع -->
<div style="background:white; border-radius:10px; padding:20px;">
    <h3>تاریخچه ارجاع و اقدامات</h3>
    <?php if(empty($history_list)): ?><p>هیچ اقدامی ثبت نشده است.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>زمان</th><th>از</th><th>به</th><th>اقدام</th><th>توضیحات</th></tr></thead>
        <tbody><?php foreach($history_list as $h): ?>
        <tr>
            <td><?php echo jdate('Y/m/d H:i', strtotime($h['created_at'])); ?></td>
            <td><?php echo htmlspecialchars($h['from_name']); ?></td>
            <td><?php echo htmlspecialchars($h['to_name']??$h['to_department_name']??'-'); ?></td>
            <td><?php echo $h['action']; ?></td>
            <td><?php echo nl2br(htmlspecialchars($h['notes']??'')); ?></td>
        </tr><?php endforeach; ?></tbody>
    </table><?php endif; ?>
</div>

<script>
document.getElementById('actionSelect')?.addEventListener('change', function() {
    document.getElementById('forwardFields').style.display = this.value === 'forward' ? 'block' : 'none';
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>