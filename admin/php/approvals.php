<?php
require_once __DIR__ . '/config/session.php';
requireLogin();
require_once __DIR__ . '/models/Registration.php';
require_once __DIR__ . '/config/database.php';
// Use shared mail helpers (PHPMailer)
require_once __DIR__ . '/../../shared/config/mail_config.php';

$registration = new Registration();
$db = Database::getInstance()->getConnection();

$success = '';
$error = '';

// AJAX actions for send link and subscription payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf_token)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    if ($action === 'send_upload_link') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if (!$registrationId) { echo json_encode(['success'=>false,'message'=>'Missing registration_id']); exit; }
        try {
            // Lookup owner from registration (actual columns)
            $stmt = $db->prepare("SELECT business_name, owner_email, owner_name FROM studio_registrations WHERE registration_id = ?");
            $stmt->execute([$registrationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Registration not found']); exit; }
            $toEmail = trim($row['owner_email'] ?? '');
            $toName  = trim($row['owner_name'] ?? '');

            // Create token (match schema: registration_id, token, expires_at)
            $token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
            $stmt->execute([$registrationId, $token]);

            // Notify owner
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // Point to the document upload page
            $url    = $scheme . '://' . $host . '/auth/php/upload-documents.php?token=' . urlencode($token);
            $msg    = 'Please upload studio documents: ' . $url;
            // OwnerID may be unknown at registration stage
            $stmt = $db->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, IsRead, Created_At, For_User) VALUES (NULL, NULL, 'document_request', ?, ?, 0, NOW(), 'Owner')");
            $stmt->execute([$msg, $registrationId]);

            // Send email via PHPMailer
            $studioName = $row['business_name'] ?? 'Your Studio';
            $subject = 'Museek – Document Upload Link';
            $html = '<p>Hello ' . htmlspecialchars($toName ?: 'Studio Owner') . ',</p>' .
                    '<p>Thank you for registering "' . htmlspecialchars($studioName) . '".</p>' .
                    '<p>Please upload your verification documents using the secure link below:</p>' .
                    '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:10px 16px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Open Upload Page</a></p>' .
                    '<p>This link will expire in 7 days.</p>' .
                    '<p>Regards,<br>Museek Admin</p>';
            $alt = "Please complete your registration here: $url\nThis link will expire in 7 days.";
            $emailSent = ($toEmail !== '') ? sendTransactionalEmail($toEmail, $toName ?: $toEmail, $subject, $html, $alt) : false;

            echo json_encode(['success' => true, 'url' => $url]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Server error']);
        }
        exit;
    }

    if ($action === 'create_sub_payment') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        if (!$registrationId) { echo json_encode(['success'=>false,'message'=>'Missing registration_id']); exit; }
        try {
            // Get registration details with subscription plan pricing
            // Use plan_id from studio_registrations
            $stmt = $db->prepare("SELECT sr.business_name, sr.owner_email, sr.owner_name, 
                                         COALESCE(sr.subscription_duration, 'monthly') as subscription_duration,
                                         sr.plan_id,
                                         sp.plan_name, sp.monthly_price, sp.yearly_price
                                  FROM studio_registrations sr
                                  JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
                                  WHERE sr.registration_id = ?");
            $stmt->execute([$registrationId]);
            $regRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$regRow) { echo json_encode(['success'=>false,'message'=>'Registration not found']); exit; }
            
            // Determine the subscription amount based on duration (default to monthly if not set)
            $subscriptionDuration = $regRow['subscription_duration'] ?? 'monthly';
            $subscriptionAmount = ($subscriptionDuration === 'yearly') 
                ? $regRow['yearly_price'] 
                : $regRow['monthly_price'];
            $planName = $regRow['plan_name'] ?? 'Subscription';
            $duration = ucfirst($subscriptionDuration);

            // Create pending payment with plan-based pricing
            $stmt = $db->prepare("INSERT INTO payment (PaymentGroupID, BookingID, OwnerID, Init_Amount, Amount, Pay_Date, GCashID, CashID, Pay_Stats)
                                  VALUES (CONCAT('SUB_', ?), NULL, NULL, 0.00, ?, NOW(), NULL, NULL, 'Pending')");
            $stmt->execute([$registrationId, $subscriptionAmount]);

            // Ensure an active upload/payment token exists; create if missing
            $tokenStmt = $db->prepare("SELECT token FROM document_upload_tokens WHERE registration_id = ? AND expires_at > NOW() ORDER BY token_id DESC LIMIT 1");
            $tokenStmt->execute([$registrationId]);
            $token = (string)($tokenStmt->fetchColumn() ?: '');
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                   ->execute([$registrationId, $token]);
            }

            // Send email request with plan-based pricing
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url    = $scheme . '://' . $host . '/auth/php/subscription-payment.php?token=' . urlencode($token);
            $toEmail = trim($regRow['owner_email'] ?? '');
            $toName  = trim($regRow['owner_name'] ?? '');
            $studioName = $regRow['business_name'] ?? 'Your Studio';
            $formattedAmount = number_format($subscriptionAmount, 2);
            if ($toEmail !== '') {
                $subject = "Museek – Subscription Payment Request (₱{$formattedAmount})";
                $html = '<p>Hello ' . htmlspecialchars($toName ?: 'Studio Owner') . ',</p>' .
                        '<p>We have created a subscription payment request for your registration of "' . htmlspecialchars($studioName) . '".</p>' .
                        '<p><strong>Plan:</strong> ' . htmlspecialchars($planName) . ' (' . htmlspecialchars($duration) . ')</p>' .
                        '<p><strong>Amount:</strong> ₱' . htmlspecialchars($formattedAmount) . '</p>' .
                        '<p>Please open the page below to submit your GCash payment proof for the subscription:</p>' .
                        '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:10px 16px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Open Subscription Payment Page</a></p>' .
                        '<p>Thank you,<br>Museek Admin</p>';
                $alt = "A ₱{$formattedAmount} ({$planName} - {$duration}) subscription payment request has been created. Submit payment here: $url";
                sendTransactionalEmail($toEmail, $toName ?: $toEmail, $subject, $html, $alt);
            }
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            error_log("Create payment error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'confirm_sub_payment') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        $gcashRef = trim($_POST['gcash_ref'] ?? '');
        if (!$registrationId || $gcashRef === '') { echo json_encode(['success'=>false,'message'=>'Missing fields']); exit; }
        try {
            // Get subscription amount from studio_registrations
            $stmt = $db->prepare("SELECT COALESCE(sr.subscription_duration, 'monthly') as subscription_duration, 
                                         sp.monthly_price, sp.yearly_price
                                  FROM studio_registrations sr
                                  JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
                                  WHERE sr.registration_id = ?");
            $stmt->execute([$registrationId]);
            $regRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $subscriptionAmount = 500.00; // Default fallback
            if ($regRow) {
                $subscriptionAmount = ($regRow['subscription_duration'] === 'yearly') 
                    ? $regRow['yearly_price'] 
                    : $regRow['monthly_price'];
            }
            
            // OwnerID may be unknown; update by PaymentGroupID only
            
            // Mark payment completed; if not exists, insert then update
            $stmt = $db->prepare("UPDATE payment SET Pay_Stats='Completed', Pay_Date=NOW() WHERE PaymentGroupID=CONCAT('SUB_', ?)");
            $stmt->execute([$registrationId]);
            if ($stmt->rowCount() === 0) {
                $db->prepare("INSERT INTO payment (PaymentGroupID, BookingID, OwnerID, Init_Amount, Amount, Pay_Date, GCashID, CashID, Pay_Stats)
                               VALUES (CONCAT('SUB_', ?), NULL, NULL, 0.00, ?, NOW(), NULL, NULL, 'Completed')")
                  ->execute([$registrationId, $subscriptionAmount]);
            }

            // Notify owner
            $formattedAmount = number_format($subscriptionAmount, 2);
            $db->prepare("INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, IsRead, Created_At, For_User)
                          VALUES (NULL, NULL, 'payment_confirmation', CONCAT('Subscription paid (₱', ?, '). Ref: ', ?), ?, 0, NOW(), 'Owner')")
              ->execute([$formattedAmount, $gcashRef, $registrationId]);

            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Server error']);
        }
        exit;
    }
    
    // Handle send_reminder action (Quick Action Buttons)
    if ($action === 'send_reminder') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        
        if (!$registrationId || !$type) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        
        try {
            // Fetch registration details with subscription plan info
            $stmt = $db->prepare("SELECT sr.business_name, sr.owner_email, sr.owner_name, sr.owner_phone, sr.registration_id, 
                                         COALESCE(sr.subscription_duration, 'monthly') as subscription_duration,
                                         sp.plan_name, sp.monthly_price, sp.yearly_price,
                                         rp.payment_status as actual_payment_status,
                                         0 as service_count
                                  FROM studio_registrations sr
                                  JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
                                  LEFT JOIN registration_payments rp ON sr.registration_id = rp.registration_id
                                  WHERE sr.registration_id = ?");
            $stmt->execute([$registrationId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reg) {
                echo json_encode(['success' => false, 'message' => 'Registration not found']);
                exit;
            }
            
            // Check if documents exist
            $docStmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE registration_id = ?");
            $docStmt->execute([$registrationId]);
            $docCount = (int)$docStmt->fetchColumn();
            
            $toEmail = trim($reg['owner_email'] ?? '');
            $toName = trim($reg['owner_name'] ?? '');
            $studioName = $reg['business_name'] ?? 'your studio';
            $serviceCount = (int)($reg['service_count'] ?? 0);
            
            // Calculate subscription amount based on plan and duration
            $subscriptionAmount = ($reg['subscription_duration'] === 'yearly') 
                ? $reg['yearly_price'] 
                : $reg['monthly_price'];
            $formattedAmount = number_format($subscriptionAmount, 2);
            $planName = $reg['plan_name'] ?? 'Subscription';
            $duration = ucfirst($reg['subscription_duration'] ?? 'monthly');
            
            // Generate necessary links based on type
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uploadLink = '';
            $paymentLink = '';
            
            // Generate document upload link for missing_docs type
            if ($type === 'missing_docs') {
                // Check if a valid token exists, or create a new one
                $tokenStmt = $db->prepare("SELECT token FROM document_upload_tokens WHERE registration_id = ? AND expires_at > NOW() ORDER BY token_id DESC LIMIT 1");
                $tokenStmt->execute([$registrationId]);
                $token = (string)($tokenStmt->fetchColumn() ?: '');
                if ($token === '') {
                    $token = bin2hex(random_bytes(32));
                    $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                       ->execute([$registrationId, $token]);
                }
                $uploadLink = $scheme . '://' . $host . '/auth/php/upload-documents.php?token=' . urlencode($token);
            }
            
            // Generate payment link for payment_pending type
            if ($type === 'payment_pending') {
                // Check if a valid token exists, or create a new one
                $tokenStmt = $db->prepare("SELECT token FROM document_upload_tokens WHERE registration_id = ? AND expires_at > NOW() ORDER BY token_id DESC LIMIT 1");
                $tokenStmt->execute([$registrationId]);
                $token = (string)($tokenStmt->fetchColumn() ?: '');
                if ($token === '') {
                    $token = bin2hex(random_bytes(32));
                    $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                       ->execute([$registrationId, $token]);
                }
                $paymentLink = $scheme . '://' . $host . '/auth/php/subscription-payment.php?token=' . urlencode($token);
            }
            
            // Define message templates (server-side)
            $templates = [
                'missing_docs' => [
                    'subject' => 'Action Required: Upload Verification Documents',
                    'body' => "Dear $toName,\n\n" .
                             "We are reviewing your studio registration for \"$studioName\".\n\n" .
                             "We noticed that some required verification documents are missing or incomplete. " .
                             "To proceed with your registration, please upload the following documents:\n\n" .
                             "• Business Permit\n" .
                             "• DTI Registration\n" .
                             "• BIR Certificate\n" .
                             "• Mayor's Permit\n" .
                             "• Valid ID\n\n" .
                             "Please submit these documents within 7 days to avoid delays in your registration approval.\n\n" .
                             "[UPLOAD_LINK]\n\n" .
                             "If you have any questions, please don't hesitate to contact us.\n\n" .
                             "Best regards,\nMuseek Admin Team"
                ],
                'incomplete_info' => [
                    'subject' => 'Complete Your Studio Setup - Information Required',
                    'body' => "Dear $toName,\n\n" .
                             "Your studio registration for \"$studioName\" is under review.\n\n" .
                             "We noticed that your studio profile is missing some important information. " .
                             "Please complete the following:\n\n" .
                             ($serviceCount === 0 ? "• Add at least one service to your studio\n" : "") .
                             (empty($reg['owner_phone']) ? "• Add your contact phone number\n" : "") .
                             ($docCount === 0 ? "• Upload verification documents\n" : "") .
                             "\nThis information is required for approval. Please complete this within 5 days.\n\n" .
                             "Best regards,\nMuseek Admin Team"
                ],
                'deadline_warning' => [
                    'subject' => 'Urgent: Complete Your Studio Registration',
                    'body' => "Dear $toName,\n\n" .
                             "This is a reminder that your studio registration deadline is approaching.\n\n" .
                             "You have LIMITED TIME to complete all requirements for \"$studioName\".\n\n" .
                             "Pending items:\n" .
                             ($serviceCount === 0 ? "• Add services\n" : "") .
                             ($docCount === 0 ? "• Upload documents\n" : "") .
                            (empty($reg['actual_payment_status']) || $reg['actual_payment_status'] !== 'completed' ? "• Complete payment (₱$formattedAmount)\n" : "") .
                             "\nPlease complete these requirements immediately to avoid registration cancellation.\n\n" .
                             "Best regards,\nMuseek Admin Team"
                ],
                'payment_pending' => [
                    'subject' => 'Payment Required: Complete Your Subscription',
                    'body' => "Dear $toName,\n\n" .
                             "Your studio registration is almost complete!\n\n" .
                             "To activate your studio on the Museek platform, please complete your subscription payment.\n\n" .
                             "Plan: $planName ($duration)\n" .
                             "Amount: ₱$formattedAmount\n\n" .
                             "[PAYMENT_LINK]\n\n" .
                             "Once payment is confirmed, we will proceed with the final review of your registration.\n\n" .
                             "If you have any questions or need assistance, please contact our support team.\n\n" .
                             "Best regards,\nMuseek Admin Team"
                ]
            ];
            
            if (!isset($templates[$type])) {
                echo json_encode(['success' => false, 'message' => 'Invalid template type']);
                exit;
            }
            
            $template = $templates[$type];
            $subject = $template['subject'];
            $body = $template['body'];
            
            // Replace placeholders with actual links for plain text body
            $plainBody = $body;
            if ($uploadLink) {
                $plainBody = str_replace('[UPLOAD_LINK]', "Click the link below to upload your documents:\n\n" . $uploadLink, $plainBody);
            }
            if ($paymentLink) {
                $plainBody = str_replace('[PAYMENT_LINK]', "Click the link below to complete your payment:\n\n" . $paymentLink, $plainBody);
            }
            
            // Replace placeholders with HTML buttons for HTML body
            $htmlBody = $body;
            if ($uploadLink) {
                $uploadButton = '<div style="margin: 20px 0; text-align: center;"><a href="' . htmlspecialchars($uploadLink) . '" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">📄 Upload Documents</a></div>';
                $htmlBody = str_replace('[UPLOAD_LINK]', $uploadButton, $htmlBody);
            }
            if ($paymentLink) {
                $paymentButton = '<div style="margin: 20px 0; text-align: center;"><a href="' . htmlspecialchars($paymentLink) . '" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">💳 Complete Payment (₱' . htmlspecialchars($formattedAmount) . ')</a></div>';
                $htmlBody = str_replace('[PAYMENT_LINK]', $paymentButton, $htmlBody);
            }
            
            // Convert to HTML (preserving line breaks and escaping)
            $htmlBody = nl2br(htmlspecialchars($htmlBody, ENT_NOQUOTES));
            // Unescape the HTML buttons/links
            $htmlBody = str_replace('&lt;div', '<div', $htmlBody);
            $htmlBody = str_replace('&lt;/div&gt;', '</div>', $htmlBody);
            $htmlBody = str_replace('&lt;a ', '<a ', $htmlBody);
            $htmlBody = str_replace('&lt;/a&gt;', '</a>', $htmlBody);
            $htmlBody = str_replace('&quot;', '"', $htmlBody);
            
            // Send email
            if ($toEmail !== '') {
                sendTransactionalEmail($toEmail, $toName ?: $toEmail, $subject, $htmlBody, $plainBody);
                
                // Log the communication (optional - could be added to a communications table)
                // For now, we'll just send the email
            }
            
            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        } catch (Exception $e) {
            error_log("Send reminder error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
        exit;
    }
    
    // Handle generate_upload_link action (for template preview)
    if ($action === 'generate_upload_link') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        
        if (!$registrationId) {
            echo json_encode(['success' => false, 'message' => 'Missing registration_id']);
            exit;
        }
        
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            
            // Check if a valid token exists, or create a new one
            $tokenStmt = $db->prepare("SELECT token FROM document_upload_tokens WHERE registration_id = ? AND expires_at > NOW() ORDER BY token_id DESC LIMIT 1");
            $tokenStmt->execute([$registrationId]);
            $token = (string)($tokenStmt->fetchColumn() ?: '');
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                   ->execute([$registrationId, $token]);
            }
            
            $uploadLink = $scheme . '://' . $host . '/auth/php/upload-documents.php?token=' . urlencode($token);
            echo json_encode(['success' => true, 'link' => $uploadLink]);
        } catch (Exception $e) {
            error_log("Generate upload link error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to generate link']);
        }
        exit;
    }
    
    // Handle generate_payment_link action (for template preview)
    if ($action === 'generate_payment_link') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        
        if (!$registrationId) {
            echo json_encode(['success' => false, 'message' => 'Missing registration_id']);
            exit;
        }
        
        try {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            
            // Check if a valid token exists, or create a new one
            $tokenStmt = $db->prepare("SELECT token FROM document_upload_tokens WHERE registration_id = ? AND expires_at > NOW() ORDER BY token_id DESC LIMIT 1");
            $tokenStmt->execute([$registrationId]);
            $token = (string)($tokenStmt->fetchColumn() ?: '');
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                $db->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
                   ->execute([$registrationId, $token]);
            }
            
            $paymentLink = $scheme . '://' . $host . '/auth/php/subscription-payment.php?token=' . urlencode($token);
            echo json_encode(['success' => true, 'link' => $paymentLink]);
        } catch (Exception $e) {
            error_log("Generate payment link error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to generate link']);
        }
        exit;
    }
    
    // Handle send_custom_message action (Custom Message Form)
    if ($action === 'send_custom_message') {
        header('Content-Type: application/json');
        $registrationId = (int)($_POST['registration_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        if (!$registrationId || !$subject || !$message) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        
        try {
            // Fetch registration details
            $stmt = $db->prepare("SELECT business_name, owner_email, owner_name FROM studio_registrations WHERE registration_id = ?");
            $stmt->execute([$registrationId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reg) {
                echo json_encode(['success' => false, 'message' => 'Registration not found']);
                exit;
            }
            
            $toEmail = trim($reg['owner_email'] ?? '');
            $toName = trim($reg['owner_name'] ?? '');
            
            // Process message to convert URLs to HTML links
            $plainBody = $message;
            $htmlBody = $message;
            
            // Convert plain URLs to clickable links in HTML version
            $urlPattern = '/(https?:\/\/[^\s]+)/';
            $htmlBody = preg_replace_callback($urlPattern, function($matches) {
                $url = $matches[1];
                // Style the link as a button if it's an upload or payment link
                if (strpos($url, 'upload-documents.php') !== false) {
                    return '<div style="margin: 20px 0; text-align: center;"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">📄 Upload Documents</a></div>';
                } elseif (strpos($url, 'subscription-payment.php') !== false) {
                    return '<div style="margin: 20px 0; text-align: center;"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">💳 Complete Payment</a></div>';
                } else {
                    return '<a href="' . htmlspecialchars($url) . '" style="color:#dc2626;">' . htmlspecialchars($url) . '</a>';
                }
            }, $htmlBody);
            
            // Convert to HTML preserving line breaks
            $htmlBody = nl2br(htmlspecialchars($htmlBody, ENT_NOQUOTES));
            // Unescape the HTML buttons/links
            $htmlBody = str_replace('&lt;div', '<div', $htmlBody);
            $htmlBody = str_replace('&lt;/div&gt;', '</div>', $htmlBody);
            $htmlBody = str_replace('&lt;a ', '<a ', $htmlBody);
            $htmlBody = str_replace('&lt;/a&gt;', '</a>', $htmlBody);
            $htmlBody = str_replace('&quot;', '"', $htmlBody);
            
            // Send email
            if ($toEmail !== '') {
                sendTransactionalEmail($toEmail, $toName ?: $toEmail, $subject, $htmlBody, $plainBody);
                
                // Log the communication (optional)
                // For now, we'll just send the email
            }
            
            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        } catch (Exception $e) {
            error_log("Send custom message error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        }
        exit;
    }
}

// Handle bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrf_token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        $adminId = $_SESSION['admin_id'];
        
        if (empty($selected_ids)) {
            $error = 'Please select at least one registration.';
        } elseif ($bulk_action === 'approve') {
            $approved_count = 0;
            foreach ($selected_ids as $id) {
                if ($registration->approve($id, $adminId, 'Bulk approved')) {
                    $approved_count++;
                }
            }
            $success = "Successfully approved {$approved_count} studio(s).";
        } elseif ($bulk_action === 'reject') {
            $reason = $_POST['bulk_reason'] ?? '';
            if (empty($reason)) {
                $error = 'Please provide a reason for bulk rejection.';
            } else {
                $rejected_count = 0;
                foreach ($selected_ids as $id) {
                    if ($registration->reject($id, $adminId, $reason)) {
                        $rejected_count++;
                    }
                }
                $success = "Successfully rejected {$rejected_count} studio(s).";
            }
        }
    }
}

$filters = [
    'status' => $_GET['status'] ?? 'pending',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'business_name' => $_GET['search'] ?? ''
];

$limit = (int)($_GET['limit'] ?? 10);
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$registrations = $registration->getAll($filters, $limit, $offset);
$countFilters = $filters; unset($countFilters['business_name']);
$totalRows = $registration->getAllCount($countFilters);
$totalPages = max(1, ceil($totalRows / $limit));

$pageTitle = 'Approvals Queue';
include __DIR__ . '/views/components/header.php';
?>

<div class="page-header">
    <h1>Approvals Queue</h1>
    <p>Review and approve studio registrations</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<!-- FILTERS -->
<div class="filters">
    <form method="GET" style="display:flex;gap:16px;flex:1;flex-wrap:wrap;align-items:end;">
        <div class="filter-group">
            <label class="filter-label">Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending (includes payment submitted)</option>
                <option value="payment_submitted" <?= $filters['status'] === 'payment_submitted' ? 'selected' : '' ?>>Payment Submitted Only</option>
                <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="" <?= $filters['status'] === '' ? 'selected' : '' ?>>All</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Search</label>
            <input type="text" name="search" class="filter-input" placeholder="Business name..." value="<?= htmlspecialchars($filters['business_name']) ?>">
        </div>
        <div class="filter-group">
            <label class="filter-label">Date From</label>
            <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div class="filter-group">
            <label class="filter-label">Date To</label>
            <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <div class="filter-group">
            <label class="filter-label">Rows</label>
            <select name="limit" class="filter-select" onchange="this.form.submit()">
                <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                <option value="15" <?= $limit == 15 ? 'selected' : '' ?>>15</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="../php/approvals.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- BULK ACTIONS BAR -->
<?php if (!empty($registrations) && $filters['status'] === 'pending'): ?>
<div class="card" style="margin-bottom:15px;padding:15px;">
    <form method="POST" id="bulkForm" onsubmit="return handleBulkAction(event);">
        <?= csrfField() ?>
        <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
            <span style="font-weight:500;">Bulk Actions:</span>
            <button type="button" onclick="selectAll()" class="btn btn-sm btn-secondary">Select All</button>
            <button type="button" onclick="deselectAll()" class="btn btn-sm btn-secondary">Deselect All</button>
            <span id="selectedCount" style="color:#666;">0 selected</span>
            <div style="flex:1;"></div>
            <input type="hidden" name="bulk_action" id="bulkAction">
            <button type="button" onclick="bulkApprove()" class="btn btn-sm btn-success">Approve Selected</button>
            <button type="button" onclick="showBulkRejectModal()" class="btn btn-sm btn-danger">Reject Selected</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- TABLE CARD -->
<div class="card">
    <?php if (empty($registrations)): ?>
        <div class="empty-state">
            <h3>No registrations match your filters</h3>
            <p>Try adjusting your filter criteria</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <?php if ($filters['status'] === 'pending'): ?>
                            <th style="width:40px;"><input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this)"></th>
                        <?php endif; ?>
                        <th>Studio</th><th>Owner</th><th>Subscription Plan</th><th>Submitted</th><th>Workflow Stage</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <?php if ($filters['status'] === 'pending'): ?>
                            <td><input type="checkbox" class="reg-checkbox" value="<?= $reg['registration_id'] ?>" onchange="updateSelectedCount()"></td>
                        <?php endif; ?>
                        <td><strong><?= htmlspecialchars($reg['business_name']) ?></strong><br><span class="text-muted text-sm"><?= htmlspecialchars($reg['business_address']) ?></span></td>
                        <td><?= htmlspecialchars($reg['owner_name']) ?><br><span class="text-muted text-sm"><?= htmlspecialchars($reg['owner_email']) ?></span></td>
                        <td>
                            <?php
                            $planName = $reg['plan_name'] ?? 'Unknown';
                            $isFree = (stripos($planName, 'free') !== false);
                            $badgeClass = $isFree ? 'badge-success' : 'badge-primary';
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($planName) ?></span>
                        </td>
                        <td class="text-sm"><?= date('M d, Y H:i', strtotime($reg['submitted_at'])) ?></td>
                        <td>
                            <?php
                            // Determine workflow stage based on payment and document status
                            $docCount = $reg['document_count'] ?? 0;
                            // Use actual_payment_status from registration_payments table
                            $paymentStatus = $reg['actual_payment_status'] ?? 'pending';
                            $regStatus = $reg['registration_status'] ?? 'pending';
                            
                            if ($regStatus === 'approved') {
                                $stage = ['label' => 'Completed', 'class' => 'badge-success'];
                            } elseif ($regStatus === 'rejected') {
                                $stage = ['label' => 'Rejected', 'class' => 'badge-danger'];
                            } elseif ($paymentStatus === 'completed' && $docCount > 0) {
                                $stage = ['label' => 'Ready for Review', 'class' => 'badge-info'];
                            } elseif ($paymentStatus === 'completed') {
                                $stage = ['label' => 'Payment Verified', 'class' => 'badge-success'];
                            } elseif ($regStatus === 'payment_submitted') {
                                $stage = ['label' => 'Payment Awaiting Verification', 'class' => 'badge-warning'];
                            } elseif ($docCount > 0) {
                                $stage = ['label' => 'Docs Uploaded', 'class' => 'badge-primary'];
                            } else {
                                $stage = ['label' => 'New Registration', 'class' => 'badge-secondary'];
                            }
                            echo '<span class="badge ' . $stage['class'] . '">' . $stage['label'] . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                            $statusBadges = ['approved'=>'badge-success','pending'=>'badge-warning','rejected'=>'badge-danger','requires_info'=>'badge-info'];
                            echo '<span class="badge ' . ($statusBadges[$reg['registration_status']] ?? 'badge-secondary') . '">' . ucfirst(str_replace('_', ' ', $reg['registration_status'])) . '</span>';
                            ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
<a href="../php/approval-detail.php?id=<?= $reg['registration_id'] ?>" class="btn btn-sm btn-primary">View</a>
<?php if ($reg['registration_status'] === 'pending'): ?>
    <button onclick="sendUploadLink(<?= (int)$reg['registration_id'] ?>, '<?= htmlspecialchars($reg['business_name'], ENT_QUOTES) ?>')" 
            class="btn btn-sm btn-success" title="Send document upload link to owner">
        📧 Send Link
    </button>
    <?php
    // Only show payment buttons for paid plans
    $regPlanName = $reg['plan_name'] ?? 'Unknown';
    $regIsFree = (stripos($regPlanName, 'free') !== false);
    if (!$regIsFree):
    ?>
    <button onclick="createSubPayment(<?= (int)$reg['registration_id'] ?>)" class="btn btn-sm btn-warning" title="Create subscription payment request based on selected plan">💳 Request Payment</button>
    <button onclick="confirmSubPayment(<?= (int)$reg['registration_id'] ?>)" class="btn btn-sm btn-info" title="Mark subscription as paid via GCash">Mark Paid</button>
    <?php endif; ?>
<?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalRows > $limit): ?>
        <div class="pagination">
            <?php
            $prev = $page - 1; $next = $page + 1;
            $baseUrl = http_build_query(array_merge($_GET, ['page' => ''])) . '&page=';
            $baseUrl = preg_replace('/&page=\d+/', '', $baseUrl);
            $baseUrl = '../php/approvals.php?' . $baseUrl;
            ?>
            <a href="<?= $baseUrl . max(1, $prev) ?>" class="<?= $page == 1 ? 'disabled' : '' ?>">Previous</a>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                    <a href="<?= $baseUrl . $i ?>" class="<?= $i == $page ? 'current' : '' ?>"><?= $i ?></a>
                <?php elseif ($i == $page - 2 || $i == $page + 2): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endfor; ?>
            <a href="<?= $baseUrl . min($totalPages, $next) ?>" class="<?= $page == $totalPages ? 'disabled' : '' ?>">Next</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- SEND UPLOAD LINK MODAL -->
<div id="sendLinkModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;max-width:500px;width:90%;">
        <h2 style="margin-top:0;">Send Document Upload Link</h2>
        <p style="color:#666;">Send a secure upload link to: <strong id="linkStudioName"></strong></p>
        <p style="color:#666;font-size:14px;">The owner will receive a link to upload required documents and submit payment for registration.</p>
        
        <div style="background:#f8f9fa;padding:15px;border-radius:5px;margin:15px 0;">
            <p style="margin:0;font-size:13px;"><strong>What happens next?</strong></p>
            <ol style="margin:10px 0 0 0;padding-left:20px;font-size:13px;">
                <li>Owner receives email with secure upload link</li>
                <li>Owner uploads required documents</li>

                <li>You review and approve/reject</li>
            </ol>
        </div>
        
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="closeSendLinkModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="confirmSendLink()" class="btn btn-success">Send Upload Link</button>
        </div>
    </div>
</div>

<!-- BULK REJECT MODAL -->
<div id="bulkRejectModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;max-width:500px;width:90%;">
        <h2 style="margin-top:0;">Bulk Reject Studios</h2>
        <p style="color:#666;">You are about to reject <span id="rejectCount">0</span> studio(s).</p>
        
        <div class="form-group">
            <label class="form-label">Reason for Rejection *</label>
            <textarea id="bulkRejectReason" class="form-textarea" rows="4" placeholder="Enter reason for rejecting these studios..." required></textarea>
        </div>
        
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
            <button type="button" onclick="closeBulkRejectModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="submitBulkReject()" class="btn btn-danger">Reject Selected</button>
        </div>
    </div>
</div>

<script>
function selectAll() {
    document.querySelectorAll('.reg-checkbox').forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.reg-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
}

function toggleAll(checkbox) {
    document.querySelectorAll('.reg-checkbox').forEach(cb => cb.checked = checkbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.reg-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = checked + ' selected';
}

function getSelectedIds() {
    const ids = [];
    document.querySelectorAll('.reg-checkbox:checked').forEach(cb => ids.push(cb.value));
    return ids;
}

function bulkApprove() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one studio to approve.');
        return;
    }
    
    if (!confirm(`Are you sure you want to approve ${ids.length} studio(s)? Make sure all studios have valid coordinates set.`)) {
        return;
    }
    
    document.getElementById('bulkAction').value = 'approve';
    
    // Add hidden inputs for selected IDs
    const form = document.getElementById('bulkForm');
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    form.submit();
}

function showBulkRejectModal() {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('Please select at least one studio to reject.');
        return;
    }
    
    document.getElementById('rejectCount').textContent = ids.length;
    document.getElementById('bulkRejectReason').value = '';
    document.getElementById('bulkRejectModal').style.display = 'block';
}

function closeBulkRejectModal() {
    document.getElementById('bulkRejectModal').style.display = 'none';
}

function submitBulkReject() {
    const reason = document.getElementById('bulkRejectReason').value.trim();
    if (!reason) {
        alert('Please provide a reason for rejection.');
        return;
    }
    
    if (!confirm('Are you sure you want to reject the selected studios?')) {
        return;
    }
    
    const ids = getSelectedIds();
    document.getElementById('bulkAction').value = 'reject';
    
    const form = document.getElementById('bulkForm');
    
    // Add reason
    const reasonInput = document.createElement('input');
    reasonInput.type = 'hidden';
    reasonInput.name = 'bulk_reason';
    reasonInput.value = reason;
    form.appendChild(reasonInput);
    
    // Add selected IDs
    ids.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    form.submit();
}

function handleBulkAction(event) {
    event.preventDefault();
    return false;
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBulkRejectModal();
        closeSendLinkModal();
    }
});

// Send upload link functionality
let currentRegistrationId = null;

function sendUploadLink(registrationId, studioName) {
    currentRegistrationId = registrationId;
    document.getElementById('linkStudioName').textContent = studioName;
    document.getElementById('sendLinkModal').style.display = 'block';
}

function closeSendLinkModal() {
    document.getElementById('sendLinkModal').style.display = 'none';
    currentRegistrationId = null;
}

function confirmSendLink() {
    if (!currentRegistrationId) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    
// Send AJAX request to backend
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=send_upload_link&registration_id=' + encodeURIComponent(currentRegistrationId) + '&csrf_token=<?= generateCSRFToken() ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Upload link sent successfully! The studio owner will receive an email with instructions.');
            window.location.reload();
        } else {
            alert('❌ Error: ' + data.message);
            btn.disabled = false;
            btn.textContent = 'Send Upload Link';
        }
    })
    .catch(error => {
        alert('❌ Network error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Send Upload Link';
    });
}

function createSubPayment(registrationId) {
    if (!confirm('Create a subscription payment request for this registration based on their selected plan?')) return;
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=create_sub_payment&registration_id=' + encodeURIComponent(registrationId) + '&csrf_token=<?= generateCSRFToken() ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { alert('✅ Payment request created successfully! Owner will receive email with payment instructions.'); window.location.reload(); } else { alert('❌ Error: ' + d.message); }
    })
    .catch(() => alert('Network error'));
}

function confirmSubPayment(registrationId) {
    const ref = prompt('Enter GCash reference number:');
    if (!ref) return;
    fetch('../php/approvals.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=confirm_sub_payment&registration_id=' + encodeURIComponent(registrationId) + '&gcash_ref=' + encodeURIComponent(ref) + '&csrf_token=<?= generateCSRFToken() ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { alert('Subscription marked as paid.'); window.location.reload(); } else { alert('Error: ' + d.message); }
    })
    .catch(() => alert('Network error'));
}
</script>

<?php include __DIR__ . '/views/components/footer.php'; ?>
