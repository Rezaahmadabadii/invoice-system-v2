<?php
namespace App\Controllers;

use App\Core\Session;
use App\Core\Database;
use App\Models\InvoiceApproval;
use App\Models\ApprovalChain;

class ApprovalController
{
    public function __construct()
    {
        if (!Session::has('user_id')) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * نمایش لیست فاکتورهای در انتظار تأیید
     */
    public function pending()
    {
        $userId = Session::get('user_id');
        $userRole = Session::get('user_role');

        // فاکتورهایی که کاربر می‌تواند تأیید کند
        $invoices = Database::fetchAll(
            "SELECT i.*, c.name as customer_name,
                    ia.id as approval_id, ia.step_id, ia.status as approval_status,
                    s.name as step_name
             FROM invoice_approvals ia
             JOIN invoices i ON ia.invoice_id = i.id
             JOIN approval_steps s ON ia.step_id = s.id
             LEFT JOIN customers c ON i.customer_id = c.id
             WHERE ia.approver_id = ? AND ia.status = 'pending'
             ORDER BY i.created_at DESC",
            [$userId]
        );

        require_once app_path('Views/approvals/pending.php');
    }

    /**
     * نمایش تاریخچه تأییدها
     */
    public function history()
    {
        $userId = Session::get('user_id');

        $approvals = Database::fetchAll(
            "SELECT ia.*, i.invoice_number, i.title as invoice_title,
                    u.full_name as approver_name, s.name as step_name
             FROM invoice_approvals ia
             JOIN invoices i ON ia.invoice_id = i.id
             JOIN approval_steps s ON ia.step_id = s.id
             JOIN users u ON ia.approver_id = u.id
             WHERE ia.approver_id = ? AND ia.status != 'pending'
             ORDER BY ia.updated_at DESC
             LIMIT 50",
            [$userId]
        );

        require_once app_path('Views/approvals/history.php');
    }

    /**
     * نمایش جزئیات یک فاکتور برای تأیید
     */
    public function show($id)
    {
        $approval = Database::fetch(
            "SELECT ia.*, i.*, c.name as customer_name, s.name as step_name
             FROM invoice_approvals ia
             JOIN invoices i ON ia.invoice_id = i.id
             JOIN approval_steps s ON ia.step_id = s.id
             LEFT JOIN customers c ON i.customer_id = c.id
             WHERE ia.id = ?",
            [$id]
        );

        if (!$approval) {
            Session::setFlash('error', 'مورد تأیید یافت نشد');
            header('Location: /approvals/pending');
            return;
        }

        // بررسی دسترسی
        if ($approval['approver_id'] != Session::get('user_id')) {
            http_response_code(403);
            require_once app_path('Views/errors/403.php');
            return;
        }

        // دریافت آیتم‌های فاکتور
        $items = Database::fetchAll(
            "SELECT * FROM invoice_items WHERE invoice_id = ?",
            [$approval['invoice_id']]
        );

        require_once app_path('Views/approvals/show.php');
    }

    /**
     * تأیید یک فاکتور
     */
    public function approve($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /approvals/pending');
            return;
        }

        $approval = Database::fetch(
            "SELECT * FROM invoice_approvals WHERE id = ?",
            [$id]
        );

        if (!$approval) {
            Session::setFlash('error', 'مورد تأیید یافت نشد');
            header('Location: /approvals/pending');
            return;
        }

        if ($approval['approver_id'] != Session::get('user_id')) {
            http_response_code(403);
            require_once app_path('Views/errors/403.php');
            return;
        }

        if ($approval['status'] !== 'pending') {
            Session::setFlash('error', 'این فاکتور قبلاً بررسی شده است');
            header('Location: /approvals/pending');
            return;
        }

        $notes = $_POST['notes'] ?? '';

        // تأیید مرحله جاری
        Database::update('invoice_approvals',
            [
                'status' => 'approved',
                'notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$id]
        );

        // بررسی مراحل بعدی
        $nextStep = Database::fetch(
            "SELECT * FROM approval_steps 
             WHERE chain_id = (SELECT chain_id FROM approval_steps WHERE id = ?)
             AND step_order > (SELECT step_order FROM approval_steps WHERE id = ?)
             ORDER BY step_order LIMIT 1",
            [$approval['step_id'], $approval['step_id']]
        );

        if (!$nextStep) {
            // همه مراحل تأیید شدند
            Database::update('invoices',
                ['status' => 'approved', 'approved_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$approval['invoice_id']]
            );
            
            Session::setFlash('success', 'فاکتور با موفقیت تأیید شد و به مرحله نهایی رسید');
        } else {
            // مرحله بعدی فعال می‌شود
            Database::insert('invoice_approvals', [
                'invoice_id' => $approval['invoice_id'],
                'step_id' => $nextStep['id'],
                'approver_id' => $nextStep['approver_id'],
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            Session::setFlash('success', 'فاکتور تأیید شد و به مرحله بعد ارسال گردید');
        }

        // ثبت فعالیت
        $this->logActivity(Session::get('user_id'), 'approve', "تأیید فاکتور #{$approval['invoice_id']}");

        header('Location: /approvals/pending');
    }

    /**
     * رد یک فاکتور
     */
    public function reject($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /approvals/pending');
            return;
        }

        $approval = Database::fetch(
            "SELECT * FROM invoice_approvals WHERE id = ?",
            [$id]
        );

        if (!$approval) {
            Session::setFlash('error', 'مورد تأیید یافت نشد');
            header('Location: /approvals/pending');
            return;
        }

        if ($approval['approver_id'] != Session::get('user_id')) {
            http_response_code(403);
            require_once app_path('Views/errors/403.php');
            return;
        }

        if ($approval['status'] !== 'pending') {
            Session::setFlash('error', 'این فاکتور قبلاً بررسی شده است');
            header('Location: /approvals/pending');
            return;
        }

        $notes = $_POST['notes'] ?? '';
        if (empty($notes)) {
            Session::setFlash('error', 'وارد کردن دلیل رد الزامی است');
            header('Location: '/approvals/show/' . $id);
            return;
        }

        // رد مرحله جاری
        Database::update('invoice_approvals',
            [
                'status' => 'rejected',
                'notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$id]
        );

        // به‌روزرسانی وضعیت فاکتور
        Database::update('invoices',
            ['status' => 'rejected'],
            'id = ?',
            [$approval['invoice_id']]
        );

        // رد سایر مراحل در انتظار
        Database::update('invoice_approvals',
            ['status' => 'skipped'],
            'invoice_id = ? AND status = "pending"',
            [$approval['invoice_id']]
        );

        // ثبت فعالیت
        $this->logActivity(Session::get('user_id'), 'reject', "رد فاکتور #{$approval['invoice_id']}");

        Session::setFlash('success', 'فاکتور با موفقیت رد شد');
        header('Location: /approvals/pending');
    }

    /**
     * مدیریت زنجیره‌های تأیید (برای ادمین)
     */
    public function chains()
    {
        if (Session::get('user_role') !== 'admin') {
            http_response_code(403);
            require_once app_path('Views/errors/403.php');
            return;
        }

        $chains = ApprovalChain::all();
        require_once app_path('Views/approvals/chains.php');
    }

    /**
     * ایجاد زنجیره جدید
     */
    public function storeChain()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Session::get('user_role') !== 'admin') {
            header('Location: /approvals/chains');
            return;
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'created_by' => Session::get('user_id'),
            'created_at' => date('Y-m-d H:i:s')
        ];

        if (empty($data['name'])) {
            Session::setFlash('error', 'نام زنجیره الزامی است');
            header('Location: /approvals/chains');
            return;
        }

        // اگر پیش‌فرض است، بقیه را غیرپیش‌فرض کن
        if ($data['is_default']) {
            Database::update('approval_chains', ['is_default' => 0], 'is_default = 1', []);
        }

        $chainId = Database::insert('approval_chains', $data);

        // ذخیره مراحل
        if (isset($_POST['steps']) && is_array($_POST['steps'])) {
            foreach ($_POST['steps'] as $order => $step) {
                if (!empty($step['name']) && !empty($step['approver_role'])) {
                    Database::insert('approval_steps', [
                        'chain_id' => $chainId,
                        'step_order' => $order + 1,
                        'name' => $step['name'],
                        'approver_role' => $step['approver_role'],
                        'approver_id' => $step['approver_id'] ?? null,
                        'required_notes' => isset($step['required_notes']) ? 1 : 0
                    ]);
                }
            }
        }

        Session::setFlash('success', 'زنجیره تأیید با موفقیت ایجاد شد');
        header('Location: /approvals/chains');
    }

    /**
     * ثبت فعالیت
     */
    private function logActivity($userId, $action, $description)
    {
        try {
            Database::insert('activities', [
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // خطا را نادیده بگیر
        }
    }
}