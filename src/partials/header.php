<?php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../permissions.php';

if (
    session_status()
    !== PHP_SESSION_ACTIVE
) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Поточний користувач
|--------------------------------------------------------------------------
*/

$headerUser =
    isset($user) && is_array($user)
        ? $user
        : current_user();

$currentPage = basename(
    $_SERVER['PHP_SELF'] ?? ''
);

$headerRole = $headerUser['role'] ?? '';

/*
|--------------------------------------------------------------------------
| Назви ролей
|--------------------------------------------------------------------------
*/

$headerRoleLabels = [
    'superadmin'      => 'Суперадміністратор',
    'admin'           => 'Адміністратор',
    'manager'         => 'Менеджер',
    'section_manager' => 'Начальник дільниці',
    'employee'        => 'Працівник',
];

/*
|--------------------------------------------------------------------------
| Active menu
|--------------------------------------------------------------------------
*/

if (!function_exists('headerActive')) {

    function headerActive(
        string $page,
        string $currentPage
    ): string {
        return $page === $currentPage
            ? 'nav-active'
            : '';
    }
}

/*
|--------------------------------------------------------------------------
| Додавання пункту меню
|--------------------------------------------------------------------------
*/

if (!function_exists('headerMenuItem')) {

    function headerMenuItem(
        string $url,
        string $label,
        string $page,
        string $currentPage,
        bool $allowed = true,
        string $extraClass = ''
    ): void {

        if (!$allowed) {
            return;
        }

        $classes = trim(
            headerActive(
                $page,
                $currentPage
            )
            . ' '
            . $extraClass
        );

        ?>
        <a
            href="<?= htmlspecialchars(
                $url,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="<?= htmlspecialchars(
                $classes,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >
            <?= htmlspecialchars(
                $label,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </a>
        <?php
    }
}

?>
<style>

    .app-header {
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
    }

    .app-header-inner {
        max-width: 1400px;
        margin: 0 auto;
        padding: 14px 20px;
    }

    .app-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }

    .app-brand {
        color: #111827;
        text-decoration: none;
        font-size: 20px;
        font-weight: 800;
        white-space: nowrap;
    }

    .app-user {
        color: #6b7280;
        font-size: 13px;
        text-align: right;
        line-height: 1.45;
    }

    .app-user strong {
        color: #111827;
    }

    .app-user-role {
        color: #2563eb;
        font-weight: 600;
    }

    .app-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 13px;
    }

    .app-nav a {
        display: inline-flex;
        align-items: center;
        min-height: 38px;
        padding: 0 11px;
        border-radius: 7px;
        color: #374151;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition:
            background .15s ease,
            color .15s ease;
    }

    .app-nav a:hover {
        background: #f3f4f6;
        color: #111827;
    }

    .app-nav a.nav-active {
        background: #111827;
        color: #ffffff;
    }

    .app-nav .logout-link {
        color: #991b1b;
        margin-left: auto;
    }

    .app-nav .logout-link:hover {
        background: #fee2e2;
        color: #991b1b;
    }

    .app-nav .shipping-link {
        font-weight: 700;
    }

    @media (max-width: 900px) {

        .app-topbar {
            align-items: flex-start;
        }

        .app-nav {
            gap: 5px;
        }

        .app-nav a {
            padding: 0 9px;
            font-size: 13px;
        }

        .app-nav .logout-link {
            margin-left: 0;
        }

    }

</style>


