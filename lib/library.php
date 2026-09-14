<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

require 'db.php';

$userId = $_SESSION['student_id'];

$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = (int) ($_POST['book_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        $flashMessage = 'That book could not be found.';
        $flashType    = 'error';

    } elseif ($action === 'borrow') {

        $stmt = $pdo->prepare(
            'SELECT id FROM loans WHERE book_id = ? AND user_id = ? AND returned_at IS NULL'
        );
        $stmt->execute([$bookId, $userId]);
        $alreadyBorrowed = (bool) $stmt->fetch();

        if ($alreadyBorrowed) {
            $flashMessage = 'You already have "' . $book['title'] . '" borrowed.';
            $flashType    = 'error';

        } elseif ((int) $book['copies_available'] < 1) {
            $flashMessage = 'Sorry, there are no copies of "' . $book['title'] . '" available right now.';
            $flashType    = 'error';

        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO loans (book_id, user_id, borrowed_at, due_date, status)
                     VALUES (?, ?, NOW(), (NOW() + INTERVAL '7 days')::date, 'pending')"
                )->execute([$bookId, $userId]);

                $updateStmt = $pdo->prepare(
                    'UPDATE books SET copies_available = copies_available - 1
                     WHERE id = ? AND copies_available > 0'
                );
                $updateStmt->execute([$bookId]);

                if ($updateStmt->rowCount() === 0) {
                    throw new PDOException('No copies left.');
                }

                $pdo->commit();
                $flashMessage = 'You borrowed "' . $book['title'] . '". Enjoy the read!';
                $flashType    = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $flashMessage = 'Sorry, that book just became unavailable. Please try again.';
                $flashType    = 'error';
            }
        }
    }

    $_SESSION['flash_message'] = $flashMessage;
    $_SESSION['flash_type']    = $flashType;
    header('Location: library.php');
    exit;
}

if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType    = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$stmt  = $pdo->query('SELECT * FROM books ORDER BY title');
$books = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT book_id, due_date, status FROM loans WHERE user_id = ? AND returned_at IS NULL');
$stmt->execute([$userId]);
$myActiveLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by book_id for quick lookup when rendering each book card
$myLoansByBookId = [];
foreach ($myActiveLoans as $loan) {
    $myLoansByBookId[(int) $loan['book_id']] = $loan;
}
$myBorrowedBookIds = array_keys($myLoansByBookId);

function formatPublishedDate(string $isoDate): string
{
    $timestamp = strtotime($isoDate);
    return $timestamp ? date('F j, Y', $timestamp) : $isoDate;
}

const OVERDUE_FEE_PER_DAY = 5; // pesos

