<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OPTIMA GLASS — спільна робота
|--------------------------------------------------------------------------
|
| Одна glass_operations = одна виробнича операція.
|
| Персональний виробіток зберігається у:
| glass_operation_workers
|
*/


function teamGlassArea(array $glass): float
{
    return (
        (float)($glass['width'] ?? 0)
        *
        (float)($glass['height'] ?? 0)
        *
        (float)($glass['quantity'] ?? 1)
    ) / 1000000.0;
}


function resolveTeamStageId(
    PDO $db,
    int|string $stage
): ?int {

    if (
        is_int($stage)
        ||
        (
            is_string($stage)
            &&
            ctype_digit($stage)
        )
    ) {

        $stageId = (int)$stage;

        return $stageId > 0
            ? $stageId
            : null;
    }

    $stageName = trim((string)$stage);

    if ($stageName === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id
        FROM production_stages
        WHERE name = :name
        LIMIT 1
    ");

    $stmt->execute([
        ':name' => $stageName,
    ]);

    $value = $stmt->fetchColumn();

    return $value !== false
        ? (int)$value
        : null;
}


function recordOperationWorkers(
    PDO $db,
    int $operationId,
    int $employeeId,
    int|string $stage,
    array $glass
): void {

    if ($operationId <= 0) {
        throw new RuntimeException(
            'Некоректний ID виробничої операції.'
        );
    }

    if ($employeeId <= 0) {
        throw new RuntimeException(
            'Некоректний ID працівника.'
        );
    }

    $area = teamGlassArea($glass);

    $stageId = resolveTeamStageId(
        $db,
        $stage
    );

    $sessionId = null;

    /*
     * Шукаємо активну спільну роботу
     * на фактичній дільниці операції.
     */
    if ($stageId !== null) {

        $sessionStmt = $db->prepare("
            SELECT ws.id

            FROM work_sessions ws

            JOIN work_session_members wsm
                ON wsm.work_session_id = ws.id
               AND wsm.employee_id = :employee_id

            WHERE ws.active = 1
              AND ws.mode = 'team'
              AND ws.stage_id = :stage_id

            ORDER BY ws.id DESC

            LIMIT 1
        ");

        $sessionStmt->execute([
            ':employee_id' => $employeeId,
            ':stage_id' => $stageId,
        ]);

        $value = $sessionStmt->fetchColumn();

        if ($value !== false) {
            $sessionId = (int)$value;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SOLO
    |--------------------------------------------------------------------------
    */

    if (!$sessionId) {

        $stmt = $db->prepare("
            INSERT INTO glass_operation_workers (
                operation_id,
                employee_id,
                share_percent,
                area_m2
            )
            VALUES (
                :operation_id,
                :employee_id,
                100,
                :area_m2
            )
        ");

        $stmt->execute([
            ':operation_id' => $operationId,
            ':employee_id' => $employeeId,
            ':area_m2' => $area,
        ]);

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | TEAM
    |--------------------------------------------------------------------------
    */

    $membersStmt = $db->prepare("
        SELECT
            employee_id,
            share_percent

        FROM work_session_members

        WHERE work_session_id = :session_id

        ORDER BY id
    ");

    $membersStmt->execute([
        ':session_id' => $sessionId,
    ]);

    $members = $membersStmt->fetchAll(
        PDO::FETCH_ASSOC
    );

    /*
     * Страховка:
     * якщо session пошкоджена і учасників немає,
     * виробіток не втрачаємо.
     */
    if (!$members) {

        $stmt = $db->prepare("
            INSERT INTO glass_operation_workers (
                operation_id,
                employee_id,
                share_percent,
                area_m2
            )
            VALUES (
                :operation_id,
                :employee_id,
                100,
                :area_m2
            )
        ");

        $stmt->execute([
            ':operation_id' => $operationId,
            ':employee_id' => $employeeId,
            ':area_m2' => $area,
        ]);

        return;
    }

    $totalShare = 0.0;

    foreach ($members as $member) {

        $totalShare +=
            (float)$member['share_percent'];
    }

    if ($totalShare <= 0) {

        throw new RuntimeException(
            'Некоректний розподіл виробітку команди.'
        );
    }

    $insertStmt = $db->prepare("
        INSERT INTO glass_operation_workers (
            operation_id,
            employee_id,
            share_percent,
            area_m2
        )
        VALUES (
            :operation_id,
            :employee_id,
            :share_percent,
            :area_m2
        )
    ");

    foreach ($members as $member) {

        $normalizedShare =
            (
                (float)$member['share_percent']
                /
                $totalShare
            )
            * 100.0;

        $memberArea =
            $area
            *
            $normalizedShare
            /
            100.0;

        $insertStmt->execute([
            ':operation_id' =>
                $operationId,

            ':employee_id' =>
                (int)$member['employee_id'],

            ':share_percent' =>
                $normalizedShare,

            ':area_m2' =>
                $memberArea,
        ]);
    }
}
