<?php
/**
 * Shared notification presentation helpers (all roles).
 */

function lendingNotificationCategory(string $title, string $message): string
{
    $combined = strtolower($title . ' ' . $message);

    if (str_contains($combined, 'payment') || str_contains($combined, 'payment recorded') || str_contains($combined, 'payment reminder')) {
        return 'payment';
    }
    if (str_contains($combined, 'agreement')) {
        return 'agreement';
    }
    if (str_contains($combined, 'rejected') || str_contains($combined, 'application rejected')) {
        return 'rejected';
    }
    if (str_contains($combined, 'approved') || str_contains($combined, 'released') || str_contains($combined, 'congratulations')) {
        return 'loan';
    }
    if (str_contains($combined, 'system') || str_contains($combined, 'alert') || str_contains($combined, 'notice')) {
        return 'system';
    }

    return 'general';
}

/**
 * @return array{label: string, icon: string, accent: string}
 */
function lendingNotificationCategoryMeta(string $category): array
{
    return match ($category) {
        'payment' => ['label' => 'Payment', 'icon' => 'fa-wallet', 'accent' => 'payment'],
        'loan' => ['label' => 'Loan update', 'icon' => 'fa-circle-check', 'accent' => 'loan'],
        'agreement' => ['label' => 'Agreement', 'icon' => 'fa-file-signature', 'accent' => 'agreement'],
        'rejected' => ['label' => 'Declined', 'icon' => 'fa-circle-xmark', 'accent' => 'rejected'],
        'system' => ['label' => 'System', 'icon' => 'fa-tower-broadcast', 'accent' => 'system'],
        default => ['label' => 'General', 'icon' => 'fa-bell', 'accent' => 'general'],
    };
}

function lendingNotificationRelativeTime(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return '';
    }

    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' min' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return date('M j, Y · g:i A', $timestamp);
}

