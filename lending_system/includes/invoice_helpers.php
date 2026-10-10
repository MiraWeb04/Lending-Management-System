<?php
/**
 * Shared invoice / receipt presentation helpers (no business logic).
 */

require_once __DIR__ . '/env_lending.php';

function invoiceBrand(): array
{
    return [
        'legal_name' => (string)lendingEnv('COMPANY_NAME', 'RJ and RR Finance Services'),
        'tagline' => (string)lendingEnv('COMPANY_TAGLINE', 'Official lending & collections documentation'),
        'address' => (string)lendingEnv('COMPANY_ADDRESS', 'Purok San Francisco, Poblacion, Sominot, ZDS'),
        'phone' => (string)lendingEnv('COMPANY_PHONE', '+63 9817074262'),
        'email' => (string)lendingEnv('COMPANY_EMAIL', 'RJ&RRservices@gmail.com'),
        'logo' => 'images/logo.png',
    ];
}

function invoiceMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function renderInvoiceBrandContactList(): string
{
    $brand = invoiceBrand();
    $html = '<ul class="invoice-brand__contact">';
    $html .= '<li><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span>' . htmlspecialchars($brand['address'], ENT_QUOTES, 'UTF-8') . '</span></li>';
    $html .= '<li><i class="fas fa-phone" aria-hidden="true"></i><span>' . htmlspecialchars($brand['phone'], ENT_QUOTES, 'UTF-8') . '</span></li>';
    $html .= '<li><i class="fas fa-envelope" aria-hidden="true"></i><span>' . htmlspecialchars($brand['email'], ENT_QUOTES, 'UTF-8') . '</span></li>';
    $html .= '</ul>';
    return $html;
}

function renderInvoiceDocumentHeader(string $documentTypeLabel, string $documentNumber, string $documentDateDisplay): string
{
    $brand = invoiceBrand();
    $html = '<header class="invoice-header">';
    $html .= '<div class="invoice-brand">';
    $html .= '<div class="invoice-brand__logo"><img src="' . htmlspecialchars($brand['logo'], ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($brand['legal_name'], ENT_QUOTES, 'UTF-8') . ' logo"></div>';
    $html .= '<div>';
    $html .= '<h2 class="invoice-brand__name">' . htmlspecialchars($brand['legal_name'], ENT_QUOTES, 'UTF-8') . '</h2>';
    $html .= '<p class="invoice-brand__tagline">' . htmlspecialchars($brand['tagline'], ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= renderInvoiceBrandContactList();
    $html .= '</div></div>';
    $html .= '<div class="invoice-meta">';
    $html .= '<span class="invoice-meta__doctype">' . htmlspecialchars($documentTypeLabel, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<p class="invoice-meta__number">' . htmlspecialchars($documentNumber, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<p class="invoice-meta__date">' . htmlspecialchars($documentDateDisplay, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '</div></header>';
    return $html;
}
