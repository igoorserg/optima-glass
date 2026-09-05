<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/permissions.php';

$user = require_user();

/*
|--------------------------------------------------------------------------
| Допоміжні функції
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

function priorityLabel(int $priority): string
{
    return match ($priority) {
        3 => '🔴 Критичний',
        2 => '🟠 Терміновий',
        default => '🟢 Звичайний',
    };
}

function glassStatusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Створено',
        'waiting' => 'Очікує',
        'in_progress' => 'У роботі',
        'completed' => 'Готово',
        'rejected' => 'Брак',
        'rework' => 'Повторна обробка',
        default => $status,
    };
}

function batchStatusLabel(string $status): string
{
    return match ($status) {
        'created' => 'Створена',
        'in_progress' => 'У роботі',
        'completed' => 'Завершена',
        'cancelled' => 'Скасована',
        default => $status,
    };
}

function executionModeLabel(string $mode): string
{
    return match ($mode) {
        'batch' => 'Партіями',
        'both' => 'Поштучно + партіями',
        default => 'Поштучно',
    };
}

function writeProductionAudit(
    PDO $db,
    int $userId,
    string $action,
    ?string $entityType,
    ?int $entityId,
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
            :entity_type,
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
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,

        ':old_value' =>
            $oldValue !== null
                ? json_encode(
                    $oldValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':new_value' =>
            $newValue !== null
                ? json_encode(
                    $newValue,
                    JSON_UNESCAPED_UNICODE
                )
                : null,

        ':ip_address' =>
            $_SERVER['REMOTE_ADDR']
            ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT']
            ?? null,
    ]);
}

/*
|--------------------------------------------------------------------------
| Права
|--------------------------------------------------------------------------
*/

require_permission(
    'production.view',
    $user
);

$canManageProduction =
    can(
        'production.manage',
        $user
    );

$canCreateBatch =
    can(
        'production.create_batch',
        $user
    );

$canEditOrders =
    can(
        'orders.edit',
        $user
    );