<header class="app-header">

    <div class="app-header-inner">

        <div class="app-topbar">

            <a
                href="/"
                class="app-brand"
            >
                OPTIMA GLASS
            </a>


            <?php if ($headerUser): ?>

                <div class="app-user">

                    <strong>
                        <?= htmlspecialchars(
                            $headerUser['name'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>

                    <br>

                    <span class="app-user-role">
                        <?= htmlspecialchars(
                            $headerRoleLabels[$headerRole]
                                ?? $headerRole,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </span>

                    <?php if (
                        !empty(
                            $headerUser['stage_name']
                        )
                    ): ?>

                        <br>

                        <?= htmlspecialchars(
                            $headerUser['stage_name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>


        <?php if ($headerUser): ?>

            <nav class="app-nav">

                <?php

                /*
                |--------------------------------------------------------------------------
                | SUPERADMIN
                |--------------------------------------------------------------------------
                */

                if ($headerRole === 'superadmin') {

                    headerMenuItem(
                        '/',
                        '🏠 Головна',
                        'index.php',
                        $currentPage
                    );

                    headerMenuItem(
                        '/production.php',
                        '🏭 Виробництво',
                        'production.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glasses.php',
                        '🪟 Скло',
                        'glasses.php',
                        $currentPage,
                        can(
                            'glass.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glass_create.php',
                        '➕ Нове замовлення',
                        'glass_create.php',
                        $currentPage,
                        can(
                            'glass.create',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/shipping.php',
                        '🚚 Відвантаження',
                        'shipping.php',
                        $currentPage,
                        can(
                            'production.ship',
                            $headerUser
                        ),
                        'shipping-link'
                    );

                    headerMenuItem(
                        '/manager.php',
                        '📋 Менеджер',
                        'manager.php',
                        $currentPage,
                        can(
                            'orders.start_production',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/employees.php',
                        '👥 Працівники',
                        'employees.php',
                        $currentPage,
                        can(
                            'employees.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/roles.php',
                        '🔐 Ролі',
                        'roles.php',
                        $currentPage,
                        can(
                            'system.roles',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/user_permissions.php',
                        '🛡 Права',
                        'user_permissions.php',
                        $currentPage,
                        can(
                            'user_permissions.manage',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/stages.php',
                        '🏗 Дільниці',
                        'stages.php',
                        $currentPage,
                        can(
                            'system.settings',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/glass_types.php',
                        '🪟 Типи скла',
                        'glass_types.php',
                        $currentPage,
                        can(
                            'system.settings',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/import.php',
                        '📥 Імпорт',
                        'import.php',
                        $currentPage,
                        can(
                            'orders.create',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/notifications.php',
                        '🔔 Сповіщення',
                        'notifications.php',
                        $currentPage,
                        can(
                            'notifications.view',
                            $headerUser
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | ADMIN
                |--------------------------------------------------------------------------
                */

                elseif ($headerRole === 'admin') {

                    headerMenuItem(
                        '/',
                        '🏠 Головна',
                        'index.php',
                        $currentPage
                    );

                    headerMenuItem(
                        '/production.php',
                        '🏭 Виробництво',
                        'production.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glasses.php',
                        '🪟 Скло',
                        'glasses.php',
                        $currentPage,
                        can(
                            'glass.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glass_create.php',
                        '➕ Нове замовлення',
                        'glass_create.php',
                        $currentPage,
                        can(
                            'glass.create',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/shipping.php',
                        '🚚 Відвантаження',
                        'shipping.php',
                        $currentPage,
                        can(
                            'production.ship',
                            $headerUser
                        ),
                        'shipping-link'
                    );

                    headerMenuItem(
                        '/manager.php',
                        '📋 Менеджер',
                        'manager.php',
                        $currentPage,
                        can(
                            'orders.start_production',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/employees.php',
                        '👥 Працівники',
                        'employees.php',
                        $currentPage,
                        can(
                            'employees.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/stages.php',
                        '🏗 Дільниці',
                        'stages.php',
                        $currentPage,
                        can(
                            'system.settings',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/glass_types.php',
                        '🪟 Типи скла',
                        'glass_types.php',
                        $currentPage,
                        can(
                            'system.settings',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/admin/import.php',
                        '📥 Імпорт',
                        'import.php',
                        $currentPage,
                        can(
                            'orders.create',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/notifications.php',
                        '🔔 Сповіщення',
                        'notifications.php',
                        $currentPage,
                        can(
                            'notifications.view',
                            $headerUser
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | MANAGER
                |--------------------------------------------------------------------------
                */

                elseif ($headerRole === 'manager') {

                    headerMenuItem(
                        '/',
                        '🏠 Головна',
                        'index.php',
                        $currentPage
                    );

                    headerMenuItem(
                        '/production.php',
                        '🏭 Виробництво',
                        'production.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glasses.php',
                        '🪟 Скло',
                        'glasses.php',
                        $currentPage,
                        can(
                            'glass.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glass_create.php',
                        '➕ Нове замовлення',
                        'glass_create.php',
                        $currentPage,
                        can(
                            'glass.create',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/shipping.php',
                        '🚚 Відвантаження',
                        'shipping.php',
                        $currentPage,
                        can(
                            'production.ship',
                            $headerUser
                        ),
                        'shipping-link'
                    );

                    headerMenuItem(
                        '/manager.php',
                        '📋 Менеджер',
                        'manager.php',
                        $currentPage,
                        can(
                            'orders.start_production',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/notifications.php',
                        '🔔 Сповіщення',
                        'notifications.php',
                        $currentPage,
                        can(
                            'notifications.view',
                            $headerUser
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | SECTION MANAGER
                |--------------------------------------------------------------------------
                */

                elseif ($headerRole === 'section_manager') {

                    headerMenuItem(
                        '/',
                        '🏠 Головна',
                        'index.php',
                        $currentPage
                    );

                    headerMenuItem(
                        '/work.php',
                        '👷 Моя робота',
                        'work.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                        && !empty(
                            $headerUser['stage_id']
                        )
                    );

                    headerMenuItem(
                        '/production.php',
                        '🏭 Виробництво',
                        'production.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/glasses.php',
                        '🪟 Скло',
                        'glasses.php',
                        $currentPage,
                        can(
                            'glass.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/scan.php',
                        '📷 QR',
                        'scan.php',
                        $currentPage,
                        can(
                            'glass.scan',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/notifications.php',
                        '🔔 Сповіщення',
                        'notifications.php',
                        $currentPage,
                        can(
                            'notifications.view',
                            $headerUser
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | EMPLOYEE
                |--------------------------------------------------------------------------
                */

                elseif ($headerRole === 'employee') {

                    headerMenuItem(
                        '/work.php',
                        '👷 Моя робота',
                        'work.php',
                        $currentPage,
                        can(
                            'production.view',
                            $headerUser
                        )
                        && !empty(
                            $headerUser['stage_id']
                        )
                    );

                    headerMenuItem(
                        '/glasses.php',
                        '🪟 Скло',
                        'glasses.php',
                        $currentPage,
                        can(
                            'glass.view',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/scan.php',
                        '📷 QR',
                        'scan.php',
                        $currentPage,
                        can(
                            'glass.scan',
                            $headerUser
                        )
                    );

                    headerMenuItem(
                        '/notifications.php',
                        '🔔 Сповіщення',
                        'notifications.php',
                        $currentPage,
                        can(
                            'notifications.view',
                            $headerUser
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Невідома роль
                |--------------------------------------------------------------------------
                */

                else {

                    headerMenuItem(
                        '/',
                        '🏠 Головна',
                        'index.php',
                        $currentPage
                    );
                }

                ?>

                <a
                    href="/logout.php"
                    class="logout-link"
                >
                    🚪 Вийти
                </a>

            </nav>

        <?php endif; ?>

    </div>

</header>
