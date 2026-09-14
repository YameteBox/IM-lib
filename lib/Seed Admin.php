<?php
require 'db.php';
 
// ---- EDIT THESE BEFORE RUNNING ----
$adminId  = '202515246';
$username = 'admin246';               
$email    = 'admin246@plm.edu.ph';    
$password = '202515246'; 
// ------------------------------------
 
$hash = password_hash($password, PASSWORD_DEFAULT);
 
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (admin_id, username, email) VALUES (?, ?, ?)'
    );
    $stmt->execute([$adminId, $username, $email]);
 
    $stmt = $pdo->prepare(
        'INSERT INTO admins (admin_id, password_hash, role) VALUES (?, ?, ?)'
    );
    $stmt->execute([$adminId, $hash, 'admin']);
 
    $pdo->commit();
    echo "Admin $adminId created successfully.<br>";
    echo "Username: $username<br>";
    echo "Password: $password<br>";
    echo "(Log in at admin_login.php, then delete this file.)";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Failed: " . $e->getMessage();
}