<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

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

$otp = trim((string) ($_POST['otp'] ?? ''));
$token = trim((string) ($_POST['token'] ?? ''));

if ($otp === '' || $token === '') {

    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'OTP and token are required.'
    ]);

    exit;
}

$statement = $conn->prepare(
    'SELECT id, email FROM users 
     WHERE invite_token = ? 
     AND otp = ? 
     AND otp_expiry > NOW() 
     LIMIT 1'
);

$statement->bind_param('ss', $token, $otp);

$statement->execute();

$result = $statement->get_result();

$user = $result->fetch_assoc();

$statement->close();

if (!$user) {

    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => 'Invalid OTP'
    ]);

    exit;
}

if (emailExistsOnAnotherUser($conn, (string) ($user['email'] ?? ''), (int) $user['id'])) {

    http_response_code(409);

    echo json_encode([
        'status' => false,
        'error' => 'EMAIL_ALREADY_EXISTS',
        'message' => 'This email already exists. Account cannot be created.',
        'field' => 'email'
    ]);

    exit;
}

$updateStatement = $conn->prepare(
    'UPDATE users 
     SET is_verified = 1 
     WHERE id = ?'
);

$updateStatement->bind_param('i', $user['id']);

$updateStatement->execute();

$updateStatement->close();

$_SESSION['verified_user_id'] = (int) $user['id'];

$_SESSION['verified_invite_token'] = $token;

echo json_encode([
    'status' => true,
    'message' => 'OTP verified successfully',
    'redirect' => '../pages/set-password.php?token=' . urlencode($token)
]);

exit;
