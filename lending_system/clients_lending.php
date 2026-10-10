<?php
/**
 * Clients Management Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireStaff();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/csrf_lending.php';
require_once 'includes/borrower_registration_helpers.php';

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

// Initialize variables
$message = '';
$messageType = '';
$searchTerm = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['message'], $_GET['type'])) {
    $message = trim((string)$_GET['message']);
    $messageType = ($_GET['type'] ?? '') === 'success' ? 'success' : 'danger';
}

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


$addClientForm = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'dob' => '',
    'gender' => '',
    'civil_status' => '',
    'nationality' => '',
    'mobile_number' => '',
    'email' => '',
    'address' => '',
    'username' => '',
    'government_id_type' => '',
    'government_id_number' => '',
    'consent' => false,
];
$reopenAddClientModal = false;

// Process client form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();

    if (isCollector()) {
        $message = 'Collectors can only view assigned clients.';
        $messageType = 'danger';
    } else {
        $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;

        if ($clientId > 0) {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($firstName === '' || $lastName === '' || $address === '' || $contact === '') {
                $message = 'Please fill in all required fields.';
                $messageType = 'danger';
            } else {
                $clientData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'address' => $address,
                    'contact' => $contact,
                    'email' => $email,
                ];

                if (update('clients', $clientData, 'client_id = ?', [$clientId])) {
                    $message = 'Client updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update client.';
                    $messageType = 'danger';
                }
            }
        } else {
            $addClientForm = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'middle_name' => trim($_POST['middle_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'dob' => trim($_POST['dob'] ?? ''),
                'gender' => trim($_POST['gender'] ?? ''),
                'civil_status' => trim($_POST['civil_status'] ?? ''),
                'nationality' => trim($_POST['nationality'] ?? ''),
                'mobile_number' => trim($_POST['mobile_number'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'address' => trim($_POST['address'] ?? ''),
                'username' => trim($_POST['username'] ?? ''),
                'government_id_type' => trim($_POST['government_id_type'] ?? ''),
                'government_id_number' => trim($_POST['government_id_number'] ?? ''),
                'consent' => !empty($_POST['consent']),
            ];

            $approvedBy = trim((string)($user['full_name'] ?? $user['username'] ?? 'Admin'));
            $createResult = createClientFromBorrowerRegistration(
                array_merge($addClientForm, [
                    'password' => $_POST['password'] ?? '',
                    'confirm_password' => $_POST['confirm_password'] ?? '',
                ]),
                $approvedBy
            );

            if (!empty($createResult['success'])) {
                header(
                    'Location: clients_lending.php?message='
                    . rawurlencode('Client and borrower portal account created successfully.')
                    . '&type=success'
                );
                exit;
            } else {
                $message = $createResult['error'] ?? 'Failed to add client.';
                $messageType = 'danger';
                $reopenAddClientModal = true;
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
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Clients - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/admin_pages.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="app-content py-2">
        <?php
        $clientHeaderActions = isCollector() ? '' : '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal"><i class="fas fa-plus me-1"></i> Add New Client</button>';
        echo renderAdminDashboardHeader(
            'Client Management',
            'Track assigned clients and keep account records organized.',
            'fa-users',
            'Clients',
            $clientHeaderActions
        );
        ?>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-search me-2 text-primary"></i>Search clients</h6>
            </div>
            <div class="chart-panel__body pt-0">
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

        <div class="panel-card panel-card--flush">
            <div class="chart-panel__header">
                <h6 class="m-0 fw-bold"><i class="fas fa-list me-2 text-primary"></i>Client list</h6>
            </div>
            <div class="chart-panel__body p-0 pt-0">
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
    <!-- Add Client Modal (same fields as borrower registration) -->
    <div class="modal fade add-client-modal" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content add-client-modal__content">
                <div class="modal-header add-client-modal__header flex-shrink-0 border-0">
                    <div class="add-client-modal__header-inner">
                        <div class="add-client-modal__header-icon" aria-hidden="true"><i class="fas fa-user-plus"></i></div>
                        <div>
                            <h5 class="modal-title mb-1" id="addClientModalLabel">Add New Client</h5>
                            <p class="add-client-modal__subtitle mb-0">Client profile and borrower portal credentials</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="post" id="addClientForm" class="add-client-modal__form">
                    <?php echo csrfField(); ?>
                    <div class="modal-body add-client-modal__body">
                        <nav class="add-client-modal__nav" aria-label="Form sections">
                            <a href="#add-client-section-personal" class="add-client-modal__nav-link" data-add-client-section="add-client-section-personal">Personal</a>
                            <a href="#add-client-section-contact" class="add-client-modal__nav-link" data-add-client-section="add-client-section-contact">Contact</a>
                            <a href="#add-client-section-account" class="add-client-modal__nav-link" data-add-client-section="add-client-section-account">Account</a>
                            <a href="#add-client-section-id" class="add-client-modal__nav-link" data-add-client-section="add-client-section-id">ID</a>
                            <a href="#add-client-section-consent" class="add-client-modal__nav-link" data-add-client-section="add-client-section-consent">Confirm</a>
                        </nav>

                        <article class="add-client-section" id="add-client-section-personal">
                            <header class="add-client-section__head">
                                <span class="add-client-section__icon"><i class="fas fa-id-card"></i></span>
                                <div>
                                    <h6 class="add-client-section__title">Personal information</h6>
                                    <p class="add-client-section__desc">Legal name and basic demographics</p>
                                </div>
                            </header>
                            <div class="add-client-section__body row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="add_first_name">First name <span class="required-star">*</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_first_name" name="first_name" value="<?php echo htmlspecialchars($addClientForm['first_name']); ?>" required autocomplete="given-name">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_middle_name">Middle name <span class="text-secondary">(optional)</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_middle_name" name="middle_name" value="<?php echo htmlspecialchars($addClientForm['middle_name']); ?>" autocomplete="additional-name">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_last_name">Last name <span class="required-star">*</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_last_name" name="last_name" value="<?php echo htmlspecialchars($addClientForm['last_name']); ?>" required autocomplete="family-name">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_dob">Date of birth <span class="required-star">*</span></label>
                                    <input type="date" class="form-control form-control-lg rounded-3 add-client-field" id="add_dob" name="dob" value="<?php echo htmlspecialchars($addClientForm['dob']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_gender">Gender <span class="required-star">*</span></label>
                                    <select class="form-select form-select-lg rounded-3 add-client-field" id="add_gender" name="gender" required>
                                        <option value="">Select gender</option>
                                        <option value="Male" <?php echo $addClientForm['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $addClientForm['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Prefer not to say" <?php echo $addClientForm['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_civil_status">Civil status <span class="required-star">*</span></label>
                                    <select class="form-select form-select-lg rounded-3 add-client-field" id="add_civil_status" name="civil_status" required>
                                        <option value="">Select status</option>
                                        <option value="Single" <?php echo $addClientForm['civil_status'] === 'Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo $addClientForm['civil_status'] === 'Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo $addClientForm['civil_status'] === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        <option value="Separated" <?php echo $addClientForm['civil_status'] === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="add_nationality">Nationality <span class="required-star">*</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_nationality" name="nationality" value="<?php echo htmlspecialchars($addClientForm['nationality']); ?>" placeholder="e.g. Filipino" required>
                                </div>
                            </div>
                        </article>

                        <article class="add-client-section" id="add-client-section-contact">
                            <header class="add-client-section__head">
                                <span class="add-client-section__icon"><i class="fas fa-address-book"></i></span>
                                <div>
                                    <h6 class="add-client-section__title">Contact information</h6>
                                    <p class="add-client-section__desc">How we reach the client for updates and loan notices</p>
                                </div>
                            </header>
                            <div class="add-client-section__body row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="add_mobile_number">Mobile number <span class="required-star">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-phone text-primary"></i></span>
                                        <input type="tel" class="form-control rounded-end-3 add-client-field" id="add_mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($addClientForm['mobile_number']); ?>" placeholder="09XX XXX XXXX" required autocomplete="tel">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="add_email">Email address <span class="required-star">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text rounded-start-3"><i class="fas fa-envelope text-primary"></i></span>
                                        <input type="email" class="form-control rounded-end-3 add-client-field" id="add_email" name="email" value="<?php echo htmlspecialchars($addClientForm['email']); ?>" placeholder="name@email.com" required autocomplete="email">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="add_address">Complete address <span class="required-star">*</span></label>
                                    <textarea class="form-control rounded-3 add-client-field" id="add_address" name="address" rows="2" placeholder="House no., street, barangay, city, province" required><?php echo htmlspecialchars($addClientForm['address']); ?></textarea>
                                </div>
                            </div>
                        </article>

                        <article class="add-client-section" id="add-client-section-account">
                            <header class="add-client-section__head">
                                <span class="add-client-section__icon"><i class="fas fa-key"></i></span>
                                <div>
                                    <h6 class="add-client-section__title">Portal account</h6>
                                    <p class="add-client-section__desc">Login credentials for the borrower portal (active immediately)</p>
                                </div>
                            </header>
                            <div class="add-client-section__body row g-3">
                                <div class="col-md-12 col-lg-4">
                                    <label class="form-label" for="add_username">Username <span class="required-star">*</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_username" name="username" value="<?php echo htmlspecialchars($addClientForm['username']); ?>" required autocomplete="username">
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <label class="form-label" for="add_password">Password <span class="required-star">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <input type="password" class="form-control rounded-start-3 add-client-field" id="add_password" name="password" required autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary password-toggle rounded-end-3" data-target="add_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="password-strength mt-2 small" id="addPasswordStrength">Password strength: <span>weak</span></div>
                                </div>
                                <div class="col-md-6 col-lg-4">
                                    <label class="form-label" for="add_confirm_password">Confirm password <span class="required-star">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <input type="password" class="form-control rounded-start-3 add-client-field" id="add_confirm_password" name="confirm_password" required autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary password-toggle rounded-end-3" data-target="add_confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="password-match mt-2 small" id="addPasswordMatch">Please confirm the password.</div>
                                </div>
                            </div>
                        </article>

                        <article class="add-client-section" id="add-client-section-id">
                            <header class="add-client-section__head">
                                <span class="add-client-section__icon"><i class="fas fa-fingerprint"></i></span>
                                <div>
                                    <h6 class="add-client-section__title">Government ID</h6>
                                    <p class="add-client-section__desc">Valid identification on file</p>
                                </div>
                            </header>
                            <div class="add-client-section__body row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="add_government_id_type">ID type <span class="required-star">*</span></label>
                                    <select class="form-select form-select-lg rounded-3 add-client-field" id="add_government_id_type" name="government_id_type" required>
                                        <option value="">Select ID type</option>
                                        <option value="Driver's License" <?php echo $addClientForm['government_id_type'] === "Driver's License" ? 'selected' : ''; ?>>Driver's License</option>
                                        <option value="Passport" <?php echo $addClientForm['government_id_type'] === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                        <option value="UMID" <?php echo $addClientForm['government_id_type'] === 'UMID' ? 'selected' : ''; ?>>UMID</option>
                                        <option value="SSS/GSIS" <?php echo $addClientForm['government_id_type'] === 'SSS/GSIS' ? 'selected' : ''; ?>>SSS/GSIS</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="add_government_id_number">ID number <span class="required-star">*</span></label>
                                    <input type="text" class="form-control form-control-lg rounded-3 add-client-field" id="add_government_id_number" name="government_id_number" value="<?php echo htmlspecialchars($addClientForm['government_id_number']); ?>" required>
                                </div>
                            </div>
                        </article>

                        <article class="add-client-section add-client-section--consent" id="add-client-section-consent">
                            <div class="add-client-consent">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="add_consent" name="consent" value="1" <?php echo $addClientForm['consent'] ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="add_consent">
                                        I confirm the client&rsquo;s information is accurate and they agree to the Terms and Conditions for use of the borrower portal.
                                    </label>
                                </div>
                            </div>
                        </article>
                    </div>
                    <div class="modal-footer add-client-modal__footer flex-shrink-0">
                        <p class="add-client-modal__footer-note mb-0 me-auto d-none d-md-block"><span class="required-star">*</span> Required fields</p>
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-lg px-4 shadow-sm"><i class="fas fa-save me-2"></i>Save Client</button>
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
                    <?php echo csrfField(); ?>
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

<?php include 'includes/nav_footer_lending.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($reopenAddClientModal): ?>
            const addClientModalEl = document.getElementById('addClientModal');
            if (addClientModalEl) {
                bootstrap.Modal.getOrCreateInstance(addClientModalEl).show();
            }
            <?php endif; ?>

            const addPasswordInput = document.getElementById('add_password');
            const addConfirmInput = document.getElementById('add_confirm_password');
            const addStrengthText = document.getElementById('addPasswordStrength');
            const addMatchText = document.getElementById('addPasswordMatch');

            function getPasswordStrength(value) {
                let score = 0;
                if (value.length >= 8) score += 1;
                if (/[A-Z]/.test(value)) score += 1;
                if (/[0-9]/.test(value)) score += 1;
                if (/[^A-Za-z0-9]/.test(value)) score += 1;
                if (score <= 1) return { label: 'weak', className: 'text-danger' };
                if (score === 2) return { label: 'fair', className: 'text-warning' };
                if (score === 3) return { label: 'good', className: 'text-info' };
                return { label: 'strong', className: 'text-success' };
            }

            function updateAddPasswordFeedback() {
                if (!addPasswordInput || !addStrengthText || !addMatchText) return;
                const strength = getPasswordStrength(addPasswordInput.value);
                addStrengthText.innerHTML = 'Password strength: <span class="' + strength.className + '">' + strength.label + '</span>';
                if (addConfirmInput.value) {
                    addMatchText.innerHTML = addConfirmInput.value === addPasswordInput.value
                        ? '<span class="text-success">Passwords match.</span>'
                        : '<span class="text-danger">Passwords do not match.</span>';
                } else {
                    addMatchText.textContent = 'Please confirm the password.';
                }
            }

            [addPasswordInput, addConfirmInput].forEach(function (field) {
                if (field) field.addEventListener('input', updateAddPasswordFeedback);
            });

            document.querySelectorAll('#addClientModal .password-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = button.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    if (!input) return;
                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    button.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                });
            });

            updateAddPasswordFeedback();

            document.querySelectorAll('[data-add-client-section]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    const sectionId = link.getAttribute('data-add-client-section');
                    const section = sectionId ? document.getElementById(sectionId) : null;
                    if (section) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });

            const editButtons = document.querySelectorAll('.edit-client-btn');
            
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const clientId = this.getAttribute('data-client-id');
                    const firstName = this.getAttribute('data-first-name');
                    const lastName = this.getAttribute('data-last-name');
                    const address = this.getAttribute('data-address');
                    const contact = this.getAttribute('data-contact');
                    const email = this.getAttribute('data-email');
                    document.getElementById('edit_client_id').value = clientId;
                    document.getElementById('edit_first_name').value = firstName;
                    document.getElementById('edit_last_name').value = lastName;
                    document.getElementById('edit_address').value = address;
                    document.getElementById('edit_contact').value = contact;
                    document.getElementById('edit_email').value = email;
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
