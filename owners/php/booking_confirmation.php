<?php
// booking_confirmation.php
require_once __DIR__ . '/db.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if booking ID is provided
if (!isset($_GET['booking_id'])) {
    header('Location: booking.php');
    exit();
}

$booking_id = $_GET['booking_id'];

// Fetch booking details
$stmt = $conn->prepare("SELECT b.*, s.name as studio_name, s.address as studio_address,
                        s.contact_phone as studio_phone, s.contact_email as studio_email,
                        sv.name as service_name, sv.price, sv.duration,
                        i.name as instructor_name, u.name as client_name, u.email as client_email,
                        p.transaction_id, p.created_at as payment_date
                        FROM bookings b
                        JOIN studios s ON b.studio_id = s.id
                        JOIN services sv ON b.service_id = sv.id
                        JOIN instructors i ON b.instructor_id = i.id
                        JOIN users u ON b.client_id = u.id
                        LEFT JOIN payments p ON b.id = p.booking_id
                        WHERE b.id = ? AND b.client_id = ?");
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Booking not found or doesn't belong to this user
    header('Location: booking.php');
    exit();
}

$booking = $result->fetch_assoc();
?>

&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation - Studio Booking System</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .confirmation-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .confirmation-card {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .confirmation-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .confirmation-header .status {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .status.confirmed {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .status.pending {
            background-color: #fff8e1;
            color: #f57c00;
        }

        .confirmation-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .detail-section h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .detail-row {
            margin-bottom: 10px;
        }

        .detail-label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
            color: #555;
        }

        .payment-info {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .qr-code {
            text-align: center;
            margin-top: 30px;
        }

        .qr-code img {
            max-width: 200px;
            border: 1px solid #ddd;
            padding: 10px;
            background-color: #fff;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }

        .calendar-button {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        @media print {
            header, footer, .action-buttons {
                display: none;
            }

            body, .confirmation-container, .confirmation-card {
                margin: 0;
                padding: 0;
            }

            .confirmation-card {
                box-shadow: none;
                border: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Booking Confirmation</h1>
            <nav>
                <ul>
                    <li><a href="Home.php">Home</a></li>
                    <li><a href="browse.php">Browse Studios</a></li>
                    <li><a href="booking.php">Bookings</a></li>
                    <li><a href="gallery.html">Gallery</a></li>
                    <li><a href="blog.html">Blog</a></li>
                    <li><a href="about.html">About</a></li>
                    <li><a href="contact.html">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <section class="confirmation-container">
                <div class="confirmation-card">
                    <div class="confirmation-header">
                        <?php if ($booking['payment_status'] === 'paid'): ?>
                            <div class="status confirmed">Confirmed</div>
                            <h2>Your booking is confirmed!</h2>
                            <p>Booking #<?php echo $booking_id; ?></p>
                        <?php else: ?>
                            <div class="status pending">Pending Payment</div>
                            <h2>Your booking is pending payment</h2>
                            <p>Booking #<?php echo $booking_id; ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="confirmation-details">
                        <div class="detail-section">
                            <h3>Booking Details</h3>

                            <div class="detail-row">
                                <span class="detail-label">Studio</span>
                                <span><?php echo htmlspecialchars($booking['studio_name']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Service</span>
                                <span><?php echo htmlspecialchars($booking['service_name']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Instructor</span>
                                <span><?php echo htmlspecialchars($booking['instructor_name']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Date</span>
                                <span><?php echo date('l, F j, Y', strtotime($booking['booking_date'])); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Time</span>
                                <span><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Duration</span>
                                <span><?php echo $booking['duration']; ?> minutes</span>
                            </div>
                        </div>

                        <div class="detail-section">
                            <h3>Studio Information</h3>

                            <div class="detail-row">
                                <span class="detail-label">Address</span>
                                <span><?php echo nl2br(htmlspecialchars($booking['studio_address'])); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Phone</span>
                                <span><?php echo htmlspecialchars($booking['studio_phone']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Email</span>
                                <span><?php echo htmlspecialchars($booking['studio_email']); ?></span>
                            </div>

                            <?php if ($booking['payment_status'] === 'paid'): ?>
                                <div class="payment-info">
                                    <div class="detail-row">
                                        <span class="detail-label">Payment Status</span>
                                        <span>Paid</span>
                                    </div>

                                    <div class="detail-row">
                                        <span class="detail-label">Amount</span>
                                        <span>$<?php echo number_format($booking['price'], 2); ?></span>
                                    </div>

                                    <div class="detail-row">
                                        <span class="detail-label">Transaction ID</span>
                                        <span><?php echo htmlspecialchars($booking['transaction_id']); ?></span>
                                    </div>

                                    <div class="detail-row">
                                        <span class="detail-label">Payment Date</span>
                                        <span><?php echo date('F j, Y, h:i A', strtotime($booking['payment_date'])); ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="payment-info">
                                    <div class="detail-row">
                                        <span class="detail-label">Payment Status</span>
                                        <span>Pending</span>
                                    </div>

                                    <div class="detail-row">
                                        <span class="detail-label">Amount Due</span>
                                        <span>$<?php echo number_format($booking['price'], 2); ?></span>
                                    </div>

                                    <div style="display:flex; gap:10px; align-items:center; margin-top:10px;">
                                        <a href="payment.php?booking_id=<?php echo $booking_id; ?>" class="btn">Proceed to Payment</a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($booking['payment_status'] === 'paid'): ?>
                        <div class="qr-code">
                            <p>Show this QR code when you arrive at the studio</p>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=BOOKING-<?php echo $booking_id; ?>" alt="Booking QR Code">
                        </div>
                    <?php endif; ?>

                    <div class="action-buttons">
                        <button class="btn" onclick="window.print()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 6 2 18 2 18 9"></polyline>
                                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                                <rect x="6" y="14" width="12" height="8"></rect>
                            </svg>
                            Print
                        </button>

                        <a href="mailto:?subject=Studio%20Booking%20Confirmation&body=Here%20are%20my%20booking%20details%3A%0A%0AStudio%3A%20<?php echo urlencode($booking['studio_name']); ?>%0AService%3A%20<?php echo urlencode($booking['service_name']); ?>%0ADate%3A%20<?php echo urlencode(date('l, F j, Y', strtotime($booking['booking_date']))); ?>%0ATime%3A%20<?php echo urlencode(date('h:i A', strtotime($booking['booking_time']))); ?>%0A%0ABooking%20ID%3A%20<?php echo $booking_id; ?>" class="btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                <polyline points="22,6 12,13 2,6"></polyline>
                            </svg>
                            Email
                        </a>

                        <a href="https://calendar.google.com/calendar/render?action=TEMPLATE&text=<?php echo urlencode($booking['service_name'] . ' at ' . $booking['studio_name']); ?>&dates=<?php echo date('Ymd\THis', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'])); ?>/<?php echo date('Ymd\THis', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'] . ' +' . $booking['duration'] . ' minutes')); ?>&details=<?php echo urlencode('Booking ID: ' . $booking_id . '\nInstructor: ' . $booking['instructor_name']); ?>&location=<?php echo urlencode($booking['studio_address']); ?>" target="_blank" class="btn calendar-button">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            Add to Calendar
                        </a>
                    </div>
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; 2023 Studio Booking System. All rights reserved.</p>
        </footer>
    </div>
    <script>
    (function() {
        // Show transient status from redirect params
        const params = new URLSearchParams(window.location.search);
        const success = params.get('success');
        const error = params.get('error');
        if (success) {
            const el = document.createElement('div');
            el.className = 'alert alert-success';
            el.textContent = success;
            document.querySelector('.confirmation-container')?.prepend(el);
        }
        if (error) {
            const el = document.createElement('div');
            el.className = 'alert alert-danger';
            el.textContent = error;
            document.querySelector('.confirmation-container')?.prepend(el);
        }

        // Payment handling removed
    })();
    </script>
</body>
</html>
