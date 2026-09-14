<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

require 'db.php';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id = trim($_POST['student_id'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    // --- validation ---
    if ($student_id === '' || $username === '' || $email === '' || $password === '') {
        $errors[] = 'All fields are required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address is not valid.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // --- check for existing student ID or username ---
    if (empty($errors)) {

        $stmt = $pdo->prepare(
            'SELECT student_id, username
             FROM users
             WHERE student_id = ? OR username = ?'
        );

        $stmt->execute([$student_id, $username]);

        if ($stmt->fetch()) {
            $errors[] = 'That Student ID or username is already registered.';
        }
    }

    // --- create the new account ---
    if (empty($errors)) {

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {

            $stmt = $pdo->prepare(
                'INSERT INTO students (student_id, password_hash, role)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$student_id, $hash, 'student']);

            $stmt = $pdo->prepare(
                'INSERT INTO users (student_id, username, email)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$student_id, $username, $email]);

            $pdo->commit();
            $success = true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register a Student</title>
<style>
    :root {
        --ink: #1c1c1c;
        --paper: #faf9f6;
        --line: #d9d6cf;
        --accent: #1E5EBA;
        --error: #a3342a;
        --success: #1e6b46;
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
        position: relative;
    }
    body::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background-image:
            linear-gradient(rgba(20, 18, 14, 0.55), rgba(20, 18, 14, 0.55)),
            url('PLM_FACADE.jpg');
        background-repeat: no-repeat;
        background-position: center;
        background-size: cover;
        background-attachment: fixed;
    }
    .card {
        width: 100%;
        max-width: 400px;
        border: 1px solid var(--line);
        padding: 40px 36px;
        background: #fff;
        position: relative;
        z-index: 1;
    }
    .admin-badge {
        display: inline-block;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #eaf1ee;
        color: var(--accent);
        padding: 4px 10px;
        margin-bottom: 14px;
    }
    h1 {
        font-size: 22px;
        font-weight: 400;
        letter-spacing: 0.02em;
        margin: 0 0 6px;
    }
    .sub {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        color: #6b6b6b;
        margin: 0 0 28px;
    }
    label {
        display: block;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #6b6b6b;
        margin-bottom: 6px;
        margin-top: 18px;
    }
    input {
        width: 100%;
        padding: 10px 0;
        border: none;
        border-bottom: 1px solid var(--line);
        background: transparent;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 15px;
        color: var(--ink);
        outline: none;
    }
    input:focus {
        border-bottom-color: var(--accent);
    }
    img {
        display: block;
        margin-left: auto;
        margin-right: auto;
        margin-top: 0px;
    }
    button {
        width: 100%;
        margin-top: 30px;
        padding: 12px;
        background: var(--accent);
        color: #fff;
        border: none;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 14px;
        letter-spacing: 0.02em;
        cursor: pointer;
    }
    button:hover { opacity: 0.9; }
    .msg {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        padding: 12px 14px;
        margin-bottom: 4px;
    }
    .msg.error   { background: #fbeceb; color: var(--error); }
    .msg.success { background: #eaf1ee; color: var(--success); }
    .foot {
        margin-top: 22px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        color: #6b6b6b;
        text-align: center;
    }
    .foot a { color: var(--accent); }
</style>
</head>
<body>
    <div class="card">
        <img src="PLM_LOGO.png" height="150" width="150">
        <br>
        <span class="admin-badge">Admin action</span>
        <h1>Register a New User</h1>
        <p class="sub">Create a user account on their behalf.</p>

        <?php if ($success): ?>

            <div class="msg success">
                User account created successfully.
            </div>

            <form method="GET" action="register.php">
                <button type="submit">Register another user</button>
            </form>

        <?php else: ?>

            <?php foreach ($errors as $error): ?>
                <div class="msg error"><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>

            <form method="POST" action="register.php">

                <label for="student_id">Student Number</label>
                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>"
                    required
                >

                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    required
                >

                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                >

                <label for="password">Temporary Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >

                <label for="confirm_password">Confirm password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    required
                >

                <button type="submit">Create Student Account</button>

            </form>

        <?php endif; ?>

        <p class="foot">
            <a href="admin_library.php">&larr; Back to admin library</a>
        </p>
    </div>
</body>
</html>