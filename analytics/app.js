
function formatDurationJS(hours) {
    hours = parseFloat(hours) || 0;
    if (hours <= 0) return '—';
    if (hours < 1) {
        const m = Math.round(hours * 60);
        return m < 1 ? 'کمتر از ۱ دقیقه' : m + ' دقیقه';
    }
    if (hours < 24) {
        const h = Math.floor(hours);
        const m = Math.round((hours - h) * 60);
        return m === 0 ? h + ' ساعت' : h + ' ساعت و ' + m + ' دقیقه';
    }
    const d = Math.floor(hours / 24);
    const rh = hours - d * 24;
    const h = Math.floor(rh);
    const m = Math.round((rh - h) * 60);
    let r = d + ' روز';
    if (h > 0) r += ' و ' + h + ' ساعت';
    if (m > 0 && h === 0) r += ' و ' + m + ' دقیقه';
    return r;
}

function hexToRgba(hex, alpha) {
    if (!hex) return 'rgba(59,130,246,' + alpha + ')';
    let h = hex.replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    const r = parseInt(h.substring(0, 2), 16),
        g = parseInt(h.substring(2, 4), 16),
        b = parseInt(h.substring(4, 6), 16);
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
}

function hoursToSpeedScore(h) {
    if (h === null || h === undefined || h <= 0) return null;
    return Math.round(1000 / (h + 1));
}

function escapeHtmlAnalytics(s) {
    if (s === null || s === undefined) return '';
    const m = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(s).replace(/[&<>"']/g, c => m[c]);
}

const AnalyticsState = {
    speedUsers: new Set(),
    subjects: new Set(),
    speedChart: null,
    subjectChart: null,
    mainBarChart: null,
    kpiMiniChart: null,
    modalChart: null,
    scheduleChart: null,
    avgTrendChart: null,
    monthlyChart: null,
    activeModalUser: null,
    MAX_SPEED: 5,
    MAX_SUBJECTS: 5
};

let GLOBAL_DASHBOARD = null;

const TOOLTIP_STYLE = {
    backgroundColor: '#1e293b',
    borderColor: 'rgba(255,255,255,0.1)',
    borderWidth: 1,
    padding: 12,
    cornerRadius: 10,
    titleColor: '#f8fafc',
    bodyColor: '#cbd5e1',
    titleFont: { size: 12, weight: 'bold' },
    bodyFont: { size: 12 },
    boxPadding: 6,
    usePointStyle: true,
    displayColors: true,
};

const GRID_STYLE = {
    color: 'rgba(148,163,184,0.15)',
    drawBorder: false
};

/* ============================================================
   MULTI-SELECT
============================================================ */
function toggleMultiSelect(e, id) {
    if (e) e.stopPropagation();
    const el = document.getElementById(id);
    if (!el) return;
    const wasOpen = el.classList.contains('open');
    document.querySelectorAll('.multi-select').forEach(m => m.classList.remove('open'));
    if (!wasOpen) el.classList.add('open');
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.multi-select')) {
        document.querySelectorAll('.multi-select.open').forEach(m => m.classList.remove('open'));
    }
});

/* ============================================================
   SPEED USER LIST
============================================================ */
function renderSpeedUserList() {
    const list = document.getElementById('speedUserList');
    if (!list) return;
    if (!GLOBAL_DASHBOARD.speedTrend || !GLOBAL_DASHBOARD.speedTrend.users) {
        list.innerHTML = '';
        return;
    }
    const users = GLOBAL_DASHBOARD.speedTrend.users;
    let html = '';
    const selCount = AnalyticsState.speedUsers.size;
    users.forEach(u => {
        const isSel = AnalyticsState.speedUsers.has(u.name);
        const isDis = !isSel && selCount >= AnalyticsState.MAX_SPEED;
        const av = u.avatar_url
            ? '<img src="' + escapeHtmlAnalytics(u.avatar_url) + '" alt="">'
            : escapeHtmlAnalytics(u.initial || '?');
        const crown = u.is_creator ? '<i class="fas fa-crown crown"></i>' : '';
        html += `<div class="multi-select__item ${isSel ? 'selected' : ''} ${isDis ? 'disabled' : ''}" onclick="toggleSpeedUser('${escapeHtmlAnalytics(u.name).replace(/'/g, "\\'")}')">
            <span class="multi-select__check"><i class="fas fa-check"></i></span>
            <span class="multi-select__avatar">${av}</span>
            <span class="multi-select__name">${crown}${escapeHtmlAnalytics(u.name)}</span>
            <span class="multi-select__color-dot" style="background:${u.color};"></span>
        </div>`;
    });
    list.innerHTML = html;

    const cEl = document.getElementById('speedUserCount');
    const lEl = document.getElementById('speedUserLabel');
    if (cEl) cEl.textContent = selCount + '/' + AnalyticsState.MAX_SPEED;
    if (lEl) {
        if (selCount === 0) lEl.textContent = 'انتخاب کاربران';
        else if (selCount === 1) lEl.textContent = Array.from(AnalyticsState.speedUsers)[0];
        else lEl.textContent = selCount + ' کاربر انتخاب شده';
    }
}

