<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

require 'db.php';

$adminId = $_SESSION['admin_id'];

$flashMessage = '';
$flashType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_return') {

        $loanId = (int) ($_POST['loan_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT loans.*, books.title
             FROM loans
             INNER JOIN books ON loans.book_id = books.id
             WHERE loans.id = ? AND loans.returned_at IS NULL'
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $flashMessage = 'That loan could not be found, or it was already marked returned.';
            $flashType    = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'UPDATE loans SET returned_at = NOW() WHERE id = ?'
                )->execute([$loanId]);

                $pdo->prepare(
                    'UPDATE books SET copies_available = copies_available + 1 WHERE id = ?'
                )->execute([$loan['book_id']]);

                $pdo->commit();
                $flashMessage = 'Marked "' . $loan['title'] . '" as returned.';
                $flashType    = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $flashMessage = 'Something went wrong confirming that return. Please try again.';
                $flashType    = 'error';
            }
        }

    } elseif ($action === 'approve_request') {

        $loanId = (int) ($_POST['loan_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT loans.*, books.title
             FROM loans
             INNER JOIN books ON loans.book_id = books.id
             WHERE loans.id = ? AND loans.status = \'pending\''
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $flashMessage = 'That request could not be found, or it was already processed.';
            $flashType    = 'error';
        } else {
            $pdo->prepare(
                "UPDATE loans SET status = 'approved' WHERE id = ?"
            )->execute([$loanId]);

            $flashMessage = 'Approved the borrow request for "' . $loan['title'] . '".';
            $flashType    = 'success';
        }

    } elseif ($action === 'reject_request') {

        $loanId = (int) ($_POST['loan_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT loans.*, books.title
             FROM loans
             INNER JOIN books ON loans.book_id = books.id
             WHERE loans.id = ? AND loans.status = \'pending\''
        );
        $stmt->execute([$loanId]);
        $loan = $stmt->fetch();

        if (!$loan) {
            $flashMessage = 'That request could not be found, or it was already processed.';
            $flashType    = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM loans WHERE id = ?')->execute([$loanId]);

                $pdo->prepare(
                    'UPDATE books SET copies_available = copies_available + 1 WHERE id = ?'
                )->execute([$loan['book_id']]);

                $pdo->commit();
                $flashMessage = 'Rejected the borrow request for "' . $loan['title'] . '".';
                $flashType    = 'success';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $flashMessage = 'Something went wrong rejecting that request. Please try again.';
                $flashType    = 'error';
            }
        }
    } elseif ($action === 'add_book') {

        $title         = trim($_POST['title'] ?? '');
        $authors       = trim($_POST['authors'] ?? '');
        $isbn          = trim($_POST['isbn'] ?? '');
        $publishedDate = trim($_POST['published_date'] ?? '');
        $shelfNumber   = trim($_POST['shelf_number'] ?? '');
        $copies        = (int) ($_POST['copies_available'] ?? 0);

        if ($title === '' || $authors === '' || $publishedDate === '') {
            $flashMessage = 'Title, authors, and published date are required to add a book.';
            $flashType    = 'error';
        } else {
            $pdo->prepare(
                'INSERT INTO books (title, authors, isbn, published_date, shelf_number, copies_available)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $title,
                $authors,
                $isbn !== '' ? $isbn : null,
                $publishedDate,
                $shelfNumber !== '' ? $shelfNumber : null,
                max(0, $copies),
            ]);

            $flashMessage = 'Added "' . $title . '" to the catalog.';
            $flashType    = 'success';
        }

    } elseif ($action === 'update_stock') {

        $bookId    = (int) ($_POST['book_id'] ?? 0);
        $addCopies = (int) ($_POST['add_copies'] ?? 0);

        $stmt = $pdo->prepare('SELECT title FROM books WHERE id = ?');
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if (!$book) {
            $flashMessage = 'That book could not be found.';
            $flashType    = 'error';
        } elseif ($addCopies <= 0) {
            $flashMessage = 'Enter a number of copies greater than zero.';
            $flashType    = 'error';
        } else {
            $pdo->prepare(
                'UPDATE books SET copies_available = copies_available + ? WHERE id = ?'
            )->execute([$addCopies, $bookId]);

            $flashMessage = 'Added ' . $addCopies . ' cop' . ($addCopies === 1 ? 'y' : 'ies')
                . ' of "' . $book['title'] . '".';
            $flashType    = 'success';
        }
    }

    $_SESSION['flash_message'] = $flashMessage;
    $_SESSION['flash_type']    = $flashType;

    $returnTab = $_POST['return_tab'] ?? 'catalog';
    header('Location: admin_library.php?tab=' . urlencode($returnTab));
    exit;
}

