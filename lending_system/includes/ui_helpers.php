<?php
/**
 * Shared UI rendering helpers (presentation only).
 */

function renderLoanStatusPill(string $status): string
{
    $normalized = strtolower(trim($status));
    $class = match ($normalized) {
        'active' => 'status-pill--active',
        'paid', 'fully paid' => 'status-pill--paid',
        'overdue' => 'status-pill--overdue',
        'pending', 'approved' => 'status-pill--pending',
        'rejected', 'cancelled', 'canceled' => 'status-pill--rejected',
        default => 'status-pill--pending',
    };

    return '<span class="status-pill ' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

function renderApplicationStatusPill(string $status): string
{
    $normalized = strtolower(trim($status));
    $class = 'status-pill--pending';

    if (str_contains($normalized, 'reject') || str_contains($normalized, 'declin')) {
        $class = 'status-pill--rejected';
    } elseif (str_contains($normalized, 'release') || str_contains($normalized, 'accept') || str_contains($normalized, 'approv')) {
        $class = 'status-pill--active';
    } elseif (str_contains($normalized, 'incomplete') || str_contains($normalized, 'pending') || str_contains($normalized, 'review')) {
        $class = 'status-pill--pending';
    } elseif (str_contains($normalized, 'overdue')) {
        $class = 'status-pill--overdue';
    }

    return '<span class="status-pill ' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

function adminDashboardHref(): string
{
    if (function_exists('isCollector') && isCollector()) {
        return 'collector_dashboard_lending.php';
    }
    return 'dashboard_lending.php';
}

function renderAdminDashboardHeader(string $title, string $subtitle, string $iconClass, string $crumbCurrent = '', string $actionsHtml = ''): string
{
    $home = adminDashboardHref();
    $html = '<div class="dashboard-header">';
    $html .= '<div><div class="dashboard-breadcrumb"><a href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">Home</a>';
    if ($crumbCurrent !== '') {
        $html .= ' / ' . htmlspecialchars($crumbCurrent, ENT_QUOTES, 'UTF-8');
    }
    $html .= '</div>';
    $html .= '<h1 class="page-title mb-1"><i class="fas ' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . ' me-2"></i>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<p class="page-subtitle mb-0">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p></div>';
    if ($actionsHtml !== '') {
        $html .= '<div class="quick-actions">' . $actionsHtml . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function lendingPaymentReferenceLabel(?string $method, ?string $reference): string
{
    $method = strtolower(trim((string)$method));
    $needsReference = str_contains($method, 'bank') || str_contains($method, 'wallet');
    $reference = trim((string)$reference);
    if (!$needsReference || $reference === '') {
        return '—';
    }

    return $reference;
}

function renderEmptyState(string $iconClass, string $title, string $description = ''): string
{
    $html = '<div class="empty-state"><div class="empty-state__icon"><i class="fas ' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i></div>';
    $html .= '<h3 class="h5 mb-2">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
    if ($description !== '') {
        $html .= '<p class="mb-0">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $html .= '</div>';
    return $html;
}
