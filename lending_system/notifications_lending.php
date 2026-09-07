<?php
/**
 * Notifications Page for Lending Management System
 */

require_once 'includes/auth_lending.php';
requireLogin();
require_once 'includes/db_lending.php';

$user = getCurrentUser();
$userId = (int)($user['user_id'] ?? 0);

ensureNotificationsSchema();

$message = '';
$messageType = 'success';
$notificationFilter = trim((string)($_GET['filter'] ?? 'all'));
$searchTerm = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        markAllNotificationsRead($userId);
        header('Location: notifications_lending.php');
        exit;
    }

    if (isset($_POST['mark_read']) && isset($_POST['notification_id']) && is_numeric($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        markNotificationRead($notificationId, $userId);
        header('Location: notifications_lending.php');
        exit;
    }

    if (isset($_POST['delete_notification']) && isset($_POST['notification_id']) && is_numeric($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        deleteNotification($notificationId, $userId);
        header('Location: notifications_lending.php');
        exit;
    }

    if (isset($_POST['delete_selected']) && isset($_POST['notification_ids'])) {
        $selectedIds = [];
        foreach (explode(',', (string)$_POST['notification_ids']) as $notificationIdCandidate) {
            $notificationId = (int)trim($notificationIdCandidate);
            if ($notificationId > 0) {
                $selectedIds[] = $notificationId;
            }
        }

        $selectedIds = array_values(array_unique($selectedIds));
        foreach ($selectedIds as $notificationId) {
            deleteNotification($notificationId, $userId);
        }
        header('Location: notifications_lending.php');
        exit;
    }

    if (isset($_POST['delete_all_read'])) {
        deleteReadNotifications($userId);
        header('Location: notifications_lending.php');
        exit;
    }
}

$unreadCount = getUnreadNotificationCount($userId);
$notifications = getUserNotifications($userId, 200);

function getNotificationCategory(string $title, string $message): string {
    $combined = strtolower($title . ' ' . $message);

    if (stripos($combined, 'payment') !== false || stripos($combined, 'payment recorded') !== false || stripos($combined, 'payment reminder') !== false) {
        return 'payment';
    }

    if (stripos($combined, 'agreement') !== false) {
        return 'agreement';
    }

    if (stripos($combined, 'rejected') !== false || stripos($combined, 'application rejected') !== false) {
        return 'rejected';
    }

    if (stripos($combined, 'approved') !== false || stripos($combined, 'released') !== false || stripos($combined, 'congratulations') !== false) {
        return 'loan';
    }

    if (stripos($combined, 'system') !== false || stripos($combined, 'alert') !== false || stripos($combined, 'notice') !== false) {
        return 'system';
    }

    return 'general';
}

function getNotificationIconClass(string $title, string $message): string {
    $category = getNotificationCategory($title, $message);

    return match ($category) {
        'payment' => 'bi-wallet2',
        'loan' => 'bi-check-circle',
        'agreement' => 'bi-file-earmark-text',
        'rejected' => 'bi-exclamation-triangle',
        'system' => 'bi-bell',
        default => 'bi-bell',
    };
}

function getNotificationCardClass(bool $isRead, string $category): string {
    $base = 'notification-card';
    if ($isRead) {
        $base .= ' read';
    } else {
        $base .= ' unread';
        if ($category === 'payment') {
            $base .= ' payment';
        } elseif ($category === 'loan') {
            $base .= ' loan';
        } elseif ($category === 'agreement') {
            $base .= ' agreement';
        } elseif ($category === 'rejected') {
            $base .= ' rejected';
        }
    }

    return $base;
}

$filteredNotifications = [];
foreach ($notifications as $notification) {
    $title = trim((string)($notification['title'] ?? ''));
    $messageText = trim((string)($notification['message'] ?? ''));
    $category = getNotificationCategory($title, $messageText);
    $matchesFilter = true;

    if ($notificationFilter !== '' && $notificationFilter !== 'all') {
        switch ($notificationFilter) {
            case 'unread':
                $matchesFilter = (int)$notification['is_read'] === 0;
                break;
            case 'read':
                $matchesFilter = (int)$notification['is_read'] === 1;
                break;
            case 'payment':
                $matchesFilter = $category === 'payment';
                break;
            case 'loan':
                $matchesFilter = $category === 'loan';
                break;
            case 'agreement':
                $matchesFilter = $category === 'agreement';
                break;
            case 'rejected':
                $matchesFilter = $category === 'rejected';
                break;
            default:
                $matchesFilter = true;
        }
    }

    $matchesSearch = true;
    if ($searchTerm !== '') {
        $matchesSearch = stripos($title . ' ' . $messageText, $searchTerm) !== false;
    }

    if ($matchesFilter && $matchesSearch) {
        $notification['category'] = $category;
        $filteredNotifications[] = $notification;
    }
}

$notifications = $filteredNotifications;

function renderNotificationMessage(string $messageText): string {
    $safeMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
    $safeMessage = preg_replace('/\b(Loan\s*#\d+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    $safeMessage = preg_replace('/\b(Borrower\s+Name:\s*[^<]+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    $safeMessage = preg_replace('/\b(Amount:\s*₱[0-9,\.]+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    return $safeMessage;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Lending Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/lending_styles.css">
</head>
<body>
    <?php include 'includes/nav_lending.php'; ?>

    <div class="container-fluid py-4">
        <div class="page-hero">
            <div>
                <h1 class="page-title"><i class="fas fa-bell me-2"></i>Notifications</h1>
                <p class="page-subtitle">Manage your notifications, review unread messages, and stay updated with account activity.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card shadow-sm border-0 notification-shell">
                    <div class="card-header bg-white py-3 border-0">
                        <div class="notification-summary">
                            <div>
                                <h6 class="m-0 fw-bold">Your Notifications</h6>
                                <div class="text-muted small mt-1">You have <span class="badge unread-counter-badge"><?php echo $unreadCount; ?></span> unread notification<?php echo $unreadCount !== 1 ? 's' : ''; ?>.</div>
                            </div>
                            <div class="notification-toolbar">
                                <form method="get" class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center mb-0">
                                    <div class="input-group input-group-sm notification-search-box">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search notifications">
                                    </div>
                                    <select class="form-select form-select-sm notification-filter-select" name="filter" onchange="this.form.submit()">
                                        <option value="all" <?php echo ($notificationFilter === 'all' || $notificationFilter === '') ? 'selected' : ''; ?>>All Notifications</option>
                                        <option value="unread" <?php echo $notificationFilter === 'unread' ? 'selected' : ''; ?>>Unread</option>
                                        <option value="read" <?php echo $notificationFilter === 'read' ? 'selected' : ''; ?>>Read</option>
                                        <option value="payment" <?php echo $notificationFilter === 'payment' ? 'selected' : ''; ?>>Payment</option>
                                        <option value="loan" <?php echo $notificationFilter === 'loan' ? 'selected' : ''; ?>>Loan Updates</option>
                                        <option value="agreement" <?php echo $notificationFilter === 'agreement' ? 'selected' : ''; ?>>Agreements</option>
                                        <option value="rejected" <?php echo $notificationFilter === 'rejected' ? 'selected' : ''; ?>>Rejected Applications</option>
                                    </select>
                                </form>
                                <form method="post" class="mb-0">
                                    <button type="submit" name="mark_all_read" class="btn btn-sm btn-primary modern-btn"<?php echo $unreadCount === 0 ? ' disabled' : ''; ?>><i class="fas fa-check-double me-1"></i>Mark All as Read</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($notifications) === 0): ?>
                            <div class="text-center text-muted py-5 empty-state-box">
                                <i class="fas fa-bell-slash fa-2x mb-3"></i>
                                <div>No notifications found for the selected filter.</div>
                            </div>
                        <?php else: ?>
                            <div class="notification-list-header">
                                <div class="form-check d-flex align-items-center gap-2">
                                    <input class="form-check-input" type="checkbox" id="selectAllNotifications">
                                    <label class="form-check-label text-muted small mb-0" for="selectAllNotifications">Select all</label>
                                </div>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <button type="button" class="btn btn-sm btn-outline-danger modern-btn" id="deleteSelectedBtn" disabled>
                                        <i class="fas fa-trash me-1"></i>Delete Selected
                                    </button>
                                    <span class="text-muted small" id="selectedCountText">0 selected</span>
                                    <form method="post" class="mb-0">
                                        <button type="button" class="btn btn-sm btn-outline-secondary modern-btn" data-bs-toggle="modal" data-bs-target="#deleteReadModal"><i class="fas fa-trash-alt me-1"></i>Delete All Read</button>
                                    </form>
                                </div>
                            </div>
                            <div class="notification-list">
                                <?php foreach ($notifications as $notification): ?>
                                    <?php
                                    $notificationId = (int)($notification['id'] ?? 0);
                                    $title = trim((string)($notification['title'] ?? ''));
                                    $messageText = trim((string)($notification['message'] ?? ''));
                                    $createdAt = $notification['created_at'] ?? null;
                                    $isRead = (int)($notification['is_read'] ?? 0) === 1;
                                    $category = $notification['category'] ?? getNotificationCategory($title, $messageText);
                                    $icon = '';
                                    ?>
                                    <div class="notification-card <?php echo $isRead ? 'read' : 'unread'; ?>">
                                        <div class="notification-card__left">
                                            <div class="notification-card__content">
                                                <div class="notification-card__header">
                                                    <h6 class="notification-title mb-0"><?php echo htmlspecialchars($title); ?></h6>
                                                    <span class="notification-status-pill <?php echo $isRead ? 'read' : 'unread'; ?>"><?php echo $isRead ? 'Read' : 'Unread'; ?></span>
                                                </div>
                                                <p class="notification-message mb-0"><?php echo htmlspecialchars($messageText); ?></p>
                                                <div class="notification-meta">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('M d, Y H:i', strtotime($createdAt)); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="notification-card__actions">
                                            <div class="form-check me-2">
                                                <input class="form-check-input notification-select" type="checkbox" value="<?php echo $notificationId; ?>" id="notification-<?php echo $notificationId; ?>">
                                                <label class="visually-hidden" for="notification-<?php echo $notificationId; ?>">Select notification</label>
                                            </div>
                                            <?php if (!$isRead): ?>
                                                <form method="post" class="mb-0" id="markReadForm-<?php echo $notificationId; ?>">
                                                    <input type="hidden" name="notification_id" value="<?php echo $notificationId; ?>">
                                                    <input type="hidden" name="mark_read" value="1">
                                                </form>
                                                <button type="submit" class="btn btn-icon btn-outline-secondary" form="markReadForm-<?php echo $notificationId; ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="Mark as Read">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-icon btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteNotificationModal" data-notification-id="<?php echo $notificationId; ?>" title="Delete Notification" data-bs-placement="top">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card shadow-sm border-0 notification-shell">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="m-0 fw-bold">Notification Tips</h6>
                    </div>
                    <div class="card-body notification-side-card">
                        <div class="notification-side-card__badge">Borrower Inbox</div>
                        <p class="mb-3">This page shows alerts for your borrower account, loan activity, payments, and system updates.</p>
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Keep your payment schedule up to date.</li>
                            <li class="mb-2"><i class="fas fa-info-circle text-primary me-2"></i>Important system messages appear here first.</li>
                            <li><i class="fas fa-bell text-warning me-2"></i>Mark notifications read after reviewing them.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteNotificationModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Notification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Are you sure you want to delete this notification?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="post" class="mb-0">
                            <input type="hidden" name="notification_id" id="deleteNotificationId">
                            <input type="hidden" name="delete_notification" value="1">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteReadModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete All Read</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">This will remove all notifications that have already been read. Continue?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="post" class="mb-0">
                            <input type="hidden" name="delete_all_read" value="1">
                            <button type="submit" class="btn btn-danger">Confirm</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Selected</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">Are you sure you want to delete the selected notifications?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="post" class="mb-0" id="deleteSelectedForm">
                            <input type="hidden" name="delete_selected" value="1">
                            <input type="hidden" name="notification_ids" id="deleteSelectedIds">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            var deleteNotificationModal = document.getElementById('deleteNotificationModal');
            if (deleteNotificationModal) {
                deleteNotificationModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var notificationId = button.getAttribute('data-notification-id');
                    document.getElementById('deleteNotificationId').value = notificationId;
                });
            }

            var selectAllNotifications = document.getElementById('selectAllNotifications');
            var selectedCountText = document.getElementById('selectedCountText');
            var deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
            var notificationCheckboxes = document.querySelectorAll('.notification-select');

            function updateSelectionControls() {
                var selected = Array.from(document.querySelectorAll('.notification-select:checked')).map(function (checkbox) {
                    return checkbox.value;
                });

                if (selectedCountText) {
                    selectedCountText.textContent = selected.length + ' selected';
                }

                if (deleteSelectedBtn) {
                    deleteSelectedBtn.disabled = selected.length === 0;
                }

                if (selectAllNotifications) {
                    selectAllNotifications.checked = notificationCheckboxes.length > 0 && selected.length === notificationCheckboxes.length;
                }
            }

            if (selectAllNotifications) {
                selectAllNotifications.addEventListener('change', function () {
                    document.querySelectorAll('.notification-select').forEach(function (checkbox) {
                        checkbox.checked = selectAllNotifications.checked;
                    });
                    updateSelectionControls();
                });
            }

            notificationCheckboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', updateSelectionControls);
            });

            updateSelectionControls();

            if (deleteSelectedBtn) {
                deleteSelectedBtn.addEventListener('click', function () {
                    var selected = Array.from(document.querySelectorAll('.notification-select:checked')).map(function (checkbox) {
                        return checkbox.value;
                    });

                    if (selected.length === 0) {
                        return;
                    }

                    document.getElementById('deleteSelectedIds').value = selected.join(',');
                    var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteSelectedModal'));
                    modal.show();
                });
            }
        });
    </script>
</body>
</html>
