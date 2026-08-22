<?php

require __DIR__ . '/db.php';

$name = 'Администратор';
$email = 'admin@optima-glass.local';
$password = 'admin123';

$stmt = $db->prepare("
    INSERT INTO users (name, email, password, role, active)
    VALUES (:name, :email, :password, 'admin', 1)
");

$stmt->execute([
    ':name' => $name,
    ':email' => $email,
    ':password' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Admin created.\n";
