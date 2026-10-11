/**
 * SMM Panel - Admin Control Center Vanilla JavaScript (ES6+)
 */

// Initialize Admin Dashboard Charts if canvas element exists
document.addEventListener('DOMContentLoaded', () => {
    const chartCanvas = document.getElementById('adminOrdersChart');
    if (chartCanvas && typeof Chart !== 'undefined') {
        const isDark = document.documentElement.classList.contains('dark');
        const ctx = chartCanvas.getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Orders Volume',
                    data: [12, 19, 15, 25, 32, 28, 45],
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)' },
                        ticks: { color: isDark ? '#94a3b8' : '#64748b' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: isDark ? '#94a3b8' : '#64748b' }
                    }
                }
            }
        });

        window.addEventListener('themechanged', (e) => {
            const dark = e.detail && e.detail.isDark;
            chart.options.scales.y.grid.color = dark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)';
            chart.options.scales.y.ticks.color = dark ? '#94a3b8' : '#64748b';
            chart.options.scales.x.ticks.color = dark ? '#94a3b8' : '#64748b';
            chart.update();
        });
    }

    // Attach SweetAlert2 confirmations with efficient event delegation
    document.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-confirm]');
        if (!el) return;
        const prompt = el.getAttribute('data-confirm') || 'Are you sure you want to proceed?';
        if (typeof window.notify !== 'undefined') {
            e.preventDefault();
            const ok = await window.notify.confirm(prompt);
            if (ok) {
                if (el.tagName === 'A') {
                    window.location.href = el.href;
                } else if (el.form) {
                    el.form.submit();
                }
            }
        }
    });
});
