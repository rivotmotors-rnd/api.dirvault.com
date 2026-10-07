<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/db.php';

$drivaultConfig = require __DIR__ . '/../config/drivault.php';

function extractDrivaultErrorMessage(string $responseBody): string
{
    $trimmed = trim($responseBody);

    if ($trimmed === '') {
        return 'Empty response from Drivault.';
    }

    $xml = @simplexml_load_string($trimmed);
    if ($xml !== false && isset($xml->meta->message)) {
        return trim((string) $xml->meta->message) ?: 'Drivault request failed.';
    }

    $json = json_decode($trimmed, true);
    if (is_array($json)) {
        $message = $json['ocs']['meta']['message'] ?? $json['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }
    }

    return 'Drivault request failed.';
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
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
    $existingUser = $result->fetch_assoc();
    $statement->close();

    return (bool) $existingUser;
}

$plainPassword = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$token = trim((string) ($_POST['token'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$wantsJsonResponse = strtolower((string) ($_POST['response_type'] ?? '')) === 'json';
$verifiedUserId = isset($_SESSION['verified_user_id']) ? (int) $_SESSION['verified_user_id'] : 0;
$verifiedInviteToken = (string) ($_SESSION['verified_invite_token'] ?? '');

if ($token === '' && $verifiedUserId <= 0) {
    http_response_code(422);
    exit('Invalid token.');
}
if ($username === '') {
    http_response_code(422);
    exit('Username is required.');
}

if (!preg_match('/^[a-zA-Z0-9._-]{4,20}$/', $username)) {
    http_response_code(422);
    exit(
        'Username must be 4-20 characters and can contain only letters, numbers, dot, underscore and hyphen.'
    );
}
if ($plainPassword === '' || $confirmPassword === '') {
    http_response_code(422);
    exit('Password and confirm password are required.');
}

if ($plainPassword !== $confirmPassword) {
    http_response_code(422);
    exit('Password and confirm password do not match.');
}

$passwordPattern =
'/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{10,}$/';
if (preg_match($passwordPattern, $plainPassword) !== 1) {
    http_response_code(422);
    exit(
'Password must be at least 10 characters and include uppercase, lowercase, number, and special character.'
);
}

if ($verifiedUserId > 0) {
    $userStatement = $conn->prepare(
        'SELECT id, name, email, phone, invite_token, inviter_email FROM users WHERE id = ? LIMIT 1'
    );
    $userStatement->bind_param('i', $verifiedUserId);
} else {
    $userStatement = $conn->prepare(
        'SELECT id, name, email, phone, invite_token, inviter_email FROM users WHERE invite_token = ? LIMIT 1'
    );
    $userStatement->bind_param('s', $token);
}

$userStatement->execute();
$userResult = $userStatement->get_result();
$user = $userResult->fetch_assoc();
$userStatement->close();

if (!$user) {
    http_response_code(404);
    exit('Invitation token not found.');
}

if ($verifiedInviteToken !== '' && (string) ($user['invite_token'] ?? '') !== $verifiedInviteToken) {
    http_response_code(409);
    exit('This invitation link has been replaced by a newer invite.');
}

$invitedEmail = trim((string) ($user['email'] ?? ''));

if ($invitedEmail !== '' && emailExistsOnAnotherUser($conn, $invitedEmail, (int) $user['id'])) {
    http_response_code(409);
    exit('This email already exists. Account cannot be created.');
}

$endpoint = trim((string) ($drivaultConfig['endpoint'] ?? ''));
$apiUsername = trim((string) ($drivaultConfig['username'] ?? ''));
$apiPassword = trim((string) ($drivaultConfig['password'] ?? ''));

if ($endpoint === '' || $apiUsername === '' || $apiPassword === '') {
    http_response_code(500);
    exit('Drivault API is not configured.');
}

/*
==================================
CHECK IF USERNAME EXISTS IN DRIVAULT
==================================
*/

$checkUrl =
    'https://login.drivault.com/ocs/v1.php/cloud/users?search=' .
    urlencode($username);

$checkCurl = curl_init();

curl_setopt_array($checkCurl, [

    CURLOPT_URL => $checkUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,

    CURLOPT_USERPWD =>
        $apiUsername . ':' . $apiPassword,

    CURLOPT_HTTPHEADER => [

        'OCS-APIRequest: true',
        'Accept: application/json'

    ]

]);

$checkResponse = curl_exec($checkCurl);

if ($checkResponse === false) {

    curl_close($checkCurl);

    http_response_code(500);

    exit('Unable to verify username.');
}

curl_close($checkCurl);

$responseData = json_decode(
    $checkResponse,
    true
);

if (
    isset(
        $responseData['ocs']['data']['users']
    )
) {

    $existingUsers =
        $responseData['ocs']['data']['users'];

    foreach (
        $existingUsers as $existingUser
    ) {

        if (
            strtolower($existingUser) ===
            strtolower($username)
        ) {

            http_response_code(422);

            exit(
                'Username already exists. Please choose another username.'
            );
        }
    }
}

$passwordHash = password_hash(
    $plainPassword,
    PASSWORD_BCRYPT
);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->begin_transaction();

    // Mark the invitation as accepted locally before syncing to Drivault.


    // Create the invited user's Drivault account.
    $curlHandle = curl_init($endpoint);
    curl_setopt_array($curlHandle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'displayName' => (string) ($user['name'] ?? ''),
            'userid' => $username,
            'email' => (string) ($user['email'] ?? ''),
            'password' => $plainPassword,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $apiUsername . ':' . $apiPassword,
        CURLOPT_HTTPHEADER => [
            'OCS-APIRequest: true',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $responseBody = curl_exec($curlHandle);
    $curlError = curl_error($curlHandle);
    $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
    curl_close($curlHandle);

    if ($responseBody === false) {
        throw new RuntimeException('Unable to connect to Drivault: ' . $curlError);
    }

    $responseText = (string) $responseBody;
    $xml = @simplexml_load_string($responseText);
    $ocsStatus = $xml !== false ? strtolower(trim((string) ($xml->meta->status ?? ''))) : '';
    $statusCode = $xml !== false ? (int) ($xml->meta->statuscode ?? 0) : 0;

    if ($httpStatus >= 400 || ($ocsStatus !== '' && $ocsStatus !== 'ok') || ($statusCode !== 0 && $statusCode !== 100)) {
        throw new RuntimeException(extractDrivaultErrorMessage($responseText));
    }
    $updateStatement = $conn->prepare(
    'UPDATE users
     SET password = ?,
         username = ?,
         is_active = 1,
         invite_accepted = ?
     WHERE id = ?'
);

$inviteAccepted = 'yes';

$updateStatement->bind_param(
    'sssi',
    $passwordHash,
    $username,
    $inviteAccepted,
    $user['id']
);
$updateStatement->execute();

if ($updateStatement->affected_rows < 0) {
    throw new RuntimeException(
        'Failed to update invitation status.'
    );
}

$updateStatement->close();
    // // Reward only the inviter by increasing the inviter's quota.
    // $inviterUserId = trim((string) ($user['inviter_email'] ?? ''));
    // $inviterUserId = preg_replace('/[^A-Za-z0-9@._-]/', '', $inviterUserId) ?? '';

$inviterUserId = trim(
    (string) ($user['inviter_email'] ?? '')
);

if ($inviterUserId !== '') {

    error_log(
        "Reward User: " .
        $inviterUserId
    );

    error_log(
        "Inviter User: "
        . $inviterUserId
    );

    error_log(
        "Inviter User: "
        . $inviterUserId
    );

    /*
    ==================================
    GET CURRENT QUOTA
    ==================================
    */

    $getUserCurl = curl_init(
        $endpoint .
        '/' .
        rawurlencode(
            $inviterUserId
        )
    );

    curl_setopt_array(
        $getUserCurl,
        [

        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,

        CURLOPT_USERPWD =>
            $apiUsername .
            ':' .
            $apiPassword,

        CURLOPT_HTTPHEADER => [
            'OCS-APIRequest: true',
        ],

    ]);

    $getUserResponse =
        curl_exec(
            $getUserCurl
        );

    curl_close(
        $getUserCurl
    );

    /*
    ==================================
    CURRENT STORAGE
    ==================================
    */

    $currentQuotaGb = 100; // default user storage

if ($getUserResponse !== false) {

    $quotaXml =
        @simplexml_load_string(
            $getUserResponse
        );

    $currentQuotaBytes =
        (int)(
            $quotaXml
            ->data
            ->quota
            ->quota ?? 0
        );

    if ($currentQuotaBytes > 0) {

        $currentQuotaGb =
            round(
                $currentQuotaBytes /
                1024 /
                1024 /
                1024
            );
    }

    error_log(
        "Current Bytes: "
        . $currentQuotaBytes
    );

    error_log(
        "Current GB: "
        . $currentQuotaGb
    );
}

    /*
    ==================================
    ADD REWARD
    ==================================
    */

    $rewardGb = 100;

    $newQuotaGb =
        $currentQuotaGb +
        $rewardGb;

    error_log(
        "Current: "
        . $currentQuotaGb
        . "GB"
    );

    error_log(
        "Reward: +"
        . $rewardGb
        . "GB"
    );

    error_log(
        "Final: "
        . $newQuotaGb
        . "GB"
    );

    /*
    ==================================
    UPDATE QUOTA
    ==================================
    */

    $quotaCurlHandle =
        curl_init(
            $endpoint .
            '/' .
            rawurlencode(
                $inviterUserId
            )
        );

    curl_setopt_array(
        $quotaCurlHandle,
        [

        CURLOPT_CUSTOMREQUEST =>
            'PUT',

        CURLOPT_POSTFIELDS =>
            http_build_query([
                'key' => 'quota',
                'value' =>
                    $newQuotaGb
                    .'GB'
            ]),

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_TIMEOUT =>
            30,

        CURLOPT_HTTPAUTH =>
            CURLAUTH_BASIC,

        CURLOPT_USERPWD =>
            $apiUsername .
            ':' .
            $apiPassword,

        CURLOPT_HTTPHEADER => [

            'OCS-APIRequest: true',

            'Content-Type: application/x-www-form-urlencoded',
        ],

    ]);

    $quotaResponseBody =
        curl_exec(
            $quotaCurlHandle
        );

    curl_close(
        $quotaCurlHandle
    );

    error_log(
        "Quota Updated: "
        . $newQuotaGb
        . "GB"
    );
}


    $_SESSION['account_created_details'] = [
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'phone' => (string) ($user['phone'] ?? ''),
        'password' => $plainPassword,
    ];

    unset($_SESSION['verified_user_id'], $_SESSION['verified_invite_token']);

    $conn->commit();

    $successRedirect = '../pages/set-password.php?token=' . urlencode((string) ($user['invite_token'] ?? $token));

    if ($wantsJsonResponse) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully.',
            'redirect' => $successRedirect,
        ]);
        exit;
    }

    header('Location: ' . $successRedirect);
    exit;
} catch (Throwable $exception) {
    $conn->rollback();

    error_log(
        sprintf(
            '[set_password] Drivault sync error: %s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    http_response_code(500);
    exit('Unable to create Drivault user: ' . $exception->getMessage());
}
