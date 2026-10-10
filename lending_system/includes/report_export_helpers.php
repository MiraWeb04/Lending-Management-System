<?php
/**
 * Report export payload builders and CSV streaming (admin reports module).
 */

function lendingPeriodModeLabels(): array
{
    return [
        'daily' => 'Daily',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'custom' => 'Custom range',
    ];
}

function lendingYearOptions(int $yearsBack = 12): array
{
    return range((int)date('Y'), (int)date('Y') - max(0, $yearsBack));
}

function resolveReportPeriodRange(string $mode, array $input): array
{
    $today = new DateTimeImmutable('today');
    $allowed = ['daily', 'monthly', 'yearly', 'custom'];
    $mode = in_array($mode, $allowed, true) ? $mode : 'monthly';

    $result = [
        'period_mode' => $mode,
        'start_date' => $today->format('Y-m-d'),
        'end_date' => $today->format('Y-m-d'),
        'report_date' => $today->format('Y-m-d'),
        'report_month' => $today->format('Y-m'),
        'report_year' => (int)$today->format('Y'),
        'chart_granularity' => 'day',
        'period_label' => 'Today',
    ];

    if ($mode === 'daily') {
        $reportDate = trim((string)($input['report_date'] ?? $input['start_date'] ?? ''));
        if ($reportDate === '' || !strtotime($reportDate)) {
            $reportDate = $today->format('Y-m-d');
        }
        $day = DateTimeImmutable::createFromFormat('Y-m-d', date('Y-m-d', strtotime($reportDate))) ?: $today;
        $result['start_date'] = $day->format('Y-m-d');
        $result['end_date'] = $day->format('Y-m-d');
        $result['report_date'] = $day->format('Y-m-d');
        $result['report_month'] = $day->format('Y-m');
        $result['report_year'] = (int)$day->format('Y');
        $result['chart_granularity'] = 'day';
        $result['period_label'] = $day->format('F d, Y');
        return $result;
    }

    if ($mode === 'monthly') {
        $reportMonth = trim((string)($input['report_month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $reportMonth)) {
            $fallback = trim((string)($input['start_date'] ?? ''));
            $reportMonth = ($fallback !== '' && strtotime($fallback)) ? date('Y-m', strtotime($fallback)) : $today->format('Y-m');
        }
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $reportMonth . '-01') ?: $today->modify('first day of this month');
        $end = $start->modify('last day of this month');
        if ($end > $today) {
            $end = $today;
        }
        $result['start_date'] = $start->format('Y-m-d');
        $result['end_date'] = $end->format('Y-m-d');
        $result['report_date'] = $end->format('Y-m-d');
        $result['report_month'] = $reportMonth;
        $result['report_year'] = (int)$start->format('Y');
        $result['chart_granularity'] = 'day';
        $result['period_label'] = $start->format('F Y');
        return $result;
    }

    if ($mode === 'yearly') {
        $reportYear = (int)($input['report_year'] ?? 0);
        if ($reportYear < 2000 || $reportYear > 2100) {
            $reportYear = (int)date('Y', strtotime((string)($input['start_date'] ?? 'now')));
        }
        if ($reportYear < 2000 || $reportYear > 2100) {
            $reportYear = (int)$today->format('Y');
        }
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $reportYear . '-01-01') ?: $today;
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $reportYear . '-12-31') ?: $today;
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $end) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $reportYear . '-01-01') ?: $today;
        }
        $result['start_date'] = $start->format('Y-m-d');
        $result['end_date'] = $end->format('Y-m-d');
        $result['report_date'] = $end->format('Y-m-d');
        $result['report_month'] = $end->format('Y-m');
        $result['report_year'] = $reportYear;
        $result['chart_granularity'] = 'month';
        $result['period_label'] = (string)$reportYear;
        return $result;
    }

    // Custom range
    $startRaw = trim((string)($input['start_date'] ?? ''));
    $endRaw = trim((string)($input['end_date'] ?? ''));
    if ($startRaw === '' || !strtotime($startRaw)) {
        $startRaw = $today->modify('first day of this month')->format('Y-m-d');
    }
    if ($endRaw === '' || !strtotime($endRaw)) {
        $endRaw = $today->format('Y-m-d');
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d', date('Y-m-d', strtotime($startRaw))) ?: $today;
    $end = DateTimeImmutable::createFromFormat('Y-m-d', date('Y-m-d', strtotime($endRaw))) ?: $today;
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }
    if ($end > $today) {
        $end = $today;
    }
    $days = (int)$start->diff($end)->days + 1;
    $granularity = 'month';
    if ($days <= 45) {
        $granularity = 'day';
    } elseif ($days > 730) {
        $granularity = 'year';
    }

    $result['period_mode'] = 'custom';
    $result['start_date'] = $start->format('Y-m-d');
    $result['end_date'] = $end->format('Y-m-d');
    $result['report_date'] = $end->format('Y-m-d');
    $result['report_month'] = $start->format('Y-m');
    $result['report_year'] = (int)$start->format('Y');
    $result['chart_granularity'] = $granularity;
    $result['period_label'] = $start->format('M d, Y') . ' – ' . $end->format('M d, Y');
    return $result;
}

