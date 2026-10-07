<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';

$testingConfig = require __DIR__ . '/../config/testing.php';

$token = (string) ($_GET['token'] ?? '');
$dummyOtp = (string) ($testingConfig['dummy_otp'] ?? '1234');
$useDummyValues = (bool) ($testingConfig['use_dummy_values'] ?? true);

$name = '';
$email = '';
$phone = '';

if ($token !== '') {

    $statement = $conn->prepare(
        'SELECT name, email, phone FROM users WHERE invite_token = ? LIMIT 1'
    );

    $statement->bind_param('s', $token);

    $statement->execute();

    $result = $statement->get_result();

    $user = $result->fetch_assoc();

    $statement->close();

    if ($user) {

        $name = (string) ($user['name'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $phone = (string) ($user['phone'] ?? '');
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Verify OTP</title>

<link rel="icon" type="image/x-icon" href="/assets/Photos/favicon.ico">

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
    min-height:100vh;
    padding:20px;
}

.container{
    width:100%;
    max-width:480px;
}

.card{
    background:#ffffff;
    border-radius:24px;
    padding:40px;
    box-shadow:0 4px 20px rgba(0,0,0,0.05);
    position:relative;
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

.info-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:18px;
    margin-bottom:25px;
}

.info-box p{
    margin-bottom:10px;
    color:#475569;
    font-size:15px;
}

.info-box p:last-child{
    margin-bottom:0;
}

.info-box strong{
    color:#0f172a;
}

.form-group{
    margin-bottom:22px;
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
    font-size:16px;
    transition:0.3s;
}

input:focus{
    border-color:#4ade80;
    box-shadow:0 0 0 4px rgba(74,222,128,0.15);
}

.helper{
    background:#dcfce7;
    color:#15803d;
    padding:12px;
    border-radius:12px;
    margin-bottom:20px;
    font-size:14px;
}

button{
    width:100%;
    height:54px;

    border:none;
    border-radius:14px;

    background:#4ade80;
    color:#fff;

    font-size:17px;
    font-weight:600;

    cursor:pointer;

    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;

    transition:all .25s ease;
}

button:hover{
    background:#22c55e;
}

button:disabled{
    background:#4ade80;
    cursor:not-allowed;
    opacity:.85;
}
.btn-spinner{
    width:20px;
    height:20px;

    border:3px solid rgba(255,255,255,.35);
    border-top:3px solid #ffffff;

    border-radius:50%;

    animation:spin .7s linear infinite;
}

.footer{
    text-align:center;
    margin-top:22px;
    color:#94a3b8;
    font-size:14px;
}

.hidden{
    display:none;
}

.loader-wrapper{
    display:flex;
    justify-content:center;
    margin-top:20px;
    margin-bottom:20px;
}

.loader{
    width:28px;
    height:28px;
    border:3px solid rgba(74,222,128,0.2);
    border-top-color:#22c55e;
    border-radius:50%;
    animation:spin 1s linear infinite;
    display:none;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.resend-text{
    text-align:center;
    margin-top:18px;
    color:#64748b;
    font-size:14px;
}

.resend-btn{
    background:none;
    border:none;
    color:#22c55e;
    font-weight:600;
    cursor:pointer;
    font-size:14px;
    padding:0;
    width:auto;
}

.resend-btn:hover{
    background:none;
}

.timer{
    color:#ef4444;
    font-weight:600;
}

.toast{
    position:fixed;
    top:20px;
    left:50%;
    transform:translateX(-50%);

    width:90%;
    max-width:320px;

    color:#ffffff;
    padding:14px 16px;
    border-radius:12px;

    font-size:14px;
    font-weight:600;
    text-align:center;
    line-height:1.4;
    word-break:break-word;

    box-sizing:border-box;

    box-shadow:0 8px 20px rgba(0,0,0,0.15);
    z-index:9999;
    opacity:0;
    transition:0.3s;
}

@media (max-width: 480px) {
    .toast {
        width: fit-content;
        max-width: 90%;
        white-space: nowrap;   /* Keeps text on one line */
        padding: 10px 14px;
        font-size: 13px;
    }
}

</style>

</head>

<body>

<div class="container">

    <div class="card">

        <div class="logo">

            <div class="logo-box">
                <img src="/assets/Photos/icon-192.png" alt="Drivault logo">
            </div>

            <h1>Drivault</h1>

        </div>

        <h2 class="title">
            Verify <span>OTP</span>
        </h2>

        <p class="subtitle">
           Congratulations! You've received an invitation to join Drivault. Verify your mobile number to continue with your account setup.
        </p>

        <?php if ($name !== '' || $email !== '' || $phone !== '') { ?>

        <div class="info-box">

            <p>
                <strong>Name:</strong>
                <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <p>
                <strong>Email:</strong>
                <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <p>
                <strong>Phone:</strong>
                <?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>
            </p>

        </div>

        <?php } ?>

        <button id="generateOtpBtn"
        type="button"
        onclick="generateOtp()">

    <span id="generateSpinner"
          class="btn-spinner hidden"></span>

    <span id="generateText">
        Generate OTP
    </span>

</button>

       

        <form
            id="otpForm"
            class="hidden"
        >

            <input
                type="hidden"
                name="token"
                value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>"
            >

            <div class="form-group">

                <label for="otp">
                    Enter OTP
                </label>

                <input
                    id="otp"
                    type="text"
                    name="otp"
                    placeholder="Enter OTP"
                    autocomplete="off"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    maxlength="4"
                    required
                >

            </div>

            <?php if ($useDummyValues) { ?>

            <div class="helper">
                Testing OTP : <?php echo htmlspecialchars($dummyOtp, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <?php } ?>

           <button id="verifyBtn" type="submit">

    <span id="verifySpinner" class="btn-spinner hidden"></span>

    <span id="verifyText">
        Verify OTP
    </span>

</button>

        </form>

        <div
            class="resend-text hidden"
            id="resendSection"
        >

            <span id="timerText">
                Resend OTP in <span class="timer" id="countdown">90</span>s
            </span>

            <button
                type="button"
                class="resend-btn hidden"
                id="resendBtn"
                onclick="resendOtp()"
            >
                Resend OTP
            </button>

        </div>

        <div class="footer">
            Â© 2026 Drivault
        </div>

    </div>

</div>

<script>

let countdownInterval;

function showToast(message, type = 'success') {

    const toast = document.createElement('div');

    toast.className = 'toast';

    toast.innerText = message;

    if(type === 'error') {

        toast.style.background = '#ef4444';

    } else {

        toast.style.background = '#22c55e';
    }

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '1';
    }, 100);

    setTimeout(() => {

        toast.style.opacity = '0';

        setTimeout(() => {
            toast.remove();
        }, 300);

    }, 3000);
}

function startCountdown() {

    let timeLeft = 90;

    const countdown = document.getElementById('countdown');

    const resendBtn = document.getElementById('resendBtn');

    const timerText = document.getElementById('timerText');

    resendBtn.classList.add('hidden');

    timerText.classList.remove('hidden');

    countdown.innerText = timeLeft;

    clearInterval(countdownInterval);

    countdownInterval = setInterval(() => {

        timeLeft--;

        countdown.innerText = timeLeft;

        if(timeLeft <= 0) {

            clearInterval(countdownInterval);

            timerText.classList.add('hidden');

            resendBtn.classList.remove('hidden');
        }

    }, 1000);
}

function generateOtp() {

   const generateBtn = document.getElementById('generateOtpBtn');

const spinner = document.getElementById('generateSpinner');

const text = document.getElementById('generateText');

const otpForm = document.getElementById('otpForm');

const resendSection = document.getElementById('resendSection');

generateBtn.disabled = true;

spinner.classList.remove('hidden');

text.innerHTML = "Generating OTP...";

    generateBtn.disabled = true;

    // loader.style.display = 'block';

    const formData = new FormData();
    formData.append('token', <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>);

    fetch('../api/generate_otp.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(async (response) => {
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.status === false) {
            throw new Error(payload.message || 'Unable to generate OTP');
        }

        // loader.style.display = 'none';
        spinner.classList.add('hidden');

text.innerHTML = "Generate OTP";

generateBtn.style.display = "none";

        showToast(payload.message || "OTP has been sent to your mobile number");

        otpForm.classList.remove('hidden');

        resendSection.classList.remove('hidden');

        startCountdown();
    })
    .catch((error) => {
        // loader.style.display = 'none';
        spinner.classList.add('hidden');

text.innerHTML = "Generate OTP";

generateBtn.disabled = false;
        showToast(error.message || "Unable to generate OTP", "error");
    });
}

function resendOtp() {

    const resendBtn = document.getElementById('resendBtn');

    resendBtn.classList.add('hidden');

    const formData = new FormData();
    formData.append('token', <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>);

    fetch('../api/generate_otp.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(async (response) => {
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.status === false) {
            throw new Error(payload.message || 'Unable to resend OTP');
        }

        showToast(payload.message || "OTP resent successfully");
        startCountdown();
    })
    .catch((error) => {
        showToast(error.message || "Unable to resend OTP", "error");
        resendBtn.classList.remove('hidden');
    });
}