function toggleSpeedUser(name) {
    const was = AnalyticsState.speedUsers.has(name);
    if (!was && AnalyticsState.speedUsers.size >= AnalyticsState.MAX_SPEED) return;
    if (was) AnalyticsState.speedUsers.delete(name);
    else AnalyticsState.speedUsers.add(name);
    renderSpeedUserList();
    renderSpeedChart();
    renderSpeedLegend();
}

/* ============================================================
   SUBJECT LIST
============================================================ */
function renderSubjectList() {
    const list = document.getElementById('subjectList');
    if (!list) return;
    const labels = GLOBAL_DASHBOARD.subjectLabels || [];
    const counts = GLOBAL_DASHBOARD.subjectCounts || [];
    if (!labels.length) {
        list.innerHTML = '';
        return;
    }
    const colors = ['#2563eb', '#10b981', '#7c3aed', '#f59e0b', '#ec4899', '#14b8a6', '#ef4444', '#6366f1', '#0891b2', '#d946ef'];
    const selCount = AnalyticsState.subjects.size;
    let html = '';
    labels.forEach((lbl, i) => {
        const isSel = AnalyticsState.subjects.has(lbl);
        const isDis = !isSel && selCount >= AnalyticsState.MAX_SUBJECTS;
        const color = colors[i % colors.length];
        html += `<div class="multi-select__item ${isSel ? 'selected' : ''} ${isDis ? 'disabled' : ''}" onclick="toggleSubject('${escapeHtmlAnalytics(lbl).replace(/'/g, "\\'")}')">
            <span class="multi-select__check"><i class="fas fa-check"></i></span>
            <span class="multi-select__color-dot" style="background:${color};"></span>
            <span class="multi-select__name">${escapeHtmlAnalytics(lbl)}</span>
            <span class="multi-select__count-badge">${counts[i] || 0}</span>
        </div>`;
    });
    list.innerHTML = html;

    const cEl = document.getElementById('subjectCount');
    const lEl = document.getElementById('subjectLabel');
    if (cEl) cEl.textContent = selCount + '/' + AnalyticsState.MAX_SUBJECTS;
    if (lEl) {
        if (selCount === 0) lEl.textContent = 'انتخاب موضوعات';
        else if (selCount === 1) lEl.textContent = Array.from(AnalyticsState.subjects)[0];
        else lEl.textContent = selCount + ' موضوع انتخاب شده';
    }
}

function toggleSubject(title) {
    const was = AnalyticsState.subjects.has(title);
    if (!was && AnalyticsState.subjects.size >= AnalyticsState.MAX_SUBJECTS) return;
    if (was) AnalyticsState.subjects.delete(title);
    else AnalyticsState.subjects.add(title);
    renderSubjectList();
    renderSubjectChart();
}

