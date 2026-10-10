<?php
/**
 * Expenses Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireStaff();

// Get current user data
$user = getCurrentUser();

if (isCollector()) {
    denyCollectorAccess('Collectors cannot access the expenses module.');
}

// Include database connection
require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/report_export_helpers.php';

ensureExpensesSchema();

$periodMode = trim((string)($_GET['period_mode'] ?? 'monthly'));
$periodResolved = resolveReportPeriodRange($periodMode, $_GET);
$periodMode = $periodResolved['period_mode'];
$startDate = $periodResolved['start_date'];
$endDate = $periodResolved['end_date'];
$reportDate = $periodResolved['report_date'];
$reportMonth = $periodResolved['report_month'];
$reportYear = $periodResolved['report_year'];
$periodLabel = $periodResolved['period_label'];
$periodModeLabels = lendingPeriodModeLabels();
$yearOptions = lendingYearOptions();

$expenseCategories = [
    'Transportation',
    'Office Supplies',
    'Utilities',
    'Maintenance',
    'Salaries',
    'Miscellaneous'
];

// Initialize variables
$message = '';
$messageType = '';
$expenses = [];
$addExpense = false;
$editExpense = null;
$searchTerm = '';

// Check if we're adding a new expense
if (isset($_GET['add']) && $_GET['add'] === 'true') {
    $addExpense = true;
}

// Check if we're editing an expense
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $expenseId = (int)$_GET['edit'];
    
    // Get expense details
    $expenseQuery = "SELECT * FROM expenses WHERE expense_id = ? LIMIT 1";
    $expenseResult = executeQuery($expenseQuery, [$expenseId]);
    $editExpense = $expenseResult->fetch();
    
    if (!$editExpense) {
        header('Location: expenses_lending.php');
        exit;
    }
}

// Process expense form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();
    if (isset($_POST['add_expense'])) {
        $description = trim($_POST['description']);
        $amount = (float)$_POST['amount'];
        $date = $_POST['date'];
        $category = trim($_POST['category'] ?? '');
        
        // Validate expense data
        if (empty($description)) {
            $message = "Description is required.";
            $messageType = 'danger';
        } elseif (empty($category)) {
            $message = "Category is required.";
            $messageType = 'danger';
        } elseif ($amount <= 0) {
            $message = "Amount must be greater than zero.";
            $messageType = 'danger';
        } else {
            // Insert expense record
            $insertQuery = "INSERT INTO expenses (description, amount, date, category) VALUES (?, ?, ?, ?)";
            $params = [$description, $amount, $date, $category];
            
            try {
                executeQuery($insertQuery, $params);
                $message = "Expense of ₱" . number_format($amount, 2) . " has been recorded successfully.";
                $messageType = 'success';
                
                // Redirect to clear the form
                header("Location: expenses_lending.php?message=$message&type=$messageType");
                exit;
            } catch (Exception $e) {
                $message = "Error recording expense: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
    
    // Process expense update
    if (isset($_POST['update_expense'])) {
        $expenseId = (int)$_POST['expense_id'];
        $description = trim($_POST['description']);
        $amount = (float)$_POST['amount'];
        $date = $_POST['date'];
        $category = trim($_POST['category'] ?? '');
        
        // Validate expense data
        if (empty($description)) {
            $message = "Description is required.";
            $messageType = 'danger';
        } elseif (empty($category)) {
            $message = "Category is required.";
            $messageType = 'danger';
        } elseif ($amount <= 0) {
            $message = "Amount must be greater than zero.";
            $messageType = 'danger';
        } else {
            // Update expense record
            $updateQuery = "UPDATE expenses SET description = ?, amount = ?, date = ?, category = ? WHERE expense_id = ?";
            $params = [$description, $amount, $date, $category, $expenseId];
            
            try {
                executeQuery($updateQuery, $params);
                $message = "Expense has been updated successfully.";
                $messageType = 'success';
                
                // Redirect to expenses list
                header("Location: expenses_lending.php?message=$message&type=$messageType");
                exit;
            } catch (Exception $e) {
                $message = "Error updating expense: " . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
    
    // Delete expense
    if (isset($_POST['delete_expense'])) {
        $expenseId = (int)$_POST['expense_id'];
        
        // Delete the expense
        $deleteQuery = "DELETE FROM expenses WHERE expense_id = ?";
        
        try {
            executeQuery($deleteQuery, [$expenseId]);
            $message = "Expense has been deleted successfully.";
            $messageType = 'success';
            
            // Redirect to expenses list
            header("Location: expenses_lending.php?message=$message&type=$messageType");
            exit;
        } catch (Exception $e) {
            $message = "Error deleting expense: " . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// Handle search and filtering
if (isset($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

// Check for messages passed via URL
if (isset($_GET['message']) && isset($_GET['type'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'];
}

// Build query based on search and filter
$expensesQuery = "SELECT * FROM expenses WHERE 1=1";
$queryParams = [];

if (!empty($searchTerm)) {
    $expensesQuery .= " AND description LIKE ?";
    $queryParams[] = "%$searchTerm%";
}

$expensesQuery .= " AND DATE(date) BETWEEN ? AND ?";
$queryParams[] = $startDate;
$queryParams[] = $endDate;

$expensesQuery .= " ORDER BY date DESC, expense_id DESC";

// Execute the query
$expensesResult = executeQuery($expensesQuery, $queryParams);
$expenses = $expensesResult->fetchAll();

$periodExpensesRow = executeQuery(
    'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS expense_count FROM expenses WHERE DATE(date) BETWEEN ? AND ?',
    [$startDate, $endDate]
)->fetch() ?: [];
$periodExpenses = (float)($periodExpensesRow['total'] ?? 0);
$periodExpenseCount = (int)($periodExpensesRow['expense_count'] ?? 0);

$periodCollectionRow = executeQuery(
    'SELECT COALESCE(SUM(amount_paid), 0) AS total FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?',
    [$startDate, $endDate]
)->fetch() ?: [];
$periodCollection = (float)($periodCollectionRow['total'] ?? 0);
$periodNetIncome = $periodCollection - $periodExpenses;

$totalExpensesAllTime = (float)(executeQuery('SELECT COALESCE(SUM(amount), 0) AS total FROM expenses')->fetch()['total'] ?? 0);

$formattedPeriodExpenses = number_format($periodExpenses, 2);
$formattedPeriodCollection = number_format($periodCollection, 2);
$formattedPeriodNetIncome = number_format($periodNetIncome, 2);
$formattedTotalExpenses = number_format($totalExpensesAllTime, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Expenses Management - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="app-content py-2">
        <?php if ($editExpense): ?>
        <!-- Edit Expense Form -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h1 class="h3 mb-0 text-gray-800">
                    <i class="fas fa-edit me-2"></i>Edit Expense
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="expenses_lending.php">Expenses</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit Expense #<?php echo $editExpense['expense_id']; ?></li>
                    </ol>
                </nav>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="expenses_lending.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Expenses
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold">Update Expense Information</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>
                        
                        <form action="expenses_lending.php" method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="update_expense" value="1">
                            <input type="hidden" name="expense_id" value="<?php echo $editExpense['expense_id']; ?>">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="description" name="description" value="<?php echo htmlspecialchars($editExpense['description']); ?>" required>
                                    <div class="invalid-feedback">Please enter a description.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($expenseCategories as $categoryOption): ?>
                                        <option value="<?php echo htmlspecialchars($categoryOption); ?>" <?php echo (trim($editExpense['category'] ?? '') === $categoryOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($categoryOption); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a category.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" value="<?php echo $editExpense['amount']; ?>" required>
                                        <div class="invalid-feedback">Please enter a valid amount.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date" name="date" value="<?php echo $editExpense['date']; ?>" required>
                                    <div class="invalid-feedback">Please select a date.</div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Update Expense
                                    </button>
                                    <a href="expenses_lending.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($addExpense): ?>
        <!-- Add Expense Form -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Record New Expense</h5>
                            <a href="expenses_lending.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Expenses
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php endif; ?>
                        
                        <form action="expenses_lending.php" method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="add_expense" value="1">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="description" name="description" placeholder="e.g., Office Rent, Utilities, Salaries" required>
                                    <div class="invalid-feedback">Please enter a description.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($expenseCategories as $categoryOption): ?>
                                        <option value="<?php echo htmlspecialchars($categoryOption); ?>"><?php echo htmlspecialchars($categoryOption); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Please select a category.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" required>
                                        <div class="invalid-feedback">Please enter a valid amount.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                                    <div class="invalid-feedback">Please select a date.</div>
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Record Expense
                                    </button>
                                    <a href="expenses_lending.php" class="btn btn-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Expenses List -->
        <?php
        echo renderAdminDashboardHeader(
            'Expenses Management',
            'Track operating spend against collections for clear cashflow visibility.',
            'fa-receipt',
            'Expenses',
            '<a href="expenses_lending.php?add=true" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Record Expense</a>'
        );
        ?>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php
        $periodFilterFormAction = 'expenses_lending.php';
        $periodFilterFormId = 'expenses-period-filter-form';
        $periodFilterHidden = array_filter(['search' => $searchTerm], fn($v) => $v !== '' && $v !== null);
        include 'includes/lending_period_filter.php';
        ?>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--danger">
                <div class="kpi-card__top"><div class="kpi-card__label">Expenses (period)</div><div class="kpi-card__icon"><i class="fas fa-receipt"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedPeriodExpenses; ?></div>
                <div class="kpi-card__meta"><?php echo (int)$periodExpenseCount; ?> entries · <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top"><div class="kpi-card__label">Collections (period)</div><div class="kpi-card__icon"><i class="fas fa-hand-holding-dollar"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedPeriodCollection; ?></div>
                <div class="kpi-card__meta">Payments received in range</div>
            </div>
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top"><div class="kpi-card__label">Net (period)</div><div class="kpi-card__icon"><i class="fas fa-chart-line"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedPeriodNetIncome; ?></div>
                <div class="kpi-card__meta">Collections minus expenses</div>
            </div>
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top"><div class="kpi-card__label">All-time expenses</div><div class="kpi-card__icon"><i class="fas fa-layer-group"></i></div></div>
                <div class="kpi-card__value">₱<?php echo $formattedTotalExpenses; ?></div>
                <div class="kpi-card__meta">Lifetime operating spend</div>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-search me-2 text-primary"></i>Search expenses</h6>
            </div>
            <div class="chart-panel__body pt-0">
                <form action="expenses_lending.php" method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="period_mode" value="<?php echo htmlspecialchars($periodMode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="report_year" value="<?php echo (int)$reportYear; ?>">
                    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="col-lg-10">
                        <label class="form-label small text-muted mb-2">Description</label>
                        <div class="input-group modern-input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search by description" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <button class="btn btn-primary" type="submit"><i class="fas fa-search me-1"></i> Search</button>
                        </div>
                    </div>
                    <div class="col-lg-2">
                        <a href="expenses_lending.php" class="btn btn-outline-secondary w-100 modern-reset-btn">Reset all</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Expenses list</h6>
            </div>
            <div class="chart-panel__body pt-0">
                <?php if (count($expenses) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo $expense['expense_id']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($expense['date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                <td><?php echo htmlspecialchars($expense['category'] ?? 'Uncategorized'); ?></td>
                                <td>₱<?php echo number_format($expense['amount'], 2); ?></td>
                                <td>
                                    <a href="expenses_lending.php?edit=<?php echo $expense['expense_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Expense">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-danger btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Expense" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $expense['expense_id']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    
                                    <!-- Delete Modal -->
                                    <div class="modal fade" id="deleteModal<?php echo $expense['expense_id']; ?>" tabindex="-1" aria-labelledby="deleteModalLabel<?php echo $expense['expense_id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="deleteModalLabel<?php echo $expense['expense_id']; ?>">Confirm Delete</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    Are you sure you want to delete this expense record?
                                                    <p class="mt-2 mb-0"><strong>Description:</strong> <?php echo htmlspecialchars($expense['description']); ?></p>
                                                    <p class="mb-0"><strong>Amount:</strong> ₱<?php echo number_format($expense['amount'], 2); ?></p>
                                                    <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($expense['date'])); ?></p>
                                                    <div class="alert alert-warning">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                        This action cannot be undone.
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <form action="expenses_lending.php" method="post">
                                                        <input type="hidden" name="delete_expense" value="1">
                                                        <input type="hidden" name="expense_id" value="<?php echo $expense['expense_id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-rounded btn-action-hover">Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-search text-muted fa-3x mb-3"></i>
                    <p class="lead">No expenses found</p>
                    <?php if (!empty($searchTerm)): ?>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="expenses_lending.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo me-1"></i> Reset Filters
                    </a>
                    <?php else: ?>
                    <a href="expenses_lending.php?add=true" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Record New Expense
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
<?php include 'includes/nav_footer_lending.php'; ?>
    
    <!-- Form Validation Script -->
    <script>
    (function () {
        'use strict'
        
        // Fetch all forms we want to apply validation styles to
        var forms = document.querySelectorAll('.needs-validation')
        
        // Loop over them and prevent submission
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault()
                    event.stopPropagation()
                }
                
                form.classList.add('was-validated')
            }, false)
        })
    })()
    </script>
</body>
</html>
