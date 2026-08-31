<?php

/*
|--------------------------------------------------------------------------
| Система разрешений OPTIMA GLASS
|--------------------------------------------------------------------------
|
| Приоритет:
|
| 1. Индивидуальное право пользователя
| 2. Право роли
| 3. Запрет по умолчанию
|
| user_permissions.allowed:
| NULL = наследовать право роли
| 1    = разрешить
| 0    = запретить
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Текущий пользователь
|--------------------------------------------------------------------------
*/

function permissionUser(): ?array
{
    return current_user();
}


/*
|--------------------------------------------------------------------------
| Является ли пользователь суперадмином
|--------------------------------------------------------------------------
*/

function isSuperAdmin(?array $user = null): bool
{
    $user ??= permissionUser();

    if (!$user) {
        return false;
    }

    return ($user['role'] ?? '') === 'superadmin';
}


/*
|--------------------------------------------------------------------------
| Получить ID роли пользователя
|--------------------------------------------------------------------------
*/

function userRoleId(
    PDO $db,
    array $user
): ?int {

    /*
     * Сначала используем role_id,
     * если auth.php уже его возвращает.
     */

    if (
        isset($user['role_id'])
        &&
        $user['role_id'] !== null
    ) {
        return (int) $user['role_id'];
    }

    $role = $user['role'] ?? '';

    if ($role === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id
        FROM roles
        WHERE name = :name
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':name' =>
            $role,
    ]);

    $roleId =
        $stmt->fetchColumn();

    if ($roleId === false) {
        return null;
    }

    return (int) $roleId;
}


/*
|--------------------------------------------------------------------------
| Получить ID разрешения
|--------------------------------------------------------------------------
*/

function permissionId(
    PDO $db,
    string $permission
): ?int {

    $stmt = $db->prepare("
        SELECT id
        FROM permissions
        WHERE code = :code
          AND active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':code' =>
            $permission,
    ]);

    $permissionId =
        $stmt->fetchColumn();

    if ($permissionId === false) {
        return null;
    }

    return (int) $permissionId;
}


/*
|--------------------------------------------------------------------------
| Проверка разрешения
|--------------------------------------------------------------------------
*/

