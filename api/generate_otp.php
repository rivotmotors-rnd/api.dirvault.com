<?php

declare(strict_types=1);

require __DIR__ . '/../config/db.php';

$testingConfig = require __DIR__ . '/../config/testing.php';

$smsConfig = [
    'enabled' => true,

    'gateway_url' => 'https://api.kapsystem.com/sms/1/text/query',

    'username' => 'outofboxcloud',

    'password' => '0utofboX!211',

    'from' => 'DRVULT',

    'india_dlt_content_template_id' => '1607100000000384119',

    'india_dlt_principal_entity_id' => '1601483163299673613',

    'india_dlt_telemarketer_id' => '1602100000000004471',
];




$logFile = '/var/www/html/api/logs/app.log';

function writeLog(string $message): void
{
    global $logFile;

    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function findInvite(mysqli $conn, string $token): ?array
{
    $statement = $conn->prepare('SELECT id, email, phone FROM users WHERE invite_token = ? LIMIT 1');
    $statement->bind_param('s', $token);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result->fetch_assoc();
    $statement->close();

    return $user ?: null;
}

function emailExistsOnAnotherUser(mysqli $conn, string $email, int $currentUserId): bool
{
    $normalizedEmail = normalizeEmail($email);

    $statement = $conn->prepare(
        'SELECT id
         FROM users
         WHERE LOWER(TRIM(email)) = ?
           AND id <> ?
         LIMIT 1'
    );
    $statement->bind_param('si', $normalizedEmail, $currentUserId);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result->fetch_assoc();
    $statement->close();

    return (bool) $user;
}
function sendOtpSms(string $phone, string $otp, array $smsConfig): array
{
writeLog('sendOtpSms() called');
writeLog('Phone: ' . $phone);
writeLog('OTP: ' . $otp);

    if (empty($smsConfig['enabled'])) {
        return [
            'sent' => false,
            'response' => 'SMS gateway disabled.',
        ];
    }

    $gatewayUrl = trim((string) ($smsConfig['gateway_url'] ?? ''));
    $username   = trim((string) ($smsConfig['username'] ?? ''));
    $password   = trim((string) ($smsConfig['password'] ?? ''));

    if (
        $gatewayUrl === '' ||
        $username === '' ||
        $password === ''
    ) {
        throw new RuntimeException('SMS gateway is not configured.');
    }

    // $message =
    //     'Dear Customer, ' . $otp .
    //     ' is the OTP to pair your RIVOT One app with your RIVOT nx100. ' .
    //     'Do not share this code. Thank you - RIVOT';
//     $message =
// 'Welcome to Drivault. Your OTP is ' . $otp .
// '. Use this code to verify your identity and complete your account registration. ' .
// 'Do not share this OTP with anyone. – Drivault';
$message =
    'Welcome to Drivault. Your OTP is ' . $otp .
    '. Use this code to verify your identity and complete your account registration. Drivault';


    $mobile = '91' . preg_replace('/[^0-9]/', '', $phone);
writeLog('Mobile: ' . $mobile);
writeLog('Gateway URL: ' . $gatewayUrl);
writeLog('Username: ' . $username);

   $url =
    $gatewayUrl .
    '?username=' . urlencode($username) .
    '&password=' . urlencode($password) .
    '&from=' . urlencode($smsConfig['from']) .
    '&to=' . $mobile .
    '&text=' . urlencode($message) .
    '&indiaDltContentTemplateId=' . urlencode($smsConfig['india_dlt_content_template_id']) .
    '&indiaDltPrincipalEntityId=' . urlencode($smsConfig['india_dlt_principal_entity_id']) .
    '&indiaDltTelemarketerId=' . urlencode($smsConfig['india_dlt_telemarketer_id']);

writeLog('SMS URL: ' . $url);
    // Send SMS
 $response = @file_get_contents($url);

if ($response === false) {
    writeLog('file_get_contents FAILED');

    $error = error_get_last();
    if ($error) {
        writeLog('PHP Error: ' . $error['message']);
    }

    throw new RuntimeException('Unable to send OTP SMS.');
}

writeLog('SMS Response: ' . $response);

    if ($response === false) {
        throw new RuntimeException('Unable to send OTP SMS.');
    }

    return [
        'sent' => true,
        'response' => $response,
    ];
}
$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));

if ($token === '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        jsonResponse(422, [
            'status' => false,
            'message' => 'Token is required.',
        ]);
    }

    http_response_code(422);
    exit('Token is required.');
}

$invite = findInvite($conn, $token);

if ($invite === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        jsonResponse(404, [
            'status' => false,
            'message' => 'Invitation token not found.',
        ]);
    }

    http_response_code(404);
    exit('Invitation token not found.');
}

$inviteId = (int) ($invite['id'] ?? 0);
$email = trim((string) ($invite['email'] ?? ''));
$phone = trim((string) ($invite['phone'] ?? ''));

if ($email !== '' && emailExistsOnAnotherUser($conn, $email, $inviteId)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        jsonResponse(409, [
            'status' => false,
            'error' => 'EMAIL_ALREADY_EXISTS',
            'message' => 'This email already exists. Account cannot be created.',
            'field' => 'email',
        ]);
    }

    http_response_code(409);
    exit('This email already exists. Account cannot be created.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: ../pages/verify-otp.php?token=' . urlencode($token));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'status' => false,
        'message' => 'Method not allowed. Use POST.',
    ]);
}

if ($phone === '') {
    jsonResponse(422, [
        'status' => false,
        'message' => 'Phone number is required.',
    ]);
}

$useDummyValues = (bool) ($testingConfig['use_dummy_values'] ?? false);
$otp = $useDummyValues
    ? (string) ($testingConfig['dummy_otp'] ?? '1234')
    : (string) random_int(1000, 9999);

if (preg_match('/^\d{4}$/', $otp) !== 1) {
    jsonResponse(500, [
        'status' => false,
        'message' => 'Configured OTP must be exactly 4 digits.',
    ]);
}

try {
    $statement = $conn->prepare(
        'UPDATE users
         SET otp = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
         WHERE invite_token = ?'
    );
    $statement->bind_param('ss', $otp, $token);
    $statement->execute();
    $statement->close();

    $smsResult = sendOtpSms($phone, $otp, $smsConfig);

    jsonResponse(200, [
        'status' => true,
        'message' => !empty($smsResult['sent'])
            ? 'OTP sent to your mobile number.'
            : 'OTP generated. SMS gateway is disabled.',
        'sms_sent' => (bool) ($smsResult['sent'] ?? false),
    ]);
} catch (Throwable $exception) {
    error_log(
        sprintf(
            '[generate_otp] Error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    jsonResponse(500, [
        'status' => false,
        'message' => $exception->getMessage(),
    ]);
}
