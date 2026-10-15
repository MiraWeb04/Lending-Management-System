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
    initializeStackedTables();
    initializeCollectorSearch();
    initializeAccessibleButtons();
    formatCurrencyFields();
    initializeSidebarCounts();
});

function initializeSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const dateTime = document.getElementById('dateTime');
    const backdrop = document.getElementById('sidebarBackdrop');
    const layout = document.querySelector('.dashboard-layout');
    const borrowerLayout = layout && layout.classList.contains('dashboard-layout--borrower');
    const mobileNav = window.matchMedia(borrowerLayout ? '(max-width: 991.98px)' : '(max-width: 1199.98px)');

    if (dateTime) {
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' };
            dateTime.textContent = now.toLocaleString('en-US', options);
        }

        updateDateTime();
        setInterval(updateDateTime, 60000);
    }

    if (!sidebar || !toggle) {
        return;
    }

    function setDrawer(open, restoreFocus) {
        const mobile = mobileNav.matches;
        const show = open && mobile;
        sidebar.classList.toggle('open', show);
        if (layout) {
            layout.classList.toggle('drawer-open', show);
        }
        if (backdrop) {
            backdrop.classList.toggle('is-visible', show);
            backdrop.hidden = !show;
        }
        toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (!show && restoreFocus) {
            toggle.focus();
        }
    }

    const laptopNav = window.matchMedia('(min-width: 1200px) and (max-width: 1499.98px)');

    function applyLaptopSidebarDefault() {
        if (borrowerLayout || !laptopNav.matches || sidebar.dataset.userToggled === '1') {
            return;
        }
        sidebar.classList.add('collapsed');
    }

    if (borrowerLayout) {
        sidebar.classList.remove('collapsed');
        toggle.setAttribute('aria-expanded', mobileNav.matches ? 'false' : 'true');
    }
    applyLaptopSidebarDefault();
    laptopNav.addEventListener('change', applyLaptopSidebarDefault);
    mobileNav.addEventListener('change', function () {
        if (!mobileNav.matches) {
            setDrawer(false, false);
        }
    });

    toggle.addEventListener('click', function () {
        if (borrowerLayout && !mobileNav.matches) {
            sidebar.classList.remove('collapsed');
            toggle.setAttribute('aria-expanded', 'true');
            return;
        }

        sidebar.dataset.userToggled = '1';
        if (mobileNav.matches) {
            setDrawer(!sidebar.classList.contains('open'), false);
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setDrawer(false, true);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) {
            setDrawer(false, true);
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
    if (!window.bootstrap || !window.bootstrap.Popover) {
        return;
    }

    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (trigger) {
        new window.bootstrap.Popover(trigger);
    });
}

function initializeDeleteConfirmations() {
    document.querySelectorAll('form[data-confirm], button[data-confirm], a[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            const message = element.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });
}

function initializeDatePickers() {
    const today = new Date().toISOString().slice(0, 10);
    document.querySelectorAll('input[type="date"][data-max-today]').forEach(function (input) {
        if (!input.max) {
            input.max = today;
        }
    });
}

function initializeSearch() {
    document.querySelectorAll('input[data-table]').forEach(function (input) {
        input.addEventListener('keyup', function () {
            const table = document.getElementById(input.getAttribute('data-table'));
            if (table) {
                filterTable(table, input.value.toLowerCase());
            }
        });
    });
}

function initializeStackedTables() {
    document.querySelectorAll('.table-responsive > table.table').forEach(function (table) {
        if (table.classList.contains('receipt-table') || table.classList.contains('invoice-line-items') || table.classList.contains('detail-table')) {
            return;
        }

        const headers = Array.from(table.querySelectorAll('thead th')).map(function (header) {
            return header.textContent.replace(/\s+/g, ' ').trim();
        });
        if (!headers.length) {
            return;
        }

        table.classList.add('table-stack');
        table.querySelectorAll('tbody tr').forEach(function (row) {
            const cells = row.querySelectorAll(':scope > td');
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
                return;
            }
            cells.forEach(function (cell, index) {
                if (!cell.getAttribute('data-label') && headers[index]) {
                    cell.setAttribute('data-label', headers[index]);
                }
            });
        });
    });
}

function initializeAccessibleButtons() {
    document.querySelectorAll('button, a.btn').forEach(function (element) {
        if (element.getAttribute('aria-label')) {
            return;
        }
        const title = element.getAttribute('title');
        const text = element.textContent.replace(/\s+/g, ' ').trim();
        if (title && text === '') {
            element.setAttribute('aria-label', title);
        }
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
    const receipt = document.querySelector('.invoice-document') || document.querySelector('.receipt-shell') || document.querySelector('.receipt-paper');
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

function formatSidebarCount(count) {
    const value = Math.max(0, Number(count) || 0);
    return value > 99 ? '99+' : String(value);
}

function applySidebarCounts(counts) {
    document.querySelectorAll('[data-nav-count]').forEach(function (badge) {
        const key = badge.getAttribute('data-nav-count');
        if (!Object.prototype.hasOwnProperty.call(counts, key)) {
            return;
        }

        const count = Math.max(0, Number(counts[key]) || 0);
        const hint = badge.getAttribute('data-nav-hint') || 'open';
        badge.textContent = formatSidebarCount(count);
        badge.hidden = count <= 0;

        const link = badge.closest('a');
        if (!link) {
            return;
        }

        const base = link.getAttribute('data-nav-label') || '';
        if (!base) {
            return;
        }

        const label = count > 0 ? base + ', ' + count + ' ' + hint : base;
        link.setAttribute('title', label);
        if (count > 0 || link.hasAttribute('aria-label')) {
            link.setAttribute('aria-label', label);
        }
        if (link.classList.contains('notification-bell')) {
            link.classList.toggle('has-unread', count > 0);
        }
    });
}

function refreshSidebarCounts() {
    if (!document.getElementById('mainSidebar')) {
        return;
    }

    fetch('sidebar_counts_lending.php', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
    }).then(function (response) {
        if (!response.ok) {
            return null;
        }
        return response.json();
    }).then(function (payload) {
        if (!payload || !payload.ok || !payload.counts) {
            return;
        }
        applySidebarCounts(payload.counts);
    }).catch(function () {});
}

function initializeSidebarCounts() {
    if (!document.getElementById('mainSidebar')) {
        return;
    }

    let lastFetch = 0;
    function tick() {
        if (document.hidden) {
            return;
        }
        lastFetch = Date.now();
        refreshSidebarCounts();
    }

    window.setInterval(tick, 20000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && Date.now() - lastFetch > 8000) {
            tick();
        }
    });
}