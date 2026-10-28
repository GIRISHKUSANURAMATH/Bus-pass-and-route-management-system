/* Charts for admin dashboard + reports. Data comes from JSON blocks rendered by PHP. */
(function () {
  'use strict';
  if (typeof Chart === 'undefined') return;
  const palette = ['#1d4ed8', '#16a34a', '#f59e0b', '#dc2626', '#9333ea', '#0891b2', '#64748b', '#db2777'];
  const make = (id, type, set, opts) => {
    const el = document.getElementById(id);
    if (!el || !set) return;
    new Chart(el, {
      type,
      data: { labels: set.labels, datasets: [{ label: set.label || '', data: set.data, backgroundColor: type === 'line' ? 'rgba(29,78,216,.15)' : (set.colors || palette), borderColor: type === 'line' ? '#1d4ed8' : '#fff', fill: type === 'line', tension: .3 }] },
      options: Object.assign({ responsive: true, aspectRatio: type === 'doughnut' ? 1.6 : 1.8, plugins: { legend: { display: type === 'doughnut' } }, scales: type === 'doughnut' ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } } }, opts || {}),
    });
  };
  const d = App.data('dashCharts');
  if (d) { make('chartRoutes', 'bar', d.routes); make('chartPayments', 'doughnut', d.payments); }
  const r = App.data('reportCharts');
  if (r) {
    make('chartRevenue', 'line', r.revenue);
    make('chartBranch', 'bar', r.branch);
    make('chartPassStatus', 'doughnut', r.passStatus);
    make('chartAppsRoute', 'bar', r.appsRoute);
  }
})();
