<?php
/**
 * Quizy Form - קובץ אישור מנוי
 * 
 * קובץ זה מטפל באישור מנוי ושליחת מייל אישור ללקוח
 * באמצעות Resend API
 */

// הגדרות בסיסיות
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

// מפתח ה-API של Resend - ישירות בקוד לפשטות
$resend_api_key = 're_STacfGs3_DaWkfkqzEvQsu2VsSm2kygxV';

// הגדרת כתובות מייל
// חשוב: השתמש בדומיין המאומת playzones.app לשליחת מיילים
// הלקוחות צריכים לפנות ל-info@playzone.co.il ולא להשיב למייל זה
$sender_email = 'Quizy Form <no-reply@playzones.app>';
$admin_email = 'info@playzone.co.il';

// הגדרת קובץ לוג
$log_file = __DIR__ . '/form_submissions.log';

// בדיקת פרמטרים - תמיכה ב-GET וגם ב-POST
$order_id = isset($_POST['order_id']) ? $_POST['order_id'] : (isset($_GET['order_id']) ? $_GET['order_id'] : '');
$customer_email = isset($_POST['email']) ? $_POST['email'] : (isset($_GET['email']) ? $_GET['email'] : '');
$type = isset($_POST['type']) ? $_POST['type'] : (isset($_GET['type']) ? $_GET['type'] : 'subscription');

// בדיקה אם זו חבילת תוכנה
$is_software_package = ($type === 'software');

// בדיקת תקינות הפרמטרים
if (empty($order_id) || empty($customer_email) || !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
    showErrorPage('פרמטרים חסרים או לא תקינים');
    exit;
}

// בדיקה אם זו בקשת GET (אז נציג דף אישור) או POST (אז נשלח את המייל)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // זו בקשת GET - נציג דף אישור במקום לשלוח מייל מיד
    // כך מונעים שליחה אוטומטית כאשר Gmail או אנטי-וירוס סורקים את הלינק
    showConfirmationPage($order_id, $customer_email, $type);
    exit;
}

// רישום לוג של הבקשה
$log_data = date('Y-m-d H:i:s') . ' - בקשת אישור ' . ($is_software_package ? 'חבילת תוכנה' : 'מנוי') . ': ' . $order_id . ' עבור ' . $customer_email . "\n";
file_put_contents($log_file, $log_data, FILE_APPEND);

// בניית תוכן המייל ללקוח
$confirmation_html = buildConfirmationEmail($order_id, $customer_email, $is_software_package);

// קביעת נושא המייל בהתאם לסוג
$confirmation_subject = $is_software_package
    ? 'החבילה שלך בהתקנה! - Quizy Games'
    : 'המנוי שלך פעיל! - Quizy Cloud Storage';

// שמירת עותק של המייל
$debug_dir = __DIR__ . '/debug_emails';
if (!is_dir($debug_dir)) {
    mkdir($debug_dir, 0755, true);
}
$debug_file = $debug_dir . '/' . time() . '_confirmation_email.html';
file_put_contents($debug_file, $confirmation_html);

// שליחת המייל ללקוח
$result = sendEmailWithResend($resend_api_key, $confirmation_html, $customer_email, $confirmation_subject);

// רישום תוצאת השליחה
$result_log = date('Y-m-d H:i:s') . ' - תוצאת שליחת מייל אישור ללקוח: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($log_file, $result_log, FILE_APPEND);

