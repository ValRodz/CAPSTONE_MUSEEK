<?php
require_once __DIR__ . '/config/session.php';
requireLogin();

require_once __DIR__ . '/models/Report.php';
require_once __DIR__ . '/models/Studio.php';

$report = new Report();
$studioModel = new Studio();

$currentMonth = date('Y-m');
$period = $_GET['period'] ?? $currentMonth;
$studioId = $_GET['studio_id'] ?? null;

$startDate = date('Y-m-01', strtotime($period));
$endDate = date('Y-m-t', strtotime($period));

$kpis = $report->getKPIs($startDate, $endDate, $studioId);
$avgRating = $report->getAverageRating($studioId);
$topServices = $report->getTopServices(5, $startDate, $endDate);
$studioPerformance = $report->getStudioPerformance($startDate, $endDate);

$allStudios = $studioModel->getAll(['status' => 'approved']);

$pageTitle = 'Reports';
include __DIR__ . '/views/components/header.php';
?>



<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>Reports</h1>
        <p>Monthly operational insights and performance metrics</p>
    </div>
    <a href="../php/export-bookings.php?period=<?= urlencode($period) ?>&studio_id=<?= $studioId ?>" class="btn btn-success" title="Export all bookings to CSV">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:5px;">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
        </svg>
        Export to CSV
    </a>
</div>

<!-- FILTERS -->
<div class="filters">
    <form method="GET" action="" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
        <div class="filter-group">
            <label class="filter-label">Period</label>
            <input type="month" name="period" class="filter-input" value="<?= htmlspecialchars($period) ?>">
        </div>

        <div class="filter-group">
            <label class="filter-label">Studio</label>
            <select name="studio_id" class="filter-select">
                <option value="">All Studios</option>
                <?php foreach ($allStudios as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $studioId == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="../admin/reports.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- KPI CARDS -->
<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Bookings</h3>
        <div class="stat-value"><?= number_format($kpis['total_bookings']) ?></div>
        <span class="text-sm text-muted">For <?= date('F Y', strtotime($period)) ?></span>
    </div>

    <div class="stat-card">
        <h3>Paid Bookings</h3>
        <div class="stat-value" style="color: var(--success);"><?= number_format($kpis['paid_bookings']) ?></div>
        <span class="text-sm text-muted"><?= number_format($kpis['unpaid_bookings']) ?> unpaid</span>
    </div>

    <div class="stat-card">
        <h3>Total Revenue</h3>
        <div class="stat-value">₱<?= number_format($kpis['total_revenue'], 2) ?></div>
        <span class="text-sm text-muted">Paid bookings only</span>
    </div>

    <div class="stat-card">
        <h3>Average Rating</h3>
        <div class="stat-value"><?= number_format($avgRating, 2) ?></div>
        <span class="text-sm text-muted">Out of 5.00</span>
    </div>
</div>

<!-- TOP SERVICES -->
<div class="card">
    <div class="card-header">
        <h2>Top 5 Services by Bookings</h2>
    </div>

    <?php if (empty($topServices)): ?>
        <div class="empty-state">
            <p>No service data available for this period</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Service Name</th>
                        <th>Type</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topServices as $service): ?>
                        <tr>
                            <td><?= htmlspecialchars($service['name']) ?></td>
                            <td><?= htmlspecialchars($service['type'] ?? 'N/A') ?></td>
                            <td><?= number_format($service['booking_count']) ?></td>
                            <td>₱<?= number_format($service['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- STUDIO PERFORMANCE -->
<div class="card">
    <div class="card-header">
        <h2>Studio Performance</h2>
    </div>

    <?php if (empty($studioPerformance)): ?>
        <div class="empty-state">
            <p>No studio performance data available</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Studio</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studioPerformance as $perf): ?>
                        <tr>
                            <td><?= htmlspecialchars($perf['name']) ?></td>
                            <td><?= number_format($perf['bookings']) ?></td>
                            <td>₱<?= number_format($perf['revenue'], 2) ?></td>
                            <td><?= number_format($perf['rating'], 2) ?> / 5.00</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- VISUALIZATIONS -->
<div class="card">
    <div class="card-header">
        <h2>📊 Analytics Dashboard</h2>
        <p class="text-muted">Visual insights and performance metrics</p>
    </div>
    <style>
        .viz-grid { 
            display: grid; 
            gap: 20px; 
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); 
            margin-top: 16px;
        }
        @media (max-width: 1024px) { 
            .viz-grid { grid-template-columns: 1fr; } 
        }
        .viz-card { 
            background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0.02) 100%);
            backdrop-filter: blur(10px);
            padding: 24px; 
            border-radius: 12px; 
            height: 380px; 
            display: flex; 
            flex-direction: column;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .viz-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #e50914, #b20710);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .viz-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(229, 9, 20, 0.2);
        }
        .viz-card:hover::before {
            opacity: 1;
        }
        .viz-card h3 { 
            margin: 0 0 16px; 
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .viz-card h3 .chart-icon {
            font-size: 20px;
        }
        .viz-card .viz-canvas { 
            flex: 1; 
            min-height: 0;
            position: relative;
        }
        .viz-full { 
            grid-column: 1 / -1;
            height: 450px;
        }
        .chart-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: -12px;
            margin-bottom: 12px;
        }
    </style>
    <div class="viz-grid">
        <div class="viz-card">
            <h3><span class="chart-icon">🎯</span> Booking Status Distribution</h3>
            <div class="chart-subtitle">Payment completion rate</div>
            <canvas id="bookingsPie" class="viz-canvas"></canvas>
        </div>
        <div class="viz-card">
            <h3><span class="chart-icon">📈</span> Popular Services</h3>
            <div class="chart-subtitle">Top 5 by booking volume</div>
            <canvas id="servicesBookingsBar" class="viz-canvas"></canvas>
        </div>
        <div class="viz-card">
            <h3><span class="chart-icon">💰</span> Revenue Leaders</h3>
            <div class="chart-subtitle">Highest earning services</div>
            <canvas id="servicesRevenueBar" class="viz-canvas"></canvas>
        </div>
        <div class="viz-card">
            <h3><span class="chart-icon">🏢</span> Studio Performance</h3>
            <div class="chart-subtitle">Revenue by location</div>
            <canvas id="studioRevenueBar" class="viz-canvas"></canvas>
        </div>
        <div class="viz-card viz-full">
            <h3><span class="chart-icon">⭐</span> Quality vs Revenue Analysis</h3>
            <div class="chart-subtitle">Correlation between ratings and revenue (bubble size = bookings)</div>
            <canvas id="studioBubble" class="viz-canvas"></canvas>
        </div>
    </div>
