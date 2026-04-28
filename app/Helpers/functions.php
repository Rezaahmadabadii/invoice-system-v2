<?php
/**
 * توابع کمکی
 */

if (!function_exists('jdate')) {
    function jdate($format, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        if (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        
        if (class_exists('jDateTime')) {
            return jDateTime::date($format, $timestamp, false, true, 'Asia/Tehran');
        }
        return date($format, $timestamp);
    }
}

// ============================================
// توابع بررسی دسترسی
// ============================================

if (!function_exists('hasRole')) {
    function hasRole($role_name) {
        if (!isset($_SESSION['user_roles'])) return false;
        return in_array($role_name, $_SESSION['user_roles']);
    }
}

if (!function_exists('hasAnyRole')) {
    function hasAnyRole($roles) {
        if (!isset($_SESSION['user_roles'])) return false;
        foreach ($roles as $role) {
            if (in_array($role, $_SESSION['user_roles'])) return true;
        }
        return false;
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission($permission_name) {
        global $pdo;
        
        if (!isset($_SESSION['user_id'])) return false;
        if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin']) return true;
        
        static $permissions_cache = [];
        
        if (isset($permissions_cache[$_SESSION['user_id']])) {
            return in_array($permission_name, $permissions_cache[$_SESSION['user_id']]);
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.name 
                FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                JOIN user_roles ur ON rp.role_id = ur.role_id
                WHERE ur.user_id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $permissions_cache[$_SESSION['user_id']] = $permissions;
            return in_array($permission_name, $permissions);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin() {
        return isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'];
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
    }
}

// ============================================
// توابع ثبت فعالیت
// ============================================

if (!function_exists('logActivity')) {
    function logActivity($user_id, $action, $description, $document_id = null, $old_value = null, $new_value = null) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activities (user_id, document_id, action, description, old_value, new_value, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            return $stmt->execute([
                $user_id,
                $document_id,
                $action,
                $description,
                $old_value ? json_encode($old_value) : null,
                $new_value ? json_encode($new_value) : null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
}