function buildReportTrendSeries(string $startDate, string $endDate, string $granularity): array
{
    require_once __DIR__ . '/db_lending.php';

    $labels = [];
    $bucketKeys = [];
    $collections = [];
    $expenses = [];

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    if ($granularity === 'year') {
        $cursor = DateTimeImmutable::createFromFormat('Y-m-d', $start->format('Y') . '-01-01') ?: $start;
        $endYear = (int)$end->format('Y');
        for ($y = (int)$cursor->format('Y'); $y <= $endYear; $y++) {
            $key = (string)$y;
            $labels[] = $key;
            $bucketKeys[] = $key;
            $collections[$key] = 0.0;
            $expenses[$key] = 0.0;
        }
        $paymentSql = 'SELECT DATE_FORMAT(payment_date, "%Y") AS bucket, COALESCE(SUM(amount_paid), 0) AS total
            FROM payments WHERE payment_date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
        $expenseSql = 'SELECT DATE_FORMAT(date, "%Y") AS bucket, COALESCE(SUM(amount), 0) AS total
            FROM expenses WHERE date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
    } elseif ($granularity === 'month') {
        $cursor = $start->modify('first day of this month');
        $endCursor = $end->modify('first day of next month');
        $interval = new DateInterval('P1M');
        foreach (new DatePeriod($cursor, $interval, $endCursor) as $month) {
            $key = $month->format('Y-m');
            $labels[] = $month->format('M Y');
            $bucketKeys[] = $key;
            $collections[$key] = 0.0;
            $expenses[$key] = 0.0;
        }
        $paymentSql = 'SELECT DATE_FORMAT(payment_date, "%Y-%m") AS bucket, COALESCE(SUM(amount_paid), 0) AS total
            FROM payments WHERE payment_date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
        $expenseSql = 'SELECT DATE_FORMAT(date, "%Y-%m") AS bucket, COALESCE(SUM(amount), 0) AS total
            FROM expenses WHERE date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
    } else {
        $cursor = $start;
        $endCursor = $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        foreach (new DatePeriod($cursor, $interval, $endCursor) as $day) {
            $key = $day->format('Y-m-d');
            $labels[] = $day->format('M d');
            $bucketKeys[] = $key;
            $collections[$key] = 0.0;
            $expenses[$key] = 0.0;
        }
        $paymentSql = 'SELECT DATE_FORMAT(payment_date, "%Y-%m-%d") AS bucket, COALESCE(SUM(amount_paid), 0) AS total
            FROM payments WHERE payment_date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
        $expenseSql = 'SELECT DATE_FORMAT(date, "%Y-%m-%d") AS bucket, COALESCE(SUM(amount), 0) AS total
            FROM expenses WHERE date BETWEEN ? AND ? GROUP BY bucket ORDER BY bucket';
    }

    $paymentRows = executeQuery($paymentSql, [$startDate, $endDate])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($paymentRows as $row) {
        $bucket = (string)($row['bucket'] ?? '');
        if (array_key_exists($bucket, $collections)) {
            $collections[$bucket] = (float)($row['total'] ?? 0);
        }
    }

    $expenseRows = executeQuery($expenseSql, [$startDate, $endDate])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expenseRows as $row) {
        $bucket = (string)($row['bucket'] ?? '');
        if (array_key_exists($bucket, $expenses)) {
            $expenses[$bucket] = (float)($row['total'] ?? 0);
        }
    }

    return [
        'labels' => $labels,
        'collections' => array_values($collections),
        'expenses' => array_values($expenses),
        'granularity' => $granularity,
    ];
}

function buildReportsFilterQuery(array $params): string
{
    $allowed = [
        'type', 'period_mode', 'report_date', 'report_month', 'report_year',
        'start_date', 'end_date', 'collector_id', 'client_id', 'search', 'status',
    ];
    $filtered = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $params)) {
            continue;
        }
        $value = $params[$key];
        if ($value === '' || $value === null) {
            continue;
        }
        $filtered[$key] = $value;
    }
    return http_build_query($filtered);
}

function streamReportCsv(array $payload, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }

    // UTF-8 BOM for Excel compatibility
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, [(string)($payload['title'] ?? 'Report')]);
    fputcsv($out, ['Report type', (string)($payload['type'] ?? '')]);
    if (!empty($payload['period_mode'])) {
        fputcsv($out, ['Period mode', (string)$payload['period_mode']]);
    }
    fputcsv($out, ['Period', (string)($payload['period_label'] ?? ((string)($payload['start_date'] ?? '') . ' to ' . (string)($payload['end_date'] ?? '')))]);
    fputcsv($out, ['Generated', (string)($payload['generated_at'] ?? date('Y-m-d H:i:s'))]);
    fputcsv($out, []);

    if (!empty($payload['summary']) && is_array($payload['summary'])) {
        fputcsv($out, ['Summary metrics']);
        foreach ($payload['summary'] as $label => $value) {
            fputcsv($out, [(string)$label, (string)$value]);
        }
        fputcsv($out, []);
    }

    if (!empty($payload['tables']) && is_array($payload['tables'])) {
        foreach ($payload['tables'] as $table) {
            fputcsv($out, [(string)($table['name'] ?? 'Data')]);
            if (!empty($table['headers'])) {
                fputcsv($out, array_map('strval', $table['headers']));
            }
            foreach ($table['rows'] ?? [] as $row) {
                fputcsv($out, array_map('strval', $row));
            }
            fputcsv($out, []);
        }
    }

    fclose($out);
}

