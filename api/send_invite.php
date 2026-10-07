<?php
declare(strict_types=1);

header('Content-Type: application/json');

session_start();

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../vendor/autoload.php';

$appConfig = require __DIR__ . '/../config/app.php';
$mailConfig = require __DIR__ . '/../config/mail.php';
$drivaultConfig = require __DIR__ . '/../config/drivault.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


function formatDatabaseErrorMessage(mysqli_sql_exception $exception): string
{
    $errorCode = (int) $exception->getCode();
    $errorMessage = strtolower($exception->getMessage());

    if ($errorCode === 1932 || str_contains($errorMessage, "doesn't exist in engine")) {
        return 'The users table is unavailable in MySQL. Repair or recreate the users table and try again.';
    }

    if ($errorCode === 1406 || str_contains($errorMessage, 'data too long')) {
        return 'One of the invitation values is too long to save. Shorten the entered details and try again.';
    }

    if ($errorCode === 1062 || str_contains($errorMessage, 'duplicate entry')) {
        return 'This email already belongs to another account record. Use a different email or update the existing user first.';
    }

    return 'Unable to save the invitation. Check the database configuration.';
}

function buildPublicUrl(string $baseUrl, string $path): string
{
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function normalizeInvitationEmail(string $email): string
{
    return strtolower(trim($email));
}

function normalizeInvitationPhone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function normalizeInviterValue(string $inviterEmail): string
{
    return strtolower(trim($inviterEmail));
}

function getInviterUserId(string $inviterEmail): string
{
    return normalizeInviterValue(explode('@', $inviterEmail)[0] ?? '');
}

function exitAlreadyInvited(string $field): void
{
    http_response_code(409);

    echo json_encode([
        'status' => false,
        'error' => 'ALREADY_INVITED',
        'message' => $field === 'email'
            ? 'This email has already been invited by you.'
            : 'This phone number has already been invited by you.',
        'field' => $field,
    ]);

    exit;
}

function extractDrivaultUsers(string $responseBody): array
{
    $data = json_decode($responseBody, true);

    if (!is_array($data)) {
        return [];
    }

    $users = $data['ocs']['data']['users'] ?? [];

    return is_array($users) ? array_values($users) : [];
}

function searchDrivaultUsers(string $endpoint, string $apiUsername, string $apiPassword, string $searchTerm): array
{
    $curlHandle = curl_init(rtrim($endpoint, '/') . '?search=' . rawurlencode($searchTerm));

    curl_setopt_array($curlHandle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $apiUsername . ':' . $apiPassword,
        CURLOPT_HTTPHEADER => [
            'OCS-APIRequest: true',
            'Accept: application/json',
        ],
    ]);

    $responseBody = curl_exec($curlHandle);
    $curlError = curl_error($curlHandle);
    $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
    curl_close($curlHandle);

    if ($responseBody === false) {
        throw new RuntimeException('Unable to connect to Drivault: ' . $curlError);
    }

    if ($httpStatus >= 400) {
        throw new RuntimeException('Drivault account verification failed.');
    }

    return extractDrivaultUsers((string) $responseBody);
}