/**
 * Calculate the overdue fee in pesos for a loan.
 * Nothing is owed on the due date itself — the fee starts accruing
 * from the first full day *after* it.
 */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Library</title>
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
        max-width: 960px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
        background: var(--paper);
        border: 1px solid var(--line);
        padding: 32px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
    }
    .header-card {
        background: #fff;
        border: 1px solid var(--line);
        padding: 28px 36px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
    }
    .header-left { display: flex; align-items: center; gap: 18px; }
    .header-card img { height: 70px; width: 70px; }
    .header-card h1 { font-size: 20px; font-weight: 400; margin: 0 0 6px; }
    .role {
        display: inline-block;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #eaf1ee;
        color: var(--accent);
        padding: 4px 10px;
    }
    .header-links {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        display: flex;
        gap: 18px;
    }
    .header-links a { color: var(--accent); text-decoration: none; }
    .header-links a:hover { text-decoration: underline; }
    .msg {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        padding: 12px 16px;
        margin-bottom: 20px;
    }
    .msg.error   { background: #fbeceb; color: var(--error); }
    .msg.success { background: #eaf1ee; color: var(--success); }
    .catalog-heading { font-size: 18px; font-weight: 400; margin: 0 0 16px; }
    .book-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
    }
    .book-card {
        background: #fff;
        border: 1px solid var(--line);
        padding: 20px;
        display: flex;
        flex-direction: column;
    }
    .book-title { font-size: 16px; margin: 0 0 6px; line-height: 1.3; }
    .book-meta {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12.5px;
        color: #6b6b6b;
        margin: 2px 0;
    }
    .book-meta strong { color: var(--ink); font-weight: 600; }
    .availability {
        display: inline-block;
        margin-top: 10px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 4px 10px;
        width: fit-content;
    }
    .availability.in-stock  { background: #eaf1ee; color: var(--success); }
    .availability.borrowed  { background: #eef2fb; color: var(--accent); }
    .availability.none-left { background: #fbeceb; color: var(--error); }
    .book-actions { margin-top: auto; padding-top: 16px; }
    .book-actions button {
        width: 100%;
        padding: 10px;
        border: none;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        letter-spacing: 0.02em;
        cursor: pointer;
    }
    .btn-borrow { background: var(--accent); color: #fff; }
    .btn-borrow:hover { opacity: 0.9; }
    .btn-borrow:disabled { background: #c9c9c9; cursor: not-allowed; }
</style>
</head>
<body>
<div class="page">

    <div class="header-card">
        <div class="header-left">
            <img src="PLM_LOGO.png" alt="PLM logo">
            <div>
                <h1>Library — Student #<?= htmlspecialchars($_SESSION['username']) ?></h1>
                <span class="role"><?= htmlspecialchars($_SESSION['role']) ?></span>
            </div>
        </div>
        <div class="header-links">
            <a href="profile.php">Profile</a>
            <a href="login.php">Log out</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="msg <?= $flashType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <h2 class="catalog-heading">Browse the catalog</h2>

    <div class="book-grid">
        <?php foreach ($books as $book): ?>
            <?php
                $authors = array_map('trim', explode(',', $book['authors']));

                $copiesLeft     = (int) $book['copies_available'];
                $isBorrowedByMe = in_array((int) $book['id'], $myBorrowedBookIds, true);
            ?>
            <div class="book-card">
                <h3 class="book-title"><?= htmlspecialchars($book['title']) ?></h3>

                <p class="book-meta">
                    <strong>Author<?= count($authors) > 1 ? 's' : '' ?>:</strong>
                    <?= htmlspecialchars(implode(', ', $authors)) ?>
                </p>
                <p class="book-meta">
                    <strong>Published:</strong> <?= htmlspecialchars(formatPublishedDate($book['published_date'])) ?>
                </p>
                <p class="book-meta">
                    <strong>ISBN:</strong> <?= htmlspecialchars($book['isbn']) ?>
                </p>

                <?php if ($isBorrowedByMe): ?>
                    <?php
                        $loanInfo  = $myLoansByBookId[(int) $book['id']];
                        $dueDate   = $loanInfo['due_date'];
                        $isPending = $loanInfo['status'] === 'pending';
                        $fee       = $isPending ? 0.0 : calculateOverdueFee($dueDate);
                    ?>
                    <?php if ($isPending): ?>
                        <span class="availability borrowed">Request pending approval</span>
                    <?php else: ?>
                        <span class="availability borrowed">Borrowed by you</span>
                        <p class="book-meta">
                            <strong>Due:</strong> <?= htmlspecialchars(formatPublishedDate($dueDate)) ?>
                        </p>
                        <?php if ($fee > 0): ?>
                            <span class="availability none-left">
                                Overdue — ₱<?= number_format($fee, 2) ?> fee
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($copiesLeft > 0): ?>
                    <span class="availability in-stock">
                        <?= $copiesLeft ?> cop<?= $copiesLeft === 1 ? 'y' : 'ies' ?> available
                    </span>
                <?php else: ?>
                    <span class="availability none-left">No copies left</span>
                <?php endif; ?>

                <div class="book-actions">
                    <?php if ($isBorrowedByMe): ?>
                        <button type="button" class="btn-borrow" disabled>
                            <?= $isPending ? 'Borrow Request Pending' : 'Currently borrowed by you' ?>
                        </button>
                    <?php else: ?>
                        <form method="POST" action="library.php">
                            <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                            <input type="hidden" name="action" value="borrow">
                            <button type="submit" class="btn-borrow" <?= $copiesLeft <= 0 ? 'disabled' : '' ?>>
                                <?= $copiesLeft <= 0 ? 'Unavailable' : 'Borrow this book' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>
</body>
</html>