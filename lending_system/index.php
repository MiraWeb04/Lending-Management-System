<?php
/**
 * Public Borrower Portal landing page for RJ and RR Finance Services.
 */

require_once 'includes/auth_lending.php';
require_once 'includes/contact_inquiry_helpers.php';

if (isLoggedIn()) {
    header('Location: ' . getDashboardRedirectUrl(getCurrentUser()));
    exit;
}

$contactFlash = $_SESSION['contact_inquiry_flash'] ?? null;
unset($_SESSION['contact_inquiry_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_inquiry_submit'])) {
    csrfRequireValid();

    $rateWindowStart = (int)($_SESSION['contact_inquiry_rate_start'] ?? 0);
    $rateCount = (int)($_SESSION['contact_inquiry_rate_count'] ?? 0);
    if ($rateWindowStart <= 0 || (time() - $rateWindowStart) > 3600) {
        $rateWindowStart = time();
        $rateCount = 0;
    }
    if ($rateCount >= 5) {
        $contactFlash = ['type' => 'danger', 'message' => 'Too many inquiries from this browser. Please try again in an hour or call our office.'];
    } else {
        $_SESSION['contact_inquiry_rate_start'] = $rateWindowStart;
        $_SESSION['contact_inquiry_rate_count'] = $rateCount + 1;

    $honeypot = trim((string)($_POST['company_website'] ?? ''));
    if ($honeypot !== '') {
        header('Location: index.php#contact');
        exit;
    }

    $contactName = trim((string)($_POST['contact_name'] ?? ''));
    $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $contactMessage = trim((string)($_POST['contact_message'] ?? ''));
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    $result = createContactInquiry($contactName, $contactEmail, $contactMessage, is_string($ipAddress) ? $ipAddress : null);

    if (!empty($result['success'])) {
        $_SESSION['contact_inquiry_flash'] = [
            'type' => 'success',
            'message' => (string)($result['message'] ?? 'Thank you! Your inquiry was sent.'),
        ];
        header('Location: index.php#contact');
        exit;
    }

    $contactFlash = [
        'type' => 'danger',
        'message' => (string)($result['message'] ?? 'Unable to send your inquiry.'),
    ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>RJ and RR Finance Services | Borrower Portal</title>
    <meta name="description" content="Professional lending solutions for borrowers with fast approvals and transparent service.">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/pwa.css">
</head>
<body class="public-site">
    <?php include 'includes/borrower_public_header.php'; ?>

    <main id="home">
        <section class="public-hero">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-7">
                        <span class="public-pill mb-3 d-inline-flex align-items-center"><i class="fas fa-shield-halved me-2"></i> Trusted local finance partner</span>
                        <h1 class="mb-3">Finance that feels professional, clear, and built for real borrowers.</h1>
                        <p class="lead text-secondary mb-4">Apply online, track your application in the borrower portal, sign agreements digitally, and stay on top of payments — all in one secure experience with RJ &amp; RR Finance Services.</p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="registration_lending.php" class="btn btn-primary btn-lg rounded-pill px-4"><i class="fas fa-file-signature me-2"></i>Start application</a>
                            <a href="borrower_login_lending.php" class="btn btn-outline-primary btn-lg rounded-pill px-4">Sign in to portal</a>
                        </div>
                        <div class="public-trust-strip">
                            <div class="public-trust-strip__item"><i class="fas fa-lock"></i> Secure borrower accounts</div>
                            <div class="public-trust-strip__item"><i class="fas fa-file-contract"></i> Digital loan agreements</div>
                            <div class="public-trust-strip__item"><i class="fas fa-headset"></i> Local support team</div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="public-hero-card">
                            <div class="public-hero-card__top d-flex align-items-center gap-3 mb-3">
                                <img src="images/logo.png" alt="RJ and RR Finance Services logo" width="56" height="56" style="border-radius:16px">
                                <div>
                                    <h4 class="mb-1 fw-bold">Borrower portal</h4>
                                    <p class="mb-0 text-muted small">Your loan journey in one place</p>
                                </div>
                            </div>
                            <div class="public-hero-card__feature">
                                <div class="public-hero-card__feature-icon"><i class="fas fa-upload"></i></div>
                                <div><h6>Apply &amp; upload documents</h6><p>Submit requirements and track verification status.</p></div>
                            </div>
                            <div class="public-hero-card__feature">
                                <div class="public-hero-card__feature-icon"><i class="fas fa-handshake"></i></div>
                                <div><h6>Review &amp; sign agreement</h6><p>Clear terms before funds are released.</p></div>
                            </div>
                            <div class="public-hero-card__feature">
                                <div class="public-hero-card__feature-icon"><i class="fas fa-wallet"></i></div>
                                <div><h6>Manage active loans</h6><p>View balances, payment history, and notifications.</p></div>
                            </div>
                            <a href="registration_lending.php" class="btn btn-primary w-100 rounded-pill mt-3">Create free account</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="about" class="public-section public-section--muted">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-6">
                        <span class="section-label">About Us</span>
                        <h2 class="section-title">Built for borrowers who value clarity and confidence.</h2>
                        <p class="text-secondary">RJ and RR Finance Services combines thoughtful lending practices, competitive rates, and a streamlined digital experience so clients can access the support they need with confidence.</p>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <div class="info-card">
                                    <i class="fas fa-handshake"></i>
                                    <h5>Trusted Guidance</h5>
                                    <p>Professional consultation from first inquiry to repayment completion.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-card">
                                    <i class="fas fa-chart-line"></i>
                                    <h5>Transparent Terms</h5>
                                    <p>Clear repayment schedules and fair, documented loan structures.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <img src="images/logo.png" alt="RJ and RR Finance Services" class="about-illustration">
                    </div>
                </div>
            </div>
        </section>

        <section id="services" class="public-section public-section--gradient">
            <div class="container">
                <div class="text-center mb-5">
                    <span class="section-label">Loan products</span>
                    <h2 class="section-title">Financing options designed for different goals.</h2>
                    <p class="text-secondary mx-auto" style="max-width:36rem">Choose the product that fits your need. Our team will guide you through requirements and repayment schedules.</p>
                </div>
                <div class="row g-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <div class="service-card__icon"><i class="fas fa-user-check"></i></div>
                            <h5 class="fw-bold">Salary loan</h5>
                            <p class="mb-0">Support for personal expenses and short-term obligations with structured repayments.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <div class="service-card__icon"><i class="fas fa-store"></i></div>
                            <h5 class="fw-bold">Business loan</h5>
                            <p class="mb-0">Working capital for inventory, operations, and small business growth.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <div class="service-card__icon"><i class="fas fa-bolt"></i></div>
                            <h5 class="fw-bold">Emergency loan</h5>
                            <p class="mb-0">Timely assistance when urgent financial needs cannot wait.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <div class="service-card__icon"><i class="fas fa-car"></i></div>
                            <h5 class="fw-bold">Vehicle financing</h5>
                            <p class="mb-0">Practical plans for transportation and mobility investments.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="public-section public-section--muted">
            <div class="container">
                <div class="text-center mb-5">
                    <span class="section-label">How It Works</span>
                    <h2 class="section-title">A straightforward borrowing journey.</h2>
                </div>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="step-card">
                            <div class="step-number">1</div>
                            <h5>Apply Online</h5>
                            <p>Fill out your details and share the loan type and amount you need.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-card">
                            <div class="step-number">2</div>
                            <h5>Get Reviewed</h5>
                            <p>Our team reviews your request and contacts you with the next steps.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="step-card">
                            <div class="step-number">3</div>
                            <h5>Receive Support</h5>
                            <p>Access funds with guidance from a professional lending team.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="calculator" class="public-section public-section--gradient">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-6">
                        <span class="section-label">Loan calculator</span>
                        <h2 class="section-title">Preview a sample repayment estimate.</h2>
                        <p class="text-secondary">Adjust amount and term to explore a rough monthly figure before you apply. Final terms are confirmed during loan review.</p>
                    </div>
                    <div class="col-lg-6">
                        <div class="calculator-card">
                            <div class="mb-3">
                                <label class="form-label" for="loanAmount">Loan amount (₱)</label>
                                <input type="number" class="form-control" id="loanAmount" value="100000" min="1000" step="1000">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="loanTerm">Term (months)</label>
                                <input type="number" class="form-control" id="loanTerm" value="12" min="1" max="36">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="interestRate">Sample rate (% per month)</label>
                                <input type="number" class="form-control" id="interestRate" value="5" min="1" max="30" step="0.1" readonly>
                            </div>
                            <div class="result-box">
                                <div class="small text-muted">Estimated monthly payment</div>
                                <div class="result-amount" id="monthlyPayment">₱0.00</div>
                            </div>
                            <p class="calculator-disclaimer mb-0">Illustration only. Actual schedules follow your approved loan agreement.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="faq" class="public-section public-section--muted">
            <div class="container">
                <div class="text-center mb-5">
                    <span class="section-label">FAQs</span>
                    <h2 class="section-title">Questions borrowers ask us.</h2>
                </div>
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="accordion faq-accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">How do I start an application?</button></h3>
                                <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion"><div class="accordion-body">Register for a borrower account, complete your profile, and submit a loan application with the required documents through the portal.</div></div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">What happens after I apply?</button></h3>
                                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Our team reviews your application, may request additional documents, and updates your status in the borrower portal at each stage.</div></div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">Is my information secure?</button></h3>
                                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Yes. Your account is protected by login credentials, and loan records are handled through our internal lending system.</div></div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">Can I track payments online?</button></h3>
                                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Once your loan is active, you can view payment history and account updates in the borrower dashboard.</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="public-section pt-0">
            <div class="container">
                <div class="public-cta-band text-center text-lg-start">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8">
                            <h2 class="h3 fw-bold mb-2">Ready to apply for financing?</h2>
                            <p class="mb-0 opacity-75">Create your borrower account in minutes and submit your first application when you are ready.</p>
                        </div>
                        <div class="col-lg-4 d-flex flex-wrap gap-2 justify-content-center justify-content-lg-end">
                            <a href="registration_lending.php" class="btn btn-light rounded-pill">Register now</a>
                            <a href="borrower_login_lending.php" class="btn btn-outline-light rounded-pill">Portal login</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="contact" class="public-section public-section--gradient">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-5">
                        <span class="section-label">Contact Us</span>
                        <h2 class="section-title">Let’s talk about your financing needs.</h2>
                        <p class="text-secondary">Reach out to our team for loan questions, assistance, or a personalized consultation.</p>
                        <ul class="contact-list">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(invoiceBrand()['address'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><i class="fas fa-phone"></i> <?php echo htmlspecialchars(invoiceBrand()['phone'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><i class="fas fa-envelope"></i> <?php echo htmlspecialchars(invoiceBrand()['email'], ENT_QUOTES, 'UTF-8'); ?></li>
                        </ul>
                    </div>
                    <div class="col-lg-7">
                        <form id="contactInquiryForm" class="calculator-card" method="post" action="index.php#contact" novalidate>
                            <input type="hidden" name="contact_inquiry_submit" value="1">
                            <?php echo csrfField(); ?>
                            <div class="visually-hidden" aria-hidden="true">
                                <label for="company_website">Company website</label>
                                <input type="text" name="company_website" id="company_website" tabindex="-1" autocomplete="off">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="contact_name">Your Name</label>
                                <input type="text" class="form-control" id="contact_name" name="contact_name" required maxlength="150" placeholder="Juan Dela Cruz" value="<?php echo htmlspecialchars((string)($_POST['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="contact_email">Email Address</label>
                                <input type="email" class="form-control" id="contact_email" name="contact_email" required maxlength="254" placeholder="you@example.com" value="<?php echo htmlspecialchars((string)($_POST['contact_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="contact_message">Message</label>
                                <textarea class="form-control" id="contact_message" name="contact_message" rows="4" required minlength="10" maxlength="5000" placeholder="How can we help?"><?php echo htmlspecialchars((string)($_POST['contact_message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary rounded-pill" id="contactInquirySubmitBtn"><i class="fas fa-paper-plane me-2"></i>Send Inquiry</button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <div class="modal fade" id="contactInquiryModal" tabindex="-1" aria-labelledby="contactInquiryModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered contact-inquiry-modal-dialog">
            <div class="modal-content contact-inquiry-modal border-0 shadow-lg">
                <div class="modal-body text-center px-4 py-5" id="contactInquiryModalBody">
                    <div class="contact-inquiry-modal__sending">
                        <div class="contact-inquiry-modal__spinner mb-3" role="status" aria-hidden="true"></div>
                        <h2 class="h5 fw-bold mb-2" id="contactInquiryModalTitle">Sending your message</h2>
                        <p class="text-secondary mb-0 small">Please wait while we deliver your inquiry to our team…</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/borrower_public_footer.php'; ?>

    <script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const loanAmount = document.getElementById('loanAmount');
            const loanTerm = document.getElementById('loanTerm');
            const interestRate = document.getElementById('interestRate');
            const monthlyPayment = document.getElementById('monthlyPayment');

            function calculatePayment() {
                const principal = parseFloat(loanAmount.value) || 0;
                const months = parseInt(loanTerm.value) || 1;
                const rate = (parseFloat(interestRate.value) || 0) / 100;
                const monthly = principal * (rate / 12) / (1 - Math.pow(1 + rate / 12, -months));
                monthlyPayment.textContent = '₱' + monthly.toLocaleString('en-PH', {maximumFractionDigits: 2});
            }

            [loanAmount, loanTerm, interestRate].forEach(function (field) {
                field.addEventListener('input', calculatePayment);
            });

            calculatePayment();

            var contactFlash = <?php echo json_encode(
                is_array($contactFlash) && !empty($contactFlash['message'])
                    ? ['type' => (string)($contactFlash['type'] ?? 'success'), 'message' => (string)$contactFlash['message']]
                    : null,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
            ); ?>;

            var contactForm = document.getElementById('contactInquiryForm');
            var contactModalEl = document.getElementById('contactInquiryModal');
            var contactModalBody = document.getElementById('contactInquiryModalBody');
            var contactSubmitBtn = document.getElementById('contactInquirySubmitBtn');
            var contactModal = contactModalEl && typeof bootstrap !== 'undefined'
                ? bootstrap.Modal.getOrCreateInstance(contactModalEl)
                : null;

            function renderContactModal(state, message) {
                if (!contactModalBody) {
                    return;
                }
                var safeMessage = message || '';
                if (state === 'sending') {
                    contactModalBody.innerHTML = '<div class="contact-inquiry-modal__sending">' +
                        '<div class="contact-inquiry-modal__spinner mb-3" role="status"><span class="visually-hidden">Sending</span></div>' +
                        '<h2 class="h5 fw-bold mb-2">Sending your message</h2>' +
                        '<p class="text-secondary mb-0 small">Please wait while we deliver your inquiry to our team…</p></div>';
                    return;
                }
                if (state === 'success') {
                    contactModalBody.innerHTML = '<div class="contact-inquiry-modal__result contact-inquiry-modal__result--success">' +
                        '<div class="contact-inquiry-modal__icon mb-3"><i class="fas fa-circle-check"></i></div>' +
                        '<h2 class="h5 fw-bold mb-2">Message sent</h2>' +
                        '<p class="text-secondary mb-4">' + safeMessage + '</p>' +
                        '<button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">OK</button></div>';
                    return;
                }
                contactModalBody.innerHTML = '<div class="contact-inquiry-modal__result contact-inquiry-modal__result--error">' +
                    '<div class="contact-inquiry-modal__icon mb-3"><i class="fas fa-circle-exclamation"></i></div>' +
                    '<h2 class="h5 fw-bold mb-2">Could not send</h2>' +
                    '<p class="text-secondary mb-4">' + safeMessage + '</p>' +
                    '<button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">Try again</button></div>';
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            if (contactForm && contactModal) {
                contactForm.addEventListener('submit', function () {
                    if (!contactForm.checkValidity()) {
                        return;
                    }
                    renderContactModal('sending');
                    contactModal.show();
                    if (contactSubmitBtn) {
                        contactSubmitBtn.disabled = true;
                        contactSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending…';
                    }
                });
            }

            if (contactFlash && contactFlash.message && contactModal) {
                var flashType = contactFlash.type === 'danger' ? 'error' : 'success';
                renderContactModal(flashType, escapeHtml(contactFlash.message));
                contactModal.show();
                if (flashType === 'success' && contactForm) {
                    contactForm.reset();
                }
                if (contactSubmitBtn) {
                    contactSubmitBtn.disabled = false;
                    contactSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Inquiry';
                }
            }

            if (contactModalEl && contactSubmitBtn) {
                contactModalEl.addEventListener('hidden.bs.modal', function () {
                    contactSubmitBtn.disabled = false;
                    contactSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Inquiry';
                });
            }
        });
    </script>
    <script src="assets/pwa.js" defer></script>
</body>
</html>