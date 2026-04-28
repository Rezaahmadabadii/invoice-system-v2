-- ============================================
-- جدول کارگاه‌ها/بخش‌های پروژه
-- ============================================
CREATE TABLE IF NOT EXISTS workshops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- درج نمونه
INSERT IGNORE INTO workshops (name) VALUES
('کارگاه شمال'),
('کارگاه جنوب'),
('کارگاه شرق'),
('کارگاه غرب'),
('پروژه تقاطع غیرهمسطح'),
('پروژه پل B2');