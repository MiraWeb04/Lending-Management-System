<?php
require_once __DIR__ . '/invoice_helpers.php';
$publicBrandName = invoiceBrand()['legal_name'];
?>
<footer class="public-footer">
    <div class="container py-5">
        <div class="row g-4 align-items-start">
            <div class="col-lg-6">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <img src="images/logo.png" alt="" class="public-brand-logo" width="42" height="42">
                    <div>
                        <h5 class="mb-0"><?php echo htmlspecialchars($publicBrandName, ENT_QUOTES, 'UTF-8'); ?></h5>
                        <small class="text-white-50">Borrower portal</small>
                    </div>
                </div>
                <p class="mb-0 text-white-50 pe-lg-4">Professional lending support with a clear application process, secure account access, and transparent communication from review to repayment.</p>
            </div>
            <div class="col-lg-3">
                <h6 class="text-white fw-bold mb-3">Quick links</h6>
                <div class="d-flex flex-column gap-2">
                    <a class="text-white-50 text-decoration-none" href="borrower_login_lending.php">Borrower login</a>
                    <a class="text-white-50 text-decoration-none" href="registration_lending.php">Create account</a>
                    <a class="text-white-50 text-decoration-none" href="index.php#services">Loan products</a>
                </div>
            </div>
            <div class="col-lg-3 text-lg-end">
                <div class="footer-links d-flex flex-column flex-lg-row flex-lg-wrap justify-content-lg-end gap-2">
                    <a href="index.php#home">Home</a>
                    <a href="index.php#contact">Contact</a>
                </div>
                <p class="small text-white-50 mt-3 mb-0">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($publicBrandName, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>
</footer>
