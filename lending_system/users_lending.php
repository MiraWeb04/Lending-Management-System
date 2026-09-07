<?php
/**
 * Users Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require admin access to this page
requireAdmin();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';

// Initialize variables
$errorMsg = '';
$successMsg = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new user
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $confirmPassword = trim($_POST['confirm_password']);
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = trim($_POST['role']);
        
        // Validate input
        if (empty($username) || empty($password) || empty($fullName) || empty($email)) {
            $errorMsg = 'All fields are required';
        } elseif ($password !== $confirmPassword) {
            $errorMsg = 'Passwords do not match';
        } else {
            // Check if username already exists
            $checkQuery = "SELECT user_id FROM users WHERE username = ?";
            $checkResult = executeQuery($checkQuery, [$username]);
            
            if ($checkResult->rowCount() > 0) {
                $errorMsg = 'Username already exists';
            } else {
                // Hash password
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Normalize role to match DB enum values (e.g. Admin, Collector, Borrower)
$roleDb = normalizeUserRole($role);

                // Insert new user and set default status to Active
                $insertQuery = "INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)";
                $insertParams = [$username, $hashedPassword, $fullName, $email, $roleDb, 'Active'];
                
                try {
                    executeQuery($insertQuery, $insertParams);
                    $successMsg = 'User added successfully';
                } catch (PDOException $e) {
                    $errorMsg = 'Error adding user: ' . $e->getMessage();
                }
            }
        }
    }
    
    // Update user
    elseif (isset($_POST['update_user'])) {
        $userId = $_POST['user_id'];
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $role = trim($_POST['role']);
        $activeFlag = isset($_POST['active']) ? 'Active' : 'Inactive';
        
        // Validate input
        if (empty($fullName) || empty($email)) {
            $errorMsg = 'Name and email are required';
        } else {
            // Normalize role to DB enum
            $roleDb = normalizeUserRole($role);

            // Update user (use `status` column in the database)
            $updateQuery = "UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE user_id = ?";
            $updateParams = [$fullName, $email, $roleDb, $activeFlag, $userId];
            
            try {
                executeQuery($updateQuery, $updateParams);
                $successMsg = 'User updated successfully';
            } catch (PDOException $e) {
                $errorMsg = 'Error updating user: ' . $e->getMessage();
            }
        }
    }
    
    // Reset password
    elseif (isset($_POST['reset_password'])) {
        $userId = $_POST['user_id'];
        $password = trim($_POST['password']);
        $confirmPassword = trim($_POST['confirm_password']);
        
        // Validate input
        if (empty($password)) {
            $errorMsg = 'Password is required';
        } elseif ($password !== $confirmPassword) {
            $errorMsg = 'Passwords do not match';
        } else {
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password
            $updateQuery = "UPDATE users SET password = ? WHERE user_id = ?";
            $updateParams = [$hashedPassword, $userId];
            
            try {
                executeQuery($updateQuery, $updateParams);
                $successMsg = 'Password reset successfully';
            } catch (PDOException $e) {
                $errorMsg = 'Error resetting password: ' . $e->getMessage();
            }
        }
    }
    
    // Delete user
    elseif (isset($_POST['delete_user'])) {
        $userId = $_POST['user_id'];
        
        // Prevent deleting your own account
        if ($userId == $user['user_id']) {
            $errorMsg = 'You cannot delete your own account';
        } else {
            // Delete user
            $deleteQuery = "DELETE FROM users WHERE user_id = ?";
            $deleteParams = [$userId];
            
            try {
                executeQuery($deleteQuery, $deleteParams);
                $successMsg = 'User deleted successfully';
            } catch (PDOException $e) {
                $errorMsg = 'Error deleting user: ' . $e->getMessage();
            }
        }
    }
}

// Get user filters and pagination
$searchTerm = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$whereClauses = [];
$queryParams = [];

if ($searchTerm !== '') {
    $whereClauses[] = "(username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $searchPattern = "%$searchTerm%";
    $queryParams = array_merge($queryParams, [$searchPattern, $searchPattern, $searchPattern]);
}

if ($roleFilter !== '' && in_array(strtolower($roleFilter), ['admin', 'collector', 'borrower'], true)) {
    $whereClauses[] = "LOWER(role) = ?";
    $queryParams[] = strtolower($roleFilter);
}

if ($statusFilter !== '' && in_array(strtolower($statusFilter), ['active', 'inactive'], true)) {
    $whereClauses[] = "LOWER(status) = ?";
    $queryParams[] = strtolower($statusFilter);
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

$countQuery = "SELECT COUNT(*) as total FROM users $whereSql";
$countResult = executeQuery($countQuery, $queryParams);
$totalUsers = $countResult->fetch()['total'] ?? 0;
$totalPages = max(1, (int)ceil($totalUsers / $limit));

$usersQuery = "SELECT * FROM users $whereSql ORDER BY username LIMIT $offset, $limit";
$usersResult = executeQuery($usersQuery, $queryParams);
$users = $usersResult->fetchAll();

function getInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials ?: 'U';
}

function buildQueryString($overrides = []) {
    global $searchTerm, $roleFilter, $statusFilter, $page;
    $params = [
        'search' => $searchTerm,
        'role' => $roleFilter,
        'status' => $statusFilter,
        'page' => $page,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return http_build_query($params);
}

function getActiveFlag(array $row) {
    // Prefer numeric `active` if present
    if (array_key_exists('active', $row)) {
        return (int)$row['active'];
    }

    // Fallback to `status` column (case-insensitive)
    if (array_key_exists('status', $row) && is_string($row['status'])) {
        return (strtolower($row['status']) === 'active') ? 1 : 0;
    }

    // Default to inactive
    return 0;
}

function normalizeUserRole(string $role): string {
    $normalized = trim($role);
    if ($normalized === '') {
        return 'Borrower';
    }

    $normalized = strtolower($normalized);

    if ($normalized === 'admin') {
        return 'Admin';
    }

    if ($normalized === 'collector') {
        return 'Collector';
    }

    if ($normalized === 'borrower') {
        return 'Borrower';
    }

    return ucfirst($normalized);
}

function getUserRoleBadgeLabel(string $role): string {
    $normalized = strtolower(trim($role));

    if ($normalized === 'admin') {
        return 'Administrator';
    }

    if ($normalized === 'collector') {
        return 'Collector';
    }

    if ($normalized === 'borrower') {
        return 'Borrower';
    }

    return ucfirst($normalized);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Lending Management System</title>
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
        <div class="page-hero">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-user-cog me-2"></i>User Management
                </h1>
                <p class="page-subtitle">Manage system users and access control with a polished administrative workflow.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo $errorMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?php echo $successMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Filter Panel -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="row gx-3 gy-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search users</label>
                        <input type="search" class="form-control" id="search" name="search" placeholder="Search by name, username, or email" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role">
                            <option value="">All roles</option>
                            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            <option value="collector" <?php echo $roleFilter === 'collector' ? 'selected' : ''; ?>>Collector</option>
                            <option value="borrower" <?php echo $roleFilter === 'borrower' ? 'selected' : ''; ?>>Borrower</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="users_lending.php" class="btn btn-outline-secondary mt-2">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-users me-2"></i>System Users</h6>
                    <small class="text-muted">Showing <?php echo count($users); ?> of <?php echo $totalUsers; ?> users</small>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-primary btn-rounded btn-action-hover" data-bs-toggle="modal" data-bs-target="#addUserModal" aria-label="Add New User">
                        <i class="fas fa-user-plus me-1"></i> Add New User
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($users) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-borderless table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $userItem): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:44px; height:44px; font-weight:600;">
                                            <?php echo getInitials($userItem['full_name']); ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold mb-0"><?php echo htmlspecialchars($userItem['full_name']); ?></div>
                                            <div class="text-muted small">@<?php echo htmlspecialchars($userItem['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($userItem['email']); ?></td>
                                <td>
                                    <?php if (strcasecmp($userItem['role'], 'admin') === 0): ?>
                                        <span class="badge role-badge bg-danger">Administrator</span>
                                    <?php elseif (strcasecmp($userItem['role'], 'collector') === 0): ?>
                                        <span class="badge role-badge bg-primary">Collector</span>
                                    <?php else: ?>
                                        <span class="badge role-badge bg-info">Borrower</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (getActiveFlag($userItem) == 1): ?>
                                        <span class="badge bg-success text-white">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary text-white">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($userItem['last_login']): ?>
                                        <?php echo date('M d, Y h:i A', strtotime($userItem['last_login'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                    <td class="text-end">
                                    <div class="btn-group" role="group" aria-label="Actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-rounded btn-action-hover" 
                                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                                            title="Edit User"
                                            data-user-id="<?php echo $userItem['user_id']; ?>"
                                            data-username="<?php echo htmlspecialchars($userItem['username']); ?>"
                                            data-fullname="<?php echo htmlspecialchars($userItem['full_name']); ?>"
                                            data-email="<?php echo htmlspecialchars($userItem['email']); ?>"
                                            data-role="<?php echo htmlspecialchars($userItem['role']); ?>"
                                            data-active="<?php echo getActiveFlag($userItem); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-warning btn-rounded btn-action-hover"
                                            data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                            title="Reset Password"
                                            data-user-id="<?php echo $userItem['user_id']; ?>"
                                            data-username="<?php echo htmlspecialchars($userItem['username']); ?>">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <?php if ($userItem['user_id'] != $user['user_id']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-rounded btn-action-hover"
                                            data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                            title="Delete User"
                                            data-user-id="<?php echo $userItem['user_id']; ?>"
                                            data-username="<?php echo htmlspecialchars($userItem['username']); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <div class="mb-3">
                        <i class="fas fa-user-slash fa-3x text-secondary"></i>
                    </div>
                    <h5 class="mb-2">No users found</h5>
                    <p class="text-muted mb-4">Try clearing filters or add a new user to begin managing accounts.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal" aria-label="Add User">
                        <i class="fas fa-user-plus me-1"></i> Add New User
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($totalPages > 1 && count($users) > 0): ?>
                <nav aria-label="Users pagination">
                    <ul class="pagination justify-content-end mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $page - 1]); ?>" tabindex="-1">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $p]); ?>"><?php echo $p; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildQueryString(['page' => $page + 1]); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="users_lending.php" method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="collector">Collector</option>
                                <option value="admin">Administrator</option>
                                <option value="borrower">Borrower</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="users_lending.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" id="edit_user_id" name="user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" name="username" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Role</label>
                            <select class="form-select" id="edit_role" name="role" required>
                                <option value="Collector">Collector</option>
                                <option value="Admin">Administrator</option>
                                <option value="Borrower">Borrower</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="edit_active" name="active">
                            <label class="form-check-label" for="edit_active">Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="users_lending.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" id="reset_user_id" name="user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="reset_username" name="username" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="reset_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="reset_password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="reset_confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="reset_confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-warning">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="users_lending.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" id="delete_user_id" name="user_id">
                        <p>Are you sure you want to delete the user <strong id="delete_username"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
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
    
    <!-- Custom Scripts -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Edit User Modal
        const editUserModal = document.getElementById('editUserModal');
        if (editUserModal) {
            editUserModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                const fullName = button.getAttribute('data-fullname');
                const email = button.getAttribute('data-email');
                const role = button.getAttribute('data-role');
                const active = button.getAttribute('data-active');
                
                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_full_name').value = fullName;
                document.getElementById('edit_email').value = email;
                document.getElementById('edit_role').value = role;
                document.getElementById('edit_active').checked = active == 1;
            });
        }
        
        // Reset Password Modal
        const resetPasswordModal = document.getElementById('resetPasswordModal');
        if (resetPasswordModal) {
            resetPasswordModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                
                document.getElementById('reset_user_id').value = userId;
                document.getElementById('reset_username').value = username;
            });
        }
        
        // Delete User Modal
        const deleteUserModal = document.getElementById('deleteUserModal');
        if (deleteUserModal) {
            deleteUserModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                
                document.getElementById('delete_user_id').value = userId;
                document.getElementById('delete_username').textContent = username;
            });
        }
    });
    </script>
</body>
</html>
