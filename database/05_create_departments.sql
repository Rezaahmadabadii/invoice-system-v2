-- ============================================
-- جدول بخش‌ها/واحدهای سازمانی (برای ارجاع)
-- ============================================
CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- درج نمونه
INSERT IGNORE INTO departments (name, description) VALUES
('مدیریت', 'مدیران ارشد'),
('امور مالی', 'حسابداری و امور مالی'),
('فنی و مهندسی', 'نظارت بر پروژه‌ها'),
('بازرگانی', 'خرید و تدارکات'),
('پیمانکاران', 'مدیریت قراردادها'),
('حقوقی', 'امور حقوقی');