/**
 * فایل اصلی جاوااسکریپت - سیستم مدیریت فاکتورها
 */

// Sidebar Toggle for Mobile
const menuToggle = document.getElementById('menuToggle');
const closeSidebar = document.getElementById('closeSidebar');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');

// Open Sidebar
if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        sidebar.classList.add('active');
        overlay.classList.add('active');
    });
}

// Close Sidebar
if (closeSidebar) {
    closeSidebar.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });
}

// Close Sidebar when clicking overlay
if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });
}

// Dropdown Menu Functionality
const dropdownToggles = document.querySelectorAll('.dropdown-toggle');

dropdownToggles.forEach(toggle => {
    toggle.addEventListener('click', (e) => {
        e.preventDefault();
        
        const parentLi = toggle.closest('li.dropdown');
        const isActive = parentLi.classList.contains('active');
        
        // Close all other dropdowns
        document.querySelectorAll('.dropdown').forEach(dropdown => {
            dropdown.classList.remove('active');
        });
        
        // Toggle current dropdown
        if (!isActive) {
            parentLi.classList.add('active');
        }
    });
});

// Active Menu Item
const menuItems = document.querySelectorAll('.sidebar-nav a:not(.dropdown-toggle)');

menuItems.forEach(item => {
    item.addEventListener('click', (e) => {
        // Remove active class from all items
        document.querySelectorAll('.sidebar-nav > ul > li').forEach(li => {
            li.classList.remove('active');
        });
        
        // Add active class to clicked item's parent li
        const parentLi = item.closest('li');
        if (parentLi && !parentLi.classList.contains('dropdown')) {
            parentLi.classList.add('active');
        }
        
        // Close sidebar on mobile after clicking
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    });
});

// Handle window resize
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        // Close sidebar on desktop view
        if (window.innerWidth > 768) {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        }
    }, 250);
});

// Search functionality
const searchInput = document.querySelector('.search-box input');
if (searchInput) {
    searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        console.log('جستجو برای:', searchTerm);
        // اینجا می‌تونی AJAX بزنی برای جستجوی فاکتورها
    });
}

// Table row click handler
const tableRows = document.querySelectorAll('.data-table tbody tr');
tableRows.forEach(row => {
    row.style.cursor = 'pointer';
});

// Format numbers as Persian
function formatNumber(number) {
    return new Intl.NumberFormat('fa-IR').format(number);
}

// Show notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

// Confirm action
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

console.log('✅ سیستم مدیریت فاکتورها با موفقیت بارگذاری شد');