<?php
// اجرا کن با آدرس: http://localhost/invoice-system-v2/database/install.php

$host = 'localhost';
$dbname = 'invoice_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>📦 نصب خودکار جداول</h2>";
    
    $sql_files = [
        '01_create_users.sql',
        '02_create_companies.sql',
        '03_create_vendors.sql',
        '04_create_workshops.sql',
        '05_create_departments.sql',
        '06_create_roles_permissions.sql',
        '07_create_documents.sql',
        '08_create_activities.sql'
    ];
    
    foreach ($sql_files as $file) {
        $path = __DIR__ . '/' . $file;
        if (file_exists($path)) {
            $sql = file_get_contents($path);
            $pdo->exec($sql);
            echo "✅ $file ایجاد شد.<br>";
        } else {
            echo "⚠️ $file یافت نشد.<br>";
        }
    }
    
    echo "<br><h3>✅ نصب با موفقیت کامل شد.</h3>";
    
} catch (PDOException $e) {
    die("❌ خطا: " . $e->getMessage());
}
?>