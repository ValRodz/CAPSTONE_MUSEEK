<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

include '../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get filters from URL parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$methodFilter = isset($_GET['method']) ? $_GET['method'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

// Build the query with filters (updated for new schema with booking_services)
$query = "
    SELECT p.PaymentID, p.Amount, p.Pay_Date, p.Pay_Stats,
           b.BookingID, b.booking_date,
           c.Name as client_name,
           CASE 
               WHEN p.GCashID IS NOT NULL THEN 'GCash'
               WHEN p.CashID IS NOT NULL THEN 'Cash'
               ELSE 'Unknown'
           END as payment_method,
           CASE
               WHEN p.GCashID IS NOT NULL THEN g.GCash_Num
               ELSE NULL
           END as gcash_number,
           CASE
               WHEN p.GCashID IS NOT NULL THEN g.Ref_Num
               ELSE NULL
           END as reference_number,
           GROUP_CONCAT(DISTINCT srv.ServiceType ORDER BY srv.ServiceType SEPARATOR ', ') AS ServiceType
    FROM payment p
    JOIN bookings b ON p.BookingID = b.BookingID
    JOIN clients c ON b.ClientID = c.ClientID
    LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
    LEFT JOIN services srv ON bsrv.ServiceID = srv.ServiceID
    LEFT JOIN g_cash g ON p.GCashID = g.GCashID
    LEFT JOIN cash ca ON p.CashID = ca.CashID
    WHERE p.OwnerID = ?
    GROUP BY p.PaymentID
";

$params = [$ownerId];

// Add filters to the query
if ($statusFilter) {
    $query .= " AND p.Pay_Stats = ?";
    $params[] = ucfirst($statusFilter);
}

if ($methodFilter) {
    if (strtolower($methodFilter) === 'gcash') {
        $query .= " AND p.GCashID IS NOT NULL";
    } elseif (strtolower($methodFilter) === 'cash') {
        $query .= " AND p.CashID IS NOT NULL";
    }
}

if ($dateFilter) {
    $query .= " AND DATE(p.Pay_Date) = ?";
    $params[] = $dateFilter;
}

$query .= " ORDER BY p.Pay_Date DESC";

// Execute the query
$payments = $pdo->prepare($query);
$payments->execute($params);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// Calculate total revenue
$totalRevenue = 0;
$completedRevenue = 0;
$pendingRevenue = 0;

foreach ($payments as $payment) {
    $totalRevenue += $payment['Amount'];
    if (strtolower($payment['Pay_Stats']) === 'completed') {
        $completedRevenue += $payment['Amount'];
    } elseif (strtolower($payment['Pay_Stats']) === 'pending') {
        $pendingRevenue += $payment['Amount'];
    }
}

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Get owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get payment status badge
function getPaymentStatusBadge($status)
{
    switch (strtolower($status)) {
        case 'completed':
            return '<span class="badge bg-green-600">Completed</span>';
        case 'pending':
            return '<span class="badge bg-yellow-600">Pending</span>';
        case 'failed':
            return '<span class="badge bg-red-600">Failed</span>';
        default:
            return '<span class="badge bg-gray-600">' . $status . '</span>';
    }
}

// Helper function to get payment method badge
function getPaymentMethodBadge($method)
{
    switch (strtolower($method)) {
        case 'gcash':
            return '<span class="badge bg-blue-600">GCash</span>';
        case 'cash':
            return '<span class="badge bg-green-600">Cash</span>';
        default:
            return '<span class="badge bg-gray-600">' . $method . '</span>';
    }
}

// Helper function to get customer initials
function getInitials($name)
{
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>MuSeek - Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        body {
            font-family: "Inter", sans-serif;
        }

        .sidebar {
            display: none;
            height: 100vh;
            width: 250px;
            position: fixed;
            z-index: 40;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
        }

        .sidebar.active {
            display: block;
        }

        .main-content {
            box-sizing: border-box;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        .avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .payment-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .payment-table th {
            text-align: left;
            padding: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #9ca3af;
            background-color: #0a0a0a;
            border-bottom: 1px solid #222222;
        }

        .payment-table td {
            padding: 0.75rem;
            font-size: 0.875rem;
            border-bottom: 1px solid #222222;
        }

        .payment-table tr {
            cursor: pointer;
        }

        .payment-table tr:hover {
            background-color: #111111;
        }

        .stat-card {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            padding: 1.5rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: #161616;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            bottom: 100%;
            margin-bottom: 5px;
            background-color: #0a0a0a;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 1;
            border-radius: 0.375rem;
            border: 1px solid #222222;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 0.875rem;
        }

        .dropdown-content a:hover {
            background-color: #222222;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }
        /* Deep-link highlight for a specific payment row */
        .row-highlight {
            background-color: rgba(248, 113, 113, 0.08); /* red-400 at low opacity */
            outline: 2px solid #f87171; /* red-400 */
            outline-offset: -2px;
            animation: pulseRowHighlight 2500ms ease-out;
        }
        @keyframes pulseRowHighlight {
            0% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.35); }
            50% { box-shadow: 0 0 0 8px rgba(248, 113, 113, 0.15); }
            100% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.0); }
        }
    </style>
