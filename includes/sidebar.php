<!-- sidebar.php -->
<?php
// Get current user role
$user_id = $_SESSION['user_id'];
$role_query = "SELECT role, name FROM users WHERE id = ?";
$stmt = $conn->prepare($role_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_role = $user_data['role'] ?? 'sale';
$user_name = $user_data['name'] ?? 'User';

// Get user initials for avatar
$name_parts = explode(' ', $user_name);
$initials = '';
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if (strlen($initials) > 2) {
    $initials = substr($initials, 0, 2);
}

// Define menu items with roles
$menu_items = [
    'main' => [
        'label' => 'Main',
        'items' => [
            'dashboard' => [
                'title' => 'Dashboard',
                'icon' => 'bi-grid-1x2-fill',
                'url' => 'index.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'sales' => [
        'label' => 'Sales',
        'icon' => 'bi-cart',
        'is_section' => true,
        'items' => [
            'new-sale' => [
                'title' => 'New Sale',
                'icon' => 'bi-cash-coin',
                'url' => 'new-sale.php',
                'roles' => ['admin', 'sale'],
                'active_pages' => ['new-sale.php']
            ],
            
            // 'bulk-sale' => [
            //     'title' => 'Bulk Sale',
            //     'icon' => 'bi-cash-stack',
            //     'url' => 'bulk_sale.php',
            //     'roles' => ['admin', 'sale'],
            //     'active_pages' => ['bulk_sale.php']
            // ],

            'invoices' => [
                'title' => 'Invoices',
                'icon' => 'bi-receipt',
                'url' => 'invoices.php',
                'roles' => ['admin', 'sale']
            ],

            'customers' => [
                'title' => 'Customers',
                'icon' => 'bi-people',
                'url' => 'customers.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'inventory' => [
        'label' => 'Inventory',
        'icon' => 'bi-box-seam',
        'is_section' => true,
        'items' => [
            'categories' => [
                'title' => 'Categories',
                'icon' => 'bi-tags',
                'url' => 'categories.php',
                'roles' => ['admin', 'sale']
            ],
            'products' => [
                'title' => 'Products',
                'icon' => 'bi-box-seam',
                'url' => 'products.php',
                'roles' => ['admin', 'sale']
            ],
            'stocks' => [
                'title' => 'Stock Levels',
                'icon' => 'bi-bar-chart',
                'url' => 'stocks.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'purchases' => [
        'label' => 'Purchases',
        'icon' => 'bi-truck',
        'is_section' => true,
        'items' => [
            'suppliers' => [
                'title' => 'Suppliers',
                'icon' => 'bi-truck',
                'url' => 'suppliers.php',
                'roles' => ['admin', 'sale']
            ],
            'purchase-order' => [
                'title' => 'Add Purchase',
                'icon' => 'bi-cart-plus',
                'url' => 'add-purchase.php',
                'roles' => ['admin']
            ],
            'purchase-list' => [
                'title' => 'Manage Purchase',
                'icon' => 'bi-cart-check',
                'url' => 'manage-purchases.php',
                'roles' => ['admin', 'sale']
            ],
            'purchase-payments' => [
                'title' => 'Purchase Payment History',
                'icon' => 'bi-credit-card',
                'url' => 'purchase-payments.php',
                'roles' => ['admin']
            ]
        ]
    ],
    
    'financial' => [
        'label' => 'Financial',
        'icon' => 'bi-pie-chart-fill',
        'is_section' => true,
        'items' => [
            // 'expense-add' => [
            //     'title' => 'Add Expense',
            //     'icon' => 'bi-plus-circle',
            //     'url' => 'expenses.php',
            //     'roles' => ['admin']
            // ],
            'expense-list' => [
                'title' => 'Manage Expense',
                'icon' => 'bi-receipt',
                'url' => 'expenses.php',
                'roles' => ['admin', 'sale']
            ],
            'bank-transactions' => [
                'title' => 'Bank Transactions',
                'icon' => 'bi-bank',
                'url' => 'bank-acc-transactions.php',
                'roles' => ['admin']
            ]
        ]
    ],
    
    'gst' => [
        'label' => 'GST',
        'icon' => 'bi-percent',
        'is_section' => true,
        'items' => [
            'gst-rates' => [
                'title' => 'GST Rates',
                'icon' => 'bi-percent',
                'url' => 'gst-rates.php',
                'roles' => ['admin']
            ]
        ]
    ],
    
    'reports' => [
        'label' => 'Reports',
        'icon' => 'bi-graph-up',
        'is_section' => true,
        'items' => [
            'sales-report' => [
                'title' => 'Sales Report',
                'icon' => 'bi-graph-up',
                'url' => 'sales.php',
                'roles' => ['admin', 'sale']
            ],
           'product-wise-sale-report' => [
    'title' => 'Product wise sale Report',
    'icon' => 'bi-box-seam', // product related
    'url' => 'product-wise-sale-report.php',
    'roles' => ['admin']
],
'customer-ledger-report' => [
    'title' => 'Customer Ledger Report',
    'icon' => 'bi-journal-text', // ledger/book related
    'url' => 'customer-ledger-report.php',
    'roles' => ['admin']
],
            'purchase-report' => [
                'title' => 'Purchase Report',
                'icon' => 'bi-graph-down',
                'url' => 'purchase-report.php',
                'roles' => ['admin']
            ],
            'stock-report' => [
                'title' => 'Stock Report',
                'icon' => 'bi-pie-chart',
                'url' => 'stock.php',
                'roles' => ['admin', 'sale']
            ],
            'oc-report' => [
                'title' => 'O/C Report',
                'icon' => 'bi-graph-up-arrow',
                'url' => 'oc_report.php',
                'roles' => ['admin', 'sale']
            ],
            'profit-loss' => [
                'title' => 'Profit & Loss',
                'icon' => 'bi-bar-chart-line',
                'url' => 'profit_loss_report.php',
                'roles' => ['admin', 'sale']
            ],
            'gst-report' => [
                'title' => 'GST Report',
                'icon' => 'bi-file-spreadsheet',
                'url' => 'gst_reports.php',
                'roles' => ['admin']
            ],
            'payment-methods' => [
                'title' => 'Payment Methods Report',
                'icon' => 'bi-wallet2',
                'url' => 'payment-methods-report.php',
                'roles' => ['admin']
            ],
            'activity-log' => [
                'title' => 'Activity Log',
                'icon' => 'bi-clock-history',
                'url' => 'activity-log.php',
                'roles' => ['admin']
            ]
        ]
    ],
    
    'settings' => [
        'label' => 'Settings',
        'icon' => 'bi-gear',
        'is_section' => true,
        'items' => [
            'invoice-setting' => [
                'title' => 'Invoice Settings',
                'icon' => 'bi-gear',
                'url' => 'invoice-setting.php',
                'roles' => ['admin']
            ],
            'users' => [
                'title' => 'Users',
                'icon' => 'bi-person-badge',
                'url' => 'users.php',
                'roles' => ['admin']
            ],
            'profile' => [
                'title' => 'My Profile',
                'icon' => 'bi-person',
                'url' => 'profile.php',
                'roles' => ['admin', 'sale']
            ],
            'logout' => [
                'title' => 'Logout',
                'icon' => 'bi-box-arrow-right',
                'url' => 'logout.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ]
];

// Filter menu items based on user role
function filterMenuByRole($menu_items, $user_role) {
    $filtered_menu = [];
    foreach ($menu_items as $section_key => $section) {
        if (isset($section['is_section']) && $section['is_section']) {
            $filtered_items = [];
            foreach ($section['items'] as $item_key => $item) {
                if (in_array($user_role, $item['roles'])) {
                    $filtered_items[$item_key] = $item;
                }
            }
            if (!empty($filtered_items)) {
                $filtered_menu[$section_key] = [
                    'label' => $section['label'],
                    'icon' => $section['icon'],
                    'is_section' => true,
                    'items' => $filtered_items
                ];
            }
        } else {
            // Regular single menu item (like dashboard)
            $filtered_items = [];
            foreach ($section['items'] as $item_key => $item) {
                if (in_array($user_role, $item['roles'])) {
                    $filtered_items[$item_key] = $item;
                }
            }
            if (!empty($filtered_items)) {
                $filtered_menu[$section_key] = [
                    'label' => $section['label'],
                    'items' => $filtered_items
                ];
            }
        }
    }
    return $filtered_menu;
}

$menu_items = filterMenuByRole($menu_items, $user_role);

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Determine which sections should be open by default
function isSectionActive($section_items, $current_page) {
    foreach ($section_items as $item) {
        if ($current_page == $item['url'] ||
            (isset($item['active_pages']) && in_array($current_page, $item['active_pages']))) {
            return true;
        }
    }
    return false;
}
?>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar Navigation -->
<nav class="sidebar" id="sidebar" data-testid="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <img src="assets/logo1.png" alt="Sri plast Logo" style="width: 38px; height: 38px; object-fit: contain;">
        </div>
        <div>
            <h2>Sri Plaast</h2>
            <small>Inventory Management</small>
        </div>
    </div>

    <div class="sidebar-nav">
        <?php foreach ($menu_items as $section_key => $section): ?>
            <?php if (isset($section['is_section']) && $section['is_section']): ?>
                <!-- Collapsible Section -->
                <?php 
                $section_active = isSectionActive($section['items'], $current_page);
                $section_open = $section_active ? 'open' : '';
                ?>
                <div class="sidebar-section">
                    <div class="sidebar-section-header <?php echo $section_active ? 'active-section' : ''; ?>" 
                         onclick="toggleSection('<?php echo $section_key; ?>', this)">
                        <div class="section-header-left">
                            <i class="bi <?php echo $section['icon']; ?>"></i>
                            <span class="section-label"><?php echo $section['label']; ?></span>
                        </div>
                        <i class="bi bi-chevron-down section-arrow" id="arrow-<?php echo $section_key; ?>"></i>
                    </div>
                    
                    <div class="sidebar-submenu" id="section-<?php echo $section_key; ?>" style="display: <?php echo $section_open ? 'block' : 'none'; ?>;">
                        <?php foreach ($section['items'] as $item_key => $item): ?>
                            <?php
                            $is_active = '';
                            if ($current_page == $item['url'] ||
                                (isset($item['active_pages']) && in_array($current_page, $item['active_pages']))) {
                                $is_active = 'active';
                            }
                            ?>
                            <a href="<?php echo $item['url']; ?>" 
                               class="nav-link submenu-link <?php echo $is_active; ?>"
                               data-testid="nav-<?php echo $item_key; ?>">
                                <i class="bi <?php echo $item['icon']; ?>"></i>
                                <span><?php echo $item['title']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Regular Section (like Main) -->
                <div class="sidebar-label"><?php echo $section['label']; ?></div>
                <?php foreach ($section['items'] as $key => $item): ?>
                    <?php
                    $is_active = '';
                    if ($current_page == $item['url'] ||
                        (isset($item['active_pages']) && in_array($current_page, $item['active_pages']))) {
                        $is_active = 'active';
                    }
                    
                    // Special case for dashboard
                    if ($key == 'dashboard' && $current_page == 'index.php') {
                        $is_active = 'active';
                    }
                    ?>
                    
                    <a href="<?php echo $item['url']; ?>" 
                       class="nav-link <?php echo $is_active; ?>"
                       data-testid="nav-<?php echo $key; ?>">
                        <i class="bi <?php echo $item['icon']; ?>"></i>
                        <span><?php echo $item['title']; ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role"><?php echo ucfirst($user_role); ?></div>
            </div>
        </div>
    </div>

    <button class="sidebar-toggle-compact" onclick="toggleCompactSidebar()" data-testid="button-compact-sidebar">
        <i class="bi bi-layout-sidebar-inset" id="compactIcon"></i>
        <span>Compact View</span>
    </button>
</nav>

<script>
// Current active section tracking
let currentOpenSection = null;

// Toggle section submenu with auto-close other sections
function toggleSection(sectionId, element) {
    const submenu = document.getElementById('section-' + sectionId);
    const arrow = document.getElementById('arrow-' + sectionId);
    
    // If this section is currently open, close it
    if (submenu.style.display === 'block') {
        submenu.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
        currentOpenSection = null;
        localStorage.setItem('last_open_section', '');
    } else {
        // Close the previously open section if exists
        if (currentOpenSection && currentOpenSection !== sectionId) {
            const prevSubmenu = document.getElementById('section-' + currentOpenSection);
            const prevArrow = document.getElementById('arrow-' + currentOpenSection);
            if (prevSubmenu) {
                prevSubmenu.style.display = 'none';
                prevArrow.style.transform = 'rotate(0deg)';
            }
        }
        
        // Open this section
        submenu.style.display = 'block';
        arrow.style.transform = 'rotate(180deg)';
        currentOpenSection = sectionId;
        localStorage.setItem('last_open_section', sectionId);
    }
}

// Initialize section states
document.addEventListener('DOMContentLoaded', function() {
    let activeSectionFound = false;
    
    <?php foreach ($menu_items as $section_key => $section): ?>
        <?php if (isset($section['is_section']) && $section['is_section']): ?>
            (function() {
                const sectionId = '<?php echo $section_key; ?>';
                const submenu = document.getElementById('section-' + sectionId);
                const arrow = document.getElementById('arrow-' + sectionId);
                const isActive = <?php echo isSectionActive($section['items'], $current_page) ? 'true' : 'false'; ?>;
                
                if (isActive) {
                    // If this section is active, open it and set as current
                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(180deg)';
                    currentOpenSection = sectionId;
                    activeSectionFound = true;
                } else {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                }
            })();
        <?php endif; ?>
    <?php endforeach; ?>
    
    // If no active section found, check localStorage for last open section
    if (!activeSectionFound) {
        const lastOpen = localStorage.getItem('last_open_section');
        if (lastOpen) {
            const lastSubmenu = document.getElementById('section-' + lastOpen);
            const lastArrow = document.getElementById('arrow-' + lastOpen);
            if (lastSubmenu) {
                lastSubmenu.style.display = 'block';
                lastArrow.style.transform = 'rotate(180deg)';
                currentOpenSection = lastOpen;
            }
        }
    }
});

// Toggle sidebar (mobile)
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

// Toggle compact sidebar
function toggleCompactSidebar() {
    const sidebar = document.getElementById('sidebar');
    const icon = document.getElementById('compactIcon');
    
    sidebar.classList.toggle('compact');
    
    if (sidebar.classList.contains('compact')) {
        icon.classList.remove('bi-layout-sidebar-inset');
        icon.classList.add('bi-layout-sidebar-inset-reverse');
    } else {
        icon.classList.remove('bi-layout-sidebar-inset-reverse');
        icon.classList.add('bi-layout-sidebar-inset');
    }
    
    // Save preference to localStorage
    localStorage.setItem('compact_sidebar', sidebar.classList.contains('compact'));
}

// Initialize compact sidebar preference
document.addEventListener('DOMContentLoaded', function() {
    const compactPref = localStorage.getItem('compact_sidebar');
    if (compactPref === 'true') {
        document.getElementById('sidebar').classList.add('compact');
        document.getElementById('compactIcon').classList.remove('bi-layout-sidebar-inset');
        document.getElementById('compactIcon').classList.add('bi-layout-sidebar-inset-reverse');
    }
});

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggleBtn = event.target.closest('[onclick="toggleSidebar()"]');
    
    if (!sidebar.contains(event.target) && !toggleBtn && window.innerWidth <= 768) {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }
});
</script>

<style>
/* Additional styles for collapsible sections with reduced font sizes */
.sidebar-section {
    margin-bottom: 4px;
}

.sidebar-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    cursor: pointer;
    color: #a0aec0;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin: 2px 8px;
    font-size: 0.85rem; /* Reduced from default */
}

.sidebar-section-header:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.sidebar-section-header.active-section {
    color: #fff;
    background: rgba(59, 130, 246, 0.2);
}

.section-header-left {
    display: flex;
    align-items: center;
    gap: 10px; /* Reduced gap */
}

.section-header-left i {
    font-size: 1.1rem; /* Slightly reduced */
    width: 22px; /* Reduced width */
}

.section-label {
    font-size: 0.85rem; /* Reduced from 0.95rem */
    font-weight: 500;
}

.section-arrow {
    font-size: 0.9rem; /* Reduced from 1rem */
    transition: transform 0.3s ease;
}

.sidebar-label {
    font-size: 0.75rem; /* Reduced from default */
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280;
    padding: 12px 16px 4px;
    font-weight: 600;
}

.sidebar-submenu {
    margin-left: 6px; /* Reduced from 8px */
    padding-left: 6px; /* Reduced from 8px */
    border-left: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar-submenu .nav-link {
    padding: 8px 16px 8px 44px !important; /* Reduced padding */
    font-size: 0.8rem; /* Reduced from 0.9rem */
}

.sidebar-submenu .nav-link i {
    font-size: 0.9rem; /* Reduced from 1rem */
    width: 18px; /* Reduced from 20px */
}

/* Main navigation links */
.nav-link {
    display: flex;
    align-items: center;
    gap: 10px; /* Reduced from 12px */
    padding: 10px 16px; /* Reduced from 12px 16px */
    color: #a0aec0;
    text-decoration: none;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin: 2px 8px;
    font-size: 0.85rem; /* Added font size reduction */
}

.nav-link i {
    font-size: 1.1rem; /* Slightly reduced */
    width: 22px; /* Reduced width */
}

.nav-link:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.nav-link.active {
    background: #3b82f6;
    color: #fff;
}

/* Compact sidebar adjustments */
.sidebar.compact .sidebar-section-header {
    padding: 10px; /* Reduced from 12px */
    justify-content: center;
}

.sidebar.compact .section-header-left span,
.sidebar.compact .section-arrow {
    display: none;
}

.sidebar.compact .sidebar-submenu {
    position: fixed;
    left: 70px;
    background: #1e293b;
    border-radius: 8px;
    padding: 6px 0; /* Reduced from 8px */
    min-width: 180px; /* Reduced from 200px */
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    display: none !important;
}

.sidebar.compact .sidebar-section:hover .sidebar-submenu {
    display: block !important;
}

.sidebar.compact .sidebar-submenu .nav-link {
    padding: 8px 14px !important; /* Reduced from 10px 16px */
    font-size: 0.8rem; /* Added font size */
}

/* Sidebar brand adjustments */
.sidebar-brand h2 {
    font-size: 1.2rem; /* Reduced from default */
    margin: 0;
    line-height: 1.2;
}

.sidebar-brand small {
    font-size: 0.7rem; /* Reduced from default */
    opacity: 0.7;
}

/* User info adjustments */
.user-name {
    font-size: 0.9rem; /* Reduced from default */
    font-weight: 500;
}

.user-role {
    font-size: 0.75rem; /* Reduced from default */
    opacity: 0.7;
}

.user-avatar {
    width: 36px; /* Slightly reduced */
    height: 36px; /* Slightly reduced */
    font-size: 0.9rem; /* Reduced from default */
}

/* Compact toggle button */
.sidebar-toggle-compact {
    font-size: 0.8rem; /* Added font size */
    padding: 8px 12px; /* Adjusted padding */
}

.sidebar-toggle-compact i {
    font-size: 1rem; /* Added icon size */
}
</style>