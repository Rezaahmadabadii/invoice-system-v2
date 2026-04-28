<?php
use App\Core\Session;

$title = 'افزودن مشتری جدید';
ob_start();
?>

<div class="page-header">
    <h1>افزودن مشتری جدید</h1>
    <a href="/customers" class="btn btn-secondary">
        <i class="fas fa-arrow-right"></i>
        بازگشت به لیست
    </a>
</div>

<?php if (Session::has('flash_error')): ?>
    <div class="alert alert-error">
        <?php echo Session::getFlash('error'); ?>
    </div>
<?php endif; ?>

<div class="form-container">
    <form method="POST" action="/customers/store" class="customer-form">
        <div class="form-section">
            <h2>اطلاعات مشتری</h2>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="code">کد مشتری</label>
                    <input type="text" id="code" name="code" value="<?php echo $code; ?>" readonly class="readonly">
                    <small>کد مشتری به صورت خودکار تولید می‌شود</small>
                </div>

                <div class="form-group">
                    <label for="name">نام مشتری <span class="required">*</span></label>
                    <input type="text" id="name" name="name" required 
                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                           placeholder="نام شرکت یا شخص">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">تلفن</label>
                    <input type="text" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                           placeholder="۰۲۱۱۲۳۴۵۶۷۸">
                </div>

                <div class="form-group">
                    <label for="email">ایمیل</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           placeholder="info@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="address">آدرس</label>
                <textarea id="address" name="address" rows="3" 
                          placeholder="آدرس کامل"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="economic_code">کد اقتصادی</label>
                    <input type="text" id="economic_code" name="economic_code" 
                           value="<?php echo htmlspecialchars($_POST['economic_code'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="national_id">شناسه ملی</label>
                    <input type="text" id="national_id" name="national_id" 
                           value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i>
                ذخیره مشتری
            </button>
            <a href="/customers" class="btn btn-secondary">انصراف</a>
        </div>
    </form>
</div>

<style>
.form-container {
    max-width: 800px;
    margin: 0 auto;
}

.form-section {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.form-section h2 {
    font-size: 18px;
    color: var(--text-primary);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 15px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-secondary);
    font-weight: 500;
}

.form-group .required {
    color: var(--danger);
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: inherit;
    font-size: 14px;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,122,255,0.1);
}

.form-group input.readonly {
    background: var(--glass-bg);
    cursor: not-allowed;
}

.form-group small {
    display: block;
    margin-top: 5px;
    color: var(--text-secondary);
    font-size: 12px;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>