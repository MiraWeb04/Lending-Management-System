<?php
/**
 * Collector Management Page for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireAdmin();

require_once 'includes/db_lending.php';

$errorMsg = '';
$successMsg = '';

function ensureCollectorSchema() {
    global $conn;

    $columns = [];
    $columnStmt = $conn->query('SHOW COLUMNS FROM users');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['employee_id'])) {
        $conn->exec("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) NULL UNIQUE AFTER user_id");
    }

    if (!isset($columns['contact_number'])) {
        $conn->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL AFTER email");
    }

    if (!isset($columns['date_created'])) {
        $conn->exec("ALTER TABLE users ADD COLUMN date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status");
    }

    $clientColumns = [];
    $clientColumnStmt = $conn->query('SHOW COLUMNS FROM clients');
    foreach ($clientColumnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $clientColumns[$column['Field']] = true;
    }

    if (!isset($clientColumns['collector_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN collector_id INT NULL DEFAULT NULL AFTER contact');
    }
}

function generateCollectorEmployeeId(PDO $conn): string {
    $stmt = $conn->query("SELECT employee_id FROM users WHERE employee_id LIKE 'COL-%' ORDER BY CAST(SUBSTRING(employee_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $lastId = $stmt->fetchColumn();

    if ($lastId) {
        $number = (int)substr($lastId, 4);
        $number++;
    } else {
        $number = 1;
    }

    return 'COL-' . str_pad($number, 4, '0', STR_PAD_LEFT);
}

function buildCollectorQueryString(array $overrides = []): string {
    $params = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return http_build_query($params);
}

ensureCollectorSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_collector'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($fullName === '' || $username === '' || $email === '' || $password === '' || $confirmPassword === '') {
            $errorMsg = 'Please complete all required fields.';
        } elseif ($password !== $confirmPassword) {
            $errorMsg = 'Passwords do not match.';
        } else {
            $existing = executeQuery('SELECT user_id FROM users WHERE username = ? LIMIT 1', [$username]);
            if ($existing->rowCount() > 0) {
                $errorMsg = 'That username is already taken.';
            } else {
                $employeeId = generateCollectorEmployeeId($conn);
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                try {
                    executeQuery(
                        'INSERT INTO users (employee_id, username, password, full_name, email, contact_number, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$employeeId, $username, $hashedPassword, $fullName, $email, $contactNumber, 'Collector', 'Active']
                    );
                    $successMsg = 'Collector account created successfully.';
                } catch (PDOException $e) {
                    $errorMsg = 'Unable to create collector account: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['edit_collector'])) {
        $collectorId = (int)($_POST['collector_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');

        if ($collectorId <= 0 || $fullName === '' || $username === '' || $email === '') {
            $errorMsg = 'Please provide the required collector information.';
        } else {
            $duplicate = executeQuery('SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1', [$username, $collectorId]);
            if ($duplicate->rowCount() > 0) {
                $errorMsg = 'That username is already used by another collector.';
            } else {
                try {
                    executeQuery(
                        'UPDATE users SET full_name = ?, username = ?, email = ?, contact_number = ? WHERE user_id = ? AND role = ?',
                        [$fullName, $username, $email, $contactNumber, $collectorId, 'Collector']
                    );
                    $successMsg = 'Collector details updated successfully.';
                } catch (PDOException $e) {
                    $errorMsg = 'Unable to update collector details: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['toggle_status'])) {
        $collectorId = (int)($_POST['collector_id'] ?? 0);
        $newStatus = $_POST['status'] === 'Inactive' ? 'Inactive' : 'Active';

        if ($collectorId > 0) {
            try {
                executeQuery('UPDATE users SET status = ? WHERE user_id = ? AND role = ?', [$newStatus, $collectorId, 'Collector']);
                $successMsg = 'Collector status updated.';
            } catch (PDOException $e) {
                $errorMsg = 'Unable to change collector status: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        $collectorId = (int)($_POST['collector_id'] ?? 0);
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($collectorId <= 0 || $password === '' || $confirmPassword === '') {
            $errorMsg = 'Please enter and confirm a new password.';
        } elseif ($password !== $confirmPassword) {
            $errorMsg = 'Passwords do not match.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            try {
                executeQuery('UPDATE users SET password = ? WHERE user_id = ? AND role = ?', [$hashedPassword, $collectorId, 'Collector']);
                $successMsg = 'Collector password reset successfully.';
            } catch (PDOException $e) {
                $errorMsg = 'Unable to reset collector password: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_collector'])) {
        $collectorId = (int)($_POST['collector_id'] ?? 0);
        $currentUserId = (int)($currentUser['user_id'] ?? 0);

        if ($collectorId <= 0) {
            $errorMsg = 'Please select a collector to delete.';
        } elseif ($collectorId === $currentUserId) {
            $errorMsg = 'You cannot delete your own admin account.';
        } else {
            $assignedClients = executeQuery('SELECT COUNT(*) AS total FROM clients WHERE collector_id = ?', [$collectorId]);
            $assignedCount = (int)$assignedClients->fetchColumn();

            if ($assignedCount > 0) {
                $errorMsg = 'This collector cannot be deleted because assigned clients still exist.';
            } else {
                try {
                    executeQuery('DELETE FROM users WHERE user_id = ? AND role = ?', [$collectorId, 'Collector']);
                    $successMsg = 'Collector deleted successfully.';
                } catch (PDOException $e) {
                    $errorMsg = 'Unable to delete collector: ' . $e->getMessage();
                }
            }
        }
    }
}

$searchTerm = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClauses = ["role = 'Collector'"];
$queryParams = [];

if ($searchTerm !== '') {
    $whereClauses[] = '(employee_id LIKE ? OR full_name LIKE ? OR username LIKE ? OR email LIKE ? OR contact_number LIKE ?)';
    $pattern = "%$searchTerm%";
    $queryParams = array_merge($queryParams, [$pattern, $pattern, $pattern, $pattern, $pattern]);
}

if ($statusFilter !== '' && in_array($statusFilter, ['Active', 'Inactive'], true)) {
    $whereClauses[] = 'status = ?';
    $queryParams[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

$countStmt = executeQuery("SELECT COUNT(*) AS total FROM users $whereSql", $queryParams);
$totalCollectors = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCollectors / $limit));

$collectorsSql = "SELECT * FROM users $whereSql ORDER BY date_created DESC, full_name ASC LIMIT $offset, $limit";
$collectorsStmt = executeQuery($collectorsSql, $queryParams);
$collectors = $collectorsStmt->fetchAll(PDO::FETCH_ASSOC);

$viewCollector = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    if ($viewId > 0) {
        $viewStmt = executeQuery('SELECT * FROM users WHERE user_id = ? AND role = ? LIMIT 1', [$viewId, 'Collector']);
        $viewCollector = $viewStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$currentUser = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collectors - Lending Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/nav_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title"><i class="fas fa-user-tie me-2"></i>Collector Management</h1>
                <p class="page-subtitle">Admin-only collector account administration for field collection operations.</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollectorModal" aria-label="Add Collector">
                <i class="fas fa-plus me-2"></i>Add Collector
            </button>
        </div>

        <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($errorMsg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($successMsg !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($successMsg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($viewCollector): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-3">
                        <div>
                            <h5 class="section-title mb-2">Collector Details</h5>
                            <p class="text-muted mb-1">Viewing <?php echo htmlspecialchars($viewCollector['full_name']); ?></p>
                        </div>
                        <a href="collectors_lending.php" class="btn btn-outline-secondary btn-sm">Close</a>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3"><strong>Employee ID:</strong><br><?php echo htmlspecialchars($viewCollector['employee_id'] ?? 'Pending'); ?></div>
                        <div class="col-md-3"><strong>Full Name:</strong><br><?php echo htmlspecialchars($viewCollector['full_name']); ?></div>
                        <div class="col-md-3"><strong>Username:</strong><br><?php echo htmlspecialchars($viewCollector['username']); ?></div>
                        <div class="col-md-3"><strong>Status:</strong><br><span class="badge status-badge bg-<?php echo strtolower($viewCollector['status'] ?? 'inactive') === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($viewCollector['status'] ?? 'Inactive'); ?></span></div>
                        <div class="col-md-3"><strong>Email:</strong><br><?php echo htmlspecialchars($viewCollector['email'] ?? '-'); ?></div>
                        <div class="col-md-3"><strong>Contact Number:</strong><br><?php echo htmlspecialchars($viewCollector['contact_number'] ?? '-'); ?></div>
                        <div class="col-md-3"><strong>Date Created:</strong><br><?php echo htmlspecialchars($viewCollector['date_created'] ?? '-'); ?></div>
                        <div class="col-md-3"><strong>Last Login:</strong><br><?php echo htmlspecialchars($viewCollector['last_login'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name, username, email, employee ID">
                    </div>
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All</option>
                            <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $statusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                    <div class="col-md-2">
                        <a href="collectors_lending.php" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee ID</th>
                                <th>Collector</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Date Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($collectors)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No collector accounts found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($collectors as $collector): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($collector['employee_id'] ?? 'Pending'); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($collector['full_name']); ?></div>
                                            <div class="small text-muted">@<?php echo htmlspecialchars($collector['username']); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($collector['email'] ?? '-'); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($collector['contact_number'] ?? '-'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge status-badge bg-<?php echo strtolower($collector['status'] ?? 'inactive') === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo htmlspecialchars($collector['status'] ?? 'Inactive'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($collector['date_created'] ?? '-'); ?></td>
                                        <td class="text-end">
                                                                <div class="btn-group btn-group-sm" role="group">
                                                <a href="collectors_lending.php?view_id=<?php echo (int)$collector['user_id']; ?>" class="btn btn-outline-primary collector-action-button" aria-label="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-secondary collector-action-button" data-bs-toggle="modal" data-bs-target="#editCollectorModal<?php echo (int)$collector['user_id']; ?>" aria-label="Edit Collector">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="collector_id" value="<?php echo (int)$collector['user_id']; ?>">
                                                    <input type="hidden" name="status" value="<?php echo (strtolower($collector['status'] ?? 'inactive') === 'active') ? 'Inactive' : 'Active'; ?>">
                                                    <button type="submit" name="toggle_status" class="btn btn-outline-<?php echo (strtolower($collector['status'] ?? 'inactive') === 'active') ? 'warning' : 'success'; ?> collector-action-button" aria-label="Change Status">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-outline-info collector-action-button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo (int)$collector['user_id']; ?>" aria-label="Reset Password">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger confirm-delete collector-action-button" data-bs-toggle="modal" data-bs-target="#deleteCollectorModal<?php echo (int)$collector['user_id']; ?>" aria-label="Delete Collector">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="mt-4" aria-label="Collector pagination">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="collectors_lending.php?<?php echo buildCollectorQueryString(['page' => $i]); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="addCollectorModal" tabindex="-1" aria-labelledby="addCollectorModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addCollectorModalLabel">Add Collector</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <input type="text" class="form-control" id="contact_number" name="contact_number">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <div class="alert alert-info mb-0">Collector accounts can only be created by Admin users.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_collector" class="btn btn-primary">Create Collector</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($collectors as $collector): ?>
        <div class="modal fade" id="editCollectorModal<?php echo (int)$collector['user_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="collector_id" value="<?php echo (int)$collector['user_id']; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Collector</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($collector['full_name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($collector['username']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($collector['email'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact_number" value="<?php echo htmlspecialchars($collector['contact_number'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="edit_collector" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="resetPasswordModal<?php echo (int)$collector['user_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="collector_id" value="<?php echo (int)$collector['user_id']; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Reset Password</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteCollectorModal<?php echo (int)$collector['user_id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="collector_id" value="<?php echo (int)$collector['user_id']; ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Delete Collector</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Delete <strong><?php echo htmlspecialchars($collector['full_name']); ?></strong>?</p>
                            <p class="text-muted mb-0">Deletion is only allowed when no assigned clients remain.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="delete_collector" class="btn btn-danger">Delete Collector</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/lending.js"></script>
</body>
</html>
