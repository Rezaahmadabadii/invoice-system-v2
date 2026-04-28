CREATE TABLE IF NOT EXISTS forwarding (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    from_user INT NOT NULL,
    to_role INT NOT NULL, -- به کدام نقش ارجاع شده
    to_user INT NULL, -- اگر به شخص خاصی ارجاع شده
    deadline DATETIME NOT NULL, -- مهلت
    status ENUM('pending', 'viewed', 'forwarded', 'approved', 'rejected') DEFAULT 'pending',
    notes TEXT,
    viewed_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_document (document_id),
    INDEX idx_from (from_user),
    INDEX idx_to_role (to_role),
    INDEX idx_status (status),
    
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_role) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;