<?php
namespace App\Models;

use App\Core\Database;

class InvoiceApproval
{
    public $id;
    public $invoice_id;
    public $step_id;
    public $approver_id;
    public $status;
    public $notes;
    public $created_at;
    public $updated_at;

    /**
     * پیدا کردن تأیید با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM invoice_approvals WHERE id = ?", [$id]);
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت تأییدهای یک فاکتور
     */
    public static function getByInvoice($invoiceId)
    {
        $data = Database::fetchAll(
            "SELECT ia.*, u.full_name as approver_name, u.role as approver_role,
                    s.name as step_name, s.step_order
             FROM invoice_approvals ia
             LEFT JOIN users u ON ia.approver_id = u.id
             LEFT JOIN approval_steps s ON ia.step_id = s.id
             WHERE ia.invoice_id = ?
             ORDER BY s.step_order",
            [$invoiceId]
        );
        return $data;
    }

    /**
     * تأیید یک مرحله
     */
    public static function approve($id, $notes = '')
    {
        Database::update('invoice_approvals', 
            [
                'status' => 'approved',
                'notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$id]
        );
    }

    /**
     * رد یک مرحله
     */
    public static function reject($id, $notes)
    {
        Database::update('invoice_approvals', 
            [
                'status' => 'rejected',
                'notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$id]
        );
    }

    /**
     * بررسی وضعیت نهایی فاکتور
     */
    public static function checkFinalStatus($invoiceId)
    {
        $approvals = self::getByInvoice($invoiceId);
        
        $allApproved = true;
        $hasRejected = false;
        
        foreach ($approvals as $approval) {
            if ($approval['status'] === 'rejected') {
                $hasRejected = true;
                break;
            }
            if ($approval['status'] !== 'approved') {
                $allApproved = false;
            }
        }
        
        if ($hasRejected) {
            return 'rejected';
        } elseif ($allApproved) {
            return 'approved';
        } else {
            return 'pending';
        }
    }

    /**
     * تبدیل آرایه به آبجکت
     */
    protected static function hydrate($data)
    {
        $approval = new self();
        foreach ($data as $key => $value) {
            $approval->$key = $value;
        }
        return $approval;
    }
}