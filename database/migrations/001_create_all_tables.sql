-- ============================================
-- سیستم مدیریت فاکتورها - اسکریپت کامل پایگاه داده
-- ============================================

-- ایجاد دیتابیس
CREATE DATABASE IF NOT EXISTS invoice_system 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_persian_ci;

USE invoice_system;

-- ============================================
-- ۱. جدول کاربران
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100),
    phone VARCHAR(20),
    department VARCHAR(100),
    position VARCHAR(100),
    avatar VARCHAR(255),
    role ENUM('admin', 'supervisor', 'finance', 'user') DEFAULT 'user',
    remember_token VARCHAR(255) NULL,
    last_login DATETIME NULL,
    last_ip VARCHAR(45),
    login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    status ENUM('active', 'inactive', 'locked') DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۲. جدول فاکتورها
-- ============================================
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    project_code VARCHAR(50),
    contractor VARCHAR(255),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    amount DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) DEFAULT 0,
    discount_amount DECIMAL(18,2) DEFAULT 0,
    currency ENUM('IRR', 'USD', 'EUR') DEFAULT 'IRR',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    payment_date DATE NULL,
    payment_method VARCHAR(50),
    payment_reference VARCHAR(100),
    status ENUM('draft', 'pending', 'under_review', 'approved', 'rejected', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    file_path VARCHAR(500),
    file_name VARCHAR(255),
    file_size INT,
    file_type VARCHAR(100),
    created_by INT NOT NULL,
    updated_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_number (invoice_number),
    INDEX idx_project (project_code),
    INDEX idx_status (status),
    INDEX idx_dates (issue_date, due_date),
    INDEX idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۳. جدول آیتم‌های فاکتور
-- ============================================
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_code VARCHAR(50),
    description TEXT NOT NULL,
    quantity DECIMAL(15,2) NOT NULL DEFAULT 1,
    unit VARCHAR(20),
    unit_price DECIMAL(18,2) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    tax_percent DECIMAL(5,2) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_id (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۴. جدول فعالیت‌ها
-- ============================================
CREATE TABLE activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    related_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۵. جدول بازنشانی رمز عبور
-- ============================================
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(100) UNIQUE NOT NULL,
    expires DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_expires (expires),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۶. جدول تنظیمات
-- ============================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text',
    category VARCHAR(50) DEFAULT 'general',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۷. جدول اعلان‌ها
-- ============================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(500),
    related_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۸. جدول فایل‌ها
-- ============================================
CREATE TABLE files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    related_type VARCHAR(50) NOT NULL,
    related_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    file_type VARCHAR(100),
    mime_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_related (related_type, related_id),
    INDEX idx_uploader (uploaded_by),
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- ۹. داده‌های اولیه
-- ============================================

-- کاربر ادمین (رمز: 123)
INSERT INTO users (username, password, email, full_name, role, status) VALUES
('admin', '$2y$10$4B5ox8qE.LWZ4YqkOwDk0e3G1rPfK2gVXxJZQyWzRlM7nS8tUvWqO', 'admin@company.com', 'مدیر سیستم', 'admin', 'active');

-- تنظیمات اولیه
INSERT INTO settings (setting_key, setting_value, setting_type, category, description) VALUES
('site_name', 'سیستم مدیریت فاکتورها', 'text', 'general', 'نام سایت'),
('items_per_page', '20', 'number', 'general', 'تعداد آیتم در هر صفحه'),
('date_format', 'Y-m-d', 'text', 'general', 'فرمت تاریخ'),
('time_format', 'H:i:s', 'text', 'general', 'فرمت زمان'),
('currency', 'IRR', 'text', 'financial', 'واحد پول پیش‌فرض'),
('tax_rate', '9', 'number', 'financial', 'درصد مالیات پیش‌فرض'),
('session_timeout', '3600', 'number', 'security', 'مدت زمان نشست (ثانیه)'),
('max_login_attempts', '5', 'number', 'security', 'حداکثر تلاش برای ورود'),
('lockout_time', '900', 'number', 'security', 'مدت زمان قفل (ثانیه)'),
('upload_max_size', '10485760', 'number', 'files', 'حداکثر حجم آپلود (10MB)'),
('allowed_file_types', '["jpg","jpeg","png","pdf","doc","docx","xls","xlsx"]', 'json', 'files', 'نوع فایل‌های مجاز');