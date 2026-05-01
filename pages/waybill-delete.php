<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

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

$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'waybill'");
$stmt->execute([$id]);
$waybill = $stmt->fetch();

if (!$waybill) {
    $_SESSION['error'] = 'بارنامه یافت نشد';
    header('Location: waybills.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_creator = ($waybill['created_by'] == $user_id);
$is_admin = in_array('admin', $_SESSION['user_roles'] ?? []) || in_array('super_admin', $_SESSION['user_roles'] ?? []);

$deletable_statuses = ['draft', 'pending'];
$can_delete = ($is_creator && in_array($waybill['status'], $deletable_statuses)) || $is_admin;

if (!$can_delete) {
    $_SESSION['error'] = 'شما مجاز به حذف این بارنامه نیستید.';
    header('Location: waybill-view.php?id=' . $id);
    exit;
}

if ($waybill['file_path']) {
    $file_path = __DIR__ . '/../' . $waybill['file_path'];
    if (file_exists($file_path)) unlink($file_path);
}

try {
    $pdo->prepare("DELETE FROM forwarding_history WHERE document_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
    $_SESSION['message'] = 'بارنامه با موفقیت حذف شد.';
} catch (Exception $e) {
    $_SESSION['error'] = 'خطا در حذف بارنامه';
}

header('Location: waybills.php');
exit;
?>