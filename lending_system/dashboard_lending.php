<?php
/**
 * Dashboard Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

function dashboardFetchRow($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
}

function dashboardFetchRows($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

if (isCollector()) {
    header('Location: collector_dashboard_lending.php');
    exit;
}

// Include database connection
require_once 'includes/db_lending.php';

ensureExpensesSchema();

// Get dashboard statistics

// Total Clients
$clientsQuery = "SELECT COUNT(*) as total FROM clients";
$totalClients = dashboardFetchRow($clientsQuery)['total'] ?? 0;

// Active Loans
$activeLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Active'";
$activeLoans = dashboardFetchRow($activeLoansQuery)['total'] ?? 0;

// Overdue Loans
$overdueLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Overdue'";
$overdueLoans = dashboardFetchRow($overdueLoansQuery)['total'] ?? 0;

// Paid Loans
$paidLoansQuery = "SELECT COUNT(*) as total FROM loans WHERE status = 'Paid'";
$paidLoans = dashboardFetchRow($paidLoansQuery)['total'] ?? 0;

// Total Collection Today
$todayDate = date('Y-m-d');
$todayCollectionQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE payment_date = ?";
$todayCollection = dashboardFetchRow($todayCollectionQuery, [$todayDate])['total'] ?? 0;

// Total Payments
$totalPaymentsQuery = "SELECT SUM(amount_paid) as total FROM payments";
$totalPayments = dashboardFetchRow($totalPaymentsQuery)['total'] ?? 0;

// Total Expenses
$totalExpensesQuery = "SELECT SUM(amount) as total FROM expenses";
$totalExpenses = dashboardFetchRow($totalExpensesQuery)['total'] ?? 0;

// Expense breakdown by category
$expenseBreakdownQuery = "SELECT category, SUM(amount) AS total_amount FROM expenses GROUP BY category ORDER BY total_amount DESC";
$expenseBreakdownRows = dashboardFetchRows($expenseBreakdownQuery);
$expenseLabels = [];
$expenseData = [];
$expenseColors = [];
$categoryColorMap = [
    'Transportation' => '#2563eb',
    'Office Supplies' => '#f59e0b',
    'Utilities' => '#10b981',
    'Maintenance' => '#ef4444',
    'Salaries' => '#8b5cf6',
    'Miscellaneous' => '#6366f1'
];

foreach ($expenseBreakdownRows as $row) {
    $label = $row['category'] ?: 'Uncategorized';
    $expenseLabels[] = $label;
    $expenseData[] = (float)$row['total_amount'];
    $expenseColors[] = $categoryColorMap[$label] ?? '#6b7280';
}

if (empty($expenseLabels)) {
    $expenseLabels = ['Transportation', 'Office Supplies', 'Utilities', 'Maintenance', 'Miscellaneous'];
    $expenseData = [15000, 8000, 12000, 10000, 5000];
    $expenseColors = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'];
}

// Total Users
$totalUsersQuery = "SELECT COUNT(*) as total FROM users";
$totalUsers = dashboardFetchRow($totalUsersQuery)['total'] ?? 0;

ensureNotificationsSchema();
$unreadNotificationCount = getUnreadNotificationCount($user['user_id']);
$notifications = getUserNotifications($user['user_id'], 5);

// Recent Payments (last 5)
$recentPaymentsQuery = "
    SELECT p.payment_id, p.payment_date, p.amount_paid, p.collector_name, 
           c.first_name, c.last_name, l.loan_id
    FROM payments p
    JOIN loans l ON p.loan_id = l.loan_id
    JOIN clients c ON l.client_id = c.client_id
    ORDER BY p.payment_date DESC, p.payment_id DESC
    LIMIT 5
";
$recentPayments = dashboardFetchRows($recentPaymentsQuery);

// Overdue Loans (top 5)
$overdueLoansListQuery = "
    SELECT l.loan_id, l.loan_amount, l.total_payable, l.due_date, 
           c.first_name, c.last_name, c.contact
    FROM loans l
    JOIN clients c ON l.client_id = c.client_id
    WHERE l.status = 'Overdue'
    ORDER BY l.due_date ASC
    LIMIT 5
";
$overdueLoanslist = dashboardFetchRows($overdueLoansListQuery);

// Calculate total loan amount
$totalLoanAmountQuery = "SELECT SUM(loan_amount) as total FROM loans WHERE status = 'Active'";
$totalLoanAmount = dashboardFetchRow($totalLoanAmountQuery)['total'] ?? 0;

// Calculate total receivable amount
$totalReceivableQuery = "SELECT SUM(total_payable) as total FROM loans WHERE status = 'Active'";
$totalReceivable = dashboardFetchRow($totalReceivableQuery)['total'] ?? 0;

// Format currency values
$formattedTodayCollection = number_format($todayCollection, 2);
$formattedTotalLoanAmount = number_format($totalLoanAmount, 2);
$formattedTotalReceivable = number_format($totalReceivable, 2);

// Calculate expected profit
$expectedProfit = $totalReceivable - $totalLoanAmount;
$formattedExpectedProfit = number_format($expectedProfit, 2);

// Get monthly collection data for chart (last 6 months)
$monthlyCollectionData = [];
$monthLabels = [];

for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    
    $monthlyQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE payment_date BETWEEN ? AND ?";
    $monthlyTotal = dashboardFetchRow($monthlyQuery, [$monthStart, $monthEnd])['total'] ?? 0;
    
    $monthlyCollectionData[] = $monthlyTotal;
    $monthLabels[] = date('M Y', strtotime($monthStart));
}

// Keep expense chart defaults separate from the database-backed collection trend.
$expenseLabels = isset($expenseLabels) ? $expenseLabels : [
    'Transportation', 'Office Supplies', 'Utilities', 'Maintenance', 'Miscellaneous'
];
$expenseData = isset($expenseData) ? $expenseData : [15000, 8000, 12000, 10000, 5000];
$expenseColors = isset($expenseColors) ? $expenseColors : [
    '#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6'
];

// Convert to JSON for chart.js
$monthlyCollectionJSON = json_encode($monthlyCollectionData);
$monthLabelsJSON = json_encode($monthLabels);
$collectionLabelsJSON = json_encode($monthLabels);
$collectionDataJSON = json_encode($monthlyCollectionData);
$expenseLabelsJSON = json_encode($expenseLabels);
$expenseDataJSON = json_encode($expenseData);
$expenseColorsJSON = json_encode($expenseColors);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>! Here is your lending portfolio overview.</p>
            </div>
        </div>

        <!-- Premium Statistic Cards -->
        <div class="row g-4 mb-5">
            <div class="col-xl-4 col-md-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="metric-icon bg-primary-subtle text-primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="metric-label">Total Clients</div>
                            <div class="metric-value"><?php echo number_format($totalClients); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="metric-icon bg-success-subtle text-success">
                            <i class="fas fa-hand-holding-dollar"></i>
                        </div>
                        <div>
                            <div class="metric-label">Active Loans</div>
                            <div class="metric-value"><?php echo number_format($activeLoans); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="metric-icon bg-info-subtle text-info">
                            <i class="fas fa-money-check-dollar"></i>
                        </div>
                        <div>
                            <div class="metric-label">Total Payments</div>
                            <div class="metric-value">₱<?php echo number_format($totalPayments, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4 col-md-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="metric-icon bg-warning-subtle text-warning">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <div class="metric-label">Monthly Collections</div>
                            <div class="metric-value">₱<?php echo number_format($todayCollection, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <div class="col-xl-4 col-md-6">
            <div class="card metric-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="metric-icon bg-secondary-subtle text-secondary">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <div class="metric-label">Unread Notifications</div>
                        <div class="metric-value"><?php echo number_format($unreadNotificationCount); ?></div>
                    </div>
                </div>
            </div>
        </div>
            </div>

            <div class="col-xl-4 col-md-6">
                <div class="card metric-card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="metric-icon bg-secondary-subtle text-secondary">
                            <i class="fas fa-user-cog"></i>
                        </div>
                        <div>
                            <div class="metric-label">Total Users</div>
                            <div class="metric-value"><?php echo number_format($totalUsers); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row g-4 mb-5">
            <div class="col-xl-7">
                <div class="card chart-card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="m-0 fw-bold">Collection Trend</h6>
                            <small class="text-muted">Monthly Payments Performance</small>
                        </div>
                        <span class="badge bg-primary bg-opacity-15 text-primary">Live</span>
                    </div>
                    <div class="card-body chart-card-body">
                        <div class="chart-canvas-shell">
                            <canvas id="collectionTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card chart-card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                        <div>
                            <h6 class="m-0 fw-bold">Expense Breakdown</h6>
                            <small class="text-muted">Category Spending Overview</small>
                        </div>
                        <span class="badge bg-warning bg-opacity-15 text-warning">Budget</span>
                    </div>
                    <div class="card-body chart-card-body">
                        <div class="chart-canvas-shell chart-canvas-shell--small">
                            <canvas id="expenseBreakdownChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="row mb-4">
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-money-check-alt me-2"></i>Financial Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-borderless">
                                <tbody>
                                    <tr>
                                        <td><strong>Total Active Loan Amount:</strong></td>
                                        <td class="text-end">₱<?php echo $formattedTotalLoanAmount; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Total Receivable Amount:</strong></td>
                                        <td class="text-end">₱<?php echo $formattedTotalReceivable; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Expected Profit:</strong></td>
                                        <td class="text-end text-success">₱<?php echo $formattedExpectedProfit; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Today's Collection:</strong></td>
                                        <td class="text-end">₱<?php echo $formattedTodayCollection; ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-exclamation-circle me-2"></i>Overdue Loans</h6>
                        <a href="loans_lending.php?status=overdue" class="btn btn-sm btn-outline-primary btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View All Overdue Loans">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($overdueLoanslist) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Client</th>
                                        <th>Amount</th>
                                        <th>Due Date</th>
                                        <th>Contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdueLoanslist as $loan): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></td>
                                        <td>₱<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($loan['contact']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                            <p>No overdue loans at the moment.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-history me-2"></i>Recent Payments</h6>
                        <a href="payments_lending.php" class="btn btn-sm btn-outline-primary btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View All Payments">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($recentPayments) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Client</th>
                                        <th>Amount</th>
                                        <th>Collector</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                        <td>₱<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                                        <td>
                                            <a href="payments_lending.php?view=<?php echo $payment['payment_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Payment">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-receipt text-muted fa-3x mb-3"></i>
                            <p>No recent payments recorded.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
<?php include 'includes/nav_footer_lending.php'; ?>

        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between small">
                <div class="text-muted">Copyright &copy; Lending Management System <?php echo date('Y'); ?></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Charts JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const chartInstances = {};

            function createOrUpdateChart(canvasId, config) {
                const canvas = document.getElementById(canvasId);
                if (!canvas) {
                    return null;
                }

                const existingChart = chartInstances[canvasId];
                if (existingChart) {
                    existingChart.destroy();
                }

                const ctx = canvas.getContext('2d');
                chartInstances[canvasId] = new Chart(ctx, config);
                return chartInstances[canvasId];
            }

            // Collection trend line chart
            createOrUpdateChart('collectionTrendChart', {
                type: 'line',
                data: {
                    labels: <?php echo $collectionLabelsJSON; ?>,
                    datasets: [{
                        label: 'Collected Amount',
                        data: <?php echo $collectionDataJSON; ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.16)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1200,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return 'Collected: ₱' + Number(context.raw).toLocaleString('en-PH');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 0,
                                autoSkip: true
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    return '₱' + Number(value).toLocaleString('en-PH');
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            // Expense breakdown doughnut chart
            createOrUpdateChart('expenseBreakdownChart', {
                type: 'doughnut',
                data: {
                    labels: <?php echo $expenseLabelsJSON; ?>,
                    datasets: [{
                        data: <?php echo $expenseDataJSON; ?>,
                        backgroundColor: <?php echo $expenseColorsJSON; ?>,
                        borderColor: '#ffffff',
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    animation: {
                        duration: 1200,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 12,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const total = context.chart.data.datasets[0].data.reduce((sum, value) => sum + Number(value), 0);
                                    const value = Number(context.raw);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ₱${value.toLocaleString('en-PH')} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            window.addEventListener('resize', function () {
                Object.values(chartInstances).forEach(function (chart) {
                    chart.resize();
                });
            });
        });
    </script>
</body>
</html>
