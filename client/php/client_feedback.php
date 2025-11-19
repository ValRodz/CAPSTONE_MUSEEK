<?php
// Set session cookie parameters before starting the session
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
session_regenerate_id(true);
include '../../shared/config/db.php';
include '../../shared/config/db pdo.php';
include '../../shared/config/path_config.php';


error_log("Session ID in client_feedback.php: " . session_id());

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'client') {
    error_log("Authentication failed in client_feedback.php. Session data: " . print_r($_SESSION, true));
    header('Location: ../../auth/php/login.php');
    exit();
}

if (!isset($_GET['booking_id']) || !is_numeric($_GET['booking_id'])) {
    header('Location: bookings.php?error=Invalid booking ID');
    exit();
}

$booking_id = (int)$_GET['booking_id'];
$client_id = $_SESSION['user_id'];

$booking_query = "
    SELECT 
        b.BookingID, 
        s.StudioName, 
        s.OwnerID, 
        bs.Book_Stats,
        c.Name as ClientName
    FROM bookings b
    JOIN studios s ON b.StudioID = s.StudioID
    JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
    JOIN clients c ON b.ClientID = c.ClientID
    WHERE b.BookingID = ? AND b.ClientID = ? AND bs.Book_Stats IN ('Finished', 'Archived')
";
$stmt = mysqli_prepare($conn, $booking_query);
mysqli_stmt_bind_param($stmt, "ii", $booking_id, $client_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    header('Location: bookings.php?error=Booking not found or not completed');
    exit();
}

$booking = mysqli_fetch_assoc($result);
$studio_name = $booking['StudioName'];
$owner_id = $booking['OwnerID'];
$client_name = $booking['ClientName'];
mysqli_stmt_close($stmt);

// Check if feedback already exists for this booking
$feedback_query = "SELECT FeedbackID FROM feedback WHERE ClientID = ? AND BookingID = ?";
$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "ii", $client_id, $booking_id);
mysqli_stmt_execute($stmt);
$feedback_result = mysqli_stmt_get_result($stmt);
$feedback_exists = mysqli_num_rows($feedback_result) > 0;
mysqli_stmt_close($stmt);

if ($feedback_exists) {
    mysqli_close($conn);
    header('Location: bookings.php?error=Feedback already submitted for this booking');
    exit();
}

