<?php
/**
 * Admin Create New Loan — same 5-step wizard as borrower loan application.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/loan_wizard_runtime.php';
requireStaff();

if (isCollector()) {
    denyCollectorAccess('Collectors cannot create new loans.');
}

$loanWizardConfig = loanWizardDefaultConfig('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['client']) && is_numeric($_GET['client'])) {
        $newClientId = (int)$_GET['client'];
        if ($newClientId > 0) {
            $previousClientId = (int)($_SESSION['admin_loan_client_id'] ?? 0);
            if ($previousClientId !== $newClientId) {
                lwClearWizardSessions($loanWizardConfig);
            }
            $_SESSION['admin_loan_client_id'] = $newClientId;
        } else {
            unset($_SESSION['admin_loan_client_id']);
            lwClearWizardSessions($loanWizardConfig);
        }
    } elseif (!isset($_GET['step']) && !isset($_GET['submitted']) && !isset($_GET['edit'])) {
        // Fresh "Create New Loan" from loans list — do not reuse a previous client selection.
        unset($_SESSION['admin_loan_client_id']);
        lwClearWizardSessions($loanWizardConfig);
    }
}

require __DIR__ . '/borrower_loan_application_lending.php';
