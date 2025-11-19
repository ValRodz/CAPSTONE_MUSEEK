<?php
session_start();
include '../../shared/config/db.php';
include '../../shared/config/path_config.php';

$is_authenticated = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);

// Fetch subscription plans from database
$plans_query = "SELECT plan_id, plan_name, description, monthly_price, features FROM subscription_plans WHERE is_active = 1 ORDER BY monthly_price ASC";
$plans_result = mysqli_query($conn, $plans_query);
$subscription_plans = [];

while ($plan = mysqli_fetch_assoc($plans_result)) {
    $subscription_plans[] = $plan;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Prices - MuSeek</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo getCSSPath('style.css'); ?>">
    <style>
        /* Netflix-style Global Styles */
        body {
            background: #141414;
            color: #fff;
            font-family: 'Source Sans Pro', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        .hero {
            position: relative;
            width: 100%;
            height: 40vh;
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.08)), url('<?php echo getDummyPath('slide-1.jpg'); ?>');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0;
        }

        .hero-content {
            text-align: center;
            max-width: 800px;
            padding: 0 20px;
            z-index: 2;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
            color: rgba(229, 9, 20, 0.9);
        }

        .hero-subtitle {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            opacity: 0.9;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.8);
        }

        .pricing-section {
            background: #141414;
            padding: 60px 0;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .section-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-title {
            color: #ffffff;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #e50914, #f40612);
            border-radius: 2px;
        }

        .section-subtitle {
            color: #b3b3b3;
            font-size: 1.2rem;
            margin-top: 30px;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }

        .pricing-card {
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 16px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }

        .pricing-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(229, 9, 20, 0.1) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .pricing-card:hover::before {
            opacity: 1;
        }

        .pricing-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(229, 9, 20, 0.3);
        }

        .pricing-card.featured {
            border: 2px solid #e50914;
            transform: scale(1.05);
        }

        .pricing-card.featured::after {
            content: 'POPULAR';
            position: absolute;
            top: 20px;
            right: -30px;
            background: #e50914;
            color: white;
            padding: 5px 40px;
            font-size: 12px;
            font-weight: 600;
            transform: rotate(45deg);
            z-index: 1;
        }

        .plan-name {
            color: #ffffff;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .plan-price {
            color: #e50914;
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .plan-price span {
            font-size: 1.2rem;
            color: #b3b3b3;
        }

        .plan-description {
            color: #b3b3b3;
            font-size: 1rem;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        .features-list {
            list-style: none;
            padding: 0;
            margin: 0 0 30px 0;
            text-align: left;
        }

        .features-list li {
            color: #ffffff;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
        }

        .features-list li:last-child {
            border-bottom: none;
        }

        .features-list li i {
            color: #e50914;
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .features-list li .feature-name {
            flex: 1;
        }

        .features-list li .feature-value {
            color: #b3b3b3;
            font-size: 0.9rem;
        }

        .cta-button {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #e50914 0%, #b8070f 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(229, 9, 20, 0.3);
            border: none;
            cursor: pointer;
        }

        .cta-button:hover {
            background: linear-gradient(135deg, #f40612 0%, #d1080e 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(229, 9, 20, 0.4);
        }

        .cta-button.secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .cta-button.secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .comparison-table {
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 16px;
            padding: 40px;
            margin-top: 60px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .comparison-title {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 30px;
            text-align: center;
        }

        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr repeat(2, 1fr);
            gap: 1px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .comparison-cell {
            padding: 15px;
            background: #2a2a2a;
            text-align: center;
        }

        .comparison-cell.header {
            background: #1f1f1f;
            font-weight: 600;
            color: #ffffff;
            text-align: left;
        }

        .comparison-cell.plan-header {
            background: #e50914;
            color: white;
            font-weight: 700;
        }

        .comparison-cell.feature-yes {
            color: #4CAF50;
        }

        .comparison-cell.feature-no {
            color: #f44336;
        }

        .faq-section {
            margin-top: 60px;
            text-align: center;
        }

        .faq-title {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 30px;
        }

        .faq-item {
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            text-align: left;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .faq-question {
            color: #ffffff;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .faq-question:hover {
            color: #e50914;
        }

        .faq-answer {
            color: #b3b3b3;
            font-size: 1rem;
            line-height: 1.5;
            display: none;
        }

        .faq-answer.show {
            display: block;
        }

        .contact-cta {
            text-align: center;
            margin-top: 60px;
            padding: 40px;
            background: linear-gradient(145deg, #1f1f1f 0%, #2a2a2a 100%);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .contact-cta h3 {
            color: #ffffff;
            font-size: 1.8rem;
            margin-bottom: 15px;
        }

        .contact-cta p {
            color: #b3b3b3;
            font-size: 1.1rem;
            margin-bottom: 25px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.8rem;
            }

            .hero-subtitle {
                font-size: 1.2rem;
            }

            .section-title {
                font-size: 2rem;
            }

            .pricing-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .pricing-card.featured {
                transform: none;
            }

            .comparison-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .comparison-cell {
                text-align: left;
                padding: 10px;
            }

            .container {
                padding: 0 15px;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 2.2rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            .section-title {
                font-size: 1.8rem;
            }

            .plan-name {
                font-size: 1.5rem;
            }

            .plan-price {
                font-size: 2.5rem;
            }

            .pricing-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body class="header-collapse">
    <div id="site-content">
        <?php include '../../shared/components/navbar.php'; ?>
        
        <div class="hero">
            <div class="hero-content">
                <h1 class="hero-title">MuSeek</h1>
                <p class="hero-subtitle">Choose the perfect plan for your studio</p>
            </div>
        </div>

        <main class="main-content">
            <div class="pricing-section">
                <div class="container">
                    <div class="section-header">
                        <h2 class="section-title">Subscription Plans</h2>
                        <p class="section-subtitle">Unlock the full potential of your recording studio with GCash subscription plans</p>
                    </div>

                    <div class="pricing-grid">
                        <?php if (!empty($subscription_plans)): ?>
                            <?php foreach ($subscription_plans as $index => $plan): ?>
                                <div class="pricing-card <?php echo $index === 1 ? 'featured' : ''; ?>">
                                    <div class="plan-name"><?php echo htmlspecialchars($plan['plan_name']); ?> Plan</div>
                                    <div class="plan-price">₱<?php echo number_format($plan['monthly_price'], 0); ?><span>/month</span></div>
                                    <div class="plan-description"><?php echo htmlspecialchars($plan['description']); ?></div>
                                    <ul class="features-list">
                                        <?php 
                                        $features = explode(',', $plan['features']);
                                        foreach ($features as $feature):
                                            $feature = trim($feature);
                                            if (!empty($feature)):
                                        ?>
                                            <li><i class="fas fa-check"></i><span class="feature-name"><?php echo htmlspecialchars($feature); ?></span></li>
                                        <?php endif; endforeach; ?>
                                    </ul>
                                    <a href="<?php echo $is_authenticated ? '../../auth/php/owner_register.php' : '../../auth/php/login.php'; ?>" class="cta-button">Subscribe with GCash</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-studios">
                                <i class="fa fa-music"></i>
                                <h3>No Subscription Plans Available</h3>
                                <p>We're working on adding subscription plans. Please check back soon!</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($subscription_plans)): ?>
                    <div class="comparison-table">
                        <h3 class="comparison-title">Plan Comparison</h3>
                        <div class="comparison-grid">
                            <div class="comparison-cell header">Features</div>
                            <?php foreach ($subscription_plans as $plan): ?>
                                <div class="comparison-cell plan-header"><?php echo htmlspecialchars($plan['plan_name']); ?></div>
                            <?php endforeach; ?>

                            <div class="comparison-cell header">Monthly Price</div>
                            <?php foreach ($subscription_plans as $plan): ?>
                                <div class="comparison-cell">₱<?php echo number_format($plan['monthly_price'], 0); ?></div>
                            <?php endforeach; ?>

                            <div class="comparison-cell header">Key Features</div>
                            <?php foreach ($subscription_plans as $plan): ?>
                                <div class="comparison-cell">
                                    <?php 
                                    $features = explode(',', $plan['features']);
                                    $first_three = array_slice($features, 0, 3);
                                    foreach ($first_three as $feature):
                                        echo htmlspecialchars(trim($feature)) . '<br>';
                                    endforeach;
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="faq-section">
                        <h3 class="faq-title">Frequently Asked Questions</h3>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                What payment methods do you accept?
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                We only accept GCash for all subscription payments.
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                Can I change plans later?
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                Yes! You can upgrade or downgrade your plan at any time. Changes take effect immediately and billing is prorated.
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFAQ(this)">
                                How do I cancel my subscription?
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                You can cancel your subscription anytime from your account settings. Your access will continue until the end of your current billing period.
                            </div>
                        </div>
                    </div>

                    <div class="contact-cta">
                        <h3>Ready to Get Started?</h3>
                        <p>Join hundreds of studio owners who trust MuSeek to manage their bookings</p>
                        <a href="<?php echo $is_authenticated ? '../../auth/php/owner_register.php' : '../../auth/php/login.php'; ?>" class="cta-button">Subscribe Now with GCash</a>
                    </div>
                </div>
            </div>
        </main>
        <?php include '../../shared/components/footer.php'; ?>
    </div>

    <script>
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('i');
            
            if (answer.classList.contains('show')) {
                answer.classList.remove('show');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            } else {
                answer.classList.add('show');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
        }

        // Add smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>