<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

/**
 * Проверяет, авторизован ли пользователь.
 */
function require_login(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Возвращает актуальные данные текущего пользователя из БД.
 */
function current_user(): ?array
{
    global $db;

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.role,
            u.active,
            u.stage_id,
            ps.name AS stage_name
        FROM users u
        LEFT JOIN production_stages ps
            ON ps.id = u.stage_id
        WHERE u.id = :id
          AND u.active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => (int) $_SESSION['user_id'],
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_email'],
            $_SESSION['user_name'],
            $_SESSION['user_role']
        );

        return null;
    }

    return $user;
}

/**
 * Возвращает текущего пользователя или завершает запрос.
 */
function require_user(): array
{
    require_login();

    $user = current_user();

    if (!$user) {
        header('Location: /login.php');
        exit;
    }

    return $user;
}

/**
 * Проверяет наличие одной из переданных ролей.
 */
function has_role(array $roles, ?array $user = null): bool
{
    $user ??= current_user();

    if (!$user) {
        return false;
    }

    return in_array($user['role'], $roles, true);
}

/**
 * Требует одну из указанных ролей.
 */
function require_roles(array $roles): array
{
    $user = require_user();

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Доступ запрещён.');
    }

    return $user;
}

/**
 * Суперадмин.
 */
function is_superadmin(?array $user = null): bool
{
    return has_role(['superadmin'], $user);
}

/**
 * Администратор или суперадмин.
 */
function is_admin(?array $user = null): bool
{
    return has_role(['superadmin', 'admin'], $user);
}

/**
 * Менеджер, администратор или суперадмин.
 */
function can_manage_orders(?array $user = null): bool
{
    return has_role(
        ['superadmin', 'admin', 'manager'],
        $user
    );
}

/**
 * Пользователь, который имеет доступ к производственному управлению.
 */
function can_manage_production(?array $user = null): bool
{
    return has_role(
        [
            'superadmin',
            'admin',
            'manager',
            'section_manager',
        ],
        $user
    );
}

/**
 * Начальник участка.
 */
function is_section_manager(?array $user = null): bool
{
    return has_role(['section_manager'], $user);
}

/**
 * Сотрудник.
 */
function is_employee(?array $user = null): bool
{
    return has_role(['employee'], $user);
}

/**
 * Получает ID участка текущего пользователя.
 */
function current_stage_id(?array $user = null): ?int
{
    $user ??= current_user();

    if (!$user || $user['stage_id'] === null) {
        return null;
    }

    return (int) $user['stage_id'];
}

/**
 * Проверяет, относится ли пользователь к указанному участку.
 *
 * Для superadmin/admin/manager возвращает true,
 * поскольку они могут работать с производством без ограничения одним участком.
 *
 * Для section_manager и employee проверяется их stage_id.
 */
function can_access_stage(
    int $stageId,
    ?array $user = null
): bool {
    $user ??= current_user();

    if (!$user) {
        return false;
    }

    if (in_array(
        $user['role'],
        ['superadmin', 'admin', 'manager'],
        true
    )) {
        return true;
    }

    return (int) ($user['stage_id'] ?? 0) === $stageId;
}

/**
 * Проверяет, имеет ли пользователь доступ ко всем участкам.
 */
function can_access_all_stages(?array $user = null): bool
{
    $user ??= current_user();

    if (!$user) {
        return false;
    }

    return in_array(
        $user['role'],
        ['superadmin', 'admin', 'manager'],
        true
    );
}