$userStageId =
    current_stage_id(
        $user
    );

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION[
            'csrf_production'
        ]
    )
) {

    $_SESSION[
        'csrf_production'
    ] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION[
        'csrf_production'
    ];

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Дільниці
|--------------------------------------------------------------------------
*/

$stageStmt =
    $db->query("
        SELECT
            id,
            name,
            active,
            execution_mode
        FROM production_stages
        WHERE active = 1
        ORDER BY id
    ");

$stages =
    $stageStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Визначаємо доступну дільницю
|--------------------------------------------------------------------------
*/

$requestedStageId =
    (int) (
        $_GET['stage_id']
        ??
        $_POST['stage_id']
        ??
        0
    );

if (
    is_section_manager($user)
) {

    /*
     * Майстер дільниці завжди працює
     * тільки на своїй закріпленій дільниці.
     *
     * Параметр stage_id з URL для нього
     * ігнорується.
     */

    $stageId =
        $userStageId ?? 0;

} elseif ($canManageProduction) {

    $stageId =
        $requestedStageId;

    /*
     * Якщо дільницю не вибрано —
     * відкриваємо першу активну.
     */

    if (
        $stageId <= 0
        &&
        !empty($stages)
    ) {

        $stageId =
            (int)
            $stages[0]['id'];
    }

} else {

    /*
     * Працівник або начальник дільниці
     * бачить тільки свою дільницю.
     */

    if ($userStageId === null) {

        http_response_code(403);

        exit(
            'Користувачу не призначено виробничу дільницю.'
        );
    }

    $stageId =
        (int)
        $userStageId;
}

/*
|--------------------------------------------------------------------------
| Обрана дільниця
|--------------------------------------------------------------------------
*/

$selectedStage = null;

foreach ($stages as $stage) {

    if (
        (int)
        $stage['id']
        ===
        $stageId
    ) {

        $selectedStage =
            $stage;

        break;
    }
}

if (!$selectedStage) {

    http_response_code(404);

    exit(
        'Виробничу дільницю не знайдено.'
    );
}

/*
|--------------------------------------------------------------------------
| Режим
|--------------------------------------------------------------------------
*/

$mode =
    $_GET['mode']
    ??
    $_POST['mode']
    ??
    'single';

if (
    !in_array(
        $mode,
        [
            'single',
            'batch',
        ],
        true
    )
) {

    $mode =
        'single';
}

/*
 * Якщо дільниця тільки поштучна —
 * партійний режим заборонено.
 */

if (
    $selectedStage[
        'execution_mode'
    ]
    ===
    'single'
) {

    $mode =
        'single';
}

/*
|--------------------------------------------------------------------------
| Зміна пріоритету замовлення
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    ($_POST['action'] ?? '') === 'change_order_priority'
) {

    require_permission(
        'orders.edit',
        $user
    );

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token'] ?? ''
        )
    ) {

        http_response_code(403);

        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $priorityOrderId =
        (int) (
            $_POST['order_id']
            ?? 0
        );

    $newPriority =
        (int) (
            $_POST['priority']
            ?? 0
        );

    if (
        $priorityOrderId <= 0
    ) {

        $error =
            'Замовлення не вказано.';

    } elseif (
        !in_array(
            $newPriority,
            [
                1,
                2,
                3,
            ],
            true
        )
    ) {

        $error =
            'Некоректний пріоритет.';

    } else {

        try {

            $db->beginTransaction();

            $priorityOrderStmt =
                $db->prepare("
                    SELECT
                        id,
                        order_number,
                        customer_name,
                        priority,
                        status
                    FROM orders
                    WHERE id = :id
                    LIMIT 1
                ");

            $priorityOrderStmt->execute([
                ':id' =>
                    $priorityOrderId,
            ]);

            $priorityOrder =
                $priorityOrderStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$priorityOrder) {

                throw new RuntimeException(
                    'Замовлення не знайдено.'
                );
            }

            $oldPriority =
                (int)
                $priorityOrder[
                    'priority'
                ];

            if (
                $oldPriority ===
                $newPriority
            ) {

                throw new RuntimeException(
                    'Вибрано той самий пріоритет.'
                );
            }

            $priorityUpdateStmt =
                $db->prepare("
                    UPDATE orders
                    SET
                        priority =
                            :priority,

                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id =
                        :id
                ");

            $priorityUpdateStmt->execute([
                ':priority' =>
                    $newPriority,

                ':id' =>
                    $priorityOrderId,
            ]);

            writeProductionAudit(
                $db,
                (int)
                $user['id'],
                'change_order_priority',
                'order',
                $priorityOrderId,
                [
                    'priority' =>
                        $oldPriority,
                ],
                [
                    'priority' =>
                        $newPriority,

                    'order_number' =>
                        $priorityOrder[
                            'order_number'
                        ],
                ]
            );

            $db->commit();

            $success =
                'Пріоритет замовлення №'
                . $priorityOrder[
                    'order_number'
                ]
                . ' змінено: '
                . priorityLabel(
                    $newPriority
                )
                . '.';

        } catch (
            Throwable $exception
        ) {

            if (
                $db->inTransaction()
            ) {
                $db->rollBack();
            }

            $error =
                'Не вдалося змінити пріоритет: '
                . $exception
                    ->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Створення партії
|--------------------------------------------------------------------------
*/

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
    &&
    (
        $_POST['action']
        ?? ''
    )
    ===
    'create_batch'
) {

    /*
     * Серверна перевірка права.
     */

    require_permission(
        'production.create_batch',
        $user
    );

    if (
        !hash_equals(
            $csrfToken,
            $_POST[
                'csrf_token'
            ]
            ?? ''
        )
    ) {

        http_response_code(403);

        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $postStageId =
        (int) (
            $_POST[
                'stage_id'
            ]
            ?? 0
        );

    $orderId =
        (int) (
            $_POST[
                'order_id'
            ]
            ?? 0
        );

    $assignedEmployeeId =
        (int) (
            $_POST[
                'assigned_employee_id'
            ]
            ?? 0
        );

    $glassIds =
        $_POST[
            'glass_ids'
        ]
        ?? [];

    if (
        !is_array(
            $glassIds
        )
    ) {
        $glassIds = [];
    }

    $glassIds =
        array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $glassIds
                    ),
                    static fn (
                        int $id
                    ): bool =>
                        $id > 0
                )
            )
        );

    /*
     * Дільниця повинна збігатися
     * з поточною доступною дільницею.
     */

    if (
        $postStageId
        !==
        $stageId
    ) {

        $error =
            'Неможливо створити партію для іншої дільниці.';

    } elseif (
        $selectedStage[
            'execution_mode'
        ]
        ===
        'single'
    ) {

        $error =
            'На цій дільниці дозволена тільки поштучна робота.';

    } elseif (
        $orderId <= 0
    ) {

        $error =
            'Замовлення не вибрано.';

    } elseif (
        $assignedEmployeeId <= 0
    ) {

        $error =
            'Працівника не вибрано.';

    } elseif (
        empty(
            $glassIds
        )
    ) {

        $error =
            'Не вибрано жодного скла.';

    } else {

        try {

            $db->beginTransaction();

            /*
             * ----------------------------------------------------------
             * Перевіряємо замовлення.
             * ----------------------------------------------------------
             */

            $orderStmt =
                $db->prepare("
                    SELECT
                        id,
                        order_number,
                        customer_name,
                        status,
                        priority
                    FROM orders
                    WHERE id =
                        :id
                    LIMIT 1
                ");

            $orderStmt->execute([
                ':id' =>
                    $orderId,
            ]);

            $order =
                $orderStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$order) {

                throw new RuntimeException(
                    'Замовлення не знайдено.'
                );
            }

            if (
                $order[
                    'status'
                ]
                !==
                'in_production'
            ) {

                throw new RuntimeException(
                    'Замовлення не перебуває у виробництві.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Перевіряємо працівника.
             * ----------------------------------------------------------
             */

            $employeeStmt =
                $db->prepare("
                    SELECT
                        id,
                        name,
                        role,
                        stage_id,
                        active
                    FROM users
                    WHERE id =
                        :id
                    LIMIT 1
                ");

            $employeeStmt->execute([
                ':id' =>
                    $assignedEmployeeId,
            ]);

            $assignedEmployee =
                $employeeStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (
                !$assignedEmployee
                ||
                (int)
                $assignedEmployee[
                    'active'
                ]
                !== 1
            ) {

                throw new RuntimeException(
                    'Працівника не знайдено або його обліковий запис вимкнено.'
                );
            }

            if (
                (int) (
                    $assignedEmployee[
                        'stage_id'
                    ]
                    ?? 0
                )
                !==
                $postStageId
            ) {

                throw new RuntimeException(
                    'Працівник не належить до вибраної дільниці.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Отримуємо route_step для дільниці
             * з першого вибраного скла.
             * ----------------------------------------------------------
             */

            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count(
                            $glassIds
                        ),
                        '?'
                    )
                );

            $glassCheckSql = "
                SELECT
                    g.id,
                    g.code,
                    g.order_id,
                    g.order_number,
                    g.status,
                    g.current_step_id,
                    g.current_location,
                    g.route_id,

                    rs.name
                        AS stage_name,

                    ps.id
                        AS production_stage_id

                FROM glasses g

                JOIN route_steps rs
                    ON rs.id =
                        g.current_step_id

                JOIN production_stages ps
                    ON ps.name =
                        rs.name

                WHERE g.id IN (
                    {$placeholders}
                )
            ";

            $glassCheckStmt =
                $db->prepare(
                    $glassCheckSql
                );

            $glassCheckStmt->execute(
                $glassIds
            );

            $selectedGlasses =
                $glassCheckStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            if (
                count(
                    $selectedGlasses
                )
                !==
                count(
                    $glassIds
                )
            ) {

                throw new RuntimeException(
                    'Не всі вибрані стекла знайдено.'
                );
            }

            $routeStepId = null;

            foreach (
                $selectedGlasses
                as $glass
            ) {

                if (
                    (int)
                    $glass[
                        'order_id'
                    ]
                    !==
                    $orderId
                ) {

                    throw new RuntimeException(
                        'У партії можуть бути стекла тільки одного замовлення.'
                    );
                }

                if (
                    (int)
                    $glass[
                        'production_stage_id'
                    ]
                    !==
                    $postStageId
                ) {

                    throw new RuntimeException(
                        'Скло '
                        . $glass['code']
                        . ' знаходиться на іншій дільниці.'
                    );
                }

                if (
                    $glass[
                        'status'
                    ]
                    !==
                    'waiting'
                ) {

                    throw new RuntimeException(
                        'Скло '
                        . $glass['code']
                        . ' недоступне для створення партії.'
                    );
                }

                if (
                    $routeStepId === null
                ) {

                    $routeStepId =
                        (int)
                        $glass[
                            'current_step_id'
                        ];

                } elseif (
                    $routeStepId
                    !==
                    (int)
                    $glass[
                        'current_step_id'
                    ]
                ) {

                    throw new RuntimeException(
                        'Усі стекла партії повинні бути на одному етапі маршруту.'
                    );
                }

                /*
                 * Скло не повинно вже
                 * бути в активній партії.
                 */

                $activeBatchStmt =
                    $db->prepare("
                        SELECT
                            pb.id

                        FROM production_batch_items pbi

                        JOIN production_batches pb
                            ON pb.id =
                                pbi.batch_id

                        WHERE pbi.glass_id =
                            :glass_id

                          AND pb.status IN (
                              'created',
                              'in_progress'
                          )

                        LIMIT 1
                    ");

                $activeBatchStmt->execute([
                    ':glass_id' =>
                        (int)
                        $glass[
                            'id'
                        ],
                ]);

                if (
                    $activeBatchStmt
                        ->fetchColumn()
                    !== false
                ) {

                    throw new RuntimeException(
                        'Скло '
                        . $glass['code']
                        . ' вже входить до активної партії.'
                    );
                }
            }

            if (
                $routeStepId === null
            ) {

                throw new RuntimeException(
                    'Не вдалося визначити етап маршруту.'
                );
            }

            /*
             * ----------------------------------------------------------
             * Створюємо партію.
             * ----------------------------------------------------------
             */

            $batchInsert =
                $db->prepare("
                    INSERT INTO production_batches (
                        order_id,
                        route_step_id,
                        employee_id,
                        created_by,
                        assigned_employee_id,
                        status,
                        started_at,
                        created_at
                    )
                    VALUES (
                        :order_id,
                        :route_step_id,
                        :employee_id,
                        :created_by,
                        :assigned_employee_id,
                        'in_progress',
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                ");

            $batchInsert->execute([
                ':order_id' =>
                    $orderId,

                ':route_step_id' =>
                    $routeStepId,

                ':employee_id' =>
                    $assignedEmployeeId,

                ':created_by' =>
                    (int)
                    $user['id'],

                ':assigned_employee_id' =>
                    $assignedEmployeeId,
            ]);

            $batchId =
                (int)
                $db->lastInsertId();

            /*
             * ----------------------------------------------------------
             * Додаємо стекла.
             * ----------------------------------------------------------
             */

            $itemInsert =
                $db->prepare("
                    INSERT INTO production_batch_items (
                        batch_id,
                        glass_id,
                        status
                    )
                    VALUES (
                        :batch_id,
                        :glass_id,
                        'pending'
                    )
                ");

            $glassUpdate =
                $db->prepare("
                    UPDATE glasses
                    SET
                        status =
                            'in_progress',

                        employee_id =
                            :employee_id,

                        updated_at =
                            CURRENT_TIMESTAMP

                    WHERE id =
                        :id
                ");

            foreach (
                $selectedGlasses
                as $glass
            ) {

                $glassId =
                    (int)
                    $glass['id'];

                $itemInsert->execute([
                    ':batch_id' =>
                        $batchId,

                    ':glass_id' =>
                        $glassId,
                ]);

                $glassUpdate->execute([
                    ':employee_id' =>
                        $assignedEmployeeId,

                    ':id' =>
                        $glassId,
                ]);
            }

            /*
             * ----------------------------------------------------------
             * Сповіщення працівнику.
             * ----------------------------------------------------------
             */

            $notificationId = null;

            try {

                $notificationStmt =
                    $db->prepare("
                        INSERT INTO notifications (
                            user_id,
                            type,
                            title,
                            message,
                            entity_type,
                            entity_id,
                            channel,
                            status
                        )
                        VALUES (
                            :user_id,
                            'batch_assigned',
                            :title,
                            :message,
                            'batch',
                            :entity_id,
                            'in_app',
                            'unread'
                        )
                    ");

                $notificationStmt->execute([
                    ':user_id' =>
                        $assignedEmployeeId,

                    ':title' =>
                        'Вам призначено нову партію',

                    ':message' =>
                        'Партія №'
                        . $batchId
                        . '. Замовлення '
                        . $order[
                            'order_number'
                        ]
                        . '. Скло: '
                        . count(
                            $selectedGlasses
                        )
                        . '.',

                    ':entity_id' =>
                        $batchId,
                ]);

                $notificationId =
                    (int)
                    $db->lastInsertId();

            } catch (
                Throwable
                $notificationException
            ) {

                /*
                 * Помилка сповіщення
                 * не повинна ламати створення партії.
                 */
            }

            /*
             * ----------------------------------------------------------
             * Audit.
             * ----------------------------------------------------------
             */

            writeProductionAudit(
                $db,
                (int)
                $user['id'],
                'create_batch',
                'batch',
                $batchId,
                null,
                [
                    'order_id' =>
                        $orderId,

                    'order_number' =>
                        $order[
                            'order_number'
                        ],

                    'route_step_id' =>
                        $routeStepId,

                    'stage_id' =>
                        $postStageId,

                    'created_by' =>
                        (int)
                        $user['id'],

                    'assigned_employee_id' =>
                        $assignedEmployeeId,

                    'glass_ids' =>
                        array_map(
                            static fn (
                                array $glass
                            ): int =>
                                (int)
                                $glass['id'],
                            $selectedGlasses
                        ),

                    'quantity' =>
                        count(
                            $selectedGlasses
                        ),

                    'notification_id' =>
                        $notificationId,
                ]
            );

            $db->commit();

            $success =
                'Партію №'
                . $batchId
                . ' створено. Виконавець: '
                . $assignedEmployee[
                    'name'
                ]
                . '. Скло: '
                . count(
                    $selectedGlasses
                )
                . '.';

        } catch (
            Throwable
            $exception
        ) {

            if (
                $db->inTransaction()
            ) {
                $db->rollBack();
            }

            $error =
                'Не вдалося створити партію: '
                . $exception
                    ->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Призначення замовлення на дільниці
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    (
        $_POST['action']
        ?? ''
    ) === 'assign_stage_order'
) {

    if (
        !is_section_manager($user)
    ) {
        http_response_code(403);
        exit(
            'Призначати роботу може тільки Майстер дільниці.'
        );
    }

    if (
        !hash_equals(
            $csrfToken,
            $_POST['csrf_token']
            ?? ''
        )
    ) {
        http_response_code(403);
        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $assignmentOrderId =
        (int) (
            $_POST['order_id']
            ?? 0
        );

    $assignmentTarget =
        trim(
            (string) (
                $_POST['assignment_target']
                ?? ''
            )
        );

    try {

        if (
            $assignmentOrderId <= 0
        ) {
            throw new RuntimeException(
                'Не вибрано замовлення.'
            );
        }

        /*
         * Перевіряємо, що замовлення
         * дійсно знаходиться на дільниці Майстра.
         */

        $checkStmt =
            $db->prepare("
                SELECT 1

                FROM glasses g

                JOIN route_steps rs
                    ON rs.id =
                        g.current_step_id

                JOIN production_stages ps
                    ON ps.name =
                        rs.name

                WHERE g.order_id =
                    :order_id

                  AND ps.id =
                    :stage_id

                  AND g.status IN (
                      'waiting',
                      'in_progress'
                  )

                LIMIT 1
            ");

        $checkStmt->execute([
            ':order_id' =>
                $assignmentOrderId,

            ':stage_id' =>
                $stageId,
        ]);

        if (
            !$checkStmt->fetchColumn()
        ) {
            throw new RuntimeException(
                'Замовлення вже не знаходиться на цій дільниці.'
            );
        }

        $assignmentType = null;
        $employeeId = null;
        $workSessionId = null;

        /*
         * Працівник
         */

        if (
            str_starts_with(
                $assignmentTarget,
                'employee:'
            )
        ) {

            $assignmentType =
                'employee';

            $employeeId =
                (int)
                substr(
                    $assignmentTarget,
                    strlen(
                        'employee:'
                    )
                );

            $employeeStmt =
                $db->prepare("
                    SELECT
                        id,
                        name

                    FROM users

                    WHERE id =
                        :employee_id

                      AND active = 1

                      AND stage_id =
                        :stage_id

                      AND role IN (
                          'employee',
                          'section_manager'
                      )

                    LIMIT 1
                ");

            $employeeStmt->execute([
                ':employee_id' =>
                    $employeeId,

                ':stage_id' =>
                    $stageId,
            ]);

            if (
                !$employeeStmt
                    ->fetch(
                        PDO::FETCH_ASSOC
                    )
            ) {
                throw new RuntimeException(
                    'Працівник не належить до цієї дільниці.'
                );
            }
        }

        /*
         * Бригада
         */

        elseif (
            str_starts_with(
                $assignmentTarget,
                'brigade:'
            )
        ) {

            $assignmentType =
                'brigade';

            $workSessionId =
                (int)
                substr(
                    $assignmentTarget,
                    strlen(
                        'brigade:'
                    )
                );

            $brigadeStmt =
                $db->prepare("
                    SELECT
                        id,
                        owner_employee_id

                    FROM work_sessions

                    WHERE id =
                        :session_id

                      AND stage_id =
                        :stage_id

                      AND active = 1

                      AND mode =
                        'team'

                    LIMIT 1
                ");

            $brigadeStmt->execute([
                ':session_id' =>
                    $workSessionId,

                ':stage_id' =>
                    $stageId,
            ]);

            $brigade =
                $brigadeStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (
                !$brigade
            ) {
                throw new RuntimeException(
                    'Бригаду не знайдено або вона вже завершена.'
                );
            }

            /*
             * В employee_id сохраняем
             * ответственного бригады.
             */

            $employeeId =
                (int)
                $brigade[
                    'owner_employee_id'
                ];
        }

        /*
         * Пустое значение = снять назначение.
         */

        elseif (
            $assignmentTarget !== ''
            &&
            $assignmentTarget !== 'none'
        ) {
            throw new RuntimeException(
                'Невідомий тип призначення.'
            );
        }

        $db->beginTransaction();

        /*
         * Закрываем старое назначение заказа
         * на этой дільниці.
         */

        $oldStmt =
            $db->prepare("
                SELECT
                    id,
                    employee_id,
                    status,
                    priority

                FROM order_stage_assignments

                WHERE order_id =
                    :order_id

                  AND stage_id =
                    :stage_id

                  AND active = 1
            ");

        $oldStmt->execute([
            ':order_id' =>
                $assignmentOrderId,

            ':stage_id' =>
                $stageId,
        ]);

        $oldAssignments =
            $oldStmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        foreach (
            $oldAssignments
            as $old
        ) {

            $closeStmt =
                $db->prepare("
                    UPDATE order_stage_assignments

                    SET
                        active = 0,
                        status = 'cancelled',
                        ended_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP

                    WHERE id =
                        :id
                ");

            $closeStmt->execute([
                ':id' =>
                    (int)
                    $old['id'],
            ]);

            $historyStmt =
                $db->prepare("
                    INSERT INTO order_stage_assignment_history (
                        assignment_id,
                        order_id,
                        stage_id,
                        employee_id,
                        action,
                        old_status,
                        new_status,
                        old_priority,
                        new_priority,
                        changed_by,
                        created_at
                    )
                    VALUES (
                        :assignment_id,
                        :order_id,
                        :stage_id,
                        :employee_id,
                        'unassigned',
                        :old_status,
                        'cancelled',
                        :old_priority,
                        :new_priority,
                        :changed_by,
                        CURRENT_TIMESTAMP
                    )
                ");

            $historyStmt->execute([
                ':assignment_id' =>
                    (int)
                    $old['id'],

                ':order_id' =>
                    $assignmentOrderId,

                ':stage_id' =>
                    $stageId,

                ':employee_id' =>
                    (int)
                    $old['employee_id'],

                ':old_status' =>
                    $old['status'],

                ':old_priority' =>
                    (int)
                    $old['priority'],

                ':new_priority' =>
                    (int)
                    $old['priority'],

                ':changed_by' =>
                    (int)
                    $user['id'],
            ]);
        }

        /*
         * Новое назначение.
         */

        if (
            $employeeId !== null
        ) {

            $priorityStmt =
                $db->prepare("
                    SELECT priority

                    FROM orders

                    WHERE id =
                        :order_id

                    LIMIT 1
                ");

            $priorityStmt->execute([
                ':order_id' =>
                    $assignmentOrderId,
            ]);

            $priority =
                max(
                    1,
                    (int)
                    $priorityStmt
                        ->fetchColumn()
                );

            $insertStmt =
                $db->prepare("
                    INSERT INTO order_stage_assignments (
                        order_id,
                        stage_id,
                        employee_id,
                        assigned_by,
                        status,
                        priority,
                        active,
                        assigned_at,
                        created_at,
                        updated_at,
                        assignment_type,
                        work_session_id
                    )
                    VALUES (
                        :order_id,
                        :stage_id,
                        :employee_id,
                        :assigned_by,
                        'assigned',
                        :priority,
                        1,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP,
                        :assignment_type,
                        :work_session_id
                    )
                ");

            $insertStmt->execute([
                ':order_id' =>
                    $assignmentOrderId,

                ':stage_id' =>
                    $stageId,

                ':employee_id' =>
                    $employeeId,

                ':assigned_by' =>
                    (int)
                    $user['id'],

                ':priority' =>
                    $priority,

                ':assignment_type' =>
                    $assignmentType,

                ':work_session_id' =>
                    $workSessionId,
            ]);

            $assignmentId =
                (int)
                $db->lastInsertId();

            $historyStmt =
                $db->prepare("
                    INSERT INTO order_stage_assignment_history (
                        assignment_id,
                        order_id,
                        stage_id,
                        employee_id,
                        action,
                        new_status,
                        new_priority,
                        changed_by,
                        created_at
                    )
                    VALUES (
                        :assignment_id,
                        :order_id,
                        :stage_id,
                        :employee_id,
                        'assigned',
                        'assigned',
                        :priority,
                        :changed_by,
                        CURRENT_TIMESTAMP
                    )
                ");

            $historyStmt->execute([
                ':assignment_id' =>
                    $assignmentId,

                ':order_id' =>
                    $assignmentOrderId,

                ':stage_id' =>
                    $stageId,

                ':employee_id' =>
                    $employeeId,

                ':priority' =>
                    $priority,

                ':changed_by' =>
                    (int)
                    $user['id'],
            ]);
        }

        $db->commit();

        header(
            'Location: /production.php?stage_id='
            . $stageId
            . '&mode=single'
        );

        exit;

    } catch (
        Throwable $exception
    ) {

        if (
            $db->inTransaction()
        ) {
            $db->rollBack();
        }

        $error =
            'Не вдалося змінити призначення: '
            . $exception->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| Працівники вибраної дільниці
|--------------------------------------------------------------------------
*/

$employeesStmt =
    $db->prepare("
        SELECT
            id,
            name,
            role,
            stage_id,
            active
        FROM users
        WHERE active = 1
          AND stage_id =
              :stage_id
          AND role IN (
              'employee',
              'section_manager'
          )
        ORDER BY name
    ");

$employeesStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$employees =
    $employeesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Активні бригади дільниці
|--------------------------------------------------------------------------
*/

$brigadesStmt =
    $db->prepare("
        SELECT
            ws.id,
            ws.owner_employee_id,

            owner.name
                AS owner_name,

            GROUP_CONCAT(
                member.name,
                ', '
            )
                AS member_names

        FROM work_sessions ws

        JOIN users owner
            ON owner.id =
                ws.owner_employee_id

        JOIN work_session_members wsm
            ON wsm.work_session_id =
                ws.id

        JOIN users member
            ON member.id =
                wsm.employee_id

        WHERE ws.active = 1

          AND ws.mode =
            'team'

          AND ws.stage_id =
            :stage_id

        GROUP BY
            ws.id,
            ws.owner_employee_id,
            owner.name

        ORDER BY
            ws.id
    ");

$brigadesStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$brigades =
    $brigadesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Черга скла
|--------------------------------------------------------------------------
*/

$queueStmt =
    $db->prepare("
        SELECT
            g.id,
            g.code,
            g.order_id,
            g.order_number,
            g.glass_type,
            g.thickness,
            g.width,
            g.height,
            g.quantity,
            g.status,
            g.current_location,
            g.current_step_id,

            o.customer_name,
            o.priority,
            o.planned_date,

            osa.employee_id
                AS assigned_employee_id,

            osa.assignment_type
                AS assignment_type,

            osa.work_session_id
                AS assigned_work_session_id,

            assigned_user.name
                AS assigned_employee_name,

            rs.name
                AS stage_name

        FROM glasses g

        JOIN orders o
            ON o.id =
                g.order_id

        LEFT JOIN order_stage_assignments osa
            ON osa.id = (
                SELECT osa2.id

                FROM order_stage_assignments osa2

                WHERE osa2.order_id =
                    o.id

                  AND osa2.stage_id =
                    :assignment_stage_id

                  AND osa2.active = 1

                ORDER BY
                    osa2.id DESC

                LIMIT 1
            )

        LEFT JOIN users assigned_user
            ON assigned_user.id =
                osa.employee_id

        JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        JOIN production_stages ps
            ON ps.name =
                rs.name

        WHERE ps.id =
            :stage_id

          AND o.status =
            'in_production'

          AND g.status =
            'waiting'

          AND NOT EXISTS (
              SELECT 1

              FROM production_batch_items pbi

              JOIN production_batches pb
                  ON pb.id =
                      pbi.batch_id

              WHERE pbi.glass_id =
                  g.id

                AND pb.status IN (
                    'created',
                    'in_progress'
                )
          )

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL
                THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            o.id ASC,

            g.id ASC
    ");

$queueStmt->execute([
    ':stage_id' =>
        $stageId,

    ':assignment_stage_id' =>
        $stageId,
]);

$queue =
    $queueStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Групуємо чергу по замовленнях
|--------------------------------------------------------------------------
*/

$ordersQueue = [];

foreach ($queue as $glass) {

    $orderId =
        (int)
        $glass['order_id'];

    if (
        !isset(
            $ordersQueue[
                $orderId
            ]
        )
    ) {

        $ordersQueue[
            $orderId
        ] = [
            'order_id' =>
                $orderId,

            'order_number' =>
                $glass[
                    'order_number'
                ],

            'customer_name' =>
                $glass[
                    'customer_name'
                ],

            'priority' =>
                (int)
                $glass[
                    'priority'
                ],

            'planned_date' =>
                $glass[
                    'planned_date'
                ],

            'glasses' =>
                [],
        ];
    }

    $ordersQueue[
        $orderId
    ][
        'glasses'
    ][] =
        $glass;
}

/*
|--------------------------------------------------------------------------
| Активні партії дільниці
|--------------------------------------------------------------------------
*/

$activeBatchesStmt =
    $db->prepare("
        SELECT
            pb.id,
            pb.status,
            pb.created_at,
            pb.started_at,
            pb.assigned_employee_id,

            o.order_number,
            o.customer_name,
            o.priority,

            u.name
                AS employee_name,

            COUNT(pbi.id)
                AS total_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'completed'
                    THEN 1
                    ELSE 0
                END
            )
                AS completed_items,

            SUM(
                CASE
                    WHEN pbi.status =
                        'rejected'
                    THEN 1
                    ELSE 0
                END
            )
                AS rejected_items

        FROM production_batches pb

        JOIN route_steps rs
            ON rs.id =
                pb.route_step_id

        JOIN production_stages ps
            ON ps.name =
                rs.name

        JOIN orders o
            ON o.id =
                pb.order_id

        LEFT JOIN users u
            ON u.id =
                pb.assigned_employee_id

        LEFT JOIN production_batch_items pbi
            ON pbi.batch_id =
                pb.id

        WHERE ps.id =
            :stage_id

          AND pb.status IN (
              'created',
              'in_progress'
          )

        GROUP BY
            pb.id,
            o.id,
            u.id

        ORDER BY
            o.priority DESC,
            pb.created_at ASC
    ");

$activeBatchesStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$activeBatches =
    $activeBatchesStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Замовлення поточної дільниці
|--------------------------------------------------------------------------
*/

$stageOrdersStmt =
    $db->prepare("
        SELECT DISTINCT
            o.id,
            o.order_number,
            o.customer_name,
            o.priority,
            o.status,
            o.planned_date

        FROM orders o

        JOIN glasses g
            ON g.order_id =
                o.id

        JOIN route_steps rs
            ON rs.id =
                g.current_step_id

        JOIN production_stages ps
            ON ps.name =
                rs.name

        WHERE ps.id =
            :stage_id

          AND o.status =
            'in_production'

          AND g.status IN (
              'waiting',
              'in_progress'
          )

        ORDER BY
            o.priority DESC,

            CASE
                WHEN o.planned_date IS NULL
                THEN 1
                ELSE 0
            END,

            o.planned_date ASC,

            o.id ASC
    ");

$stageOrdersStmt->execute([
    ':stage_id' =>
        $stageId,
]);

$stageOrders =
    $stageOrdersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Статистика дільниці
|--------------------------------------------------------------------------
*/

$queueArea = 0.0;

foreach ($queue as $glass) {

    $queueArea +=
        (
            (int)
            $glass['width']
            *
            (int)
            $glass['height']
            *
            max(
                1,
                (int)
                $glass['quantity']
            )
        )
        / 1000000;
}

?>
<!DOCTYPE html>
<html lang="uk">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Виробництво — OPTIMA GLASS
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            font-family: Arial, sans-serif;
        }

        .page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .page-header {
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin-bottom: 7px;
        }

        .muted {
            color: #6b7280;
        }

        .card {
            margin-bottom: 20px;
            padding: 22px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .message {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 10px;
        }

        .message-success {
            background: #dcfce7;
            color: #166534;
        }

        .message-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .stage-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .stage-link {
            padding: 9px 13px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            color: #111827;
            text-decoration: none;
        }

        .stage-link.active {
            background: #111827;
            color: #fff;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .summary-card {
            padding: 17px;
            border-radius: 10px;
            background: #f9fafb;
        }

        .summary-value {
            display: block;
            margin-top: 6px;
            font-size: 25px;
            font-weight: 700;
        }

        .mode-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }

        .mode-link {
            padding: 9px 13px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            text-decoration: none;
            color: #111827;
        }

        .mode-link.active {
            background: #111827;
            color: #fff;
        }

        .order-card {
            margin-bottom: 18px;
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 15px;
        }

        .order-title {
            font-size: 18px;
            font-weight: 700;
        }

        .priority-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .priority-form select {
            width: auto;
            min-width: 170px;
        }

        .priority-list {
            display: grid;
            gap: 10px;
            margin-top: 15px;
        }

        .priority-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .priority-order-title {
            font-weight: 700;
        }

        .priority-order-meta {
            margin-top: 5px;
            color: #6b7280;
            font-size: 13px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .batch-controls {
            display: grid;
            grid-template-columns:
                minmax(180px, 260px)
                auto;
            gap: 10px;
            margin-top: 15px;
            align-items: end;
        }

        select {
            width: 100%;
            min-height: 42px;
            padding: 0 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 15px;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 700;
        }

        .button-primary {
            background: #111827;
            color: #fff;
        }

        .button-secondary {
            background: #f3f4f6;
            color: #111827;
        }

        .batch-list {
            display: grid;
            gap: 10px;
        }

        .batch-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .empty {
            padding: 28px 10px;
            text-align: center;
            color: #6b7280;
        }

        @media (
            max-width: 800px
        ) {

            .summary {
                grid-template-columns: 1fr;
            }

            .order-header,
            .batch-row {
                flex-direction: column;
                align-items: stretch;
            }

            .batch-controls {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php require __DIR__ . '/../src/partials/header.php'; ?>

<main class="page">

    <header class="page-header">

        <h1>
            Виробництво
        </h1>

        <div class="muted">

            Дільниця:
            <strong>
                <?= e(
                    $selectedStage[
                        'name'
                    ]
                ) ?>
            </strong>

            ·

            Режим:
            <strong>
                <?= e(
                    executionModeLabel(
                        $selectedStage[
                            'execution_mode'
                        ]
                    )
                ) ?>
            </strong>

        </div>

    </header>


    <?php if (
        $success !== ''
    ): ?>

        <div class="message message-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <?php if (
        $error !== ''
    ): ?>

        <div class="message message-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <?php if (
        $canManageProduction
        &&
        !is_section_manager($user)
    ): ?>

        <section class="card">

            <strong>
                Дільниці
            </strong>

            <div
                class="stage-nav"
                style="margin-top:12px;"
            >

                <?php foreach (
                    $stages
                    as $stage
                ): ?>

                    <a
                        href="/production.php?stage_id=<?= (int) $stage['id'] ?>&mode=<?= e($mode) ?>"
                        class="stage-link <?= (int) $stage['id'] === $stageId
                            ? 'active'
                            : '' ?>"
                    >
                        <?= e(
                            $stage['name']
                        ) ?>
                    </a>

                <?php endforeach; ?>

            </div>

        </section>

    <?php endif; ?>


    <section class="card">

        <div class="summary">

            <div class="summary-card">

                Скло в черзі

                <span class="summary-value">
                    <?= count($queue) ?>
                </span>

            </div>


            <div class="summary-card">

                Площа черги

                <span class="summary-value">

                    <?= number_format(
                        $queueArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>


            <div class="summary-card">

                Активні партії

                <span class="summary-value">
                    <?= count(
                        $activeBatches
                    ) ?>
                </span>

            </div>

        </div>

    </section>


    
    <?php if (
        $canEditOrders
    ): ?>

        <section class="card">

            <h2>
                Пріоритети замовлень
            </h2>

            <div class="muted">
                Зміна пріоритету одразу впливає
                на порядок у виробничій черзі.
            </div>

            <?php if (
                !$stageOrders
            ): ?>

                <div class="empty">
                    На цій дільниці немає активних замовлень.
                </div>

            <?php else: ?>

                <div class="priority-list">

                    <?php foreach (
                        $stageOrders
                        as $stageOrder
                    ): ?>

                        <div class="priority-row">

                            <div>

                                <div class="priority-order-title">

                                    Замовлення №
                                    <?= e(
                                        $stageOrder[
                                            'order_number'
                                        ]
                                    ) ?>

                                </div>

                                <div class="priority-order-meta">

                                    <?= e(
                                        $stageOrder[
                                            'customer_name'
                                        ]
                                        ?? 'Клієнта не вказано'
                                    ) ?>

                                    · Поточний:
                                    <?= e(
                                        priorityLabel(
                                            (int)
                                            $stageOrder[
                                                'priority'
                                            ]
                                        )
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $stageOrder[
                                                'planned_date'
                                            ]
                                        )
                                    ): ?>

                                        · План:
                                        <?= e(
                                            $stageOrder[
                                                'planned_date'
                                            ]
                                        ) ?>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <div class="priority-form">

                                <a
                                    href="/order.php?number=<?= urlencode(
                                        (string)
                                        $stageOrder[
                                            'order_number'
                                        ]
                                    ) ?>"
                                    class="button button-secondary"
                                >
                                    Відкрити замовлення
                                </a>

                                <form
                                    method="post"
                                    class="priority-form"
                                >

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
                                    value="change_order_priority"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= (int)
                                        $stageOrder[
                                            'id'
                                        ] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="stage_id"
                                    value="<?= $stageId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="mode"
                                    value="<?= e(
                                        $mode
                                    ) ?>"
                                >

                                <select
                                    name="priority"
                                    required
                                >

                                    <option
                                        value="1"
                                        <?= (int)
                                            $stageOrder[
                                                'priority'
                                            ] === 1
                                                ? 'selected'
                                                : '' ?>
                                    >
                                        🟢 Звичайний
                                    </option>

                                    <option
                                        value="2"
                                        <?= (int)
                                            $stageOrder[
                                                'priority'
                                            ] === 2
                                                ? 'selected'
                                                : '' ?>
                                    >
                                        🟠 Терміновий
                                    </option>

                                    <option
                                        value="3"
                                        <?= (int)
                                            $stageOrder[
                                                'priority'
                                            ] === 3
                                                ? 'selected'
                                                : '' ?>
                                    >
                                        🔴 Критичний
                                    </option>

                                </select>

                                <button
                                    type="submit"
                                    class="button button-secondary"
                                >
                                    Змінити
                                </button>

                                </form>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


<?php if (
        $selectedStage[
            'execution_mode'
        ]
        !==
        'single'
    ): ?>

        <div class="mode-nav">

            <a
                href="/production.php?stage_id=<?= $stageId ?>&mode=single"
                class="mode-link <?= $mode === 'single'
                    ? 'active'
                    : '' ?>"
            >
                Поштучно
            </a>

            <a
                href="/production.php?stage_id=<?= $stageId ?>&mode=batch"
                class="mode-link <?= $mode === 'batch'
                    ? 'active'
                    : '' ?>"
            >
                Партії
            </a>

        </div>

    <?php endif; ?>


    <?php if (
        $mode === 'batch'
        &&
        $selectedStage[
            'execution_mode'
        ]
        !==
        'single'
    ): ?>

        <section class="card">

            <h2>
                Створення партії
            </h2>


            <?php if (
                !$canCreateBatch
            ): ?>

                <div class="empty">

                    У вас немає дозволу
                    на створення партій.

                </div>

            <?php elseif (
                !$ordersQueue
            ): ?>

                <div class="empty">

                    Немає доступного скла
                    для створення партії.

                </div>

            <?php else: ?>

                <?php foreach (
                    $ordersQueue
                    as $orderData
                ): ?>

                    <form
                        method="post"
                        class="order-card"
                    >

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
                            value="create_batch"
                        >

                        <input
                            type="hidden"
                            name="stage_id"
                            value="<?= $stageId ?>"
                        >

                        <input
                            type="hidden"
                            name="mode"
                            value="batch"
                        >

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?= (int)
                                $orderData[
                                    'order_id'
                                ] ?>"
                        >


                        <div class="order-header">

                            <div>

                                <div class="order-title">

                                    Замовлення
                                    <?= e(
                                        $orderData[
                                            'order_number'
                                        ]
                                    ) ?>

                                </div>

                                <div class="muted">

                                    <?= e(
                                        $orderData[
                                            'customer_name'
                                        ]
                                        ?? ''
                                    ) ?>

                                    ·

                                    <?= e(
                                        priorityLabel(
                                            (int)
                                            $orderData[
                                                'priority'
                                            ]
                                        )
                                    ) ?>

                                </div>

                            </div>


                            <a
                                href="/order.php?number=<?= urlencode(
                                    (string)
                                    $orderData[
                                        'order_number'
                                    ]
                                ) ?>"
                                class="button button-secondary"
                            >
                                Відкрити замовлення
                            </a>


                        </div>


                        <div class="table-wrap">

                            <table>

                                <thead>

                                    <tr>

                                        <th>
                                            Вибрати
                                        </th>

                                        <th>
                                            Скло
                                        </th>

                                        <th>
                                            Тип
                                        </th>

                                        <th>
                                            Розмір
                                        </th>

                                        <th>
                                            Товщина
                                        </th>

                                        <th>
                                            Площа
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                <?php foreach (
                                    $orderData[
                                        'glasses'
                                    ]
                                    as $glass
                                ): ?>

                                    <tr>

                                        <td>

                                            <input
                                                type="checkbox"
                                                name="glass_ids[]"
                                                value="<?= (int)
                                                    $glass[
                                                        'id'
                                                    ] ?>"
                                            >

                                        </td>

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $glass[
                                                        'code'
                                                    ]
                                                ) ?>
                                            </strong>

                                        </td>

                                        <td>

                                            <?= e(
                                                $glass[
                                                    'glass_type'
                                                ]
                                                ?? ''
                                            ) ?>

                                        </td>

                                        <td>

                                            <?= (int)
                                                $glass[
                                                    'width'
                                                ] ?>

                                            ×

                                            <?= (int)
                                                $glass[
                                                    'height'
                                                ] ?>

                                            мм

                                        </td>

                                        <td>

                                            <?= $glass[
                                                'thickness'
                                            ] !== null
                                                ? e(
                                                    (string)
                                                    $glass[
                                                        'thickness'
                                                    ]
                                                ) . ' мм'
                                                : '—'
                                            ?>

                                        </td>

                                        <td>

                                            <?= number_format(
                                                (
                                                    (int)
                                                    $glass[
                                                        'width'
                                                    ]
                                                    *
                                                    (int)
                                                    $glass[
                                                        'height'
                                                    ]
                                                    *
                                                    max(
                                                        1,
                                                        (int)
                                                        $glass[
                                                            'quantity'
                                                        ]
                                                    )
                                                )
                                                / 1000000,
                                                2,
                                                ',',
                                                ' '
                                            ) ?>

                                            м²

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                        <div class="batch-controls">

                            <div>

                                <label>

                                    <strong>
                                        Виконавець
                                    </strong>

                                </label>

                                <select
                                    name="assigned_employee_id"
                                    required
                                >

                                    <option value="">
                                        Оберіть працівника
                                    </option>

                                    <?php foreach (
                                        $employees
                                        as $employee
                                    ): ?>

                                        <option
                                            value="<?= (int)
                                                $employee[
                                                    'id'
                                                ] ?>"
                                        >

                                            <?= e(
                                                $employee[
                                                    'name'
                                                ]
                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>


                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Створити партію
                            </button>

                        </div>

                    </form>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>

    <?php else: ?>

        <section class="card">

            <h2>
                Черга дільниці
            </h2>

            <?php if (
                !$queue
            ): ?>

                <div class="empty">

                    На дільниці зараз
                    немає доступного скла.

                </div>

            <?php else: ?>

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>

                                <th>#</th>
                                <th>Скло</th>
                                <th>Замовлення</th>
                                <th>Пріоритет</th>
                                <th>Розмір</th>
                                <th>Товщина</th>
                                <th>Призначено</th>
                                <th>Статус</th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $queue
                            as $index =>
                                $glass
                        ): ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td>

                                    <strong>
                                        <?= e(
                                            $glass[
                                                'code'
                                            ]
                                        ) ?>
                                    </strong>

                                </td>

                                <td>

                                    <?= e(
                                        $glass[
                                            'order_number'
                                        ]
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $glass[
                                                'customer_name'
                                            ]
                                        )
                                    ): ?>

                                        <br>

                                        <small>

                                            <?= e(
                                                $glass[
                                                    'customer_name'
                                                ]
                                            ) ?>

                                        </small>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= e(
                                        priorityLabel(
                                            (int)
                                            $glass[
                                                'priority'
                                            ]
                                        )
                                    ) ?>

                                </td>

                                <td>

                                    <?= (int)
                                        $glass[
                                            'width'
                                        ] ?>

                                    ×

                                    <?= (int)
                                        $glass[
                                            'height'
                                        ] ?>

                                    мм

                                </td>

                                <td>

                                    <?= $glass[
                                        'thickness'
                                    ] !== null
                                        ? e(
                                            (string)
                                            $glass[
                                                'thickness'
                                            ]
                                        ) . ' мм'
                                        : '—'
                                    ?>

                                </td>

                                <td>

                                    <?php if (
                                        is_section_manager(
                                            $user
                                        )
                                    ): ?>

                                        <form
                                            method="post"
                                            style="
                                                display:flex;
                                                gap:6px;
                                                align-items:center;
                                                min-width:260px;
                                            "
                                        >

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
                                                value="assign_stage_order"
                                            >

                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= (int)
                                                    $glass[
                                                        'order_id'
                                                    ] ?>"
                                            >

                                            <?php

                                            $currentTarget =
                                                'none';

                                            if (
                                                (
                                                    $glass[
                                                        'assignment_type'
                                                    ]
                                                    ?? ''
                                                )
                                                ===
                                                'brigade'
                                                &&
                                                !empty(
                                                    $glass[
                                                        'assigned_work_session_id'
                                                    ]
                                                )
                                            ) {

                                                $currentTarget =
                                                    'brigade:'
                                                    .
                                                    (int)
                                                    $glass[
                                                        'assigned_work_session_id'
                                                    ];

                                            } elseif (
                                                !empty(
                                                    $glass[
                                                        'assigned_employee_id'
                                                    ]
                                                )
                                            ) {

                                                $currentTarget =
                                                    'employee:'
                                                    .
                                                    (int)
                                                    $glass[
                                                        'assigned_employee_id'
                                                    ];
                                            }

                                            ?>

                                            <select
                                                name="assignment_target"
                                                style="
                                                    min-width:200px;
                                                "
                                            >

                                                <option
                                                    value="none"
                                                    <?= $currentTarget
                                                        === 'none'
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    Не призначено
                                                </option>

                                                <optgroup
                                                    label="Працівники"
                                                >

                                                    <?php foreach (
                                                        $employees
                                                        as $employee
                                                    ): ?>

                                                        <?php
                                                        $employeeTarget =
                                                            'employee:'
                                                            .
                                                            (int)
                                                            $employee[
                                                                'id'
                                                            ];
                                                        ?>

                                                        <option
                                                            value="<?= e(
                                                                $employeeTarget
                                                            ) ?>"
                                                            <?= $currentTarget
                                                                ===
                                                                $employeeTarget
                                                                    ? 'selected'
                                                                    : ''
                                                            ?>
                                                        >
                                                            <?= e(
                                                                $employee[
                                                                    'name'
                                                                ]
                                                            ) ?>
                                                        </option>

                                                    <?php endforeach; ?>

                                                </optgroup>

                                                <?php if (
                                                    $brigades
                                                ): ?>

                                                    <optgroup
                                                        label="Бригади"
                                                    >

                                                        <?php foreach (
                                                            $brigades
                                                            as $brigade
                                                        ): ?>

                                                            <?php
                                                            $brigadeTarget =
                                                                'brigade:'
                                                                .
                                                                (int)
                                                                $brigade[
                                                                    'id'
                                                                ];
                                                            ?>

                                                            <option
                                                                value="<?= e(
                                                                    $brigadeTarget
                                                                ) ?>"
                                                                <?= $currentTarget
                                                                    ===
                                                                    $brigadeTarget
                                                                        ? 'selected'
                                                                        : ''
                                                                ?>
                                                            >
                                                                Бригада:
                                                                <?= e(
                                                                    $brigade[
                                                                        'member_names'
                                                                    ]
                                                                ) ?>
                                                            </option>

                                                        <?php endforeach; ?>

                                                    </optgroup>

                                                <?php endif; ?>

                                            </select>

                                            <button
                                                type="submit"
                                                class="button button-secondary"
                                                title="Зберегти призначення"
                                            >
                                                ✓
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <?= e(
                                            $glass[
                                                'assigned_employee_name'
                                            ]
                                            ?? 'Не призначено'
                                        ) ?>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= e(
                                        glassStatusLabel(
                                            $glass[
                                                'status'
                                            ]
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <section class="card">

        <h2>
            Активні партії
        </h2>

        <?php if (
            !$activeBatches
        ): ?>

            <div class="empty">

                Активних партій немає.

            </div>

        <?php else: ?>

            <div class="batch-list">

                <?php foreach (
                    $activeBatches
                    as $batch
                ): ?>

                    <?php

                    $total =
                        (int)
                        $batch[
                            'total_items'
                        ];

                    $completed =
                        (int)
                        $batch[
                            'completed_items'
                        ];

                    $rejected =
                        (int)
                        $batch[
                            'rejected_items'
                        ];

                    $remaining =
                        max(
                            0,
                            $total
                            -
                            $completed
                            -
                            $rejected
                        );

                    ?>

                    <div class="batch-row">

                        <div>

                            <strong>

                                Партія №
                                <?= (int)
                                    $batch[
                                        'id'
                                    ] ?>

                            </strong>

                            ·

                            <?= e(
                                $batch[
                                    'order_number'
                                ]
                            ) ?>

                            ·

                            <?= e(
                                priorityLabel(
                                    (int)
                                    $batch[
                                        'priority'
                                    ]
                                )
                            ) ?>

                            <div class="muted">

                                Виконавець:
                                <?= e(
                                    $batch[
                                        'employee_name'
                                    ]
                                    ?? 'Не призначено'
                                ) ?>

                                ·

                                Всього:
                                <?= $total ?>

                                ·

                                Готово:
                                <?= $completed ?>

                                ·

                                Брак:
                                <?= $rejected ?>

                                ·

                                Залишилось:
                                <?= $remaining ?>

                            </div>

                        </div>


                        <a
                            href="/batch.php?id=<?= (int)
                                $batch[
                                    'id'
                                ] ?>"
                            class="button button-secondary"
                        >
                            Відкрити партію
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>

</html>
