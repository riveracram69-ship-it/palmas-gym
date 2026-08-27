<?php
require_once 'config/db.php';

try {
    $pdo->beginTransaction();

    // 1. Add created_by to members
    echo "Adding created_by to members...\n";
    $pdo->exec("ALTER TABLE members ADD COLUMN created_by INT NULL");
    $pdo->exec("ALTER TABLE members ADD CONSTRAINT fk_members_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");

    // 2. Add created_by to subscriptions
    echo "Adding created_by to subscriptions...\n";
    $pdo->exec("ALTER TABLE subscriptions ADD COLUMN created_by INT NULL");
    $pdo->exec("ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");

    $pdo->commit();
    echo "Success! Database connected.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
