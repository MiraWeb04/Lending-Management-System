(function () {
    function ensureToastStack() {
        let stack = document.getElementById('appToastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'appToastStack';
            stack.className = 'toast-stack';
            stack.setAttribute('aria-live', 'polite');
            stack.setAttribute('aria-atomic', 'true');
            document.body.appendChild(stack);
        }
        return stack;
    }

    window.showAppToast = function (message, type) {
        if (!message) return;
        const stack = ensureToastStack();
        const toast = document.createElement('div');
        const variant = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
        toast.className = 'app-toast app-toast--' + variant;
        const body = document.createElement('div');
        body.className = 'flex-grow-1';
        body.textContent = message;
        toast.appendChild(body);
        stack.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
            toast.style.transition = 'opacity 180ms ease, transform 180ms ease';
            setTimeout(function () { toast.remove(); }, 200);
        }, 4200);
    };

    function injectCsrfIntoPostForms() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta || !meta.content) {
            return;
        }
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function (form) {
            if (form.querySelector('input[name="_csrf_token"]')) {
                return;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf_token';
            input.value = meta.content;
            form.appendChild(input);
        });
    }

    document.addEventListener('DOMContentLoaded', injectCsrfIntoPostForms);

    function initLendingPeriodFilter(root) {
        const scope = root || document;
        scope.querySelectorAll('.lending-period-mode').forEach(function (modeSelect) {
            const form = modeSelect.closest('form');
            if (!form) {
                return;
            }
            const fields = form.querySelectorAll('.reports-period-field');
            function sync() {
                const mode = modeSelect.value || 'monthly';
                fields.forEach(function (el) {
                    const match = el.getAttribute('data-period-field') === mode;
                    el.classList.toggle('is-active', match);
                    el.querySelectorAll('input, select').forEach(function (input) {
                        input.disabled = !match;
                    });
                });
            }
            modeSelect.addEventListener('change', sync);
            sync();
        });
    }

    window.initLendingPeriodFilter = initLendingPeriodFilter;

    document.addEventListener('DOMContentLoaded', function () {
        initLendingPeriodFilter(document);

        const params = new URLSearchParams(window.location.search);
        const message = params.get('message');
        const type = params.get('type') || 'success';
        if (message) {
            showAppToast(decodeURIComponent(message.replace(/\+/g, ' ')), type);
        }

        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (alert) {
            if (alert.closest('.toast-stack')) return;
            if (alert.classList.contains('d-none') || alert.hidden || alert.closest('.d-none')) return;
            const text = alert.textContent.trim();
            if (!text) return;
            let toastType = 'info';
            if (alert.classList.contains('alert-success')) toastType = 'success';
            if (alert.classList.contains('alert-danger')) toastType = 'danger';
            if (alert.classList.contains('alert-warning')) toastType = 'warning';
            showAppToast(text, toastType);
        });

        document.querySelectorAll('[data-global-search]').forEach(function (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') return;
                const q = input.value.trim();
                if (!q) return;
                const target = input.getAttribute('data-global-search');
                if (target) {
                    window.location.href = target + (target.indexOf('?') >= 0 ? '&' : '?') + 'search=' + encodeURIComponent(q);
                }
            });
        });
    });

    window.LendingCharts = {
        currencyTooltip: function (context) {
            const value = Number(context.raw || 0);
            return (context.dataset.label || 'Amount') + ': ₱' + value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        baseOptions: function () {
            return {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 750, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        labels: {
                            usePointStyle: true,
                            boxWidth: 10,
                            padding: 16,
                            font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.94)',
                        titleFont: { family: 'Plus Jakarta Sans', weight: '700', size: 13 },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        callbacks: {
                            label: function (context) {
                                return LendingCharts.currencyTooltip(context);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { font: { family: 'Plus Jakarta Sans', size: 11 }, color: '#64748b', maxRotation: 0, autoSkipPadding: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(15,57,116,0.06)', drawTicks: false },
                        ticks: {
                            font: { family: 'Plus Jakarta Sans', size: 11 },
                            color: '#64748b',
                            padding: 8,
                            callback: function (value) {
                                return '₱' + Number(value).toLocaleString('en-PH', { maximumFractionDigits: 0 });
                            }
                        }
                    }
                }
            };
        },
        doughnutOptions: function (centerText) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: { animateRotate: true, animateScale: true, duration: 700 },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 10,
                            padding: 14,
                            font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.94)',
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: function (context) {
                                const value = Number(context.raw || 0);
                                const total = context.dataset.data.reduce(function (a, b) { return a + Number(b || 0); }, 0);
                                const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                return context.label + ': ' + value + ' (' + pct + '%)';
                            }
                        }
                    },
                    centerText: centerText || ''
                }
            };
        },
        centerTextPlugin: {
            id: 'centerText',
            beforeDraw: function (chart) {
                const text = chart.config.options?.plugins?.centerText;
                if (!text) return;
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data || !meta.data[0]) return;
                const center = meta.data[0];
                const x = center.x;
                const y = center.y;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#1c2d48';
                ctx.font = '700 1.35rem "Plus Jakarta Sans", sans-serif';
                ctx.fillText(String(text), x, y - 6);
                ctx.fillStyle = '#64748b';
                ctx.font = '600 0.68rem "Plus Jakarta Sans", sans-serif';
                ctx.fillText('TOTAL', x, y + 14);
                ctx.restore();
            }
        },
        lineAreaDataset: function (label, data, borderColor, backgroundColor) {
            return {
                label: label,
                data: data,
                borderColor: borderColor,
                backgroundColor: backgroundColor,
                fill: true,
                tension: 0.38,
                borderWidth: 2.5,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2,
                pointBorderColor: borderColor
            };
        }
    };
})();