/* ============================================================
   SPEED LEGEND (chips)
============================================================ */
function renderSpeedLegend() {
    const legend = document.getElementById('speedLegend');
    if (!legend) return;
    const users = GLOBAL_DASHBOARD.speedTrend ? GLOBAL_DASHBOARD.speedTrend.users : [];
    const visible = users.filter(u => AnalyticsState.speedUsers.has(u.name));
    if (!visible.length) {
        legend.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:.78rem;padding:10px;">کاربری انتخاب نشده است</div>';
        return;
    }
    let html = '';
    visible.forEach(u => {
        const av = u.avatar_url
            ? '<img src="' + escapeHtmlAnalytics(u.avatar_url) + '" alt="">'
            : escapeHtmlAnalytics(u.initial || '?');
        const crown = u.is_creator
            ? '<i class="fas fa-crown" style="color:#fbbf24;font-size:.6rem;margin-left:3px;"></i>'
            : '';
        html += `<button type="button" class="speed-chip" onclick="openUserModal('${escapeHtmlAnalytics(u.name).replace(/'/g, "\\'")}')">
            <span class="speed-chip__avatar" style="background:${u.color};">${av}</span>
            <span class="speed-chip__info">
                <span class="speed-chip__name">${crown}${escapeHtmlAnalytics(u.name)}</span>
                <span class="speed-chip__hint">مشاهده پروفایل</span>
            </span>
            <i class="fas fa-chart-line speed-chip__arrow"></i>
        </button>`;
    });
    legend.innerHTML = html;
}

/* ============================================================
   SPEED CHART (filter empty days)
============================================================ */
function renderSpeedChart() {
    const canvas = document.getElementById('speedTrendChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (!D.speedTrend || !D.speedTrend.users || !D.speedTrend.users.length) return;

    const visible = D.speedTrend.users.filter(u => AnalyticsState.speedUsers.has(u.name));

    if (AnalyticsState.speedChart) {
        AnalyticsState.speedChart.destroy();
        AnalyticsState.speedChart = null;
    }
    if (!visible.length) return;

    const totalDays = (D.trendDays || []).length;

    // فقط روزهایی که حداقل یک کاربر داده دارد
    const activeDayIndexes = [];
    for (let i = 0; i < totalDays; i++) {
        const hasData = visible.some(u => {
            const v = (u.data || [])[i];
            return v !== null && v !== undefined;
        });
        if (hasData) activeDayIndexes.push(i);
    }

    // اگر هیچ داده‌ای نبود، همه روزها
    const dayIndexes = activeDayIndexes.length > 0 ? activeDayIndexes : [...Array(totalDays).keys()];
    const activeLabels = dayIndexes.map(i => D.trendDays[i]);

    // محاسبه maxScore روی داده‌های فعال
    let maxScore = 0;
    visible.forEach(u => {
        dayIndexes.forEach(i => {
            const v = (u.data || [])[i];
            const s = hoursToSpeedScore(v);
            if (s !== null && s > maxScore) maxScore = s;
        });
    });
    if (maxScore < 10) maxScore = 10;

    const ctx = canvas.getContext('2d');
    const datasets = visible.map(u => {
        // ⭐ تبدیل null به 0 برای اینکه خط تا انتهای نمودار ادامه پیدا کند
        const scoreData = dayIndexes.map(i => {
            const v = (u.data || [])[i];
            const score = hoursToSpeedScore(v);
            return score === null ? 0 : score;
        });
        const orig = dayIndexes.map(i => (u.data || [])[i]);
        const grad = ctx.createLinearGradient(0, 0, 0, 300);
        grad.addColorStop(0, hexToRgba(u.color, 0.45));
        grad.addColorStop(0.35, hexToRgba(u.color, 0.20));
        grad.addColorStop(0.7, hexToRgba(u.color, 0.08));
        grad.addColorStop(1, hexToRgba(u.color, 0.02));
        return {
            label: u.name,
            data: scoreData,
            _origHours: orig,
            _color: u.color,
            borderColor: u.color,
            backgroundColor: grad,
            borderWidth: 2.5,
            tension: 0.55,
            spanGaps: true,
            fill: true,
            pointBackgroundColor: u.color,
            pointBorderColor: '#fff',
            pointBorderWidth: 2.5,
            pointRadius: 5,
            pointHoverRadius: 8,
            pointHoverBorderWidth: 3
        };
    });

    AnalyticsState.speedChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: activeLabels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: {
                padding: { left: 12, right: 12, top: 10, bottom: 6 }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: function (ctx) {
                            const i = ctx.dataIndex;
                            const oh = (ctx.dataset._origHours || [])[i];
                            if (oh === null || oh === undefined) return ctx.dataset.label + ': بدون داده';
                            return ctx.dataset.label + ': ' + formatDurationJS(oh);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: maxScore,
                    title: {
                        display: true,
                        text: 'امتیاز سرعت (بالاتر = سریع‌تر)',
                        color: '#94a3b8',
                        font: { size: 10, weight: 'bold' }
                    },
                    ticks: { color: '#64748b', font: { size: 10 } },
                    grid: {
                        color: 'rgba(148,163,184,0.14)',
                        drawBorder: false,
                        drawTicks: false
                    },
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10, weight: 'bold' },
                        padding: 6
                    }
                }
            }
        }
    });
}

