<?php

require __DIR__ . '/db.php';

$db->exec("
    CREATE TABLE IF NOT EXISTS glasses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        order_number TEXT,
        glass_type TEXT,
        width INTEGER,
        height INTEGER,
        quantity INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'created',
        current_location TEXT,
        employee_id INTEGER,
        comment TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES users(id)
    )
");

echo "Glasses table created.\n";
