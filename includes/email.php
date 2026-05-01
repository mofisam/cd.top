<?php
require_once '../config/smtp.php';

// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Adjust path based on your setup
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
}

/**
 * Send email using SMTP
 */
function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // Timeout settings
        $mail->Timeout = 30;
        
        // Enable debug logging (disable in production)
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        
        // Sender
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Recipient
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        if (!empty($altBody)) {
            $mail->AltBody = $altBody;
        } else {
            $mail->AltBody = strip_tags($body);
        }
        
        // Attachments
        foreach ($attachments as $attachment) {
            if (file_exists($attachment)) {
                $mail->addAttachment($attachment);
            }
        }
        
        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully'];
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}

/**
 * Send contact notification to admin
 */
function sendContactNotification($name, $email, $subject, $message) {
    $adminEmail = SMTP_ADMIN_EMAIL;
    $emailSubject = "New Contact Message: " . $subject;
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; }
            .header { background: linear-gradient(135deg, #1E3A8A, #10B981); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { padding: 30px; background: #f9f9f9; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .info-item { margin: 10px 0; padding: 8px; border-left: 3px solid #3B82F6; background: #f0f9ff; }
            .message-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border: 1px solid #e0e0e0; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; background: #f0f0f0; }
            .badge { display: inline-block; background: #10B981; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📧 New Contact Message</h1>
                <p>checkdomain.top</p>
            </div>
            <div class='content'>
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #1E3A8A;'>Sender Information</h3>
                    <div class='info-item'>
                        <strong>👤 Name:</strong> " . htmlspecialchars($name) . "
                    </div>
                    <div class='info-item'>
                        <strong>📧 Email:</strong> <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a>
                    </div>
                    <div class='info-item'>
                        <strong>📝 Subject:</strong> " . htmlspecialchars($subject) . "
                    </div>
                </div>
                
                <div class='message-box'>
                    <h3 style='margin-top: 0; color: #1E3A8A;'>💬 Message</h3>
                    <div style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>
                        " . nl2br(htmlspecialchars($message)) . "
                    </div>
                </div>
                
                <div style='margin-top: 20px; text-align: center;'>
                    <a href='mailto:" . htmlspecialchars($email) . "' style='background: #3B82F6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        📧 Reply to " . htmlspecialchars($name) . "
                    </a>
                </div>
            </div>
            <div class='footer'>
                <p>This message was sent from the contact form on checkdomain.top</p>
                <p>⏰ Sent at: " . date('Y-m-d H:i:s') . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "New contact message from $name\n\nEmail: $email\nSubject: $subject\n\nMessage:\n$message";
    
    return sendEmail($adminEmail, $emailSubject, $body, $altBody);
}

/**
 * Send auto-reply to user
 */
function sendAutoReply($name, $email, $subject) {
    $replySubject = "Thank you for contacting checkdomain.top";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; }
            .header { background: linear-gradient(135deg, #1E3A8A, #10B981); color: white; padding: 30px; text-align: center; }
            .content { padding: 30px; background: #f9f9f9; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; background: #f0f0f0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Thank You for Contacting Us!</h1>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($name) . ",</p>
                <p>Thank you for reaching out to <strong>checkdomain.top</strong>. We have received your message regarding:</p>
                <div style='background: #e8f4f8; padding: 10px; border-radius: 5px; margin: 15px 0;'>
                    <strong>Subject:</strong> " . htmlspecialchars($subject) . "
                </div>
                <p>Our support team will review your inquiry and get back to you within <strong>24 hours</strong> during business days.</p>
                <p>In the meantime, you can:</p>
                <ul>
                    <li>Check our <a href='https://checkdomain.top/faq'>FAQ section</a> for quick answers</li>
                    <li>Explore domain availability using our <a href='https://checkdomain.top'>domain checker</a></li>
                    <li>Pin domains you're interested in to get availability alerts</li>
                </ul>
                <p>If your matter is urgent, please reply to this email and we'll prioritize your request.</p>
                <p>Best regards,<br><strong>The checkdomain.top Team</strong></p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " checkdomain.top. All rights reserved.</p>
                <p>This is an automated confirmation - please do not reply directly to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "Dear $name,\n\nThank you for contacting checkdomain.top. We have received your message and will respond within 24 hours.\n\nBest regards,\nThe checkdomain.top Team";
    
    return sendEmail($email, $replySubject, $body, $altBody);
}

/**
 * Send domain availability notification
 */
function sendDomainNotification($email, $domain, $status) {
    $subject = "Domain Availability Alert: $domain";
    
    if ($status === 'available') {
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 500px; margin: 0 auto; padding: 20px; }
                .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='success'>
                    <h2>Good News! 🎉</h2>
                    <p>The domain <strong>$domain</strong> is now AVAILABLE!</p>
                    <p>Don't miss out - register it now before someone else does!</p>
                    <a href='https://checkdomain.top/domain/$domain' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Register Now</a>
                </div>
            </div>
        </body>
        </html>
        ";
    } else {
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 500px; margin: 0 auto; padding: 20px; }
                .info { background: #cce5ff; color: #004085; padding: 15px; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='info'>
                    <h2>Domain Status Update</h2>
                    <p>The domain <strong>$domain</strong> is still TAKEN.</p>
                    <p>We'll continue monitoring it and notify you immediately when it becomes available.</p>
                    <p>You can also search for similar domains on our website.</p>
                    <a href='https://checkdomain.top' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Search More Domains</a>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    $altBody = "Domain $domain is now $status!";
    return sendEmail($email, $subject, $body, $altBody);
}

/**
 * Send welcome email to new subscriber
 */
function sendWelcomeEmail($email, $name) {
    $subject = "Welcome to checkdomain.top!";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; }
            .header { background: linear-gradient(135deg, #1E3A8A, #10B981); color: white; padding: 30px; text-align: center; }
            .content { padding: 30px; }
            .feature { display: inline-block; width: 30%; margin: 10px; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Welcome to checkdomain.top!</h1>
                <p>Your domain search companion</p>
            </div>
            <div class='content'>
                <h2>Hello " . htmlspecialchars($name ?: 'there') . "! 👋</h2>
                <p>Thank you for subscribing to checkdomain.top. You're now part of our community!</p>
                <h3>What you can do with your account:</h3>
                <div style='text-align: center;'>
                    <div class='feature'>
                        <div style='font-size: 30px;'>🔍</div>
                        <p>Check millions of domains instantly</p>
                    </div>
                    <div class='feature'>
                        <div style='font-size: 30px;'>📌</div>
                        <p>Pin domains and get availability alerts</p>
                    </div>
                    <div class='feature'>
                        <div style='font-size: 30px;'>💡</div>
                        <p>Get smart domain suggestions</p>
                    </div>
                </div>
                <p>We'll send you updates about:</p>
                <ul>
                    <li>New domain features and improvements</li>
                    <li>Domain availability alerts for your pinned domains</li>
                    <li>Special offers and tips for finding the perfect domain</li>
                </ul>
                <p>Start exploring now: <a href='https://checkdomain.top'>checkdomain.top</a></p>
                <hr>
                <p style='font-size: 12px; color: #666;'>You can unsubscribe at any time by clicking the link in our emails.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "Welcome to checkdomain.top!\n\nThank you for subscribing. You can now check millions of domains for availability, pin domains, and get alerts when they become available.\n\nVisit us: https://checkdomain.top";
    
    return sendEmail($email, $subject, $body, $altBody);
}

/**
 * Test SMTP connection
 */
function testSMTPConnection() {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout    = 10;
        
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = 'error_log';
        
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress(SMTP_FROM_EMAIL);
        $mail->Subject = 'SMTP Test';
        $mail->Body    = 'This is a test email from checkdomain.top';
        
        $mail->send();
        return ['success' => true, 'message' => 'SMTP connection successful'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo];
    }
}
?>