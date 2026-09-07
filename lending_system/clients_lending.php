<?php
/**
 * Clients Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';

function ensureClientCollectorColumn() {
    global $conn;

    $columns = [];
    $columnStmt = $conn->query('SHOW COLUMNS FROM clients');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['collector_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN collector_id INT NULL DEFAULT NULL AFTER contact');
    }
}

ensureClientCollectorColumn();

$collectorUserId = (int)($user['user_id'] ?? 0);
if (isCollector() && $collectorUserId <= 0) {
    denyCollectorAccess('Unable to identify your collector account.');
}

$activeCollectors = [];
if (isAdmin()) {
    $collectorsQuery = "SELECT user_id, full_name, employee_id FROM users WHERE role = 'Collector' AND status = 'Active' ORDER BY full_name ASC";
    $collectorsResult = executeQuery($collectorsQuery);
    $activeCollectors = $collectorsResult->fetchAll();
}

// Initialize variables
$message = '';
$messageType = '';
$searchTerm = '';

// Process search
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = trim($_GET['search']);
}

// Process client deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $clientId = (int)$_GET['delete'];

    function deleteClientCascade(int $clientId): bool {
        global $conn;

        try {
            $conn->beginTransaction();

            $clientRow = executeQuery('SELECT client_id, user_id FROM clients WHERE client_id = ? LIMIT 1', [$clientId])->fetch(PDO::FETCH_ASSOC);
            $clientUserId = $clientRow['user_id'] ?? null;

            $loanRows = executeQuery('SELECT loan_id, release_id FROM loans WHERE client_id = ?', [$clientId])->fetchAll(PDO::FETCH_ASSOC);
            $loanIds = [];
            $releaseIds = [];

            foreach ($loanRows as $loanRow) {
                if (!empty($loanRow['loan_id'])) {
                    $loanIds[] = (int)$loanRow['loan_id'];
                }
                if (!empty($loanRow['release_id'])) {
                    $releaseIds[] = (int)$loanRow['release_id'];
                }
            }

            if (!empty($loanIds)) {
                $placeholders = implode(', ', array_fill(0, count($loanIds), '?'));
                executeQuery("DELETE FROM payments WHERE loan_id IN ($placeholders)", $loanIds);
            }

            if (!empty($releaseIds)) {
                $placeholders = implode(', ', array_fill(0, count($releaseIds), '?'));
                executeQuery("DELETE FROM loan_payment_schedules WHERE release_id IN ($placeholders)", $releaseIds);
                executeQuery("DELETE FROM loan_releases WHERE id IN ($placeholders)", $releaseIds);
            }

            executeQuery('DELETE FROM loans WHERE client_id = ?', [$clientId]);

            if (!empty($clientUserId)) {
                $applicationRows = executeQuery('SELECT id FROM loan_applications WHERE user_id = ?', [$clientUserId])->fetchAll(PDO::FETCH_ASSOC);
                $applicationIds = array_column($applicationRows, 'id');

                if (!empty($applicationIds)) {
                    $appPlaceholders = implode(', ', array_fill(0, count($applicationIds), '?'));
                    executeQuery("DELETE FROM loan_application_documents WHERE application_id IN ($appPlaceholders)", $applicationIds);
                    executeQuery("DELETE FROM loan_application_reviews WHERE application_id IN ($appPlaceholders)", $applicationIds);
                    executeQuery("DELETE FROM loan_agreements WHERE application_id IN ($appPlaceholders)", $applicationIds);
                    executeQuery("DELETE FROM loan_applications WHERE id IN ($appPlaceholders)", $applicationIds);
                }

                executeQuery('DELETE FROM borrower_applications WHERE user_id = ?', [$clientUserId]);
                executeQuery('DELETE FROM notifications WHERE user_id = ?', [$clientUserId]);
                executeQuery('DELETE FROM loan_release_notifications WHERE user_id = ?', [$clientUserId]);
                executeQuery('DELETE FROM users WHERE user_id = ?', [$clientUserId]);
            }

            executeQuery('DELETE FROM clients WHERE client_id = ?', [$clientId]);

            $conn->commit();
            return true;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('Client cascade delete failed: ' . $e->getMessage());
            return false;
        }
    }

    $deleteResult = deleteClientCascade($clientId);

    if ($deleteResult) {
        $message = "Client and related borrower data were permanently deleted successfully.";
        $messageType = 'success';
    } else {
        $message = "Failed to delete client data. Please try again.";
        $messageType = 'danger';
    }
}


// Process client form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isCollector()) {
        $message = 'Collectors can only view assigned clients.';
        $messageType = 'danger';
    } else {
    // Sanitize and validate input
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    $collectorId = isset($_POST['collector_id']) && $_POST['collector_id'] !== '' ? (int)$_POST['collector_id'] : null;
    $isAdmin = isAdmin();
    
    // Validate required fields
    if (!$isAdmin && isset($_POST['collector_id']) && $_POST['collector_id'] !== '') {
        $message = "Only administrators can assign collectors.";
        $messageType = 'danger';
    } elseif (empty($firstName) || empty($lastName) || empty($address) || empty($contact)) {
        $message = "Please fill in all required fields.";
        $messageType = 'danger';
    } else {
        // Prepare client data
        $clientData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'address' => $address,
            'contact' => $contact,
            'email' => $email
        ];

        if ($isAdmin) {
            $clientData['collector_id'] = $collectorId;
        }
        
        // Update or insert client
        if ($clientId > 0) {
            // Update existing client
            $updateResult = update('clients', $clientData, 'client_id = ?', [$clientId]);
            
            if ($updateResult) {
                $message = "Client updated successfully.";
                $messageType = 'success';
            } else {
                $message = "Failed to update client.";
                $messageType = 'danger';
            }
        } else {
            // Add date_registered for new clients
            $clientData['date_registered'] = date('Y-m-d');
            
            // Insert new client
            $insertId = insert('clients', $clientData);
            
            if ($insertId) {
                $message = "Client added successfully.";
                $messageType = 'success';
            } else {
                $message = "Failed to add client.";
                $messageType = 'danger';
            }
        }
    }
    }
}

// Get clients with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build query based on search term
$whereClause = '';
$params = [];

if (isCollector()) {
    $whereClause = 'c.collector_id = ?';
    $params = [$collectorUserId];

    if (!empty($searchTerm)) {
        $whereClause .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR u.full_name LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }
} else {
    if (!empty($searchTerm)) {
        $whereClause = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR u.full_name LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    }
}

// Get total clients count for pagination
$countQuery = "SELECT COUNT(*) as total FROM clients c LEFT JOIN users u ON c.collector_id = u.user_id AND u.role = 'Collector'";
if (!empty($whereClause)) {
    $countQuery .= " WHERE $whereClause";
}
$countResult = executeQuery($countQuery, $params);
$totalClients = $countResult->fetch()['total'];
$totalPages = ceil($totalClients / $limit);

// Get clients for current page
$clientsQuery = "SELECT c.*, u.full_name AS collector_name FROM clients c LEFT JOIN users u ON c.collector_id = u.user_id AND u.role = 'Collector'";
if (!empty($whereClause)) {
    $clientsQuery .= " WHERE $whereClause";
}
$clientsQuery .= " ORDER BY c.last_name, c.first_name LIMIT $offset, $limit";
$clientsResult = executeQuery($clientsQuery, $params);
$clients = $clientsResult->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients - Lending Management System</title>
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
                <h1 class="page-title"><i class="fas fa-users me-2"></i>Client Management</h1>
                <p class="page-subtitle">Track assigned clients and keep account records organized.</p>
            </div>
            <?php if (!isCollector()): ?>
            <div class="page-header-actions">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
                    <i class="fas fa-plus me-1"></i> Add New Client
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Search and Filter -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form action="" method="get" class="row g-3">
                    <div class="col-md-10">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="Search by name, contact, or email" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Clients Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold">Client List</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Collector</th>
                                <th>Address</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($clients) > 0): ?>
                                <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td><?php echo $client['client_id']; ?></td>
                                    <td><?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($client['contact']); ?></td>
                                    <td><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo !empty($client['collector_name']) ? htmlspecialchars($client['collector_name']) : '<span class="text-muted">Unassigned</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($client['address']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($client['date_registered'])); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="client_details_lending.php?id=<?php echo $client['client_id']; ?>" class="btn btn-sm btn-info btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Client">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (!isCollector()): ?>
                                            <button type="button" class="btn btn-sm btn-primary btn-rounded btn-action-hover edit-client-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editClientModal"
                                                data-client-id="<?php echo $client['client_id']; ?>"
                                                data-first-name="<?php echo htmlspecialchars($client['first_name']); ?>"
                                                data-last-name="<?php echo htmlspecialchars($client['last_name']); ?>"
                                                data-address="<?php echo htmlspecialchars($client['address']); ?>"
                                                data-contact="<?php echo htmlspecialchars($client['contact']); ?>"
                                                data-email="<?php echo htmlspecialchars($client['email'] ?? ''); ?>"
                                                data-collector-id="<?php echo !empty($client['collector_id']) ? (int)$client['collector_id'] : ''; ?>"
                                                data-bs-toggle="tooltip" data-bs-placement="top" title="Edit Client">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="loans_lending.php?client=<?php echo $client['client_id']; ?>" class="btn btn-sm btn-success btn-rounded btn-action-hover" data-bs-toggle="tooltip" data-bs-placement="top" title="View Loans">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger btn-rounded btn-action-hover delete-client-btn" data-bs-toggle="tooltip" data-bs-placement="top" title="Delete Client" data-client-id="<?php echo $client['client_id']; ?>" data-client-name="<?php echo htmlspecialchars($client['first_name'] . ' ' . $client['last_name']); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-users text-muted fa-3x mb-3"></i>
                                        <p>No clients found. <?php echo !empty($searchTerm) ? 'Try a different search term.' : 'Add your first client to get started.'; ?></p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!isCollector()): ?>
    <!-- Add Client Modal -->
    <div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addClientModalLabel"><i class="fas fa-user-plus me-2"></i>Add New Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="post">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="contact" name="contact" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                            <div class="col-12">
                                <label for="collector_id" class="form-label">Assigned Collector</label>
                                <select class="form-select" id="collector_id" name="collector_id">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($activeCollectors as $collector): ?>
                                        <option value="<?php echo (int)$collector['user_id']; ?>">
                                            <?php echo htmlspecialchars($collector['full_name'] . ' (' . ($collector['employee_id'] ?? 'No ID') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!isAdmin()): ?>
                                    <div class="form-text">Only administrators can assign collectors.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="address" name="address" rows="3" required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Client</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php if (!isCollector()): ?>
    <!-- Edit Client Modal -->
    <div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editClientModalLabel"><i class="fas fa-user-edit me-2"></i>Edit Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="post">
                    <input type="hidden" id="edit_client_id" name="client_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_contact" class="form-label">Contact Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_contact" name="contact" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="edit_email" name="email">
                            </div>
                            <div class="col-12">
                                <label for="edit_collector_id" class="form-label">Assigned Collector</label>
                                <select class="form-select" id="edit_collector_id" name="collector_id">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($activeCollectors as $collector): ?>
                                        <option value="<?php echo (int)$collector['user_id']; ?>">
                                            <?php echo htmlspecialchars($collector['full_name'] . ' (' . ($collector['employee_id'] ?? 'No ID') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!isAdmin()): ?>
                                    <div class="form-text">Only administrators can assign collectors.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12">
                                <label for="edit_address" class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="edit_address" name="address" rows="3" required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Client</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php if (!isCollector()): ?>
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteClientModal" tabindex="-1" aria-labelledby="deleteClientModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteClientModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the client: <strong id="delete_client_name"></strong>?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-circle me-1"></i> This action cannot be undone. All client data will be permanently removed.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirm_delete" class="btn btn-danger">Delete Client</a>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

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
    
    <!-- Custom JavaScript -->
    <script>
        // Edit Client Modal
        document.addEventListener('DOMContentLoaded', function() {
            const editButtons = document.querySelectorAll('.edit-client-btn');
            
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const clientId = this.getAttribute('data-client-id');
                    const firstName = this.getAttribute('data-first-name');
                    const lastName = this.getAttribute('data-last-name');
                    const address = this.getAttribute('data-address');
                    const contact = this.getAttribute('data-contact');
                    const email = this.getAttribute('data-email');
                    const collectorId = this.getAttribute('data-collector-id') || '';
                    
                    document.getElementById('edit_client_id').value = clientId;
                    document.getElementById('edit_first_name').value = firstName;
                    document.getElementById('edit_last_name').value = lastName;
                    document.getElementById('edit_address').value = address;
                    document.getElementById('edit_contact').value = contact;
                    document.getElementById('edit_email').value = email;
                    document.getElementById('edit_collector_id').value = collectorId;
                });
            });
            
            // Delete Client Modal
            const deleteButtons = document.querySelectorAll('.delete-client-btn');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const clientId = this.getAttribute('data-client-id');
                    const clientName = this.getAttribute('data-client-name');
                    
                    document.getElementById('delete_client_name').textContent = clientName;
                    document.getElementById('confirm_delete').href = 'clients_lending.php?delete=' + clientId;
                    
                    // Show the modal
                    const deleteModal = new bootstrap.Modal(document.getElementById('deleteClientModal'));
                    deleteModal.show();
                });
            });
        });
    </script>
</body>
</html>
