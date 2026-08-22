<?php

require __DIR__ . '/db.php';

$db->exec("
    CREATE TABLE IF NOT EXISTS glass_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        glass_id INTEGER NOT NULL,
        employee_id INTEGER NOT NULL,
        old_status TEXT,
        new_status TEXT NOT NULL,
        old_location TEXT,
        new_location TEXT,
        comment TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (glass_id) REFERENCES glasses(id),
        FOREIGN KEY (employee_id) REFERENCES users(id)
    )
");

echo "History table created.\n";
