<?php
/**
 * Public Borrower Registration Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
require_once 'includes/borrower_registration_helpers.php';
ensureNotificationsSchema();

if (isLoggedIn()) {
    $redirect = getDashboardRedirectUrl(getCurrentUser());
    header('Location: ' . $redirect);
    exit;
}

$error = '';
$success = '';

$firstName = trim($_POST['first_name'] ?? '');
$middleName = trim($_POST['middle_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$civilStatus = trim($_POST['civil_status'] ?? '');
$nationality = trim($_POST['nationality'] ?? '');
$mobileNumber = trim($_POST['mobile_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$confirmPassword = trim($_POST['confirm_password'] ?? '');
$governmentIdType = trim($_POST['government_id_type'] ?? '');
$governmentIdNumber = trim($_POST['government_id_number'] ?? '');
$consent = !empty($_POST['consent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequireValid();
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = 'The ID image must be 5 MB or smaller.';
    } else {
        $registration = registerPendingBorrower($_POST, $_FILES['government_id_image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!empty($registration['ok'])) {
            $_SESSION['borrower_registration_pending_email'] = (string)($registration['email'] ?? $email);
            header('Location: borrower_registration_success_lending.php');
            exit;
        }
        $error = (string)($registration['error'] ?? 'Registration could not be completed. Please try again.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/lending_assets.php'; lendingRenderPwaMeta(); ?>
    <title>Register - RJ and RR Finance Services</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
    <link rel="stylesheet" href="css/borrower_public.css?v=20261025">
</head>
<body class="public-auth-page register-page">
    <div class="public-auth-layout">
        <?php include 'includes/borrower_auth_brand_panel.php'; ?>
        <div class="public-auth-panel register-panel">
            <div class="public-auth-shell public-auth-shell--wide w-100">
                <div class="public-auth-card register-card shadow-sm">
            <a href="index.php" class="public-auth-back"><i class="fas fa-arrow-left"></i> Back to website</a>
            <div class="register-intro">
                <p class="register-kicker">Borrower registration</p>
                <h2 class="auth-title mb-1">Create your account</h2>
                <p class="auth-subtitle mb-0">Four short sections. Your account stays pending until an administrator approves it.</p>
            </div>
            <nav class="register-steps" aria-label="Registration sections">
                <a href="#register-personal"><span class="register-steps__num">1</span><span>Personal</span></a>
                <a href="#register-contact"><span class="register-steps__num">2</span><span>Contact</span></a>
                <a href="#register-account"><span class="register-steps__num">3</span><span>Account</span></a>
                <a href="#register-id"><span class="register-steps__num">4</span><span>Valid ID</span></a>
            </nav>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success rounded-4"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="post" class="public-auth-form" enctype="multipart/form-data" novalidate>
                <?php echo csrfField(); ?>
                <div class="register-section" id="register-personal">
                    <div class="register-section__head">
                        <span class="register-section__icon" aria-hidden="true"><i class="fas fa-user"></i></span>
                        <div>
                            <h3>Personal information</h3>
                            <p>Use the name and details that appear on your valid ID.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="first_name">First Name</label>
                            <input type="text" class="form-control js-person-name" id="first_name" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" maxlength="100" required>
                            <div class="form-text">Letters, spaces, hyphens, and apostrophes only.</div>
                            <div class="text-danger small mt-1 d-none" data-field-error="first_name"></div>
                        </div>
                        <div class="col-md-4 register-field">
                            <label class="form-label" for="middle_name">Middle Name <span class="text-muted">(Optional)</span></label>
                            <input type="text" class="form-control js-person-name" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($middleName); ?>" maxlength="100">
                            <div class="text-danger small mt-1 d-none" data-field-error="middle_name"></div>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="last_name">Last Name</label>
                            <input type="text" class="form-control js-person-name" id="last_name" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" maxlength="100" required>
                            <div class="text-danger small mt-1 d-none" data-field-error="last_name"></div>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="dob">Date of Birth</label>
                            <input type="date" class="form-control" id="dob" name="dob" value="<?php echo htmlspecialchars($dob); ?>" required>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="gender">Gender</label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo $gender === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="civil_status">Civil Status</label>
                            <select class="form-select" id="civil_status" name="civil_status" required>
                                <option value="">Select</option>
                                <option value="Single" <?php echo $civilStatus === 'Single' ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo $civilStatus === 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Widowed" <?php echo $civilStatus === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                <option value="Separated" <?php echo $civilStatus === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="nationality">Nationality</label>
                            <input type="text" class="form-control js-person-name" id="nationality" name="nationality" value="<?php echo htmlspecialchars($nationality); ?>" maxlength="100" required>
                            <div class="text-danger small mt-1 d-none" data-field-error="nationality"></div>
                        </div>
                    </div>
                </div>

                <div class="register-section" id="register-contact">
                    <div class="register-section__head">
                        <span class="register-section__icon" aria-hidden="true"><i class="fas fa-address-book"></i></span>
                        <div>
                            <h3>Contact information</h3>
                            <p>We use these details for account updates and loan notices.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 register-field register-field--required">
                            <label class="form-label" for="mobile_number">Mobile Number</label>
                            <input type="tel" class="form-control" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($mobileNumber); ?>" maxlength="30" inputmode="tel" required>
                            <div class="form-text">Digits only, with an optional leading +.</div>
                            <div class="text-danger small mt-1 d-none" data-field-error="mobile_number"></div>
                        </div>
                        <div class="col-md-6 register-field register-field--required">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" maxlength="150" required>
                            <div class="form-text">Do not use characters such as # $ % ^ &.</div>
                            <div class="text-danger small mt-1 d-none" data-field-error="email"></div>
                        </div>
                        <div class="col-12 register-field register-field--required">
                            <label class="form-label" for="address">Complete Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($address); ?>" maxlength="2000" required>
                            <div class="form-text">Letters, numbers, spaces, commas, periods, and hyphens only.</div>
                            <div class="text-danger small mt-1 d-none" data-field-error="address"></div>
                        </div>
                    </div>
                </div>

                <div class="register-section" id="register-account">
                    <div class="register-section__head">
                        <span class="register-section__icon" aria-hidden="true"><i class="fas fa-key"></i></span>
                        <div>
                            <h3>Account information</h3>
                            <p>Choose the username and password you will use to sign in.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-group register-password">
                                <input type="password" class="form-control" id="password" name="password" value="<?php echo htmlspecialchars($password); ?>" <?php echo lendingPasswordInputAttributes(); ?> required>
                                <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <?php echo lendingPasswordHintHtml(); ?>
                            <div class="password-strength mt-2" id="passwordStrength">Password strength: <span>weak</span></div>
                        </div>
                        <div class="col-md-4 register-field register-field--required">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <div class="input-group register-password">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" value="<?php echo htmlspecialchars($confirmPassword); ?>" required>
                                <button type="button" class="btn btn-outline-secondary password-toggle" data-target="confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-match mt-2" id="passwordMatch">Please confirm your password.</div>
                        </div>
                    </div>
                </div>

                <div class="register-section" id="register-id">
                    <div class="register-section__head">
                        <span class="register-section__icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
                        <div>
                            <h3>ID verification</h3>
                            <p>Upload a readable photo of the identification card you selected.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 register-field register-field--required">
                            <label class="form-label" for="government_id_type">ID Type</label>
                            <select class="form-select" id="government_id_type" name="government_id_type" required>
                                <option value="">Select</option>
                                <?php foreach (borrowerIdTypeOptions() as $idTypeOption): ?>
                                    <option value="<?php echo htmlspecialchars($idTypeOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $governmentIdType === $idTypeOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($idTypeOption, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 register-field register-field--required">
                            <label class="form-label" for="government_id_number">ID Number</label>
                            <input type="text" class="form-control" id="government_id_number" name="government_id_number" maxlength="100" value="<?php echo htmlspecialchars($governmentIdNumber); ?>" required>
                        </div>
                        <div class="col-12 register-field register-field--required">
                            <label class="form-label" for="government_id_image">Upload Valid ID</label>
                            <div class="register-upload">
                                <span class="register-upload__icon" aria-hidden="true"><i class="fas fa-cloud-arrow-up"></i></span>
                                <div class="register-upload__copy">
                                    <strong>Choose a clear photo of your ID</strong>
                                    <p><?php echo htmlspecialchars(borrowerIdFormatLabel(), ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int)borrowerIdMaxBytes(); ?>">
                                <input type="file" class="form-control" id="government_id_image" name="government_id_image" accept="<?php echo htmlspecialchars(borrowerIdAcceptAttribute(), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="id-upload-filename mt-2" id="idFileName">No file selected.</div>
                            <div class="id-upload-preview d-none" id="idPreviewWrap">
                                <img id="idPreview" alt="Preview of the selected identification card">
                            </div>
                            <div class="text-danger small mt-2 d-none" id="idUploadError" role="alert"></div>
                            <p class="form-text mb-0">Upload a clear image of your valid ID. Make sure your name, photo, and identification details are readable.</p>
                        </div>
                    </div>
                </div>

                <div class="register-section register-section--consent">
                    <div class="consent-card">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" <?php echo $consent ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="consent">
                                I agree to the Terms and Conditions and understand that my account will remain under <strong>Pending Verification</strong> until approved by the administrator.
                            </label>
                        </div>
                    </div>
                </div>

                <div class="register-actions">
                    <button type="submit" class="btn btn-primary btn-lg login-btn">
                        Register Account <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                    <a href="index.php" class="btn btn-outline-primary btn-lg rounded-pill">Back to Home</a>
                </div>
            </form>

            <div class="auth-footer mt-4 text-center">
                <p class="mb-0 text-muted">Already have an account? <a href="borrower_login_lending.php">Login</a></p>
            </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            const strengthText = document.getElementById('passwordStrength');
            const matchText = document.getElementById('passwordMatch');

            function getStrength(value) {
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

            function updatePasswordFeedback() {
                const strength = getStrength(passwordInput.value);
                strengthText.innerHTML = 'Password strength: <span class="' + strength.className + '">' + strength.label + '</span>';
                if (confirmInput.value) {
                    matchText.innerHTML = confirmInput.value === passwordInput.value ? '<span class="text-success">Passwords match.</span>' : '<span class="text-danger">Passwords do not match.</span>';
                } else {
                    matchText.textContent = 'Please confirm your password.';
                }
            }

            [passwordInput, confirmInput].forEach(function (field) {
                field.addEventListener('input', updatePasswordFeedback);
            });

            document.querySelectorAll('.password-toggle').forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = button.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    button.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                });
            });

            updatePasswordFeedback();

            const registrationForm = document.querySelector('.public-auth-form');
            const idInput = document.getElementById('government_id_image');
            const idFileName = document.getElementById('idFileName');
            const idPreviewWrap = document.getElementById('idPreviewWrap');
            const idPreview = document.getElementById('idPreview');
            const idUploadError = document.getElementById('idUploadError');
            const maxIdBytes = <?php echo (int)borrowerIdMaxBytes(); ?>;
            const allowWebp = <?php echo borrowerIdWebpSupported() ? 'true' : 'false'; ?>;
            let previewUrl = '';

            function showIdError(message) {
                idUploadError.textContent = message;
                idUploadError.classList.remove('d-none');
            }

            function clearIdError() {
                idUploadError.textContent = '';
                idUploadError.classList.add('d-none');
            }

            function clearIdPreview() {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = '';
                }
                idPreview.removeAttribute('src');
                idPreviewWrap.classList.add('d-none');
                idFileName.textContent = 'No file selected.';
            }

            function validateSelectedId(file, report) {
                if (!file) {
                    if (report) {
                        showIdError('Please upload a clear image of your valid ID.');
                    }
                    return false;
                }
                const extension = (file.name.split('.').pop() || '').toLowerCase();
                const allowedExtensions = allowWebp ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
                const allowedTypes = allowWebp ? ['image/jpeg', 'image/png', 'image/webp'] : ['image/jpeg', 'image/png'];
                if (!allowedExtensions.includes(extension) || (file.type && !allowedTypes.includes(file.type))) {
                    if (report) {
                        showIdError(allowWebp ? 'Upload a JPG, PNG, or WEBP image of your valid ID.' : 'Upload a JPG or PNG image of your valid ID.');
                    }
                    return false;
                }
                if (file.size <= 0) {
                    if (report) {
                        showIdError('The uploaded ID image is empty.');
                    }
                    return false;
                }
                if (file.size > maxIdBytes) {
                    if (report) {
                        showIdError('The ID image must be 5 MB or smaller.');
                    }
                    return false;
                }
                clearIdError();
                return true;
            }

            idInput.addEventListener('change', function () {
                const file = idInput.files && idInput.files[0] ? idInput.files[0] : null;
                if (!validateSelectedId(file, true)) {
                    idInput.value = '';
                    clearIdPreview();
                    return;
                }
                clearIdError();
                idFileName.textContent = file.name;
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                }
                previewUrl = URL.createObjectURL(file);
                idPreview.src = previewUrl;
                idPreviewWrap.classList.remove('d-none');
            });

            const namePattern = /^\p{L}[\p{L}\s.'\-]*$/u;
            const mobilePattern = /^\+?[0-9][0-9\-\s()]*$/;
            const emailBlocked = /[#$%^&*()=\[\]{}|\\:;"'<>,\/?!`~]/;
            const addressPattern = /^[\p{L}0-9][\p{L}0-9\s,.'\/\-]*$/u;

            function fieldErrorElement(id) {
                return document.querySelector('[data-field-error="' + id + '"]');
            }

            function setFieldError(input, message) {
                const error = fieldErrorElement(input.id);
                input.classList.toggle('is-invalid', message !== '');
                if (!error) {
                    return;
                }
                error.textContent = message;
                error.classList.toggle('d-none', message === '');
            }

            function digitsOnly(value) {
                return (value.match(/\d/g) || []).length;
            }

            function validateRegistrationField(input, report) {
                const value = input.value.trim();
                let message = '';
                if (input.classList.contains('js-person-name')) {
                    const label = input.id === 'nationality' ? 'Nationality' : (input.labels && input.labels[0] ? input.labels[0].textContent.replace('(Optional)', '').trim() : 'Name');
                    if (value === '') {
                        message = input.required ? 'Please enter the ' + label.toLowerCase() + '.' : '';
                    } else if (!namePattern.test(value)) {
                        message = label + ' cannot contain numbers or special characters such as # $ % ^ &.';
                    }
                } else if (input.id === 'mobile_number') {
                    const count = digitsOnly(value);
                    if (value === '') {
                        message = 'Please enter a valid mobile number.';
                    } else if (!mobilePattern.test(value) || /[A-Za-z#$%^&]/.test(value)) {
                        message = 'Mobile number cannot contain letters or special characters such as # $ % ^ &.';
                    } else if (count < 10 || count > 15) {
                        message = 'Please enter a valid mobile number.';
                    }
                } else if (input.id === 'email') {
                    if (value === '') {
                        message = 'Please enter a valid email address.';
                    } else if (emailBlocked.test(value) || !/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/.test(value)) {
                        message = 'Email address cannot contain special characters such as # $ % ^ &. Use a normal email address.';
                    }
                } else if (input.id === 'address') {
                    if (value === '' || !addressPattern.test(value) || !/\p{L}/u.test(value)) {
                        message = 'Complete address cannot contain special characters such as # $ % ^ &.';
                    }
                }
                if (report) {
                    setFieldError(input, message);
                }
                return message === '';
            }

            const restrictedInputs = Array.from(document.querySelectorAll('.js-person-name, #mobile_number, #email, #address'));
            restrictedInputs.forEach(function (input) {
                input.addEventListener('input', function () {
                    if (input.classList.contains('is-invalid') || input.value.trim() !== '') {
                        validateRegistrationField(input, true);
                    }
                });
            });

            registrationForm.addEventListener('submit', function (event) {
                let firstInvalid = null;
                restrictedInputs.forEach(function (input) {
                    if (!validateRegistrationField(input, true) && !firstInvalid) {
                        firstInvalid = input;
                    }
                });
                const file = idInput.files && idInput.files[0] ? idInput.files[0] : null;
                const fileValid = validateSelectedId(file, true);
                if (firstInvalid || !fileValid) {
                    event.preventDefault();
                    (firstInvalid || idInput).focus();
                }
            });
        });
    </script>
</body>
</html>
