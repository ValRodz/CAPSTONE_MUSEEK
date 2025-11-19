<?php
// Include path configuration if not already included
if (!function_exists('getImagePath')) {
    include __DIR__ . '/../config/path_config.php';
}
?>
<footer class="site-footer">
    <div class="footer-container">
        <div class="footer-row">
            <div class="footer-col about-col">
                <img src="<?php echo getImagePath('images/logo4.png'); ?>" class="footer-logo" alt="MuSeek">
                <p class="footer-description">MuSeek connects musicians with recording studios for seamless booking experiences.</p>
            </div>
            
            <div class="footer-col links-col">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="<?php echo getBasePath(); ?>">Home</a></li>
                    <li><a href="<?php echo getBasePath('browse.php'); ?>">Browse Studios</a></li>
                    <li><a href="<?php echo getBasePath('aboutpage.php'); ?>">About Us</a></li>
                </ul>
            </div>
            
            <div class="footer-col contact-col">
                <h3>Contact Us</h3>
                <address>
                    <p><i class="fas fa-map-marker-alt"></i> Talisay City, Negros Occidental</p>
                    <p><i class="fas fa-phone"></i> <a href="tel:+639508199489">(+63) 950 819 9489</a></p>
                    <p><i class="fas fa-envelope"></i> <a href="mailto:kyzzer.jallorina@gmail.com">kyzzer.jallorina@gmail.com</a></p>
                </address>
            </div>
            
            <div class="footer-col social-col">
                <h3>Follow Us</h3>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                </div>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p class="copyright">© <?php echo date('Y'); ?> MuSeek. All rights reserved</p>
            <div class="footer-bottom-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<style>
    .site-footer {
        background-color: var(--background-dark, #0f0f0f);
        color: var(--text-secondary, #b3b3b3);
        padding: 60px 0 20px;
        font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .footer-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }
    
    .footer-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        margin-bottom: 40px;
    }
    
    .footer-col {
        flex: 1;
        min-width: 200px;
        margin-bottom: 30px;
        padding-right: 20px;
    }
    
    .footer-logo {
        width: 180px;
        margin-bottom: 15px;
    }
    
    .footer-description {
        font-size: 14px;
        line-height: 1.6;
        margin-bottom: 20px;
    }
    
    .footer-col h3 {
        color: var(--text-primary, #ffffff);
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        position: relative;
    }
    
    .footer-col h3:after {
        content: '';
        display: block;
        width: 40px;
        height: 3px;
        background-color: var(--primary-color, #e50914);
        margin-top: 10px;
    }
    
    .footer-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .footer-links li {
        margin-bottom: 10px;
    }
    
    .footer-links a, .contact-col a {
        color: var(--text-secondary, #b3b3b3);
        text-decoration: none;
        transition: color 0.3s ease;
    }
    
    .footer-links a:hover, .contact-col a:hover {
        color: var(--primary-color, #e50914);
    }
    
    .contact-col p {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .contact-col i {
        margin-right: 10px;
        color: var(--primary-color, #e50914);
    }
    
    .social-links {
        display: flex;
        gap: 15px;
    }
    
    .social-links a {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: rgba(255, 255, 255, 0.1);
        color: var(--text-primary, #ffffff);
        transition: all 0.3s ease;
    }
    
    .social-links a:hover {
        background-color: var(--primary-color, #e50914);
        transform: translateY(-3px);
    }
    
    .footer-bottom {
        padding-top: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
    }
    
    .copyright {
        font-size: 14px;
        margin: 0;
    }
    
    .footer-bottom-links {
        display: flex;
        gap: 20px;
    }
    
    .footer-bottom-links a {
        color: var(--text-secondary, #b3b3b3);
        text-decoration: none;
        font-size: 14px;
        transition: color 0.3s ease;
    }
    
    .footer-bottom-links a:hover {
        color: var(--primary-color, #e50914);
    }
    
    @media (max-width: 768px) {
        .footer-row {
            flex-direction: column;
        }
        
        .footer-col {
            width: 100%;
            padding-right: 0;
        }
        
        .footer-bottom {
            flex-direction: column;
            text-align: center;
        }
        
        .footer-bottom-links {
            margin-top: 15px;
            justify-content: center;
        }
    }
</style>