<?php

declare(strict_types=1);

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

$allowedRoles = [
    'superadmin',
    'admin',
    'manager',
];

if (
    !in_array(
        $user['role'] ?? '',
        $allowedRoles,
        true
    )
) {
    http_response_code(403);
    exit('Керування маршрутами доступне тільки менеджеру або адміністратору.');
}

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function routeAudit(
    PDO $db,
    int $userId,
    string $action,
    int $routeId,
    ?array $oldValue = null,
    ?array $newValue = null
): void {
    $stmt = $db->prepare("
        INSERT INTO audit_log (
            user_id,
            action,
            entity_type,
            entity_id,
            old_value,
            new_value,
            ip_address,
            user_agent
        )
        VALUES (
            :user_id,
            :action,
            'route',
            :entity_id,
            :old_value,
            :new_value,
            :ip_address,
            :user_agent
        )
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':entity_id' => $routeId,
        ':old_value' => $oldValue !== null
            ? json_encode($oldValue, JSON_UNESCAPED_UNICODE)
            : null,
        ':new_value' => $newValue !== null
            ? json_encode($newValue, JSON_UNESCAPED_UNICODE)
            : null,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

if (empty($_SESSION['csrf_routes'])) {
    $_SESSION['csrf_routes'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_routes'];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Дільниці
|--------------------------------------------------------------------------
*/

$stageStmt = $db->query("
    SELECT
        id,
        name
    FROM production_stages
    WHERE active = 1
    ORDER BY id
");

$stages = $stageStmt->fetchAll(
    PDO::FETCH_ASSOC
);

$stageMap = [];

foreach ($stages as $stage) {
    $stageMap[(int)$stage['id']] =
        (string)$stage['name'];
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token'] ?? ''
        )
    ) {
        http_response_code(403);
        exit('Помилка перевірки безпеки.');
    }

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Створення маршруту
    |--------------------------------------------------------------------------
    */

    if ($action === 'create_route') {

        $name = trim(
            (string)($_POST['name'] ?? '')
        );

        $rawStageIds =
            $_POST['stage_ids'] ?? [];

        if (!is_array($rawStageIds)) {
            $rawStageIds = [];
        }

        $stageIds = [];

        foreach ($rawStageIds as $rawId) {

            $stageId = (int)$rawId;

            if (
                $stageId > 0
                &&
                isset($stageMap[$stageId])
                &&
                !in_array(
                    $stageId,
                    $stageIds,
                    true
                )
            ) {
                $stageIds[] = $stageId;
            }
        }

        if ($name === '') {

            $error =
                'Вкажіть назву маршруту.';

        } elseif (count($stageIds) < 1) {

            $error =
                'Додайте хоча б одну дільницю.';

        } else {

            try {

                $db->beginTransaction();

                $duplicateStmt =
                    $db->prepare("
                        SELECT id
                        FROM routes
                        WHERE LOWER(TRIM(name))
                            =
                            LOWER(TRIM(:name))
                        LIMIT 1
                    ");

                $duplicateStmt->execute([
                    ':name' => $name,
                ]);

                if (
                    $duplicateStmt
                        ->fetchColumn()
                    !== false
                ) {
                    throw new RuntimeException(
                        'Маршрут з такою назвою вже існує.'
                    );
                }

                $routeStmt =
                    $db->prepare("
                        INSERT INTO routes (
                            name,
                            active
                        )
                        VALUES (
                            :name,
                            1
                        )
                    ");

                $routeStmt->execute([
                    ':name' => $name,
                ]);

                $routeId =
                    (int)$db->lastInsertId();

                $stepStmt =
                    $db->prepare("
                        INSERT INTO route_steps (
                            route_id,
                            step_number,
                            name
                        )
                        VALUES (
                            :route_id,
                            :step_number,
                            :name
                        )
                    ");

                $stepNames = [];

                foreach (
                    $stageIds
                    as $index => $stageId
                ) {
                    $stageName =
                        $stageMap[$stageId];

                    $stepStmt->execute([
                        ':route_id' =>
                            $routeId,

                        ':step_number' =>
                            $index + 1,

                        ':name' =>
                            $stageName,
                    ]);

                    $stepNames[] =
                        $stageName;
                }

                routeAudit(
                    $db,
                    (int)$user['id'],
                    'route_created',
                    $routeId,
                    null,
                    [
                        'name' => $name,
                        'steps' => $stepNames,
                        'active' => 1,
                    ]
                );

                $db->commit();

                $_SESSION['routes_flash'] =
                    'Маршрут створено.';

                header(
                    'Location: /routes.php'
                );
                exit;

            } catch (Throwable $exception) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                $error =
                    $exception->getMessage();
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Увімкнути / вимкнути
    |--------------------------------------------------------------------------
    */

    if ($action === 'toggle_route') {

        $routeId =
            (int)($_POST['route_id'] ?? 0);

        try {

            $stmt = $db->prepare("
                SELECT
                    id,
                    name,
                    active
                FROM routes
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                ':id' => $routeId,
            ]);

            $route =
                $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$route) {
                throw new RuntimeException(
                    'Маршрут не знайдено.'
                );
            }

            $oldActive =
                (int)$route['active'];

            $newActive =
                $oldActive === 1
                    ? 0
                    : 1;

            $db->beginTransaction();

            $updateStmt =
                $db->prepare("
                    UPDATE routes
                    SET active = :active
                    WHERE id = :id
                ");

            $updateStmt->execute([
                ':active' => $newActive,
                ':id' => $routeId,
            ]);

            routeAudit(
                $db,
                (int)$user['id'],
                $newActive
                    ? 'route_activated'
                    : 'route_deactivated',
                $routeId,
                [
                    'active' => $oldActive,
                ],
                [
                    'active' => $newActive,
                ]
            );

            $db->commit();

            $_SESSION['routes_flash'] =
                $newActive
                    ? 'Маршрут активовано.'
                    : 'Маршрут вимкнено.';

            header(
                'Location: /routes.php'
            );
            exit;

        } catch (Throwable $exception) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $error =
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_SESSION['routes_flash']
    )
) {
    $success =
        (string)
        $_SESSION['routes_flash'];

    unset(
        $_SESSION['routes_flash']
    );
}

/*
|--------------------------------------------------------------------------
| Маршрути
|--------------------------------------------------------------------------
*/

$routeStmt = $db->query("
    SELECT
        r.id,
        r.name,
        r.active,
        r.created_at,

        (
            SELECT COUNT(*)
            FROM glasses g
            WHERE g.route_id = r.id
        ) AS glass_count

    FROM routes r

    ORDER BY
        r.active DESC,
        r.id DESC
");

$routes =
    $routeStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

$stepsStmt =
    $db->query("
        SELECT
            id,
            route_id,
            step_number,
            name
        FROM route_steps
        ORDER BY
            route_id,
            step_number
    ");

$stepsByRoute = [];

foreach (
    $stepsStmt->fetchAll(
        PDO::FETCH_ASSOC
    )
    as $step
) {
    $routeId =
        (int)$step['route_id'];

    $stepsByRoute[$routeId][] =
        $step;
}

require __DIR__
    . '/../src/partials/header.php';

?>

<style>
.routes-page {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}

.routes-grid {
    display: grid;
    grid-template-columns:
        minmax(320px, 420px)
        minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

.routes-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
}

.routes-title {
    margin: 0 0 18px;
}

.route-builder {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.route-builder input[type="text"],
.route-builder select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
}

.stage-row {
    display: grid;
    grid-template-columns:
        42px 1fr 42px;
    gap: 8px;
    align-items: center;
}

.stage-number {
    text-align: center;
    font-weight: 700;
}

.route-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.route-item {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
}

.route-item.inactive {
    opacity: .65;
}

.route-head {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    align-items: flex-start;
}

.route-name {
    font-size: 17px;
    font-weight: 700;
}

.route-path {
    margin-top: 10px;
    line-height: 1.7;
}

.route-meta {
    margin-top: 8px;
    font-size: 13px;
    color: #6b7280;
}

.route-actions {
    margin-top: 12px;
}

.button {
    display: inline-block;
    border: 0;
    border-radius: 8px;
    padding: 9px 14px;
    cursor: pointer;
    background: #2563eb;
    color: white;
    font-weight: 600;
}

.button-secondary {
    background: #e5e7eb;
    color: #111827;
}

.message {
    padding: 12px 14px;
    border-radius: 9px;
    margin-bottom: 16px;
}

.message-success {
    background: #ecfdf5;
}

.message-error {
    background: #fef2f2;
}

.muted {
    color: #6b7280;
}

@media (
    max-width: 900px
) {
    .routes-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="routes-page">

    <h1>
        🛣 Управління маршрутами
    </h1>

    <p class="muted">
        Маршрут визначає послідовність
        проходження скла по виробничих
        дільницях.
    </p>

    <?php if ($success !== ''): ?>

        <div class="message message-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="message message-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <div class="routes-grid">

        <section class="routes-card">

            <h2 class="routes-title">
                Новий маршрут
            </h2>

            <form
                method="post"
                id="route-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="create_route"
                >

                <label>
                    Назва маршруту
                </label>

                <input
                    type="text"
                    name="name"
                    placeholder="Наприклад: Скло з гартуванням"
                    required
                    style="
                        width:100%;
                        box-sizing:border-box;
                        padding:10px 12px;
                        margin:7px 0 18px;
                    "
                >

                <strong>
                    Послідовність дільниць
                </strong>

                <p class="muted">
                    Додайте дільниці
                    у потрібному порядку.
                </p>

                <div
                    id="stage-list"
                    class="route-builder"
                ></div>

                <div
                    style="
                        display:flex;
                        gap:8px;
                        margin-top:12px;
                        flex-wrap:wrap;
                    "
                >
                    <button
                        type="button"
                        class="button button-secondary"
                        id="add-stage"
                    >
                        + Додати дільницю
                    </button>

                    <button
                        type="submit"
                        class="button"
                    >
                        Створити маршрут
                    </button>
                </div>

            </form>

        </section>

        <section class="routes-card">

            <h2 class="routes-title">
                Існуючі маршрути
            </h2>

            <div class="route-list">

                <?php foreach (
                    $routes
                    as $route
                ): ?>

                    <?php
                    $routeId =
                        (int)$route['id'];

                    $routeSteps =
                        $stepsByRoute[
                            $routeId
                        ] ?? [];
                    ?>

                    <article
                        class="route-item <?= (int)$route['active'] === 1
                            ? ''
                            : 'inactive' ?>"
                    >

                        <div class="route-head">

                            <div>

                                <div class="route-name">
                                    <?= e(
                                        $route['name']
                                    ) ?>
                                </div>

                                <div class="route-path">

                                    <?php if (
                                        $routeSteps
                                    ): ?>

                                        <?php foreach (
                                            $routeSteps
                                            as $index => $step
                                        ): ?>

                                            <?php if (
                                                $index > 0
                                            ): ?>
                                                →
                                            <?php endif; ?>

                                            <?= e(
                                                $step['name']
                                            ) ?>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <span class="muted">
                                            Дільниці не задані
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <strong>
                                <?= (int)$route['active'] === 1
                                    ? 'Активний'
                                    : 'Вимкнений' ?>
                            </strong>

                        </div>

                        <div class="route-meta">
                            ID: <?= $routeId ?>
                            · Використано у склі:
                            <?= (int)$route['glass_count'] ?>
                        </div>

                        <div class="route-actions">

                            <form method="post">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e(
                                        $csrfToken
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="toggle_route"
                                >

                                <input
                                    type="hidden"
                                    name="route_id"
                                    value="<?= $routeId ?>"
                                >

                                <button
                                    type="submit"
                                    class="button button-secondary"
                                >
                                    <?= (int)$route['active'] === 1
                                        ? 'Вимкнути'
                                        : 'Увімкнути' ?>
                                </button>

                            </form>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        </section>

    </div>

</div>

<script>
const stages =
    <?= json_encode(
        $stages,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) ?>;

const list =
    document.getElementById(
        'stage-list'
    );

const addButton =
    document.getElementById(
        'add-stage'
    );

function updateNumbers() {
    const rows =
        list.querySelectorAll(
            '.stage-row'
        );

    rows.forEach(
        (row, index) => {
            row.querySelector(
                '.stage-number'
            ).textContent =
                String(index + 1);
        }
    );
}

function addStage(
    selectedId = ''
) {
    const row =
        document.createElement(
            'div'
        );

    row.className =
        'stage-row';

    const number =
        document.createElement(
            'div'
        );

    number.className =
        'stage-number';

    const select =
        document.createElement(
            'select'
        );

    select.name =
        'stage_ids[]';

    select.required = true;

    const empty =
        document.createElement(
            'option'
        );

    empty.value = '';
    empty.textContent =
        'Оберіть дільницю';

    select.appendChild(empty);

    stages.forEach(stage => {

        const option =
            document.createElement(
                'option'
            );

        option.value =
            stage.id;

        option.textContent =
            stage.name;

        if (
            String(stage.id)
            ===
            String(selectedId)
        ) {
            option.selected = true;
        }

        select.appendChild(
            option
        );
    });

    const remove =
        document.createElement(
            'button'
        );

    remove.type =
        'button';

    remove.className =
        'button button-secondary';

    remove.textContent =
        '×';

    remove.addEventListener(
        'click',
        () => {
            row.remove();

            if (
                list.children.length
                === 0
            ) {
                addStage();
            }

            updateNumbers();
        }
    );

    row.appendChild(number);
    row.appendChild(select);
    row.appendChild(remove);

    list.appendChild(row);

    updateNumbers();
}

addButton.addEventListener(
    'click',
    () => addStage()
);

addStage();
</script>

<?php
require __DIR__
    . '/../src/partials/footer.php';
?>
