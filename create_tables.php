<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'invoice_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ایجاد جدول users (اگر وجود نداره)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            full_name VARCHAR(100),
            role ENUM('admin', 'supervisor', 'finance', 'user') DEFAULT 'user',
            last_login DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci
    ");
    echo "✅ جدول users ایجاد شد<br>";
    
    // ایجاد جدول customers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20),
            email VARCHAR(100),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci
    ");
    echo "✅ جدول customers ایجاد شد<br>";
    
    // ایجاد جدول invoices
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) UNIQUE NOT NULL,
            customer_id INT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            amount DECIMAL(18,2) NOT NULL,
            tax DECIMAL(18,2) DEFAULT 0,
            discount DECIMAL(18,2) DEFAULT 0,
            total_amount DECIMAL(18,2) GENERATED ALWAYS AS (amount + tax - discount) STORED,
            status ENUM('draft', 'pending', 'under_review', 'approved', 'rejected', 'paid') DEFAULT 'draft',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci
    ");
    echo "✅ جدول invoices ایجاد شد<br>";
    
    // ایجاد جدول invoice_items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            quantity INT DEFAULT 1,
            price DECIMAL(18,2) NOT NULL,
            total DECIMAL(18,2) GENERATED ALWAYS AS (quantity * price) STORED,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci
    ");
    echo "✅ جدول invoice_items ایجاد شد<br>";
    
    // ایجاد جدول activities
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci
    ");
    echo "✅ جدول activities ایجاد شد<br>";
    
    echo "<br><h2 style='color:green;'>✅ همه جدول‌ها با موفقیت ایجاد شدند!</h2>";
    echo "<p><a href='dashboard.php'>رفتن به داشبورد</a></p>";
    
} catch(PDOException $e) {
    die("❌ خطا: " . $e->getMessage());
}
?>