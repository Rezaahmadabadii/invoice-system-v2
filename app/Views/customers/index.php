<?php
use App\Core\Session;

$title = 'مدیریت مشتریان';
ob_start();
?>

<div class="page-header">
    <h1>مدیریت مشتریان</h1>
    <div class="header-actions">
        <a href="/customers/create" class="btn btn-primary">
            <i class="fas fa-plus"></i>
            مشتری جدید
        </a>
    </div>
</div>

<?php if (Session::has('flash_success')): ?>
    <div class="alert alert-success">
        <?php echo Session::getFlash('success'); ?>
    </div>
<?php endif; ?>

<?php if (Session::has('flash_error')): ?>
    <div class="alert alert-error">
        <?php echo Session::getFlash('error'); ?>
    </div>
<?php endif; ?>

<!-- جستجو -->
<div class="filters-card">
    <form method="GET" action="/customers" class="filters-form">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="جستجو بر اساس نام، تلفن، ایمیل یا کد..." 
                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        </div>
        <button type="submit" class="btn btn-secondary">جستجو</button>
        <?php if (!empty($_GET['search'])): ?>
            <a href="/customers" class="btn btn-secondary">پاک کردن فیلتر</a>
        <?php endif; ?>
    </form>
</div>

<!-- لیست مشتریان -->
<div class="customers-grid">
    <?php if (empty($customers)): ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h3>هیچ مشتری یافت نشد</h3>
            <p>اولین مشتری را ایجاد کنید</p>
            <a href="/customers/create" class="btn btn-primary">ایجاد مشتری جدید</a>
        </div>
    <?php else: ?>
        <?php foreach ($customers as $customer): ?>
            <div class="customer-card">
                <div class="customer-header">
                    <div class="customer-avatar">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="customer-title">
                        <h3><?php echo htmlspecialchars($customer['name']); ?></h3>
                        <span class="customer-code">کد: <?php echo $customer['code'] ?? '-'; ?></span>
                    </div>
                </div>
                
                <div class="customer-info">
                    <?php if (!empty($customer['phone'])): ?>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($customer['phone']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['email'])): ?>
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($customer['email']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['address'])): ?>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?php echo htmlspecialchars($customer['address']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="customer-footer">
                    <a href="/customers/<?php echo $customer['id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-eye"></i>
                        مشاهده
                    </a>
                    <a href="/customers/<?php echo $customer['id']; ?>/edit" class="btn btn-secondary btn-sm">
                        <i class="fas fa-edit"></i>
                        ویرایش
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.page-header h1 {
    font-size: 24px;
    color: var(--text-primary);
}

.filters-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}

.filters-form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.search-box {
    flex: 1;
    position: relative;
}

.search-box i {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
}

.search-box input {
    width: 100%;
    padding: 10px 35px 10px 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-family: inherit;
}

.customers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.customer-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    transition: transform 0.3s, box-shadow 0.3s;
}

.customer-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.customer-header {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
}

.customer-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--secondary));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.customer-title {
    flex: 1;
}

.customer-title h3 {
    font-size: 18px;
    margin-bottom: 5px;
    color: var(--text-primary);
}

.customer-code {
    font-size: 12px;
    color: var(--text-secondary);
}

.customer-info {
    margin-bottom: 15px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    font-size: 14px;
    color: var(--text-secondary);
}

.info-item i {
    width: 16px;
    color: var(--primary);
}

.customer-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 60px 20px;
    background: var(--card-bg);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

.empty-state i {
    font-size: 64px;
    color: var(--text-secondary);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 20px;
    color: var(--text-primary);
    margin-bottom: 10px;
}

.empty-state p {
    color: var(--text-secondary);
    margin-bottom: 20px;
}
</style>

<?php 
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
?>