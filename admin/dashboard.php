<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
$user = current_user();

$pdo = db();
$errors = [];
$messages = [];

// Статусы задач для отображения
$TASK_STATUSES = [
    'draft'       => 'Черновик',
    'planned'     => 'Запланировано',
    'in_progress' => 'В процессе',
    'review'      => 'Готово (ревью)',
    'done'        => 'Завершена',
];

// ------------------------
// Настройки пагинации задач
// ------------------------
$PER_PAGE_OPTIONS = ['all', 5, 10, 20, 50, 100, 500];
$DEFAULT_PER_PAGE = 20;

// per_page для задач из GET
$perPageParam = $_GET['per_page'] ?? '';
if ($perPageParam === 'all') {
    $perPage = 'all';
} elseif (ctype_digit($perPageParam) && in_array((int)$perPageParam, [5, 10, 20, 50, 100, 500], true)) {
    $perPage = (int)$perPageParam;
} else {
    $perPage = $DEFAULT_PER_PAGE;
}

// Текущая страница задач
$page = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// ------------------------
// Настройки "последних действий" (audit log block внизу)
// ------------------------
$AUDIT_LIMIT_OPTIONS = [5, 10, 20, 50, 100];
$DEFAULT_AUDIT_LIMIT = 10;

$auditLimitParam = $_GET['audit_limit'] ?? '';
if (ctype_digit($auditLimitParam) && in_array((int)$auditLimitParam, $AUDIT_LIMIT_OPTIONS, true)) {
    $auditLimit = (int)$auditLimitParam;
} else {
    $auditLimit = $DEFAULT_AUDIT_LIMIT;
}

