<?php
// payment.php
include '../../shared/config/db.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/php/login.html');
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
$stmt = $conn->prepare("SELECT b.*, s.StudioName as studio_name, sv.ServiceType as service_name, sv.Price,
                        i.Name as instructor_name, c.Name as client_name, c.Email as client_email
                        FROM bookings b
                        JOIN studios s ON b.StudioID = s.StudioID
                        JOIN services sv ON b.ServiceID = sv.ServiceID
                        JOIN instructors i ON b.InstructorID = i.InstructorID
                        JOIN clients c ON b.ClientID = c.ClientID
                        WHERE b.BookingID = ? AND b.ClientID = ?");
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Booking not found or doesn't belong to this user
    header('Location: booking.php');
    exit();
}

$booking = $result->fetch_assoc();

// Check if booking is already paid
if ($booking['payment_status'] === 'paid') {
    header('Location: booking_confirmation.php?booking_id=' . $booking_id);
    exit();
}

// Process payment submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'];
    $card_number = isset($_POST['card_number']) ? $_POST['card_number'] : '';
    $card_expiry = isset($_POST['card_expiry']) ? $_POST['card_expiry'] : '';
    $card_cvv = isset($_POST['card_cvv']) ? $_POST['card_cvv'] : '';
    $amount = $booking['price'];

    // Generate a transaction ID
    $transaction_id = 'TXN' . time() . rand(1000, 9999);

    // In a real application, you would integrate with a payment gateway here
    // For this example, we'll simulate a successful payment

    // Start transaction
    $conn->begin_transaction();

    try {
        // Create payment record
        $stmt = $conn->prepare("INSERT INTO payments (booking_id, amount, payment_method, transaction_id, status)
                                VALUES (?, ?, ?, ?, 'completed')");
        $stmt->bind_param("idss", $booking_id, $amount, $payment_method, $transaction_id);
        $stmt->execute();

        // Update booking payment status
        $stmt = $conn->prepare("UPDATE bookings SET payment_status = 'paid' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();

        // Commit transaction
        $conn->commit();

        // Redirect to confirmation page
        header('Location: booking_confirmation.php?booking_id=' . $booking_id);
        exit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Payment processing failed: " . $e->getMessage();
    }
}
?>

&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Studio Booking System</title>
    <link rel="stylesheet" href="../../shared/assets/css/style.css">
    <style>
        .payment-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .payment-summary {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .payment-summary h3 {
            margin-top: 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .summary-label {
            font-weight: bold;
        }

        .total-row {
            border-top: 1px solid #ddd;
            padding-top: 10px;
            margin-top: 10px;
            font-size: 1.2em;
            font-weight: bold;
        }

        .payment-methods {
            margin-bottom: 30px;
        }

        .payment-method {
            margin-bottom: 15px;
        }

        .payment-method input[type="radio"] {
            margin-right: 10px;
        }

        .payment-details {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .card-row {
            display: flex;
            gap: 15px;
        }

        .card-element {
            flex: 1;
        }

        .card-element.small {
            flex: 0 0 100px;
        }

        .secure-badge {
            display: flex;
            align-items: center;
            color: #4CAF50;
            font-size: 0.9em;
            margin-top: 20px;
        }

        .secure-badge svg {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Complete Your Payment</h1>
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
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <section class="payment-container">
                <div class="payment-summary">
                    <h3>Booking Summary</h3>

                    <div class="summary-row">
                        <span class="summary-label">Studio:</span>
                        <span><?php echo htmlspecialchars($booking['studio_name']); ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="summary-label">Service:</span>
                        <span><?php echo htmlspecialchars($booking['service_name']); ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="summary-label">Instructor:</span>
                        <span><?php echo htmlspecialchars($booking['instructor_name']); ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="summary-label">Date:</span>
                        <span><?php echo date('l, F j, Y', strtotime($booking['booking_date'])); ?></span>
                    </div>

                    <div class="summary-row">
                        <span class="summary-label">Time:</span>
                        <span><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></span>
                    </div>

                    <div class="summary-row total-row">
                        <span>Total Amount:</span>
                        <span>$<?php echo number_format($booking['price'], 2); ?></span>
                    </div>
                </div>

                <form method="post" id="payment-form">
                    <div class="payment-methods">
                        <h3>Select Payment Method</h3>

                        <div class="payment-method">
                            <input type="radio" id="credit-card" name="payment_method" value="credit_card" checked>
                            <label for="credit-card">Credit Card</label>
                        </div>

                        <div class="payment-method">
                            <input type="radio" id="paypal" name="payment_method" value="paypal">
                            <label for="paypal">PayPal</label>
                        </div>

                        <div id="credit-card-details" class="payment-details">
                            <div class="form-group">
                                <label for="card-name">Cardholder Name</label>
                                <input type="text" id="card-name" name="card_name" required>
                            </div>

                            <div class="form-group">
                                <label for="card-number">Card Number</label>
                                <input type="text" id="card-number" name="card_number" placeholder="1234 5678 9012 3456" required>
                            </div>

                            <div class="card-row">
                                <div class="card-element form-group">
                                    <label for="card-expiry">Expiration Date</label>
                                    <input type="text" id="card-expiry" name="card_expiry" placeholder="MM/YY" required>
                                </div>

                                <div class="card-element small form-group">
                                    <label for="card-cvv">CVV</label>
                                    <input type="text" id="card-cvv" name="card_cvv" placeholder="123" required>
                                </div>
                            </div>

                            <div class="secure-badge">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                Secure Payment
                            </div>
                        </div>

                        <div id="paypal-details" class="payment-details" style="display: none;">
                            <p>You will be redirected to PayPal to complete your payment.</p>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Pay Now $<?php echo number_format($booking['price'], 2); ?></button>
                        <a href="booking.php" class="btn">Cancel</a>
                    </div>
                </form>
            </section>
        </main>

        <footer>
            <p>&copy; 2023 Studio Booking System. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Toggle payment method details
        document.addEventListener('DOMContentLoaded', function() {
            const creditCardRadio = document.getElementById('credit-card');
            const paypalRadio = document.getElementById('paypal');
            const creditCardDetails = document.getElementById('credit-card-details');
            const paypalDetails = document.getElementById('paypal-details');

            creditCardRadio.addEventListener('change', function() {
                if (this.checked) {
                    creditCardDetails.style.display = 'block';
                    paypalDetails.style.display = 'none';
                }
            });

            paypalRadio.addEventListener('change', function() {
                if (this.checked) {
                    creditCardDetails.style.display = 'none';
                    paypalDetails.style.display = 'block';
                }
            });

            // Format credit card number
            const cardNumberInput = document.getElementById('card-number');
            cardNumberInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 16) {
                    value = value.substr(0, 16);
                }

                // Add spaces every 4 digits
                let formattedValue = '';
                for (let i = 0; i &lt; value.length; i++) {
                    if (i > 0 && i % 4 === 0) {
                        formattedValue += ' ';
                    }
                    formattedValue += value[i];
                }

                e.target.value = formattedValue;
            });

            // Format expiry date
            const expiryInput = document.getElementById('card-expiry');
            expiryInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 4) {
                    value = value.substr(0, 4);
                }

                if (value.length > 2) {
                    value = value.substr(0, 2) + '/' + value.substr(2);
                }

                e.target.value = value;
            });

            // Format CVV
            const cvvInput = document.getElementById('card-cvv');
            cvvInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length > 3) {
                    value = value.substr(0, 3);
                }

                e.target.value = value;
            });
        });
    </script>
</body>
</html>
