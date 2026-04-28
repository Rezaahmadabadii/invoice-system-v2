CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_number VARCHAR(50) UNIQUE NOT NULL,
    type ENUM('invoice', 'waybill', 'tax') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    amount DECIMAL(18,2),
    
    -- ارجاع‌ها
    created_by INT NOT NULL,
    company_id INT NULL,
    workshop_id INT NULL,
    vendor_id INT NULL,
    department_id INT NOT NULL, -- بخش ارجاع فعلی
    
    -- تاریخ‌ها
    document_date VARCHAR(20), -- تاریخ شمسی
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    
    -- وضعیت
    status ENUM('draft', 'pending', 'approved', 'rejected', 'completed') DEFAULT 'draft',
    
    -- قرارداد
    has_contract TINYINT(1) DEFAULT 0,
    contract_number VARCHAR(100) NULL,
    
    -- ارزش افزوده
    vat TINYINT(1) DEFAULT 0,
    vat_amount DECIMAL(18,2) DEFAULT 0,
    
    -- فایل
    file_path VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
    
    -- سامانه مودیان (برای نوع tax)
    tax_status ENUM('pending', 'sent', 'failed') NULL,
    tax_sent_date DATETIME NULL,
    
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_department (department_id),
    INDEX idx_created_by (created_by),
    INDEX idx_company (company_id),
    INDEX idx_vendor (vendor_id),
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE SET NULL,
    FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;