// Dashboard charts + sidebar toggle. Data comes from window.dashboardStats
// (injected by includes/dashboard_view.php).
(function () {
    'use strict';

    let stats = window.dashboardStats;
    if (!stats) {
        const el = document.getElementById('dashboard-stats-data');
        if (el) {
            try { stats = JSON.parse(el.textContent); window.dashboardStats = stats; } catch (e) {}
        }
    }
    stats = stats || { memberGrowth: [], gender: {} };

    // Aurora theme colours and fonts
    // Site palette (Admin → Site Colours); falls back to the original colours
    const C = window.AuroraColor || ((n, fb, a) => a === undefined ? 'rgb(' + fb.join(',') + ')' : 'rgba(' + fb.join(',') + ',' + a + ')');
    const AUR = { g: C('--c-accent2', [92, 255, 176]), t: C('--c-accent', [45, 226, 230]), v: C('--c-primary', [139, 92, 255]),
        m: C('--c-accent3', [255, 79, 216]), ink: C('--c-text', [243, 240, 255]), mute: C('--c-muted', [170, 163, 210]) };
    if (window.Chart) {
        Chart.defaults.font.family = '"Hanken Grotesk", system-ui, sans-serif';
        Chart.defaults.color = AUR.mute;
    }

    function showEmpty(canvasId, emptyId) {
        document.getElementById(canvasId).style.display = 'none';
        document.getElementById(emptyId).style.display = 'block';
    }

    function initMemberGrowthChart() {
        const growth = stats.memberGrowth || [];
        if (growth.length === 0) {
            showEmpty('memberGrowthChart', 'memberGrowthEmpty');
            return;
        }

        const canvas = document.getElementById('memberGrowthChart');
        const c2d = canvas.getContext('2d');
        const stroke = c2d.createLinearGradient(0, 0, canvas.clientWidth || 600, 0);
        stroke.addColorStop(0, AUR.g); stroke.addColorStop(0.35, AUR.t); stroke.addColorStop(0.7, AUR.v); stroke.addColorStop(1, AUR.m);
        const fill = c2d.createLinearGradient(0, 0, 0, canvas.clientHeight || 400);
        fill.addColorStop(0, C('--c-accent', [45, 226, 230], 0.28)); fill.addColorStop(1, C('--c-primary', [139, 92, 255], 0));

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: growth.map(p => p.label),
                datasets: [{
                    label: 'Members',
                    data: growth.map(p => p.value),
                    borderColor: stroke,
                    backgroundColor: fill,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: AUR.ink,
                    pointBorderColor: AUR.t,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 10, bottom: 20, left: 10, right: 10 } },
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        borderColor: AUR.t,
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: { label: ctx => `Members: ${ctx.parsed.y}` }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: C('--c-primary-cdbeff', [205, 190, 255], 0.12) },
                        ticks: { color: AUR.mute, font: { size: 12 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: AUR.mute, font: { size: 11 }, maxRotation: 45, minRotation: 45 }
                    }
                }
            }
        });
    }

    function initGenderChart() {
        const g = stats.gender || {};
        const slices = [
            { label: 'Male', count: g.male || 0, color: AUR.t },
            { label: 'Female', count: g.female || 0, color: AUR.m },
            { label: 'Other', count: g.other || 0, color: AUR.v }
        ].filter(s => s.count > 0);

        if (slices.length === 0) {
            showEmpty('genderDistributionChart', 'genderEmpty');
            return;
        }

        new Chart(document.getElementById('genderDistributionChart'), {
            type: 'doughnut',
            data: {
                labels: slices.map(s => s.label),
                datasets: [{
                    data: slices.map(s => s.count),
                    backgroundColor: slices.map(s => s.color),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                layout: { padding: { top: 10, bottom: 20, left: 10, right: 10 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: AUR.ink, padding: 20, usePointStyle: true, font: { size: 13 } }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        borderColor: AUR.t,
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: { label: ctx => `${ctx.label}: ${ctx.parsed}` }
                    }
                }
            }
        });
    }

    // Phone menu: the button opens/closes the sidebar; a tapped link, Escape or a tap outside closes it.
    // While closed on a phone the sidebar is inert (not reachable with Tab); while open, Tab stays inside it.
    (function () {
        const btn = document.getElementById('sidebarToggle'), bar = document.getElementById('sidebar');
        if (!btn || !bar) return;
        const phone = () => getComputedStyle(btn).display !== 'none';
        const icon = btn.querySelector('i');
        function set(open, refocus) {
            bar.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            if (icon) icon.className = open ? 'fas fa-xmark' : 'fas fa-bars';
            bar.inert = phone() && !open;
            if (open) { const first = bar.querySelector('a'); if (first) first.focus(); }
            else if (refocus) btn.focus();
        }
        set(false);
        btn.addEventListener('click', () => set(!bar.classList.contains('open'), true));
        bar.querySelectorAll('a').forEach(a => a.addEventListener('click', () => { if (phone()) set(false); }));
        document.addEventListener('click', e => {
            if (bar.classList.contains('open') && !bar.contains(e.target) && !btn.contains(e.target)) set(false);
        });
        document.addEventListener('keydown', e => {
            if (!bar.classList.contains('open')) return;
            if (e.key === 'Escape') { set(false, true); return; }
            if (e.key === 'Tab') {
                const f = [btn, ...bar.querySelectorAll('a')];
                const i = f.indexOf(document.activeElement);
                if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && (i === f.length - 1 || i === -1)) { e.preventDefault(); f[0].focus(); }
            }
        });
        window.addEventListener('resize', () => { bar.inert = phone() && !bar.classList.contains('open'); });
    })();

    if (window.Chart) {
        initMemberGrowthChart();
        initGenderChart();
    }
})();