// Handle form submission via AJAX
$errors = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    // Validate input
    if ($rating < 1 || $rating > 5) {
        $errors[] = "Please select a rating between 1 and 5.";
    }
    if (empty($comment)) {
        $errors[] = "Comment is required.";
    } elseif (strlen($comment) > 255) {
        $errors[] = "Comment must be 255 characters or less.";
    }

    if (empty($errors)) {
        // Generate a unique FeedbackID
        $feedback_id_query = "SELECT MAX(FeedbackID) AS max_id FROM feedback";
        $result = mysqli_query($conn, $feedback_id_query);
        if (!$result) {
            error_log("Error fetching max FeedbackID: " . mysqli_error($conn));
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ["Database error while generating feedback ID."]]);
            exit();
        }

        $row = mysqli_fetch_assoc($result);
        $feedback_id = ($row['max_id'] ?? 0) + 1;

        // Insert feedback
        $insert_query = "INSERT INTO feedback (FeedbackID, OwnerID, ClientID, BookingID, Rating, Comment) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_query);
        if (!$stmt) {
            error_log("Error preparing insert query: " . mysqli_error($conn));
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ["Database error while preparing feedback submission."]]);
            exit();
        }

        mysqli_stmt_bind_param($stmt, "iiiiis", $feedback_id, $owner_id, $client_id, $booking_id, $rating, $comment);
        if (mysqli_stmt_execute($stmt)) {
            error_log("Feedback submitted for BookingID $booking_id by ClientID $client_id");
            $success = true;
            mysqli_stmt_close($stmt);
            
            // Insert notification for owner
            try {
                $stars = str_repeat('⭐', $rating);
                $notificationMessage = "{$client_name} rated your studio {$stars} ({$rating}/5). Click to view feedback.";
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, For_User, Created_At, IsRead) 
                    VALUES (?, ?, 'feedback', ?, ?, 'Owner', NOW(), 0)
                ");
                $notifStmt->execute([$owner_id, $client_id, $notificationMessage, $feedback_id]);
                error_log("Notification created for OwnerID $owner_id - New feedback from ClientID $client_id");
            } catch (Exception $e) {
                // Log error but don't break feedback submission
                error_log("Failed to create feedback notification: " . $e->getMessage());
            }
            
            mysqli_close($conn);
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        } else {
            error_log("Error submitting feedback for BookingID $booking_id: " . mysqli_error($conn));
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'errors' => ["Error submitting feedback: " . mysqli_error($conn)]]);
            exit();
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit();
    }
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Submit Feedback - MuSeek</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;600;700;900&display=swap" rel="stylesheet" type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Modern CSS Variables for consistent theming */
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --secondary-color: #3b82f6;
            --background-dark: #0f0f0f;
            --background-card: rgba(20, 20, 20, 0.95);
            --text-primary: #ffffff;
            --text-secondary: #b3b3b3;
            --border-color: #333333;
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.4);
            --border-radius: 12px;
        }

        #branding img {
            width: 180px;
            display: block;
        }

        body,
        main {
            background: linear-gradient(135deg, rgba(15, 15, 15, 0.9), rgba(30, 30, 30, 0.8)),
                url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
        }

        .feedback-section {
            padding: 40px 0;
            margin: 100px 0;
            width: 100%;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .feedback-container {
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
        }

        .feedback-form {
            background: var(--background-card);
            padding: 30px;
            border-radius: var(--border-radius);
            color: var(--text-primary);
            position: relative;
            box-shadow: var(--shadow-medium);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .feedback-form h2 {
            margin: 0 0 20px;
            color: var(--text-primary);
            font-size: 28px;
            text-align: center;
            font-weight: 600;
        }

        .close-button {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 22px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .close-button:hover {
            color: #fff;
            background: var(--primary-color);
            transform: rotate(90deg);
        }

        .feedback-form .form-group {
            margin-bottom: 15px;
        }

        .feedback-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #ccc;
        }

        .feedback-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-small);
            background: rgba(34, 34, 34, 0.8);
            color: var(--text-primary);
            font-size: 16px;
            resize: vertical;
            transition: border-color 0.3s ease;
        }

        .feedback-form textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .star-rating {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .star-rating input {
            display: none;
        }

        .star-rating label {
            font-size: 36px;
            color: #ccc;
            cursor: pointer;
            margin: 0 8px;
            transition: all 0.2s ease;
            text-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
        }

        .star-rating label:hover {
            transform: scale(1.1);
        }

        .star-rating.rating-1 label:nth-child(-n+2),
        .star-rating.rating-2 label:nth-child(-n+4),
        .star-rating.rating-3 label:nth-child(-n+6),
        .star-rating.rating-4 label:nth-child(-n+8),
        .star-rating.rating-5 label:nth-child(-n+10) {
            color: #f5c518;
            text-shadow: 0 0 10px rgba(245, 197, 24, 0.5);
        }

        .feedback-form button {
            padding: 12px 24px;
            background: var(--primary-color);
            border: none;
            border-radius: var(--border-radius-small);
            color: var(--text-primary);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-top: 10px;
        }

        .feedback-form button:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.2);
        }

        .error {
            color: #dc3545;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .success {
            color: #28a745;
            font-size: 14px;
            margin-bottom: 10px;
            text-align: center;
        }

        .feedback-note {
            color: var(--text-secondary);
            font-size: 14px;
            text-align: center;
            margin-top: 20px;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .feedback-section {
                padding: 20px 0;
            }

            .feedback-container {
                padding: 0 10px;
            }

            .feedback-form {
                padding: 15px;
            }

            .feedback-form h2 {
                font-size: 20px;
            }

            .star-rating label {
                font-size: 24px;
                margin: 0 3px;
            }

            .feedback-form textarea {
                font-size: 12px;
            }

            .feedback-form button {
                font-size: 14px;
                padding: 8px 16px;
            }

            .close-button {
                top: 8px;
                right: 8px;
                font-size: 18px;
            }
        }
    </style>
