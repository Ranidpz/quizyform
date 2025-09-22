<?php
// Simple form handler for Vercel
header('Content-Type: text/html; charset=utf-8');

// Log the request for debugging
error_log("Form submission received: " . print_r($_POST, true));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);

// Check if we have POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
    
    // Extract form data
    $customerName = isset($_POST['customerName']) ? $_POST['customerName'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $package = isset($_POST['package']) ? $_POST['package'] : '';
    
    // Basic validation
    if (empty($customerName) || empty($email) || empty($phone)) {
        error_log("Missing required fields");
        // Redirect to error page or back to form
        header('Location: /error.html');
        exit();
    }
    
    // Resend API configuration
    $resend_api_key = 're_STacfGs3_DaWkfkqzEvQsu2VsSm2kygxV';
    $sender_email = 'Quizy Form <no-reply@playzones.app>';
    $admin_email = 'info@playzone.co.il';
    
    // Email content
    $admin_subject = 'בקשה חדשה למנוי אחסון - Quizy';
    $admin_body = "
    <div style='direction: rtl; font-family: Arial, sans-serif;'>
        <h2>התקבלה בקשה חדשה למנוי אחסון</h2>
        <p><strong>שם הלקוח:</strong> " . htmlspecialchars($customerName) . "</p>
        <p><strong>אימייל:</strong> " . htmlspecialchars($email) . "</p>
        <p><strong>טלפון:</strong> " . htmlspecialchars($phone) . "</p>
        <p><strong>חבילה:</strong> " . htmlspecialchars($package) . "</p>
    </div>
    ";
    
    $customer_subject = 'פרטי הזמנה - מנוי אחסון Quizy';
    $customer_body = "
    <div style='direction: rtl; font-family: Arial, sans-serif;'>
        <h2>שלום " . htmlspecialchars($customerName) . ",</h2>
        <p>תודה על ההרשמה לשירותי האחסון של קוויזי!</p>
        <p>פרטי ההזמנה שלך:</p>
        <ul>
            <li><strong>חבילה:</strong> " . htmlspecialchars($package) . "</li>
            <li><strong>אימייל:</strong> " . htmlspecialchars($email) . "</li>
        </ul>
        <p>נחזור אליך בהקדם עם פרטי התשלום.</p>
    </div>
    ";
    
    // Function to send email
    function sendResendEmail($api_key, $to, $subject, $html, $from) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, 'https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ]);
        
        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Email API response to $to: HTTP $httpCode - $response");
        
        return $httpCode === 200;
    }
    
    // Try to send emails
    $admin_sent = sendResendEmail($resend_api_key, $admin_email, $admin_subject, $admin_body, $sender_email);
    $customer_sent = sendResendEmail($resend_api_key, $email, $customer_subject, $customer_body, $sender_email);
    
    // Log results
    error_log("Admin email sent: " . ($admin_sent ? 'YES' : 'NO'));
    error_log("Customer email sent: " . ($customer_sent ? 'YES' : 'NO'));
    
    // Always redirect to thank you page (even if email fails)
    header('Location: /thank_you.html');
    exit();
    
} else {
    // Not a POST request or no data
    error_log("Invalid request: Method=" . $_SERVER['REQUEST_METHOD'] . ", POST data empty=" . (empty($_POST) ? 'YES' : 'NO'));
    header('Location: /');
    exit();
}
?>