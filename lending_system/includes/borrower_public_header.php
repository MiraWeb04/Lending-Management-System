<?php
/** Shared header for borrower landing & marketing pages */
require_once __DIR__ . '/invoice_helpers.php';
$publicBrandName = invoiceBrand()['legal_name'];
?>
<header class="public-header sticky-top">
    <nav class="navbar navbar-expand-lg py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php#home">
                <img src="images/logo.png" alt="<?php echo htmlspecialchars($publicBrandName, ENT_QUOTES, 'UTF-8'); ?> logo" class="public-brand-logo" width="42" height="42">
                <span><?php echo htmlspecialchars($publicBrandName, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNavbar" aria-controls="publicNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="publicNavbar">
                <ul class="navbar-nav align-items-lg-center gap-lg-1">
                    <li class="nav-item"><a class="nav-link" href="index.php#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#calculator">Calculator</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#faq">FAQs</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#contact">Contact</a></li>
                    <li class="nav-item ms-lg-2"><a class="btn btn-outline-primary btn-sm rounded-pill px-3" href="borrower_login_lending.php">Login</a></li>
                    <li class="nav-item ms-lg-1"><a class="btn btn-primary btn-sm rounded-pill px-3" href="registration_lending.php">Register</a></li>
                </ul>
            </div>
        </div>
    </nav>
</header>