// אם שליחת המייל ללקוח נכשלה (כנראה כי הכתובת לא מורשית בחשבון הניסיון)
// שלח מייל נוסף למנהל עם פרטי הלקוח
if (!$result['success'] && $customer_email !== $admin_email) {
    $notification_subject = 'שים לב: נכשלה שליחת מייל אישור ללקוח - ' . $customer_email;
    
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
            <h3>שים לב: נכשלה שליחת מייל אישור ללקוח</h3>
            <p>לא הצלחנו לשלוח מייל אישור ללקוח בגלל מגבלות בחשבון הניסיון של Resend.</p>
            <p>אנא צור קשר עם הלקוח באופן ידני בהקדם האפשרי.</p>
        </div>
        
        <div class="customer-info">
            <h3>פרטי הלקוח:</h3>
            <p><strong>אימייל:</strong> ' . htmlspecialchars($customer_email) . '</p>
            <p><strong>מזהה הזמנה:</strong> ' . htmlspecialchars($order_id) . '</p>
        </div>
        
        <p>התוכן שהיה אמור להישלח ללקוח שמור בתיקיית debug_emails.</p>
    </body>
    </html>';
    
    // שליחת המייל למנהל
    $notification_result = sendEmailWithResend($resend_api_key, $notification_html, $admin_email, $notification_subject);
    
    // רישום תוצאת השליחה
    $result_log = date('Y-m-d H:i:s') . ' - תוצאת שליחת התראה למנהל: ' . json_encode($notification_result, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($log_file, $result_log, FILE_APPEND);
    
    // הצגת דף הצלחה למרות שלא נשלח מייל
    showSuccessPage($customer_email . ' (לא נשלח מייל - דומיין לא מאומת)');
    exit;
}

// הצגת דף אישור או שגיאה
if ($result['success']) {
    showSuccessPage($customer_email);
} else {
    showErrorPage('שגיאה בשליחת המייל: ' . $result['error']);
}

/**
 * פונקציה להצגת דף אישור לפני שליחת המייל
 * זה מונע שליחה אוטומטית כאשר Gmail או אנטי-וירוס סורקים את הלינק
 */
function showConfirmationPage($order_id, $customer_email, $type) {
    $is_software = ($type === 'software');
    $title = $is_software ? 'חבילת תוכנה' : 'מנוי';

    echo '<!DOCTYPE html>
    <html lang="he" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>אישור ' . htmlspecialchars($title) . ' | Quizy</title>
        <link href="https://fonts.googleapis.com/css2?family=Assistant:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: "Assistant", sans-serif;
                background-color: #f5f7fa;
                margin: 0;
                padding: 0;
                direction: rtl;
                text-align: right;
                color: #333;
            }
            .container {
                max-width: 800px;
                margin: 50px auto;
                padding: 30px;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }
            .warning-icon {
                font-size: 64px;
                color: #ffc107;
                text-align: center;
                margin-bottom: 20px;
            }
            h1 {
                color: #0078d4;
                margin-bottom: 20px;
                text-align: center;
            }
            p {
                font-size: 18px;
                line-height: 1.6;
                margin-bottom: 20px;
            }
            .details {
                background-color: #f5f7fa;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            .warning-box {
                background-color: #fff3cd;
                border: 2px solid #ffc107;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                color: #856404;
            }
            .button-container {
                text-align: center;
                margin-top: 30px;
            }
            .confirm-button {
                background-color: #28a745;
                color: white;
                padding: 15px 40px;
                border: none;
                border-radius: 5px;
                font-size: 18px;
                font-weight: bold;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .confirm-button:hover {
                background-color: #218838;
            }
            .cancel-button {
                background-color: #dc3545;
                color: white;
                padding: 15px 40px;
                border: none;
                border-radius: 5px;
                font-size: 18px;
                font-weight: bold;
                cursor: pointer;
                margin-right: 10px;
                transition: background-color 0.3s;
                text-decoration: none;
                display: inline-block;
            }
            .cancel-button:hover {
                background-color: #c82333;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="warning-icon">⚠️</div>
            <h1>אישור ' . htmlspecialchars($title) . '</h1>

            <p><strong>האם אתה בטוח שברצונך לאשר את ה' . htmlspecialchars($title) . ' הזה?</strong></p>

            <div class="details">
                <p><strong>מזהה הזמנה:</strong> ' . htmlspecialchars($order_id) . '</p>
                <p><strong>כתובת מייל לקוח:</strong> ' . htmlspecialchars($customer_email) . '</p>
                <p><strong>סוג:</strong> ' . htmlspecialchars($title) . '</p>
            </div>

            <div class="warning-box">
                <h3>⚠️ שים לב!</h3>';

    if ($is_software) {
        echo '
                <p>לאחר אישור, הפעולות הבאות יתבצעו:</p>
                <ul>
                    <li>הלקוח יקבל מייל אישור התקנה</li>
                    <li>המייל יודיע שהחבילה תותקן תוך 48 שעות</li>
                    <li>הלקוח יוכל להתחיל ולהפיק שעשועונים, אך ההורדה תתאפשר רק לאחר ההתקנה</li>
                </ul>';
    } else {
        echo '
                <p>לאחר אישור, הפעולות הבאות יתבצעו:</p>
                <ul>
                    <li>הלקוח יקבל מייל אישור הפעלת מנוי</li>
                    <li>המנוי יופעל מיידית</li>
                    <li>הלקוח יוכל להתחיל להשתמש בשירות</li>
                </ul>';
    }

    echo '
            </div>

            <form method="POST" action="" class="button-container">
                <input type="hidden" name="order_id" value="' . htmlspecialchars($order_id) . '">
                <input type="hidden" name="email" value="' . htmlspecialchars($customer_email) . '">
                <input type="hidden" name="type" value="' . htmlspecialchars($type) . '">
                <a href="https://quizygame.com" class="cancel-button">ביטול</a>
                <button type="submit" class="confirm-button">✓ אישור ושליחת מייל ללקוח</button>
            </form>
        </div>
    </body>
    </html>';
}

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
 * פונקציה לבניית תוכן המייל אישור ללקוח
 */
function buildConfirmationEmail($order_id, $customer_email, $is_software_package = false) {
    // מייל שונה לחבילת תוכנה
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
                .success-box {
                    background-color: #fff3cd;
                    border: 2px solid #ffc107;
                    border-radius: 5px;
                    padding: 20px;
                    margin: 20px 0;
                    color: #856404;
                }
                .success-icon {
                    font-size: 48px;
                    color: #ffc107;
                    text-align: center;
                    margin-bottom: 15px;
                }
                .details {
                    background-color: #f5f7fa;
                    border: 1px solid #e1e4e8;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
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
            <h2>החבילה שלך בהתקנה! - Quizy Games</h2>

            <div class="success-box">
                <div class="success-icon">⏳</div>
                <h3>התשלום התקבל בהצלחה!</h3>
                <p>תודה! קיבלנו את התשלום עבור חבילת התוכנה ההתחלתית.</p>
            </div>

            <div class="details">
                <p><strong>מזהה הזמנה:</strong> ' . htmlspecialchars($order_id) . '</p>
                <p><strong>כתובת מייל:</strong> ' . htmlspecialchars($customer_email) . '</p>
                <p><strong>תאריך:</strong> ' . date('d/m/Y') . '</p>
            </div>

            <div class="note">
                <h3>⏰ מה הלאה?</h3>
                <ul>
                    <li><strong>התקנת החבילה תתבצע תוך 48 שעות</strong></li>
                    <li>תקבל/י הודעה כשהחבילה מוכנה לשימוש</li>
                    <li>אתם יכולים כבר להתחיל ולהפיק את השעשועונים, אבל ההורדה תתאפשר רק לאחר התקנת החבילה</li>
                </ul>

                <p><strong>יש בעיה או שאלה?</strong> צרו קשר:</p>
                <ul>
                    <li>📱 וואטסאפ / טלפון: 077-300-6306</li>
                    <li>📧 אימייל: info@playzone.co.il</li>
                </ul>
            </div>

            <div class="footer">
                <p><strong>צוות Quizy Games</strong><br>
                טלפון: 077-300-6306<br>
                אימייל: info@playzone.co.il</p>
                <p style="color: #ff0000; font-size: 12px;">שים לב: זהו מייל אוטומטי ולא ניתן להשיב אליו. לכל שאלה או בקשה, אנא פנה אלינו ב-info@playzone.co.il</p>
            </div>
        </body>
        </html>';
    } else {
        // מייל רגיל למנויים
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
                .success-box {
                    background-color: #d4edda;
                    border: 1px solid #c3e6cb;
                    border-radius: 5px;
                    padding: 20px;
                    margin: 20px 0;
                    color: #155724;
                }
                .success-icon {
                    font-size: 48px;
                    color: #28a745;
                    text-align: center;
                    margin-bottom: 15px;
                }
                .details {
                    background-color: #f5f7fa;
                    border: 1px solid #e1e4e8;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #eee;
                    font-size: 14px;
                    color: #666;
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
            </style>
        </head>
        <body>
            <h2>המנוי שלך פעיל! - Quizy Cloud Storage</h2>

            <div class="success-box">
                <div class="success-icon">✓</div>
                <h3>המנוי שלך הופעל בהצלחה!</h3>
                <p>אנו שמחים לבשר לך שהמנוי שלך לשירות אחסון קבצים של קוויזי פעיל כעת.</p>
            </div>

            <div class="details">
                <p><strong>מזהה הזמנה:</strong> ' . htmlspecialchars($order_id) . '</p>
                <p><strong>כתובת מייל:</strong> ' . htmlspecialchars($customer_email) . '</p>
                <p><strong>תאריך הפעלה:</strong> ' . date('d/m/Y') . '</p>
            </div>

            <p>אם יש לך שאלות כלשהן או שאת/ה זקוק/ה לעזרה, אנחנו כאן בשבילך!</p>

            <a href="https://quizygame.com" class="button">לתמיכה טכנית</a>

            <div class="footer">
                <p>בברכה,<br>צוות קוויזי</p>
                <p>טלפון: 077-300-6306<br>אימייל: info@playzone.co.il</p>
                <p style="color: #ff0000; font-size: 12px;">שים לב: זהו מייל אוטומטי ולא ניתן להשיב אליו. לכל שאלה או בקשה, אנא פנה אלינו בכתובת info@playzone.co.il</p>
            </div>
        </body>
        </html>';
    }

    return $html;
}

/**
 * פונקציה להצגת דף הצלחה
 */
function showSuccessPage($customer_email) {
    echo '<!DOCTYPE html>
    <html lang="he" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>אישור מנוי | Quizy Cloud Storage</title>
        <link href="https://fonts.googleapis.com/css2?family=Assistant:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: "Assistant", sans-serif;
                background-color: #f5f7fa;
                margin: 0;
                padding: 0;
                direction: rtl;
                text-align: right;
                color: #333;
            }
            .container {
                max-width: 800px;
                margin: 50px auto;
                padding: 30px;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                text-align: center;
            }
            .success-icon {
                font-size: 64px;
                color: #28a745;
                margin-bottom: 20px;
            }
            h1 {
                color: #0078d4;
                margin-bottom: 20px;
            }
            p {
                font-size: 18px;
                line-height: 1.6;
                margin-bottom: 20px;
            }
            .details {
                background-color: #f5f7fa;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                text-align: right;
            }
            .button {
                display: inline-block;
                background-color: #0078d4;
                color: white;
                padding: 12px 30px;
                border-radius: 5px;
                text-decoration: none;
                font-weight: bold;
                margin-top: 20px;
                transition: background-color 0.3s;
            }
            .button:hover {
                background-color: #005a9e;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="success-icon">✓</div>
            <h1>המייל נשלח בהצלחה!</h1>
            <p>מייל אישור הפעלת מנוי נשלח בהצלחה ל-' . htmlspecialchars($customer_email) . '</p>
            
            <div class="details">
                <p><strong>פעולות שבוצעו:</strong></p>
                <p>1. המנוי אושר במערכת</p>
                <p>2. נשלח מייל אישור ללקוח</p>
                <p>3. הלקוח יכול להתחיל להשתמש במערכת</p>
            </div>
            
            <a href="https://quizygame.com" class="button">חזרה לדף הראשי</a>
        </div>
    </body>
    </html>';
}

/**
 * פונקציה להצגת דף שגיאה
 */
function showErrorPage($error_message) {
    echo '<!DOCTYPE html>
    <html lang="he" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>שגיאה | Quizy Cloud Storage</title>
        <link href="https://fonts.googleapis.com/css2?family=Assistant:wght@200;300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: "Assistant", sans-serif;
                background-color: #f5f7fa;
                margin: 0;
                padding: 0;
                direction: rtl;
                text-align: right;
                color: #333;
            }
            .container {
                max-width: 800px;
                margin: 50px auto;
                padding: 30px;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                text-align: center;
            }
            .error-icon {
                font-size: 64px;
                color: #dc3545;
                margin-bottom: 20px;
            }
            h1 {
                color: #dc3545;
                margin-bottom: 20px;
            }
            p {
                font-size: 18px;
                line-height: 1.6;
                margin-bottom: 20px;
            }
            .error-details {
                background-color: #f8d7da;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                text-align: right;
                color: #721c24;
            }
            .button {
                display: inline-block;
                background-color: #0078d4;
                color: white;
                padding: 12px 30px;
                border-radius: 5px;
                text-decoration: none;
                font-weight: bold;
                margin-top: 20px;
                transition: background-color 0.3s;
            }
            .button:hover {
                background-color: #005a9e;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="error-icon">✗</div>
            <h1>אירעה שגיאה</h1>
            <p>לא ניתן היה להשלים את פעולת אישור המנוי.</p>
            
            <div class="error-details">
                <p><strong>פרטי השגיאה:</strong></p>
                <p>' . htmlspecialchars($error_message) . '</p>
            </div>
            
            <p>אנא נסו שוב או צרו קשר עם מנהל המערכת.</p>
            
            <a href="index.html" class="button">חזרה לדף הראשי</a>
        </div>
    </body>
    </html>';
}
?> 