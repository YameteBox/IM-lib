<?php
require 'db.php';

// If we get this far, db.php's try/catch already succeeded —
// otherwise it would have called die() before reaching this line.
echo "Connected to the database successfully!";

// Optional: prove we can actually run a query, not just connect
$stmt = $pdo->query('SELECT COUNT(*) AS total FROM users');
$row = $stmt->fetch();
echo "<br>Current row count in users table: " . $row['total'];