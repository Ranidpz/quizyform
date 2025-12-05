<?php
/**
 * Quizy Form - קובץ שליחת מיילים
 *
 * קובץ זה מטפל בשליחת מיילים מטופס ההרשמה לשירותי אחסון של קוויזי
 * באמצעות Resend API
 */

// הגדרות בסיסיות
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

// טעינת משתני סביבה מקובץ .env
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!getenv($name)) {
            putenv("$name=$value");
        }
    }
    return true;
}
loadEnv(__DIR__ . '/.env');

// מפתח ה-API של Resend - נטען מקובץ env מוגן
$resend_api_key = getenv('RESEND_API_KEY');
if (!$resend_api_key) {
    error_log('RESEND_API_KEY not found in environment');
    header("Location: error.html?type=config_error");
    exit;
}

// טוקן סודי לאישור הזמנות
$approve_secret_token = getenv('APPROVE_SECRET_TOKEN');

// הגדרת כתובות מייל
// חשוב: השתמש בדומיין המאומת playzone.co.il לשליחת מיילים
// הלקוחות צריכים לפנות ל-info@playzone.co.il ולא להשיב למייל זה
$sender_email = 'Quizy Form <no-reply@playzone.co.il>';
$admin_email = getenv('ADMIN_EMAIL') ?: 'info@playzone.co.il';

// קביעת נושא המייל בהתאם לסוג החבילה
$is_software_package = (isset($_POST['package']) && $_POST['package'] === 'starter_package');
if ($is_software_package) {
    $admin_subject = 'בקשה חדשה לרכישת חבילת תוכנה - Quizy';
    $customer_subject = 'פרטי רכישה - חבילת תוכנה Quizy';
} else {
    $admin_subject = 'בקשה חדשה למנוי אחסנת קבצים - Quizy';
    $customer_subject = 'פרטי הזמנה - מנוי אחסון קבצים Quizy';
}

// הגדרת קובץ לוג
$log_file = __DIR__ . '/form_submissions.log';

// רישום לוג של הבקשה
$log_data = date('Y-m-d H:i:s') . ' - התקבלה בקשה חדשה: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($log_file, $log_data, FILE_APPEND);

// רשימת דפי הפניה מורשים (הגנה מפני Open Redirect)
$allowed_redirects = [
    'thank_you.html',
    'thank_you_software.html',
    'error.html',
    '/thank_you.html',
    '/thank_you_software.html',
    '/error.html'
];

// פונקציה לוולידציית הפניה בטוחה
function getSafeRedirect($requested_redirect, $allowed_list, $default = 'thank_you.html') {
    if (empty($requested_redirect)) {
        return $default;
    }
    // בדיקה שההפניה ברשימה המורשית
    if (in_array($requested_redirect, $allowed_list)) {
        return $requested_redirect;
    }
    // בדיקה שזה לא URL חיצוני
    if (preg_match('/^https?:\/\//i', $requested_redirect)) {
        return $default; // חסום URLs חיצוניים
    }
    // בדיקה שזה לא protocol-relative URL
    if (strpos($requested_redirect, '//') === 0) {
        return $default;
    }
    return $default;
}

// בדיקה שיש נתונים בטופס
if (empty($_POST) || (count($_POST) === 1 && isset($_POST['redirect']))) {
    $error_log = date('Y-m-d H:i:s') . " - לא התקבלו נתונים בטופס\n";
    file_put_contents($log_file, $error_log, FILE_APPEND);

    // הפניה בטוחה לדף תודה
    $redirect_url = getSafeRedirect($_POST['redirect'] ?? '', $allowed_redirects);
    header("Location: $redirect_url");
    exit;
}

// בדיקת שדות חובה
$required_fields = ['customerName', 'email', 'phone'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field])) {
        $error_log = date('Y-m-d H:i:s') . " - שדה חובה חסר: $field\n";
        file_put_contents($log_file, $error_log, FILE_APPEND);
        
        // הפניה לדף שגיאה
        header("Location: error.html?type=missing_fields");
        exit;
    }
}