function lendingRenderNotificationMessage(string $messageText): string
{
    $safeMessage = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
    $safeMessage = preg_replace('/\b(Loan\s*#\d+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    $safeMessage = preg_replace('/\b(Borrower\s+Name:\s*[^<]+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    $safeMessage = preg_replace('/\b(Amount:\s*₱[0-9,\.]+)\b/i', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;
    $safeMessage = preg_replace('/\b(₱[0-9][0-9,]*(?:\.[0-9]{2})?)\b/', '<span class="notification-value">$1</span>', $safeMessage) ?? $safeMessage;

    return $safeMessage;
}

/**
 * @return array{badge: string, title: string, intro: string, tips: list<array{icon: string, tone: string, text: string}>}
 */
function lendingNotificationInboxContext(): array
{
    if (function_exists('isBorrower') && isBorrower()) {
        return [
            'badge' => 'Borrower inbox',
            'title' => 'Stay on top of your loan',
            'intro' => 'Alerts for applications, releases, payment confirmations, and important account updates appear here.',
            'tips' => [
                ['icon' => 'fa-calendar-check', 'tone' => 'success', 'text' => 'Review payment reminders before your due dates.'],
                ['icon' => 'fa-file-signature', 'tone' => 'primary', 'text' => 'Open agreement notices when your loan is approved.'],
                ['icon' => 'fa-check-double', 'tone' => 'info', 'text' => 'Mark items read after you have taken action.'],
            ],
        ];
    }

    if (function_exists('isCollector') && isCollector()) {
        return [
            'badge' => 'Collector alerts',
            'title' => 'Your field operations feed',
            'intro' => 'Payment postings, assigned loan updates, and system notices for your collector account.',
            'tips' => [
                ['icon' => 'fa-route', 'tone' => 'primary', 'text' => 'Check alerts before your collection route.'],
                ['icon' => 'fa-money-check-dollar', 'tone' => 'success', 'text' => 'Payment confirmations sync with your dashboard totals.'],
                ['icon' => 'fa-bell', 'tone' => 'warning', 'text' => 'Unread badges in the top bar match this inbox.'],
            ],
        ];
    }

    return [
        'badge' => 'Admin inbox',
        'title' => 'Operations command center',
        'intro' => 'Loan applications, releases, payments, and system events across the lending platform.',
        'tips' => [
            ['icon' => 'fa-file-signature', 'tone' => 'primary', 'text' => 'New borrower applications surface here first.'],
            ['icon' => 'fa-chart-line', 'tone' => 'success', 'text' => 'Cross-check large payments with Reports & Analytics.'],
            ['icon' => 'fa-trash-can', 'tone' => 'info', 'text' => 'Clear read notifications to keep the inbox focused.'],
        ],
    ];
}

function lendingNotificationFilterOptions(): array
{
    return [
        'all' => 'All',
        'unread' => 'Unread',
        'read' => 'Read',
        'payment' => 'Payments',
        'loan' => 'Loans',
        'agreement' => 'Agreements',
        'rejected' => 'Declined',
    ];
}

/**
 * @param array<string, mixed> $notification
 * @param array{truncate?: int, href?: string} $options
 */
function renderNotificationFeedItem(array $notification, array $options = []): string
{
    $title = trim((string)($notification['title'] ?? 'Notification'));
    $messageText = trim((string)($notification['message'] ?? ''));
    $createdAt = $notification['created_at'] ?? null;
    $isRead = (int)($notification['is_read'] ?? 0) === 1;
    $category = lendingNotificationCategory($title, $messageText);
    $meta = lendingNotificationCategoryMeta($category);
    $truncate = (int)($options['truncate'] ?? 100);
    $href = trim((string)($options['href'] ?? 'notifications_lending.php'));

    $preview = $messageText;
    if ($truncate > 0 && strlen($preview) > $truncate) {
        $preview = substr($preview, 0, $truncate - 3) . '...';
    }

    $relative = lendingNotificationRelativeTime(is_string($createdAt) ? $createdAt : null);
    $absolute = $createdAt ? date('M d, Y · g:i A', strtotime((string)$createdAt)) : '';

    $itemClass = 'notification-feed__item notification-feed__item--' . $meta['accent'];
    if (!$isRead) {
        $itemClass .= ' is-unread';
    }

    $html = '<a class="' . htmlspecialchars($itemClass, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="notification-feed__icon notification-feed__icon--' . htmlspecialchars($meta['accent'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">';
    $html .= '<i class="fas ' . htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') . '"></i></div>';
    $html .= '<div class="notification-feed__body min-w-0">';
    $html .= '<div class="notification-feed__row">';
    $html .= '<span class="notification-feed__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
    if (!$isRead) {
        $html .= '<span class="notification-feed__dot" aria-label="Unread"></span>';
    }
    $html .= '</div>';
    if ($preview !== '') {
        $html .= '<p class="notification-feed__preview">' . htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $html .= '<div class="notification-feed__meta">';
    $html .= '<span class="notification-feed__category">' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    if ($relative !== '') {
        $html .= '<span class="notification-feed__time" title="' . htmlspecialchars($absolute, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($relative, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div></div></a>';

    return $html;
}

function renderNotificationTopbarBell(int $unreadCount, string $href = 'notifications_lending.php'): string
{
    $bellClass = 'notification-bell icon-button';
    if ($unreadCount > 0) {
        $bellClass .= ' has-unread';
    }

    $html = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($bellClass, ENT_QUOTES, 'UTF-8') . '" aria-label="Notifications';
    if ($unreadCount > 0) {
        $html .= ', ' . (int)$unreadCount . ' unread';
    }
    $html .= '">';
    $html .= '<span class="notification-bell__icon"><i class="fas fa-bell" aria-hidden="true"></i></span>';
    if ($unreadCount > 0) {
        $display = $unreadCount > 99 ? '99+' : (string)(int)$unreadCount;
        $html .= '<span class="notification-bell__badge">' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</a>';

    return $html;
}
