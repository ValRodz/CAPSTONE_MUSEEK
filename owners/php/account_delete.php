<?php
// No session needed - account has been deleted
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Deleted - Museek</title>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700,900" rel="stylesheet" type="text/css">
    <link href="../../shared/assets/fonts/font-awesome.min.css" rel="stylesheet" type="text/css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
            text-align: center;
        }
        
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 40px 30px;
        }
        
        .header .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .content h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .content p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #17a2b8;
            padding: 20px;
            margin: 30px 0;
            border-radius: 4px;
            text-align: left;
        }
        
        .info-box h3 {
            color: #0c5460;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #0c5460;
        }
        
        .info-box li {
            margin-bottom: 10px;
        }
        
        .button-group {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            color: #666;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 30px 20px;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">✓</div>
            <h1>Account Deleted</h1>
            <p>Your account has been permanently removed</p>
        </div>
        
        <div class="content">
            <h2>We're Sorry to See You Go</h2>
            
            <p>Your studio owner account and all associated data have been successfully deleted from the Museek platform.</p>
            
            <div class="info-box">
                <h3>📋 What Was Deleted:</h3>
                <ul>
                    <li>Your studio owner account and login credentials</li>
                    <li>All studios you owned and their details</li>
                    <li>Services, instructors, and schedules</li>
                    <li>Gallery photos and verification documents</li>
                    <li>Registration records and payment history</li>
                    <li>All active bookings (marked as cancelled)</li>
                </ul>
            </div>
            
            <p>A confirmation email has been sent to your email address.</p>
            
            <p><strong>Changed your mind?</strong> You can create a new account anytime, but your previous data cannot be recovered.</p>
            
            <div class="button-group">
                <a href="../../auth/php/owner_register.php" class="btn btn-primary">
                    Register New Account
                </a>
                <a href="../../index.php" class="btn btn-secondary">
                    Return to Homepage
                </a>
            </div>
        </div>
        
        <div class="footer">
            <p>Thank you for being part of the Museek community.</p>
            <p>If you have any questions or feedback, please contact our support team.</p>
        </div>
    </div>
</body>
</html>

