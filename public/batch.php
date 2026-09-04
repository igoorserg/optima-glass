<?php

require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/notifications.php';
require __DIR__ . '/../src/telegram.php';
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

function writeBatchAudit(
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
        ':user_id' =>
            $userId,

        ':action' =>
            $action,

        ':entity_type' =>
            $entityType,

        ':entity_id' =>
            $entityId,

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
            $_SERVER['REMOTE_ADDR'] ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

/*
|--------------------------------------------------------------------------
| Доступ до сторінки
|--------------------------------------------------------------------------
*/

require_permission(
    'production.view',
    $user
);

/*
|--------------------------------------------------------------------------
| ID партії
|--------------------------------------------------------------------------
*/

$batchId =
    (int) (
        $_GET['id']
        ?? $_POST['batch_id']
        ?? 0
    );

if ($batchId <= 0) {

    http_response_code(400);

    exit(
        'Партію не вказано.'
    );
}

/*
|--------------------------------------------------------------------------
| Завантаження партії
|--------------------------------------------------------------------------
*/

$batchStmt =
    $db->prepare("
        SELECT
            pb.id,
            pb.order_id,
            pb.route_step_id,
            pb.employee_id,
            pb.created_by,
            pb.assigned_employee_id,
            pb.status,
            pb.started_at,
            pb.completed_at,
            pb.created_at,

            o.order_number,
            o.customer_name,
            o.priority,
            o.planned_date,

            rs.name AS stage_name,

            u.name AS assigned_employee_name

        FROM production_batches pb

        JOIN orders o
            ON o.id =
                pb.order_id

        JOIN route_steps rs
            ON rs.id =
                pb.route_step_id

        LEFT JOIN users u
            ON u.id =
                pb.assigned_employee_id

        WHERE pb.id =
            :id

        LIMIT 1
    ");

$batchStmt->execute([
    ':id' =>
        $batchId,
]);

$batch =
    $batchStmt->fetch(
        PDO::FETCH_ASSOC
    );

if (!$batch) {

    http_response_code(404);

    exit(
        'Партію не знайдено.'
    );
}

/*
|--------------------------------------------------------------------------
| Право працювати саме з цією партією
|--------------------------------------------------------------------------
|
| Працівник — лише зі своєю партією.
| production.manage — може працювати з іншими партіями.
| Суперадмін — повний доступ.
|
|--------------------------------------------------------------------------
*/

$isAssignedEmployee =
    (int) (
        $batch[
            'assigned_employee_id'
        ] ?? 0
    )
    ===
    (int) $user['id'];

$canManageAnyBatch =
    can(
        'production.manage',
        $user
    )
    ||
    isSuperAdmin(
        $user
    );

$canWorkWithBatch =
    $isAssignedEmployee
    ||
    $canManageAnyBatch;

if (!$canWorkWithBatch) {

    http_response_code(403);

    exit(
        'Ви не маєте доступу до цієї партії.'
    );
}

$canComplete =
    can(
        'production.complete_batch',
        $user
    );

$canReject =
    can(
        'glass.reject',
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
            'csrf_batch'
        ]
    )
) {

    $_SESSION[
        'csrf_batch'
    ] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION[
        'csrf_batch'
    ];

$messageType = '';
$messageTitle = '';
$messageText = '';

/*
|--------------------------------------------------------------------------
| Причини браку
|--------------------------------------------------------------------------
*/

$rejectionReasons = [
    'Тріщина',
    'Скол',
    'Подряпина',
    'Невідповідність розміру',
    'Пошкодження поверхні',
    'Бій скла',
    'Інша причина',
];

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    ===
    'POST'
) {

    if (
        !hash_equals(
            $csrfToken,
            $_POST[
                'csrf_token'
            ] ?? ''
        )
    ) {

        http_response_code(403);

        exit(
            'Помилка перевірки безпеки.'
        );
    }

    $action =
        $_POST[
            'action'
        ] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Брак конкретного скла
    |--------------------------------------------------------------------------
    */

    if (
        $action ===
        'reject_item'
    ) {

        require_permission(
            'glass.reject',
            $user
        );

        if (
            !$canWorkWithBatch
        ) {

            http_response_code(403);

            exit(
                'Немає доступу до партії.'
            );
        }

        /*
         * Відвантаження завершується виключно через shipping.php.
         */

        if (
            in_array(
                trim(
                    (string)
                    ($batch['stage_name'] ?? '')
                ),
                [
                    'Відвантаження',
                    'Отгрузка',
                ],
                true
            )
        ) {
            $messageType =
                'error';

            $messageTitle =
                'Партію не завершено';

            $messageText =
                'Етап «Відвантаження» завершується тільки менеджером через сторінку відвантаження.';

        } elseif (
            !in_array(
                $batch[
                    'status'
                ],
                [
                    'created',
                    'in_progress',
                ],
                true
            )
        ) {

            $messageType =
                'error';

            $messageTitle =
                'Брак не оформлено';

            $messageText =
                'Партія вже завершена або скасована.';

        } else {

            $glassId =
                (int) (
                    $_POST[
                        'glass_id'
                    ] ?? 0
                );

            $reason =
                trim(
                    $_POST[
                        'reason'
                    ] ?? ''
                );

            $comment =
                trim(
                    $_POST[
                        'comment'
                    ] ?? ''
                );

            if (
                !in_array(
                    $reason,
                    $rejectionReasons,
                    true
                )
            ) {

                $messageType =
                    'error';

                $messageTitle =
                    'Причину не вказано';

                $messageText =
                    'Оберіть причину браку.';

            } else {

                try {

                    $db->beginTransaction();

                    /*
                     * Скло повинно належати цій партії.
                     */

                    $itemStmt =
                        $db->prepare("
                            SELECT
                                pbi.id
                                    AS item_id,

                                pbi.status
                                    AS item_status,

                                g.id,
                                g.code,
                                g.order_id,
                                g.order_number,
                                g.status,
                                g.current_step_id,
                                g.current_location,

                                rs.name
                                    AS stage_name

                            FROM production_batch_items pbi

                            JOIN glasses g
                                ON g.id =
                                    pbi.glass_id

                            JOIN route_steps rs
                                ON rs.id =
                                    g.current_step_id

                            WHERE pbi.batch_id =
                                :batch_id

                              AND pbi.glass_id =
                                :glass_id

                            LIMIT 1
                        ");

                    $itemStmt->execute([
                        ':batch_id' =>
                            $batchId,

                        ':glass_id' =>
                            $glassId,
                    ]);

                    $glass =
                        $itemStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if (!$glass) {

                        throw new RuntimeException(
                            'Скло не входить до цієї партії.'
                        );
                    }

                    if (
                        $glass[
                            'item_status'
                        ]
                        !==
                        'pending'
                    ) {

                        throw new RuntimeException(
                            'Це скло вже оброблено у партії.'
                        );
                    }

                    if (
                        (int)
                        $glass[
                            'current_step_id'
                        ]
                        !==
                        (int)
                        $batch[
                            'route_step_id'
                        ]
                    ) {

                        throw new RuntimeException(
                            'Скло вже знаходиться на іншій дільниці.'
                        );
                    }

                    if (
                        !in_array(
                            $glass[
                                'status'
                            ],
                            [
                                'waiting',
                                'in_progress',
                            ],
                            true
                        )
                    ) {

                        throw new RuntimeException(
                            'Поточний статус скла не дозволяє оформити брак.'
                        );
                    }

                    $fullComment =
                        $reason;

                    if (
                        $comment !== ''
                    ) {

                        $fullComment .=
                            ': '
                            . $comment;
                    }

                    /*
                     * glass_operations
                     */

                    $operationStmt =
                        $db->prepare("
                            INSERT INTO glass_operations (
                                glass_id,
                                employee_id,
                                route_step_id,
                                operation_type,
                                from_stage,
                                to_stage,
                                result,
                                batch_id,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :route_step_id,
                                'rejection',
                                :from_stage,
                                NULL,
                                'rejected',
                                :batch_id,
                                :comment
                            )
                        ");

                    $operationStmt->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':route_step_id' =>
                            (int)
                            $batch[
                                'route_step_id'
                            ],

                        ':from_stage' =>
                            $batch[
                                'stage_name'
                            ],

                        ':batch_id' =>
                            $batchId,

                        ':comment' =>
                            $fullComment,
                    ]);

                    /*
                     * glass_history
                     */

                    $historyStmt =
                        $db->prepare("
                            INSERT INTO glass_history (
                                glass_id,
                                employee_id,
                                old_status,
                                new_status,
                                old_location,
                                new_location,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :old_status,
                                'rejected',
                                :old_location,
                                :new_location,
                                :comment
                            )
                        ");

                    $historyStmt->execute([
                        ':glass_id' =>
                            $glassId,

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':old_status' =>
                            $glass[
                                'status'
                            ],

                        ':old_location' =>
                            $glass[
                                'current_location'
                            ],

                        ':new_location' =>
                            'Брак — '
                            . $batch[
                                'stage_name'
                            ],

                        ':comment' =>
                            $fullComment,
                    ]);

                    /*
                     * glasses
                     */

                    $glassUpdate =
                        $db->prepare("
                            UPDATE glasses
                            SET
                                status =
                                    'rejected',

                                current_location =
                                    :location,

                                employee_id =
                                    NULL,

                                comment =
                                    :comment,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $glassUpdate->execute([
                        ':location' =>
                            'Брак — '
                            . $batch[
                                'stage_name'
                            ],

                        ':comment' =>
                            $fullComment,

                        ':id' =>
                            $glassId,
                    ]);

                    /*
                     * production_batch_items
                     */

                    $itemUpdate =
                        $db->prepare("
                            UPDATE production_batch_items
                            SET
                                status =
                                    'rejected',

                                completed_at =
                                    CURRENT_TIMESTAMP

                            WHERE batch_id =
                                :batch_id

                              AND glass_id =
                                :glass_id
                        ");

                    $itemUpdate->execute([
                        ':batch_id' =>
                            $batchId,

                        ':glass_id' =>
                            $glassId,
                    ]);

                    /*
                     * Внутрішнє сповіщення.
                     */

                    $notificationIds =
                        notifyManagement(
                            $db,
                            'glass_rejected',
                            'Оформлено брак скла',
                            'Скло '
                            . $glass[
                                'code'
                            ]
                            . ' із замовлення '
                            . $glass[
                                'order_number'
                            ]
                            . ' оформлено як брак у партії №'
                            . $batchId
                            . ' на дільниці «'
                            . $batch[
                                'stage_name'
                            ]
                            . '». Причина: '
                            . $fullComment,
                            'glass',
                            $glassId
                        );

                    /*
                     * Audit.
                     */

                    writeBatchAudit(
                        $db,
                        (int)
                        $user['id'],
                        'reject_glass_batch',
                        'glass',
                        $glassId,
                        [
                            'status' =>
                                $glass[
                                    'status'
                                ],

                            'batch_id' =>
                                $batchId,
                        ],
                        [
                            'status' =>
                                'rejected',

                            'batch_id' =>
                                $batchId,

                            'reason' =>
                                $fullComment,

                            'notification_ids' =>
                                $notificationIds,
                        ]
                    );

                    $db->commit();

                    /*
                     * Telegram після commit.
                     */

                    $telegramResult = [
                        'success' =>
                            false,

                        'sent' =>
                            false,
                    ];

                    try {

                        $telegramMessage =
                            formatTelegramGlassRejected(
                                $glass[
                                    'code'
                                ],
                                $glass[
                                    'order_number'
                                ],
                                $batch[
                                    'stage_name'
                                ]
                            );

                        $telegramMessage .=
                            "\nПартія: №"
                            . $batchId
                            . "\nПричина: "
                            . $fullComment;

                        $telegramResult =
                            sendTelegramToGroup(
                                $db,
                                $telegramMessage
                            );

                    } catch (
                        Throwable
                        $telegramException
                    ) {

                        $telegramResult = [
                            'success' =>
                                false,

                            'sent' =>
                                false,

                            'error' =>
                                $telegramException
                                    ->getMessage(),
                        ];
                    }

                    writeBatchAudit(
                        $db,
                        (int)
                        $user['id'],
                        'telegram_notification',
                        'glass',
                        $glassId,
                        null,
                        [
                            'event' =>
                                'glass_rejected',

                            'batch_id' =>
                                $batchId,

                            'sent' =>
                                $telegramResult[
                                    'sent'
                                ]
                                ?? false,

                            'group' =>
                                $telegramResult[
                                    'group_title'
                                ]
                                ?? null,

                            'error' =>
                                $telegramResult[
                                    'error'
                                ]
                                ?? null,
                        ]
                    );

                    $messageType =
                        'success';

                    $messageTitle =
                        '❌ БРАК ОФОРМЛЕНО';

                    $messageText =
                        'Скло '
                        . e(
                            $glass[
                                'code'
                            ]
                        )
                        . ' позначено як брак. Причина: '
                        . e(
                            $fullComment
                        );

                } catch (
                    Throwable
                    $exception
                ) {

                    if (
                        $db->inTransaction()
                    ) {
                        $db->rollBack();
                    }

                    $messageType =
                        'error';

                    $messageTitle =
                        'Брак не оформлено';

                    $messageText =
                        e(
                            $exception
                                ->getMessage()
                        );

                    try {

                        writeBatchAudit(
                            $db,
                            (int)
                            $user['id'],
                            'reject_glass_batch_error',
                            'glass',
                            $glassId > 0
                                ? $glassId
                                : null,
                            null,
                            [
                                'batch_id' =>
                                    $batchId,

                                'error' =>
                                    $exception
                                        ->getMessage(),
                            ]
                        );

                    } catch (
                        Throwable
                        $auditException
                    ) {
                        // Не змінюємо результат.
                    }
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Завершення партії
    |--------------------------------------------------------------------------
    */

    elseif (
        $action ===
        'complete_batch'
    ) {

        require_permission(
            'production.complete_batch',
            $user
        );

        if (
            !$canWorkWithBatch
        ) {

            http_response_code(403);

            exit(
                'Немає доступу до партії.'
            );
        }

        if (
            !in_array(
                $batch[
                    'status'
                ],
                [
                    'created',
                    'in_progress',
                ],
                true
            )
        ) {

            $messageType =
                'error';

            $messageTitle =
                'Партію не завершено';

            $messageText =
                'Партія вже завершена або скасована.';

        } else {

            try {

                $db->beginTransaction();

                /*
                 * Повторно отримуємо партію.
                 */

                $currentBatchStmt =
                    $db->prepare("
                        SELECT
                            id,
                            status,
                            route_step_id,
                            assigned_employee_id,
                            order_id

                        FROM production_batches

                        WHERE id =
                            :id

                        LIMIT 1
                    ");

                $currentBatchStmt->execute([
                    ':id' =>
                        $batchId,
                ]);

                $currentBatch =
                    $currentBatchStmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$currentBatch) {

                    throw new RuntimeException(
                        'Партію більше не знайдено.'
                    );
                }

                if (
                    !in_array(
                        $currentBatch[
                            'status'
                        ],
                        [
                            'created',
                            'in_progress',
                        ],
                        true
                    )
                ) {

                    throw new RuntimeException(
                        'Партію вже завершено.'
                    );
                }

                /*
                 * Незавершене скло.
                 */

                $itemsStmt =
                    $db->prepare("
                        SELECT
                            pbi.id
                                AS item_id,

                            pbi.status
                                AS item_status,

                            g.id,
                            g.code,
                            g.order_id,
                            g.order_number,
                            g.status,
                            g.current_step_id,
                            g.current_location,
                            g.route_id,

                            rs.step_number,
                            rs.name
                                AS stage_name

                        FROM production_batch_items pbi

                        JOIN glasses g
                            ON g.id =
                                pbi.glass_id

                        JOIN route_steps rs
                            ON rs.id =
                                g.current_step_id

                        WHERE pbi.batch_id =
                            :batch_id

                          AND pbi.status =
                            'pending'

                        ORDER BY pbi.id
                    ");

                $itemsStmt->execute([
                    ':batch_id' =>
                        $batchId,
                ]);

                $pendingItems =
                    $itemsStmt->fetchAll(
                        PDO::FETCH_ASSOC
                    );

                $completedCount = 0;

                /*
                 * Завершуємо кожне pending-скло.
                 */

                foreach (
                    $pendingItems
                    as $glass
                ) {

                    if (
                        (int)
                        $glass[
                            'current_step_id'
                        ]
                        !==
                        (int)
                        $batch[
                            'route_step_id'
                        ]
                    ) {

                        throw new RuntimeException(
                            'Скло '
                            . $glass[
                                'code'
                            ]
                            . ' вже знаходиться на іншій дільниці.'
                        );
                    }

                    /*
                     * Наступний етап.
                     */

                    $nextStmt =
                        $db->prepare("
                            SELECT
                                id,
                                step_number,
                                name

                            FROM route_steps

                            WHERE route_id =
                                :route_id

                              AND step_number =
                                :step_number

                            LIMIT 1
                        ");

                    $nextStmt->execute([
                        ':route_id' =>
                            (int)
                            $glass[
                                'route_id'
                            ],

                        ':step_number' =>
                            (int)
                            $glass[
                                'step_number'
                            ]
                            + 1,
                    ]);

                    $nextStep =
                        $nextStmt->fetch(
                            PDO::FETCH_ASSOC
                        );

                    if ($nextStep) {

                        $newStatus =
                            'waiting';

                        $newStepId =
                            (int)
                            $nextStep[
                                'id'
                            ];

                        $newLocation =
                            $nextStep[
                                'name'
                            ];

                        $toStage =
                            $nextStep[
                                'name'
                            ];

                    } else {

                        $newStatus =
                            'completed';

                        $newStepId =
                            (int)
                            $glass[
                                'current_step_id'
                            ];

                        $newLocation =
                            'Готово';

                        $toStage =
                            null;
                    }

                    /*
                     * Операція.
                     */

                    $operationStmt =
                        $db->prepare("
                            INSERT INTO glass_operations (
                                glass_id,
                                employee_id,
                                route_step_id,
                                operation_type,
                                from_stage,
                                to_stage,
                                result,
                                batch_id,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :route_step_id,
                                'production',
                                :from_stage,
                                :to_stage,
                                'completed',
                                :batch_id,
                                'Операцію завершено у складі партії.'
                            )
                        ");

                    $operationStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':route_step_id' =>
                            (int)
                            $glass[
                                'current_step_id'
                            ],

                        ':from_stage' =>
                            $glass[
                                'stage_name'
                            ],

                        ':to_stage' =>
                            $toStage,

                        ':batch_id' =>
                            $batchId,
                    ]);

                    /*
                     * Історія.
                     */

                    $historyStmt =
                        $db->prepare("
                            INSERT INTO glass_history (
                                glass_id,
                                employee_id,
                                old_status,
                                new_status,
                                old_location,
                                new_location,
                                comment
                            )
                            VALUES (
                                :glass_id,
                                :employee_id,
                                :old_status,
                                :new_status,
                                :old_location,
                                :new_location,
                                'Партію завершено.'
                            )
                        ");

                    $historyStmt->execute([
                        ':glass_id' =>
                            (int)
                            $glass['id'],

                        ':employee_id' =>
                            (int)
                            $user['id'],

                        ':old_status' =>
                            $glass[
                                'status'
                            ],

                        ':new_status' =>
                            $newStatus,

                        ':old_location' =>
                            $glass[
                                'current_location'
                            ],

                        ':new_location' =>
                            $newLocation,
                    ]);

                    /*
                     * Скло.
                     */

                    $glassUpdate =
                        $db->prepare("
                            UPDATE glasses
                            SET
                                status =
                                    :status,

                                current_step_id =
                                    :step_id,

                                current_location =
                                    :location,

                                employee_id =
                                    NULL,

                                updated_at =
                                    CURRENT_TIMESTAMP

                            WHERE id =
                                :id
                        ");

                    $glassUpdate->execute([
                        ':status' =>
                            $newStatus,

                        ':step_id' =>
                            $newStepId,

                        ':location' =>
                            $newLocation,

                        ':id' =>
                            (int)
                            $glass['id'],
                    ]);

                    /*
                     * Item.
                     */

                    $itemUpdate =
                        $db->prepare("
                            UPDATE production_batch_items
                            SET
                                status =
                                    'completed',

                                completed_at =
                                    CURRENT_TIMESTAMP

                            WHERE batch_id =
                                :batch_id

                              AND glass_id =
                                :glass_id
                        ");

                    $itemUpdate->execute([
                        ':batch_id' =>
                            $batchId,

                        ':glass_id' =>
                            (int)
                            $glass['id'],
                    ]);

                    /*
                     * Сповіщення наступній дільниці.
                     */

                    if ($nextStep) {

                        $nextStageStmt =
                            $db->prepare("
                                SELECT id
                                FROM production_stages
                                WHERE name =
                                    :name
                                  AND active = 1
                                LIMIT 1
                            ");

                        $nextStageStmt->execute([
                            ':name' =>
                                $nextStep[
                                    'name'
                                ],
                        ]);

                        $nextStageId =
                            $nextStageStmt
                                ->fetchColumn();

                        if (
                            $nextStageId !== false
                        ) {

                            notifyStage(
                                $db,
                                (int)
                                $nextStageId,
                                'glass_moved',
                                'Нове скло надійшло на дільницю',
                                'Скло '
                                . $glass[
                                    'code'
                                ]
                                . ' із замовлення '
                                . $glass[
                                    'order_number'
                                ]
                                . ' надійшло на дільницю «'
                                . $nextStep[
                                    'name'
                                ]
                                . '» після завершення партії №'
                                . $batchId
                                . '.',
                                'glass',
                                (int)
                                $glass['id']
                            );
                        }
                    }

                    $completedCount++;
                }

                /*
                 * Скільки браку вже є.
                 */

                $rejectedStmt =
                    $db->prepare("
                        SELECT COUNT(*)
                        FROM production_batch_items
                        WHERE batch_id =
                            :batch_id
                          AND status =
                            'rejected'
                    ");

                $rejectedStmt->execute([
                    ':batch_id' =>
                        $batchId,
                ]);

                $rejectedCount =
                    (int)
                    $rejectedStmt
                        ->fetchColumn();

                /*
                 * Завершуємо партію.
                 */

                $batchUpdate =
                    $db->prepare("
                        UPDATE production_batches
                        SET
                            status =
                                'completed',

                            completed_at =
                                CURRENT_TIMESTAMP

                        WHERE id =
                            :id
                    ");

                $batchUpdate->execute([
                    ':id' =>
                        $batchId,
                ]);

                writeBatchAudit(
                    $db,
                    (int)
                    $user['id'],
                    'complete_batch',
                    'batch',
                    $batchId,
                    [
                        'status' =>
                            $batch[
                                'status'
                            ],
                    ],
                    [
                        'status' =>
                            'completed',

                        'completed_count' =>
                            $completedCount,

                        'rejected_count' =>
                            $rejectedCount,

                        'completed_by_user_id' =>
                            (int)
                            $user['id'],
                    ]
                );

                $db->commit();

                /*
                 * Telegram.
                 */

                try {

                    $telegramMessage =
                        formatTelegramBatchCompleted(
                            $batchId,
                            $batch[
                                'order_number'
                            ],
                            $batch[
                                'stage_name'
                            ],
                            $user[
                                'name'
                            ]
                            ?? '',
                            $completedCount,
                            $rejectedCount
                        );

                    $telegramResult =
                        sendTelegramToGroup(
                            $db,
                            $telegramMessage
                        );

                    writeBatchAudit(
                        $db,
                        (int)
                        $user['id'],
                        'telegram_notification',
                        'batch',
                        $batchId,
                        null,
                        [
                            'event' =>
                                'batch_completed',

                            'sent' =>
                                $telegramResult[
                                    'sent'
                                ]
                                ?? false,

                            'group' =>
                                $telegramResult[
                                    'group_title'
                                ]
                                ?? null,

                            'error' =>
                                $telegramResult[
                                    'error'
                                ]
                                ?? null,
                        ]
                    );

                } catch (
                    Throwable
                    $telegramException
                ) {
                    // Telegram не змінює виробничий результат.
                }

                $messageType =
                    'success';

                $messageTitle =
                    '✅ ПАРТІЮ ЗАВЕРШЕНО';

                $messageText =
                    'Готово: '
                    . $completedCount
                    . '. Брак: '
                    . $rejectedCount
                    . '.';

                /*
                 * Оновлюємо локальний статус сторінки.
                 */

                $batch[
                    'status'
                ] =
                    'completed';

            } catch (
                Throwable
                $exception
            ) {

                if (
                    $db->inTransaction()
                ) {
                    $db->rollBack();
                }

                $messageType =
                    'error';

                $messageTitle =
                    'Партію не завершено';

                $messageText =
                    e(
                        $exception
                            ->getMessage()
                    );
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Оновлюємо дані партії після POST
|--------------------------------------------------------------------------
*/

$batchStmt->execute([
    ':id' =>
        $batchId,
]);

$batch =
    $batchStmt->fetch(
        PDO::FETCH_ASSOC
    );

/*
|--------------------------------------------------------------------------
| Скло партії
|--------------------------------------------------------------------------
*/

$itemsStmt =
    $db->prepare("
        SELECT
            pbi.id
                AS item_id,

            pbi.status
                AS item_status,

            pbi.completed_at,

            g.id
                AS glass_id,

            g.code,
            g.order_number,
            g.glass_type,
            g.width,
            g.height,
            g.thickness,
            g.quantity,
            g.status
                AS glass_status,

            g.current_location

        FROM production_batch_items pbi

        JOIN glasses g
            ON g.id =
                pbi.glass_id

        WHERE pbi.batch_id =
            :batch_id

        ORDER BY pbi.id
    ");

$itemsStmt->execute([
    ':batch_id' =>
        $batchId,
]);

$items =
    $itemsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

$totalCount =
    count($items);

$completedCount = 0;
$rejectedCount = 0;
$pendingCount = 0;
$totalArea = 0.0;

foreach (
    $items
    as $item
) {

    if (
        $item[
            'item_status'
        ]
        ===
        'completed'
    ) {
        $completedCount++;
    } elseif (
        $item[
            'item_status'
        ]
        ===
        'rejected'
    ) {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }

    $totalArea +=
        (
            (int)
            $item['width']
            *
            (int)
            $item['height']
            *
            max(
                1,
                (int)
                $item['quantity']
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
        Партія №<?= $batchId ?> — OPTIMA GLASS
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .card {
            margin-bottom: 20px;
            padding: 24px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        h1,
        h2 {
            margin-top: 0;
        }

        .meta {
            color: #6b7280;
            line-height: 1.6;
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .summary-card {
            padding: 15px;
            border-radius: 10px;
            background: #f9fafb;
        }

        .summary-value {
            display: block;
            margin-top: 6px;
            font-size: 24px;
            font-weight: 700;
        }

        .message {
            margin-bottom: 20px;
            padding: 16px;
            border-radius: 10px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 11px 9px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }

        .status {
            font-weight: 700;
        }

        .reject-form {
            display: grid;
            grid-template-columns:
                minmax(130px, 180px)
                minmax(160px, 1fr)
                auto;
            gap: 7px;
        }

        select,
        input {
            min-height: 38px;
            padding: 0 9px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
        }

        .button {
            min-height: 40px;
            padding: 0 14px;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
        }

        .button-danger {
            background: #b91c1c;
            color: #fff;
        }

        .button-primary {
            background: #111827;
            color: #fff;
        }

        .button-secondary {
            display: inline-flex;
            align-items: center;
            padding: 0 14px;
            min-height: 40px;
            border-radius: 8px;
            background: #f3f4f6;
            color: #111827;
            text-decoration: none;
        }

        .complete-form {
            margin-top: 20px;
        }

        @media (
            max-width: 800px
        ) {

            .summary {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .reject-form {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<?php
require __DIR__
    . '/../src/partials/header.php';
?>

<main class="page">

    <section class="card">

        <h1>
            Партія №<?= $batchId ?>
        </h1>

        <div class="meta">

            Замовлення:
            <strong>
                <?= e(
                    $batch[
                        'order_number'
                    ]
                ) ?>
            </strong>

            <br>

            Дільниця:
            <strong>
                <?= e(
                    $batch[
                        'stage_name'
                    ]
                ) ?>
            </strong>

            <br>

            Виконавець:
            <strong>
                <?= e(
                    $batch[
                        'assigned_employee_name'
                    ]
                    ?? 'Не призначено'
                ) ?>
            </strong>

            <br>

            Статус:
            <strong>
                <?= e(
                    $batch[
                        'status'
                    ]
                ) ?>
            </strong>

        </div>


        <div class="summary">

            <div class="summary-card">

                Всього

                <span class="summary-value">
                    <?= $totalCount ?>
                </span>

            </div>

            <div class="summary-card">

                Готово

                <span class="summary-value">
                    <?= $completedCount ?>
                </span>

            </div>

            <div class="summary-card">

                Брак

                <span class="summary-value">
                    <?= $rejectedCount ?>
                </span>

            </div>

            <div class="summary-card">

                Площа

                <span class="summary-value">

                    <?= number_format(
                        $totalArea,
                        2,
                        ',',
                        ' '
                    ) ?>

                    м²

                </span>

            </div>

        </div>

    </section>


    <?php if (
        $messageType !== ''
    ): ?>

        <div
            class="message <?= e(
                $messageType
            ) ?>"
        >

            <strong>
                <?= e(
                    $messageTitle
                ) ?>
            </strong>

            <br><br>

            <?= $messageText ?>

        </div>

    <?php endif; ?>


    <section class="card">

        <h2>
            Скло партії
        </h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Скло</th>
                        <th>Розмір</th>
                        <th>Товщина</th>
                        <th>Статус</th>
                        <th>Розташування</th>
                        <th>Дія</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach (
                    $items
                    as $index =>
                        $item
                ): ?>

                    <tr>

                        <td>
                            <?= $index + 1 ?>
                        </td>

                        <td>

                            <strong>
                                <?= e(
                                    $item[
                                        'code'
                                    ]
                                ) ?>
                            </strong>

                            <?php if (
                                !empty(
                                    $item[
                                        'glass_type'
                                    ]
                                )
                            ): ?>

                                <br>

                                <small>
                                    <?= e(
                                        $item[
                                            'glass_type'
                                        ]
                                    ) ?>
                                </small>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?= (int)
                                $item[
                                    'width'
                                ] ?>

                            ×

                            <?= (int)
                                $item[
                                    'height'
                                ] ?>

                            мм

                        </td>

                        <td>

                            <?= $item[
                                'thickness'
                            ] !== null
                                ? e(
                                    (string)
                                    $item[
                                        'thickness'
                                    ]
                                ) . ' мм'
                                : '—'
                            ?>

                        </td>

                        <td class="status">

                            <?php

                            echo match (
                                $item[
                                    'item_status'
                                ]
                            ) {
                                'completed' =>
                                    '✅ Готово',

                                'rejected' =>
                                    '❌ Брак',

                                default =>
                                    '⏳ Очікує',
                            };

                            ?>

                        </td>

                        <td>

                            <?= e(
                                $item[
                                    'current_location'
                                ]
                            ) ?>

                        </td>

                        <td>

                            <?php if (
                                $item[
                                    'item_status'
                                ]
                                === 'pending'
                                &&
                                $canReject
                                &&
                                in_array(
                                    $batch[
                                        'status'
                                    ],
                                    [
                                        'created',
                                        'in_progress',
                                    ],
                                    true
                                )
                            ): ?>

                                <form
                                    method="post"
                                    class="reject-form"
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
                                        value="reject_item"
                                    >

                                    <input
                                        type="hidden"
                                        name="batch_id"
                                        value="<?= $batchId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="glass_id"
                                        value="<?= (int)
                                            $item[
                                                'glass_id'
                                            ] ?>"
                                    >

                                    <select
                                        name="reason"
                                        required
                                    >

                                        <option value="">
                                            Причина
                                        </option>

                                        <?php foreach (
                                            $rejectionReasons
                                            as $rejectionReason
                                        ): ?>

                                            <option
                                                value="<?= e(
                                                    $rejectionReason
                                                ) ?>"
                                            >
                                                <?= e(
                                                    $rejectionReason
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <input
                                        type="text"
                                        name="comment"
                                        placeholder="Коментар"
                                    >

                                    <button
                                        type="submit"
                                        class="button button-danger"
                                    >
                                        ❌ Брак
                                    </button>

                                </form>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <?php if (
            $canComplete
            &&
            in_array(
                $batch[
                    'status'
                ],
                [
                    'created',
                    'in_progress',
                ],
                true
            )
        ): ?>

            <form
                method="post"
                class="complete-form"
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
                    value="complete_batch"
                >

                <input
                    type="hidden"
                    name="batch_id"
                    value="<?= $batchId ?>"
                >

                <button
                    type="submit"
                    class="button button-primary"
                    onclick="return confirm('Завершити партію та передати всі незабраковані стекла на наступний етап?');"
                >
                    ✅ Завершити партію
                </button>

            </form>

        <?php endif; ?>


        <p style="margin-top:20px;">

            <a
                href="/work.php"
                class="button-secondary"
            >
                ← Повернутися до роботи
            </a>

        </p>

    </section>

</main>

</body>

</html>