function buildReportExportPayload(string $reportType, array $ctx): array
{
    $payload = [
        'title' => (string)($ctx['report_label'] ?? 'Reports & Analytics'),
        'type' => $reportType,
        'start_date' => (string)($ctx['start_date'] ?? ''),
        'end_date' => (string)($ctx['end_date'] ?? ''),
        'period_mode' => (string)($ctx['period_mode'] ?? ''),
        'period_label' => (string)($ctx['period_label'] ?? ''),
        'generated_at' => date('Y-m-d H:i:s'),
        'summary' => [],
        'tables' => [],
    ];

    $granularity = (string)($ctx['chart_granularity'] ?? 'month');
    $trendPeriodLabel = match ($granularity) {
        'day' => 'Day',
        'year' => 'Year',
        default => 'Month',
    };

    $months = $ctx['monthly_labels'] ?? [];
    $collections = array_values($ctx['monthly_collection'] ?? []);
    $expenses = array_values($ctx['monthly_expense'] ?? []);

    switch ($reportType) {
        case 'dashboard':
            $payload['summary'] = [
                'Borrowers' => (string)($ctx['total_borrowers'] ?? 0),
                'Active loans' => (string)($ctx['active_loans'] ?? 0),
                'Overdue loans' => (string)($ctx['overdue_loans'] ?? 0),
                'Collections (period)' => (string)($ctx['total_collected_fmt'] ?? ''),
                'Disbursed (period)' => (string)($ctx['total_disbursed_fmt'] ?? ''),
                'Expenses (period)' => (string)($ctx['total_expenses_fmt'] ?? ''),
                'Net income (period)' => (string)($ctx['net_income_fmt'] ?? ''),
                'Profit margin' => (string)($ctx['profit_margin_fmt'] ?? ''),
                'Outstanding balance' => (string)($ctx['total_outstanding_fmt'] ?? ''),
            ];
            $trendRows = [];
            foreach ($months as $i => $label) {
                $col = (float)($collections[$i] ?? 0);
                $exp = (float)($expenses[$i] ?? 0);
                $trendRows[] = [$label, $col, $exp, $col - $exp];
            }
            $dailyTitle = match ($granularity) {
                'day' => 'Daily collections and expenses',
                'year' => 'Yearly collections and expenses',
                default => 'Daily collections and expenses',
            };
            $payload['tables'][] = [
                'name' => $dailyTitle,
                'style' => 'bordered',
                'headers' => ['Date', 'Collections', 'Expenses', 'Net'],
                'rows' => $trendRows,
            ];
            $loanStatus = $ctx['loan_status'] ?? [];
            $statusRows = [];
            foreach ($loanStatus as $status => $data) {
                $statusRows[] = [
                    $status,
                    $data['count'] ?? 0,
                    $data['amount'] ?? 0,
                    $data['payable'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Loan portfolio by status',
                'style' => 'bordered',
                'headers' => ['Status', 'Count', 'Principal (PHP)', 'Total payable (PHP)'],
                'rows' => $statusRows,
            ];
            break;

        case 'loan_reports':
            $payload['summary'] = [
                'Total loans' => (string)($ctx['total_loans'] ?? 0),
                'Active' => (string)($ctx['active_loans'] ?? 0),
                'Overdue' => (string)($ctx['overdue_loans'] ?? 0),
                'Paid' => (string)($ctx['paid_loans'] ?? 0),
                'Outstanding receivable' => (string)($ctx['total_outstanding_fmt'] ?? ''),
            ];
            $loanStatus = $ctx['loan_status'] ?? [];
            $statusRows = [];
            foreach ($loanStatus as $status => $data) {
                $statusRows[] = [
                    $status,
                    $data['count'] ?? 0,
                    $data['amount'] ?? 0,
                    $data['payable'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Loan status breakdown',
                'style' => 'bordered',
                'headers' => ['Status', 'Count', 'Principal (PHP)', 'Total payable (PHP)'],
                'rows' => $statusRows,
            ];
            break;

        case 'payment_reports':
            $payload['summary'] = [
                'Payment count' => (string)($ctx['payment_summary']['count'] ?? 0),
                'Total collected' => (string)($ctx['total_collected_fmt'] ?? ''),
                'Average payment' => (string)($ctx['payment_summary_avg_fmt'] ?? ''),
            ];
            $collectorRows = [];
            foreach ($ctx['payments_by_collector'] ?? [] as $row) {
                $collectorRows[] = [
                    $row['collector_name'] ?? '',
                    $row['total_collected'] ?? 0,
                    $row['payments_count'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Collections by collector',
                'style' => 'bordered',
                'headers' => ['Collector', 'Collected (PHP)', 'Payment count'],
                'rows' => $collectorRows,
            ];
            $paymentRows = [];
            foreach ($ctx['recent_payments'] ?? [] as $row) {
                $paymentRows[] = [
                    $row['payment_date'] ?? '',
                    $row['receipt_number'] ?? '',
                    $row['borrower_name'] ?? '',
                    $row['loan_number'] ?? '',
                    $row['amount_paid'] ?? 0,
                    $row['collector_name'] ?? '',
                ];
            }
            $payload['tables'][] = [
                'name' => 'Payments in selected period',
                'style' => 'bordered',
                'headers' => ['Date', 'Receipt', 'Borrower', 'Loan #', 'Amount (PHP)', 'Collector'],
                'rows' => $paymentRows,
            ];
            break;

        case 'borrower_reports':
            $payload['summary'] = [
                'Total borrowers' => (string)($ctx['total_borrowers'] ?? 0),
                'Active loans (portfolio)' => (string)($ctx['active_loans'] ?? 0),
                'Outstanding (portfolio)' => (string)($ctx['total_outstanding_fmt'] ?? ''),
            ];
            $borrowerRows = [];
            foreach ($ctx['borrower_data'] ?? [] as $row) {
                $borrowerRows[] = [
                    $row['borrower_name'] ?? '',
                    $row['loan_count'] ?? 0,
                    $row['total_borrowed'] ?? 0,
                    $row['total_payable'] ?? 0,
                    $row['outstanding_balance'] ?? 0,
                    $row['total_paid_period'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Borrower portfolio',
                'style' => 'bordered',
                'headers' => ['Borrower', 'Loans', 'Borrowed (PHP)', 'Payable (PHP)', 'Outstanding (PHP)', 'Collected in period (PHP)'],
                'rows' => $borrowerRows,
            ];
            break;

        case 'collector_performance':
            $payload['summary'] = [
                'Collectors' => (string)($ctx['total_collectors'] ?? 0),
                'Total collected (period)' => (string)($ctx['total_collected_fmt'] ?? ''),
                'Average per collector' => (string)($ctx['avg_per_collector_fmt'] ?? ''),
            ];
            $perfRows = [];
            foreach ($ctx['collector_performance'] ?? [] as $row) {
                $perfRows[] = [
                    $row['collector_name'] ?? '',
                    $row['total_collected'] ?? 0,
                    $row['payment_count'] ?? 0,
                    $row['active_loans'] ?? 0,
                    $row['overdue_loans'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Collector performance',
                'style' => 'bordered',
                'headers' => ['Collector', 'Collected (PHP)', 'Payments', 'Active loans', 'Overdue loans'],
                'rows' => $perfRows,
            ];
            break;

        case 'financial_summary':
            $payload['summary'] = [
                'Collections (revenue)' => (string)($ctx['total_collected_fmt'] ?? ''),
                'Expenses' => (string)($ctx['total_expenses_fmt'] ?? ''),
                'Net income' => (string)($ctx['net_income_fmt'] ?? ''),
                'Profit margin' => (string)($ctx['profit_margin_fmt'] ?? ''),
                'Disbursed (period)' => (string)($ctx['total_disbursed_fmt'] ?? ''),
            ];
            $trendRows = [];
            foreach ($months as $i => $label) {
                $trendRows[] = [
                    $label,
                    $collections[$i] ?? 0,
                    $expenses[$i] ?? 0,
                    ($collections[$i] ?? 0) - ($expenses[$i] ?? 0),
                ];
            }
            $payload['tables'][] = [
                'name' => 'Daily collections and expenses',
                'style' => 'bordered',
                'headers' => ['Date', 'Collections', 'Expenses', 'Net'],
                'rows' => $trendRows,
            ];
            $expenseRows = [];
            foreach ($ctx['expense_breakdown'] ?? [] as $row) {
                $expenseRows[] = [
                    $row['description'] ?? '',
                    $row['total'] ?? 0,
                ];
            }
            $payload['tables'][] = [
                'name' => 'Expense breakdown',
                'style' => 'bordered',
                'headers' => ['Description', 'Amount (PHP)'],
                'rows' => $expenseRows,
            ];
            break;
    }

    $payload['period_heading'] = reportExportPeriodHeading(
        (string)$payload['period_label'],
        (string)$payload['start_date'],
        (string)$payload['end_date']
    );
    $payload['period_range_display'] = reportExportPeriodRangeDisplay(
        (string)$payload['start_date'],
        (string)$payload['end_date']
    );
    $payload['overview_tiles'] = reportExportOverviewTiles($reportType, $ctx);
    $payload['performance_summary'] = reportExportPerformanceSummary($reportType, $ctx);

    return $payload;
}

function reportExportPeriodHeading(string $periodLabel, string $startDate, string $endDate): string
{
    if ($periodLabel !== '') {
        return mb_strtoupper($periodLabel, 'UTF-8');
    }
    if ($startDate !== '' && strtotime($startDate)) {
        return mb_strtoupper(date('F Y', strtotime($startDate)), 'UTF-8');
    }

    return mb_strtoupper(date('F Y'), 'UTF-8');
}

function reportExportPeriodRangeDisplay(string $startDate, string $endDate): string
{
    if ($startDate === '' || !strtotime($startDate)) {
        return 'Period not specified';
    }
    if ($endDate === '' || !strtotime($endDate) || $startDate === $endDate) {
        return date('F j, Y', strtotime($startDate));
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if (date('Y', $startTs) === date('Y', $endTs) && date('m', $startTs) === date('m', $endTs)) {
        return date('F j', $startTs) . ' - ' . date('F j, Y', $endTs);
    }

    return date('F j, Y', $startTs) . ' - ' . date('F j, Y', $endTs);
}

/** @return list<array{label: string, value: string}> */
function reportExportOverviewTiles(string $reportType, array $ctx): array
{
    return match ($reportType) {
        'loan_reports' => [
            ['label' => 'Total Loans', 'value' => (string)($ctx['total_loans'] ?? 0)],
            ['label' => 'Active', 'value' => (string)($ctx['active_loans'] ?? 0)],
            ['label' => 'Overdue', 'value' => (string)($ctx['overdue_loans'] ?? 0)],
            ['label' => 'Outstanding', 'value' => (string)($ctx['total_outstanding_fmt'] ?? '')],
            ['label' => 'Paid', 'value' => (string)($ctx['paid_loans'] ?? 0)],
        ],
        'payment_reports' => [
            ['label' => 'Payments', 'value' => (string)($ctx['payment_summary']['count'] ?? 0)],
            ['label' => 'Collected', 'value' => (string)($ctx['total_collected_fmt'] ?? '')],
            ['label' => 'Avg Payment', 'value' => (string)($ctx['payment_summary_avg_fmt'] ?? '')],
        ],
        'borrower_reports' => [
            ['label' => 'Borrowers', 'value' => (string)($ctx['total_borrowers'] ?? 0)],
            ['label' => 'Active Loans', 'value' => (string)($ctx['active_loans'] ?? 0)],
            ['label' => 'Outstanding', 'value' => (string)($ctx['total_outstanding_fmt'] ?? '')],
        ],
        'collector_performance' => [
            ['label' => 'Collectors', 'value' => (string)($ctx['total_collectors'] ?? 0)],
            ['label' => 'Collected', 'value' => (string)($ctx['total_collected_fmt'] ?? '')],
            ['label' => 'Avg / Collector', 'value' => (string)($ctx['avg_per_collector_fmt'] ?? '')],
        ],
        'financial_summary' => [
            ['label' => 'Collections', 'value' => (string)($ctx['total_collected_fmt'] ?? '')],
            ['label' => 'Expenses', 'value' => (string)($ctx['total_expenses_fmt'] ?? '')],
            ['label' => 'Net Income', 'value' => (string)($ctx['net_income_fmt'] ?? '')],
            ['label' => 'Disbursed', 'value' => (string)($ctx['total_disbursed_fmt'] ?? '')],
            ['label' => 'Margin', 'value' => (string)($ctx['profit_margin_fmt'] ?? '')],
        ],
        default => [
            ['label' => 'Collections', 'value' => (string)($ctx['total_collected_fmt'] ?? '')],
            ['label' => 'Disbursed', 'value' => (string)($ctx['total_disbursed_fmt'] ?? '')],
            ['label' => 'Net Income', 'value' => (string)($ctx['net_income_fmt'] ?? '')],
            ['label' => 'Balance', 'value' => (string)($ctx['total_outstanding_fmt'] ?? '')],
            ['label' => 'Borrowers', 'value' => (string)($ctx['total_borrowers'] ?? 0)],
            ['label' => 'Active Loans', 'value' => (string)($ctx['active_loans'] ?? 0)],
            ['label' => 'Overdue', 'value' => (string)($ctx['overdue_loans'] ?? 0)],
        ],
    };
}

/** @return list<string> */
function reportExportPerformanceSummary(string $reportType, array $ctx): array
{
    $collected = (string)($ctx['total_collected_fmt'] ?? 'PHP 0.00');
    $expenses = (string)($ctx['total_expenses_fmt'] ?? 'PHP 0.00');
    $net = (string)($ctx['net_income_fmt'] ?? 'PHP 0.00');
    $disbursed = (string)($ctx['total_disbursed_fmt'] ?? 'PHP 0.00');

    $lines = [
        'Total collections in period: ' . $collected,
        'Total operating expenses: ' . $expenses,
        'Net income (collections minus expenses): ' . $net,
        'Loan releases (disbursed) in period: ' . $disbursed,
    ];

    if ($reportType === 'payment_reports') {
        $lines[] = 'Number of payment transactions: ' . (string)($ctx['payment_summary']['count'] ?? 0);
    }

    return $lines;
}

function reportPdfEscape(string $text): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii === false) {
        $ascii = $text;
    }
    $ascii = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', (string)$ascii) ?? '';

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
}

function reportPdfFormatCell(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return 'PHP ' . number_format((float)$value, 2);
    }
    if (is_string($value) && is_numeric($value) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        return 'PHP ' . number_format((float)$value, 2);
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '-';
    }

    return str_replace(["\u{20B1}", '₱'], 'PHP ', $text);
}

function reportPdfTruncate(string $text, int $maxLen): string
{
    if ($maxLen < 4) {
        return $text;
    }
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return mb_substr($text, 0, $maxLen - 3) . '...';
}

function reportPdfMaxCellLength(int $columnCount): int
{
    return match (true) {
        $columnCount <= 2 => 46,
        $columnCount === 3 => 32,
        $columnCount === 4 => 24,
        $columnCount === 5 => 20,
        default => 16,
    };
}

/** @return list<float> */
function reportPdfColumnPositions(int $columnCount, float $left, float $right): array
{
    $columnCount = max(1, $columnCount);
    if ($columnCount === 1) {
        return [$left];
    }

    $width = max(0.0, $right - $left);
    $step = $width / ($columnCount - 1);
    $positions = [];
    for ($i = 0; $i < $columnCount; $i++) {
        $positions[] = $left + ($step * $i);
    }

    return $positions;
}

function reportPdfCompileDocument(array $pageStreams, float $pageWidth, float $pageHeight): string
{
    $pageCount = count($pageStreams);
    if ($pageCount === 0) {
        $pageStreams = ['BT /F1 10 Tf 48 500 Td (No report data) Tj ET'];
        $pageCount = 1;
    }

    $pagesId = 2;
    $firstPageId = 3;
    $firstContentId = 3 + $pageCount;
    $fontRegularId = 3 + (2 * $pageCount);
    $fontBoldId = 4 + (2 * $pageCount);

    $chunks = [];
    $chunks[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

    $pageKids = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageId = $firstPageId + $i;
        $contentId = $firstContentId + $i;
        $pageKids[] = $pageId . ' 0 R';

        $stream = $pageStreams[$i] . "\n";
        $chunks[$contentId] = $contentId . " 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream\nendobj\n";
        $chunks[$pageId] = $pageId . " 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> >> >>\nendobj\n";
    }

    $chunks[2] = "2 0 obj\n<< /Type /Pages /Kids [" . implode(' ', $pageKids) . "] /Count {$pageCount} >>\nendobj\n";
    $chunks[$fontRegularId] = $fontRegularId . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $chunks[$fontBoldId] = $fontBoldId . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

    ksort($chunks, SORT_NUMERIC);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $position = strlen($pdf);
    foreach ($chunks as $objectId => $body) {
        $offsets[$objectId] = $position;
        $pdf .= $body;
        $position = strlen($pdf);
    }

    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($chunks));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        if (isset($offsets[$id])) {
            $pdf .= str_pad((string)$offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        } else {
            $pdf .= "0000000000 65535 f \n";
        }
    }

    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

final class LendingReportPdfBuilder
{
    private const PAGE_WIDTH = 842.0;
    private const PAGE_HEIGHT = 595.0;
    private const FRAME_INSET = 32.0;
    private const CONTENT_LEFT = 52.0;
    private const CONTENT_RIGHT = 790.0;
    private const CONTENT_BOTTOM = 68.0;

    /** @var list<string> */
    private array $pageStreams = [];

    /** @var list<string> */
    private array $currentOps = [];

    private float $y = 0.0;

    private bool $headerRendered = false;

    private string $footerLeft = '';

    private string $footerRight = '';

    public function setFooter(string $left, string $right): void
    {
        $this->footerLeft = $left;
        $this->footerRight = $right;
    }

    private function setStrokeGray(float $gray): void
    {
        $this->currentOps[] = sprintf('%.3F G', max(0.0, min(1.0, $gray)));
    }

    private function setLineWidth(float $width): void
    {
        $this->currentOps[] = sprintf('%.2F w', $width);
    }

    private function strokeRect(float $x, float $bottom, float $width, float $height): void
    {
        $this->currentOps[] = sprintf('%.2F %.2F %.2F %.2F re S', $x, $bottom, $width, $height);
    }

    private function drawHorizontalLine(float $x1, float $y, float $x2): void
    {
        $this->currentOps[] = sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y, $x2, $y);
    }

    private function drawVerticalLine(float $x, float $yBottom, float $yTop): void
    {
        $this->currentOps[] = sprintf('%.2F %.2F m %.2F %.2F l S', $x, $yBottom, $x, $yTop);
    }

    private function drawText(string $text, float $x, float $y, int $size, bool $bold): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->currentOps[] = sprintf(
            'BT /%s %d Tf %.2F %.2F Td (%s) Tj ET',
            $font,
            $size,
            $x,
            $y,
            reportPdfEscape($text)
        );
    }

    private function drawPageFrame(): void
    {
        $size = self::PAGE_WIDTH - (2 * self::FRAME_INSET);
        $this->setStrokeGray(0.45);
        $this->setLineWidth(1.0);
        $this->strokeRect(self::FRAME_INSET, self::FRAME_INSET, $size, self::PAGE_HEIGHT - (2 * self::FRAME_INSET));
    }

    private function drawPageFooter(): void
    {
        if ($this->footerLeft === '' && $this->footerRight === '') {
            return;
        }
        $lineY = 56.0;
        $this->setStrokeGray(0.65);
        $this->setLineWidth(0.6);
        $this->drawHorizontalLine(self::CONTENT_LEFT, $lineY, self::CONTENT_RIGHT);
        if ($this->footerLeft !== '') {
            $this->drawText($this->footerLeft, self::CONTENT_LEFT, 42.0, 8, false);
        }
        if ($this->footerRight !== '') {
            $this->drawText($this->footerRight, 585.0, 42.0, 8, false);
        }
    }

    private function finalizeCurrentPage(): void
    {
        if ($this->currentOps === []) {
            return;
        }
        $this->drawPageFooter();
        $this->pageStreams[] = implode("\n", $this->currentOps);
        $this->currentOps = [];
    }

    private function startPage(): void
    {
        $this->finalizeCurrentPage();
        $this->drawPageFrame();
        $this->y = self::PAGE_HEIGHT - 56.0;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed >= self::CONTENT_BOTTOM) {
            return;
        }
        $this->startPage();
    }

    public function renderExecutiveHeader(string $companyName, string $periodHeading, string $reportTitle, string $rangeDisplay): void
    {
        if ($this->pageStreams === [] && $this->currentOps === []) {
            $this->startPage();
        }

        $top = self::PAGE_HEIGHT - 56.0;
        $this->drawText($companyName, self::CONTENT_LEFT, $top, 12, true);
        $this->drawText($periodHeading, 610.0, $top, 11, true);
        $this->drawText($reportTitle, self::CONTENT_LEFT, $top - 18, 11, true);
        $this->drawText($rangeDisplay, self::CONTENT_LEFT, $top - 34, 10, false);

        $this->setStrokeGray(0.55);
        $this->setLineWidth(0.8);
        $this->drawHorizontalLine(self::CONTENT_LEFT, $top - 44, self::CONTENT_RIGHT);

        $this->y = $top - 58.0;
        $this->headerRendered = true;
    }

    public function sectionHeading(string $title): void
    {
        $this->ensureSpace(24);
        $this->spacer(6);
        $this->drawText(mb_strtoupper($title, 'UTF-8'), self::CONTENT_LEFT, $this->y, 10, true);
        $this->y -= 16;
    }

    public function spacer(float $points = 10.0): void
    {
        $this->y -= $points;
    }

    /** @param list<array{label: string, value: string}> $tiles */
    public function metricTiles(array $tiles, int $columns = 4): void
    {
        if ($tiles === []) {
            return;
        }

        $columns = max(1, min(4, $columns));
        $gap = 10.0;
        $tileHeight = 46.0;
        $contentWidth = self::CONTENT_RIGHT - self::CONTENT_LEFT;
        $tileWidth = ($contentWidth - (($columns - 1) * $gap)) / $columns;

        for ($index = 0; $index < count($tiles); $index += $columns) {
            $this->ensureSpace($tileHeight + 12);
            $rowTop = $this->y;
            $rowBottom = $rowTop - $tileHeight;

            for ($column = 0; $column < $columns; $column++) {
                $tileIndex = $index + $column;
                if ($tileIndex >= count($tiles)) {
                    break;
                }
                $tile = $tiles[$tileIndex];
                $x = self::CONTENT_LEFT + ($column * ($tileWidth + $gap));

                $this->setStrokeGray(0.72);
                $this->setLineWidth(0.6);
                $this->strokeRect($x, $rowBottom, $tileWidth, $tileHeight);
                $this->drawText(reportPdfTruncate((string)($tile['label'] ?? ''), 20), $x + 8, $rowTop - 14, 8, true);
                $this->drawText(
                    reportPdfTruncate(reportPdfFormatCell($tile['value'] ?? ''), 18),
                    $x + 8,
                    $rowTop - 32,
                    11,
                    true
                );
            }

            $this->y = $rowBottom - 12;
        }
    }

    /** @param list<string> $lines */
    public function performancePanel(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $panelHeight = 16 + (count($lines) * 14);
        $this->ensureSpace($panelHeight + 8);
        $top = $this->y;
        $bottom = $top - $panelHeight;

        $this->setStrokeGray(0.72);
        $this->setLineWidth(0.6);
        $this->strokeRect(self::CONTENT_LEFT, $bottom, self::CONTENT_RIGHT - self::CONTENT_LEFT, $panelHeight);

        $textY = $top - 14;
        foreach ($lines as $line) {
            $this->drawText(reportPdfTruncate((string)$line, 110), self::CONTENT_LEFT + 10, $textY, 9, false);
            $textY -= 14;
        }

        $this->y = $bottom - 10;
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function borderedTable(array $headers, array $rows): void
    {
        $headers = array_values(array_map('strval', $headers));
        $columnCount = max(1, count($headers));
        $tableWidth = self::CONTENT_RIGHT - self::CONTENT_LEFT;
        $columnWidth = $tableWidth / $columnCount;
        $columnWidths = array_fill(0, $columnCount, $columnWidth);
        $headerHeight = 18.0;
        $rowHeight = 15.0;

        if ($rows === []) {
            $rows = [['No records for this section in the selected period.'] + array_fill(0, max(0, $columnCount - 1), '')];
        }

        $rowIndex = 0;
        while ($rowIndex < count($rows)) {
            $available = $this->y - self::CONTENT_BOTTOM - $headerHeight;
            $maxRows = max(1, (int)floor($available / $rowHeight));
            $chunk = array_slice($rows, $rowIndex, $maxRows);
            $chunkCount = count($chunk);
            $tableHeight = $headerHeight + ($chunkCount * $rowHeight);

            $this->ensureSpace($tableHeight + 6);
            $top = $this->y;
            $bottom = $top - $tableHeight;

            $this->setStrokeGray(0.55);
            $this->setLineWidth(0.6);
            $this->strokeRect(self::CONTENT_LEFT, $bottom, $tableWidth, $tableHeight);

            $headerBottom = $top - $headerHeight;
            $this->drawHorizontalLine(self::CONTENT_LEFT, $headerBottom, self::CONTENT_RIGHT);

            $x = self::CONTENT_LEFT;
            for ($i = 0; $i < $columnCount - 1; $i++) {
                $x += $columnWidths[$i];
                $this->drawVerticalLine($x, $bottom, $top);
            }

            $x = self::CONTENT_LEFT;
            for ($i = 0; $i < $columnCount; $i++) {
                $this->drawText(reportPdfTruncate($headers[$i], 22), $x + 6, $top - 13, 9, true);
                $x += $columnWidths[$i];
            }

            $currentTop = $headerBottom;
            foreach ($chunk as $row) {
                $this->drawHorizontalLine(self::CONTENT_LEFT, $currentTop, self::CONTENT_RIGHT);
                $cells = array_values(is_array($row) ? $row : []);
                $x = self::CONTENT_LEFT;
                for ($i = 0; $i < $columnCount; $i++) {
                    $cell = reportPdfFormatCell($cells[$i] ?? '');
                    $this->drawText(reportPdfTruncate($cell, 22), $x + 6, $currentTop - 11, 8, false);
                    $x += $columnWidths[$i];
                }
                $currentTop -= $rowHeight;
            }

            $this->y = $bottom - 12;
            $rowIndex += $chunkCount;

            if ($rowIndex < count($rows)) {
                $this->startPage();
            }
        }
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    public function simpleTable(array $headers, array $rows): void
    {
        $this->borderedTable($headers, $rows);
    }

    public function output(): string
    {
        $this->finalizeCurrentPage();
        if ($this->pageStreams === []) {
            $this->startPage();
            $this->drawText('No report data available.', self::CONTENT_LEFT, 500.0, 11, true);
            $this->finalizeCurrentPage();
        }

        return reportPdfCompileDocument($this->pageStreams, self::PAGE_WIDTH, self::PAGE_HEIGHT);
    }
}

function reportPdfBrandHeading(string $legalName): string
{
    $name = str_ireplace(' and ', ' & ', trim($legalName));

    return mb_strtoupper($name, 'UTF-8');
}

function reportPdfFormatGeneratedFooter(string $generatedAt): string
{
    $ts = strtotime($generatedAt);

    return 'Generated: ' . ($ts ? date('F j, Y H:i', $ts) : $generatedAt);
}

function buildLendingReportPdfFromPayload(array $payload): string
{
    require_once __DIR__ . '/invoice_helpers.php';
    $brand = invoiceBrand();
    $pdf = new LendingReportPdfBuilder();

    $generatedAt = (string)($payload['generated_at'] ?? date('Y-m-d H:i:s'));
    $pdf->setFooter(
        reportPdfFormatGeneratedFooter($generatedAt),
        reportPdfBrandHeading((string)$brand['legal_name'])
    );

    $reportTitle = match ((string)($payload['type'] ?? '')) {
        'financial_summary' => 'Financial Summary Report',
        'payment_reports' => 'Payment Collections Report',
        'loan_reports' => 'Loan Portfolio Report',
        'borrower_reports' => 'Borrower Portfolio Report',
        'collector_performance' => 'Collector Performance Report',
        default => 'Lending & Collections Report',
    };

    $pdf->renderExecutiveHeader(
        reportPdfBrandHeading((string)$brand['legal_name']),
        (string)($payload['period_heading'] ?? mb_strtoupper((string)($payload['period_label'] ?? ''), 'UTF-8')),
        $reportTitle,
        (string)($payload['period_range_display'] ?? reportExportPeriodRangeDisplay(
            (string)($payload['start_date'] ?? ''),
            (string)($payload['end_date'] ?? '')
        ))
    );

    $pdf->sectionHeading('Financial Overview');
    $pdf->metricTiles(is_array($payload['overview_tiles'] ?? null) ? $payload['overview_tiles'] : [], 4);

    $performance = is_array($payload['performance_summary'] ?? null) ? $payload['performance_summary'] : [];
    if ($performance !== []) {
        $pdf->sectionHeading('Collection Performance');
        $pdf->performancePanel($performance);
    }

    foreach ($payload['tables'] ?? [] as $table) {
        if (!is_array($table)) {
            continue;
        }
        $pdf->sectionHeading((string)($table['name'] ?? 'Report Detail'));
        $headers = is_array($table['headers'] ?? null) ? $table['headers'] : [];
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
        $pdf->borderedTable($headers, $rows);
    }

    return $pdf->output();
}

function streamLendingReportPdf(array $payload, string $reportType): void
{
    $pdf = buildLendingReportPdfFromPayload($payload);
    $safeType = preg_replace('/[^a-z0-9_-]+/i', '-', $reportType) ?: 'report';
    $filename = 'lending-report-' . strtolower($safeType) . '-' . date('Y-m-d') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $pdf;
}

function renderReportKpi(string $variant, string $iconClass, string $label, string $value, string $meta = ''): string
{
    $allowed = ['primary', 'success', 'warning', 'danger', 'info'];
    $variant = in_array($variant, $allowed, true) ? $variant : 'primary';
    $html = '<div class="kpi-card kpi-card--' . $variant . '">';
    $html .= '<div class="kpi-card__top"><div class="kpi-card__label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<div class="kpi-card__icon"><i class="fas ' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i></div></div>';
    $html .= '<div class="kpi-card__value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>';
    if ($meta !== '') {
        $html .= '<div class="kpi-card__meta">' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $html .= '</div>';
    return $html;
}
