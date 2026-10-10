<?php
/**
 * Reusable period filter (daily / monthly / yearly / custom).
 * Expects: $periodMode, $periodLabel, $reportDate, $reportMonth, $reportYear,
 *          $startDate, $endDate, $yearOptions, $periodModeLabels,
 *          $periodFilterFormAction, $periodFilterHidden (array of name => value).
 */
$periodFilterFormAction = $periodFilterFormAction ?? '';
$periodFilterHidden = is_array($periodFilterHidden ?? null) ? $periodFilterHidden : [];
$periodFilterFormId = $periodFilterFormId ?? 'lending-period-filter-form';
?>
<div class="reports-filter-card mb-4">
    <div class="card-body p-4">
        <form action="<?php echo htmlspecialchars($periodFilterFormAction, ENT_QUOTES, 'UTF-8'); ?>" method="get" class="row g-3 align-items-end lending-period-filter" id="<?php echo htmlspecialchars($periodFilterFormId, ENT_QUOTES, 'UTF-8'); ?>">
            <?php foreach ($periodFilterHidden as $hiddenName => $hiddenValue): ?>
                <?php if ($hiddenValue === '' || $hiddenValue === null) { continue; } ?>
                <input type="hidden" name="<?php echo htmlspecialchars((string)$hiddenName, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string)$hiddenValue, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endforeach; ?>
            <div class="col-lg-3 col-md-4">
                <label class="form-label fw-semibold" for="<?php echo htmlspecialchars($periodFilterFormId, ENT_QUOTES, 'UTF-8'); ?>_mode">Period</label>
                <select class="form-select lending-period-mode" name="period_mode" id="<?php echo htmlspecialchars($periodFilterFormId, ENT_QUOTES, 'UTF-8'); ?>_mode">
                    <?php foreach ($periodModeLabels as $modeKey => $modeLabel): ?>
                        <option value="<?php echo htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $periodMode === $modeKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="daily">
                <label class="form-label">Day</label>
                <input type="date" class="form-control" name="report_date" value="<?php echo htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="monthly">
                <label class="form-label">Month</label>
                <input type="month" class="form-control" name="report_month" value="<?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m'); ?>">
            </div>
            <div class="col-lg-3 col-md-4 reports-period-field" data-period-field="yearly">
                <label class="form-label">Year</label>
                <select class="form-select" name="report_year">
                    <?php foreach ($yearOptions as $yearOption): ?>
                        <option value="<?php echo (int)$yearOption; ?>"<?php echo (int)$reportYear === (int)$yearOption ? ' selected' : ''; ?>><?php echo (int)$yearOption; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4 col-md-6 reports-period-field" data-period-field="custom">
                <label class="form-label">Custom range</label>
                <div class="row g-2">
                    <div class="col-6">
                        <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="From date">
                    </div>
                    <div class="col-6">
                        <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo date('Y-m-d'); ?>" aria-label="To date">
                    </div>
                </div>
            </div>
            <?php if (!empty($periodFilterExtraHtml)): ?>
                <?php echo $periodFilterExtraHtml; ?>
            <?php endif; ?>
            <div class="col-lg-2 col-md-3 ms-lg-auto">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply</button>
            </div>
            <div class="col-12 lending-period-showing">Showing: <strong><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </form>
    </div>
</div>
