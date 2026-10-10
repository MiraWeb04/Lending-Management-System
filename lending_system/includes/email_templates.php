<?php
/**
 * Email templates for Lending System
 */
function renderEmailTemplate(string $title, string $htmlContent): string
{
    $host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $company = 'Lending System';

    $style = "background-color:#f4f7fb;margin:0;padding:20px;font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;";
    $cardStyle = "max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.06);";
    $headerStyle = "background:linear-gradient(90deg,#1565c0,#1976d2);color:#fff;padding:20px;text-align:center;";
    $titleStyle = "font-size:18px;margin:0;text-align:center;";
    $bodyStyle = "padding:24px;color:#1f2937;line-height:1.6;font-size:15px;";
    $footerStyle = "padding:16px;background:#fafafa;color:#6b7280;text-align:center;font-size:13px;";

    $html = <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"></head>
<body style="{$style}">
  <div style="{$cardStyle}">
    <div style="{$headerStyle}">
      <h1 style="{$titleStyle}">{$title}</h1>
    </div>
    <div style="{$bodyStyle}">
      {$htmlContent}
    </div>
    <div style="{$footerStyle}">
      &mdash; {$company}
    </div>
  </div>
  </body>
</html>
HTML;

    return $html;
}
