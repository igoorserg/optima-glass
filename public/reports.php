<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/permissions.php';

require_user();
require_permission('reports.view');

$db = $GLOBALS['db'];

$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
$stageFilter = trim((string)($_GET['stage'] ?? ''));
$employeeFilter = trim((string)($_GET['employee_id'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}

$fromDateTime = $dateFrom . ' 00:00:00';
$toDateTime = $dateTo . ' 23:59:59';

/*
|--------------------------------------------------------------------------
| Площа скла
|--------------------------------------------------------------------------
|
| width × height × quantity / 1 000 000 = м²
|
| Наприклад:
| 1000 × 1000 × 10 = 10 м²
|
*/
$areaSql = "
    (
        COALESCE(g.width, 0)
        * COALESCE(g.height, 0)
        * COALESCE(g.quantity, 1)
    ) / 1000000.0
";

/*
|--------------------------------------------------------------------------
| Ділянки
|--------------------------------------------------------------------------
*/
$stageList = $db->query("
    SELECT id, name
    FROM production_stages
    WHERE active = 1
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Працівники
|--------------------------------------------------------------------------
*/
$employeeList = $db->query("
    SELECT
        u.id,
        u.name,
        ps.name AS stage_name
    FROM users u
    LEFT JOIN production_stages ps
        ON ps.id = u.stage_id
    WHERE u.active = 1
      AND u.role IN ('employee', 'section_manager')
    ORDER BY
        ps.id,
        u.name
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| В РОБОТІ
|--------------------------------------------------------------------------
|
| Це поточний залишок скла на дільниці.
|
| Беремо glasses.current_location.
| Дата тут НЕ враховується.
|
*/
$workWhere = "
    g.current_location IS NOT NULL
    AND TRIM(g.current_location) <> ''
";

$workParams = [];

if ($stageFilter !== '') {
    $workWhere .= " AND g.current_location = :stage ";
    $workParams[':stage'] = $stageFilter;
}

$stmt = $db->prepare("
    SELECT
        g.current_location AS stage,
        COALESCE(SUM($areaSql), 0) AS work_area
    FROM glasses g
    WHERE $workWhere
      AND g.status NOT IN ('completed', 'shipped', 'rejected')
    GROUP BY g.current_location
");

$stmt->execute($workParams);

$workRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$workByStage = [];

foreach ($workRows as $row) {
    $workByStage[(string)$row['stage']] = (float)$row['work_area'];
}

/*
|--------------------------------------------------------------------------
| ВИКОНАНО + БРАК
|--------------------------------------------------------------------------
|
| Це вже історія операцій за вибраний період.
|
| Виконано:
| production + completed
|
| Брак:
| result = rejected
|
*/
$historyWhere = "
    go.created_at BETWEEN :date_from AND :date_to
";

$historyParams = [
    ':date_from' => $fromDateTime,
    ':date_to' => $toDateTime,
];

if ($stageFilter !== '') {
    $historyWhere .= " AND go.from_stage = :stage ";
    $historyParams[':stage'] = $stageFilter;
}

$stmt = $db->prepare("
    SELECT
        COALESCE(NULLIF(go.from_stage, ''), 'Без ділянки') AS stage,

        COALESCE(SUM(
            CASE
                WHEN go.operation_type = 'production'
                     AND go.result = 'completed'
                THEN $areaSql
                ELSE 0
            END
        ), 0) AS completed_area,

        COALESCE(SUM(
            CASE
                WHEN go.result = 'rejected'
                THEN $areaSql
                ELSE 0
            END
        ), 0) AS rejected_area

    FROM glass_operations go

    JOIN glasses g
        ON g.id = go.glass_id

    WHERE $historyWhere
      AND (
            go.operation_type = 'production'
            OR go.result = 'rejected'
          )

    GROUP BY
        COALESCE(NULLIF(go.from_stage, ''), 'Без ділянки')
");

$stmt->execute($historyParams);

$historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$historyByStage = [];

foreach ($historyRows as $row) {
    $historyByStage[(string)$row['stage']] = [
        'completed_area' => (float)$row['completed_area'],
        'rejected_area' => (float)$row['rejected_area'],
    ];
}

/*
|--------------------------------------------------------------------------
| Об'єднуємо дані по ділянках
|--------------------------------------------------------------------------
|
| Важливо:
| показуємо активні виробничі ділянки навіть якщо зараз там 0 м².
|
*/
$stages = [];

foreach ($stageList as $stage) {

    $stageName = (string)$stage['name'];

    if ($stageFilter !== '' && $stageName !== $stageFilter) {
        continue;
    }

    $completedArea =
        $historyByStage[$stageName]['completed_area'] ?? 0.0;

    $rejectedArea =
        $historyByStage[$stageName]['rejected_area'] ?? 0.0;

    $stages[] = [
        'stage' => $stageName,
        'work_area' => $workByStage[$stageName] ?? 0.0,
        'completed_area' => $completedArea,
        'rejected_area' => $rejectedArea,
    ];
}

/*
|--------------------------------------------------------------------------
| Виробіток працівників
|--------------------------------------------------------------------------
|
| Нова логіка:
|
| 1. Якщо для операції є glass_operation_workers —
|    використовуємо персональний розподіл площі.
|
|    Наприклад:
|    операція 0.50 м²
|    Владислав = 0.25 м²
|    Іван      = 0.25 м²
|
| 2. Для старих операцій, створених до впровадження
|    glass_operation_workers, використовуємо fallback:
|    100% площі отримує glass_operations.employee_id.
|
| 3. Дільниця береться з go.from_stage —
|    тобто фактичне місце виконання роботи,
|    а не штатна users.stage_id працівника.
|
*/

$employeeWhere = "
    go.created_at BETWEEN :date_from AND :date_to
";

$employeeParams = [
    ':date_from' => $fromDateTime,
    ':date_to' => $toDateTime,
];

if ($stageFilter !== '') {

    $employeeWhere .= "
        AND go.from_stage = :employee_stage
    ";

    $employeeParams[':employee_stage'] =
        $stageFilter;
}

$employeeOuterWhere = "
    1 = 1
";

if (
    $employeeFilter !== ''
    &&
    ctype_digit($employeeFilter)
) {

    $employeeOuterWhere .= "
        AND a.employee_id = :employee_id
    ";

    $employeeParams[':employee_id'] =
        (int)$employeeFilter;
}

$stmt = $db->prepare("
    WITH allocated_operations AS (

        /*
         * Нові операції:
         * площа вже розподілена між працівниками.
         */

        SELECT
            go.id AS operation_id,
            go.employee_id AS scanning_employee_id,
            gow.employee_id AS employee_id,

            COALESCE(
                NULLIF(
                    go.from_stage,
                    ''
                ),
                'Без ділянки'
            ) AS stage_name,

            go.operation_type,
            go.result,

            gow.area_m2 AS allocated_area

        FROM glass_operations go

        JOIN glass_operation_workers gow
            ON gow.operation_id =
                go.id

        WHERE $employeeWhere

          AND (
                go.operation_type =
                    'production'
                OR go.result =
                    'rejected'
              )


        UNION ALL


        /*
         * Старі операції:
         * якщо персонального розподілу ще немає,
         * 100% площі зараховуємо працівнику,
         * записаному у glass_operations.employee_id.
         */

        SELECT
            go.id AS operation_id,
            go.employee_id AS scanning_employee_id,
            go.employee_id AS employee_id,

            COALESCE(
                NULLIF(
                    go.from_stage,
                    ''
                ),
                'Без ділянки'
            ) AS stage_name,

            go.operation_type,
            go.result,

            $areaSql AS allocated_area

        FROM glass_operations go

        JOIN glasses g
            ON g.id =
                go.glass_id

        WHERE $employeeWhere

          AND (
                go.operation_type =
                    'production'
                OR go.result =
                    'rejected'
              )

          AND NOT EXISTS (
                SELECT 1
                FROM glass_operation_workers gow2
                WHERE gow2.operation_id =
                    go.id
          )
    )

    SELECT
        u.id,
        u.name AS employee,

        a.stage_name,

        COALESCE(
            SUM(
                CASE
                    WHEN
                        a.operation_type =
                            'production'
                        AND
                        a.result =
                            'completed'
                    THEN
                        a.allocated_area
                    ELSE
                        0
                END
            ),
            0
        ) AS completed_area,

        COALESCE(
            SUM(
                CASE
                    WHEN
                        a.result =
                            'rejected'
                    THEN
                        a.allocated_area
                    ELSE
                        0
                END
            ),
            0
        ) AS rejected_area

    FROM allocated_operations a

    JOIN users u
        ON u.id =
            a.employee_id

    WHERE $employeeOuterWhere

    GROUP BY
        a.employee_id,
        u.id,
        u.name,
        a.stage_name

    ORDER BY
        completed_area DESC,
        employee ASC,
        stage_name ASC
");

$stmt->execute(
    $employeeParams
);

$employees =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Форматування
|--------------------------------------------------------------------------
*/
function formatArea(float|int|string|null $value): string
{
    return number_format((float)$value, 2, ',', ' ') . ' м²';
}

function rejectPercent(
    float|int|string|null $completed,
    float|int|string|null $reject
): string {
    $completed = (float)$completed;
    $reject = (float)$reject;

    /*
     * Загальний фактичний виробіток:
     * успішно виконано + брак.
     */
    $total = $completed + $reject;

    if ($total <= 0) {
        return '0,00 %';
    }

    return number_format(
        ($reject / $total) * 100,
        2,
        ',',
        ' '
    ) . ' %';
}

$pageTitle = 'Звіти';

require __DIR__ . '/../src/partials/header.php';
?>

<div class="og-page reports-page">

    <div class="reports-heading">
        <div>
            <h1>Звіти</h1>
            <p>
                Поточне завантаження та виробіток ділянок
            </p>
        </div>
    </div>


    <form method="get" class="reports-filters">

        <div class="reports-filter-field">
            <label for="date_from">
                З дати
            </label>

            <input
                type="date"
                id="date_from"
                name="date_from"
                value="<?= htmlspecialchars($dateFrom) ?>"
            >
        </div>


        <div class="reports-filter-field">
            <label for="date_to">
                По дату
            </label>

            <input
                type="date"
                id="date_to"
                name="date_to"
                value="<?= htmlspecialchars($dateTo) ?>"
            >
        </div>


        <div class="reports-filter-field">
            <label for="stage">
                Ділянка
            </label>

            <select id="stage" name="stage">
                <option value="">
                    Усі ділянки
                </option>

                <?php foreach ($stageList as $stage): ?>
                    <option
                        value="<?= htmlspecialchars((string)$stage['name']) ?>"
                        <?= $stageFilter === (string)$stage['name'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars((string)$stage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>


        <div class="reports-filter-field">
            <label for="employee_id">
                Працівник
            </label>

            <select id="employee_id" name="employee_id">
                <option value="">
                    Усі працівники
                </option>

                <?php foreach ($employeeList as $employee): ?>

                    <?php
                    $employeeLabel = (string)$employee['name'];
                    $employeeStage = trim(
                        (string)($employee['stage_name'] ?? '')
                    );

                    if ($employeeStage !== '') {
                        $employeeLabel .= ' (' . $employeeStage . ')';
                    }
                    ?>

                    <option
                        value="<?= (int)$employee['id'] ?>"
                        <?= $employeeFilter === (string)$employee['id']
                            ? 'selected'
                            : '' ?>
                    >
                        <?= htmlspecialchars($employeeLabel) ?>
                    </option>

                <?php endforeach; ?>
            </select>
        </div>


        <div class="reports-filter-actions">

            <button
                type="submit"
                class="reports-btn reports-btn-primary"
            >
                Показати
            </button>

            <a
                href="/reports.php"
                class="reports-btn reports-btn-secondary"
            >
                Скинути
            </a>

        </div>

    </form>


    <section class="reports-section">

        <div class="reports-section-heading">
            <div>
                <h2>Виробіток працівників</h2>

                <p>
                    Виконана площа за вибраний період
                </p>
            </div>
        </div>


        <div class="reports-table-wrap">

            <table class="reports-table">

                <thead>
                <tr>
                    <th>Працівник</th>
                    <th>Виконано</th>
                    <th>Брак</th>
                    <th>% браку</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$employees): ?>

                    <tr>
                        <td colspan="4" class="reports-empty">
                            Немає даних за вибраний період
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($employees as $employee): ?>

                        <?php
                        $employeeName =
                            (string)($employee['employee']
                            ?? 'Без працівника');

                        $employeeStage =
                            trim((string)($employee['stage_name'] ?? ''));

                        if ($employeeStage !== '') {
                            $employeeName .=
                                ' (' . $employeeStage . ')';
                        }

                        $rejectValue =
                            (float)$employee['rejected_area'];
                        ?>

                        <tr>

                            <td class="reports-name">
                                <?= htmlspecialchars($employeeName) ?>
                            </td>

                            <td class="reports-number reports-success">
                                <?= formatArea(
                                    $employee['completed_area']
                                ) ?>
                            </td>

                            <td class="reports-number <?= $rejectValue > 0
                                ? 'reports-danger'
                                : '' ?>">
                                <?= formatArea(
                                    $employee['rejected_area']
                                ) ?>
                            </td>

                            <td class="reports-number <?= $rejectValue > 0
                                ? 'reports-danger'
                                : '' ?>">
                                <?= rejectPercent(
                                    $employee['completed_area'],
                                    $employee['rejected_area']
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <section class="reports-section">

        <div class="reports-section-heading">
            <div>
                <h2>Ділянки</h2>

                <p>
                    «В роботі» — уся площа скла, яка зараз знаходиться на дільниці
                </p>
            </div>
        </div>


        <div class="reports-table-wrap">

            <table class="reports-table">

                <thead>
                <tr>
                    <th>Ділянка</th>
                    <th>В роботі</th>
                    <th>Виконано</th>
                    <th>Брак</th>
                    <th>% браку</th>
                </tr>
                </thead>

                <tbody>

                <?php if (!$stages): ?>

                    <tr>
                        <td colspan="5" class="reports-empty">
                            Немає даних
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($stages as $stage): ?>

                        <?php
                        $rejectValue =
                            (float)$stage['rejected_area'];
                        ?>

                        <tr>

                            <td class="reports-name">
                                <?= htmlspecialchars(
                                    (string)$stage['stage']
                                ) ?>
                            </td>

                            <td class="reports-number">
                                <?= formatArea(
                                    $stage['work_area']
                                ) ?>
                            </td>

                            <td class="reports-number reports-success">
                                <?= formatArea(
                                    $stage['completed_area']
                                ) ?>
                            </td>

                            <td class="reports-number <?= $rejectValue > 0
                                ? 'reports-danger'
                                : '' ?>">
                                <?= formatArea(
                                    $stage['rejected_area']
                                ) ?>
                            </td>

                            <td class="reports-number <?= $rejectValue > 0
                                ? 'reports-danger'
                                : '' ?>">
                                <?= rejectPercent(
                                    $stage['completed_area'],
                                    $stage['rejected_area']
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>

<?php require __DIR__ . '/../src/partials/footer.php'; ?>
