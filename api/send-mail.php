<?php
/**
 * Vercel Serverless Function for Quizy Form
 * This handles form submissions and sends emails via Resend API
 */

// Allow CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Resend API configuration
$resend_api_key = 're_STacfGs3_DaWkfkqzEvQsu2VsSm2kygxV';
$sender_email = 'Quizy Form <no-reply@playzones.app>';
$admin_email = 'info@playzone.co.il';

// Get form data
$data = $_POST;

// Validate required fields
$required_fields = ['customerName', 'email', 'phone'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit();
    }
}

// Prepare email content
$customer_name = htmlspecialchars($data['customerName']);
$email = htmlspecialchars($data['email']);
$phone = htmlspecialchars($data['phone']);
$package = htmlspecialchars($data['package'] ?? 'לא נבחר');

// Admin email content
$admin_subject = 'בקשה חדשה למנוי אחסון - Quizy';
$admin_body = "
<div style='direction: rtl; font-family: Arial, sans-serif;'>
    <h2>התקבלה בקשה חדשה למנוי אחסון</h2>
    <p><strong>שם הלקוח:</strong> {$customer_name}</p>
    <p><strong>אימייל:</strong> {$email}</p>
    <p><strong>טלפון:</strong> {$phone}</p>
    <p><strong>חבילה:</strong> {$package}</p>
</div>
";

// Customer email content
$customer_subject = 'פרטי הזמנה - מנוי אחסון Quizy';
$customer_body = "
<div style='direction: rtl; font-family: Arial, sans-serif;'>
    <h2>שלום {$customer_name},</h2>
    <p>תודה על ההרשמה לשירותי האחסון של קוויזי!</p>
    <p>פרטי ההזמנה שלך:</p>
    <ul>
        <li><strong>חבילה:</strong> {$package}</li>
        <li><strong>אימייל:</strong> {$email}</li>
    </ul>
    <p>נחזור אליך בהקדם עם פרטי התשלום.</p>
</div>
";

// Send emails via Resend API
function sendEmail($api_key, $to, $subject, $html, $from) {
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
    
    return ['code' => $httpCode, 'response' => $response];
}

// Send admin email
$admin_result = sendEmail($resend_api_key, $admin_email, $admin_subject, $admin_body, $sender_email);

// Send customer email
$customer_result = sendEmail($resend_api_key, $email, $customer_subject, $customer_body, $sender_email);

// Check results and respond
if ($admin_result['code'] === 200 && $customer_result['code'] === 200) {
    // Success - redirect to thank you page
    header('Location: /thank_you.html');
    exit();
} else {
    // Error
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send emails']);
    exit();
}
?>