function can(
    string $permission,
    ?array $user = null
): bool {

    global $db;

    $user ??= permissionUser();

    if (!$user) {
        return false;
    }

    /*
     * Суперадмин получает все активные права.
     */

    if (isSuperAdmin($user)) {
        return true;
    }

    $permissionId =
        permissionId(
            $db,
            $permission
        );

    if ($permissionId === null) {
        return false;
    }

    $userId =
        (int) $user['id'];

    /*
     * ---------------------------------------------------------------
     * 1. Проверяем индивидуальное право
     * ---------------------------------------------------------------
     */

    $userPermissionStmt =
        $db->prepare("
            SELECT
                allowed
            FROM user_permissions
            WHERE user_id = :user_id
              AND permission_id = :permission_id
            LIMIT 1
        ");

    $userPermissionStmt->execute([
        ':user_id' =>
            $userId,

        ':permission_id' =>
            $permissionId,
    ]);

    $userPermission =
        $userPermissionStmt->fetchColumn();

    /*
     * Если индивидуальное право установлено
     * и не NULL — оно имеет приоритет.
     */

    if (
        $userPermission !== false
        &&
        $userPermission !== null
    ) {

        return (int) $userPermission === 1;
    }

    /*
     * ---------------------------------------------------------------
     * 2. Проверяем право роли
     * ---------------------------------------------------------------
     */

    $roleId =
        userRoleId(
            $db,
            $user
        );

    if ($roleId === null) {
        return false;
    }

    $rolePermissionStmt =
        $db->prepare("
            SELECT
                allowed
            FROM role_permissions
            WHERE role_id = :role_id
              AND permission_id = :permission_id
            LIMIT 1
        ");

    $rolePermissionStmt->execute([
        ':role_id' =>
            $roleId,

        ':permission_id' =>
            $permissionId,
    ]);

    $rolePermission =
        $rolePermissionStmt->fetchColumn();

    if ($rolePermission === false) {
        return false;
    }

    return (int) $rolePermission === 1;
}


/*
|--------------------------------------------------------------------------
| Обязательное разрешение
|--------------------------------------------------------------------------
*/

function require_permission(
    string $permission,
    ?array $user = null
): void {

    if (
        !can(
            $permission,
            $user
        )
    ) {

        http_response_code(403);

        exit(
            'Доступ запрещён. '
            . 'Необходимо разрешение: '
            . $permission
        );
    }
}


/*
|--------------------------------------------------------------------------
| Проверка любого из разрешений
|--------------------------------------------------------------------------
*/

function canAny(
    array $permissions,
    ?array $user = null
): bool {

    foreach ($permissions as $permission) {

        if (
            can(
                $permission,
                $user
            )
        ) {
            return true;
        }
    }

    return false;
}


/*
|--------------------------------------------------------------------------
| Проверка всех разрешений
|--------------------------------------------------------------------------
*/

function canAll(
    array $permissions,
    ?array $user = null
): bool {

    foreach ($permissions as $permission) {

        if (
            !can(
                $permission,
                $user
            )
        ) {
            return false;
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Получить все эффективные права пользователя
|--------------------------------------------------------------------------
*/

function effectivePermissions(
    ?array $user = null
): array {

    global $db;

    $user ??= permissionUser();

    if (!$user) {
        return [];
    }

    /*
     * Суперадмин получает все права.
     */

    if (isSuperAdmin($user)) {

        $stmt = $db->query("
            SELECT
                code,
                title,
                category
            FROM permissions
            WHERE active = 1
            ORDER BY
                category,
                id
        ");

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    $roleId =
        userRoleId(
            $db,
            $user
        );

    if ($roleId === null) {
        return [];
    }

    $userId =
        (int) $user['id'];

    $stmt = $db->prepare("
        SELECT
            p.id,
            p.code,
            p.title,
            p.category,

            rp.allowed AS role_allowed,

            up.allowed AS user_allowed

        FROM permissions p

        LEFT JOIN role_permissions rp
            ON rp.permission_id = p.id
           AND rp.role_id = :role_id

        LEFT JOIN user_permissions up
            ON up.permission_id = p.id
           AND up.user_id = :user_id

        WHERE p.active = 1

        ORDER BY
            p.category,
            p.id
    ");

    $stmt->execute([
        ':role_id' =>
            $roleId,

        ':user_id' =>
            $userId,
    ]);

    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    $result = [];

    foreach ($rows as $row) {

        /*
         * Индивидуальное право имеет приоритет.
         */

        if (
            $row['user_allowed'] !== null
        ) {

            $allowed =
                (int)
                $row['user_allowed']
                === 1;

        } else {

            $allowed =
                (int)
                (
                    $row['role_allowed']
                    ?? 0
                )
                === 1;
        }

        if ($allowed) {

            $result[] = [
                'code' =>
                    $row['code'],

                'title' =>
                    $row['title'],

                'category' =>
                    $row['category'],
            ];
        }
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| Удобная функция для проверки доступа к странице
|--------------------------------------------------------------------------
*/

function requirePermission(
    string $permission
): void {
    require_permission(
        $permission
    );
}


/*
|--------------------------------------------------------------------------
| Запись изменения права в audit_log
|--------------------------------------------------------------------------
*/

function auditPermissionChange(
    PDO $db,
    int $userId,
    string $action,
    string $scope,
    int $targetId,
    string $permission,
    mixed $oldValue,
    mixed $newValue
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
            'permission',

        ':entity_id' =>
            $targetId,

        ':old_value' =>
            json_encode(
                [
                    'scope' =>
                        $scope,

                    'permission' =>
                        $permission,

                    'allowed' =>
                        $oldValue,
                ],
                JSON_UNESCAPED_UNICODE
            ),

        ':new_value' =>
            json_encode(
                [
                    'scope' =>
                        $scope,

                    'permission' =>
                        $permission,

                    'allowed' =>
                        $newValue,
                ],
                JSON_UNESCAPED_UNICODE
            ),

        ':ip_address' =>
            $_SERVER['REMOTE_ADDR']
            ?? null,

        ':user_agent' =>
            $_SERVER['HTTP_USER_AGENT']
            ?? null,
    ]);
}