function exitDrivaultAccountExists(string $field): void
{
    http_response_code(409);

    echo json_encode([
        'status' => false,
        'error' => 'DRIVAULT_ACCOUNT_EXISTS',
        'message' => $field === 'email'
            ? 'This email already has a Drivault account.'
            : 'This phone number already has a Drivault account.',
        'field' => $field,
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$sessionInviterEmail = trim((string) ($_SESSION['admin_email'] ?? ''));
$inviter_email = trim((string) ($_POST['inviter_email'] ?? $sessionInviterEmail));

if ($name === '' || $email === '' || $phone === '') {
    http_response_code(422);
    exit('Name, email, and mobile number are required.');
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    http_response_code(422);
    exit('Invalid email address.');
}

if (preg_match('/^[0-9+\-\s()]{7,20}$/', $phone) !== 1) {
    http_response_code(422);
    exit('Invalid mobile number.');
}

if ($inviter_email === '') {
    http_response_code(422);
    exit('inviter_email is required.');
}

if (preg_match('/^[A-Za-z0-9@._-]{3,100}$/', $inviter_email) !== 1) {
    http_response_code(422);
    exit('Invalid inviter value.');
}

$normalizedEmail = normalizeInvitationEmail($email);
$normalizedPhone = normalizeInvitationPhone($phone);
$normalizedInviterEmail = normalizeInviterValue($inviter_email);
$normalizedInviterUserId = getInviterUserId($inviter_email);

try {
    $conn->set_charset('utf8mb4');

    $duplicateStatement = $conn->prepare(
        "SELECT email, phone
         FROM users
         WHERE role = 'user'
           AND invite_accepted = 'no'
           AND (
                LOWER(TRIM(inviter_email)) = ?
                OR LOWER(TRIM(inviter_email)) = ?
           )"
    );
    $duplicateStatement->bind_param('ss', $normalizedInviterEmail, $normalizedInviterUserId);
    $duplicateStatement->execute();
    $duplicateResult = $duplicateStatement->get_result();
    $phoneAlreadyInvited = false;

    while ($pendingInvitation = $duplicateResult->fetch_assoc()) {
        if (normalizeInvitationEmail((string) ($pendingInvitation['email'] ?? '')) === $normalizedEmail) {
            $duplicateStatement->close();
            exitAlreadyInvited('email');
        }

        if (
            $normalizedPhone !== '' &&
            normalizeInvitationPhone((string) ($pendingInvitation['phone'] ?? '')) === $normalizedPhone
        ) {
            $phoneAlreadyInvited = true;
        }
    }

    $duplicateStatement->close();

    if ($phoneAlreadyInvited) {
        exitAlreadyInvited('phone');
    }
} catch (mysqli_sql_exception $exception) {
    error_log(
        sprintf(
            '[send_invite] Duplicate invitation check error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(500);
    exit(formatDatabaseErrorMessage($exception));
}

$drivaultEndpoint = trim((string) ($drivaultConfig['endpoint'] ?? ''));
$drivaultUsername = trim((string) ($drivaultConfig['username'] ?? ''));
$drivaultPassword = trim((string) ($drivaultConfig['password'] ?? ''));

if ($drivaultEndpoint === '' || $drivaultUsername === '' || $drivaultPassword === '') {
    http_response_code(500);
    exit('Drivault API is not configured.');
}

try {
    if (count(searchDrivaultUsers($drivaultEndpoint, $drivaultUsername, $drivaultPassword, $normalizedEmail)) > 0) {
        exitDrivaultAccountExists('email');
    }

    if (
        $normalizedPhone !== '' &&
        count(searchDrivaultUsers($drivaultEndpoint, $drivaultUsername, $drivaultPassword, $normalizedPhone)) > 0
    ) {
        exitDrivaultAccountExists('phone');
    }
} catch (Throwable $exception) {
    error_log(
        sprintf(
            '[send_invite] Drivault account check error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(502);
    exit('Unable to verify Drivault account status.');
}

$token = bin2hex(random_bytes(32));

$verificationLink = buildPublicUrl(
    (string) ($appConfig['base_url'] ?? 'http://103.174.148.208'),
    sprintf(
        'api/generate_otp.php?token=%s',
        urlencode($token)
    )
);

$smtpHost = (string) ($mailConfig['host'] ?? '');
$smtpPort = (int) ($mailConfig['port'] ?? 0);
$smtpEncryption = (string) ($mailConfig['encryption'] ?? '');
$smtpUsername = trim((string) ($mailConfig['username'] ?? ''));
$smtpPassword = trim((string) ($mailConfig['password'] ?? ''));
$smtpFromName = trim((string) ($mailConfig['from_name'] ?? 'Drivault'));
$sessionInviterName = trim((string) ($_SESSION['admin_name'] ?? ''));
$inviterName = trim((string) ($_POST['inviter_name'] ?? ($sessionInviterName !== '' ? $sessionInviterName : ($mailConfig['inviter_name'] ?? $smtpFromName))));
$displayName = $inviterName;

/*
Example:
$inviter_email = 7892660797@login.drivault.com

userid becomes:
7892660797
*/

$inviterUserId = explode(
    '@',
    $inviter_email
)[0];

if($inviterUserId !== ''){

    $curlHandle = curl_init();

    curl_setopt_array($curlHandle,[

    CURLOPT_URL =>
    'https://login.drivault.com/ocs/v1.php/cloud/users/' .
    rawurlencode($inviterUserId),

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_FOLLOWLOCATION => true,

    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,

    CURLOPT_USERPWD =>
    'admin:kuRsef-gobno8-gankux',

    CURLOPT_HTTPHEADER => [
        'OCS-APIRequest: true',
        'Accept: application/json'
    ]
]);

    // curl_setopt_array($curlHandle,[

    //     CURLOPT_URL =>
    //     'http://login.drivault.com/ocs/v1.php/cloud/users/' .
    //     rawurlencode($inviterUserId),

    //     CURLOPT_RETURNTRANSFER => true,

    //     CURLOPT_HTTPAUTH =>
    //     CURLAUTH_BASIC,

    //     CURLOPT_USERPWD =>
    //     'admin:kuRsef-gobno8-gankux',

    //     CURLOPT_HTTPHEADER => [

    //         'OCS-APIRequest: true',
    //         'Accept: application/json'

    //     ]

    // ]);

    $responseBody =
        curl_exec($curlHandle);

    curl_close($curlHandle);

//     if($responseBody){

//         $userData =
//             json_decode(
//                 $responseBody,
//                 true
//             );

//         $displayName =
//             trim(
//                 (string)(
//                     $userData['ocs']
//                     ['data']
//                     ['displayname']
//                     ?? $inviterName
//                 )
//             );
//             error_log("INVITER USER ID: " . $inviterUserId);
// error_log("INVITER DISPLAY NAME: " . $displayName);
// error_log("INVITER EMAIL/PHONE: " . $inviter_email);

// $inviterName = $displayName;
//     }

    if (is_string($responseBody) && $responseBody !== '') {
        $userData = json_decode($responseBody, true);

        if (is_array($userData)) {
            $displayName =
                trim(
                    (string)(
                        $userData['ocs']
                        ['data']
                        ['displayname']
                        ?? $inviterName
                    )
                );

            error_log("INVITER USER ID: " . $inviterUserId);
            error_log("INVITER DISPLAY NAME: " . $displayName);
            error_log("INVITER EMAIL/PHONE: " . $inviter_email);

            $inviterName = $displayName;
        }
    }

}

$inviterName = $displayName;
$websiteUrl = trim((string) ($mailConfig['website_url'] ?? 'https://drivault.example.com'));
$supportEmail = trim((string) ($mailConfig['support_email'] ?? 'support@drivault.example.com'));
$googlePlayLink = trim((string) ($mailConfig['google_play_link'] ?? 'https://play.google.com/store'));
$appStoreLink = trim((string) ($mailConfig['app_store_link'] ?? 'https://www.apple.com/app-store/'));
$logoUrl = "http://103.174.148.208/assets/Photos/icon-192.png";
$googlePlayBadgeUrl = "http://103.174.148.208/assets/Photos/googlePlay.png";
$appStoreBadgeUrl = "http://103.174.148.208/assets/Photos/Apple%20store.png";
$brandIconPath = __DIR__ . '/../assets/Photos/icon-192.png';
$googlePlayImagePath = __DIR__ . '/../assets/Photos/googlePlay.png';
$appStoreImagePath = __DIR__ . '/../assets/Photos/Apple store.png';

if (
    $smtpHost === '' ||
    $smtpPort <= 0 ||
    $smtpUsername === '' ||
    $smtpPassword === '' ||
    strtolower($smtpUsername) === 'your_email@gmail.com' ||
    $smtpPassword === 'your_app_password'
) {
    http_response_code(500);
    exit('SMTP is not configured. Update config/mail.php with your real mail credentials.');
}

try {
    $transactionStarted = false;
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();
    $transactionStarted = true;

$statement = $conn->prepare(
    'INSERT INTO users (
        name,
        email,
        phone,
        invite_token,
        otp,
        otp_expiry,
        is_verified,
        role,
        inviter,
        inviter_email,
        invite_accepted
    )
    VALUES (?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?, ?)
    '
);

$userRole = 'user';
$inviteAccepted = 'no';

$statement->bind_param(
    'ssssssss',
    $name,
    $email,
    $phone,
    $token,
    $userRole,
    $inviterName,
    $inviter_email,
    $inviteAccepted
);

    $statement->execute();
    $statement->close();

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = $smtpEncryption === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $smtpPort;
    $mail->setFrom($smtpUsername, $smtpFromName);
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
  
   $mail->Subject = "You've Been Invited to Join Drivault";

$mail->Body = sprintf(

'<div style="font-family:Arial,sans-serif;padding:16px;background:#f5f7fa;color:#111827;">
<div style="width:100%%;max-width:650px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;box-sizing:border-box;">
<div style="text-align:center;margin-bottom:24px;">
%9$s
</div>

<p style="margin:0 0 16px;">Hello <strong>%1$s</strong>,</p>

<p style="margin:0 0 16px;">
<strong>%2$s</strong> has invited you to join <strong>Drivault</strong>, a secure cloud storage and file-sharing platform designed to help you store, access, manage, and share your files securely from anywhere.
</p>

<p style="margin:0 0 24px;">
To activate your account and start using Drivault, please accept the invitation by clicking the button below.
</p>

<div style="text-align:center;margin:28px 0;">
<a href="%3$s" style="background:#43E08B;padding:15px 24px;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;display:inline-block;max-width:100%%;box-sizing:border-box;">
Accept Invitation
</a>
</div>

<div style="background:#eefbf4;padding:18px;margin:0 0 24px;border-left:4px solid #43E08B;border-radius:8px;box-sizing:border-box;">
<h3 style="margin:0 0 12px;color:#111827;">Account Information</h3>
<p style="margin:0 0 8px;">Username: Create during account activation</p>
<p style="margin:0;">Password: Create during account activation</p>
</div>

<h3 style="margin:0 0 12px;color:#111827;">Why Use Drivault?</h3>
<ul style="margin:0 0 24px;padding-left:0;list-style:none;">
<li style="margin:0 0 6px;">Secure cloud file storage</li>
<li style="margin:0 0 6px;">Access files from any device</li>
<li style="margin:0 0 6px;">Safe file sharing and collaboration</li>
<li style="margin:0 0 6px;">Protected user authentication</li>
<li style="margin:0;">Reliable file synchronization</li>
</ul>

<p style="margin:0 0 16px;">
For security purposes, your mobile number will be verified using a One-Time Password (OTP) during account activation.
</p>

<p style="margin:0 0 24px;">
If you were not expecting this invitation, you may safely ignore this email.
</p>

<div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin:28px 0;text-align:center;box-sizing:border-box;">
<h3 style="margin:0 0 8px;color:#111827;">Download Drivault App</h3>
<p style="margin:0 0 18px;color:#475569;">Install the Drivault mobile app to access your files anytime.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:auto;max-width:100%%;margin:0 auto;border-collapse:collapse;">
<tr>
<td style="padding:0 6px 0 0;text-align:center;">
<a href="%4$s" style="display:inline-block;text-decoration:none;">%10$s</a>
</td>
<td style="padding:0 0 0 6px;text-align:center;">
<a href="%5$s" style="display:inline-block;text-decoration:none;">%11$s</a>
</td>
</tr>
</table>
</div>

<p style="margin:0 0 16px;">Thank you,</p>

<p style="margin:0;">
<strong>Drivault Team</strong><br>
Secure Cloud Storage &amp; File Sharing Platform
</p>

<hr style="border:none;border-top:1px solid #e5e7eb;margin:28px 0 14px;">
<p style="margin:0;color:#6b7280;font-size:12px;text-align:center;">&copy; 2026 Drivault. All rights reserved.</p>
</div>
</div>',

htmlspecialchars($name,ENT_QUOTES,'UTF-8'),
htmlspecialchars($inviterName,ENT_QUOTES,'UTF-8'),
htmlspecialchars($verificationLink,ENT_QUOTES,'UTF-8'),
htmlspecialchars($googlePlayLink,ENT_QUOTES,'UTF-8'),
htmlspecialchars($appStoreLink,ENT_QUOTES,'UTF-8'),
htmlspecialchars($phone,ENT_QUOTES,'UTF-8'),
htmlspecialchars($websiteUrl,ENT_QUOTES,'UTF-8'),
htmlspecialchars($supportEmail,ENT_QUOTES,'UTF-8'),

'<img src="' . $logoUrl . '" width="70" alt="Drivault" style="display:inline-block;border:0;max-width:70px;height:auto;">',

'<img src="' . $googlePlayBadgeUrl . '" width="160" alt="Google Play" style="display:block;border:0;width:160px;max-width:100%;height:auto;">',

'<img src="' . $appStoreBadgeUrl . '" width="160" alt="App Store" style="display:block;border:0;width:160px;max-width:100%;height:auto;">'

);
    $mail->AltBody = sprintf(
        "Hello %s,\n\n"
        . "%s has invited you to join Drivault, a secure cloud storage and file-sharing platform designed to help you store, access, manage, and share your files securely from anywhere.\n\n"
        . "To activate your account and start using Drivault, please accept the invitation by clicking the link below.\n\n"
        . "Accept Invitation:\n%s\n\n"
        . "Account Information\n\n"
        . "Username: %s\n"
        . "Password: Create during account activation\n\n"
        . "Why Use Drivault?\n"
        . "- Secure cloud file storage\n"
        . "- Access files from any device\n"
        . "- Safe file sharing and collaboration\n"
        . "- Protected user authentication\n"
        . "- Reliable file synchronization\n\n"
        . "For security purposes, your mobile number will be verified using a One-Time Password (OTP) during account activation.\n\n"
        . "If you were not expecting this invitation, you may safely ignore this email.\n\n"
        . "Download Drivault App:\n"
        . "Google Play: %s\n"
        . "App Store: %s\n\n"
        . "Thank you,\n\n"
        . "Drivault Team\n"
        . "Secure Cloud Storage & File Sharing Platform\n\n"
        . "(c) 2026 Drivault. All rights reserved.",
        $name,
        $inviterName,
        $verificationLink,
        $phone,
        $googlePlayLink,
        $appStoreLink
    );
    // $mail->send();

    // $conn->commit();
    // exit('Invitation sent successfully.');

    $mail->send();

$conn->commit();

header('Content-Type: application/json');

echo json_encode([
    'status' => true,
    'message' => 'Invitation sent successfully.',
    'inviter_name' => $inviterName,
    'inviter_email' => $inviter_email
]);

exit;
} catch (mysqli_sql_exception $exception) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }

    error_log(
        sprintf(
            '[send_invite] Database error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(500);
    exit(formatDatabaseErrorMessage($exception));
} catch (MailerException $exception) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }

    error_log(
        sprintf(
            '[send_invite] Mailer error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(500);
    exit('Unable to send invitation email: ' . $exception->getMessage());
} catch (Throwable $exception) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }

    error_log(
        sprintf(
            '[send_invite] Unhandled error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(500);
    exit('Unable to process the invitation request.');
}
