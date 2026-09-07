<?php
/**
 * Expenses Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

if (isCollector()) {
    denyCollectorAccess('Collectors cannot access the expenses module.');
}

// Include database connection
require_once 'includes/db_lending.php';

ensureExpensesSchema();

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
$dateFilter = '';

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

if (isset($_GET['date'])) {
    $dateFilter = trim($_GET['date']);
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

if (!empty($dateFilter)) {
    $expensesQuery .= " AND DATE(date) = ?";
    $queryParams[] = $dateFilter;
}

$expensesQuery .= " ORDER BY date DESC, expense_id DESC";

// Execute the query
$expensesResult = executeQuery($expensesQuery, $queryParams);
$expenses = $expensesResult->fetchAll();

// Get today's date for default filter
$today = date('Y-m-d');

// Get today's total expenses
$todayExpensesQuery = "SELECT SUM(amount) as total FROM expenses WHERE DATE(date) = ?";
$todayExpensesResult = executeQuery($todayExpensesQuery, [$today]);
$todayExpenses = $todayExpensesResult->fetch()['total'] ?? 0;

// Get total expenses for all time
$totalExpensesQuery = "SELECT SUM(amount) as total FROM expenses";
$totalExpensesResult = executeQuery($totalExpensesQuery);
$totalExpenses = $totalExpensesResult->fetch()['total'] ?? 0;

// Get today's total collection
$todayCollectionQuery = "SELECT SUM(amount_paid) as total FROM payments WHERE DATE(payment_date) = ?";
$todayCollectionResult = executeQuery($todayCollectionQuery, [$today]);
$todayCollection = $todayCollectionResult->fetch()['total'] ?? 0;

// Calculate today's net income
$todayNetIncome = $todayCollection - $todayExpenses;

// Format currency values
$formattedTodayExpenses = number_format($todayExpenses, 2);
$formattedTotalExpenses = number_format($totalExpenses, 2);
$formattedTodayCollection = number_format($todayCollection, 2);
$formattedTodayNetIncome = number_format($todayNetIncome, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses Management - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4">
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
        <div class="row mb-4">
            <div class="col-md-6">
                <h1 class="h3 mb-0 text-gray-800">
                    <i class="fas fa-receipt me-2"></i>Expenses Management
                </h1>
                <p class="mb-0">Manage all expense records in the system</p>
            </div>
            <div class="col-md-6 text-md-end">
                <a href="expenses_lending.php?add=true" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Record Expense
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Expense Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-4">
                <div class="card border-left-danger shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Today's Expenses</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo $formattedTodayExpenses; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-4">
                <div class="card border-left-warning shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Expenses</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo $formattedTotalExpenses; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-4">
                <div class="card border-left-success shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Today's Collection</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo $formattedTodayCollection; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-4">
                <div class="card border-left-primary shadow-sm h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Today's Net Income</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo $formattedTodayNetIncome; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-search me-2"></i>Search & Filter Expenses</h6>
            </div>
            <div class="card-body">
                <form action="expenses_lending.php" method="get" class="row g-3">
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" placeholder="Search by description" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text">Date</span>
                            <input type="date" class="form-control" name="date" value="<?php echo $dateFilter; ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <a href="expenses_lending.php" class="btn btn-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expenses Table -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-list me-2"></i>Expenses List</h6>
            </div>
            <div class="card-body">
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
                    <?php if (!empty($searchTerm) || !empty($dateFilter)): ?>
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

        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between small">
                <div class="text-muted">Copyright &copy; Lending Management System <?php echo date('Y'); ?></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
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
