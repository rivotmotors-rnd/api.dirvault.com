<?php
declare(strict_types=1);

session_start();

$sessionInviterEmail = trim((string) ($_SESSION['admin_email'] ?? ''));
$inviterEmail = trim((string) ($_GET['inviter_email'] ?? $sessionInviterEmail));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Send Invite</title>

<link rel="icon" type="image/x-icon" href="/php_invitation_system/assets/Photos/favicon.ico">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Inter',sans-serif;
}

body{
    background:#f5f7f9;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    padding:20px;
}

.container{
    width:100%;
    max-width:450px;
}

.card{
    background:#ffffff;
    border-radius:24px;
    padding:40px;
    box-shadow:0 4px 20px rgba(0,0,0,0.05);
}

.logo{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:25px;
}

.logo-box{
    width:52px;
    height:52px;
    border-radius:16px;
    background:#ecfdf5;
    border:1px solid #d1fae5;
    box-shadow:0 10px 24px rgba(74,222,128,0.18);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:8px;
}

.logo-box img{
    width:100%;
    height:100%;
    object-fit:contain;
}

.logo h1{
    font-size:24px;
    color:#0f172a;
    font-weight:700;
}

.title{
    font-size:30px;
    font-weight:700;
    color:#0f172a;
    margin-bottom:8px;
}

.title span{
    color:#4ade80;
}

.subtitle{
    color:#64748b;
    margin-bottom:30px;
    font-size:15px;
}

.form-group{
    margin-bottom:20px;
}

label{
    display:block;
    margin-bottom:8px;
    color:#0f172a;
    font-weight:500;
}

input{
    width:100%;
    padding:14px 16px;
    border:1px solid #e2e8f0;
    border-radius:14px;
    outline:none;
    font-size:15px;
    transition:0.3s;
    background:#fff;
}

input:focus{
    border-color:#4ade80;
    box-shadow:0 0 0 4px rgba(74,222,128,0.15);
}

button{
    width:100%;
    padding:15px;
    border:none;
    border-radius:14px;
    background:#4ade80;
    color:white;
    font-size:16px;
    font-weight:600;
    cursor:pointer;
    transition:0.3s;
}

button:hover{
    background:#22c55e;
}

.footer{
    text-align:center;
    margin-top:20px;
    color:#94a3b8;
    font-size:14px;
}

</style>
</head>

<body>

<div class="container">

    <div class="card">

        <div class="logo">
            <div class="logo-box">
                <img src="/php_invitation_system/assets/Photos/icon-192.png" alt="Drivault logo">
            </div>
            <h1>Drivault</h1>
        </div>

        <h2 class="title">
            Send <span>Invitation</span>
        </h2>

        <p class="subtitle">
            Invite users quickly with a simple secure form.
        </p>

        <form action="../api/send_invite.php" method="POST">
            <input
                type="hidden"
                name="inviter_email"
                value="<?php echo htmlspecialchars($inviterEmail, ENT_QUOTES, 'UTF-8'); ?>"
            >

            <div class="form-group">
                <label for="name">Full Name</label>
                <input id="name" type="text" name="name" placeholder="Enter full name" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input id="email" type="email" name="email" placeholder="Enter email address" required>
            </div>

            <div class="form-group">
                <label for="phone">Mobile Number</label>
                <input id="phone" type="tel" name="phone" placeholder="Enter mobile number" required>
            </div>

            <button type="submit">
                Send Invite
            </button>

        </form>

        <div class="footer">
            <!-- © 2026 Drivault -->
        </div>

    </div>

</div>

</body>
</html>
