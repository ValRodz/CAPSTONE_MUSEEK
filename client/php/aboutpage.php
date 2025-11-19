<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

if ($is_authenticated) {
    $client_query = "SELECT Name, Email, Phone FROM clients WHERE ClientID = ?";
    $stmt = mysqli_prepare($conn, $client_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $client_result = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($client_result) ?: [
        'Name' => 'Unknown',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
    mysqli_stmt_close($stmt);
} else {
    $client = [
        'Name' => 'Guest',
        'Email' => 'N/A',
        'Phone' => 'N/A'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>About MuSeek - MuSeek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet"
        type="text/css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        :root {
            --primary-color: #e50914;
            --primary-hover: #f40612;
            --bg-dark: #141414;
            --bg-darker: #0b0b0b;
            --bg-light: #1f1f1f;
            --text-primary: #fff;
            --text-secondary: #ccc;
            --border-color: #333;
            --input-bg: #333;
            --input-text: #fff;
            --input-placeholder: #aaa;
            --button-bg: #e50914;
            --button-text: #fff;
            --card-bg: rgba(30, 30, 30, 0.9);
            --card-border: #333;
            --success: #2ecc71;
            --error: #e74c3c;
        }

        #branding img {
            width: 180px;
            display: block;
        }

        body,
        main {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), 
                        url('../../shared/assets/images/dummy/slide-1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            font-family: 'Source Sans Pro', sans-serif;
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .about-container {
            display: flex;
            flex-direction: column;
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .about-section {
            padding: 40px;
            margin-bottom: 30px;
            width: 100%;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            position: relative;
            color: var(--text-primary);
            z-index: 1;
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .about-section .container {
            max-width: 100%;
        }

        .about-section h2 {
            font-size: 32px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
        }

        .about-section h2:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--primary-color);
        }

        .about-section p {
            margin: 0 0 20px;
            font-size: 16px;
            line-height: 1.7;
            color: var(--text-secondary);
        }

        .about-section .contact-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .about-section .contact-info p {
            font-size: 16px;
        }

        .about-section .contact-info a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .about-section .contact-info a:hover {
            color: var(--primary-hover);
        }

        .contact-form-section {
            padding: 40px;
            background: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .contact-form-section h2 {
            font-size: 32px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
        }

        .contact-form-section h2:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--primary-color);
        }

        .contact-form {
            margin-top: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--input-text);
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(229, 9, 20, 0.2);
        }

        .form-control::placeholder {
            color: var(--input-placeholder);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .btn-submit {
            display: inline-block;
            padding: 12px 24px;
            background-color: var(--button-bg);
            color: var(--button-text);
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
        }

        .success-message,
        .error-message {
            padding: 10px 15px;
            margin-top: 15px;
            border-radius: 4px;
            display: none;
        }

        .success-message {
            background-color: rgba(46, 204, 113, 0.2);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .error-message {
            background-color: rgba(231, 76, 60, 0.2);
            border: 1px solid var(--error);
            color: var(--error);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .feature-item {
            padding: 20px;
            background: rgba(40, 40, 40, 0.5);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .feature-icon {
            font-size: 36px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .feature-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .about-section,
            .contact-form-section {
                padding: 30px 20px;
            }

            .about-section h2,
            .contact-form-section h2 {
                font-size: 24px;
            }

            .about-section p {
                font-size: 14px;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <!--[if lt IE 9]>
    <script src="../../shared/assets/js/ie-support/html5.js"></script>
    <script src="../../shared/assets/js/ie-support/respond.js"></script>
    <![endif]-->
</head>

<body>
    <div id="site-content">
       <?php include '../../shared/components/navbar.php'; ?>
        <main>
            <div class="about-container">
                <div class="about-section">
                    <div class="container">
                        <h2>About MuSeek</h2>
                        <p>MuSeek is a web-based platform designed to simplify booking and managing music studio sessions
                            for musicians, bands, and studio owners in Bacolod City, Negros Occidental. Our mission is to
                            streamline studio operations, reduce scheduling conflicts, and help you focus on creativity.</p>
                        
                        <div class="feature-grid">
                            <div class="feature-item">
                                <div class="feature-icon"><i class="fas fa-search"></i></div>
                                <div class="feature-title">Find Studios</div>
                                <p>Easily browse nearby studios with our intuitive search tools and location-based filters.</p>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon"><i class="fas fa-calendar-alt"></i></div>
                                <div class="feature-title">Book Sessions</div>
                                <p>Check real-time availability and book recording sessions, rehearsals, and lessons.</p>
                            </div>
                            <div class="feature-item">
                                <div class="feature-icon"><i class="fas fa-comments"></i></div>
                                <div class="feature-title">Connect</div>
                                <p>Communicate directly with studio owners to discuss your project needs and requirements.</p>
                            </div>
                        </div>
                        
                        <p>With MuSeek, you can easily browse nearby studios, check real-time availability, book sessions,
                            and explore services like recording, rehearsals, and lessons. Studio owners can manage their
                            schedules, update rates, and connect with clients effortlessly.</p>
                        <p>We aim to enhance your experience with a user-friendly interface, location-based search, and a
                            helpful chatbot for quick inquiries. Join us to tune in, book up, and rock out!</p>
                        <div class="contact-info">
                            <p>Contact us at: <a href="mailto:kyzzer.jallorina@gmail.com">kyzzer.jallorina@gmail.com</a> or
                                call <a href="tel:+639508199489">(+63) 950 819 9489</a></p>
                            <p>Based in Talisay City, Negros Occidental</p>
                        </div>
                    </div>
                </div>
                
                <div class="contact-form-section">
                    <h2>Contact Us</h2>
                    <p>Have questions, feedback, or need assistance? Fill out the form below and our team will get back to you as soon as possible.</p>
                    
                    <form class="contact-form" id="contactForm" method="post" action="process_contact.php">
                        <div class="form-group">
                            <label for="name">Your Name</label>
                            <input type="text" class="form-control" id="name" name="name" placeholder="Enter your name" required
                                value="<?php echo $is_authenticated ? htmlspecialchars($client['Name']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required
                                value="<?php echo $is_authenticated ? htmlspecialchars($client['Email']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input type="text" class="form-control" id="subject" name="subject" placeholder="Enter subject" required>
                        </div>
                        <div class="form-group">
                            <label for="message">Message</label>
                            <textarea class="form-control" id="message" name="message" placeholder="Enter your message" required></textarea>
                        </div>
                        <button type="submit" class="btn-submit">Send Message</button>
                        
                        <div class="success-message" id="successMessage">
                            Your message has been sent successfully. We'll get back to you soon!
                        </div>
                        <div class="error-message" id="errorMessage">
                            There was an error sending your message. Please try again later.
                        </div>
                    </form>
                </div>
            </div>
        </main>
        <?php include '../../shared/components/footer.php'; ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo getJSPath('plugins.js'); ?>"></script>
    <script src="<?php echo getJSPath('app.js'); ?>"></script>
    <script>
        $(document).ready(function() {
            // Handle contact form submission
            $('#contactForm').on('submit', function(e) {
                e.preventDefault();
                
                // Hide any previous messages
                $('#successMessage, #errorMessage').hide();
                
                // Get form data
                var formData = $(this).serialize();
                
                // Submit form via AJAX
                $.ajax({
                    type: 'POST',
                    url: 'process_contact.php',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Show success message
                            $('#successMessage').text(response.message).fadeIn();
                            // Reset form
                            $('#contactForm')[0].reset();
                        } else {
                            // Show error message
                            $('#errorMessage').text(response.message).fadeIn();
                        }
                    },
                    error: function() {
                        // Show generic error message
                        $('#errorMessage').text('An error occurred. Please try again later.').fadeIn();
                    }
                });
            });
        });
    </script>
</body>
</html>
