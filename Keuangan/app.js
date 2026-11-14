let trendChartInstance = null;
let categoryChartInstance = null;

function isDarkMode() {
    return document.documentElement.classList.contains('dark');
}

function applyTheme(mode) {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (mode === 'dark' || (mode === 'system' && prefersDark)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
    updateChartsTheme();
}

function changeTheme(mode) {
    localStorage.setItem('et_theme', mode);
    applyTheme(mode);
}

function openModal(id) {
    if (id === 'modalRecurring') {
        filterCategoryDropdown('recType', 'recCategory');
    }
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}

function closeAllModals() {
    document.querySelectorAll('[data-modal-backdrop]').forEach(modal => {
        modal.classList.add('hidden');
    });
}

function copyAppsScriptCode(btn) {
    const codeEl = document.getElementById('appsScriptCode');
    if (!codeEl) return;
    navigator.clipboard.writeText(codeEl.innerText).then(() => {
        const original = btn.innerText;
        btn.innerText = 'Tersalin!';
        setTimeout(() => { btn.innerText = original; }, 2000);
    });
}

function filterCategoryDropdown(typeSelectId = 'trxType', catSelectId = 'trxCategory') {
    const typeEl = document.getElementById(typeSelectId);
    const catSelect = document.getElementById(catSelectId);
    if (!typeEl || !catSelect) return;

    const type = typeEl.value;
    let firstVisible = null;

    Array.from(catSelect.options).forEach(opt => {
        if (opt.dataset.type === type) {
            opt.hidden = false;
            if (!firstVisible) firstVisible = opt.value;
        } else {
            opt.hidden = true;
        }
    });

    if (catSelect.selectedOptions[0]?.hidden && firstVisible) {
        catSelect.value = firstVisible;
    }
}

function openCreateTrxModal() {
    document.getElementById('modalTrxTitle').innerText = 'Catat Transaksi Baru';
    document.getElementById('trxAction').value = 'create_transaction';
    document.getElementById('trxId').value = '';
    document.getElementById('trxType').value = 'expense';
    document.getElementById('trxDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('trxAmount').value = '';
    document.getElementById('trxDesc').value = '';
    filterCategoryDropdown('trxType', 'trxCategory');
    openModal('modalTrx');
}

function openEditTrxModal(trx) {
    document.getElementById('modalTrxTitle').innerText = 'Edit Transaksi #' + trx.id;
    document.getElementById('trxAction').value = 'update_transaction';
    document.getElementById('trxId').value = trx.id;
    document.getElementById('trxType').value = trx.type;
    filterCategoryDropdown('trxType', 'trxCategory');
    document.getElementById('trxDate').value = trx.transaction_date;
    document.getElementById('trxWallet').value = trx.wallet_id;
    document.getElementById('trxCategory').value = trx.category_id;
    document.getElementById('trxAmount').value = trx.amount;
    document.getElementById('trxDesc').value = trx.description || '';
    openModal('modalTrx');
}

function formatRupiahShort(value) {
    if (value >= 1000000) return 'Rp ' + (value / 1000000).toFixed(1) + ' jt';
    if (value >= 1000) return 'Rp ' + (value / 1000).toFixed(0) + ' rb';
    return 'Rp ' + value;
}

function initCharts() {
    if (typeof Chart === 'undefined') return;
    const data = window.CHART_DATA || {
        trend: { labels: [], income: [], expense: [] },
        category: { labels: [], values: [] }
    };

    const dark = isDarkMode();
    const textColor = dark ? '#cbd5e1' : '#475569';
    const gridColor = dark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(148, 163, 184, 0.2)';

    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas && data.trend.labels.length > 0) {
        trendChartInstance = new Chart(trendCanvas, {
            type: 'bar',
            data: {
                labels: data.trend.labels,
                datasets: [
                    {
                        label: 'Pemasukan',
                        data: data.trend.income,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        maxBarThickness: 28
                    },
                    {
                        label: 'Pengeluaran',
                        data: data.trend.expense,
                        backgroundColor: '#f43f5e',
                        borderRadius: 6,
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: textColor, font: { size: 12, weight: '600' } }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.dataset.label}: Rp ${Number(ctx.raw).toLocaleString('id-ID')}`
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor, font: { size: 11 } },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            callback: (val) => formatRupiahShort(val)
                        },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }

    const catCanvas = document.getElementById('categoryChart');
    if (catCanvas && data.category.labels.length > 0) {
        const palette = [
            '#f43f5e', '#f59e0b', '#3b82f6', '#8b5cf6',
            '#06b6d4', '#ec4899', '#14b8a6', '#6366f1'
        ];

        categoryChartInstance = new Chart(catCanvas, {
            type: 'doughnut',
            data: {
                labels: data.category.labels,
                datasets: [{
                    data: data.category.values,
                    backgroundColor: palette.slice(0, data.category.labels.length),
                    borderWidth: 2,
                    borderColor: dark ? '#0f172a' : '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: textColor, boxWidth: 12, padding: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` Rp ${Number(ctx.raw).toLocaleString('id-ID')} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
}

function updateChartsTheme() {
    const dark = isDarkMode();
    const textColor = dark ? '#cbd5e1' : '#475569';
    const gridColor = dark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(148, 163, 184, 0.2)';

    if (trendChartInstance) {
        trendChartInstance.options.plugins.legend.labels.color = textColor;
        trendChartInstance.options.scales.x.ticks.color = textColor;
        trendChartInstance.options.scales.y.ticks.color = textColor;
        trendChartInstance.options.scales.y.grid.color = gridColor;
        trendChartInstance.update();
    }

    if (categoryChartInstance) {
        categoryChartInstance.options.plugins.legend.labels.color = textColor;
        categoryChartInstance.data.datasets[0].borderColor = dark ? '#0f172a' : '#ffffff';
        categoryChartInstance.update();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('et_theme') || localStorage.getItem('theme') || 'system';
    const select = document.getElementById('themeSelect');
    if (select) select.value = saved;

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if ((localStorage.getItem('et_theme') || 'system') === 'system') {
            applyTheme('system');
        }
    });

    document.querySelectorAll('[data-modal-backdrop]').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.classList.add('hidden');
            }
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });

    initCharts();
});