/* ============================================================
   SUBJECT CHART
============================================================ */
function renderSubjectChart() {
    const canvas = document.getElementById('subjectChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    const allLabels = D.subjectLabels || [];
    const allCounts = D.subjectCounts || [];

    if (AnalyticsState.subjectChart) {
        AnalyticsState.subjectChart.destroy();
        AnalyticsState.subjectChart = null;
    }
    if (!allLabels.length) return;

    const colors = ['#2563eb', '#10b981', '#7c3aed', '#f59e0b', '#ec4899', '#14b8a6', '#ef4444', '#6366f1', '#0891b2', '#d946ef'];
    const labels = [], counts = [], bg = [];

    allLabels.forEach((l, i) => {
        if (AnalyticsState.subjects.has(l)) {
            labels.push(l);
            counts.push(allCounts[i] || 0);
            bg.push(colors[i % colors.length]);
        }
    });
    if (!labels.length) return;

    AnalyticsState.subjectChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: counts,
                backgroundColor: bg,
                borderColor: '#fff',
                borderWidth: 3,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 10,
                        color: '#475569',
                        font: { size: 10, weight: 'bold' },
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 7
                    }
                },
                tooltip: TOOLTIP_STYLE
            }
        }
    });
}

/* ============================================================
   MAIN BAR CHART (hero)
============================================================ */
function renderMainBarChart() {
    const canvas = document.getElementById('mainBarChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (AnalyticsState.mainBarChart) {
        AnalyticsState.mainBarChart.destroy();
        AnalyticsState.mainBarChart = null;
    }
    const ctx = canvas.getContext('2d');
    const g1 = ctx.createLinearGradient(0, 0, 0, 300);
    g1.addColorStop(0, 'rgba(59,130,246,0.95)');
    g1.addColorStop(1, 'rgba(59,130,246,0.55)');
    const g2 = ctx.createLinearGradient(0, 0, 0, 300);
    g2.addColorStop(0, 'rgba(16,185,129,0.95)');
    g2.addColorStop(1, 'rgba(16,185,129,0.55)');

    AnalyticsState.mainBarChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: D.trendDays,
            datasets: [
                {
                    label: 'ایجاد شده',
                    data: D.trendCreated,
                    backgroundColor: g1,
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.65
                },
                {
                    label: 'تکمیل شده',
                    data: D.trendCompleted,
                    backgroundColor: g2,
                    borderRadius: 8,
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.65
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { left: 8, right: 8, top: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: TOOLTIP_STYLE
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#64748b',
                        font: { size: 10 },
                        padding: 8
                    },
                    grid: { color: 'rgba(148,163,184,0.12)' },
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10, weight: 'bold' },
                        padding: 6
                    }
                }
            }
        }
    });
}

/* ============================================================
   KPI MINI CHART
============================================================ */
function renderKpiMiniChart() {
    const canvas = document.getElementById('kpiMiniChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (AnalyticsState.kpiMiniChart) {
        AnalyticsState.kpiMiniChart.destroy();
        AnalyticsState.kpiMiniChart = null;
    }
    const ctx = canvas.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, 0, 130);
    g.addColorStop(0, 'rgba(37,99,235,0.55)');
    g.addColorStop(1, 'rgba(37,99,235,0.02)');

    AnalyticsState.kpiMiniChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: D.trendDays,
            datasets: [{
                data: D.trendCompleted,
                borderColor: '#2563eb',
                backgroundColor: g,
                borderWidth: 2.5,
                tension: 0.55,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: ctx => 'تکمیل شده: ' + ctx.parsed.y
                    }
                }
            },
            scales: {
                y: { display: false, beginAtZero: true },
                x: { display: false }
            }
        }
    });
}

