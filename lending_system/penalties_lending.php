<?php
/**
 * Admin penalty tracking — outstanding penalties and collections.
 */

require_once 'includes/auth_lending.php';
requireStaff();

if (!isAdmin()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/report_export_helpers.php';

ensurePaymentReceiptColumns();

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

$searchTerm = trim((string)($_GET['q'] ?? ''));

$penaltyStats = fetchPenaltyCollectionStats($startDate, $endDate);
$outstandingRows = fetchBorrowersWithOutstandingPenalties();
$collectionRows = fetchPenaltyCollectionsInPeriod($startDate, $endDate);

if ($searchTerm !== '') {
    $needle = strtolower($searchTerm);
    $filterBorrower = static function (array $row) use ($needle): bool {
        $haystack = strtolower(trim(
            ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '') . ' ' .
            ($row['loan_number'] ?? '') . ' ' . ($row['client_id'] ?? '') . ' ' .
            ($row['receipt_number'] ?? '')
        ));
        return str_contains($haystack, $needle);
    };
    $outstandingRows = array_values(array_filter($outstandingRows, $filterBorrower));
    $collectionRows = array_values(array_filter($collectionRows, $filterBorrower));
}

$totalOutstandingPenalty = 0.0;
foreach ($outstandingRows as $outstandingRow) {
    $totalOutstandingPenalty += (float)($outstandingRow['penalty_due'] ?? 0);
}

$periodFilterFormAction = 'penalties_lending.php';
$periodFilterExtraHtml = '
<div class="col-lg-3 col-md-6">
    <label class="form-label fw-semibold" for="penalty-search-q">Search borrower</label>
    <input type="search" class="form-control" id="penalty-search-q" name="q" value="' . htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') . '" placeholder="Name, loan #, client ID">
</div>';

function penaltyMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Penalties - Lending Management System</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
    <link rel="stylesheet" href="css/reports.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">
        <div class="dashboard-header mb-4">
            <div>
                <div class="dashboard-breadcrumb">Finance / Penalties</div>
                <h1 class="page-title mb-1"><i class="fas fa-triangle-exclamation me-2"></i>Penalty Summary</h1>
                <p class="page-subtitle mb-0">Track borrowers with overdue penalties and penalty amounts collected by period.</p>
            </div>
        </div>

        <?php include 'includes/lending_period_filter.php'; ?>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--warning">
                <div class="kpi-card__top">
                    <div class="kpi-card__label">Outstanding penalties</div>
                    <div class="kpi-card__icon"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="kpi-card__value"><?php echo penaltyMoney($totalOutstandingPenalty); ?></div>
                <div class="kpi-card__meta"><?php echo count($outstandingRows); ?> active loan(s) with penalty due</div>
            </div>
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top">
                    <div class="kpi-card__label">Collected (<?php echo htmlspecialchars($periodLabel); ?>)</div>
                    <div class="kpi-card__icon"><i class="fas fa-coins"></i></div>
                </div>
                <div class="kpi-card__value"><?php echo penaltyMoney((float)$penaltyStats['period_collected']); ?></div>
                <div class="kpi-card__meta"><?php echo (int)$penaltyStats['period_transactions']; ?> penalty payment(s)</div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top">
                    <div class="kpi-card__label">Lifetime penalties collected</div>
                    <div class="kpi-card__icon"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="kpi-card__value"><?php echo penaltyMoney((float)$penaltyStats['lifetime_collected']); ?></div>
                <div class="kpi-card__meta"><?php echo (int)$penaltyStats['lifetime_transactions']; ?> total penalty transaction(s)</div>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="m-0 fw-bold"><i class="fas fa-user-clock me-2"></i>Borrowers with penalty due</h6>
                <span class="badge bg-warning text-dark"><?php echo count($outstandingRows); ?> account(s)</span>
            </div>
            <div class="chart-panel__body pt-0 px-0 pb-0">
                <?php if (count($outstandingRows) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Borrower</th>
                                <th>Loan</th>
                                <th>Contact</th>
                                <th class="text-end">Remaining balance</th>
                                <th class="text-end">Penalty due</th>
                                <th class="text-end">Rate</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($outstandingRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></div>
                                    <small class="text-muted">Client #<?php echo (int)($row['client_id'] ?? 0); ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($row['loan_number'] ?? ('LN-' . (int)$row['loan_id']))); ?></div>
                                    <?php echo renderLoanStatusPill((string)($row['status'] ?? 'Active')); ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($row['contact'] ?? '—')); ?></td>
                                <td class="text-end"><?php echo penaltyMoney((float)($row['remaining_balance'] ?? 0)); ?></td>
                                <td class="text-end fw-semibold text-danger"><?php echo penaltyMoney((float)($row['penalty_due'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo number_format((float)($row['penalty_rate_percent'] ?? 0), 2); ?>%</td>
                                <td class="text-end text-nowrap">
                                    <a href="loan_details_lending.php?id=<?php echo (int)$row['loan_id']; ?>" class="btn btn-sm btn-outline-primary">View loan</a>
                                    <a href="payments_lending.php?add=true&amp;loan=<?php echo (int)$row['loan_id']; ?>" class="btn btn-sm btn-primary">Collect</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Total penalty due (listed)</th>
                                <th class="text-end text-danger"><?php echo penaltyMoney($totalOutstandingPenalty); ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4">
                    <?php echo renderEmptyState('fa-check-circle', 'No outstanding penalties', 'All active loans are current on overdue penalty charges for today.'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="m-0 fw-bold"><i class="fas fa-receipt me-2"></i>Penalty collections — <?php echo htmlspecialchars($periodLabel); ?></h6>
                <span class="badge bg-success"><?php echo penaltyMoney((float)$penaltyStats['period_collected']); ?></span>
            </div>
            <div class="chart-panel__body pt-0 px-0 pb-0">
                <?php if (count($collectionRows) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Receipt</th>
                                <th>Borrower</th>
                                <th>Loan</th>
                                <th>Collector</th>
                                <th class="text-end">Installment</th>
                                <th class="text-end">Penalty collected</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($collectionRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$row['payment_date']))); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($row['receipt_number'] ?? ('#' . (int)$row['payment_id']))); ?></td>
                                <td><?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['loan_number'] ?? ('LN-' . (int)$row['loan_id']))); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['collector_name'] ?? '—')); ?></td>
                                <td class="text-end"><?php echo penaltyMoney((float)($row['amount_paid'] ?? 0)); ?></td>
                                <td class="text-end fw-semibold text-success"><?php echo penaltyMoney((float)($row['penalty_paid'] ?? 0)); ?></td>
                                <td class="text-end">
                                    <a href="payments_lending.php?view=<?php echo (int)$row['payment_id']; ?>" class="btn btn-sm btn-outline-secondary">Receipt</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4">
                    <?php echo renderEmptyState('fa-receipt', 'No penalty collections in this period', 'Penalty portions recorded on payments will appear here.'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-light border small mb-0">
            <strong>How penalties work:</strong> Overdue installments on the payment schedule accrue a penalty by loan frequency (Daily 1%, Weekly 2%, Semi-monthly 3%, Monthly 5% of each overdue installment). When recording a payment, enter the <em>Penalty portion</em> so collections appear in this report.
        </div>
    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>
    <script src="assets/lending_ui.js"></script>
</body>
</html>
