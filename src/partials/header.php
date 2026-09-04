<?php

/*
|--------------------------------------------------------------------------
| Єдина навігація OPTIMA GLASS
|--------------------------------------------------------------------------
*/

require_once __DIR__
    . '/../permissions.php';

if (
    session_status()
    !==
    PHP_SESSION_ACTIVE
) {
    session_start();
}

$currentPage =
    basename(
        $_SERVER[
            'PHP_SELF'
        ]
        ?? ''
    );

/*
|--------------------------------------------------------------------------
| Поточний користувач
|--------------------------------------------------------------------------
|
| На основних сторінках змінна $user
| уже отримана через require_user().
|
|--------------------------------------------------------------------------
*/

$headerUser =
    isset($user)
    &&
    is_array($user)
        ? $user
        : null;

/*
|--------------------------------------------------------------------------
| Допоміжна функція активного пункту
|--------------------------------------------------------------------------
*/

function headerActive(
    string $page,
    string $currentPage
): string {
    return $page === $currentPage
        ? 'nav-active'
        : '';
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
    }

    .app-nav a:hover {
        background: #f3f4f6;
        color: #111827;
    }

    .app-nav a.nav-active {
        background: #111827;
        color: #ffffff;
    }

    .app-nav-divider {
        width: 1px;
        min-height: 30px;
        margin: 4px 2px;
        background: #e5e7eb;
    }

    .app-nav .logout-link {
        color: #991b1b;
    }

    .app-nav .shipping-link {
        font-weight: 700;
    }

    @media (
        max-width: 800px
    ) {

        .app-topbar {
            align-items: flex-start;
        }

        .app-nav-divider {
            display: none;
        }

        .app-nav {
            gap: 5px;
        }

        .app-nav a {
            padding: 0 9px;
            font-size: 13px;
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


            <?php if (
                $headerUser
            ): ?>

                <div class="app-user">

                    <strong>
                        <?= htmlspecialchars(
                            $headerUser[
                                'name'
                            ]
                            ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>

                    <?php if (
                        !empty(
                            $headerUser[
                                'stage_name'
                            ]
                        )
                    ): ?>

                        <br>

                        <?= htmlspecialchars(
                            $headerUser[
                                'stage_name'
                            ],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>


        <?php if (
            $headerUser
        ): ?>

            <nav class="app-nav">

                <!-- Головна -->

                <a
                    href="/"
                    class="<?= headerActive(
                        'index.php',
                        $currentPage
                    ) ?>"
                >
                    🏠 Головна
                </a>


                <!-- Робочий екран -->

                <?php if (
                    can(
                        'production.view',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/work.php"
                        class="<?= headerActive(
                            'work.php',
                            $currentPage
                        ) ?>"
                    >
                        👷 Моя робота
                    </a>

                <?php endif; ?>


                <!-- Виробництво -->

                <?php if (
                    can(
                        'production.view',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/production.php"
                        class="<?= headerActive(
                            'production.php',
                            $currentPage
                        ) ?>"
                    >
                        🏭 Виробництво
                    </a>

                <?php endif; ?>


                <!-- Скло -->

                <?php if (
                    can(
                        'glass.view',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/glasses.php"
                        class="<?= headerActive(
                            'glasses.php',
                            $currentPage
                        ) ?>"
                    >
                        🪟 Скло
                    </a>

                <?php endif; ?>


                <!-- Створення скла -->

                <?php if (
                    can(
                        'glass.create',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/glass_create.php"
                        class="<?= headerActive(
                            'glass_create.php',
                            $currentPage
                        ) ?>"
                    >
                        ➕ Додати скло
                    </a>

                <?php endif; ?>


                <!-- QR сканування -->

                <?php if (
                    can(
                        'glass.scan',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/scan.php"
                        class="<?= headerActive(
                            'scan.php',
                            $currentPage
                        ) ?>"
                    >
                        📷 QR
                    </a>

                <?php endif; ?>


                <!-- Відвантаження -->

                <?php if (
                    can(
                        'production.ship',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/shipping.php"
                        class="shipping-link <?= headerActive(
                            'shipping.php',
                            $currentPage
                        ) ?>"
                    >
                        🚚 Відвантаження
                    </a>

                <?php endif; ?>


                <!-- Сповіщення -->

                <?php if (
                    can(
                        'notifications.view',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/notifications.php"
                        class="<?= headerActive(
                            'notifications.php',
                            $currentPage
                        ) ?>"
                    >
                        🔔 Сповіщення
                    </a>

                <?php endif; ?>


                <!-- Менеджерський екран -->

                <?php if (
                    can(
                        'production.manage',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/manager.php"
                        class="<?= headerActive(
                            'manager.php',
                            $currentPage
                        ) ?>"
                    >
                        📋 Менеджер
                    </a>

                <?php endif; ?>


                <?php if (
                    can(
                        'employees.view',
                        $headerUser
                    )
                    ||
                    can(
                        'roles.manage',
                        $headerUser
                    )
                    ||
                    can(
                        'user_permissions.manage',
                        $headerUser
                    )
                    ||
                    can(
                        'system.settings',
                        $headerUser
                    )
                ): ?>

                    <span
                        class="app-nav-divider"
                    ></span>

                <?php endif; ?>


                <!-- Працівники -->

                <?php if (
                    can(
                        'employees.view',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/employees.php"
                        class="<?= headerActive(
                            'employees.php',
                            $currentPage
                        ) ?>"
                    >
                        👥 Працівники
                    </a>

                <?php endif; ?>


                <!-- Ролі -->

                <?php if (
                    can(
                        'roles.manage',
                        $headerUser
                    )
                    ||
                    can(
                        'system.roles',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/roles.php"
                        class="<?= headerActive(
                            'roles.php',
                            $currentPage
                        ) ?>"
                    >
                        🔐 Ролі
                    </a>

                <?php endif; ?>


                <!-- Індивідуальні права -->

                <?php if (
                    can(
                        'user_permissions.manage',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/user_permissions.php"
                        class="<?= headerActive(
                            'user_permissions.php',
                            $currentPage
                        ) ?>"
                    >
                        🛡 Права
                    </a>

                <?php endif; ?>


                <!-- Виробничі дільниці -->

                <?php if (
                    can(
                        'production.manage',
                        $headerUser
                    )
                    ||
                    can(
                        'system.settings',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/admin/stages.php"
                        class="<?= headerActive(
                            'stages.php',
                            $currentPage
                        ) ?>"
                    >
                        🏗 Дільниці
                    </a>

                <?php endif; ?>


                <!-- Типи скла -->

                <?php if (
                    can(
                        'system.settings',
                        $headerUser
                    )
                    ||
                    can(
                        'glass.create',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/admin/glass_types.php"
                        class="<?= headerActive(
                            'glass_types.php',
                            $currentPage
                        ) ?>"
                    >
                        🪟 Типи скла
                    </a>

                <?php endif; ?>


                <!-- Імпорт -->

                <?php if (
                    can(
                        'orders.create',
                        $headerUser
                    )
                ): ?>

                    <a
                        href="/admin/import.php"
                        class="<?= headerActive(
                            'import.php',
                            $currentPage
                        ) ?>"
                    >
                        📥 Імпорт
                    </a>

                <?php endif; ?>


                <span
                    class="app-nav-divider"
                ></span>


                <!-- Вихід -->

                <a
                    href="/logout.php"
                    class="logout-link"
                >
                    Вийти
                </a>

            </nav>

        <?php endif; ?>

    </div>

</header>
