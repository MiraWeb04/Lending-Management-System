/**
 * Lending Management System - Shared JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    initializeSidebar();
    initializeFormGroups();
    initializePasswordToggles();
    initializeTooltips();
    initializePopovers();
    initializeDeleteConfirmations();
    initializeDatePickers();
    initializeSearch();
    initializeCollectorSearch();
    initializeAccessibleButtons();
    formatCurrencyFields();
});

function initializeSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const dateTime = document.getElementById('dateTime');

    if (!sidebar || !toggle || !dateTime) {
        return;
    }

    function updateDateTime() {
        const now = new Date();
        const options = { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' };
        dateTime.textContent = now.toLocaleString('en-US', options);
    }

    updateDateTime();
    setInterval(updateDateTime, 60000);

    toggle.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 1199.98px)').matches) {
            sidebar.classList.toggle('open');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    document.addEventListener('click', function (event) {
        if (window.matchMedia('(max-width: 1199.98px)').matches) {
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        }
    });
}

function initializeFormGroups() {
    document.querySelectorAll('.form-group').forEach(function (group) {
        const input = group.querySelector('input, textarea, select');
        if (!input) {
            return;
        }

        function updateState() {
            group.classList.toggle('filled', input.value.trim() !== '');
        }

        updateState();
        input.addEventListener('input', updateState);
        input.addEventListener('change', updateState);
    });
}

function initializePasswordToggles() {
    document.querySelectorAll('.password-toggle').forEach(function (toggle) {
        const input = toggle.querySelector('input[type="password"], input[type="text"]');
        const toggleButton = toggle.querySelector('.toggle-password');

        if (input && toggleButton) {
            toggleButton.addEventListener('click', function () {
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggleButton.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                toggleButton.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
            return;
        }

        const inputGroup = toggle.closest('.input-group');
        const groupedInput = inputGroup ? inputGroup.querySelector('input[type="password"], input[type="text"]') : null;

        if (!groupedInput) {
            return;
        }

        toggle.addEventListener('click', function () {
            const isPassword = groupedInput.type === 'password';
            groupedInput.type = isPassword ? 'text' : 'password';
            toggle.innerHTML = isPassword ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    });
}

function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        if (window.bootstrap && window.bootstrap.Tooltip) {
            new window.bootstrap.Tooltip(tooltipTriggerEl);
        }
    });
}

function initializePopovers() {
    const receipt = document.querySelector('.receipt-shell') || document.querySelector('.receipt-paper');
    if (!receipt) {
        window.print();
        return;
    }

    // Build a new window containing only the receipt HTML and necessary styles
    const newWin = window.open('', '_blank', 'noopener');
    if (!newWin) {
        // fallback to in-place print
        window.print();
        return;
    }

    // Minimal inline styles only — avoid including the app's global styles
    // which may contain @media print rules that hide content.
    const inline = '<style>' +
        '@page{size:A4; margin:10mm}html,body{height:100%;margin:0;padding:0;font-family:Inter,Segoe UI,system-ui,sans-serif;color:#0f172a;-webkit-print-color-adjust:exact}' +
        'body{padding:12mm}' +
        '.receipt-shell{width:100%;box-sizing:border-box;background:#fff;padding:12px;border-radius:6px;}' +
        '.receipt-shell *{box-sizing:border-box}' +
        '.receipt-table td,.receipt-table th{padding:6px;font-size:12px}' +
        '.receipt-shell h3{font-size:18px;margin:0 0 6px}' +
        '</style>';

    const content = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' + inline + '</head><body>' + receipt.outerHTML + '</body></html>';

    newWin.document.open();
    newWin.document.write(content);
    newWin.document.close();

    // Wait a short time for the content to render, then call print.
    // Using a short timeout is reliable across browsers for dynamically written windows.
    newWin.focus();
    setTimeout(function () {
        try {
            newWin.print();
        } catch (e) {
            console.error('Print failed:', e);
        }
        // leave the window open so the user can inspect the preview if needed
    }, 300);
        input.addEventListener('keyup', function () {
            const searchTerm = this.value.toLowerCase();
            const tableId = this.getAttribute('data-table');
            const table = document.getElementById(tableId);

            if (table) {
                filterTable(table, searchTerm);
            }
        });
    });
}

function filterTable(table, searchTerm) {
    table.querySelectorAll('tbody tr').forEach(function (row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
}

function initializeCollectorSearch() {
    const searchInput = document.getElementById('collectorSearch');
    const collectorSelect = document.getElementById('collectorSelect');

    if (!searchInput || !collectorSelect) {
        return;
    }

    const allOptions = Array.from(collectorSelect.options);

    function refreshOptions() {
        const filterText = searchInput.value.trim().toLowerCase();
        const selectedValue = collectorSelect.value;

        collectorSelect.innerHTML = '';

        const unassignedOption = document.createElement('option');
        unassignedOption.value = '';
        unassignedOption.textContent = 'Unassigned';
        collectorSelect.appendChild(unassignedOption);

        allOptions.forEach(function (option) {
            if (!option.value) {
                return;
            }

            const optionText = option.textContent.toLowerCase();
            if (filterText === '' || optionText.includes(filterText)) {
                const newOption = document.createElement('option');
                newOption.value = option.value;
                newOption.textContent = option.textContent;
                if (selectedValue === option.value) {
                    newOption.selected = true;
                }
                collectorSelect.appendChild(newOption);
            }
        });

        if (selectedValue && collectorSelect.querySelector('option[value="' + selectedValue + '"]')) {
            collectorSelect.value = selectedValue;
        } else if (!selectedValue) {
            collectorSelect.value = '';
        }
    }

    searchInput.addEventListener('input', refreshOptions);
    refreshOptions();
}

function formatCurrencyFields() {
    document.querySelectorAll('.currency-format').forEach(function (field) {
        const value = parseFloat(field.textContent.replace(/[^0-9.-]+/g, ''));
        if (!Number.isNaN(value)) {
            field.textContent = formatCurrency(value);
        }
    });
}

function formatCurrency(amount) {
    return '₱ ' + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}

function calculateLoan(principal, interestRate, term) {
    const interest = principal * (interestRate / 100);
    const totalPayable = parseFloat(principal) + parseFloat(interest);
    const dailyPayment = totalPayable / term;

    return {
        principal: principal,
        interest: interest,
        totalPayable: totalPayable,
        dailyPayment: dailyPayment,
        term: term
    };
}

function updateLoanCalculation() {
    const principalInput = document.getElementById('loan_amount');
    const interestRateInput = document.getElementById('interest_rate');
    const termInput = document.getElementById('term_days');

    if (principalInput && interestRateInput && termInput) {
        const principal = parseFloat(principalInput.value) || 0;
        const interestRate = parseFloat(interestRateInput.value) || 0;
        const term = parseFloat(termInput.value) || 1;
        const loanDetails = calculateLoan(principal, interestRate, term);

        const interestAmountField = document.getElementById('interest_amount');
        const totalPayableField = document.getElementById('total_payable');
        const dailyPaymentField = document.getElementById('daily_payment');

        if (interestAmountField) {
            interestAmountField.value = loanDetails.interest.toFixed(2);
        }
        if (totalPayableField) {
            totalPayableField.value = loanDetails.totalPayable.toFixed(2);
        }
        if (dailyPaymentField) {
            dailyPaymentField.value = loanDetails.dailyPayment.toFixed(2);
        }
    }
}

function printContent(elementId) {
    const content = document.getElementById(elementId);
    if (!content) {
        return;
    }

    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        return;
    }

    printWindow.document.write('<html><head><title>Print</title>');

    const styles = document.getElementsByTagName('link');
    for (let i = 0; i < styles.length; i++) {
        if (styles[i].rel === 'stylesheet') {
            printWindow.document.write(styles[i].outerHTML);
        }
    }

    printWindow.document.write('</head><body>');
    printWindow.document.write('<div class="container">');
    printWindow.document.write('<h4 class="text-center mb-4">Lending Management System</h4>');
    printWindow.document.write(content.innerHTML);
    printWindow.document.write('</div>');
    printWindow.document.write('</body></html>');

    printWindow.document.close();
    printWindow.focus();

    setTimeout(function () {
        printWindow.print();
        printWindow.close();
    }, 250);
}

function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) {
        return;
    }

    const rows = table.querySelectorAll('tr');
    const csv = [];

    rows.forEach(function (row) {
        const columns = row.querySelectorAll('td, th');
        const values = [];

        columns.forEach(function (column) {
            let text = column.innerText;
            text = text.replace(/[\r\n]+/gm, ' ');
            text = text.replace(/"/g, '""');
            values.push('"' + text + '"');
        });

        csv.push(values.join(','));
    });

    downloadCSV(csv.join('\n'), filename);
}

function downloadCSV(csv, filename) {
    const csvFile = new Blob([csv], { type: 'text/csv' });
    const downloadLink = document.createElement('a');

    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';

    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

/**
 * Print the receipt area scaled to fit a single page where possible.
 * Uses a temporary CSS transform to scale the `.receipt-shell` element
 * so it fits within an A4-like page height for common printers.
 */
