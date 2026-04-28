<?php
namespace App\Models;

use App\Core\Database;

class ApprovalStep
{
    public $id;
    public $chain_id;
    public $step_order;
    public $name;
    public $approver_role;
    public $approver_id;
    public $required_notes;
    public $created_at;

    /**
     * پیدا کردن مرحله با ID
     */
    public static function find($id)
    {
        $data = Database::fetch("SELECT * FROM approval_steps WHERE id = ?", [$id]);
        if ($data) {
            return self::hydrate($data);
        }
        return null;
    }

    /**
     * دریافت مراحل یک زنجیره
     */
    public static function getByChain($chainId)
    {
        $data = Database::fetchAll(
            "SELECT * FROM approval_steps WHERE chain_id = ? ORDER BY step_order",
            [$chainId]
        );
        $steps = [];
        foreach ($data as $row) {
            $steps[] = self::hydrate($row);
        }
        return $steps;
    }

    /**
     * دریافت اطلاعات تأییدکننده
     */
    public function approver()
    {
        if ($this->approver_id) {
            return Database::fetch("SELECT * FROM users WHERE id = ?", [$this->approver_id]);
        }
        return null;
    }

    /**
     * تبدیل آرایه به آبجکت
     */
    protected static function hydrate($data)
    {
        $step = new self();
        foreach ($data as $key => $value) {
            $step->$key = $value;
        }
        return $step;
    }
}