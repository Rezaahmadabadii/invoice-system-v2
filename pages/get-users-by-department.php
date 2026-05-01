<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../app/Helpers/functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$department_id = $_GET['department_id'] ?? 0;
if (!$department_id) {
    echo json_encode([]);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE department_id = ? ORDER BY full_name");
$stmt->execute([$department_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($users);
?>