/* ============================================================
   SCHEDULE CHART (stacked bar per user)
============================================================ */
function renderScheduleChart() {
    const canvas = document.getElementById('userScheduleChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (!D.userSchedule || !Object.keys(D.userSchedule).length) return;
    if (AnalyticsState.scheduleChart) {
        AnalyticsState.scheduleChart.destroy();
        AnalyticsState.scheduleChart = null;
    }

    const speedUsers = (D.speedTrend.users || []);
    const items = [];
    Object.keys(D.userSchedule).forEach((uid, idx) => {
        const s = D.userSchedule[uid];
        let name = 'کاربر ' + uid;
        let initial = '?';
        let avatar = null;
        if (speedUsers[idx]) {
            name = speedUsers[idx].name;
            initial = speedUsers[idx].initial;
            avatar = speedUsers[idx].avatar_url;
        }
        items.push({
            uid: parseInt(uid, 10),
            ...s,
            name,
            initial,
            avatar
        });
    });

    const labels = items.map(it => it.name);
    const onTimeData = items.map(it => it.on_time);
    const lateData = items.map(it => it.late);
    const pendingData = items.map(it => it.pending_total);

    AnalyticsState.scheduleChart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'به موقع',
                    data: onTimeData,
                    backgroundColor: '#10b981',
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.72,
                    categoryPercentage: 0.7
                },
                {
                    label: 'با تاخیر',
                    data: lateData,
                    backgroundColor: '#f59e0b',
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.72,
                    categoryPercentage: 0.7
                },
                {
                    label: 'انجام نشده',
                    data: pendingData,
                    backgroundColor: '#94a3b8',
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.72,
                    categoryPercentage: 0.7
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { left: 8, right: 8, top: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: { ...TOOLTIP_STYLE }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#6b7280',
                        font: { size: 10, weight: 'bold' }
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#6b7280',
                        font: { size: 10 }
                    },
                    grid: {
                        color: 'rgba(148,163,184,0.14)',
                        drawBorder: false
                    },
                    border: { display: false }
                }
            }
        }
    });
}

/* ============================================================
   AVG TIME CHART
============================================================ */
function renderAvgTrendChart() {
    const canvas = document.getElementById('avgTrendChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (!D.trendAvgHours) return;
    if (AnalyticsState.avgTrendChart) {
        AnalyticsState.avgTrendChart.destroy();
        AnalyticsState.avgTrendChart = null;
    }
    const c = canvas.getContext('2d');
    const g = c.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, 'rgba(234,88,12,0.55)');
    g.addColorStop(0.35, 'rgba(234,88,12,0.28)');
    g.addColorStop(0.7, 'rgba(234,88,12,0.10)');
    g.addColorStop(1, 'rgba(234,88,12,0.02)');

    // ⭐ نگه‌داشتن داده اصلی برای Tooltip و تبدیل null به 0 برای رسم خط
    const origData = D.trendAvgHours;
    const chartData = origData.map(v => (v === null || v === undefined) ? 0 : v);

    AnalyticsState.avgTrendChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: D.trendDays,
            datasets: [{
                label: 'میانگین زمان (ساعت)',
                data: chartData,
                _origHours: origData,
                borderColor: '#ea580c',
                backgroundColor: g,
                borderWidth: 2.5,
                tension: 0.55,
                spanGaps: true,
                fill: true,
                pointBackgroundColor: '#ea580c',
                pointBorderColor: '#fff',
                pointBorderWidth: 2.5,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { left: 8, right: 8, top: 8 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        label: function (ctx) {
                            const orig = (ctx.dataset._origHours || [])[ctx.dataIndex];
                            if (orig === null || orig === undefined) return 'بدون داده';
                            return 'میانگین زمان: ' + formatDurationJS(orig);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'ساعت',
                        color: '#94a3b8',
                        font: { size: 10, weight: 'bold' }
                    },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10 },
                        callback: v => v + 'h'
                    },
                    grid: {
                        color: 'rgba(148,163,184,0.14)',
                        drawBorder: false
                    },
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10, weight: 'bold' }
                    }
                }
            }
        }
    });
}

