<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
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
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'invoice'");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    $_SESSION['error'] = 'فاکتور مورد نظر یافت نشد.';
    header('Location: inbox.php');
    exit;
}

// بررسی دسترسی برای حذف
$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['user_roles'] ?? [];
$is_admin = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);
$is_creator = ($invoice['created_by'] == $user_id);

// وضعیت‌هایی که قابل حذف هستند (پیش‌نویس یا ارسال شده ولی هنوز مشاهده نشده)
$deletable_statuses = ['draft', 'forwarded'];

$can_delete = false;

if ($is_creator && in_array($invoice['status'], $deletable_statuses)) {
    $can_delete = true;
} elseif ($is_admin) {
    // ادمین می‌تواند در شرایط خاص حذف کند (مثلاً فاکتورهای بسته شده را هم حذف کند - اختیاری)
    $can_delete = true;
}

if (!$can_delete) {
    $_SESSION['error'] = 'شما مجاز به حذف این فاکتور نیستید. فاکتورهایی که مشاهده یا بررسی شده‌اند قابل حذف نیستند.';
    header('Location: invoice-view.php?id=' . $id);
    exit;
}

// حذف فایل فیزیکی (اگر وجود داشته باشد)
if ($invoice['file_path']) {
    $file_path = __DIR__ . '/../' . $invoice['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

// حذف فاکتور از دیتابیس
try {
    // ابتدا تاریخچه ارجاع را حذف کنید (در صورت وجود کلید خارجی)
    $pdo->prepare("DELETE FROM forwarding_history WHERE document_id = ?")->execute([$id]);
    
    // سپس خود سند را حذف کنید
    $delete = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $delete->execute([$id]);
    
    logActivity($_SESSION['user_id'], 'delete_invoice', "فاکتور حذف شد: " . $invoice['document_number'], $id);
    
    $_SESSION['message'] = 'فاکتور با موفقیت حذف شد.';
    $_SESSION['message_type'] = 'success';
} catch (Exception $e) {
    $_SESSION['error'] = 'خطا در حذف فاکتور: ' . $e->getMessage();
}

header('Location: inbox.php');
exit;
?>