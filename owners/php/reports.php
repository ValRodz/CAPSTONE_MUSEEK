<?php
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

include '../../shared/config/db pdo.php';

$ownerId = $_SESSION['user_id'];

// Get date range (default to current month)
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Get report type
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'revenue';

// Fetch revenue data for the selected period
$revenueData = $pdo->prepare("
    SELECT DATE_FORMAT(p.Pay_Date, '%Y-%m-%d') as date,
           SUM(p.Amount) as revenue
    FROM payment p
    WHERE p.OwnerID = ?
    AND p.Pay_Date BETWEEN ? AND ?
    AND p.Pay_Stats = 'Completed'
    GROUP BY DATE_FORMAT(p.Pay_Date, '%Y-%m-%d')
    ORDER BY date
");
$revenueData->execute([$ownerId, $startDate, $endDate]);
$revenueData = $revenueData->fetchAll(PDO::FETCH_ASSOC);

// Format revenue data for chart
$dates = [];
$revenues = [];
foreach ($revenueData as $data) {
    $dates[] = date('M d', strtotime($data['date']));
    $revenues[] = $data['revenue'];
}

// Calculate total revenue
$totalRevenue = array_sum($revenues);

// Fetch booking data by studio
$studioBookings = $pdo->prepare("
    SELECT s.StudioName, COUNT(b.BookingID) as booking_count, SUM(p.Amount) as revenue
    FROM studios s
    LEFT JOIN bookings b ON s.StudioID = b.StudioID
    LEFT JOIN payment p ON b.BookingID = p.BookingID AND p.Pay_Stats = 'Completed'
    WHERE s.OwnerID = ?
    AND (b.booking_date BETWEEN ? AND ? OR b.booking_date IS NULL)
    GROUP BY s.StudioID, s.StudioName
    ORDER BY revenue DESC
");
$studioBookings->execute([$ownerId, $startDate, $endDate]);
$studioBookings = $studioBookings->fetchAll(PDO::FETCH_ASSOC);

// Fetch service performance data (updated for new schema with booking_services)
$servicePerformance = $pdo->prepare("
    SELECT srv.ServiceType, COUNT(DISTINCT b.BookingID) as booking_count, SUM(p.Amount) as revenue
    FROM services srv
    JOIN studio_services ss ON srv.ServiceID = ss.ServiceID
    JOIN studios st ON ss.StudioID = st.StudioID
    LEFT JOIN booking_services bsrv ON srv.ServiceID = bsrv.ServiceID
    LEFT JOIN bookings b ON bsrv.BookingID = b.BookingID
    LEFT JOIN payment p ON b.BookingID = p.BookingID AND p.Pay_Stats = 'Completed'
    WHERE st.OwnerID = ?
    AND (b.booking_date BETWEEN ? AND ? OR b.booking_date IS NULL)
    GROUP BY srv.ServiceID, srv.ServiceType
    ORDER BY revenue DESC
");
$servicePerformance->execute([$ownerId, $startDate, $endDate]);
$servicePerformance = $servicePerformance->fetchAll(PDO::FETCH_ASSOC);

// Fetch monthly comparison data
$monthlyComparison = $pdo->prepare("
    SELECT 
        DATE_FORMAT(p.Pay_Date, '%Y-%m') as month,
        SUM(p.Amount) as revenue,
        COUNT(DISTINCT b.BookingID) as bookings
    FROM payment p
    JOIN bookings b ON p.BookingID = b.BookingID
    WHERE p.OwnerID = ?
    AND p.Pay_Stats = 'Completed'
    AND p.Pay_Date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(p.Pay_Date, '%Y-%m')
    ORDER BY month
");
$monthlyComparison->execute([$ownerId]);
$monthlyComparison = $monthlyComparison->fetchAll(PDO::FETCH_ASSOC);

// Calculate growth metrics
$currentMonthRevenue = 0;
$previousMonthRevenue = 0;
$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('-1 month'));

foreach ($monthlyComparison as $month) {
    if ($month['month'] === $currentMonth) {
        $currentMonthRevenue = $month['revenue'];
    } elseif ($month['month'] === $previousMonth) {
        $previousMonthRevenue = $month['revenue'];
    }
}

$revenueGrowth = $previousMonthRevenue > 0 ?
    (($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100 : 0;

// Fetch top clients
$topClients = $pdo->prepare("
    SELECT c.Name, c.Email, COUNT(b.BookingID) as booking_count, SUM(p.Amount) as total_spent
    FROM clients c
    JOIN bookings b ON c.ClientID = b.ClientID
    JOIN studios s ON b.StudioID = s.StudioID
    LEFT JOIN payment p ON b.BookingID = p.BookingID AND p.Pay_Stats = 'Completed'
    WHERE s.OwnerID = ?
    AND b.booking_date BETWEEN ? AND ?
    GROUP BY c.ClientID, c.Name, c.Email
    ORDER BY total_spent DESC
    LIMIT 10
");
$topClients->execute([$ownerId, $startDate, $endDate]);
$topClients = $topClients->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - MuSeek Studio</title>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">

    <style>
        :root {
            --netflix-red: #e50914;
            --netflix-black: #141414;
            --netflix-dark-gray: #2f2f2f;
            --netflix-gray: #666666;
            --netflix-light-gray: #b3b3b3;
            --netflix-white: #ffffff;
            --success-green: #46d369;
            --warning-orange: #ffa500;
            --info-blue: #0071eb;
        }

        body {
            font-family: 'Netflix Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--netflix-black);
            color: var(--netflix-white);
            margin: 0;
            padding: 0;
        }

        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            background: var(--netflix-black);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-netflix.collapsed+.main-content {
            margin-left: 70px;
        }

        .reports-container {
            padding: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 40px;
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--netflix-white), var(--netflix-light-gray));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 1.1rem;
            color: var(--netflix-light-gray);
            margin-bottom: 30px;
        }

        .filters-container {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid #333;
        }

        .filters {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-label {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            font-weight: 500;
        }

        .filter-input {
            padding: 8px 12px;
            border: 1px solid #333;
            border-radius: 6px;
            background: var(--netflix-black);
            color: var(--netflix-white);
            font-size: 14px;
            min-width: 150px;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--netflix-red);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            color: var(--netflix-white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #d40813, #e50914);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 9, 20, 0.3);
        }

        .btn-secondary {
            background: var(--netflix-dark-gray);
            color: var(--netflix-white);
            border: 1px solid #333;
        }

        .btn-secondary:hover {
            background: var(--netflix-gray);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #333;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--netflix-red), #ff6b6b);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
        }

        .stat-icon.revenue {
            background: linear-gradient(135deg, var(--success-green), #46d369);
        }

        .stat-icon.growth {
            background: linear-gradient(135deg, var(--info-blue), #0071eb);
        }

        .stat-icon.bookings {
            background: linear-gradient(135deg, var(--warning-orange), #ffa500);
        }

        .stat-icon.clients {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin: 0;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--netflix-white);
            margin: 5px 0 0 0;
        }

        .stat-change {
            font-size: 0.8rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-change.positive {
            color: var(--success-green);
        }

        .stat-change.negative {
            color: #ff6b6b;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }

        .chart-card {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #333;
        }

        .chart-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #333;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--netflix-white);
            margin: 0 0 5px 0;
        }

        .chart-subtitle {
            font-size: 0.9rem;
            color: var(--netflix-light-gray);
            margin: 0;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .data-table {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #333;
            margin-bottom: 30px;
        }

        .table-header {
            background: linear-gradient(135deg, var(--netflix-red), #ff6b6b);
            padding: 20px;
            color: var(--netflix-white);
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .table-title i {
            margin-right: 10px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: var(--netflix-black);
            color: var(--netflix-white);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid #333;
        }

        .table td {
            padding: 16px;
            border-bottom: 1px solid #333;
            color: var(--netflix-light-gray);
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: rgba(229, 9, 20, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .amount {
            font-weight: 600;
            color: var(--success-green);
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .reports-container {
                padding: 20px;
            }

            .page-title {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <div class="main-content">
        <div class="reports-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Reports & Analytics</h1>
                <p class="page-subtitle">Comprehensive insights into your studio performance</p>
            </div>

            <!-- Filters -->
            <div class="filters-container fade-in">
                <form method="GET" class="filters">
                    <div class="filter-group">
                        <label class="filter-label">Start Date</label>
                        <input type="date" name="start_date" class="filter-input" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">End Date</label>
                        <input type="date" name="end_date" class="filter-input" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Report Type</label>
                        <select name="report_type" class="filter-input">
                            <option value="revenue" <?php echo $reportType === 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                            <option value="bookings" <?php echo $reportType === 'bookings' ? 'selected' : ''; ?>>Bookings</option>
                            <option value="clients" <?php echo $reportType === 'clients' ? 'selected' : ''; ?>>Clients</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                            Generate Report
                        </button>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">&nbsp;</label>
                        <button type="button" class="btn btn-secondary" onclick="exportReport()">
                            <i class="fas fa-download"></i>
                            Export
                        </button>
                    </div>
                </form>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon revenue">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Revenue</p>
                            <p class="stat-value"><?php echo "₱" . number_format($totalRevenue, 2); ?></p>
                            <div class="stat-change <?php echo $revenueGrowth >= 0 ? 'positive' : 'negative'; ?>">
                                <i class="fas fa-arrow-<?php echo $revenueGrowth >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs(round($revenueGrowth, 1)); ?>% from last month
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon bookings">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <p class="stat-title">Total Bookings</p>
                            <p class="stat-value"><?php echo array_sum(array_column($studioBookings, 'booking_count')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon clients">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <p class="stat-title">Active Clients</p>
                            <p class="stat-value"><?php echo count($topClients); ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon growth">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <p class="stat-title">Growth Rate</p>
                            <p class="stat-value"><?php echo round($revenueGrowth, 1); ?>%</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid fade-in">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Revenue Trend</h3>
                        <p class="chart-subtitle">Daily revenue for the selected period</p>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Studio Performance</h3>
                        <p class="chart-subtitle">Revenue by studio</p>
                    </div>
                    <div class="chart-container">
                        <canvas id="studioChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Data Tables -->
            <div class="data-table fade-in">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-trophy"></i>
                        Top Clients
                    </h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Email</th>
                            <th>Bookings</th>
                            <th>Total Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topClients as $client): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['Name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($client['Email']); ?></td>
                                <td><?php echo $client['booking_count']; ?></td>
                                <td>
                                    <span class="amount"><?php echo "₱" . number_format($client['total_spent'], 2); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="data-table fade-in">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-concierge-bell"></i>
                        Service Performance
                    </h3>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Service Type</th>
                            <th>Bookings</th>
                            <th>Revenue</th>
                            <th>Avg. Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicePerformance as $service): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($service['ServiceType']); ?></strong>
                                </td>
                                <td><?php echo $service['booking_count']; ?></td>
                                <td>
                                    <span class="amount"><?php echo "₱" . number_format($service['revenue'], 2); ?></span>
                                </td>
                                <td>
                                    <span class="amount"><?php echo "₱" . number_format($service['revenue'] / max($service['booking_count'], 1), 2); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($revenues); ?>,
                    borderColor: '#e50914',
                    backgroundColor: 'rgba(229, 9, 20, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#b3b3b3'
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#b3b3b3'
                        },
                        grid: {
                            color: '#333'
                        }
                    },
                    y: {
                        ticks: {
                            color: '#b3b3b3',
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: '#333'
                        }
                    }
                }
            }
        });

        // Studio Chart
        const studioCtx = document.getElementById('studioChart').getContext('2d');
        new Chart(studioCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($studioBookings, 'StudioName')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($studioBookings, 'revenue')); ?>,
                    backgroundColor: [
                        '#e50914',
                        '#ff6b6b',
                        '#46d369',
                        '#0071eb',
                        '#ffa500'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: {
                            color: '#b3b3b3'
                        }
                    }
                }
            }
        });

        function exportReport() {
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            const reportType = document.querySelector('select[name="report_type"]').value;

            window.open(`export_report.php?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}`, '_blank');
        }

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease-out';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>

</html>