/* ============================================================
   MONTHLY CHART
============================================================ */
function renderMonthlyChart() {
    const canvas = document.getElementById('monthlyChart');
    if (!canvas) return;
    const D = GLOBAL_DASHBOARD;
    if (AnalyticsState.monthlyChart) {
        AnalyticsState.monthlyChart.destroy();
        AnalyticsState.monthlyChart = null;
    }
    const c = canvas.getContext('2d');
    const g1 = c.createLinearGradient(0, 0, 0, 240);
    g1.addColorStop(0, 'rgba(124,58,237,0.55)');
    g1.addColorStop(0.4, 'rgba(124,58,237,0.22)');
    g1.addColorStop(1, 'rgba(124,58,237,0.02)');
    const g2 = c.createLinearGradient(0, 0, 0, 240);
    g2.addColorStop(0, 'rgba(16,185,129,0.50)');
    g2.addColorStop(0.4, 'rgba(16,185,129,0.20)');
    g2.addColorStop(1, 'rgba(16,185,129,0.02)');

    AnalyticsState.monthlyChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: D.monthLabels,
            datasets: [
                {
                    label: 'ایجاد شده',
                    data: D.monthCreated,
                    borderColor: '#7c3aed',
                    backgroundColor: g1,
                    borderWidth: 2.5,
                    tension: 0.55,
                    fill: true,
                    pointBackgroundColor: '#7c3aed',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7
                },
                {
                    label: 'تکمیل شده',
                    data: D.monthCompleted,
                    borderColor: '#10b981',
                    backgroundColor: g2,
                    borderWidth: 2.5,
                    tension: 0.55,
                    fill: true,
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { left: 8, right: 8, top: 8 } },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 14,
                        color: '#475569',
                        font: { size: 11, weight: 'bold' },
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8
                    }
                },
                tooltip: TOOLTIP_STYLE
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        color: '#64748b',
                        font: { size: 10 }
                    },
                    grid: GRID_STYLE,
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 10, weight: 'bold' }
                    }
                }
            }
        }
    });
}

/* ============================================================
   USER MODAL
============================================================ */
function openUserModal(userName) {
    const D = GLOBAL_DASHBOARD;
    const user = D.speedTrend.users.find(u => u.name === userName);
    if (!user) return;
    AnalyticsState.activeModalUser = userName;

    const nEl = document.getElementById('modalUserName');
    if (nEl) nEl.textContent = userName;

    const avEl = document.getElementById('modalAvatar');
    if (avEl) {
        if (user.avatar_url) avEl.innerHTML = '<img src="' + escapeHtmlAnalytics(user.avatar_url) + '" alt="">';
        else avEl.textContent = user.initial || '?';
        avEl.style.background = user.color;
    }

    document.getElementById('userSpeedModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    const canvas = document.getElementById('userModalChart');
    if (!canvas) return;
    if (AnalyticsState.modalChart) {
        AnalyticsState.modalChart.destroy();
        AnalyticsState.modalChart = null;
    }

    // فقط روزهای فعال
    const totalDays = (D.trendDays || []).length;
    const userData = user.data || [];
    const activeDayIndexes = [];
    for (let i = 0; i < totalDays; i++) {
        const v = userData[i];
        if (v !== null && v !== undefined) activeDayIndexes.push(i);
    }
    const dayIndexes = activeDayIndexes.length > 0 ? activeDayIndexes : [...Array(totalDays).keys()];
    const activeLabels = dayIndexes.map(i => D.trendDays[i]);

    // ⭐ تبدیل null به 0
    const scoreData = dayIndexes.map(i => {
        const v = userData[i];
        const score = hoursToSpeedScore(v);
        return score === null ? 0 : score;
    });
    const orig = dayIndexes.map(i => userData[i]);

    let maxScore = 0;
    scoreData.forEach(s => { if (s !== null && s > maxScore) maxScore = s; });
    if (maxScore < 10) maxScore = 10;

    const ctx = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 340);
    grad.addColorStop(0, hexToRgba(user.color, 0.50));
    grad.addColorStop(0.35, hexToRgba(user.color, 0.22));
    grad.addColorStop(0.7, hexToRgba(user.color, 0.08));
    grad.addColorStop(1, hexToRgba(user.color, 0.02));

    AnalyticsState.modalChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: activeLabels,
            datasets: [{
                label: userName,
                data: scoreData,
                _origHours: orig,
                borderColor: user.color,
                backgroundColor: grad,
                borderWidth: 3,
                tension: 0.55,
                spanGaps: true,
                fill: true,
                pointBackgroundColor: user.color,
                pointBorderColor: '#fff',
                pointBorderWidth: 3,
                pointRadius: 6,
                pointHoverRadius: 10,
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { left: 12, right: 12, top: 10, bottom: 6 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...TOOLTIP_STYLE,
                    callbacks: {
                        title: function (items) {
                            if (items.length) return 'روز ' + items[0].label;
                            return '';
                        },
                        label: function (ctx) {
                            const i = ctx.dataIndex;
                            const oh = (ctx.dataset._origHours || [])[i];
                            if (oh === null || oh === undefined) return 'بدون داده';
                            return [
                                'زمان میانگین: ' + formatDurationJS(oh),
                                'امتیاز سرعت: ' + hoursToSpeedScore(oh)
                            ];
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: maxScore,
                    title: {
                        display: true,
                        text: 'امتیاز سرعت',
                        color: '#94a3b8',
                        font: { size: 10, weight: 'bold' }
                    },
                    ticks: { color: '#64748b', font: { size: 10 } },
                    grid: {
                        color: 'rgba(148,163,184,0.14)',
                        drawBorder: false
                    },
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#64748b',
                        font: { size: 11, weight: 'bold' }
                    }
                }
            }
        }
    });
}

