<?php

/**

 * Loan release / disbursement receipt with collector assignment details.

 */



require_once 'includes/auth_lending.php';

requireStaff();

require_once 'includes/db_lending.php';

require_once 'includes/loan_helpers.php';

require_once 'includes/invoice_helpers.php';



$releaseId = (int)($_GET['release_id'] ?? 0);

if ($releaseId <= 0) {

    header('Location: ' . (isAdmin() ? 'loan_release_lending.php' : 'dashboard_lending.php'));

    exit;

}



$release = executeQuery(

    'SELECT lr.*, la.borrower_name, la.email_address, la.mobile_number, la.complete_address, u.full_name AS collector_name

     FROM loan_releases lr

     LEFT JOIN loan_applications la ON la.id = lr.application_id

     LEFT JOIN users u ON u.user_id = lr.collector_user_id

     WHERE lr.id = ? LIMIT 1',

    [$releaseId]

)->fetch(PDO::FETCH_ASSOC);



if (!$release) {

    header('Location: loan_release_lending.php');

    exit;

}



$loan = executeQuery('SELECT * FROM loans WHERE release_id = ? LIMIT 1', [$releaseId])->fetch(PDO::FETCH_ASSOC);

$termMonths = resolveLoanTermMonths(

    $release['loan_term'] ?? null,

    $release['released_at'] ?? ($loan['date_released'] ?? null),

    $loan['due_date'] ?? null

);

if ($termMonths < 1) {

    $termMonths = 12;

}

$interestBreakdown = calculateLoanInterestBreakdown((float)($release['loan_amount'] ?? 0), (float)($release['interest_rate'] ?? 5), $termMonths);

$firstSchedule = executeQuery('SELECT due_date, total_amount_due FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number ASC LIMIT 1', [$releaseId])->fetch(PDO::FETCH_ASSOC);



$releaseReceiptNo = 'REL-' . str_pad((string)$releaseId, 6, '0', STR_PAD_LEFT);

$releaseDateLong = date('F d, Y', strtotime((string)($release['released_at'] ?? 'now')));

$releaseDateTime = date('M d, Y · g:i A', strtotime((string)($release['released_at'] ?? 'now')));

$installmentAmount = (float)($loan['daily_payment'] ?? ($firstSchedule['total_amount_due'] ?? 0));

$frequencyLabel = formatPaymentFrequencyLabel((string)($release['payment_frequency'] ?? 'Monthly'));

$applicationId = (int)($release['application_id'] ?? 0);
$agreementSignedBy = '';
if ($applicationId > 0) {
    $agreementRow = executeQuery(
        'SELECT signed_by FROM loan_agreements WHERE application_id = ? ORDER BY id DESC LIMIT 1',
        [$applicationId]
    )->fetch(PDO::FETCH_ASSOC);
    $agreementSignedBy = trim((string)($agreementRow['signed_by'] ?? ''));
}
$invoiceBorrowerSignature = $agreementSignedBy !== ''
    ? $agreementSignedBy
    : trim((string)($release['borrower_name'] ?? ''));
$invoiceCollectorName = trim((string)($release['collector_name'] ?? ''));
$invoiceAuthorizedSignatory = invoiceBrand()['legal_name'];

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>

    <title>Loan Release Invoice - Lending Management System</title>

    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">

    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">

</head>

