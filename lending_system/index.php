<?php
/**
 * Public Borrower Portal landing page for RJ and RR Finance Services.
 */

require_once 'includes/auth_lending.php';

if (isLoggedIn()) {
    header('Location: dashboard_lending.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RJ and RR Finance Services | Borrower Portal</title>
    <meta name="description" content="Professional lending solutions for borrowers with fast approvals and transparent service.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body class="public-site">
    <header class="public-header sticky-top">
        <nav class="navbar navbar-expand-lg">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="#home">
                    <img src="images/logo.png" alt="RJ and RR Finance Services logo" class="public-brand-logo">
                    <span>RJ and RR Finance Services</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNavbar" aria-controls="publicNavbar" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse justify-content-end" id="publicNavbar">
                    <ul class="navbar-nav align-items-lg-center gap-lg-2">
                        <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                        <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                        <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
                        <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
                        <li class="nav-item"><a class="nav-link" href="#calculator">Loan Calculator</a></li>
                        <li class="nav-item"><a class="nav-link" href="#faq">FAQs</a></li>
                        <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                        <li class="nav-item"><a class="btn btn-outline-primary rounded-pill ms-lg-2" href="borrower_login_lending.php">Login</a></li>
                        <li class="nav-item"><a class="btn btn-primary rounded-pill" href="registration_lending.php">Register</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <main id="home">
        <section class="public-hero py-5 py-lg-6">
            <div class="container">
                <div class="row align-items-center g-5">
                    <div class="col-lg-7">
                        <span class="badge public-pill mb-3"><i class="fas fa-shield-alt me-2"></i> Trusted lending partner</span>
                        <h1 class="display-5 fw-bold mb-3">A smarter way to fund your next opportunity.</h1>
                        <p class="lead text-secondary mb-4">RJ and RR Finance Services offers transparent, flexible financing for individuals and growing businesses with a professional borrower experience.</p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="registration_lending.php" class="btn btn-primary btn-lg rounded-pill px-4">Apply Now</a>
                            <a href="#services" class="btn btn-outline-primary btn-lg rounded-pill px-4">Explore Services</a>
                        </div>
                        <div class="row g-3 mt-4">
                            <div class="col-sm-4">
                                <div class="public-stat-card">
                                    <h3>Fast</h3>
                                    <p>Quick review and response</p>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="public-stat-card">
                                    <h3>Flexible</h3>
                                    <p>Custom repayment options</p>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="public-stat-card">
                                    <h3>Secure</h3>
                                    <p>Protected and professional process</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="public-hero-card">
                            <div class="public-hero-card__top">
                                <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                                <div>
                                    <h4>Borrower Portal</h4>
                                    <p>Modern financing made simple</p>
                                </div>
                            </div>
                            <div class="public-hero-card__body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Approval Speed</span>
                                    <strong>24 hrs</strong>
                                </div>
                                <div class="progress mb-3">
                                    <div class="progress-bar" style="width: 85%"></div>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Flexible Terms</span>
                                    <strong>Up to 24 months</strong>
                                </div>
                                <div class="progress mb-3">
                                    <div class="progress-bar bg-success" style="width: 75%"></div>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Client Satisfaction</span>
                                    <strong>98%</strong>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar bg-warning" style="width: 98%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="about" class="py-5 bg-white">
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

        <section id="services" class="py-5">
            <div class="container">
                <div class="text-center mb-5">
                    <span class="section-label">Our Loan Services</span>
                    <h2 class="section-title">Flexible financing solutions for every stage of life.</h2>
                </div>
                <div class="row g-4">
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <i class="fas fa-user-check"></i>
                            <h5>Salary Loan</h5>
                            <p>Reliable financing for everyday needs and short-term obligations.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <i class="fas fa-store"></i>
                            <h5>Business Loan</h5>
                            <p>Support growth, inventory, operations, and expansion goals.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <i class="fas fa-ambulance"></i>
                            <h5>Emergency Loan</h5>
                            <p>Fast access to funding when unexpected situations arise.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="service-card">
                            <i class="fas fa-car"></i>
                            <h5>Vehicle Financing</h5>
                            <p>Practical financing for transportation and mobility needs.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="py-5 bg-white">
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

        <section id="calculator" class="py-5">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-6">
                        <span class="section-label">Loan Calculator</span>
                        <h2 class="section-title">Estimate your monthly payments.</h2>
                        <p class="text-secondary">Use our quick calculator to preview a sample repayment plan before applying.</p>
                    </div>
                    <div class="col-lg-6">
                        <div class="calculator-card">
                            <div class="mb-3">
                                <label class="form-label">Loan Amount</label>
                                <input type="number" class="form-control" id="loanAmount" value="100000" min="1000" step="1000">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Term (Months)</label>
                                <input type="number" class="form-control" id="loanTerm" value="12" min="1" max="36">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Interest Rate (%)</label>
                                <input type="number" class="form-control" id="interestRate" value="5" min="1" max="30" step="0.1">
                            </div>
                            <div class="result-box">
                                <div class="small text-muted">Estimated Monthly Payment</div>
                                <div class="result-amount" id="monthlyPayment">₱0.00</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="faq" class="py-5 bg-white">
            <div class="container">
                <div class="text-center mb-5">
                    <span class="section-label">FAQs</span>
                    <h2 class="section-title">Frequently asked questions.</h2>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="faq-card">
                            <h5>How quickly can I apply?</h5>
                            <p>Most applications are reviewed promptly, and our team follows up with you as soon as possible.</p>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="faq-card">
                            <h5>Do you offer flexible repayment terms?</h5>
                            <p>Yes. We can discuss repayment plans that fit your income flow and financial goals.</p>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="faq-card">
                            <h5>Is the process secure?</h5>
                            <p>Absolutely. All borrower information is handled with professionalism and care.</p>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="faq-card">
                            <h5>Can I request a custom loan amount?</h5>
                            <p>Yes. We welcome discussions around customized loan amounts based on your needs.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="contact" class="py-5">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-5">
                        <span class="section-label">Contact Us</span>
                        <h2 class="section-title">Let’s talk about your financing needs.</h2>
                        <p class="text-secondary">Reach out to our team for loan questions, assistance, or a personalized consultation.</p>
                        <ul class="contact-list">
                            <li><i class="fas fa-map-marker-alt"></i> Purok San Francisco, Poblacion, Sominot, ZDS</li>
                            <li><i class="fas fa-phone"></i> +63 9817074262</li>
                            <li><i class="fas fa-envelope"></i> RJ&RRservices@gmail.com</li>
                        </ul>
                    </div>
                    <div class="col-lg-7">
                        <div class="calculator-card">
                            <div class="mb-3">
                                <label class="form-label">Your Name</label>
                                <input type="text" class="form-control" placeholder="Juan Dela Cruz">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" placeholder="you@example.com">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" rows="4" placeholder="How can we help?"></textarea>
                            </div>
                            <button class="btn btn-primary rounded-pill">Send Inquiry</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="public-footer">
        <div class="container py-4">
            <div class="row g-4 align-items-center">
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo" class="public-brand-logo">
                        <div>
                            <h5 class="mb-0">RJ and RR Finance Services</h5>
                            <small class="text-white-50">Borrower Portal</small>
                        </div>
                    </div>
                    <p class="mb-0 text-white-50">Professional lending support for borrowers who value trust, transparency, and timely service.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="footer-links">
                        <a href="#home">Home</a>
                        <a href="#about">About</a>
                        <a href="#services">Services</a>
                        <a href="#contact">Contact</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
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
        });
    </script>
</body>
</html>