</div>
</div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Visualizations JS -->
<script>
    // Source data from PHP
    const kpis = <?= json_encode($kpis) ?>;
    const topServices = <?= json_encode($topServices) ?>;
    const studioPerformance = <?= json_encode($studioPerformance) ?>;

    // Helpers
    const toNumber = v => (typeof v === 'number') ? v : (parseFloat(v) || 0);
    const truncateLabel = (label, max = 28) => {
        if (!label) return '';
        const s = String(label);
        return s.length > max ? s.slice(0, max - 1) + '…' : s;
    };
    const setChartHeight = (canvasId, count, base = 36) => {
        const el = document.getElementById(canvasId);
        if (el && el.parentElement) {
            const h = Math.max(240, count * base);
            el.parentElement.style.height = h + 'px';
        }
    };
    const fmtCurrency = v => `₱${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // Modern gradient helper
    const createGradient = (ctx, color1, color2) => {
        const gradient = ctx.createLinearGradient(0, 0, 0, ctx.canvas.height);
        gradient.addColorStop(0, color1);
        gradient.addColorStop(1, color2);
        return gradient;
    };

    // Modern color palette with gradients
    const theme = getComputedStyle(document.documentElement);
    const colors = {
        success: ['#10b981', '#059669'],      // Modern green gradient
        danger: ['#e50914', '#b20710'],       // Netflix red gradient
        primary: ['#3b82f6', '#2563eb'],      // Modern blue gradient
        warning: ['#f59e0b', '#d97706'],      // Modern orange gradient
        purple: ['#a855f7', '#9333ea'],       // Purple gradient
        cyan: ['#06b6d4', '#0891b2'],         // Cyan gradient
        pink: ['#ec4899', '#db2777'],         // Pink gradient
        text: (theme.getPropertyValue('--text-primary') || '#e5e5e5').trim()
    };
    
    // Chart.js global defaults for modern look
    Chart.defaults.color = colors.text;
    Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 15;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.85)';
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.titleFont = { size: 14, weight: 'bold' };
    Chart.defaults.plugins.tooltip.bodyFont = { size: 13 };
    Chart.defaults.plugins.tooltip.displayColors = true;
    Chart.defaults.plugins.tooltip.boxPadding = 6;

    document.addEventListener('DOMContentLoaded', () => {
        // Bookings Pie - Modern Doughnut with Gradient
        try {
            const paid = toNumber(kpis?.paid_bookings ?? 0);
            const unpaid = toNumber(kpis?.unpaid_bookings ?? 0);
            const pieCtx = document.getElementById('bookingsPie');
            if (pieCtx) {
                const total = paid + unpaid;
                const paidPercent = total > 0 ? ((paid / total) * 100).toFixed(1) : 0;
                new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Paid', 'Unpaid'],
                        datasets: [{
                            data: [paid, unpaid],
                            backgroundColor: [
                                createGradient(pieCtx.getContext('2d'), colors.success[0], colors.success[1]),
                                createGradient(pieCtx.getContext('2d'), colors.danger[0], colors.danger[1])
                            ],
                            borderWidth: 3,
                            borderColor: 'rgba(0,0,0,0.1)',
                            hoverOffset: 15,
                            hoverBorderWidth: 4,
                            hoverBorderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            tooltip: { 
                                callbacks: { 
                                    label: ctx => {
                                        const value = ctx.parsed;
                                        const percent = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return `${ctx.label}: ${value} (${percent}%)`;
                                    }
                                } 
                            },
                            legend: { 
                                position: 'bottom',
                                labels: {
                                    font: { size: 13, weight: '500' },
                                    padding: 20
                                }
                            }
                        },
                        animation: {
                            animateRotate: true,
                            animateScale: true,
                            duration: 1000,
                            easing: 'easeInOutQuart'
                        }
                    },
                    plugins: [{
                        id: 'centerText',
                        beforeDraw: (chart) => {
                            const { width, height, ctx } = chart;
                            ctx.restore();
                            const fontSize = (height / 120).toFixed(2);
                            ctx.font = `bold ${fontSize}em sans-serif`;
                            ctx.fillStyle = colors.text;
                            ctx.textBaseline = 'middle';
                            const text = `${paidPercent}%`;
                            const textX = Math.round((width - ctx.measureText(text).width) / 2);
                            const textY = height / 2 - 10;
                            ctx.fillText(text, textX, textY);
                            ctx.font = `${fontSize * 0.4}em sans-serif`;
                            ctx.fillStyle = 'rgba(229, 9, 20, 0.7)';
                            const subText = 'Paid';
                            const subTextX = Math.round((width - ctx.measureText(subText).width) / 2);
                            ctx.fillText(subText, subTextX, textY + 20);
                            ctx.save();
                        }
                    }]
                });
            }
        } catch (e) { console.error('Pie chart error:', e); }

        // Top Services by Bookings - Modern Horizontal Bar with Gradient
        try {
            const sLabels = topServices.map(s => s.name || 'Service');
            const sLabelsShort = sLabels.map(l => truncateLabel(l));
            const sBookings = topServices.map(s => toNumber(s.booking_count));
            const sRevenue = topServices.map(s => toNumber(s.revenue));
            setChartHeight('servicesBookingsBar', sLabels.length);
            const bar1 = document.getElementById('servicesBookingsBar');
            if (bar1 && sLabels.length) {
                const ctx = bar1.getContext('2d');
                const gradients = sBookings.map((_, i) => {
                    const gradient = ctx.createLinearGradient(0, 0, bar1.width, 0);
                    const colorPairs = [colors.primary, colors.purple, colors.cyan, colors.warning, colors.pink];
                    const colorPair = colorPairs[i % colorPairs.length];
                    gradient.addColorStop(0, colorPair[0]);
                    gradient.addColorStop(1, colorPair[1]);
                    return gradient;
                });
                
                new Chart(bar1, {
                    type: 'bar',
                    data: {
                        labels: sLabelsShort,
                        datasets: [{
                            label: 'Bookings',
                            data: sBookings,
                            backgroundColor: gradients,
                            borderRadius: 8,
                            borderSkipped: false,
                            hoverBackgroundColor: gradients.map((_, i) => {
                                const colorPairs = [colors.primary, colors.purple, colors.cyan, colors.warning, colors.pink];
                                return colorPairs[i % colorPairs.length][0];
                            })
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { 
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { 
                                    font: { size: 12 },
                                    padding: 8
                                }
                            },
                            y: { 
                                grid: { display: false },
                                ticks: { 
                                    callback: (value) => truncateLabel(value),
                                    font: { size: 12, weight: '500' },
                                    padding: 10
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: (items) => {
                                        const idx = items[0].dataIndex;
                                        return sLabels[idx];
                                    },
                                    label: (ctx) => `Bookings: ${ctx.parsed.x}`
                                }
                            }
                        },
                        animation: {
                            duration: 1200,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }

            // Top Services by Revenue - Modern Gradient Bar
            setChartHeight('servicesRevenueBar', sLabels.length);
            const bar2 = document.getElementById('servicesRevenueBar');
            if (bar2 && sLabels.length) {
                const ctx2 = bar2.getContext('2d');
                const revenueGradients = sRevenue.map((_, i) => {
                    const gradient = ctx2.createLinearGradient(0, 0, bar2.width, 0);
                    const colorPairs = [colors.warning, colors.danger, colors.success, colors.purple, colors.primary];
                    const colorPair = colorPairs[i % colorPairs.length];
                    gradient.addColorStop(0, colorPair[0]);
                    gradient.addColorStop(1, colorPair[1]);
                    return gradient;
                });
                
                new Chart(bar2, {
                    type: 'bar',
                    data: {
                        labels: sLabelsShort,
                        datasets: [{
                            label: 'Revenue',
                            data: sRevenue,
                            backgroundColor: revenueGradients,
                            borderRadius: 8,
                            borderSkipped: false,
                            hoverBackgroundColor: revenueGradients.map((_, i) => {
                                const colorPairs = [colors.warning, colors.danger, colors.success, colors.purple, colors.primary];
                                return colorPairs[i % colorPairs.length][0];
                            })
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { 
                                beginAtZero: true, 
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { 
                                    callback: v => fmtCurrency(v),
                                    font: { size: 12 },
                                    padding: 8
                                }
                            },
                            y: { 
                                grid: { display: false },
                                ticks: { 
                                    callback: (value) => truncateLabel(value),
                                    font: { size: 12, weight: '500' },
                                    padding: 10
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: (items) => {
                                        const idx = items[0].dataIndex;
                                        return sLabels[idx];
                                    },
                                    label: (ctx) => `Revenue: ${fmtCurrency(ctx.parsed.x)}`
                                }
                            }
                        },
                        animation: {
                            duration: 1200,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }
        } catch (e) { console.error('Services revenue chart error:', e); }

        // Studio Revenue Bar - Modern Gradient with Sparkle
        try {
            const stLabels = studioPerformance.map(s => s.name || 'Studio');
            const stLabelsShort = stLabels.map(l => truncateLabel(l));
            const stRevenue = studioPerformance.map(s => toNumber(s.revenue));
            const stBookings = studioPerformance.map(s => toNumber(s.bookings));
            const stRating = studioPerformance.map(s => toNumber(s.rating));
            const stCtx = document.getElementById('studioRevenueBar');
            setChartHeight('studioRevenueBar', stLabels.length, 32);
            if (stCtx && stLabels.length) {
                const ctx3 = stCtx.getContext('2d');
                const studioGradients = stRevenue.map((_, i) => {
                    const gradient = ctx3.createLinearGradient(0, 0, stCtx.width, 0);
                    gradient.addColorStop(0, colors.success[0]);
                    gradient.addColorStop(0.5, colors.cyan[0]);
                    gradient.addColorStop(1, colors.success[1]);
                    return gradient;
                });
                
                new Chart(stCtx, {
                    type: 'bar',
                    data: {
                        labels: stLabelsShort,
                        datasets: [{
                            label: 'Revenue',
                            data: stRevenue,
                            backgroundColor: studioGradients,
                            borderRadius: 8,
                            borderSkipped: false,
                            hoverBackgroundColor: studioGradients.map(() => colors.success[0])
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        scales: {
                            x: { 
                                beginAtZero: true, 
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { 
                                    callback: v => fmtCurrency(v),
                                    font: { size: 12 },
                                    padding: 8
                                }
                            },
                            y: { 
                                grid: { display: false },
                                ticks: { 
                                    callback: (value) => truncateLabel(value),
                                    font: { size: 12, weight: '500' },
                                    padding: 10
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: (items) => {
                                        const idx = items[0].dataIndex;
                                        return stLabels[idx];
                                    },
                                    label: (ctx) => `Revenue: ${fmtCurrency(ctx.parsed.x)}`
                                }
                            }
                        },
                        animation: {
                            duration: 1200,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }

            // Studio Rating vs Revenue - Modern Bubble Chart with Gradient
            const bubCtx = document.getElementById('studioBubble');
            if (bubCtx && stLabels.length) {
                const maxBookings = Math.max(...stBookings, 1);
                const points = studioPerformance.map((s, i) => ({
                    x: stRating[i],
                    y: stRevenue[i],
                    r: Math.max(8, (stBookings[i] / maxBookings) * 30),
                    label: stLabels[i],
                    bookings: stBookings[i]
                }));
                
                // Create gradient colors for each bubble
                const bubbleColors = points.map((_, i) => {
                    const colorPairs = [colors.danger, colors.primary, colors.purple, colors.warning, colors.cyan];
                    const pair = colorPairs[i % colorPairs.length];
                    return `${pair[0]}cc`; // Add transparency
                });
                
                const bubbleBorders = points.map((_, i) => {
                    const colorPairs = [colors.danger, colors.primary, colors.purple, colors.warning, colors.cyan];
                    return colorPairs[i % colorPairs.length][0];
                });
                
                new Chart(bubCtx, {
                    type: 'bubble',
                    data: {
                        datasets: [{
                            label: 'Studios',
                            data: points,
                            backgroundColor: bubbleColors,
                            borderColor: bubbleBorders,
                            borderWidth: 3,
                            hoverBackgroundColor: bubbleBorders,
                            hoverBorderWidth: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { 
                                title: { 
                                    display: true, 
                                    text: 'Average Rating ⭐',
                                    font: { size: 14, weight: 'bold' },
                                    padding: { top: 10 }
                                }, 
                                min: 0, 
                                max: 5,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { 
                                    stepSize: 0.5,
                                    font: { size: 12 }
                                }
                            },
                            y: { 
                                title: { 
                                    display: true, 
                                    text: 'Total Revenue 💰',
                                    font: { size: 14, weight: 'bold' },
                                    padding: { bottom: 10 }
                                }, 
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: { 
                                    callback: v => fmtCurrency(v),
                                    font: { size: 12 }
                                }
                            }
                        },
                        plugins: {
                            legend: { 
                                display: true,
                                position: 'top',
                                labels: {
                                    font: { size: 13, weight: '500' },
                                    padding: 15
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    title: ctx => ctx[0].raw.label,
                                    label: ctx => [
                                        `Rating: ${ctx.raw.x.toFixed(2)} ⭐`,
                                        `Revenue: ${fmtCurrency(ctx.raw.y)}`,
                                        `Bookings: ${ctx.raw.bookings}`
                                    ]
                                }
                            }
                        },
                        animation: {
                            duration: 1500,
                            easing: 'easeOutBounce'
                        }
                    }
                });
            }
        } catch (e) { console.error('Bubble chart error:', e); }
    });
</script>

<!-- SIDEBAR JS -->
<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (sidebar.classList.contains('closed')) {
            sidebar.classList.remove('closed');
        } else {
            sidebar.classList.add('closed');
        }
    }

    // Hover open/close
    let hoverTimeout;
    document.getElementById('sidebar').addEventListener('mouseenter', () => {
        clearTimeout(hoverTimeout);
        document.getElementById('sidebar').classList.remove('closed');
    });
    document.getElementById('sidebar').addEventListener('mouseleave', () => {
        hoverTimeout = setTimeout(() => {
            document.getElementById('sidebar').classList.add('closed');
        }, 800);
    });

    // Open by default
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('sidebar').classList.remove('closed');
    });
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>