</head>

<body class="header-collapse">
    <div id="site-content">
        <?php include '../../shared/components/navbar.php'; ?>
        <main class="main-content">
            <div class="feedback-section">
                <div class="feedback-container">
                    <form id="feedback-form" class="feedback-form" method="POST">
                        <span class="close-button" onclick="window.location.href='client_bookings.php'">×</span>
                        <h2>We Value Your Feedback! Help Us Improve (Booking #<?php echo $booking_id; ?>)</h2>
                        <div id="form-errors" class="error" style="display: none;"></div>
                        <div class="form-group">
                            <label>Rating</label>
                            <div class="star-rating" id="star-rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <input type="radio" id="rating-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" <?php echo isset($_POST['rating']) && $_POST['rating'] == $i ? 'checked' : ''; ?>>
                                    <label for="rating-<?php echo $i; ?>" data-value="<?php echo $i; ?>">★</label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="comment">Comment</label>
                            <textarea id="comment" name="comment" rows="5" maxlength="255"><?php echo isset($_POST['comment']) ? htmlspecialchars($_POST['comment']) : ''; ?></textarea>
                        </div>
                        <button type="submit">Submit Feedback</button>
                        <p class="feedback-note">This feedback could help studios improve their services and enhance your future experiences.</p>
                    </form>
                </div>
            </div>
        </main>
        <?php include '../../shared/components/footer.php'; ?>
    </div>
    <script src="../../shared/assets/js/jquery-1.11.1.min.js"></script>
    <script src="../../shared/assets/js/plugins.js"></script>
    <script src="../../shared/assets/js/app.js"></script>
    <script>
        const starRating = document.getElementById('star-rating');
        const labels = starRating.querySelectorAll('label');
        const inputs = starRating.querySelectorAll('input');

        // Set initial rating if selected
        const checkedInput = starRating.querySelector('input:checked');
        if (checkedInput) {
            starRating.classList.add(`rating-${checkedInput.value}`);
        }

        // Handle hover
        labels.forEach(label => {
            label.addEventListener('mouseover', () => {
                const value = label.getAttribute('data-value');
                starRating.classList.remove('rating-1', 'rating-2', 'rating-3', 'rating-4', 'rating-5');
                starRating.classList.add(`rating-${value}`);
            });

            label.addEventListener('mouseout', () => {
                const checkedInput = starRating.querySelector('input:checked');
                starRating.classList.remove('rating-1', 'rating-2', 'rating-3', 'rating-4', 'rating-5');
                if (checkedInput) {
                    starRating.classList.add(`rating-${checkedInput.value}`);
                }
            });
        });

        // Handle click
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                starRating.classList.remove('rating-1', 'rating-2', 'rating-3', 'rating-4', 'rating-5');
                starRating.classList.add(`rating-${input.value}`);
            });
        });

        // Handle form submission
        const form = document.getElementById('feedback-form');
        const errorDiv = document.getElementById('form-errors');

        form.addEventListener('submit', (e) => {
            e.preventDefault(); // Prevent default form submission

            const formData = new FormData(form);
            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Thank you for your feedback! We appreciate your input.');
                        window.location.href = '../../';
                    } else {
                        // Display errors
                        errorDiv.style.display = 'block';
                        errorDiv.innerHTML = data.errors.map(error => `<p>${error}</p>`).join('');
                    }
                })
                .catch(error => {
                    errorDiv.style.display = 'block';
                    errorDiv.innerHTML = `<p>Error submitting feedback: ${error.message}</p>`;
                });
        });
    </script>
</body>

</html>
