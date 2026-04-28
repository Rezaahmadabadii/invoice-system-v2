-- ============================================
-- جدول شرکت‌های زیرمجموعه
-- ============================================
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE,
    economic_code VARCHAR(50),
    national_id VARCHAR(50),
    phone VARCHAR(50),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_code (code)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- درج نمونه
INSERT IGNORE INTO companies (name, code) VALUES
('شرکت ساختمانی آوا', 'COMP001'),
('شرکت راه‌سازان', 'COMP002'),
('شرکت تجهیزات پارس', 'COMP003'),
('عمران و توسعه', 'COMP004');