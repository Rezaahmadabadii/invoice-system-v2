-- جدول شرکت‌های زیرمجموعه (هلدینگ)
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    economic_code VARCHAR(50),
    national_id VARCHAR(50),
    phone VARCHAR(50),
    address TEXT,
    logo VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    parent_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES companies(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- جدول پیمانکاران/فروشندگان
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    economic_code VARCHAR(50),
    national_id VARCHAR(50),
    phone VARCHAR(50),
    address TEXT,
    company_id INT,
    category ENUM('material', 'service', 'transport', 'other') DEFAULT 'material',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- جدول بارنامه‌ها
CREATE TABLE IF NOT EXISTS waybills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    waybill_number VARCHAR(100) UNIQUE NOT NULL,
    company_id INT NOT NULL,
    vendor_id INT,
    invoice_id INT NULL,
    date DATE NOT NULL,
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    goods_description TEXT,
    quantity DECIMAL(15,2),
    weight DECIMAL(15,2),
    amount DECIMAL(18,2),
    driver_name VARCHAR(255),
    driver_phone VARCHAR(50),
    vehicle_plate VARCHAR(50),
    status ENUM('draft', 'in_progress', 'completed', 'cancelled') DEFAULT 'draft',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    INDEX idx_number (waybill_number),
    INDEX idx_date (date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- اضافه کردن فیلدهای جدید به جدول invoices
ALTER TABLE invoices 
ADD COLUMN IF NOT EXISTS company_id INT NULL AFTER customer_id,
ADD COLUMN IF NOT EXISTS vendor_id INT NULL AFTER company_id,
ADD COLUMN IF NOT EXISTS waybill_id INT NULL AFTER vendor_id,
ADD COLUMN IF NOT EXISTS sent_to_tax BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS tax_status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS tax_sent_date DATETIME NULL,
ADD COLUMN IF NOT EXISTS tax_response TEXT,
ADD INDEX idx_company (company_id),
ADD INDEX idx_vendor (vendor_id),
ADD INDEX idx_tax_status (tax_status),
ADD FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
ADD FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL;

-- جدول فعالیت‌ها (اگر وجود نداره)
CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    related_type VARCHAR(50),
    related_id INT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_related (related_type, related_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- درج شرکت‌های نمونه
INSERT INTO companies (name, code, economic_code, national_id, status) VALUES
('هلدینگ راه‌وساختمان', 'HOLD001', '1234567890', '01234567890', 'active'),
('شرکت ساختمانی آوا', 'COMP001', '1234567891', '01234567891', 'active'),
('شرکت راه‌سازان', 'COMP002', '1234567892', '01234567892', 'active'),
('شرکت تجهیزات پارس', 'COMP003', '1234567893', '01234567893', 'active'),
('پیمانکاری مهر', 'COMP004', '1234567894', '01234567894', 'active'),
('عمران و توسعه', 'COMP005', '1234567895', '01234567895', 'active');

-- درج پیمانکاران نمونه
INSERT INTO vendors (name, code, economic_code, national_id, company_id, category, status) VALUES
('پیمانکار امین', 'VEN001', '9876543210', '09876543210', 1, 'service', 'active'),
('تأمین مصالح', 'VEN002', '9876543211', '09876543211', 2, 'material', 'active'),
('حمل و نقل سریع', 'VEN003', '9876543212', '09876543212', 3, 'transport', 'active'),
('خدمات فنی', 'VEN004', '9876543213', '09876543213', 4, 'service', 'active');