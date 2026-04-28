-- ============================================
-- جدول فروشندگان/پیمانکاران
-- ============================================
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE,
    economic_code VARCHAR(50),
    national_id VARCHAR(50),
    phone VARCHAR(50),
    address TEXT,
    contract_number VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_code (code)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- درج نمونه
INSERT IGNORE INTO vendors (name, code, contract_number) VALUES
('پیمانکار امین', 'VEN001', 'CON-1403-001'),
('تأمین مصالح', 'VEN002', 'CON-1403-002'),
('حمل و نقل سریع', 'VEN003', NULL),
('خدمات فنی', 'VEN004', 'CON-1403-003');