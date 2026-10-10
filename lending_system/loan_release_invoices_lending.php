<?php
/**
 * Admin list of Loan Release Invoices with date, collector, and frequency filters.
 */

require_once 'includes/auth_lending.php';
requireAdmin();

require_once 'includes/db_lending.php';
require_once 'includes/loan_helpers.php';
require_once 'includes/invoice_helpers.php';
require_once 'includes/ui_helpers.php';
require_once 'includes/report_export_helpers.php';

$periodMode = trim((string)($_GET['period_mode'] ?? 'all'));
$periodModeLabels = ['all' => 'All release dates'] + lendingPeriodModeLabels();
$allowedPeriodModes = array_keys($periodModeLabels);
if (!in_array($periodMode, $allowedPeriodModes, true)) {
    $periodMode = 'all';
}

$applyDateFilter = $periodMode !== 'all';
if ($applyDateFilter) {
    $periodResolved = resolveReportPeriodRange($periodMode, $_GET);
    $periodMode = $periodResolved['period_mode'];
    $startDate = $periodResolved['start_date'];
    $endDate = $periodResolved['end_date'];
    $reportDate = $periodResolved['report_date'];
    $reportMonth = $periodResolved['report_month'];
    $reportYear = $periodResolved['report_year'];
    $periodLabel = $periodResolved['period_label'];
} else {
    $today = new DateTimeImmutable('today');
    $startDate = '';
    $endDate = '';
    $reportDate = $today->format('Y-m-d');
    $reportMonth = $today->format('Y-m');
    $reportYear = (int)$today->format('Y');
    $periodLabel = 'All release dates';
}

$yearOptions = lendingYearOptions();

$collectorFilter = (int)($_GET['collector_id'] ?? 0);
$frequencyFilter = trim((string)($_GET['frequency'] ?? ''));
$allowedFrequencyKeys = ['daily', 'weekly', 'semi-monthly', 'monthly'];
if ($frequencyFilter !== '' && !in_array($frequencyFilter, $allowedFrequencyKeys, true)) {
    $frequencyFilter = '';
}

$frequencyOptions = [
    '' => 'All frequencies',
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'semi-monthly' => 'Semi-Monthly',
    'monthly' => 'Monthly',
];

$sql = '
    SELECT lr.id,
           lr.loan_number,
           lr.loan_amount,
           lr.interest_rate,
           lr.loan_term,
           lr.payment_frequency,
           lr.released_at,
           lr.released_by,
           lr.collector_user_id,
           la.borrower_name,
           u.full_name AS collector_name
    FROM loan_releases lr
    LEFT JOIN loan_applications la ON la.id = lr.application_id
    LEFT JOIN users u ON u.user_id = lr.collector_user_id
    WHERE lr.released_at IS NOT NULL
';
$params = [];

if ($applyDateFilter) {
    $sql .= ' AND DATE(lr.released_at) >= ? AND DATE(lr.released_at) <= ?';
    $params[] = $startDate;
    $params[] = $endDate;
}

if ($collectorFilter > 0) {
    $sql .= ' AND lr.collector_user_id = ?';
    $params[] = $collectorFilter;
}

$sql .= ' ORDER BY lr.released_at DESC, lr.id DESC';