function printReceipt() {
    const receipt = document.querySelector('.receipt-shell') || document.querySelector('.receipt-paper');
    if (!receipt) {
        window.print();
        return;
    }

    // Approximate printable page size in pixels at 96dpi for A4
    const pageWidthPx = 794; // ~8.27in * 96
    const pageHeightPx = 1123; // ~11.69in * 96
    const margin = 40; // px total margin allowance

    const contentWidth = receipt.scrollWidth;
    const contentHeight = receipt.scrollHeight;

    // Compute scale factors for both dimensions
    const scaleX = (pageWidthPx - margin) / contentWidth;
    const scaleY = (pageHeightPx - margin) / contentHeight;
    const scale = Math.min(scaleX, scaleY, 1);

    // Apply transform to scale down to fit one page (both width & height)
    receipt.style.transformOrigin = 'top left';
    receipt.style.transition = 'transform 120ms ease';
    receipt.style.transform = 'scale(' + scale + ')';

    // If scaled, also reduce font-size slightly as fallback for very long receipts
    if (scale < 0.9) {
        receipt.style.fontSize = (parseFloat(window.getComputedStyle(receipt).fontSize) * scale) + 'px';
    }

    // Hide non-receipt content via a class on body while printing
    document.body.classList.add('printing-receipt');

    function cleanup() {
        receipt.style.transform = '';
        receipt.style.transition = '';
        document.body.classList.remove('printing-receipt');
        window.removeEventListener('afterprint', cleanup);
    }

    window.addEventListener('afterprint', cleanup);
    // Force a reflow so the transform takes effect, then call print synchronously
    // (Chrome requires a direct user gesture to open the print dialog)
    // Reading offsetHeight forces layout to apply the transform immediately.
    // Call print synchronously to avoid popup blocking.
    // eslint-disable-next-line no-unused-expressions
    receipt.offsetHeight;
    window.print();
}