if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType    = $_SESSION['flash_type'];
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// --- Catalog (with optional search) ---
$catalogSearch = trim($_GET['catalog_q'] ?? '');

$catalogSql = 'SELECT * FROM books';
$catalogParams = [];
if ($catalogSearch !== '') {
    $catalogSql .= ' WHERE title ILIKE ? OR authors ILIKE ? OR isbn ILIKE ? OR shelf_number ILIKE ?';
    $catalogLike    = '%' . $catalogSearch . '%';
    $catalogParams  = [$catalogLike, $catalogLike, $catalogLike, $catalogLike];
}
$catalogSql .= ' ORDER BY title';

$stmt = $pdo->prepare($catalogSql);
$stmt->execute($catalogParams);
$books = $stmt->fetchAll();

// --- Currently borrowed (approved) books, with borrower details, optionally filtered by search ---
$search = trim($_GET['q'] ?? '');

$activeTab = $_GET['tab'] ?? ($search !== '' ? 'pending' : 'catalog');

$sql = "
    SELECT
        loans.id AS loan_id,
        loans.borrowed_at,
        loans.due_date,
        books.id AS book_id,
        books.title,
        books.authors,
        books.shelf_number,
        users.username AS borrower_username,
        users.student_id AS borrower_student_id
    FROM loans
    INNER JOIN books ON loans.book_id = books.id
    INNER JOIN users ON loans.user_id = users.student_id
    WHERE loans.returned_at IS NULL AND loans.status = 'approved'