<body>

    <?php include 'includes/nav_lending.php'; ?>

    <div class="app-content py-2">

        <div class="invoice-page mb-4">

            <div class="invoice-toolbar no-print">

                <div>

                    <h1 class="invoice-toolbar__title"><i class="fas fa-file-contract me-2 text-primary"></i>Loan Release Invoice</h1>

                    <p class="text-muted small mb-0">Disbursement confirmation & collector assignment</p>

                </div>

                <div class="invoice-toolbar__actions">

                    <a href="loan_release_invoices_lending.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> All invoices</a>
                    <a href="loan_release_lending.php" class="btn btn-outline-secondary btn-sm">Loan release</a>

                    <button type="button" class="btn btn-primary btn-sm" onclick="(typeof printReceipt === 'function' ? printReceipt() : window.print())"><i class="fas fa-print me-1"></i> Print</button>

                </div>

            </div>



            <div class="receipt-paper">

                <article class="invoice-document receipt-shell" aria-label="Loan release invoice">

                    <div class="invoice-document__accent" aria-hidden="true"></div>

                    <div class="invoice-document__body">

                        <?php

                        echo renderInvoiceDocumentHeader(

                            'Loan Release',

                            $releaseReceiptNo,

                            'Released ' . $releaseDateTime

                        );

                        ?>



                        <div class="invoice-parties">

                            <div class="invoice-party">

                                <p class="invoice-party__label">Released to (borrower)</p>

                                <p class="invoice-party__name"><?php echo htmlspecialchars((string)($release['borrower_name'] ?? 'Borrower'), ENT_QUOTES, 'UTF-8'); ?></p>

                                <dl>

                                    <?php if (!empty($release['email_address'])): ?>

                                    <dt>Email</dt>

                                    <dd><?php echo htmlspecialchars((string)$release['email_address'], ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <?php endif; ?>

                                    <?php if (!empty($release['mobile_number'])): ?>

                                    <dt>Mobile</dt>

                                    <dd><?php echo htmlspecialchars((string)$release['mobile_number'], ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <?php endif; ?>

                                    <?php if (!empty($release['complete_address'])): ?>

                                    <dt>Address</dt>

                                    <dd><?php echo htmlspecialchars((string)$release['complete_address'], ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <?php endif; ?>

                                </dl>

                            </div>

                            <div class="invoice-party">

                                <p class="invoice-party__label">Release & collection</p>

                                <p class="invoice-party__name"><?php echo htmlspecialchars((string)($release['collector_name'] ?? 'Assigned collector'), ENT_QUOTES, 'UTF-8'); ?></p>

                                <dl>

                                    <dt>Loan number</dt>

                                    <dd><?php echo htmlspecialchars((string)($release['loan_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <dt>Released by</dt>

                                    <dd><?php echo htmlspecialchars((string)($release['released_by'] ?? 'Admin'), ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <dt>Status</dt>

                                    <dd><?php echo htmlspecialchars((string)($release['release_status'] ?? 'Released'), ENT_QUOTES, 'UTF-8'); ?></dd>

                                    <dt>Release date</dt>

                                    <dd><?php echo htmlspecialchars($releaseDateLong, ENT_QUOTES, 'UTF-8'); ?></dd>

                                </dl>

                            </div>

                        </div>



                        <div class="table-responsive">

                            <table class="invoice-line-items receipt-table">

                                <thead>

                                    <tr>

                                        <th scope="col">Loan terms & disbursement</th>

                                        <th scope="col">Amount / value</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <tr>

                                        <td>

                                            Principal disbursed

                                            <span class="invoice-line-items__desc-muted">Net amount released to borrower</span>

                                        </td>

                                        <td><?php echo invoiceMoney((float)($release['loan_amount'] ?? 0)); ?></td>

                                    </tr>

                                    <tr>

                                        <td>Loan term</td>

                                        <td><?php echo (int)$termMonths; ?> month(s)</td>

                                    </tr>

                                    <tr>

                                        <td>Monthly interest rate</td>

                                        <td><?php echo number_format((float)($release['interest_rate'] ?? 5), 2); ?>%</td>

                                    </tr>

                                    <tr>

                                        <td>Monthly interest amount</td>

                                        <td><?php echo invoiceMoney($interestBreakdown['monthly_interest']); ?></td>

                                    </tr>

                                    <tr>

                                        <td>Total interest over term</td>

                                        <td><?php echo invoiceMoney($interestBreakdown['total_interest']); ?></td>

                                    </tr>

                                    <tr class="invoice-line-items__total">

                                        <td>Total amount payable</td>

                                        <td><?php echo invoiceMoney($interestBreakdown['total_payable']); ?></td>

                                    </tr>

                                    <tr>

                                        <td>Payment frequency</td>

                                        <td><?php echo htmlspecialchars($frequencyLabel, ENT_QUOTES, 'UTF-8'); ?></td>

                                    </tr>

                                    <tr>

                                        <td>

                                            Scheduled collection (per installment)

                                            <span class="invoice-line-items__desc-muted">Amount assigned collector will collect</span>

                                        </td>

                                        <td><?php echo invoiceMoney($installmentAmount); ?></td>

                                    </tr>

                                    <tr class="invoice-line-items__grand">

                                        <td>First payment due</td>

                                        <td><?php echo htmlspecialchars((string)($firstSchedule['due_date'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>

                                    </tr>

                                </tbody>

                            </table>

                        </div>



                        <div class="invoice-bottom">

                            <div class="invoice-notes">

                                <p><span class="invoice-status-chip"><i class="fas fa-hand-holding-usd" aria-hidden="true"></i> Funds released</span></p>

                                <p>This invoice confirms loan disbursement and assignment of the named collector for scheduled collections. Overdue installments may incur penalties according to your loan agreement and payment frequency.</p>

                            </div>

                            <div class="invoice-totals">

                                <div class="invoice-totals__row">

                                    <span>Principal</span>

                                    <span><?php echo invoiceMoney((float)($release['loan_amount'] ?? 0)); ?></span>

                                </div>

                                <div class="invoice-totals__row invoice-totals__row--emphasis">

                                    <span>Total interest</span>

                                    <span><?php echo invoiceMoney($interestBreakdown['total_interest']); ?></span>

                                </div>

                                <div class="invoice-totals__row invoice-totals__row--grand">

                                    <span>Total payable</span>

                                    <span><?php echo invoiceMoney($interestBreakdown['total_payable']); ?></span>

                                </div>

                            </div>

                        </div>



                        <div class="invoice-signatures">

                            <div class="invoice-signature">

                                <?php if ($invoiceBorrowerSignature !== ''): ?>

                                <p class="invoice-signature__name"><?php echo htmlspecialchars($invoiceBorrowerSignature, ENT_QUOTES, 'UTF-8'); ?></p>

                                <?php endif; ?>

                                <div class="invoice-signature__line"></div>

                                <p class="invoice-signature__label">Borrower</p>

                            </div>

                            <div class="invoice-signature">

                                <?php if ($invoiceCollectorName !== ''): ?>

                                <p class="invoice-signature__name"><?php echo htmlspecialchars($invoiceCollectorName, ENT_QUOTES, 'UTF-8'); ?></p>

                                <?php endif; ?>

                                <div class="invoice-signature__line"></div>

                                <p class="invoice-signature__label">Collector</p>

                            </div>

                            <div class="invoice-signature">

                                <p class="invoice-signature__name"><?php echo htmlspecialchars($invoiceAuthorizedSignatory, ENT_QUOTES, 'UTF-8'); ?></p>

                                <div class="invoice-signature__line"></div>

                                <p class="invoice-signature__label">Authorized signatory</p>

                            </div>

                        </div>



                        <footer class="invoice-footer">

                            <strong><?php echo htmlspecialchars(invoiceBrand()['legal_name'], ENT_QUOTES, 'UTF-8'); ?></strong> · Loan release documentation · <?php echo htmlspecialchars($releaseReceiptNo, ENT_QUOTES, 'UTF-8'); ?>

                        </footer>

                    </div>

                </article>

            </div>

        </div>

    </div>

    <?php include 'includes/nav_footer_lending.php'; ?>

</body>

</html>