// סינון וניקוי נתונים
$form_data = [];
foreach ($_POST as $key => $value) {
    if ($key !== 'submit' && $key !== 'redirect' && $key !== 'csrf_token') {
        $form_data[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }
}

// יצירת מזהה ייחודי להזמנה
$order_id = uniqid('quizy_');
$form_data['order_id'] = $order_id;

// בדיקה אם זו חבילת תוכנה להורדה
$is_software_package = (isset($form_data['package']) && $form_data['package'] === 'starter_package');

// בניית תוכן המייל למנהל
$admin_html_content = buildAdminEmailContent($form_data, $is_software_package);

// בניית תוכן המייל ללקוח
$customer_html_content = buildCustomerEmailContent($form_data, $is_software_package);

// שמירת עותק של המיילים
$debug_dir = __DIR__ . '/debug_emails';
if (!is_dir($debug_dir)) {
    mkdir($debug_dir, 0755, true);
}
$admin_debug_file = $debug_dir . '/' . time() . '_admin_email.html';
file_put_contents($admin_debug_file, $admin_html_content);

$customer_debug_file = $debug_dir . '/' . time() . '_customer_email.html';
file_put_contents($customer_debug_file, $customer_html_content);

// שליחת המייל למנהל
$admin_result = sendEmailWithResend($resend_api_key, $admin_html_content, $admin_email, $admin_subject);

// רישום תוצאת השליחה למנהל
$result_log = date('Y-m-d H:i:s') . ' - תוצאת שליחת מייל למנהל: ' . json_encode($admin_result, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($log_file, $result_log, FILE_APPEND);

// שליחת המייל ללקוח - רק מייל עם לינק תשלום (לא מייל אישור!)
$customer_result = sendEmailWithResend($resend_api_key, $customer_html_content, $form_data['email'], $customer_subject);

// רישום תוצאת השליחה ללקוח
$result_log = date('Y-m-d H:i:s') . ' - תוצאת שליחת מייל ללקוח: ' . json_encode($customer_result, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($log_file, $result_log, FILE_APPEND);

// אם שליחת המייל ללקוח נכשלה (כנראה כי הכתובת לא מורשית בחשבון הניסיון)
// שלח מייל נוסף למנהל עם פרטי הלקוח
if (!$customer_result['success'] && $form_data['email'] !== $admin_email) {
    $notification_subject = 'שים לב: נכשלה שליחת מייל ללקוח - ' . $form_data['customerName'];
    
    $notification_html = '
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                direction: rtl;
                text-align: right;
                color: #333;
                line-height: 1.6;
            }
            .alert {
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            .customer-info {
                background-color: #f5f7fa;
                border: 1px solid #e1e4e8;
                padding: 15px;
                border-radius: 5px;
            }
        </style>
    </head>
    <body>
        <div class="alert">
            <h3>שים לב: נכשלה שליחת מייל ללקוח</h3>
            <p>לא הצלחנו לשלוח מייל ללקוח בגלל מגבלות בחשבון הניסיון של Resend.</p>
            <p>אנא צור קשר עם הלקוח באופן ידני בהקדם האפשרי.</p>
        </div>
        
        <div class="customer-info">
            <h3>פרטי הלקוח:</h3>
            <p><strong>שם:</strong> ' . htmlspecialchars($form_data['customerName']) . '</p>
            <p><strong>אימייל:</strong> ' . htmlspecialchars($form_data['email']) . '</p>
            <p><strong>טלפון:</strong> ' . htmlspecialchars($form_data['phone']) . '</p>
            <p><strong>חבילה:</strong> ' . htmlspecialchars($form_data['package']) . '</p>
            <p><strong>מזהה הזמנה:</strong> ' . htmlspecialchars($form_data['order_id']) . '</p>
        </div>
        
        <p>התוכן שהיה אמור להישלח ללקוח שמור בתיקיית debug_emails.</p>
    </body>
    </html>';
    
    // שליחת המייל למנהל
    $notification_result = sendEmailWithResend($resend_api_key, $notification_html, $admin_email, $notification_subject);
    
    // רישום תוצאת השליחה
    $result_log = date('Y-m-d H:i:s') . ' - תוצאת שליחת התראה למנהל: ' . json_encode($notification_result, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($log_file, $result_log, FILE_APPEND);
}

// הפניה בטוחה לדף תודה
$redirect_url = getSafeRedirect($_POST['redirect'] ?? '', $allowed_redirects);
header("Location: $redirect_url");
exit;

/**
 * פונקציה לשליחת מייל באמצעות Resend API
 */
function sendEmailWithResend($api_key, $html_content, $to_email, $subject) {
    global $sender_email;
    
    // הגדרת נתוני המייל
    $data = [
        'from' => $sender_email,
        'to' => [$to_email],
        'subject' => $subject,
        'html' => $html_content
    ];
    
    // יצירת בקשת POST ל-API
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    // ביצוע הבקשה
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // החזרת תוצאת השליחה
    return [
        'success' => ($status_code >= 200 && $status_code < 300),
        'response' => $response,
        'status_code' => $status_code,
        'error' => $error
    ];
}

/**
 * פונקציה לבניית תוכן המייל למנהל
 */
function buildAdminEmailContent($form_data, $is_software_package = false) {
    global $approve_secret_token;

    // הוספת כפתור אישור ללקוח עם טוקן מאובטח
    $order_id = $form_data['order_id'];
    $customer_email = $form_data['email'];
    $package_type = $is_software_package ? 'software' : 'subscription';

    // יצירת חתימה מאובטחת לאישור (HMAC)
    $signature_data = $order_id . '|' . $customer_email . '|' . $package_type;
    $signature = hash_hmac('sha256', $signature_data, $approve_secret_token);

    $approve_url = "https://quizyform.vercel.app/approve.php?order_id={$order_id}&email=" . urlencode($customer_email) . "&type={$package_type}&sig={$signature}";

    // מידע על החבילה
    $package_info = [
        'basic' => ['name' => 'בסיסי', 'size' => '2GB', 'price' => '35 ₪', 'type' => 'אחסון בענן'],
        'standard' => ['name' => 'מתקדם', 'size' => '5GB', 'price' => '71 ₪', 'type' => 'אחסון בענן'],
        'premium' => ['name' => 'פרימיום', 'size' => '10GB', 'price' => '118 ₪', 'type' => 'אחסון בענן'],
        'pro' => ['name' => 'פרו', 'size' => '15GB', 'price' => '159 ₪', 'type' => 'אחסון בענן'],
        'ultimate' => ['name' => 'אולטימייט', 'size' => '20GB', 'price' => '189 ₪', 'type' => 'אחסון בענן'],
        'pro60' => ['name' => 'PRO60', 'size' => '60 שחקנים + 1GB בענן', 'price' => '217 ₪', 'type' => 'שעשועונים און ליין'],
        'pro300' => ['name' => 'PRO300', 'size' => '300 שחקנים + 2GB בענן', 'price' => '550 ₪', 'type' => 'שעשועונים און ליין'],
        'starter_package' => ['name' => 'חבילת תוכנה התחלתית', 'size' => '10 תבניות + 250MB אחסון', 'price' => '2,120 ₪', 'type' => 'רכישת חבילת תוכנה']
    ];

    $package = isset($form_data['package']) ? $form_data['package'] : 'premium';
    $package_details = isset($package_info[$package]) ? $package_info[$package] : $package_info['premium'];

    // הסכמה לרשימת דיוור
    $newsletter_consent = isset($form_data['newsletter']) && $form_data['newsletter'] == '1' ? 'כן' : 'לא';

    // קבלת IP ותאריך
    $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'לא זמין';
    $submission_date = date('d/m/Y');
    $submission_time = date('H:i:s');

    // תוכן שונה לחבילת תוכנה
    if ($is_software_package) {
        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    direction: rtl;
                    text-align: right;
                    color: #333;
                    line-height: 1.6;
                }
                h2 {
                    color: #0078d4;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                }
                .package-summary {
                    background-color: #fff3cd;
                    border: 2px solid #ffc107;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .package-summary h3 {
                    color: #856404;
                    margin-top: 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 20px 0;
                }
                th, td {
                    border: 1px solid #ddd;
                    padding: 10px;
                    text-align: right;
                }
                th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }
                .button {
                    display: inline-block;
                    background-color: #4CAF50;
                    color: white;
                    padding: 12px 20px;
                    text-align: center;
                    text-decoration: none;
                    font-size: 16px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .highlight {
                    background-color: #ffffcc;
                    padding: 10px;
                    border: 1px solid #ffeb3b;
                    margin: 20px 0;
                }
                .newsletter-yes {
                    color: #4CAF50;
                    font-weight: bold;
                }
                .newsletter-no {
                    color: #999;
                }
            </style>
        </head>
        <body>
            <h2>🎉 בקשה חדשה לרכישת חבילת תוכנה</h2>

            <div class="package-summary">
                <h3>פרטי החבילה:</h3>
                <p><strong>סוג:</strong> רכישה חד-פעמית (לא מנוי)</p>
                <p><strong>שם חבילה:</strong> ' . htmlspecialchars($package_details['name']) . '</p>
                <p><strong>כולל:</strong> ' . htmlspecialchars($package_details['size']) . '</p>
                <p><strong>מחיר חד-פעמי:</strong> ' . htmlspecialchars($package_details['price']) . '</p>
            </div>

            <h3>פרטי הלקוח:</h3>
            <table>
                <tr>
                    <th>שדה</th>
                    <th>ערך</th>
                </tr>
                <tr>
                    <td><strong>שם מלא</strong></td>
                    <td>' . htmlspecialchars($form_data['customerName'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>שם חברה</strong></td>
                    <td>' . htmlspecialchars($form_data['companyName'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>אימייל</strong></td>
                    <td>' . htmlspecialchars($form_data['email'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>טלפון</strong></td>
                    <td>' . htmlspecialchars($form_data['phone'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>שם לחשבונית</strong></td>
                    <td>' . htmlspecialchars($form_data['invoiceName'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>אימייל להתקנה</strong></td>
                    <td>' . htmlspecialchars($form_data['installEmail'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>מזהה הזמנה</strong></td>
                    <td>' . htmlspecialchars($order_id) . '</td>
                </tr>
            </table>

            <h3>הסכמה לרשימת דיוור:</h3>
            <p class="' . ($newsletter_consent === 'כן' ? 'newsletter-yes' : 'newsletter-no') . '">
                <strong>הסכים להצטרף לרשימת הדיוור:</strong> ' . $newsletter_consent . '
            </p>
            ' . ($newsletter_consent === 'כן' ? '
            <div style="background-color: #e8f5e9; padding: 10px; border-radius: 5px; margin: 10px 0;">
                <p><strong>פרטי אישור:</strong></p>
                <p>📅 תאריך: ' . $submission_date . '</p>
                <p>🕐 שעה: ' . $submission_time . '</p>
                <p>🌐 IP: ' . htmlspecialchars($user_ip) . '</p>
                <p style="font-size: 12px; color: #666;">ניתן להוסיף לקוח זה לרשימת הדיוור במערכת</p>
            </div>
            ' : '<p style="font-size: 12px; color: #666;">הלקוח לא ביקש להצטרף לרשימת הדיוור</p>') . '

            <div class="highlight">
                <p><strong>⚠️ שים לב - תהליך שונה מחבילות המנוי:</strong></p>
                <p>1. הלקוח קיבל מייל עם לינק לתשלום חד-פעמי</p>
                <p>2. לאחר שהלקוח משלם, בדוק במערכת התשלומים</p>
                <p>3. כשתאשר שהתשלום התקבל, לחץ על הכפתור למטה</p>
                <p>4. הלקוח יקבל מייל אוטומטי שהחבילה הותקנה</p>
                <p>5. <strong>התקן את החבילה תוך 48 שעות!</strong></p>
            </div>

            <a href="' . $approve_url . '" class="button">✅ אישור התקנת החבילה (לחץ רק אחרי תשלום!)</a>

            <p style="font-size: 12px; color: #666; margin-top: 30px;">
                נשלח מטופס קוויזי בתאריך: ' . $submission_date . ' בשעה: ' . $submission_time . '
            </p>
        </body>
        </html>';
    } else {
        // תוכן רגיל למנויי אחסון/שעשועונים
        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    direction: rtl;
                    text-align: right;
                    color: #333;
                    line-height: 1.6;
                }
                h2 {
                    color: #0078d4;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                }
                .package-summary {
                    background-color: #e3f2fd;
                    border: 2px solid #0078d4;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .package-summary h3 {
                    color: #0078d4;
                    margin-top: 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 20px 0;
                }
                th, td {
                    border: 1px solid #ddd;
                    padding: 10px;
                    text-align: right;
                }
                th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }
                .button {
                    display: inline-block;
                    background-color: #4CAF50;
                    color: white;
                    padding: 12px 20px;
                    text-align: center;
                    text-decoration: none;
                    font-size: 16px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .highlight {
                    background-color: #ffffcc;
                    padding: 10px;
                    border: 1px solid #ffeb3b;
                    margin: 20px 0;
                }
                .newsletter-yes {
                    color: #4CAF50;
                    font-weight: bold;
                }
                .newsletter-no {
                    color: #999;
                }
            </style>
        </head>
        <body>
            <h2>בקשה חדשה למנוי - ' . htmlspecialchars($package_details['type']) . '</h2>

            <div class="package-summary">
                <h3>פרטי החבילה שנרכשה:</h3>
                <p><strong>סוג מנוי:</strong> ' . htmlspecialchars($package_details['type']) . '</p>
                <p><strong>שם חבילה:</strong> ' . htmlspecialchars($package_details['name']) . '</p>
                <p><strong>היקף:</strong> ' . htmlspecialchars($package_details['size']) . '</p>
                <p><strong>מחיר חודשי:</strong> ' . htmlspecialchars($package_details['price']) . '</p>
            </div>

            <h3>פרטי הלקוח:</h3>
            <table>
                <tr>
                    <th>שדה</th>
                    <th>ערך</th>
                </tr>
                <tr>
                    <td><strong>שם מלא</strong></td>
                    <td>' . htmlspecialchars($form_data['customerName'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>שם חברה</strong></td>
                    <td>' . htmlspecialchars($form_data['companyName'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>אימייל</strong></td>
                    <td>' . htmlspecialchars($form_data['email'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>טלפון</strong></td>
                    <td>' . htmlspecialchars($form_data['phone'] ?? '') . '</td>
                </tr>
                <tr>
                    <td><strong>שם לחשבונית</strong></td>
                    <td>' . htmlspecialchars($form_data['invoiceName'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>אימייל להתקנה</strong></td>
                    <td>' . htmlspecialchars($form_data['installEmail'] ?? 'לא צוין') . '</td>
                </tr>
                <tr>
                    <td><strong>מזהה הזמנה</strong></td>
                    <td>' . htmlspecialchars($order_id) . '</td>
                </tr>
            </table>

            <h3>הסכמה לרשימת דיוור:</h3>
            <p class="' . ($newsletter_consent === 'כן' ? 'newsletter-yes' : 'newsletter-no') . '">
                <strong>הסכים להצטרף לרשימת הדיוור:</strong> ' . $newsletter_consent . '
            </p>
            ' . ($newsletter_consent === 'כן' ? '
            <div style="background-color: #e8f5e9; padding: 10px; border-radius: 5px; margin: 10px 0;">
                <p><strong>פרטי אישור:</strong></p>
                <p>📅 תאריך: ' . $submission_date . '</p>
                <p>🕐 שעה: ' . $submission_time . '</p>
                <p>🌐 IP: ' . htmlspecialchars($user_ip) . '</p>
                <p style="font-size: 12px; color: #666;">ניתן להוסיף לקוח זה לרשימת הדיוור במערכת</p>
            </div>
            ' : '<p style="font-size: 12px; color: #666;">הלקוח לא ביקש להצטרף לרשימת הדיוור</p>') . '

            <div class="highlight">
                <p><strong>פעולות נדרשות:</strong></p>
                <p>1. יש לוודא שהלקוח ביצע תשלום</p>
                <p>2. לאחר אישור התשלום, יש ללחוץ על הכפתור הבא לשליחת אישור ללקוח:</p>
            </div>

            <a href="' . $approve_url . '" class="button">אשר ללקוח שהמנוי פעיל</a>

            <p style="font-size: 12px; color: #666; margin-top: 30px;">
                נשלח מטופס קוויזי בתאריך: ' . $submission_date . ' בשעה: ' . $submission_time . '
            </p>
        </body>
        </html>';
    }

    return $html;
}

/**
 * פונקציה לבניית תוכן המייל ללקוח
 */
function buildCustomerEmailContent($form_data, $is_software_package = false) {
    // קביעת קישור התשלום בהתאם לחבילה שנבחרה
    $payment_links = [
        'basic' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=35&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%EC%E0%E7%F1%E5%EF%20%EE%E3%E9%E4%20%E1%F2%F0%EF%202GB&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=b639bedf70b5f3376e48630379c8f83648be252a396151be240045033d764534',
        'standard' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=71&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%EC%E0%E7%F1%E5%EF%20%EE%E3%E9%E4%20%E1%F2%F0%EF%205GB&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=71e64c53d4212b51edaf8295920362be23a980a550bad1605cbeeaeeeb81707e',
        'premium' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=118&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%EC%E0%E7%F1%E5%EF%20%EE%E3%E9%E4%20%E1%F2%F0%EF%2010GB&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=b3eda8fea8ca4133e0014c200a8ae19f02366cd276f2ff70d5e9c5058be37176',
        'pro' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=159&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%EC%E0%E7%F1%E5%EF%20%EE%E3%E9%E4%20%E1%F2%F0%EF%2015GB&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=fc89d18680ff0f30a792399497ee038b4838b4d675b245597bcde6204214e82f',
        'ultimate' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=189&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%EC%E0%E7%F1%E5%EF%20%EE%E3%E9%E4%20%E1%F2%F0%EF%2020GB&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=8d401301e0d0471981d199dddcb425ce849df47ba3105bace019c4830d260bee',
        'pro60' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=217&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%F2%E3%2060%20%F9%E7%F7%F0%E9%ED%20%E0%E5%EF%20%EC%E9%E9%EF&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=cacc9cfc23b87f1b2bb585ce6858ac097e94988bf9509fb118f4c8615619216d',
        'pro300' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=550&Coin=1&FixTash=False&HK=True&Info=%EE%F0%E5%E9%20%F2%E3%20300%20%F9%E7%F7%F0%E9%ED%20%E0%E5%EF%20%EC%E9%E9%EF&Masof=4501074534&MoreData=True&OnlyOnApprove=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=999&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=90a6cfc2942dce89ee4911d800760688eedbf7817b1c16f8fa6ba28ed572e88c',
        'starter_package' => 'https://icom.yaad.net/cgi-bin/yaadpay/yaadpay.pl?Amount=2120&Coin=1&FixTash=True&Info=10%20%FA%E1%F0%E9%E5%FA%20%EC%E4%E5%F8%E3%E4%20%E5%F1%F9%E9%EF%20%FA%EE%E9%EB%E4%20%EC%EE%F6%E8%F8%F4%E9%ED%20%E7%E3%E9%F9%ED%20&Masof=4501074534&MoreData=True&PageLang=HEB&Postpone=False&ShowEngTashText=True&Tash=1&UTF8out=True&action=pay&freq=1&sendemail=True&tmp=3&signature=0cdf93b84c4492c0ccd6a6dec7c6f2349ed84a0089f46fb63c71c685cd31fe8d'
    ];
    
    $package = isset($form_data['package']) ? $form_data['package'] : 'premium';
    $payment_link = isset($payment_links[$package]) ? $payment_links[$package] : $payment_links['premium'];
    
    // מידע על החבילה
    $package_info = [
        'basic' => ['name' => 'בסיסי', 'size' => '2GB', 'price' => '35 ₪'],
        'standard' => ['name' => 'מתקדם', 'size' => '5GB', 'price' => '71 ₪'],
        'premium' => ['name' => 'פרימיום', 'size' => '10GB', 'price' => '118 ₪'],
        'pro' => ['name' => 'פרו', 'size' => '15GB', 'price' => '159 ₪'],
        'ultimate' => ['name' => 'אולטימייט', 'size' => '20GB', 'price' => '189 ₪'],
        'pro60' => ['name' => 'PRO60', 'size' => '60 שחקנים', 'price' => '217 ₪'],
        'pro300' => ['name' => 'PRO300', 'size' => '300 שחקנים', 'price' => '550 ₪'],
        'starter_package' => ['name' => 'חבילת תוכנה התחלתית', 'size' => '10 תבניות + תוכנה', 'price' => '2,120 ₪']
    ];

    $package_name = isset($package_info[$package]) ? $package_info[$package]['name'] : 'פרימיום';
    $package_size = isset($package_info[$package]) ? $package_info[$package]['size'] : '10GB';
    $package_price = isset($package_info[$package]) ? $package_info[$package]['price'] : '118 ₪';

    // תוכן שונה לחבילת תוכנה
    if ($is_software_package) {
        $html = '
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    direction: rtl;
                    text-align: right;
                    color: #333;
                    line-height: 1.6;
                    max-width: 600px;
                    margin: 0 auto;
                }
                h2 {
                    color: #0078d4;
                    border-bottom: 1px solid #eee;
                    padding-bottom: 10px;
                }
                .package-info {
                    background-color: #fff3cd;
                    border: 2px solid #ffc107;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .package-name {
                    font-size: 18px;
                    font-weight: bold;
                    color: #856404;
                }
                .package-details {
                    margin: 10px 0;
                }
                .button {
                    display: inline-block;
                    background-color: #0078d4;
                    color: white;
                    padding: 15px 30px;
                    text-align: center;
                    text-decoration: none;
                    font-size: 18px;
                    font-weight: bold;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .note {
                    background-color: #e7f3ff;
                    padding: 15px;
                    border: 1px solid #0078d4;
                    margin: 20px 0;
                    font-size: 14px;
                }
                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #eee;
                    font-size: 14px;
                    color: #666;
                }
            </style>
        </head>
        <body>
            <h2>🎉 תודה על הרכישה!</h2>

            <p>שלום ' . htmlspecialchars($form_data['customerName']) . ',</p>

            <p>תודה שבחרת לרכוש את חבילת התוכנה ההתחלתית של Quizy Games! פרטי הרכישה שלך התקבלו בהצלחה.</p>

            <div class="package-info">
                <div class="package-name">חבילת תוכנה התחלתית</div>
                <div class="package-details">
                    <p><strong>החבילה כוללת:</strong></p>
                    <ul>
                        <li>תוכנת Quizy Games להורדה לשולחן העבודה</li>
                        <li>10 תבניות שעשועונים מוכנות לשימוש</li>
                        <li>250 מגה אחסון בשרת (31 ימי עסקי)</li>
                        <li>הדרכה און ליין מלאה</li>
                        <li>אחריות ותמיכה ל-12 חודשים</li>
                    </ul>
                    <p><strong>מחיר חד-פעמי:</strong> ' . $package_price . '</p>
                </div>
            </div>

            <p><strong>השלב הבא:</strong> לצורך השלמת הרכישה, יש לבצע תשלום באמצעות הקישור הבא:</p>

            <div style="text-align: center;">
                <a href="' . $payment_link . '" class="button">💳 לחצו כאן לתשלום מאובטח</a>
            </div>

            <div class="note">
                <p><strong>⏰ חשוב לדעת:</strong></p>
                <ul>
                    <li>זוהי רכישה <strong>חד-פעמית</strong> ללא חיובים חוזרים</li>
                    <li>לאחר ביצוע התשלום, נתקין את החבילה תוך <strong>48 שעות</strong></li>
                    <li>תקבלו מייל נוסף כשהחבילה תותקן במערכת</li>
                    <li>ניתן להתחיל ליצור ולהוריד את התוכנה מיד לאחר ההתקנה</li>
                </ul>
            </div>

            <div class="footer">
                <p>אם יש לכם שאלות או אם אתם זקוקים לעזרה, אנחנו כאן בשבילכם!</p>
                <p><strong>צוות Quizy Games</strong><br>
                טלפון: 077-300-6306<br>
                אימייל: info@playzone.co.il</p>
                <p style="color: #ff0000; font-size: 12px;">שימו לב: זהו מייל אוטומטי ולא ניתן להשיב אליו ישירות. לכל שאלה או בקשה, אנא פנו אלינו ב-info@playzone.co.il</p>
            </div>
        </body>
        </html>';

        return $html;
    }

    // בדיקה אם זה מנוי PRO (שעשועונים) או Cloud (אחסון)
    $is_pro_package = ($package === 'pro60' || $package === 'pro300');

    // הגדרת כותרת וטקסט בהתאם לסוג החבילה
    if ($is_pro_package) {
        $service_title = 'תודה על הרשמתכם כמנויים להפקת שעשועונים און ליין!';
        $service_description = 'תודה שבחרתם בשירות המנויים של קוויזי להפקת שעשועונים און ליין. פרטי ההרשמה שלכם התקבלו בהצלחה.';

        // הוספת נפח אחסון בענן למנויי PRO
        if ($package === 'pro60') {
            $cloud_storage = '1GB';
            $players_count = 'עד 60 סלולרים';
        } else {
            $cloud_storage = '2GB';
            $players_count = 'עד 300 סלולרים';
        }
        $storage_text = '<p><strong>נפח אחסון בענן:</strong> ' . $cloud_storage . '</p>';
        $description_text = '<p><strong>תיאור המנוי:</strong> מנוי להפעלת משחקים און ליין עם ' . $players_count . ' בכל משחק</p>';
    } else {
        $service_title = 'תודה על הרשמתכם לשירות אחסון קבצי מדיה בענן של קוויזי!';
        $service_description = 'תודה שבחרתם בשירותי האחסון של קוויזי. פרטי ההרשמה שלכם התקבלו בהצלחה.';
        $storage_text = '<p><strong>נפח אחסון:</strong> ' . $package_size . '</p>';
        $description_text = '';
    }

    $html = '
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                direction: rtl;
                text-align: right;
                color: #333;
                line-height: 1.6;
                max-width: 600px;
                margin: 0 auto;
            }
            h2 {
                color: #0078d4;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            .package-info {
                background-color: #f5f7fa;
                border: 1px solid #e1e4e8;
                border-radius: 5px;
                padding: 15px;
                margin: 20px 0;
            }
            .package-name {
                font-size: 18px;
                font-weight: bold;
                color: #0078d4;
            }
            .package-details {
                margin: 10px 0;
            }
            .button {
                display: inline-block;
                background-color: #0078d4;
                color: white;
                padding: 12px 20px;
                text-align: center;
                text-decoration: none;
                font-size: 16px;
                margin: 20px 0;
                border-radius: 4px;
            }
            .note {
                background-color: #ffffcc;
                padding: 10px;
                border: 1px solid #ffeb3b;
                margin: 20px 0;
                font-size: 14px;
            }
            .footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #eee;
                font-size: 14px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <h2>' . $service_title . '</h2>

        <p>שלום ' . htmlspecialchars($form_data['customerName']) . ',</p>

        <p>' . $service_description . '</p>

        <div class="package-info">
            <div class="package-name">חבילת ' . $package_name . ' - ' . $package_size . '</div>
            <div class="package-details">
                <p><strong>מחיר חודשי:</strong> ' . $package_price . '</p>
                ' . $storage_text . '
                ' . $description_text . '
            </div>
        </div>

        <p><strong>השלב הבא:</strong> לצורך הפעלת המנוי, יש לבצע תשלום באמצעות הקישור הבא:</p>

        <a href="' . $payment_link . '" class="button">לחצו כאן לתשלום</a>

        <div class="note">
            <p><strong>שימו לב:</strong> לאחר ביצוע התשלום, המנוי יופעל תוך 24 שעות ותקבלו מייל אישור נוסף.</p>
        </div>

        <p>אם יש לכם שאלות כלשהן או שאתם זקוקים לעזרה, אנחנו כאן בשבילכם!</p>

        <a href="https://quizygame.com" class="button">לתמיכה טכנית</a>

        <div class="footer">
            <p>בברכה,<br>צוות קוויזי</p>
            <p>טלפון: 077-300-6306<br>אימייל: info@playzone.co.il</p>
            <p style="color: #ff0000; font-size: 12px;">שימו לב: זהו מייל אוטומטי ולא ניתן להשיב אליו. לכל שאלה או בקשה, אנא פנו אלינו בכתובת info@playzone.co.il</p>
        </div>
    </body>
    </html>';
    
    return $html;
}
?> 