// ------------------------
// Обработка действий (пользователи + удаление задач)
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Удаление задачи (доступ только тем, у кого есть доступ к задачам)
    if ($action === 'delete_task') {
        if ((int)$user['can_access_tasks'] !== 1) {
            $errors[] = 'У вас нет прав для удаления задач.';
        } else {
            $taskId = (int)($_POST['task_id'] ?? 0);
            if ($taskId <= 0) {
                $errors[] = 'Некорректный ID задачи для удаления.';
            } else {
                $stmt = $pdo->prepare("SELECT id, title FROM " . DB_TABLE_TASKS . " WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $taskId]);
                $task = $stmt->fetch();

                if (!$task) {
                    $errors[] = 'Задача не найдена, возможно уже удалена.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM " . DB_TABLE_TASKS . " WHERE id = :id");
                    $stmt->execute([':id' => $taskId]);

                    $messages[] = 'Задача #' . $taskId . ' удалена.';
                    audit_log($user['id'], 'task_delete', 'Deleted task ID ' . $taskId . ' (' . $task['title'] . ')');
                }
            }
        }
    }

    // Управление пользователями — только для супер-админа
    if ($action !== 'delete_task' && $user && (int)$user['is_superadmin'] === 1) {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $errors[] = 'Некорректный идентификатор пользователя.';
        } elseif ($targetId === (int)$user['id']) {
            $errors[] = 'Нельзя выполнять это действие над своей учетной записью.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM " . DB_TABLE_USERS . " WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $targetId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $errors[] = 'Пользователь не найден.';
            } else {
                switch ($action) {
                    case 'activate':
                        if ((int)$targetUser['is_active'] !== 1) {
                            $stmt = $pdo->prepare("
                                UPDATE " . DB_TABLE_USERS . " 
                                SET is_active = 1 
                                WHERE id = :id
                            ");
                            $stmt->execute([':id' => $targetId]);
                            $messages[] = 'Пользователь #' . $targetId . ' активирован.';
                            audit_log($user['id'], 'user_activate', 'Activated user ID ' . $targetId);
                        }
                        break;

                    case 'deactivate':
                        if ((int)$targetUser['is_active'] !== 0) {
                            $stmt = $pdo->prepare("
                                UPDATE " . DB_TABLE_USERS . " 
                                SET is_active = 0 
                                WHERE id = :id
                            ");
                            $stmt->execute([':id' => $targetId]);
                            $messages[] = 'Пользователь #' . $targetId . ' деактивирован.';
                            audit_log($user['id'], 'user_deactivate', 'Deactivated user ID ' . $targetId);
                        }
                        break;

                    case 'grant_access':
                        if ((int)$targetUser['can_access_tasks'] !== 1) {
                            $stmt = $pdo->prepare("
                                UPDATE " . DB_TABLE_USERS . " 
                                SET can_access_tasks = 1 
                                WHERE id = :id
                            ");
                            $stmt->execute([':id' => $targetId]);
                            $messages[] = 'Пользователю #' . $targetId . ' выдан доступ к задачам.';
                            audit_log($user['id'], 'user_grant_access', 'Granted task access to user ID ' . $targetId);
                        }
                        break;

                    case 'revoke_access':
                        if ((int)$targetUser['can_access_tasks'] !== 0) {
                            $stmt = $pdo->prepare("
                                UPDATE " . DB_TABLE_USERS . " 
                                SET can_access_tasks = 0 
                                WHERE id = :id
                            ");
                            $stmt->execute([':id' => $targetId]);
                            $messages[] = 'У пользователя #' . $targetId . ' отозван доступ к задачам.';
                            audit_log($user['id'], 'user_revoke_access', 'Revoked task access from user ID ' . $targetId);
                        }
                        break;

                    default:
                        if ($action !== '') {
                            $errors[] = 'Неизвестное действие.';
                        }
                }
            }
        }
    }
}

// ------------------------
// Список пользователей (для вкладки "Пользователи")
// ------------------------
$users = [];
if ($user && (int)$user['is_superadmin'] === 1) {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name, login, email,
               is_superadmin, can_access_tasks, is_active,
               created_at, last_login_at
        FROM " . DB_TABLE_USERS . "
        ORDER BY id ASC
    ");
    $users = $stmt->fetchAll();
}

// ------------------------
// Список задач (для вкладки "Список задач") с пагинацией
// ------------------------
$tasks          = [];
$totalTasks     = 0;
$totalPages     = 1;
$showPagination = false;

if ((int)$user['can_access_tasks'] === 1) {
    $totalTasks = (int)$pdo->query("SELECT COUNT(*) FROM " . DB_TABLE_TASKS)->fetchColumn();

    if ($perPage === 'all') {
        $totalPages = 1;
        $page = 1;

        $stmt = $pdo->query("
            SELECT t.*, 
                   u1.login AS created_by_login,
                   u2.login AS updated_by_login
            FROM " . DB_TABLE_TASKS . " t
            LEFT JOIN " . DB_TABLE_USERS . " u1 ON t.created_by = u1.id
            LEFT JOIN " . DB_TABLE_USERS . " u2 ON t.updated_by = u2.id
            ORDER BY t.created_at DESC, t.id DESC
        ");
    } else {
        if ($totalTasks === 0) {
            $totalPages = 1;
            $page = 1;
            $stmt = null;
        } else {
            $totalPages = (int)ceil($totalTasks / $perPage);
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT t.*, 
                       u1.login AS created_by_login,
                       u2.login AS updated_by_login
                FROM " . DB_TABLE_TASKS . " t
                LEFT JOIN " . DB_TABLE_USERS . " u1 ON t.created_by = u1.id
                LEFT JOIN " . DB_TABLE_USERS . " u2 ON t.updated_by = u2.id
                ORDER BY t.created_at DESC, t.id DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    if ($stmt) {
        $tasks = $stmt->fetchAll();
    }

    $showPagination = ($perPage !== 'all' && $totalTasks > $perPage);
}

// ------------------------
// Последние действия (audit log block внизу)
// ------------------------
$recentLogs = [];
if ((int)$user['is_superadmin'] === 1 && $auditLimit > 0) {
    $stmt = $pdo->prepare("
        SELECT l.*, u.login AS user_login
        FROM " . DB_TABLE_AUDIT_LOG . " l
        LEFT JOIN " . DB_TABLE_USERS . " u ON l.user_id = u.id
        ORDER BY l.id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $auditLimit, PDO::PARAM_INT);
    $stmt->execute();
    $recentLogs = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Панель управления - Панель задач</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        .task-desc-text {
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Панель управления</h1>
            <div class="text-muted">
                Вы вошли как: <strong><?= htmlspecialchars($user['first_name'] ?: $user['login']) ?></strong>
                <?php if ((int)$user['is_superadmin'] === 1): ?>
                    <span class="badge bg-danger ms-2">Супер-администратор</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="<?= APP_BASE_URL ?>/" class="btn btn-outline-secondary me-2">На главную</a>
            <a href="<?= APP_BASE_URL ?>/admin/logout.php" class="btn btn-outline-danger">Выйти</a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($messages): ?>
        <div class="alert alert-success">
            <ul class="mb-0">
                <?php foreach ($messages as $msg): ?>
                    <li><?= htmlspecialchars($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Вкладки -->
    <ul class="nav nav-tabs mb-3" id="dashboardTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tasks-tab" data-bs-toggle="tab"
                    data-bs-target="#tasks-pane" type="button" role="tab"
                    aria-controls="tasks-pane" aria-selected="true">
                Список задач
            </button>
        </li>
        <?php if ((int)$user['is_superadmin'] === 1): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="tab"
                        data-bs-target="#users-pane" type="button" role="tab"
                        aria-controls="users-pane" aria-selected="false">
                    Пользователи
                </button>
            </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="dashboardTabsContent">
        <!-- Вкладка: Список задач -->
        <div class="tab-pane fade show active" id="tasks-pane" role="tabpanel" aria-labelledby="tasks-tab">
            <?php if ((int)$user['can_access_tasks'] !== 1): ?>
                <div class="alert alert-warning">
                    Ваша учетная запись активна, но доступ к задачам пока не выдан.
                    Обратитесь к администратору, если считаете это ошибкой.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a href="<?= APP_BASE_URL ?>/admin/tasks.php" class="btn btn-primary">
                        Создать новую задачу
                    </a>

                    <div class="d-flex align-items-center">
                        <label for="tasksPerPageSelect" class="form-label me-2 mb-0 small text-muted">
                            Задач на странице:
                        </label>
                        <select id="tasksPerPageSelect" class="form-select form-select-sm" style="width:auto;">
                            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
                                <?php
                                $value = ($opt === 'all') ? 'all' : (string)$opt;
                                $label = ($opt === 'all') ? 'Все' : (string)$opt;
                                $selected = ($perPage === 'all' && $opt === 'all') ||
                                            ($perPage !== 'all' && $perPage === (int)$opt);
                                ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        Список задач
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle">
                                <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Название</th>
                                    <th>Статус</th>
                                    <th>Создана</th>
                                    <th>Автор</th>
                                    <th>Обновлена</th>
                                    <th>Кем обновлена</th>
                                    <th style="width: 150px;">Действия</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!$tasks): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-3">
                                            Задач пока нет.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= (int)$task['id'] ?></td>
                                            <td>
                                                <div class="fw-semibold">
                                                    <?= htmlspecialchars($task['title']) ?>
                                                </div>
                                                <?php if (!empty($task['description'])): ?>
                                                    <?php
                                                    $rawDescription = $task['description'];
                                                    $short = mb_strimwidth($rawDescription, 0, 150, '…', 'UTF-8');
                                                    $full  = $rawDescription;
                                                    $isToggleNeeded = (mb_strlen($full, 'UTF-8') > mb_strlen($short, 'UTF-8'));
                                                    ?>
                                                    <div class="small task-desc-wrapper">
                                                        <?php if ($isToggleNeeded): ?>
                                                            <span class="task-desc-text js-task-desc-toggle"
                                                                  data-short="<?= htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                  data-full="<?= htmlspecialchars($full, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                  data-expanded="0">
                                                                <?= htmlspecialchars($short, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="task-desc-text">
                                                                <?= htmlspecialchars($full, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $st = $task['status'];
                                                $stLabel = $TASK_STATUSES[$st] ?? $st;
                                                ?>
                                                <span class="badge bg-light text-dark border">
                                                    <?= htmlspecialchars($stLabel) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($task['created_at']) ?></td>
                                            <td><?= htmlspecialchars($task['created_by_login'] ?: '—') ?></td>
                                            <td><?= htmlspecialchars($task['updated_at']) ?></td>
                                            <td><?= htmlspecialchars($task['updated_by_login'] ?: '—') ?></td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <a href="<?= APP_BASE_URL ?>/admin/tasks.php?edit=<?= (int)$task['id'] ?>"
                                                       class="btn btn-sm btn-outline-primary w-100">
                                                        Редактировать
                                                    </a>
                                                    <form method="post"
                                                          onsubmit="return confirm('Удалить задачу #<?= (int)$task['id'] ?>?');">
                                                        <input type="hidden" name="action" value="delete_task">
                                                        <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                            Удалить
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($showPagination): ?>
                        <div class="px-3 py-2">
                            <nav aria-label="Навигация по списку задач">
                                <ul class="pagination pagination-sm justify-content-end mb-0">
                                    <?php
                                    $baseUrl = APP_BASE_URL . '/admin/dashboard.php';
                                    $perPageParamValue = ($perPage === 'all') ? 'all' : (string)$perPage;

                                    $buildUrl = function (int $targetPage) use ($baseUrl, $perPageParamValue, $auditLimit) {
                                        $params = [
                                            'per_page'   => $perPageParamValue,
                                            'page'       => $targetPage,
                                            'audit_limit'=> $auditLimit,
                                        ];
                                        return $baseUrl . '?' . http_build_query($params);
                                    };
                                    ?>
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                           href="<?= $page > 1 ? htmlspecialchars($buildUrl($page - 1)) : '#' ?>"
                                           tabindex="-1" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">
                                            «
                                        </a>
                                    </li>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars($buildUrl($p)) ?>">
                                                <?= $p ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link"
                                           href="<?= $page < $totalPages ? htmlspecialchars($buildUrl($page + 1)) : '#' ?>">
                                            »
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Вкладка: Пользователи -->
        <?php if ((int)$user['is_superadmin'] === 1): ?>
            <div class="tab-pane fade" id="users-pane" role="tabpanel" aria-labelledby="users-tab">
                <hr class="mt-4 mb-3">
                <h2 class="h4 mb-3">Управление пользователями</h2>
                <p class="text-muted">
                    Здесь можно одобрять регистрации (активировать учетные записи) и выдавать/отзывать доступ к задачам.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Имя</th>
                            <th>Логин</th>
                            <th>Email</th>
                            <th>Статус</th>
                            <th>Доступ к задачам</th>
                            <th>Роль</th>
                            <th>Создан</th>
                            <th>Последний вход</th>
                            <th>Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="10" class="text-muted text-center">
                                    Пользователей пока нет.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= (int)$u['id'] ?></td>
                                    <td>
                                        <?= htmlspecialchars(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: '—') ?>
                                    </td>
                                    <td><?= htmlspecialchars($u['login']) ?></td>
                                    <td><?= htmlspecialchars($u['email'] ?: '—') ?></td>
                                    <td>
                                        <?php if ((int)$u['is_active'] === 1): ?>
                                            <span class="badge bg-success">Активен</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Не активен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['can_access_tasks'] === 1): ?>
                                            <span class="badge bg-primary">Есть доступ</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Нет доступа</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['is_superadmin'] === 1): ?>
                                            <span class="badge bg-danger">Супер-админ</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">Пользователь</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($u['created_at']) ?></td>
                                    <td><?= htmlspecialchars($u['last_login_at'] ?: '—') ?></td>
                                    <td>
                                        <?php if ((int)$u['id'] === (int)$user['id']): ?>
                                            <span class="text-muted small">Это вы</span>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-1">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <?php if ((int)$u['is_active'] === 1): ?>
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                                            Деактивировать
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" class="btn btn-sm btn-outline-success w-100">
                                                            Активировать
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <?php if ((int)$u['can_access_tasks'] === 1): ?>
                                                        <input type="hidden" name="action" value="revoke_access">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning w-100">
                                                            Отозвать доступ
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="grant_access">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                                            Выдать доступ
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Блок последних действий (audit log) -->
    <?php if ((int)$user['is_superadmin'] === 1): ?>
        <hr class="mt-5 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0">Последние действия пользователей</h2>
            <div class="d-flex align-items-center">
                <label for="auditLimitSelect" class="form-label me-2 mb-0 small text-muted">
                    Показать:
                </label>
                <select id="auditLimitSelect" class="form-select form-select-sm" style="width:auto;">
                    <?php foreach ($AUDIT_LIMIT_OPTIONS as $opt): ?>
                        <option value="<?= (int)$opt ?>" <?= $auditLimit === (int)$opt ? 'selected' : '' ?>>
                            <?= (int)$opt ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="small text-muted ms-2">записей</span>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Время</th>
                            <th>Пользователь</th>
                            <th>Тип действия</th>
                            <th>Детали</th>
                            <th>IP</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$recentLogs): ?>
                            <tr>
                                <td colspan="6" class="text-muted text-center py-3">
                                    Записей пока нет.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?= (int)$log['id'] ?></td>
                                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                                    <td><?= htmlspecialchars($log['user_login'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($log['action_type']) ?></td>
                                    <td class="small">
                                        <?= nl2br(htmlspecialchars($log['action_details'] ?? '')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($log['ip_address'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <a href="<?= APP_BASE_URL ?>/admin/audit_log.php" class="btn btn-outline-secondary btn-sm">
                Посмотреть все действия
            </a>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Переключение описания задачи (краткое/полное) ---
    document.querySelectorAll('.js-task-desc-toggle').forEach(function (el) {
        el.addEventListener('click', function () {
            var expanded = el.getAttribute('data-expanded') === '1';
            if (expanded) {
                el.textContent = el.getAttribute('data-short');
                el.setAttribute('data-expanded', '0');
            } else {
                el.textContent = el.getAttribute('data-full');
                el.setAttribute('data-expanded', '1');
            }
        });
    });

    var userId = <?= (int)$user['id'] ?>;

    // --- Память количества задач на странице (per_page) в localStorage ---
    var tasksSelect = document.getElementById('tasksPerPageSelect');
    if (tasksSelect) {
        var tasksStorageKey = 'taskboard_tasks_per_page_user_' + userId;
        var urlParams = new URLSearchParams(window.location.search);
        var hasPerPageParam = urlParams.has('per_page');

        if (!hasPerPageParam) {
            var savedTasksPerPage = localStorage.getItem(tasksStorageKey);
            if (savedTasksPerPage && savedTasksPerPage !== tasksSelect.value) {
                urlParams.set('per_page', savedTasksPerPage);
                // сохраняем audit_limit, если был
                if (!urlParams.has('audit_limit')) {
                    urlParams.set('audit_limit', '<?= (int)$auditLimit ?>');
                }
                urlParams.delete('page');
                window.location.search = urlParams.toString();
                return;
            }
        }

        tasksSelect.addEventListener('change', function () {
            var value = tasksSelect.value;
            localStorage.setItem(tasksStorageKey, value);

            var params = new URLSearchParams(window.location.search);
            params.set('per_page', value);
            params.set('audit_limit', document.getElementById('auditLimitSelect') ? document.getElementById('auditLimitSelect').value : '<?= (int)$auditLimit ?>');
            params.delete('page');
            window.location.search = params.toString();
        });
    }

    // --- Память количества последних логов (audit_limit) в localStorage ---
    var auditSelect = document.getElementById('auditLimitSelect');
    if (auditSelect) {
        var auditStorageKey = 'taskboard_audit_limit_user_' + userId;
        var params = new URLSearchParams(window.location.search);
        var hasAuditParam = params.has('audit_limit');

        if (!hasAuditParam) {
            var savedAuditLimit = localStorage.getItem(auditStorageKey);
            if (savedAuditLimit && savedAuditLimit !== auditSelect.value) {
                params.set('audit_limit', savedAuditLimit);
                // сохраняем per_page, если был
                if (!params.has('per_page')) {
                    params.set('per_page', '<?= $perPage === "all" ? "all" : (int)$perPage ?>');
                }
                window.location.search = params.toString();
                return;
            }
        }

        auditSelect.addEventListener('change', function () {
            var value = auditSelect.value;
            localStorage.setItem(auditStorageKey, value);

            var p = new URLSearchParams(window.location.search);
            p.set('audit_limit', value);
            if (!p.has('per_page')) {
                p.set('per_page', '<?= $perPage === "all" ? "all" : (int)$perPage ?>');
            }
            // страницу задач при смене логов можно не сбрасывать
            window.location.search = p.toString();
        });
    }
});
</script>
</body>
</html>
