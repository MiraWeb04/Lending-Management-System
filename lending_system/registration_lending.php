<?php
/**
 * Public Borrower Registration Page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';
require_once 'includes/db_lending.php';
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
    if ($firstName === '' || $lastName === '' || $dob === '' || $gender === '' || $civilStatus === '' || $nationality === '' || $mobileNumber === '' || $email === '' || $address === '' || $username === '' || $password === '' || $confirmPassword === '' || $governmentIdType === '' || $governmentIdNumber === '' || !$consent) {
        $error = 'Please complete all required fields and agree to the terms before registering.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The password and confirmation password do not match.';
    } else {
        $existingUser = executeQuery('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])->fetch(PDO::FETCH_ASSOC);
        if ($existingUser) {
            $error = 'That username or email address is already registered.';
        } else {
            global $conn;
            $conn->exec("CREATE TABLE IF NOT EXISTS borrower_applications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT DEFAULT NULL,
                first_name VARCHAR(100) NOT NULL,
                middle_name VARCHAR(100) DEFAULT NULL,
                last_name VARCHAR(100) NOT NULL,
                date_of_birth DATE DEFAULT NULL,
                gender VARCHAR(30) DEFAULT NULL,
                civil_status VARCHAR(30) DEFAULT NULL,
                nationality VARCHAR(100) DEFAULT NULL,
                mobile_number VARCHAR(30) DEFAULT NULL,
                email VARCHAR(150) DEFAULT NULL,
                complete_address TEXT DEFAULT NULL,
                username VARCHAR(100) DEFAULT NULL,
                government_id_type VARCHAR(80) DEFAULT NULL,
                government_id_number VARCHAR(100) DEFAULT NULL,
                consent_accepted TINYINT(1) DEFAULT 0,
                status VARCHAR(50) DEFAULT 'Pending Approval',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $requiredColumns = [
                'verification_status' => "ALTER TABLE borrower_applications ADD COLUMN verification_status VARCHAR(50) DEFAULT 'Pending'",
                'rejection_reason' => "ALTER TABLE borrower_applications ADD COLUMN rejection_reason TEXT DEFAULT NULL",
                'approved_by' => "ALTER TABLE borrower_applications ADD COLUMN approved_by VARCHAR(150) DEFAULT NULL",
                'approved_at' => "ALTER TABLE borrower_applications ADD COLUMN approved_at DATETIME DEFAULT NULL"
            ];

            foreach ($requiredColumns as $columnName => $alterSql) {
                $columnCheck = $conn->query("SHOW COLUMNS FROM borrower_applications WHERE Field = " . $conn->quote($columnName));
                if ($columnCheck && $columnCheck->rowCount() === 0) {
                    $conn->exec($alterSql);
                }
            }

            $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Collector','Borrower') NOT NULL DEFAULT 'Borrower'");
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $insertUser = executeQuery(
                'INSERT INTO users (username, password, full_name, role, status, date_created, email, contact_number, employee_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
                [$username, $hashedPassword, trim($firstName . ' ' . $middleName . ' ' . $lastName), 'Borrower', 'Pending Approval', $email, $mobileNumber, null]
            );
            $userId = $conn->lastInsertId();

            $stmt = $conn->prepare('INSERT INTO borrower_applications (user_id, first_name, middle_name, last_name, date_of_birth, gender, civil_status, nationality, mobile_number, email, complete_address, username, government_id_type, government_id_number, consent_accepted, status, verification_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([$userId, $firstName, $middleName, $lastName, $dob, $gender, $civilStatus, $nationality, $mobileNumber, $email, $address, $username, $governmentIdType, $governmentIdNumber, 1, 'Pending Approval', 'Pending']);
            notifyAdmins('New Borrower Registration', "A new borrower account was registered by {$firstName} {$lastName}.");

            $firstName = $middleName = $lastName = $dob = $gender = $civilStatus = $nationality = $mobileNumber = $email = $address = $username = $password = $confirmPassword = $governmentIdType = $governmentIdNumber = '';
            $consent = false;
            header('Location: borrower_registration_success_lending.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - RJ and RR Finance Services</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body class="public-auth-page">
    <div class="public-auth-shell">
        <div class="public-auth-card shadow-sm">
            <div class="text-center mb-4">
                <a href="index.php" class="d-inline-flex align-items-center gap-2 text-decoration-none">
                    <div class="login-logo me-2">
                        <img src="images/logo.png" alt="RJ and RR Finance Services logo">
                    </div>
                    <div class="text-start">
                        <h2 class="auth-title mb-0">RJ and RR Finance Services</h2>
                        <p class="auth-subtitle mb-0">Create Your Borrower Account</p>
                    </div>
                </a>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success rounded-4"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="post" class="public-auth-form" novalidate>
                <div class="registration-section">
                    <h5 class="section-subtitle">Personal Information</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="middle_name">Middle Name <span class="text-muted">(Optional)</span></label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($middleName); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="last_name">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="dob">Date of Birth</label>
                            <input type="date" class="form-control" id="dob" name="dob" value="<?php echo htmlspecialchars($dob); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="gender">Gender</label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo $gender === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="civil_status">Civil Status</label>
                            <select class="form-select" id="civil_status" name="civil_status" required>
                                <option value="">Select</option>
                                <option value="Single" <?php echo $civilStatus === 'Single' ? 'selected' : ''; ?>>Single</option>
                                <option value="Married" <?php echo $civilStatus === 'Married' ? 'selected' : ''; ?>>Married</option>
                                <option value="Widowed" <?php echo $civilStatus === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                <option value="Separated" <?php echo $civilStatus === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="nationality">Nationality</label>
                            <input type="text" class="form-control" id="nationality" name="nationality" value="<?php echo htmlspecialchars($nationality); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="registration-section">
                    <h5 class="section-subtitle">Contact Information</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="mobile_number">Mobile Number</label>
                            <input type="tel" class="form-control" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($mobileNumber); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="address">Complete Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($address); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="registration-section">
                    <h5 class="section-subtitle">Account Information</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="password">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" value="<?php echo htmlspecialchars($password); ?>" required>
                                <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength mt-2" id="passwordStrength">Password strength: <span>weak</span></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" value="<?php echo htmlspecialchars($confirmPassword); ?>" required>
                                <button type="button" class="btn btn-outline-secondary password-toggle" data-target="confirm_password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-match mt-2" id="passwordMatch">Please confirm your password.</div>
                        </div>
                    </div>
                </div>

                <div class="registration-section">
                    <h5 class="section-subtitle">Identification</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="government_id_type">Government ID Type</label>
                            <select class="form-select" id="government_id_type" name="government_id_type" required>
                                <option value="">Select</option>
                                <option value="Driver's License" <?php echo $governmentIdType === "Driver's License" ? 'selected' : ''; ?>>Driver's License</option>
                                <option value="Passport" <?php echo $governmentIdType === 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                <option value="UMID" <?php echo $governmentIdType === 'UMID' ? 'selected' : ''; ?>>UMID</option>
                                <option value="SSS/GSIS" <?php echo $governmentIdType === 'SSS/GSIS' ? 'selected' : ''; ?>>SSS/GSIS</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="government_id_number">Government ID Number</label>
                            <input type="text" class="form-control" id="government_id_number" name="government_id_number" value="<?php echo htmlspecialchars($governmentIdNumber); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="registration-section">
                    <div class="consent-card">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" <?php echo $consent ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="consent">
                                I agree to the Terms and Conditions and understand that my account will remain under <strong>Pending Verification</strong> until approved by the administrator.
                            </label>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-3 mt-4 auth-actions">
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
        });
    </script>
</body>
</html>