document.getElementById('otpForm').addEventListener('submit', async function(event) {

    event.preventDefault();

   const verifyBtn = document.getElementById('verifyBtn');
const verifySpinner = document.getElementById('verifySpinner');
const verifyText = document.getElementById('verifyText');

const otp = document.getElementById('otp').value;
    verifyBtn.disabled = true;

verifySpinner.classList.remove('hidden');
verifyText.textContent = 'Verifying OTP...';

    // verifyBtn.innerHTML = 'Verifying...';

    const formData = new FormData();

    formData.append('otp', otp);

    formData.append(
        'token',
        <?php echo json_encode($token, JSON_UNESCAPED_UNICODE); ?>
    );

    try {

   await new Promise(resolve => setTimeout(resolve, 2000));

const response = await fetch('../api/verify_otp.php', {
    method: 'POST',
    body: formData
});

    const result = await response.json();

    if (!response.ok) {
        throw new Error(result.message || 'Invalid OTP. Please try again.');
    }

    if (!result.status) {

        showToast(
            result.message || 'Invalid OTP. Please try again.',
            'error'
        );

        verifyBtn.disabled = false;

verifySpinner.classList.add('hidden');
verifyText.textContent = 'Verify OTP';

        document.getElementById('otp').focus();

        return;
    }

    showToast(
        result.message || 'OTP verified successfully'
    );

    verifySpinner.classList.add('hidden');
verifyText.textContent = 'Verifying OTP...';

    setTimeout(() => {

        if (result.redirect) {
            window.location.href = result.redirect;
        } else {
            showToast(
                'Verification successful, but redirect URL is missing.',
                'error'
            );

           verifyBtn.disabled = false;

verifySpinner.classList.add('hidden');
verifyText.textContent = 'Verify OTP';
        }

    }, 1000);

} catch (error) {

    console.error(error);

    showToast(
        error.message || 'Something went wrong. Please try again.',
        'error'
    );

    verifyBtn.disabled = false;

verifySpinner.classList.add('hidden');
verifyText.textContent = 'Verify OTP';

    document.getElementById('otp').focus();
}});

</script>

</body>
</html>
