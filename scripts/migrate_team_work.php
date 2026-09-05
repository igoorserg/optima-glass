<?php

declare(strict_types=1);

$dbPath = __DIR__ . '/../data/optima-glass.sqlite';

if (!file_exists($dbPath)) {
    fwrite(STDERR, "ERROR: database not found: {$dbPath}\n");
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->beginTransaction();

try {

    /*
    |--------------------------------------------------------------------------
    | 1. Активний режим спільної роботи
    |--------------------------------------------------------------------------
    |
    | Один працівник може мати одну активну "сесію" роботи.
    |
    | mode:
    | solo   = працює сам
    | team   = спільна робота
    |
    | Працівник, який увімкнув режим, є owner_employee_id.
    |
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS work_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            owner_employee_id INTEGER NOT NULL,
            stage_id INTEGER,

            mode TEXT NOT NULL DEFAULT 'solo'
                CHECK (mode IN ('solo', 'team')),

            active INTEGER NOT NULL DEFAULT 1,

            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (owner_employee_id)
                REFERENCES users(id),

            FOREIGN KEY (stage_id)
                REFERENCES production_stages(id)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_work_sessions_owner
        ON work_sessions(owner_employee_id)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_work_sessions_active
        ON work_sessions(active)
    ");


    /*
    |--------------------------------------------------------------------------
    | 2. Учасники активної спільної роботи
    |--------------------------------------------------------------------------
    |
    | Наприклад:
    |
    | Влад 50%
    | Іван 50%
    |
    | Тут зберігаємо поточний склад команди.
    |
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS work_session_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            work_session_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,

            share_percent REAL NOT NULL DEFAULT 100,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (work_session_id)
                REFERENCES work_sessions(id)
                ON DELETE CASCADE,

            FOREIGN KEY (employee_id)
                REFERENCES users(id),

            UNIQUE(work_session_id, employee_id)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_work_session_members_session
        ON work_session_members(work_session_id)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_work_session_members_employee
        ON work_session_members(employee_id)
    ");


    /*
    |--------------------------------------------------------------------------
    | 3. Розподіл конкретної виробничої операції
    |--------------------------------------------------------------------------
    |
    | Це головна таблиця для звітів працівників.
    |
    | Приклад:
    |
    | glass_operation #100
    | площа = 30 м²
    |
    | Влад 50% = 15 м²
    | Іван 50% = 15 м²
    |
    | Виробіток дільниці при цьому залишається 30 м².
    |
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS glass_operation_workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            operation_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,

            share_percent REAL NOT NULL,
            area_m2 REAL NOT NULL,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (operation_id)
                REFERENCES glass_operations(id)
                ON DELETE CASCADE,

            FOREIGN KEY (employee_id)
                REFERENCES users(id),

            UNIQUE(operation_id, employee_id)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_operation_workers_operation
        ON glass_operation_workers(operation_id)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_operation_workers_employee
        ON glass_operation_workers(employee_id)
    ");


    /*
    |--------------------------------------------------------------------------
    | 4. Історія змін розподілу
    |--------------------------------------------------------------------------
    |
    | Начальник дільниці може завтра змінити:
    |
    | 50/50 -> 70/30
    |
    | Але ми зберігаємо:
    | - що було
    | - що стало
    | - хто змінив
    | - коли
    | - коментар
    |
    */
    $db->exec("
        CREATE TABLE IF NOT EXISTS glass_operation_worker_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            operation_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,

            old_share_percent REAL,
            new_share_percent REAL,

            old_area_m2 REAL,
            new_area_m2 REAL,

            changed_by INTEGER NOT NULL,

            comment TEXT,

            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (operation_id)
                REFERENCES glass_operations(id)
                ON DELETE CASCADE,

            FOREIGN KEY (employee_id)
                REFERENCES users(id),

            FOREIGN KEY (changed_by)
                REFERENCES users(id)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_operation_worker_audit_operation
        ON glass_operation_worker_audit(operation_id)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_operation_worker_audit_changed_by
        ON glass_operation_worker_audit(changed_by)
    ");


    /*
    |--------------------------------------------------------------------------
    | 5. Права доступу
    |--------------------------------------------------------------------------
    |
    | team_work.use
    | Працівник може ввімкнути спільну роботу.
    |
    | team_work.manage
    | Начальник дільниці може змінювати розподіл.
    |
    */

    $permissionTableExists = (bool)$db->query("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'permissions'
    ")->fetchColumn();

    if ($permissionTableExists) {

        $columns = $db->query("
            PRAGMA table_info(permissions)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $columnNames = array_column($columns, 'name');

        if (
            in_array('code', $columnNames, true)
            && in_array('name', $columnNames, true)
        ) {
            $stmt = $db->prepare("
                INSERT OR IGNORE INTO permissions (code, name)
                VALUES (:code, :name)
            ");

            $stmt->execute([
                ':code' => 'team_work.use',
                ':name' => 'Спільна робота',
            ]);

            $stmt->execute([
                ':code' => 'team_work.manage',
                ':name' => 'Керування розподілом виробітку',
            ]);
        }
    }

    $db->commit();

    echo "OK: team work migration completed\n";

} catch (Throwable $e) {

    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
