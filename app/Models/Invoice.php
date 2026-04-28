<?php
namespace App\Models;

use App\Core\Database;

class Invoice
{
    public $id;
    public $invoice_number;
    public $customer_id;
    public $title;
    public $description;
    public $amount;
    public $tax;
    public $discount;
    public $total;
    public $status;
    public $created_by;
    public $created_at;
    public $updated_at;

    /**
     * پیدا کردن فاکتور با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM invoices WHERE id = ?", [$id]);
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت همه فاکتورها با صفحه‌بندی
     */
    public static function paginate($page = 1, $perPage = 10, $conditions = [])
    {
        $offset = ($page - 1) * $perPage;
        $where = '';
        $params = [];

        if (!empty($conditions)) {
            $wheres = [];
            foreach ($conditions as $key => $value) {
                $wheres[] = "$key = ?";
                $params[] = $value;
            }
            $where = 'WHERE ' . implode(' AND ', $wheres);
        }

        $params[] = $perPage;
        $params[] = $offset;

        $data = Database::fetchAll(
            "SELECT i.*, c.name as customer_name 
             FROM invoices i 
             LEFT JOIN customers c ON i.customer_id = c.id 
             $where 
             ORDER BY i.created_at DESC 
             LIMIT ? OFFSET ?",
            $params
        );

        $invoices = [];
        foreach ($data as $row) {
            $invoices[] = self::hydrate($row);
        }

        return $invoices;
    }

    /**
     * تبدیل آرایه به آبجکت
     */
    protected static function hydrate($data)
    {
        $invoice = new self();
        foreach ($data as $key => $value) {
            $invoice->$key = $value;
        }
        return $invoice;
    }

    /**
     * دریافت آیتم‌های فاکتور
     */
    public function items()
    {
        return Database::fetchAll(
            "SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id",
            [$this->id]
        );
    }

    /**
     * دریافت مشتری
     */
    public function customer()
    {
        return Database::fetch(
            "SELECT * FROM customers WHERE id = ?",
            [$this->customer_id]
        );
    }

    /**
     * دریافت مراحل تأیید
     */
    public function approvals()
    {
        return Database::fetchAll(
            "SELECT a.*, u.full_name as approver_name, u.role as approver_role,
                    s.name as step_name
             FROM invoice_approvals a
             LEFT JOIN users u ON a.approver_id = u.id
             LEFT JOIN approval_steps s ON a.step_id = s.id
             WHERE a.invoice_id = ?
             ORDER BY a.created_at",
            [$this->id]
        );
    }

    /**
     * بررسی قابل ویرایش بودن
     */
    public function isEditable()
    {
        return $this->status === 'draft';
    }

    /**
     * بررسی قابل حذف بودن
     */
    public function isDeletable()
    {
        return $this->status === 'draft';
    }

    /**
     * دریافت وضعیت فارسی
     */
    public function getStatusText()
    {
        $statuses = [
            'draft' => 'پیش‌نویس',
            'pending' => 'در انتظار تأیید',
            'under_review' => 'در حال بررسی',
            'approved' => 'تأیید شده',
            'rejected' => 'رد شده',
            'paid' => 'پرداخت شده',
            'overdue' => 'سررسید گذشته',
            'cancelled' => 'لغو شده'
        ];
        return $statuses[$this->status] ?? $this->status;
    }

    /**
     * دریافت کلاس وضعیت برای CSS
     */
    public function getStatusClass()
    {
        $classes = [
            'draft' => 'secondary',
            'pending' => 'warning',
            'under_review' => 'info',
            'approved' => 'success',
            'rejected' => 'danger',
            'paid' => 'success',
            'overdue' => 'danger',
            'cancelled' => 'secondary'
        ];
        return $classes[$this->status] ?? 'secondary';
    }
}