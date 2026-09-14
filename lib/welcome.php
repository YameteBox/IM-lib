<?php
session_start();

// If nobody is actually logged in, don't let them view this page
if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome</title>
<style>
    :root {
        --ink: #1c1c1c;
        --paper: #faf9f6;
        --line: #d9d6cf;
        --accent: #1E5EBA;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--paper);
        font-family: 'Georgia', 'Iowan Old Style', serif;
        color: var(--ink);
        padding: 24px;
		background-image: url('PLM_FACADE.jpg'); 
		background-repeat: no-repeat;
        background-position: center;
		background-size: cover;
		background-attachment: fixed; 
    }
    .card {
        width: 100%;
        max-width: 380px;
        border: 1px solid var(--line);
        padding: 40px 36px;
        background: #fff;
        text-align: center;
    }
    h1 {
        font-size: 22px;
        font-weight: 400;
        margin: 0 0 6px;
    }
    .sub {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        color: #6b6b6b;
        margin: 0 0 28px;
    }
    img {
        display: block;
        margin-left: auto;
        margin-right: auto;
        margin-top: 0px;
    }
    .role {
        display: inline-block;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #eaf1ee;
        color: var(--accent);
        padding: 6px 12px;
        margin-bottom: 24px;
    }
    /* Row that holds the "Browse library" and "Log out" links side by side */
    .links-row {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-top: 20px;
    }
    a.logout {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        color: var(--accent);
        text-decoration: none;
    }
    a.logout:hover { text-decoration: underline; }
</style>
</head>
<body>
    <div class="card">
        <img src="PLM_LOGO.png" height ="150" width ="150">
        <h1>You're logged in, Student #<?= htmlspecialchars($_SESSION['username']) ?>!</h1>
        <p class="sub">Welcome back.</p>
        <span class="role"><?= htmlspecialchars($_SESSION['role']) ?></span>

        <!-- New: link into the library page, alongside the existing logout link -->
        <div class="links-row">
            <a class="logout" href="library.php">Browse the library</a>
            <a class="logout" href="register.php">Log out</a>
        </div>
    </div>
</body>
</html>