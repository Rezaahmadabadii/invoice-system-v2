<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? '';
$mark_as_viewed = $input['mark_as_viewed'] ?? false;
$doc_id = $input['doc_id'] ?? 0;

$user_id = $_SESSION['user_id'];
$user_department_id = $_SESSION['user_department_id'] ?? null;

$host = 'localhost';
$dbname = 'invoice_system';
$username_db = 'root';
$password_db = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// تغییر وضعیت سند به 'viewed'
if ($mark_as_viewed && $doc_id > 0) {
    $stmt = $pdo->prepare("
        UPDATE documents 
        SET status = 'viewed' 
        WHERE id = ? 
        AND status IN ('pending', 'forwarded')
        AND (current_holder_user_id = ? OR current_holder_department_id = ?)
    ");
    $stmt->execute([$doc_id, $user_id, $user_department_id]);
}

// شمارش اسناد در انتظار برای هر سه بخش
$response = [];

// فاکتورها
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents 
    WHERE type = 'invoice' 
    AND status IN ('pending', 'forwarded')
    AND (current_holder_user_id = ? OR current_holder_department_id = ?)
");
$stmt->execute([$user_id, $user_department_id]);
$response['invoice_count'] = (int)$stmt->fetchColumn();

// بارنامه‌ها
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents 
    WHERE type = 'waybill' 
    AND status IN ('pending', 'forwarded')
    AND (current_holder_user_id = ? OR current_holder_department_id = ?)
");
$stmt->execute([$user_id, $user_department_id]);
$response['waybill_count'] = (int)$stmt->fetchColumn();

// مالیاتی
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM documents 
    WHERE type = 'tax' 
    AND status IN ('pending', 'forwarded')
    AND (current_holder_user_id = ? OR current_holder_department_id = ?)
");
$stmt->execute([$user_id, $user_department_id]);
$response['tax_count'] = (int)$stmt->fetchColumn();

header('Content-Type: application/json');
echo json_encode($response);
?>