function closeUserModal() {
    document.getElementById('userSpeedModal').classList.remove('active');
    document.body.style.overflow = '';
    if (AnalyticsState.modalChart) {
        AnalyticsState.modalChart.destroy();
        AnalyticsState.modalChart = null;
    }
    AnalyticsState.activeModalUser = null;
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && AnalyticsState.activeModalUser) closeUserModal();
});

/* ============================================================
   INIT
============================================================ */
function initAnalytics() {
    if (typeof Chart === 'undefined') return;
    const D = window.ANALYTICS_DATA;
    if (!D) return;
    GLOBAL_DASHBOARD = D;

    Chart.defaults.font.family = 'Tahoma, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';

    // انتخاب پیش‌فرض کاربران
    if (D.speedTrend && D.speedTrend.users) {
        D.speedTrend.users.slice(0, AnalyticsState.MAX_SPEED)
            .forEach(u => AnalyticsState.speedUsers.add(u.name));
    }
    if (D.subjectLabels) {
        D.subjectLabels.slice(0, AnalyticsState.MAX_SUBJECTS)
            .forEach(s => AnalyticsState.subjects.add(s));
    }

    renderSpeedUserList();
    renderSubjectList();
    renderSpeedLegend();
    renderSpeedChart();
    renderSubjectChart();
    renderMainBarChart();
    renderKpiMiniChart();
    renderScheduleChart();
    renderAvgTrendChart();
    renderMonthlyChart();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAnalytics);
} else {
    initAnalytics();
}

/* ============================================================
   SIDEBAR
============================================================ */
function toggleSidebar() {
    const s = document.getElementById('appSidebar');
    const o = document.getElementById('sidebarOverlay');
    const b = document.getElementById('hamburgerBtn');
    if (!s || !o || !b) return;
    if (s.classList.contains('open')) closeSidebar();
    else {
        s.classList.add('open');
        o.classList.add('active');
        b.classList.add('active');
    }
}

function closeSidebar() {
    const s = document.getElementById('appSidebar');
    const o = document.getElementById('sidebarOverlay');
    const b = document.getElementById('hamburgerBtn');
    if (!s || !o || !b) return;
    s.classList.remove('open');
    o.classList.remove('active');
    b.classList.remove('active');
}

let rz;
window.addEventListener('resize', function () {
    clearTimeout(rz);
    rz = setTimeout(() => {
        if (window.innerWidth > 900) closeSidebar();
    }, 150);
});

/* ============================================================
   Global interactions
============================================================ */
document.addEventListener('contextmenu', function (e) {
    const t = (e.target.tagName || '').toLowerCase();
    if (t === 'input' || t === 'textarea') return true;
    e.preventDefault();
    return false;
});

document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
        const t = (e.target.tagName || '').toLowerCase();
        if (t !== 'input' && t !== 'textarea') {
            e.preventDefault();
            return false;
        }
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
        e.preventDefault();
        return false;
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
        e.preventDefault();
        return false;
    }
});

document.addEventListener('dragstart', function (e) {
    if (e.target.tagName === 'IMG' || e.target.tagName === 'A') e.preventDefault();
});