";
$params = [];
if ($search !== '') {
    $sql .= ' AND (books.title ILIKE ? OR users.username ILIKE ? OR users.student_id ILIKE ?)';
    $like   = '%' . $search . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY loans.due_date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingLoans = $stmt->fetchAll();

// --- Borrow requests awaiting admin approval ---
$stmt = $pdo->query("
    SELECT
        loans.id AS loan_id,
        loans.borrowed_at AS requested_at,
        books.id AS book_id,
        books.title,
        books.authors,
        books.shelf_number,
        users.username AS borrower_username,
        users.student_id AS borrower_student_id
    FROM loans
    INNER JOIN books ON loans.book_id = books.id
    INNER JOIN users ON loans.user_id = users.student_id
    WHERE loans.status = 'pending'
    ORDER BY loans.borrowed_at ASC
");
$toBeProcessed = $stmt->fetchAll();

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
<title>Admin — Library</title>
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
        max-width: 1040px;
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

    .section-heading {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 16px;
        flex-wrap: wrap;
    }
    .catalog-heading { font-size: 18px; font-weight: 400; margin: 0; }

    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    }
    .tab-btn {
        padding: 10px 18px;
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

    .search-bar {
        margin-bottom: 20px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
    }
    .search-bar form { display: flex; gap: 8px; }
    .search-bar input[type="text"] {
        flex: 1;
        padding: 10px 14px;
        border: 1px solid var(--line);
        font-size: 13.5px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        outline: none;
    }
    .search-bar input[type="text"]:focus { border-color: var(--accent); }
    .search-bar button {
        padding: 10px 18px;
        border: none;
        background: var(--accent);
        color: #fff;
        font-size: 13px;
        cursor: pointer;
    }
    .search-bar button:hover { opacity: 0.9; }

    .panel.hidden { display: none; }

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
    .availability.none-left { background: #fbeceb; color: var(--error); }

    .catalog-toolbar {
        margin-bottom: 16px;
    }
    .btn-toggle-form {
        padding: 10px 18px;
        border: 1px dashed var(--accent);
        background: #fff;
        color: var(--accent);
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        cursor: pointer;
    }
    .btn-toggle-form:hover { background: #eef2fb; }

    .add-book-form {
        background: #fff;
        border: 1px solid var(--line);
        padding: 20px;
        margin-bottom: 20px;
    }
    .add-book-form.hidden { display: none; }
    .add-book-form .form-row {
        display: flex;
        gap: 16px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .add-book-form label {
        flex: 1;
        min-width: 200px;
        display: flex;
        flex-direction: column;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6b6b6b;
        gap: 6px;
    }
    .add-book-form input {
        padding: 9px 10px;
        border: 1px solid var(--line);
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13.5px;
        text-transform: none;
        letter-spacing: normal;
        color: var(--ink);
        outline: none;
    }
    .add-book-form input:focus { border-color: var(--accent); }

    .btn-edit-stock {
        width: 100%;
        padding: 8px;
        margin-top: 8px;
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12.5px;
        cursor: pointer;
    }
    .btn-edit-stock:hover { background: #f4f3f0; }
    .stock-form {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }
    .stock-form.hidden { display: none; }
    .stock-form input[type="number"] {
        flex: 1;
        padding: 8px 10px;
        border: 1px solid var(--line);
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12.5px;
        outline: none;
    }
    .stock-form .btn-confirm {
        padding: 8px 14px;
        font-size: 12.5px;
    }

    /* Pending books list */
    .loan-row {
        background: #fff;
        border: 1px solid var(--line);
        border-left: 4px solid var(--line);
        padding: 18px 20px;
        margin-bottom: 14px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
    }
    .loan-row.overdue {
        border-left-color: var(--error);
        background: #fdf4f3;
    }
    .loan-title { font-size: 16px; margin: 0 0 4px; }
    .loan-authors {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12px;
        color: #6b6b6b;
        margin: 0 0 8px;
    }
    .loan-details {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 12.5px;
        color: #4a4a4a;
        margin: 2px 0;
    }
    .loan-details strong {
        color: #6b6b6b;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 10.5px;
        letter-spacing: 0.05em;
        display: inline-block;
        width: 100px;
    }
    .loan-row.overdue .loan-details.fee-line strong,
    .loan-row.overdue .loan-details.fee-line span {
        color: var(--error);
        font-weight: 700;
    }
    .loan-actions {
        flex-shrink: 0;
    }
    .btn-confirm {
        padding: 10px 18px;
        border: none;
        background: var(--success);
        color: #fff;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13px;
        letter-spacing: 0.02em;
        cursor: pointer;
        white-space: nowrap;
    }
    .btn-confirm:hover { opacity: 0.9; }
    .empty-state {
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 13.5px;
        color: #6b6b6b;
        padding: 32px 20px;
        text-align: center;
        background: #fff;
        border: 1px solid var(--line);
    }
</style>
</head>
<body>
<div class="page">

    <div class="header-card">
        <div class="header-left">
            <img src="PLM_LOGO.png" alt="PLM logo">
            <div>
                <h1>Library — Admin #<?= htmlspecialchars($_SESSION['username']) ?></h1>
                <span class="role"><?= htmlspecialchars($_SESSION['role']) ?></span>
            </div>
        </div>
        <div class="header-links">
            <a href="register.php">Register New User</a>
            <a href="admin_profile.php">Profile</a>
            <a href="admin_login.php">Log out</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="msg <?= $flashType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <div class="section-heading">
        <h2 class="catalog-heading">Browse the catalog</h2>
    </div>

    <div class="tabs">
        <button type="button" class="tab-btn <?= $activeTab === 'catalog' ? 'active' : '' ?>" data-tab="catalog" id="tab-catalog">
            Catalog
        </button>
        <button type="button" class="tab-btn <?= $activeTab === 'pending' ? 'active' : '' ?>" data-tab="pending" id="tab-pending">
            Pending Books <span>(<?= count($pendingLoans) ?>)</span>
        </button>
        <button type="button" class="tab-btn <?= $activeTab === 'processing' ? 'active' : '' ?>" data-tab="processing" id="tab-processing">
            To Be Processed <span>(<?= count($toBeProcessed) ?>)</span>
        </button>
    </div>

    <!-- CATALOG PANEL -->
    <div class="panel <?= $activeTab !== 'catalog' ? 'hidden' : '' ?>" id="panel-catalog">
        <div class="search-bar">
            <form method="GET" action="admin_library.php">
                <input type="hidden" name="tab" value="catalog">
                <input type="text" name="catalog_q" placeholder="Search by title, author, ISBN, or shelf..."
                       value="<?= htmlspecialchars($catalogSearch) ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <div class="catalog-toolbar">
            <button type="button" class="btn-toggle-form" id="toggle-add-book">+ Add a new book</button>
        </div>

        <div class="add-book-form hidden" id="add-book-form">
            <form method="POST" action="admin_library.php">
                <input type="hidden" name="action" value="add_book">
                <input type="hidden" name="return_tab" value="catalog">

                <div class="form-row">
                    <label>Title
                        <input type="text" name="title" required>
                    </label>
                    <label>Authors (comma-separated)
                        <input type="text" name="authors" required>
                    </label>
                </div>
                <div class="form-row">
                    <label>ISBN
                        <input type="text" name="isbn">
                    </label>
                    <label>Published date
                        <input type="date" name="published_date" required>
                    </label>
                </div>
                <div class="form-row">
                    <label>Shelf number
                        <input type="text" name="shelf_number" placeholder="e.g. A-1">
                    </label>
                    <label>Copies available
                        <input type="number" name="copies_available" min="0" value="1" required>
                    </label>
                </div>

                <button type="submit" class="btn-confirm">Add Book</button>
            </form>
        </div>

        <?php if (empty($books)): ?>
            <div class="empty-state">
                <?= $catalogSearch !== '' ? 'No books match your search.' : 'No books in the catalog yet.' ?>
            </div>
        <?php else: ?>
        <div class="book-grid">
            <?php foreach ($books as $book): ?>
                <?php
                    $authors    = array_map('trim', explode(',', $book['authors']));
                    $copiesLeft = (int) $book['copies_available'];
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
                    <p class="book-meta">
                        <strong>Shelf:</strong> <?= htmlspecialchars($book['shelf_number'] ?? 'Unassigned') ?>
                    </p>

                    <?php if ($copiesLeft > 0): ?>
                        <span class="availability in-stock">
                            <?= $copiesLeft ?> cop<?= $copiesLeft === 1 ? 'y' : 'ies' ?> available
                        </span>
                    <?php else: ?>
                        <span class="availability none-left">No copies left</span>
                    <?php endif; ?>

                    <div class="book-actions">
                        <button type="button" class="btn-edit-stock" data-book="<?= (int) $book['id'] ?>">
                            Edit stock
                        </button>
                        <form method="POST" action="admin_library.php"
                              class="stock-form hidden" id="stock-form-<?= (int) $book['id'] ?>">
                            <input type="hidden" name="action" value="update_stock">
                            <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">
                            <input type="hidden" name="return_tab" value="catalog">
                            <input type="number" name="add_copies" min="1" placeholder="Add copies" required>
                            <button type="submit" class="btn-confirm">Add Stock</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- PENDING BOOKS PANEL -->
    <div class="panel <?= $activeTab !== 'pending' ? 'hidden' : '' ?>" id="panel-pending">
        <div class="search-bar">
            <form method="GET" action="admin_library.php">
                <input type="text" name="q" placeholder="Search by title, borrower name, or student number..."
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <?php if (empty($pendingLoans)): ?>
            <div class="empty-state">
                <?= $search !== '' ? 'No pending loans match your search.' : 'No books are currently borrowed.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($pendingLoans as $loan): ?>
                <?php
                    $authors = array_map('trim', explode(',', $loan['authors']));
                    $fee     = calculateOverdueFee($loan['due_date']);
                    $isLate  = $fee > 0;
                ?>
                <div class="loan-row <?= $isLate ? 'overdue' : '' ?>">
                    <div>
                        <h3 class="loan-title"><?= htmlspecialchars($loan['title']) ?></h3>
                        <p class="loan-authors"><?= htmlspecialchars(implode(', ', $authors)) ?></p>

                        <p class="loan-details">
                            <strong>Shelf</strong> <?= htmlspecialchars($loan['shelf_number'] ?? 'Unassigned') ?>
                        </p>
                        <p class="loan-details">
                            <strong>Borrowed by</strong>
                            <?= htmlspecialchars($loan['borrower_username']) ?>
                            (#<?= htmlspecialchars($loan['borrower_student_id']) ?>)
                        </p>
                        <p class="loan-details">
                            <strong>Borrowed on</strong> <?= htmlspecialchars(formatPublishedDate($loan['borrowed_at'])) ?>
                        </p>
                        <p class="loan-details">
                            <strong>Due</strong> <?= htmlspecialchars(formatPublishedDate($loan['due_date'])) ?>
                        </p>
                        <?php if ($isLate): ?>
                            <p class="loan-details fee-line">
                                <strong>Overdue fee</strong> <span>₱<?= number_format($fee, 2) ?></span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="loan-actions">
                        <form method="POST" action="admin_library.php">
                            <input type="hidden" name="action" value="confirm_return">
                            <input type="hidden" name="loan_id" value="<?= (int) $loan['loan_id'] ?>">
                            <input type="hidden" name="return_tab" value="pending">
                            <button type="submit" class="btn-confirm">Confirm Returned</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TO BE PROCESSED PANEL -->
    <div class="panel <?= $activeTab !== 'processing' ? 'hidden' : '' ?>" id="panel-processing">
        <?php if (empty($toBeProcessed)): ?>
            <div class="empty-state">No borrow requests waiting for approval.</div>
        <?php else: ?>
            <?php foreach ($toBeProcessed as $req): ?>
                <?php $authors = array_map('trim', explode(',', $req['authors'])); ?>
                <div class="loan-row">
                    <div>
                        <h3 class="loan-title"><?= htmlspecialchars($req['title']) ?></h3>
                        <p class="loan-authors"><?= htmlspecialchars(implode(', ', $authors)) ?></p>

                        <p class="loan-details">
                            <strong>Shelf</strong> <?= htmlspecialchars($req['shelf_number'] ?? 'Unassigned') ?>
                        </p>
                        <p class="loan-details">
                            <strong>Requested by</strong>
                            <?= htmlspecialchars($req['borrower_username']) ?>
                            (#<?= htmlspecialchars($req['borrower_student_id']) ?>)
                        </p>
                        <p class="loan-details">
                            <strong>Requested on</strong> <?= htmlspecialchars(formatPublishedDate($req['requested_at'])) ?>
                        </p>
                    </div>

                    <div class="loan-actions" style="display: flex; gap: 8px;">
                        <form method="POST" action="admin_library.php">
                            <input type="hidden" name="action" value="approve_request">
                            <input type="hidden" name="loan_id" value="<?= (int) $req['loan_id'] ?>">
                            <input type="hidden" name="return_tab" value="processing">
                            <button type="submit" class="btn-confirm">Approve</button>
                        </form>
                        <form method="POST" action="admin_library.php">
                            <input type="hidden" name="action" value="reject_request">
                            <input type="hidden" name="loan_id" value="<?= (int) $req['loan_id'] ?>">
                            <input type="hidden" name="return_tab" value="processing">
                            <button type="submit" class="btn-confirm" style="background: var(--error);">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
    const tabButtons = {
        catalog: document.getElementById('tab-catalog'),
        pending: document.getElementById('tab-pending'),
        processing: document.getElementById('tab-processing'),
    };
    const panels = {
        catalog: document.getElementById('panel-catalog'),
        pending: document.getElementById('panel-pending'),
        processing: document.getElementById('panel-processing'),
    };

    Object.keys(tabButtons).forEach(function (key) {
        tabButtons[key].addEventListener('click', function () {
            Object.keys(tabButtons).forEach(function (k) {
                tabButtons[k].classList.toggle('active', k === key);
                panels[k].classList.toggle('hidden', k !== key);
            });
        });
    });

    // Toggle the "Add a new book" form
    const toggleAddBookBtn = document.getElementById('toggle-add-book');
    const addBookForm      = document.getElementById('add-book-form');
    if (toggleAddBookBtn && addBookForm) {
        toggleAddBookBtn.addEventListener('click', function () {
            addBookForm.classList.toggle('hidden');
        });
    }

    // Toggle each book card's "Edit stock" mini-form
    document.querySelectorAll('.btn-edit-stock').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const bookId = btn.getAttribute('data-book');
            const form   = document.getElementById('stock-form-' + bookId);
            if (form) {
                form.classList.toggle('hidden');
            }
        });
    });
</script>
</body>
</html>