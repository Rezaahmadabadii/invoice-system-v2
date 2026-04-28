-- ============================================
-- ایجاد جداول مربوط به سطوح دسترسی
-- ============================================

-- ۱. جدول سطوح دسترسی (Roles)
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۲. جدول دسترسی‌ها (Permissions)
CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۳. جدول ارتباط نقش‌ها و دسترسی‌ها
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۴. جدول ارتباط کاربران و نقش‌ها
CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_by INT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ============================================
-- درج داده‌های اولیه (فقط اگر جدول خالی باشه)
-- ============================================

-- دسترسی‌ها
INSERT IGNORE INTO permissions (name, display_name, category) VALUES
('create_invoice', 'ایجاد فاکتور', 'invoice'),
('edit_invoice', 'ویرایش فاکتور', 'invoice'),
('delete_invoice', 'حذف فاکتور', 'invoice'),
('view_invoice', 'مشاهده فاکتور', 'invoice'),
('approve_invoice', 'تأیید نهایی فاکتور', 'invoice'),
('create_waybill', 'ایجاد بارنامه', 'waybill'),
('edit_waybill', 'ویرایش بارنامه', 'waybill'),
('delete_waybill', 'حذف بارنامه', 'waybill'),
('view_waybill', 'مشاهده بارنامه', 'waybill'),
('send_to_tax', 'ارسال به سامانه مودیان', 'tax'),
('view_tax_reports', 'مشاهده گزارش مالیاتی', 'tax'),
('forward_to_others', 'ارجاع به دیگران', 'forward'),
('view_all_forwarded', 'مشاهده همه ارجاع‌ها', 'forward'),
('manage_users', 'مدیریت کاربران', 'admin'),
('manage_roles', 'مدیریت سطوح دسترسی', 'admin'),
('view_logs', 'مشاهده گزارش‌ها', 'admin');

-- نقش‌ها
INSERT IGNORE INTO roles (name, description) VALUES
('super_admin', 'مدیر ارشد سیستم - دسترسی کامل'),
('admin', 'مدیر عادی - دسترسی مدیریتی'),
('finance', 'مدیر مالی - دسترسی به امور مالی'),
('supervisor', 'ناظر - تأییدکننده'),
('user', 'کاربر عادی');

-- اختصاص دسترسی‌ها به نقش‌ها (فقط اگر خالی باشه)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;  -- super_admin همه دسترسی‌ها

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE category IN ('invoice', 'waybill', 'tax', 'forward', 'admin');  -- admin

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE name IN ('view_invoice', 'view_tax_reports', 'approve_invoice');  -- finance

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE name IN ('view_invoice', 'approve_invoice', 'forward_to_others');  -- supervisor

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE name IN ('create_invoice', 'view_invoice', 'create_waybill', 'view_waybill');  -- user