</head>

<body class="bg-[#161616] text-white">
    <?php include __DIR__ . '/sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-1">PAYMENTS</h1>
        </header>

        <div class="p-6">
            <!-- Revenue Overview -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-medium text-gray-400">Total Revenue</div>
                        <div class="p-2 rounded-full bg-blue-900/20">
                            <i class="fas fa-peso-sign text-blue-500"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-bold">₱<?php echo number_format($totalRevenue, 2); ?></div>
                    <div class="text-xs text-gray-500 mt-1">All time</div>
                </div>

                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-medium text-gray-400">Completed Payments</div>
                        <div class="p-2 rounded-full bg-green-900/20">
                            <i class="fas fa-check text-green-500"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-bold">₱<?php echo number_format($completedRevenue, 2); ?></div>
                    <div class="text-xs text-gray-500 mt-1">All time</div>
                </div>

                <div class="stat-card">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-medium text-gray-400">Pending Payments</div>
                        <div class="p-2 rounded-full bg-yellow-900/20">
                            <i class="fas fa-clock text-yellow-500"></i>
                        </div>
                    </div>
                    <div class="text-2xl font-bold">₱<?php echo number_format($pendingRevenue, 2); ?></div>
                    <div class="text-xs text-gray-500 mt-1">All time</div>
                </div>
            </div>

            <!-- Filters and Actions -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <label for="status-filter" class="block text-xs text-gray-400 mb-1">Status</label>
                        <select id="status-filter" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                            <option value="">All Statuses</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                    </div>
                    <div>
                        <label for="method-filter" class="block text-xs text-gray-400 mb-1">Payment Method</label>
                        <select id="method-filter" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                            <option value="">All Methods</option>
                            <option value="gcash" <?php echo $methodFilter === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                            <option value="cash" <?php echo $methodFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        </select>
                    </div>
                    <div>
                        <label for="date-filter" class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" id="date-filter" value="<?php echo $dateFilter; ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                    </div>
                    <div class="mt-6">
                        <button id="apply-filters" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                            Apply Filters
                        </button>
                    </div>
                </div>
                <button id="recordPaymentBtn" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    <span>Record Payment</span>
                </button>
            </div>

            <!-- Payments Table -->
            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] overflow-hidden">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Booking Date</th>
                            <th>Amount</th>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-gray-400">No payments found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $index => $payment): ?>
                                <tr data-payment-id="<?php echo $payment['PaymentID']; ?>" onclick="openPaymentModal(<?php echo $index; ?>)">
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <div class="avatar">
                                                <?php echo getInitials($payment['client_name']); ?>
                                            </div>
                                            <span><?php echo htmlspecialchars($payment['client_name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($payment['booking_date'])); ?></td>
                                    <td class="font-medium">₱<?php echo number_format($payment['Amount'], 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($payment['Pay_Date'])); ?></td>
                                    <td><?php echo getPaymentMethodBadge($payment['payment_method']); ?></td>
                                    <td><?php echo getPaymentStatusBadge($payment['Pay_Stats']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="flex justify-between items-center mt-4">
                <div class="text-sm text-gray-400">
                    Showing <span class="font-medium text-white"><?php echo count($payments); ?></span> payments
                </div>
                <div class="flex items-center gap-2">
                    <button class="bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-1 text-sm disabled:opacity-50" disabled>
                        Previous
                    </button>
                    <button class="bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-1 text-sm">
                        Next
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Payment Details Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Payment Details</h2>
                <button id="closeModalBtn" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="paymentDetails">
                <!-- Payment details will be populated here -->
            </div>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div id="recordPaymentModal" class="modal">
        <div class="modal-content p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Record New Payment</h2>
                <button id="closeRecordModalBtn" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form action="process_payment.php" method="post" class="space-y-4">
                <div>
                    <label for="booking" class="block text-sm font-medium text-gray-400 mb-1">Booking</label>
                    <select id="booking" name="booking_id" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        <option value="">Select a booking</option>
                        <?php
                        // Fetch unpaid bookings
                        $unpaidBookings = $pdo->prepare("
                            SELECT b.BookingID, b.booking_date, c.Name as client_name, 
                                sc.Time_Start, sc.Time_End, COALESCE(p.amount, 0.00) as amount
                            FROM bookings b
                            JOIN clients c ON b.ClientID = c.ClientID
                            JOIN studios s ON b.StudioID = s.StudioID
                            JOIN schedules sc ON b.ScheduleID = sc.ScheduleID
                            LEFT JOIN payment p ON b.BookingID = p.BookingID
                            WHERE s.OwnerID = ? 
                            AND (p.PaymentID IS NULL OR p.Pay_Stats = 'Pending')
                            ORDER BY b.booking_date DESC
                        ");
                        $unpaidBookings->execute([$ownerId]);
                        $unpaidBookings = $unpaidBookings->fetchAll(PDO::FETCH_ASSOC);

                        $bookingAmounts = [];
                        foreach ($unpaidBookings as $booking) {
                            $bookingDate = date('M d, Y', strtotime($booking['booking_date']));
                            $timeStart = date('g:i A', strtotime($booking['Time_Start']));
                            $timeEnd = date('g:i A', strtotime($booking['Time_End']));
                            echo '<option value="' . $booking['BookingID'] . '">' .
                                $bookingDate . ' - ' .
                                htmlspecialchars($booking['client_name']) . ' (' .
                                $timeStart . ' - ' . $timeEnd . ')</option>';
                            $bookingAmounts[$booking['BookingID']] = $booking['amount'];
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-400 mb-1">Amount (₱)</label>
                    <input type="number" id="amount" name="amount" step="0.01" readonly class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm cursor-not-allowed">
                </div>

                <div>
                    <label for="partial_amount" class="block text-sm font-medium text-gray-400 mb-1">Partial Payment (₱, 50% Remaining)</label>
                    <input type="number" id="partial_amount" name="partial_amount" step="0.01" readonly class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm cursor-not-allowed">
                </div>

                <div>
                    <label for="payment_method" class="block text-sm font-medium text-gray-400 mb-1">Payment Method</label>
                    <select id="payment_method" name="payment_method" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                    </select>
                </div>

                <div id="gcash_fields" class="space-y-4 hidden">
                    <div>
                        <label for="gcash_number" class="block text-sm font-medium text-gray-400 mb-1">GCash Number</label>
                        <input type="text" id="gcash_number" name="gcash_number" class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="reference_number" class="block text-sm font-medium text-gray-400 mb-1">Reference Number</label>
                        <input type="text" id="reference_number" name="reference_number" class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                <div id="cash_fields" class="space-y-4">
                    <div>
                        <label for="cash_amount" class="block text-sm font-medium text-gray-400 mb-1">Cash Amount</label>
                        <input type="number" id="cash_amount" name="cash_amount" step="0.01" class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label for="change_amount" class="block text-sm font-medium text-gray-400 mb-1">Change Amount</label>
                        <input type="number" id="change_amount" name="change_amount" step="0.01" class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                    <select id="status" name="status" required class="w-full bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-2 text-sm">
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" id="cancelRecordBtn" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                        Cancel
                    </button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                        Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Booking amounts from PHP
        const bookingAmounts = <?php echo json_encode($bookingAmounts); ?>;

        // Update amount and partial amount fields when a booking is selected
        document.getElementById('booking').addEventListener('change', function() {
            const bookingId = this.value;
            const amountField = document.getElementById('amount');
            const partialAmountField = document.getElementById('partial_amount');

            if (bookingId && bookingAmounts[bookingId] !== undefined) {
                const totalAmount = parseFloat(bookingAmounts[bookingId]);
                const partialAmount = (totalAmount * 0.5).toFixed(2); // 50% of total amount
                amountField.value = totalAmount.toFixed(2);
                partialAmountField.value = partialAmount;
            } else {
                amountField.value = '';
                partialAmountField.value = '';
            }
        });

        // Sidebar toggle removed; sidebar_netflix.php manages sidebar state and layout.

        // Filter functionality
        const statusFilter = document.getElementById('status-filter');
        const methodFilter = document.getElementById('method-filter');
        const dateFilter = document.getElementById('date-filter');
        const applyFiltersBtn = document.getElementById('apply-filters');

        function applyFilters() {
            const status = statusFilter.value;
            const method = methodFilter.value;
            const date = dateFilter.value;

            const params = new URLSearchParams();
            if (status) params.append('status', status);
            if (method) params.append('method', method);
            if (date) params.append('date', date);

            const queryString = params.toString();
            if (queryString) {
                window.location.href = 'payments.php?' + queryString;
            } else {
                window.location.href = 'payments.php';
            }
        }

        applyFiltersBtn.addEventListener('click', applyFilters);

        // Payment modal functionality
        const paymentModal = document.getElementById('paymentModal');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const paymentDetails = document.getElementById('paymentDetails');

        // Store payment data for modal
        const paymentData = <?php echo json_encode($payments); ?>;

        function openPaymentModal(index) {
            const payment = paymentData[index];

            // Format the payment details
            let detailsHTML = `
                <div class="space-y-4">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="avatar" style="width: 3rem; height: 3rem; font-size: 1rem;">
                            ${getInitials(payment.client_name)}
                        </div>
                        <div>
                            <h3 class="text-lg font-bold">${payment.client_name}</h3>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-400">Amount</p>
                            <p class="text-lg font-bold">₱${parseFloat(payment.Amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-400">Status</p>
                            <p>${getPaymentStatusBadge(payment.Pay_Stats)}</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-400">Payment Date</p>
                            <p>${new Date(payment.Pay_Date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-400">Payment Method</p>
                            <p>${getPaymentMethodBadge(payment.payment_method)}</p>
                        </div>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-400">Booking Date</p>
                        <p>${new Date(payment.booking_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-400">Service</p>
                        <p>${payment.ServiceType}</p>
                    </div>
            `;

            // Add GCash details if applicable
            if (payment.payment_method === 'GCash' && payment.gcash_number) {
                detailsHTML += `
                    <div class="mt-4 p-4 bg-[#0a0a0a] rounded-lg border border-[#222222]">
                        <h4 class="font-medium mb-2">GCash Details</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-400">GCash Number</p>
                                <p>${payment.gcash_number}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-400">Reference Number</p>
                                <p>${payment.reference_number || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                `;
            }

            detailsHTML += `
                </div>
                <div class="flex justify-end mt-6">
                    <button onclick="closePaymentModal()" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium">
                        Close
                    </button>
                </div>
            `;

            paymentDetails.innerHTML = detailsHTML;
            paymentModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePaymentModal() {
            paymentModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        closeModalBtn.addEventListener('click', closePaymentModal);

        // Close modal when clicking outside
        paymentModal.addEventListener('click', function(event) {
            if (event.target === paymentModal) {
                closePaymentModal();
            }
        });

        // Helper function to get initials
        function getInitials(name) {
            return name.split(' ').map(word => word[0]).join('').toUpperCase().substring(0, 2);
        }

        // Record Payment Modal functionality
        const recordPaymentModal = document.getElementById('recordPaymentModal');
        const recordPaymentBtn = document.getElementById('recordPaymentBtn');
        const closeRecordModalBtn = document.getElementById('closeRecordModalBtn');
        const cancelRecordBtn = document.getElementById('cancelRecordBtn');
        const paymentMethodSelect = document.getElementById('payment_method');
        const gcashFields = document.getElementById('gcash_fields');
        const cashFields = document.getElementById('cash_fields');

        function openRecordPaymentModal() {
            recordPaymentModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeRecordPaymentModal() {
            recordPaymentModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        recordPaymentBtn.addEventListener('click', openRecordPaymentModal);
        closeRecordModalBtn.addEventListener('click', closeRecordPaymentModal);
        cancelRecordBtn.addEventListener('click', closeRecordPaymentModal);

        // Toggle payment method fields
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value === 'gcash') {
                gcashFields.classList.remove('hidden');
                cashFields.classList.add('hidden');
            } else {
                gcashFields.classList.add('hidden');
                cashFields.classList.remove('hidden');
            }
        });

        // Close modal when clicking outside
        recordPaymentModal.addEventListener('click', function(event) {
            if (event.target === recordPaymentModal) {
                closeRecordPaymentModal();
            }
        });

        // Calculate change amount
        const amountInput = document.getElementById('amount');
        const cashAmountInput = document.getElementById('cash_amount');
        const changeAmountInput = document.getElementById('change_amount');

        function calculateChange() {
            const amount = parseFloat(amountInput.value) || 0;
            const cashAmount = parseFloat(cashAmountInput.value) || 0;
            const change = cashAmount - amount;

            changeAmountInput.value = change > 0 ? change.toFixed(2) : '0.00';
        }

        amountInput.addEventListener('input', calculateChange);
        cashAmountInput.addEventListener('input', calculateChange);

        // Highlight a specific payment row when deep-linked via ?payment_id=123
        document.addEventListener('DOMContentLoaded', function() {
            try {
                const params = new URLSearchParams(window.location.search);
                const targetId = params.get('payment_id');
                if (!targetId) return;

                const selector = `tr[data-payment-id="${CSS.escape(targetId)}"]`;
                const row = document.querySelector(selector);
                if (!row) return;

                row.classList.add('row-highlight');
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                console.warn('Payment highlight failed:', e);
            }
        });
    </script>
</body>

</html>

