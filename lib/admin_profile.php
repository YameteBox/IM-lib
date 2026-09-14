<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

require 'db.php';

$adminId = $_SESSION['admin_id'];

// --- Profile details ---
$stmt = $pdo->prepare('
    SELECT
        admin_users.admin_id,
        admin_users.username,
        admin_users.email,
        admins.role
    FROM admin_users
    INNER JOIN admins ON admin_users.admin_id = admins.admin_id
    WHERE admin_users.admin_id = ?
');
$stmt->execute([$adminId]);
$profile = $stmt->fetch();

if (!$profile) {
    // Session points at a user that no longer exists — force re-login
    session_destroy();
    header('Location: admin_login.php');
    exit;
}

// --- Active loans (borrowed books), with book details ---
$stmt = $pdo->prepare('
    SELECT
        books.id,
        books.title,
        books.authors,
        loans.due_date,
        loans.borrowed_at
    FROM loans
    INNER JOIN books ON loans.book_id = books.id
    WHERE loans.user_id = ? AND loans.returned_at IS NULL
    ORDER BY loans.due_date ASC
');
$stmt->execute([$adminId]);
$activeLoans = $stmt->fetchAll();

const OVERDUE_FEE_PER_DAY = 5; // pesos

function formatDate(string $isoDate): string
{
    $timestamp = strtotime($isoDate);
    return $timestamp ? date('F j, Y', $timestamp) : $isoDate;
}

function calculateOverdueFee(string $dueDate): float
{
    $due   = new DateTime($dueDate);
    $today = new DateTime('today');

    if ($today <= $due) {
        return 0.0;
    }

    $daysOverdue = (int) $due->diff($today)->days;
    return $daysOverdue * OVERDUE_FEE_PER_DAY;
}

// Split loans into "all borrowed" vs "overdue only"
$overdueLoans = array_values(array_filter($activeLoans, function ($loan) {
    return calculateOverdueFee($loan['due_date']) > 0;
}));

// Initials for the default avatar placeholder, e.g. "juandelacruz" -> "JU"
$initials = strtoupper(substr($profile['username'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile</title>
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
        background: var(--paper);
        font-family: 'Georgia', 'Iowan Old Style', serif;
        color: var(--ink);
        padding: 40px 24px;
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
    .page {
        width: 100%;
        max-width: 720px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }
    .header-links {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        display: flex;
        justify-content: flex-end;
        gap: 18px;
        margin-bottom: 16px;
    }
    .header-links a { color: #fff; text-decoration: none; }
    .header-links a:hover { text-decoration: underline; }

    .profile-card {
        background: #fff;
        border: 1px solid var(--line);
        padding: 36px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 24px;
        flex-wrap: wrap;
    }
    .avatar {
        flex-shrink: 0;
        width: 96px;
        height: 96px;
        border-radius: 50%;
        background: var(--accent);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 32px;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .profile-info h1 {
        font-size: 26px;
        font-weight: 400;
        margin: 0 0 10px;
        line-height: 1.2;
    }
    .profile-meta {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13.5px;
        color: #4a4a4a;
        margin: 4px 0;
    }
    .profile-meta strong {
        color: #6b6b6b;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.05em;
        display: inline-block;
        width: 110px;
    }
    .role-badge {
        display: inline-block;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #eaf1ee;
        color: var(--accent);
        padding: 4px 10px;
        margin-top: 8px;
    }

    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    }
    .tab-btn {
        flex: 1;
        padding: 12px;
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        font-size: 13px;
        letter-spacing: 0.02em;
        cursor: pointer;
    }
    .tab-btn.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }
    .tab-btn .count {
        font-weight: 600;
    }

    .books-panel {
        background: #fff;
        border: 1px solid var(--line);
        padding: 8px;
    }
    .books-panel.hidden { display: none; }

    .loan-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid var(--line);
        gap: 16px;
        flex-wrap: wrap;
    }
    .loan-row:last-child { border-bottom: none; }
    .loan-title { font-size: 15px; margin: 0 0 4px; }
    .loan-authors {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12px;
        color: #6b6b6b;
        margin: 0;
    }
    .loan-due {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12.5px;
        text-align: right;
    }
    .loan-due .label {
        display: block;
        color: #6b6b6b;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 3px;
    }
    .fee-tag {
        display: inline-block;
        margin-top: 4px;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 3px 8px;
        background: #fbeceb;
        color: var(--error);
    }
    .empty-state {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13.5px;
        color: #6b6b6b;
        padding: 32px 20px;
        text-align: center;
    }
</style>
</head>
<body>
<div class="page">

    <div class="header-links">
        <a href="admin_library.php">&larr; Back to library</a>
        <a href="admin_register.php">Log out</a>
    </div>

    <div class="profile-card">
        <div class="avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="profile-info">
            <h1><?= htmlspecialchars($profile['username']) ?></h1>
            <p class="profile-meta"><strong>Student No.</strong> <?= htmlspecialchars($profile['admin_id']) ?></p>
            <p class="profile-meta"><strong>Email</strong> <?= htmlspecialchars($profile['email']) ?></p>
            <span class="role-badge"><?= htmlspecialchars($profile['role']) ?></span>
        </div>
    </div>

    <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="borrowed">
            Borrowed Books <span class="count">(<?= count($activeLoans) ?>)</span>
        </button>
        <button type="button" class="tab-btn" data-tab="overdue">
            Overdue Books <span class="count">(<?= count($overdueLoans) ?>)</span>
        </button>
    </div>

    <div class="books-panel" id="panel-borrowed">
        <?php if (empty($activeLoans)): ?>
            <div class="empty-state">You don't have any books borrowed right now.</div>
        <?php else: ?>
            <?php foreach ($activeLoans as $loan): ?>
                <?php
                    $authors = array_map('trim', explode(',', $loan['authors']));
                    $fee     = calculateOverdueFee($loan['due_date']);
                ?>
                <div class="loan-row">
                    <div>
                        <p class="loan-title"><?= htmlspecialchars($loan['title']) ?></p>
                        <p class="loan-authors"><?= htmlspecialchars(implode(', ', $authors)) ?></p>
                    </div>
                    <div class="loan-due">
                        <span class="label">Due</span>
                        <?= htmlspecialchars(formatDate($loan['due_date'])) ?>
                        <?php if ($fee > 0): ?>
                            <span class="fee-tag">Overdue — ₱<?= number_format($fee, 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="books-panel hidden" id="panel-overdue">
        <?php if (empty($overdueLoans)): ?>
            <div class="empty-state">No overdue books. You're all caught up!</div>
        <?php else: ?>
            <?php foreach ($overdueLoans as $loan): ?>
                <?php
                    $authors = array_map('trim', explode(',', $loan['authors']));
                    $fee     = calculateOverdueFee($loan['due_date']);
                ?>
                <div class="loan-row">
                    <div>
                        <p class="loan-title"><?= htmlspecialchars($loan['title']) ?></p>
                        <p class="loan-authors"><?= htmlspecialchars(implode(', ', $authors)) ?></p>
                    </div>
                    <div class="loan-due">
                        <span class="label">Due</span>
                        <?= htmlspecialchars(formatDate($loan['due_date'])) ?>
                        <span class="fee-tag">Overdue — ₱<?= number_format($fee, 2) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
    const tabButtons = document.querySelectorAll('.tab-btn');
    const panels = {
        borrowed: document.getElementById('panel-borrowed'),
        overdue: document.getElementById('panel-overdue'),
    };

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabButtons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            Object.keys(panels).forEach(function (key) {
                panels[key].classList.toggle('hidden', key !== btn.dataset.tab);
            });
        });
    });
</script>
</body>
</html>
