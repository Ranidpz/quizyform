<?php
/**
 * דף בדיקת שליחת מיילים - Quizy Form
 * בדיקה שה-API של Resend עובד תקין
 */

header('Content-Type: text/html; charset=utf-8');

// טעינת משתני סביבה
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

$resend_api_key = getenv('RESEND_API_KEY');

?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>בדיקת מייל - Quizy Form</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .status { padding: 15px; border-radius: 5px; margin: 15px 0; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        button {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 15px;
        }
        button:hover { background: #0056b3; }
        input[type="email"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
            direction: ltr;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 בדיקת שליחת מייל</h1>

        <h3>שלב 1: בדיקת Environment Variables</h3>
        <?php if ($resend_api_key): ?>
            <div class="status success">
                ✅ RESEND_API_KEY נמצא<br>
                <small>מתחיל ב: <?php echo substr($resend_api_key, 0, 10); ?>...</small>
            </div>
        <?php else: ?>
            <div class="status error">
                ❌ RESEND_API_KEY לא נמצא!<br>
                <small>יש לוודא שהמשתנה מוגדר ב-Vercel Environment Variables</small>
            </div>
        <?php endif; ?>

        <?php
        $approve_token = getenv('APPROVE_SECRET_TOKEN');
        if ($approve_token): ?>
            <div class="status success">
                ✅ APPROVE_SECRET_TOKEN נמצא
            </div>
        <?php else: ?>
            <div class="status warning">
                ⚠️ APPROVE_SECRET_TOKEN לא נמצא (לא קריטי לבדיקה)
            </div>
        <?php endif; ?>

        <?php
        // שליחת מייל בדיקה
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
            $test_email = filter_var($_POST['test_email'], FILTER_VALIDATE_EMAIL);

            if (!$test_email) {
                echo '<div class="status error">❌ כתובת מייל לא תקינה</div>';
            } elseif (!$resend_api_key) {
                echo '<div class="status error">❌ לא ניתן לשלוח - API Key חסר</div>';
            } else {
                echo '<h3>שלב 2: שליחת מייל בדיקה</h3>';

                $data = [
                    'from' => 'Quizy Test <no-reply@playzones.app>',
                    'to' => [$test_email],
                    'subject' => '🧪 בדיקת מערכת Quizy - ' . date('H:i:s'),
                    'html' => '
                        <div style="font-family: Arial, sans-serif; direction: rtl; text-align: right; padding: 20px;">
                            <h2 style="color: #28a745;">✅ המייל נשלח בהצלחה!</h2>
                            <p>זהו מייל בדיקה מאת מערכת Quizy Form.</p>
                            <p>תאריך ושעה: ' . date('Y-m-d H:i:s') . '</p>
                            <hr>
                            <p style="color: #666; font-size: 12px;">אם קיבלת מייל זה, המערכת עובדת תקין.</p>
                        </div>
                    '
                ];

                $ch = curl_init('https://api.resend.com/emails');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $resend_api_key,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_TIMEOUT => 30
                ]);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                echo '<div class="status info">';
                echo '<strong>פרטי הבקשה:</strong><br>';
                echo 'To: ' . htmlspecialchars($test_email) . '<br>';
                echo 'HTTP Code: ' . $http_code . '<br>';
                echo '</div>';

                if ($curl_error) {
                    echo '<div class="status error">';
                    echo '❌ שגיאת CURL: ' . htmlspecialchars($curl_error);
                    echo '</div>';
                } elseif ($http_code === 200) {
                    $result = json_decode($response, true);
                    echo '<div class="status success">';
                    echo '✅ המייל נשלח בהצלחה!<br>';
                    echo 'ID: ' . ($result['id'] ?? 'N/A');
                    echo '</div>';
                } else {
                    echo '<div class="status error">';
                    echo '❌ שגיאה בשליחה<br>';
                    echo '<pre>' . htmlspecialchars($response) . '</pre>';
                    echo '</div>';
                }
            }
        }
        ?>

        <h3>שלח מייל בדיקה</h3>
        <form method="POST">
            <input type="email" name="test_email" placeholder="הכנס כתובת מייל לבדיקה" required>
            <button type="submit">📧 שלח מייל בדיקה</button>
        </form>

        <hr style="margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">
            ⚠️ דף זה לבדיקות בלבד. יש למחוק אותו לאחר הבדיקה.
        </p>
    </div>
</body>
</html>