$releaseRows = executeQuery($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

if ($frequencyFilter !== '') {
    $releaseRows = array_values(array_filter(
        $releaseRows,
        static function (array $row) use ($frequencyFilter): bool {
            return normalizePaymentFrequencyKey((string)($row['payment_frequency'] ?? '')) === $frequencyFilter;
        }
    ));
}

$collectorOptions = executeQuery(
    "SELECT user_id, full_name
     FROM users
     WHERE LOWER(TRIM(role)) = 'collector'
       AND LOWER(TRIM(COALESCE(status, 'active'))) = 'active'
     ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$totalPrincipal = 0.0;
foreach ($releaseRows as $releaseRow) {
    $totalPrincipal += (float)($releaseRow['loan_amount'] ?? 0);
}

$periodFilterFormAction = 'loan_release_invoices_lending.php';
$periodFilterFormId = 'release-invoices-period-filter';
$periodFilterHidden = array_filter([
    'collector_id' => $collectorFilter > 0 ? (string)$collectorFilter : '',
    'frequency' => $frequencyFilter,
], static fn($v) => $v !== '' && $v !== null);

$periodFilterExtraHtml = '
<div class="col-lg-3 col-md-6">
    <label class="form-label fw-semibold" for="release-invoices-collector">Collector</label>
    <select class="form-select" id="release-invoices-collector" name="collector_id">
        <option value="">All collectors</option>';
foreach ($collectorOptions as $collectorOption) {
    $cid = (int)($collectorOption['user_id'] ?? 0);
    $selected = $collectorFilter === $cid ? ' selected' : '';
    $periodFilterExtraHtml .= '<option value="' . $cid . '"' . $selected . '>'
        . htmlspecialchars((string)($collectorOption['full_name'] ?? 'Collector'), ENT_QUOTES, 'UTF-8')
        . '</option>';
}
$periodFilterExtraHtml .= '
    </select>
</div>
<div class="col-lg-3 col-md-6">
    <label class="form-label fw-semibold" for="release-invoices-frequency">Payment frequency</label>
    <select class="form-select" id="release-invoices-frequency" name="frequency">';
foreach ($frequencyOptions as $freqKey => $freqLabel) {
    $selected = $frequencyFilter === $freqKey ? ' selected' : '';
    $periodFilterExtraHtml .= '<option value="' . htmlspecialchars($freqKey, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
        . htmlspecialchars($freqLabel, ENT_QUOTES, 'UTF-8') . '</option>';
}
$periodFilterExtraHtml .= '
    </select>
</div>';

function releaseInvoiceNumber(int $releaseId): string
{
    return 'REL-' . str_pad((string)$releaseId, 6, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Loan Release Invoices - Lending Management System</title>
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
                <div class="dashboard-breadcrumb"><a href="dashboard_lending.php">Home</a> / Loan Release Invoices</div>
                <h1 class="page-title mb-1"><i class="fas fa-file-invoice me-2"></i>Loan Release Invoices</h1>
                <p class="page-subtitle mb-0">Browse disbursement invoices by release date, assigned collector, and borrower payment frequency.</p>
            </div>
            <div class="quick-actions">
                <a href="loan_release_lending.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-contract me-1"></i> Loan Release</a>
            </div>
        </div>

        <?php include 'includes/lending_period_filter.php'; ?>

        <div class="kpi-grid mb-4">
            <div class="kpi-card kpi-card--primary">
                <div class="kpi-card__top">
                    <div class="kpi-card__label">Invoices</div>
                    <div class="kpi-card__icon"><i class="fas fa-file-invoice"></i></div>
                </div>
                <div class="kpi-card__value"><?php echo count($releaseRows); ?></div>
                <div class="kpi-card__meta"><?php echo htmlspecialchars($periodLabel); ?></div>
            </div>
            <div class="kpi-card kpi-card--success">
                <div class="kpi-card__top">
                    <div class="kpi-card__label">Principal released</div>
                    <div class="kpi-card__icon"><i class="fas fa-peso-sign"></i></div>
                </div>
                <div class="kpi-card__value"><?php echo invoiceMoney($totalPrincipal); ?></div>
                <div class="kpi-card__meta">Sum of listed releases</div>
            </div>
        </div>

        <div class="panel-card panel-card--flush mb-4">
            <div class="chart-panel__header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="m-0 fw-bold"><i class="fas fa-list me-2"></i>Release invoice register</h6>
                <?php if ($collectorFilter > 0 || $frequencyFilter !== '' || $applyDateFilter): ?>
                <a href="loan_release_invoices_lending.php" class="btn btn-sm btn-outline-secondary">Clear filters</a>
                <?php endif; ?>
            </div>
            <div class="chart-panel__body pt-0 px-0 pb-0">
                <?php if (count($releaseRows) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice #</th>
                                <th>Release date</th>
                                <th>Borrower</th>
                                <th>Loan #</th>
                                <th class="text-end">Principal</th>
                                <th>Frequency</th>
                                <th>Collector</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($releaseRows as $row): ?>
                            <?php
                            $releaseId = (int)($row['id'] ?? 0);
                            $releasedAt = (string)($row['released_at'] ?? '');
                            $releasedDisplay = $releasedAt !== '' ? date('M d, Y · g:i A', strtotime($releasedAt)) : '—';
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars(releaseInvoiceNumber($releaseId), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($releasedDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['borrower_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['loan_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end"><?php echo invoiceMoney((float)($row['loan_amount'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars(formatPaymentFrequencyLabel((string)($row['payment_frequency'] ?? 'Monthly')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['collector_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="loan_release_receipt_lending.php?release_id=<?php echo $releaseId; ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">
                                        <i class="fas fa-file-invoice me-1"></i> View invoice
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-4">
                    <?php echo renderEmptyState('fa-file-invoice', 'No release invoices found', 'Adjust the date period, collector, or payment frequency filters, or release a loan from the Loan Release module.'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>
    <script src="assets/lending_ui.js"></script>
</body>
</html>
