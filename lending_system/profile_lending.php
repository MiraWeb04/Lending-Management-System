<?php
/**
 * Profile Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Require login to access this page
requireLogin();

// Get current user data
$user = getCurrentUser();

// Include database connection
require_once 'includes/db_lending.php';

// Initialize variables
$errorMsg = '';
$successMsg = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update profile
    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        
        // Validate input
        if (empty($fullName) || empty($email)) {
            $errorMsg = 'Name and email are required';
        } else {
            // Update user profile
            $updateQuery = "UPDATE users SET full_name = ?, email = ? WHERE user_id = ?";
            $updateParams = [$fullName, $email, $user['user_id']];
            
            try {
                executeQuery($updateQuery, $updateParams);
                $successMsg = 'Profile updated successfully';
                
                // Update session data
                $_SESSION['full_name'] = $fullName;
                $_SESSION['email'] = $email;
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['email'] = $email;
                
                // Refresh user data
                $user = getCurrentUser();
            } catch (PDOException $e) {
                $errorMsg = 'Error updating profile: ' . $e->getMessage();
            }
        }
    }
    
    // Change password
    elseif (isset($_POST['change_password'])) {
        $currentPassword = trim($_POST['current_password']);
        $newPassword = trim($_POST['new_password']);
        $confirmPassword = trim($_POST['confirm_password']);
        
        // Validate input
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $errorMsg = 'All password fields are required';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMsg = 'New passwords do not match';
        } else {
            // Verify current password
            $passwordQuery = "SELECT password FROM users WHERE user_id = ?";
            $passwordResult = executeQuery($passwordQuery, [$user['user_id']]);
            $userData = $passwordResult->fetch();
            
            if (!passwordMatches($currentPassword, $userData['password'])) {
                $errorMsg = 'Current password is incorrect';
            } else {
                // Keep compatibility with existing plaintext passwords and newer hashes
                $updatedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update password
                $updateQuery = "UPDATE users SET password = ? WHERE user_id = ?";
                $updateParams = [$updatedPassword, $user['user_id']];
                
                try {
                    executeQuery($updateQuery, $updateParams);
                    $successMsg = 'Password changed successfully';
                } catch (PDOException $e) {
                    $errorMsg = 'Error changing password: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Lending Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <!-- Main Content -->
    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-user-circle me-2"></i>My Profile
                </h1>
                <p class="page-subtitle">Keep your account details and security settings up to date.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo $errorMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?php echo $successMsg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Profile Information -->
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-user me-2"></i>Profile Information</h6>
                    </div>
                    <div class="card-body">
                        <form action="profile_lending.php" method="post">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                                <div class="form-text text-muted">Username cannot be changed</div>
                            </div>
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required autocomplete="name">
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required autocomplete="email">
                            </div>
                           
                            <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <input type="text" class="form-control" id="role" value="<?php echo ucfirst(htmlspecialchars($user['role'])); ?>" disabled>
                            </div>
                           
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Change Password -->
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-key me-2"></i>Change Password</h6>
                    </div>
                    <div class="card-body">
                        <form action="profile_lending.php" method="post">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            </div>
                            <div class="mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="fas fa-info-circle me-1"></i>Password Guidelines</h6>
                                        <ul class="mb-0 ps-3">
                                            <li>Use at least 8 characters</li>
                                            <li>Include uppercase and lowercase letters</li>
                                            <li>Include at least one number</li>
                                            <li>Include at least one special character</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="fas fa-key me-1"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Account Activity -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-shield-alt me-2"></i>Account Security</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="flex-shrink-0">
                                <i class="fas fa-check-circle text-success fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="mb-0">Account Status</h6>
                                <p class="mb-0 text-muted">Your account is active and in good standing</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-calendar-alt text-primary fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="mb-0">Account Created</h6>
                                <p class="mb-0 text-muted"><?php echo date('F d, Y', strtotime($user['created_at'] ?? date('Y-m-d'))); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
<?php include 'includes/nav_footer_lending.php'; ?>

        <div class="container-fluid px-4">
            <div class="d-flex align-items-center justify-content-between small">
                <div class="text-muted">Copyright &copy; Lending Management System <?php echo date('Y'); ?></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
