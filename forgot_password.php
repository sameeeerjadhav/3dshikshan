<?php
require_once 'config.php';
session_start();

// If already logged in, redirect to index
if (isset($_SESSION['user']['role'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - 3D Shikshan</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #007664;
            --primary-hover: #006052;
            --surface: #ffffff;
            --bg: #f5f7f9;
            --text: #333333;
            --text-light: #666666;
            --border: #e0e0e0;
            --error: #d32f2f;
            --success: #2e7d32;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .auth-card {
            background: var(--surface);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 450px;
            padding: 40px 30px;
            text-align: center;
        }
        .auth-logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
        .auth-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .auth-sub {
            color: var(--text-light);
            font-size: 0.95rem;
            margin-bottom: 25px;
            line-height: 1.4;
        }
        .f-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .f-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .f-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .f-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover {
            background: var(--primary-hover);
        }
        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .auth-links {
            margin-top: 20px;
            font-size: 0.9rem;
        }
        .auth-links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .auth-links a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: none;
        }
        .alert.error {
            background: #ffebee;
            color: var(--error);
            border: 1px solid #ffcdd2;
        }
        .alert.success {
            background: #e8f5e9;
            color: var(--success);
            border: 1px solid #c8e6c9;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <img src="assets/logo.png" alt="3D Shikshan" class="auth-logo" style="height: 100px; object-fit: contain; margin-bottom: 15px;">
    <h1 class="auth-title">Reset Password</h1>
    <p class="auth-sub">Enter your registered email address and we'll send you a link to reset your password.</p>

    <div id="alertBox" class="alert"></div>

    <form id="forgotPasswordForm">
        <div class="f-group">
            <label for="email">Email Address / Login ID</label>
            <input type="email" id="email" name="email" placeholder="e.g. admin@3dshikshan.com" required>
        </div>
        <button type="submit" class="btn-submit" id="submitBtn">Send Reset Link</button>
    </form>

    <div class="auth-links">
        <a href="index.html"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
    document.getElementById('forgotPasswordForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const email = document.getElementById('email').value.trim();
        const submitBtn = document.getElementById('submitBtn');
        const alertBox = document.getElementById('alertBox');
        
        if (!email) return;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
        alertBox.style.display = 'none';
        alertBox.className = 'alert';
        
        try {
            const res = await fetch('api/forgot_password_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email })
            });
            const data = await res.json();
            
            alertBox.style.display = 'block';
            if (data.ok) {
                alertBox.className = 'alert success';
                alertBox.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + (data.message || 'Reset link sent successfully!');
                document.getElementById('forgotPasswordForm').reset();
            } else {
                alertBox.className = 'alert error';
                alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + (data.error || 'Unable to process request.');
            }
        } catch (err) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert error';
            alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Network error. Please try again later.';
        }
        
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Send Reset Link';
    });
</script>

</body>
</html>
