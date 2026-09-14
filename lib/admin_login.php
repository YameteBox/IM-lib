<?php
session_start();

require 'db.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation
    if ($login === '' || $password === '') {
        $errors[] = 'All fields are required.';
    }

    if (empty($errors)) {

        // Find the user using username OR admin ID
        $stmt = $pdo->prepare('
            SELECT
                admin_users.admin_id,
                admin_users.username,
                admins.password_hash,
                admins.role
            FROM admin_users
            INNER JOIN admins
                ON admin_users.admin_id = admins.admin_id
            WHERE admin_users.username = ?
               OR admin_users.admin_id = ?
        ');

        $stmt->execute([$login, $login]);

        $user = $stmt->fetch();

        // Check password
        if ($user && password_verify($password, $user['password_hash'])) {

            $_SESSION['admin_id'] = $user['admin_id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role']       = $user['role'];

            header('Location: admin_welcome.php');
            exit;

        } else {
            $errors[] = 'Invalid username/admin ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in</title>
<style>
    :root {
        --ink: #1c1c1c;
        --paper: #faf9f6;
        --line: #d9d6cf;
        --accent: #1E5EBA;
        --error: #a3342a;
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
    .msg.error { background: #fbeceb; color: var(--error); }
    .msg.success { background: #eaf1ee; color: var(--accent); }
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
        <h1>Welcome Back</h1>
        <p class="sub">Books books books</p>

        <?php foreach ($errors as $error): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="admin_login.php">
            <label for="login">Admin ID</label>
            <input type="text" id="login" name="login"
                   value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Log In</button>
        </form>

        <p class="foot">Are you a user? <a href="login.php"> Log in</a></p>

    </div>
</body>
</html>
