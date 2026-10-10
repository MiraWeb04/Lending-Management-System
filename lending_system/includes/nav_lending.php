<?php
/**
 * Shared navigation header and sidebar for Lending Management System
 */

$activePage = basename($_SERVER['PHP_SELF']);

function isActivePage($page) {
    global $activePage;
    return $activePage === $page ? 'active' : '';
}

require_once __DIR__ . '/notification_ui_helpers.php';

$currentUser = getCurrentUser();
$displayName = $currentUser['full_name'] ?? 'User';
$userRole = trim((string)($currentUser['role'] ?? $_SESSION['role'] ?? 'User'));

$dashboardHref = isBorrower()
    ? 'borrower_dashboard_lending.php'
    : (isCollector() ? 'collector_dashboard_lending.php' : 'dashboard_lending.php');

$navSections = [
    [
        'label' => 'Main',
        'items' => [
            ['href' => $dashboardHref, 'icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
            ['href' => 'notifications_lending.php', 'icon' => 'fa-bell', 'label' => 'Notifications'],
        ],
    ],
];

if (isBorrower()) {
    require_once __DIR__ . '/loan_helpers.php';
    $borrowerUserId = (int)($currentUser['user_id'] ?? 0);
    $borrowerCanApplyForLoan = borrowerCanApplyForNewLoan($borrowerUserId);
    $borrowerShowLoanStatusNav = borrowerHasTrackableLoanApplication($borrowerUserId);
    $borrowerNavItems = [
        ['href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'My Loan'],
        ['href' => 'borrower_payment_history_lending.php', 'icon' => 'fa-clock-rotate-left', 'label' => 'Payment History'],
    ];
    if ($borrowerShowLoanStatusNav) {
        $borrowerNavItems[] = ['href' => 'borrower_loan_status_lending.php', 'icon' => 'fa-chart-line', 'label' => 'Loan Status'];
    }
    if ($borrowerCanApplyForLoan) {
        $borrowerNavItems[] = ['href' => 'borrower_loan_application_lending.php', 'icon' => 'fa-file-signature', 'label' => 'Apply for Loan'];
    }
    $borrowerNavItems[] = ['href' => 'borrower_loan_agreement_lending.php', 'icon' => 'fa-handshake', 'label' => 'Loan Agreement'];
    $navSections[] = [
        'label' => 'My Account',
        'items' => $borrowerNavItems,
    ];
}

if (!isBorrower()) {
    $navSections[] = [
        'label' => 'Loan Management',
        'items' => array_values(array_filter([
            ['href' => 'clients_lending.php', 'icon' => 'fa-users', 'label' => 'Clients'],
            ['href' => 'loans_lending.php', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Loans'],
            ['href' => 'payments_lending.php', 'icon' => 'fa-money-check-dollar', 'label' => 'Payments'],
            isAdmin() ? ['href' => 'loan_applications_lending.php', 'icon' => 'fa-file-signature', 'label' => 'Loan Applications'] : null,
            isAdmin() ? ['href' => 'loan_release_lending.php', 'icon' => 'fa-file-contract', 'label' => 'Loan Release'] : null,
            isAdmin() ? ['href' => 'loan_release_invoices_lending.php', 'icon' => 'fa-file-invoice', 'label' => 'Release Invoices'] : null,
            isAdmin() ? ['href' => 'borrower_approval_lending.php', 'icon' => 'fa-user-check', 'label' => 'Borrower Approval'] : null,
            isAdmin() ? ['href' => 'collectors_lending.php', 'icon' => 'fa-user-tie', 'label' => 'Collectors'] : null,
        ])),
    ];
}

if (isAdmin()) {
    $navSections[] = [
        'label' => 'Finance',
        'items' => [
            ['href' => 'expenses_lending.php', 'icon' => 'fa-receipt', 'label' => 'Expenses'],
            ['href' => 'penalties_lending.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Penalties'],
            ['href' => 'reports_lending.php', 'icon' => 'fa-chart-line', 'label' => 'Reports & Analytics'],
        ],
    ];
    $navSections[] = [
        'label' => 'System',
        'items' => [
            ['href' => 'contact_inquiries_lending.php', 'icon' => 'fa-envelope-open-text', 'label' => 'Contact Inquiries'],
            ['href' => 'users_lending.php', 'icon' => 'fa-user-gear', 'label' => 'Users'],
            ['href' => 'profile_lending.php', 'icon' => 'fa-id-card', 'label' => 'Profile'],
        ],
    ];
}

$globalSearchTarget = isAdmin() ? 'clients_lending.php' : (isCollector() ? 'loans_lending.php' : '');
?>
<a class="skip-link" href="#mainContent">Skip to content</a>
<div class="dashboard-layout">
    <aside class="sidebar sidebar--premium" id="mainSidebar" aria-label="Primary navigation">
        <div class="brand-logo">
            <img src="images/logo.png" alt="RJ and RR Finance Services logo">
            <span class="brand-text">RJ &amp; RR Finance</span>
        </div>

        <nav class="sidebar-nav" aria-label="Sidebar menu">
            <?php foreach ($navSections as $section): ?>
                <div class="nav-section-label"><?php echo htmlspecialchars($section['label']); ?></div>
                <ul class="nav-links">
                    <?php foreach ($section['items'] as $item): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="<?php echo isActivePage($item['href']); ?>"<?php echo isActivePage($item['href']) === 'active' ? ' aria-current="page"' : ''; ?> title="<?php echo htmlspecialchars($item['label']); ?>">
                                <i class="fas <?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars($item['label']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer-note">
            <div>Signed in as <?php echo htmlspecialchars($displayName); ?></div>
            <?php if (isLoggedIn()): ?>
                <a href="logout_lending.php" class="sidebar-logout-link"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            <?php endif; ?>
        </div>
    </aside>

    <div class="main-panel" id="mainContent">
        <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <header class="topbar">
            <div class="topbar-inner">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <div class="topbar-title">Lending Management</div>
                        <div class="small text-muted d-none d-md-block">Business operations console</div>
                    </div>
                </div>

                <div class="topbar-meta">
                    <?php if ($globalSearchTarget !== ''): ?>
                        <div class="topbar-search position-relative d-none d-lg-block">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" class="form-control" placeholder="Search clients or records..." data-global-search="<?php echo htmlspecialchars($globalSearchTarget); ?>" aria-label="Search">
                        </div>
                    <?php endif; ?>
                    <div class="topbar-text d-none d-xl-block" id="dateTime"></div>
                    <?php $unreadCount = (int)getUnreadNotificationCount($currentUser['user_id'] ?? 0); ?>
                    <?php echo renderNotificationTopbarBell($unreadCount); ?>
                    <div class="dropdown profile-dropdown">
                        <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" type="button" id="profileMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="role-badge d-none d-md-inline-flex"><?php echo htmlspecialchars($userRole); ?></span>
                            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($displayName); ?></span>
                            <i class="fas fa-user-circle"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileMenuButton">
                            <li><a class="dropdown-item" href="profile_lending.php"><i class="fas fa-id-card me-2"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout_lending.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <div class="main-panel-body">
