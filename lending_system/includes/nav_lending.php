<?php
/**
 * Shared navigation header and sidebar for Lending Management System
 */

$activePage = basename($_SERVER['PHP_SELF']);

function isActivePage($page) {
    global $activePage;
    return $activePage === $page ? 'active' : '';
}

$navItems = [
    ['href' => isBorrower() ? 'borrower_dashboard_lending.php' : (isCollector() ? 'collector_dashboard_lending.php' : 'dashboard_lending.php'), 'icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
];

if (isBorrower()) {
    $navItems[] = ['href' => 'borrower_my_loan_lending.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'My Loan'];
    $navItems[] = ['href' => 'borrower_payment_history_lending.php', 'icon' => 'fa-history', 'label' => 'Payment History'];
}

$navItems[] = ['href' => 'notifications_lending.php', 'icon' => 'fa-bell', 'label' => 'Notifications'];

if (!isBorrower()) {
    $navItems = array_merge($navItems, [
        ['href' => 'clients_lending.php', 'icon' => 'fa-users', 'label' => 'Clients'],
        ['href' => 'loans_lending.php', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Loans'],
    ]);
}

if (!isBorrower()) {
    $navItems[] = ['href' => 'payments_lending.php', 'icon' => 'fa-money-check-dollar', 'label' => 'Payments'];
}

if (isAdmin()) {
    $navItems[] = ['href' => 'expenses_lending.php', 'icon' => 'fa-receipt', 'label' => 'Expenses'];
    $navItems[] = ['href' => 'reports_lending.php', 'icon' => 'fa-chart-line', 'label' => 'Reports & Analytics'];
}

$currentUser = getCurrentUser();
$displayName = $currentUser['full_name'] ?? 'User';

?>
<a class="skip-link" href="#mainContent">Skip to content</a>
<div class="dashboard-layout">
    <aside class="sidebar" id="mainSidebar">
        <div class="brand-logo">
            <img src="images/logo.png" alt="Lending Management System logo">
            <span class="brand-text">Lending Dashboard</span>
        </div>

        <ul class="nav-links">
            <?php foreach ($navItems as $item): ?>
                <li>
                    <a href="<?= $item['href'] ?>" class="<?= isActivePage($item['href']) ?>"<?= isActivePage($item['href']) === 'active' ? ' aria-current="page"' : '' ?>>
                        <i class="fas <?= $item['icon'] ?>"></i>
                        <span><?= $item['label'] ?></span>
                    </a>
                </li>
            <?php endforeach; ?>

            <?php if (isAdmin()): ?>
                <li>
                    <a href="users_lending.php" class="<?= isActivePage('users_lending.php') ?>">
                        <i class="fas fa-user-cog"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li>
                    <a href="collectors_lending.php" class="<?= isActivePage('collectors_lending.php') ?>">
                        <i class="fas fa-user-tie"></i>
                        <span>Collectors</span>
                    </a>
                </li>
                <li>
                    <a href="borrower_approval_lending.php" class="<?= isActivePage('borrower_approval_lending.php') ?>">
                        <i class="fas fa-user-check"></i>
                        <span>Borrower Approval</span>
                    </a>
                </li>
                <li>
                    <a href="loan_applications_lending.php" class="<?= isActivePage('loan_applications_lending.php') ?>">
                        <i class="fas fa-file-signature"></i>
                        <span>Loan Applications</span>
                    </a>
                </li>
                <li>
                    <a href="loan_release_lending.php" class="<?= isActivePage('loan_release_lending.php') ?>">
                        <i class="fas fa-file-contract"></i>
                        <span>Loan Release</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

    </aside>

    <div class="main-panel" id="mainContent">
        <header class="topbar">
            <div class="topbar-inner">
                <div class="topbar-left">
                    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <div class="topbar-title">Lending Management</div>
                    </div>
                </div>

                <div class="topbar-meta">
                    <div class="topbar-text" id="dateTime"></div>
                    <?php $unreadCount = getUnreadNotificationCount($currentUser['user_id'] ?? 0); ?>
                    <a href="notifications_lending.php" class="btn btn-outline-secondary position-relative me-2" aria-label="View notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown profile-dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" id="profileMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($displayName); ?></span>
                            <i class="fas fa-user-circle ms-2"></i>
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
