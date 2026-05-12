<?php
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../vendor/sallar/jdatetime/jdatetime.class.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

$host = 'localhost';
$dbname = 'invoice_system';
$username_db = 'root';
$password_db = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("خطا در اتصال به پایگاه داده: " . $e->getMessage());
}

$companies = $pdo->query("SELECT id, name, short_name FROM companies ORDER BY name")->fetchAll();

// ========== فیلترها ==========
$current_year = jdate('Y');
$current_month = jdate('n');

$selected_year = $_GET['year'] ?? $current_year;
$selected_month = $_GET['month'] ?? $current_month;

$months = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
$years = range($current_year - 2, $current_year + 2);

$filter_company = $_GET['company'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_financial = $_GET['financial_delivered'] ?? ''; // فیلتر جدید
$search = $_GET['search'] ?? '';

$date_condition = "";
$params = [];

if ($selected_year && $selected_month) {
    $last_day = ($selected_month <= 6) ? 31 : (($selected_month <= 11) ? 30 : 29);
    $start_parts = explode('/', "$selected_year/$selected_month/01");
    $end_parts = explode('/', "$selected_year/$selected_month/$last_day");
    
    list($g_start_y, $g_start_m, $g_start_d) = jDateTime::toGregorian($start_parts[0], $start_parts[1], $start_parts[2]);
    list($g_end_y, $g_end_m, $g_end_d) = jDateTime::toGregorian($end_parts[0], $end_parts[1], $end_parts[2]);
    
    $start_date_greg = sprintf('%04d-%02d-%02d 00:00:00', $g_start_y, $g_start_m, $g_start_d);
    $end_date_greg = sprintf('%04d-%02d-%02d 23:59:59', $g_end_y, $g_end_m, $g_end_d);
    
    $date_condition = " AND d.created_at BETWEEN ? AND ?";
    $params[] = $start_date_greg;
    $params[] = $end_date_greg;
}

// نقش‌های کاربر
$user_roles = $_SESSION['user_roles'] ?? [];
if (empty($user_roles) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ?");
    $stmt->execute([$user_id]);
    $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['user_roles'] = $user_roles;
}

$is_admin_user = in_array('admin', $user_roles) || in_array('super_admin', $user_roles);
$is_admin_flag = $is_admin_user ? 'admin' : 'no';

// ========== کوئری اصلی با فیلتر جدید ==========
$sql = "SELECT d.*, c.name as company_name, c.short_name, v.name as vendor_name,
               creator.full_name as creator_name,
               (SELECT COUNT(*) FROM document_approvals WHERE document_id = d.id) as total_approvers,
               (SELECT COUNT(*) FROM document_approvals WHERE document_id = d.id AND status = 'approved') as approved_count,
               (SELECT COUNT(*) FROM document_approvals WHERE document_id = d.id AND status = 'rejected') as rejected_count
        FROM documents d 
        LEFT JOIN companies c ON d.company_id = c.id 
        LEFT JOIN vendors v ON d.vendor_id = v.id
        LEFT JOIN users creator ON d.created_by = creator.id
        WHERE d.type = 'invoice'
        AND (
            ? = 'admin'
            OR d.created_by = ? 
            OR EXISTS (SELECT 1 FROM document_approvals da WHERE da.document_id = d.id AND da.user_id = ?)
        )
        $date_condition";

$params_main = array_merge([$is_admin_flag, $user_id, $user_id], $params);

if ($filter_company) {
    $sql .= " AND d.company_id = ?";
    $params_main[] = $filter_company;
}
if ($filter_status) {
    $sql .= " AND d.status = ?";
    $params_main[] = $filter_status;
}
if ($filter_financial === 'yes') {
    $sql .= " AND d.financial_delivered = 1";
} elseif ($filter_financial === 'no') {
    $sql .= " AND (d.financial_delivered = 0 OR d.financial_delivered IS NULL)";
}
if ($search) {
    $sql .= " AND (d.document_number LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR v.name LIKE ?)";
    $params_main[] = "%$search%";
    $params_main[] = "%$search%";
    $params_main[] = "%$search%";
    $params_main[] = "%$search%";
}

$sql .= " ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params_main);
$invoices = $stmt->fetchAll();

// ========== تاریخچه (۲ رویداد آخر) ==========
$history_sql = "SELECT 
                   fh.*,
                   u_from.full_name as from_name,
                   u_to.full_name as to_name,
                   d.document_number,
                   d.title as invoice_title,
                   d.created_at as doc_created_at
                FROM forwarding_history fh
                JOIN documents d ON fh.document_id = d.id
                LEFT JOIN users u_from ON fh.from_user_id = u_from.id
                LEFT JOIN users u_to ON fh.to_user_id = u_to.id
                WHERE d.type = 'invoice'
                AND (
                    ? = 'admin'
                    OR d.created_by = ? 
                    OR EXISTS (SELECT 1 FROM document_approvals da WHERE da.document_id = d.id AND da.user_id = ?)
                )
                AND fh.id IN (
                    SELECT MAX(id) FROM forwarding_history 
                    WHERE document_id IN (SELECT id FROM documents WHERE type = 'invoice')
                    GROUP BY document_id
                )";

$history_params = [$is_admin_flag, $user_id, $user_id];

if ($filter_company) {
    $history_sql .= " AND d.company_id = ?";
    $history_params[] = $filter_company;
}
if ($filter_status) {
    $history_sql .= " AND d.status = ?";
    $history_params[] = $filter_status;
}
if ($filter_financial === 'yes') {
    $history_sql .= " AND d.financial_delivered = 1";
} elseif ($filter_financial === 'no') {
    $history_sql .= " AND (d.financial_delivered = 0 OR d.financial_delivered IS NULL)";
}
if ($search) {
    $history_sql .= " AND (d.document_number LIKE ? OR d.title LIKE ?)";
    $history_params[] = "%$search%";
    $history_params[] = "%$search%";
}
if ($selected_year && $selected_month && isset($start_date_greg) && isset($end_date_greg)) {
    $history_sql .= " AND d.created_at BETWEEN ? AND ?";
    $history_params[] = $start_date_greg;
    $history_params[] = $end_date_greg;
}

$history_sql .= " ORDER BY fh.created_at DESC LIMIT 2";

$history_stmt = $pdo->prepare($history_sql);
$history_stmt->execute($history_params);
$recent_history = $history_stmt->fetchAll();

// آمار
$total_invoices = count($invoices);
$completed_invoices = count(array_filter($invoices, fn($i) => $i['status'] == 'final_approved'));
$pending_invoices = count(array_filter($invoices, fn($i) => in_array($i['status'], ['pending_approval', 'partially_approved', 'ready_to_close'])));
$draft_count = count(array_filter($invoices, fn($i) => $i['status'] == 'draft'));
$rejected_count = count(array_filter($invoices, fn($i) => $i['status'] == 'rejected'));
$financial_delivered_count = count(array_filter($invoices, fn($i) => ($i['financial_delivered'] ?? 0) == 1));

$page_title = 'مدیریت فاکتورها';
ob_start();
?>

<style>
    :root {
        --bg-page: #f1f5f9;
        --card-bg: #ffffff;
        --primary: #3b82f6;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --financial: #4caf50;
        --border: #e2e8f0;
        --text-main: #0f172a;
        --text-muted: #64748b;
    }
    
    /* کارت تحویل مالی شده */
    .invoice-card.financial-delivered {
        background: #e8f5e9;
        border-right: 4px solid #4caf50;
    }
    
    .financial-badge {
        background: #4caf50;
        color: white;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 9px;
        font-weight: 500;
        display: inline-block;
        margin-right: 8px;
    }
    
    /* انیمیشن سبد خرید */
    .cart-animation-area {
        display: flex;
        justify-content: center;
        margin-bottom: 15px;
        overflow: visible;
        min-height: 80px;
    }
    .cart-icon {
        font-size: 32px;
        display: inline-block;
        transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.8s ease-out;
        cursor: pointer;
        vertical-align: middle;
    }
    .cart-slide-right {
        transform: translateX(300px);
        opacity: 0;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 15px;
        margin-bottom: 25px;
    }
    .stat-card {
        padding: 15px;
        border-radius: 12px;
        color: white;
        background: linear-gradient(135deg, var(--primary), #2563eb);
    }
    .stat-card.draft-card { background: linear-gradient(135deg, var(--warning), #d97706); }
    .stat-card.rejected-card { background: linear-gradient(135deg, var(--danger), #dc2626); }
    .stat-card.financial-card { background: linear-gradient(135deg, #4caf50, #2e7d32); }
    .stat-card div:first-child { font-size: 13px; opacity: 0.9; }
    .stat-card div:last-child { font-size: 28px; font-weight: bold; }
    
    .filters-container {
        background: white;
        border-radius: 12px;
        padding: 12px 20px;
        margin-bottom: 25px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .search-box { flex: 2; min-width: 180px; position: relative; }
    .search-box input {
        width: 100%;
        padding: 8px 15px 8px 35px;
        border: 1px solid #ddd;
        border-radius: 25px;
        font-size: 13px;
    }
    .search-box i { position: absolute; right: 15px; top: 10px; color: #7f8c8d; font-size: 13px; }
    .status-filter select, .year-month select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 25px;
        background: white;
        font-size: 13px;
    }
    .filter-btn {
        background: #3498db;
        color: white;
        border: none;
        padding: 8px 18px;
        border-radius: 25px;
        cursor: pointer;
        font-size: 13px;
    }
    .filter-btn.reset { background: #95a5a6; }
    
    /* کارت‌ها */
    .cards-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(185px, 1fr));
        gap: 16px;
        max-height: 60vh;
        overflow-y: auto;
        padding: 4px;
        margin-bottom: 24px;
    }
    
    .invoice-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s ease;
        border: 1px solid #eef2f5;
    }
    
    .invoice-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(0,0,0,0.1);
    }
    
    .card-status-bar {
        height: 4px;
        width: 100%;
    }
    .status-bar-draft { background: #95a5a6; }
    .status-bar-pending_approval { background: #f59e0b; }
    .status-bar-partially_approved { background: #3b82f6; }
    .status-bar-ready_to_close { background: #10b981; }
    .status-bar-final_approved { background: #10b981; }
    .status-bar-rejected { background: #ef4444; }
    .status-bar-financial { background: #4caf50; }
    
    .card-content {
        padding: 14px;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .doc-number {
        font-size: 11px;
        font-weight: bold;
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 20px;
        direction: ltr;
        font-family: monospace;
    }
    
    .draft-badge {
        background: #f59e0b;
        color: white;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 10px;
        font-weight: bold;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 6px;
        font-size: 10px;
    }
    
    .info-row.financial-row {
        background: #e8f5e9;
        padding: 4px 8px;
        border-radius: 8px;
        margin-top: 6px;
    }
    
    .info-label {
        color: #7f8c8d;
        font-size: 9px;
    }
    
    .info-value {
        font-weight: 600;
        color: #2c3e50;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        display: block;
        font-size: 10px;
    }
    
    .amount-value {
        color: #27ae60;
        font-size: 11px;
    }
    
    .card-title {
        font-size: 12px;
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 10px;
        text-align: center;
        background: #f8f9fa;
        padding: 5px 8px;
        border-radius: 8px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .approval-progress-mini {
        margin: 10px 0;
        padding: 8px;
        background: #f8fafc;
        border-radius: 12px;
    }
    .progress-mini-bar {
        background: #e2e8f0;
        border-radius: 20px;
        height: 5px;
        overflow: hidden;
        margin-bottom: 6px;
    }
    .progress-mini-fill {
        background: linear-gradient(90deg, #10b981, #34d399);
        height: 100%;
        border-radius: 20px;
        transition: width 0.3s;
    }
    .progress-mini-stats {
        font-size: 10px;
        color: #64748b;
        text-align: center;
    }
    
    .card-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        justify-content: flex-end;
    }
    
    .action-btn {
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 11px;
        text-decoration: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .action-btn.view { background: #e8f4fd; color: #3498db; }
    .action-btn.view:hover { background: #3498db; color: white; }
    .action-btn.edit { background: #fef5e8; color: #f39c12; }
    .action-btn.edit:hover { background: #f39c12; color: white; }
    .action-btn.delete { background: #fee8e8; color: #e74c3c; }
    .action-btn.delete:hover { background: #e74c3c; color: white; }
    .action-btn.finalize { background: #fee8e8; color: #e74c3c; }
    .action-btn.finalize:hover { background: #e74c3c; color: white; }
    .action-btn.approve { background: #d1fae5; color: #059669; }
    .action-btn.approve:hover { background: #059669; color: white; }
    .action-btn.file { background: #f8f9fa; border: 1px solid #e2e8f0; }
    .action-btn.file:hover { background: #e2e8f0; transform: translateY(-1px); }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        background: white;
        border-radius: 16px;
        color: #95a5a6;
        grid-column: 1/-1;
    }
    
    .btn-create {
        background: linear-gradient(135deg, var(--success), #059669);
        color: white;
        padding: 8px 18px;
        border-radius: 8px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: 10px;
        border: none;
        cursor: pointer;
        font-size: 13px;
    }
    
    .back-link-footer {
        background: #95a5a6;
        color: white;
        padding: 8px 18px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
    }
    
    /* ========== تاریخچه ========== */
    .history-top {
        background: white;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        margin-bottom: 24px;
    }
    
    .history-top-title {
        padding: 8px 16px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
    }
    
    .history-top-title i {
        color: #3b82f6;
        font-size: 14px;
    }
    
    .history-top-scroll {
        max-height: 150px;
        overflow-y: auto;
    }
    
    .history-top-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }
    
    .history-top-table th {
        padding: 8px 12px;
        text-align: center;
        background: white;
        color: #64748b;
        font-weight: 600;
        font-size: 10px;
        text-transform: uppercase;
        border-bottom: 1px solid #e2e8f0;
        position: sticky;
        top: 0;
        background: white;
        z-index: 1;
    }
    
    .history-top-table td {
        padding: 8px 12px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    
    .history-top-table tbody tr:hover {
        background: #f8fafc;
    }
    
    .history-top-table th:not(:first-child),
    .history-top-table td:not(:first-child) {
        border-left: 1px solid #e2e8f0;
    }
    
    .history-time {
        font-family: monospace;
        font-size: 10px;
        color: #475569;
        white-space: nowrap;
        text-align: center;
    }
    
    .history-creator {
        font-size: 11px;
        color: #1e293b;
        text-align: center;
    }
    
    .history-doc-num {
        font-family: monospace;
        font-size: 10px;
        color: #475569;
        text-align: center;
        direction: ltr;
    }
    
    .history-status {
        text-align: center;
    }
    
    .history-duration {
        font-family: monospace;
        font-size: 10px;
        color: #475569;
        text-align: center;
        white-space: nowrap;
    }
    
    .status-badge-top {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .status-badge-top.status-approved {
        background: #d1fae5;
        color: #065f46;
    }
    
    .status-badge-top.status-rejected {
        background: #fee2e2;
        color: #b91c1c;
    }
    
    .status-badge-top.status-closed {
        background: #fef3c7;
        color: #b45309;
    }
    
    .status-badge-top.status-pending {
        background: #dbeafe;
        color: #1d4ed8;
    }
    
    .status-badge-top.status-other {
        background: #f1f5f9;
        color: #475569;
    }
    
    .text-center {
        text-align: center;
    }
    
    /* مودال */
    .file-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.85);
        backdrop-filter: blur(5px);
    }
    .file-modal-content {
        position: relative;
        background-color: #fff;
        margin: 3% auto;
        padding: 0;
        width: 90%;
        max-width: 1000px;
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        animation: modalFadeIn 0.3s ease-out;
        overflow: hidden;
    }
    @keyframes modalFadeIn {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    .file-modal-close {
        position: absolute;
        top: 12px;
        right: 20px;
        color: #fff;
        font-size: 32px;
        font-weight: bold;
        cursor: pointer;
        z-index: 10;
        background: rgba(0,0,0,0.5);
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .file-modal-close:hover { background: #e74c3c; transform: rotate(90deg); }
    .file-modal-body {
        padding: 20px;
        background: #f1f5f9;
        min-height: 400px;
        max-height: 85vh;
        overflow-y: auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .file-modal-body img {
        max-width: 100%;
        max-height: 75vh;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .file-modal-body iframe {
        width: 100%;
        height: 75vh;
        border: none;
        border-radius: 8px;
    }
    .file-loading {
        text-align: center;
        padding: 40px;
        color: #64748b;
        font-size: 14px;
    }
    .file-loading:after {
        content: '';
        display: inline-block;
        width: 20px;
        height: 20px;
        margin-right: 10px;
        border: 2px solid #3b82f6;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    @media (max-width: 768px) {
        .cards-list { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); max-height: 50vh; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .filters-container { flex-direction: column; align-items: stretch; }
        .filters-container > * { width: 100%; }
        .cart-icon { font-size: 60px; }
        .cart-slide-right { transform: translateX(200px); opacity: 0; }
        .history-time { font-size: 8px; }
        .history-creator { font-size: 9px; }
        .history-doc-num { font-size: 8px; }
        .history-duration { font-size: 8px; }
        .status-badge-top { font-size: 8px; padding: 2px 6px; }
        .file-modal-content { width: 95%; margin: 10% auto; }
        .file-modal-body { padding: 12px; }
        .file-modal-body img, .file-modal-body iframe { max-height: 60vh; }
    }
    @media (max-width: 480px) {
        .cards-list { grid-template-columns: 1fr; }
        .cart-slide-right { transform: translateX(100px); opacity: 0; }
    }
</style>

<!-- هدر و دکمه فاکتور جدید -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <h1 style="color: #2c3e50; margin: 0; font-size: 22px;">📄 مدیریت فاکتورها</h1>
    <div style="display: flex; align-items: center; gap: 15px;">
        <div style="display: inline-flex; align-items: center; gap: 10px;">
            <div class="cart-icon" id="cartIcon" style="font-size: 28px; display: inline-block; cursor: pointer; line-height: 1;">🛒</div>
            <button type="button" id="createInvoiceBtn" class="btn-create">➕ فاکتور جدید</button>
        </div>
        <a href="dashboard.php" class="back-link-footer">بازگشت</a>
    </div>
</div>

<!-- آمار -->
<div class="stats-grid">
    <div class="stat-card"><div>📋 کل فاکتورها</div><div><?php echo number_format($total_invoices); ?></div></div>
    <div class="stat-card" style="background: linear-gradient(135deg, #2ecc71, #27ae60);"><div>✅ نهایی شده</div><div><?php echo number_format($completed_invoices); ?></div></div>
    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);"><div>⏳ در انتظار</div><div><?php echo number_format($pending_invoices); ?></div></div>
    <div class="stat-card rejected-card"><div>❌ رد شده</div><div><?php echo number_format($rejected_count); ?></div></div>
    <div class="stat-card draft-card"><div>📝 پیش‌نویس</div><div><?php echo number_format($draft_count); ?></div></div>
    <div class="stat-card financial-card"><div>🚚 تحویل مالی</div><div><?php echo number_format($financial_delivered_count); ?></div></div>
</div>

<!-- ========== تاریخچه ========== -->
<div class="history-top">
    <div class="history-top-title">
        <i class="fas fa-history"></i>
        <span>آخرین فعالیت‌ها</span>
    </div>
    <div class="history-top-scroll">
        <table class="history-top-table">
            <thead>
                <tr>
                    <th>زمان</th>
                    <th>ایجادکننده</th>
                    <th>شماره فاکتور</th>
                    <th>وضعیت</th>
                    <th>مدت پیگیری</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_history)): ?>
                    <tr>
                        <td colspan="5" class="text-center">هیچ فعالیتی یافت نشد</td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $history_count = 0;
                    foreach ($recent_history as $h): 
                        if ($history_count >= 2) break;
                        $history_count++;
                        $full_date = jdate('Y/m-d H:i', strtotime($h['created_at']));
                        
                        if (isset($h['doc_created_at']) && !empty($h['doc_created_at'])) {
                            $doc_created_at = $h['doc_created_at'];
                        } else {
                            $doc_stmt = $pdo->prepare("SELECT created_at FROM documents WHERE id = ?");
                            $doc_stmt->execute([$h['document_id']]);
                            $doc_created_at = $doc_stmt->fetchColumn();
                        }
                        
                        $start_time = strtotime($doc_created_at);
                        $end_time = strtotime($h['created_at']);
                        $diff_seconds = $end_time - $start_time;
                        
                        if ($diff_seconds < 0) {
                            $duration = 'همین الان';
                        } elseif ($diff_seconds < 60) {
                            $duration = 'کمتر از یک دقیقه';
                        } elseif ($diff_seconds < 3600) {
                            $minutes = floor($diff_seconds / 60);
                            $duration = $minutes . ' دقیقه';
                        } elseif ($diff_seconds < 86400) {
                            $hours = floor($diff_seconds / 3600);
                            $remain_minutes = floor(($diff_seconds % 3600) / 60);
                            $duration = $hours . ' ساعت';
                            if ($remain_minutes > 0) {
                                $duration .= ' و ' . $remain_minutes . ' دقیقه';
                            }
                        } else {
                            $days = floor($diff_seconds / 86400);
                            $duration = $days . ' روز';
                        }
                        
                        $status_class = '';
                        $status_text = '';
                        $status_icon = '';
                        
                        if ($h['action'] == 'approve') {
                            $status_class = 'status-approved';
                            $status_text = 'تأیید شده';
                            $status_icon = '✅';
                        } elseif ($h['action'] == 'reject') {
                            $status_class = 'status-rejected';
                            $status_text = 'رد شده';
                            $status_icon = '❌';
                        } elseif ($h['action'] == 'final_approve') {
                            $status_class = 'status-closed';
                            $status_text = 'بسته شده';
                            $status_icon = '🔒';
                        } elseif ($h['action'] == 'financial_deliver') {
                            $status_class = 'status-closed';
                            $status_text = 'تحویل مالی';
                            $status_icon = '🚚';
                        } elseif ($h['action'] == 'forward') {
                            $status_class = 'status-pending';
                            $status_text = 'در جریان';
                            $status_icon = '🔄';
                        } else {
                            $status_class = 'status-other';
                            $status_text = 'ثبت شد';
                            $status_icon = '📌';
                        }
                    ?>
                    <tr>
                        <td class="history-time"><?php echo $full_date; ?></td>
                        <td class="history-creator"><?php echo htmlspecialchars($h['from_name']); ?></td>
                        <td class="history-doc-num"><?php echo htmlspecialchars($h['document_number']); ?></td>
                        <td class="history-status">
                            <span class="status-badge-top <?php echo $status_class; ?>">
                                <?php echo $status_icon . ' ' . $status_text; ?>
                            </span>
                        </td>
                        <td class="history-duration"><?php echo $duration; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- فیلترها -->
<div class="filters-container">
    <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchInput" placeholder="   جستجو..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="year-month" style="display: flex; gap: 8px;">
        <select id="yearSelect">
            <?php foreach ($years as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo $selected_year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endforeach; ?>
        </select>
        <select id="monthSelect">
            <?php foreach ($months as $num => $name): ?>
                <option value="<?php echo $num; ?>" <?php echo $selected_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="status-filter">
        <select id="statusFilter">
            <option value="">همه وضعیت‌ها</option>
            <option value="draft" <?php echo $filter_status == 'draft' ? 'selected' : ''; ?>>پیش‌نویس</option>
            <option value="pending_approval" <?php echo $filter_status == 'pending_approval' ? 'selected' : ''; ?>>در انتظار تأیید</option>
            <option value="partially_approved" <?php echo $filter_status == 'partially_approved' ? 'selected' : ''; ?>>تأیید نسبی</option>
            <option value="ready_to_close" <?php echo $filter_status == 'ready_to_close' ? 'selected' : ''; ?>>آماده تأیید نهایی</option>
            <option value="final_approved" <?php echo $filter_status == 'final_approved' ? 'selected' : ''; ?>>نهایی شده</option>
            <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>رد شده</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="financialFilter">
            <option value="">همه</option>
            <option value="yes" <?php echo $filter_financial === 'yes' ? 'selected' : ''; ?>>🚚 تحویل مالی شده</option>
            <option value="no" <?php echo $filter_financial === 'no' ? 'selected' : ''; ?>>❌ تحویل مالی نشده</option>
        </select>
    </div>
    <div class="status-filter">
        <select id="companyFilter">
            <option value="">همه شرکت‌ها</option>
            <?php foreach ($companies as $comp): ?>
                <option value="<?php echo $comp['id']; ?>" <?php echo $filter_company == $comp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($comp['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="filter-btn" id="applyFilterBtn">اعمال</button>
    <a href="inbox.php" class="filter-btn reset">پاک کردن</a>
</div>

<!-- کارت‌های فاکتور -->
<div class="cards-list">
    <?php if (empty($invoices)): ?>
        <div class="empty-state"><i class="fas fa-file-invoice" style="font-size: 40px;"></i><p>هیچ فاکتوری یافت نشد</p></div>
    <?php else: ?>
        <?php foreach ($invoices as $inv): 
            $isDraft = ($inv['status'] == 'draft');
            $isFinancialDelivered = ($inv['financial_delivered'] ?? 0) == 1;
            $shamsi_date = jdate('Y/m/d', strtotime($inv['created_at']));
            $total_approvers = $inv['total_approvers'] ?? 0;
            $approved_count = $inv['approved_count'] ?? 0;
            $progress_percent = $total_approvers > 0 ? round(($approved_count / $total_approvers) * 100) : 0;
            
            $needs_approval = false;
            if (!$isDraft && !in_array($inv['status'], ['final_approved', 'rejected']) && !$isFinancialDelivered) {
                $check_stmt = $pdo->prepare("SELECT status FROM document_approvals WHERE document_id = ? AND user_id = ?");
                $check_stmt->execute([$inv['id'], $user_id]);
                $approval_status = $check_stmt->fetchColumn();
                $needs_approval = in_array($approval_status, ['pending', 'viewed']);
            }
        ?>
        <div class="invoice-card <?php echo $isFinancialDelivered ? 'financial-delivered' : ''; ?>">
            <div class="card-status-bar status-bar-<?php echo $isFinancialDelivered ? 'financial' : $inv['status']; ?>"></div>
            <div class="card-content">
                <div class="card-header">
                    <span class="doc-number"><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($inv['document_number']); ?></span>
                    <?php if ($isDraft): ?>
                        <span class="draft-badge"><i class="fas fa-pen-fancy"></i> پیش‌نویس</span>
                    <?php endif; ?>
                    <?php if ($isFinancialDelivered): ?>
                        <span class="financial-badge">🚚 تحویل مالی</span>
                    <?php endif; ?>
                </div>
                <div class="card-title" title="<?php echo htmlspecialchars($inv['title']); ?>">
                    <?php echo htmlspecialchars(mb_substr($inv['title'], 0, 35)) . (mb_strlen($inv['title']) > 35 ? '...' : ''); ?>
                </div>
                <div class="info-row">
                    <span class="info-label">💰 مبلغ</span>
                    <span class="info-value amount-value"><?php echo number_format($inv['amount'] ?? 0); ?> تومان</span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏢 شرکت</span>
                    <span class="info-value"><?php echo htmlspecialchars($inv['company_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">🏪 فروشنده</span>
                    <span class="info-value"><?php echo htmlspecialchars($inv['vendor_name'] ?? '-'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📅 تاریخ</span>
                    <span class="info-value"><?php echo $shamsi_date; ?></span>
                </div>
                
                <?php if ($isFinancialDelivered && $inv['financial_delivered_at']): ?>
                <div class="info-row financial-row">
                    <span class="info-label">🚚 تاریخ تحویل به مالی</span>
                    <span class="info-value" style="color: #2e7d32; font-weight: bold;"><?php echo jdate('Y/m-d', strtotime($inv['financial_delivered_at'])); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($total_approvers > 0 && !in_array($inv['status'], ['final_approved', 'rejected', 'draft']) && !$isFinancialDelivered): ?>
                <div class="approval-progress-mini">
                    <div class="progress-mini-bar">
                        <div class="progress-mini-fill" style="width: <?php echo $progress_percent; ?>%;"></div>
                    </div>
                    <div class="progress-mini-stats">
                        <?php echo $approved_count; ?> از <?php echo $total_approvers; ?> نفر تأیید کردند
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="card-actions">
                    <?php if ($needs_approval && !$isFinancialDelivered): ?>
                        <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="action-btn approve" title="تأیید فاکتور"><i class="fas fa-check-circle"></i> تأیید</a>
                    <?php else: ?>
                        <a href="invoice-view.php?id=<?php echo $inv['id']; ?>" class="action-btn view" title="مشاهده جزئیات"><i class="fas fa-eye"></i> مشاهده</a>
                    <?php endif; ?>
                    
                    <?php if (!empty($inv['file_path'])):
                        $file_ext = strtolower(pathinfo($inv['file_path'], PATHINFO_EXTENSION));
                        $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        $is_pdf = ($file_ext == 'pdf');
                        $file_icon = ($is_image) ? 'fa-image' : (($is_pdf) ? 'fa-file-pdf' : 'fa-file-alt');
                        $file_color = ($is_image) ? '#27ae60' : (($is_pdf) ? '#e74c3c' : '#3498db');
                    ?>
                        <button type="button" class="action-btn file" 
                                data-file="<?php echo htmlspecialchars($inv['file_path']); ?>" 
                                data-filename="<?php echo htmlspecialchars($inv['file_name'] ?? basename($inv['file_path'])); ?>"
                                data-type="<?php echo $is_pdf ? 'pdf' : ($is_image ? 'image' : 'other'); ?>"
                                style="color: <?php echo $file_color; ?>; background: <?php echo $file_color; ?>10;"
                                onclick="openFileModal(this)" 
                                title="مشاهده فایل">
                            <i class="fas <?php echo $file_icon; ?>"></i>
                        </button>
                    <?php endif; ?>
                    
                    <?php if (($inv['created_by'] == $user_id || $is_admin_user) && !$isFinancialDelivered): ?>
                        <?php if ($isDraft): ?>
                            <a href="invoice-edit.php?id=<?php echo $inv['id']; ?>" class="action-btn finalize" title="تکمیل"><i class="fas fa-check-circle"></i> تکمیل</a>
                        <?php elseif (!in_array($inv['status'], ['final_approved', 'rejected'])): ?>
                            <a href="invoice-edit.php?id=<?php echo $inv['id']; ?>" class="action-btn edit" title="ویرایش"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <a href="invoice-delete.php?id=<?php echo $inv['id']; ?>" class="action-btn delete" title="حذف" onclick="return confirm('حذف شود؟')"><i class="fas fa-trash-alt"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function animateCartAndRedirect() {
    var cartIcon = document.getElementById('cartIcon');
    if (cartIcon) {
        if (cartIcon.classList.contains('cart-slide-right')) return;
        cartIcon.classList.add('cart-slide-right');
        setTimeout(function() { window.location.href = 'invoice-create.php'; }, 800);
    } else {
        window.location.href = 'invoice-create.php';
    }
}

document.getElementById('createInvoiceBtn')?.addEventListener('click', function(e) {
    e.preventDefault();
    animateCartAndRedirect();
});

function applyFilters() {
    let search = document.getElementById('searchInput').value;
    let status = document.getElementById('statusFilter').value;
    let financial = document.getElementById('financialFilter').value;
    let company = document.getElementById('companyFilter').value;
    let year = document.getElementById('yearSelect').value;
    let month = document.getElementById('monthSelect').value;
    let url = 'inbox.php?';
    if (search) url += 'search=' + encodeURIComponent(search);
    if (status) url += (search ? '&' : '') + 'status=' + encodeURIComponent(status);
    if (financial) url += (search || status ? '&' : '') + 'financial_delivered=' + encodeURIComponent(financial);
    if (company) url += (search || status || financial ? '&' : '') + 'company=' + encodeURIComponent(company);
    if (year) url += (search || status || financial || company ? '&' : '') + 'year=' + encodeURIComponent(year);
    if (month) url += (search || status || financial || company || year ? '&' : '') + 'month=' + encodeURIComponent(month);
    window.location.href = url;
}

document.getElementById('applyFilterBtn').addEventListener('click', applyFilters);
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') applyFilters();
});

function openFileModal(button) {
    const filePath = button.getAttribute('data-file');
    const fileName = button.getAttribute('data-filename') || 'فایل';
    const fileType = button.getAttribute('data-type');
    const modal = document.getElementById('fileModal');
    const modalBody = document.getElementById('fileModalBody');
    
    if (!filePath) {
        modalBody.innerHTML = '<div class="file-loading">فایل یافت نشد</div>';
        modal.style.display = 'block';
        return;
    }
    
    const fileUrl = '/invoice-system-v2/' + filePath;
    
    if (fileType === 'pdf') {
        window.open(fileUrl, '_blank');
        return;
    }
    
    if (fileType === 'image') {
        modalBody.innerHTML = '<div class="file-loading">در حال بارگذاری...</div>';
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        const img = new Image();
        img.onload = function() {
            modalBody.innerHTML = `<img src="${fileUrl}" alt="${fileName}">`;
        };
        img.onerror = function() {
            modalBody.innerHTML = `<div style="text-align: center; padding: 40px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #e74c3c; margin-bottom: 16px; display: block;"></i>
                <p>خطا در بارگذاری تصویر</p>
                <a href="${fileUrl}" download class="action-btn view" style="display: inline-flex; margin-top: 16px;">
                    <i class="fas fa-download"></i> دانلود فایل
                </a>
            </div>`;
        };
        img.src = fileUrl;
    } else {
        modalBody.innerHTML = `<div style="text-align: center; padding: 40px;">
            <i class="fas fa-file-alt" style="font-size: 48px; color: #64748b; margin-bottom: 16px; display: block;"></i>
            <p>پیش‌نمایش برای این نوع فایل امکان‌پذیر نیست</p>
            <a href="${fileUrl}" download class="action-btn view" style="display: inline-flex; margin-top: 16px;">
                <i class="fas fa-download"></i> دانلود فایل
            </a>
        </div>`;
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeFileModal(event) {
    const modal = document.getElementById('fileModal');
    if (event && event.target !== modal && !event.target.classList.contains('file-modal-close')) {
        return;
    }
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    document.getElementById('fileModalBody').innerHTML = '<div class="file-loading">در حال بارگذاری...</div>';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeFileModal();
});
</script>

<!-- مودال -->
<div id="fileModal" class="file-modal" onclick="closeFileModal(event)">
    <div class="file-modal-content" onclick="event.stopPropagation()">
        <span class="file-modal-close" onclick="closeFileModal()">&times;</span>
        <div id="fileModalBody" class="file-modal-body">
            <div class="file-loading">در حال بارگذاری...</div>
        </div>
    </div>
</div>

<script>
// ========== به‌روزرسانی شمارنده منو ==========
function updateBadges() {
    fetch('/invoice-system-v2/ajax/update_counter.php')
        .then(response => response.json())
        .then(data => {
            console.log('شمارنده:', data);
            
            const invoiceBadge = document.getElementById('invoiceBadge');
            if (invoiceBadge) {
                const count = data.invoice_count || 0;
                invoiceBadge.textContent = count;
                invoiceBadge.style.display = count > 0 ? 'inline-block' : 'none';
            }
            
            const waybillBadge = document.getElementById('waybillBadge');
            if (waybillBadge) {
                const count = data.waybill_count || 0;
                waybillBadge.textContent = count;
                waybillBadge.style.display = count > 0 ? 'inline-block' : 'none';
            }
            
            const taxBadge = document.getElementById('taxBadge');
            if (taxBadge) {
                const count = data.tax_count || 0;
                taxBadge.textContent = count;
                taxBadge.style.display = count > 0 ? 'inline-block' : 'none';
            }
        })
        .catch(error => console.error('خطا در شمارنده:', error));
}

// ========== به‌روزرسانی لیست کارت‌ها (بدون رفرش) ==========
let lastCardsHtml = '';

function refreshCards() {
    fetch(window.location.href)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newCards = doc.querySelector('.cards-list')?.innerHTML;
            const cardsContainer = document.querySelector('.cards-list');
            
            if (newCards && cardsContainer && cardsContainer.innerHTML !== newCards) {
                const scrollPos = cardsContainer.scrollTop;
                cardsContainer.innerHTML = newCards;
                cardsContainer.scrollTop = scrollPos;
                console.log('کارت‌ها به‌روزرسانی شدند');
            }
        })
        .catch(error => console.error('خطا در به‌روزرسانی کارت‌ها:', error));
}

function refreshData() {
    updateBadges();
    refreshCards();
}

setInterval(refreshData, 5000);
refreshData();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layouts/dashboard.php';
?>