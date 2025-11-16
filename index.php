<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$pdo  = db();

$TASK_STATUSES = [
    'draft'       => 'Черновик',
    'planned'     => 'Запланировано',
    'in_progress' => 'В процессе',
    'review'      => 'Готово (ревью)',
    'done'        => 'Завершено',
];

$tasksByStatus = [];

if ($user && (int)$user['can_access_tasks'] === 1) {
    foreach ($TASK_STATUSES as $code => $label) {
        $tasksByStatus[$code] = [];
    }

    $stmt = $pdo->query("
        SELECT t.id, t.title, t.description, t.status
        FROM tasks t
        ORDER BY t.status, t.created_at ASC, t.id ASC
    ");
    $allTasks = $stmt->fetchAll();

    foreach ($allTasks as $task) {
        $status = $task['status'];
        if (!isset($tasksByStatus[$status])) {
            $tasksByStatus[$status] = [];
        }
        $tasksByStatus[$status][] = $task;
    }
}
?>
<!doctype html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <title>Панель управления задачами</title>
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        .task-card {
            cursor: grab;
        }

        .task-card:active {
            cursor: grabbing;
        }

        .task-column-dropzone.drag-over {
            background-color: rgba(13, 110, 253, 0.05);
            outline: 2px dashed rgba(13, 110, 253, 0.6);
            outline-offset: -4px;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid py-4">
        <?php if (!$user): ?>
            <div class="container">
                <h1 class="mb-4">Панель управления задачами проекта</h1>
                <p class="lead">
                    Это внутренняя система для работы с задачами по проекту.
                </p>
                <p>
                    Чтобы пользоваться этим инструментом, нужно
                    <strong>зарегистрироваться</strong>. После того как администратор
                    одобрит вашу учетную запись, вам станет доступна информация по задачам
                    и возможность управлять ими через админ-панель.
                </p>

                <div class="mt-4">
                    <a href="<?= APP_BASE_URL ?>/admin/login.php" class="btn btn-primary me-2">Войти</a>
                    <a href="<?= APP_BASE_URL ?>/admin/register.php" class="btn btn-outline-secondary">Зарегистрироваться</a>
                </div>
            </div>

        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Панель управления задачами</h1>
                    <div class="text-muted">
                        Вы вошли как:
                        <strong><?= htmlspecialchars($user['first_name'] ?: $user['login']) ?></strong>
                        <?php if ((int)$user['is_superadmin'] === 1): ?>
                            <span class="badge bg-danger ms-2">Супер-администратор</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <a href="<?= APP_BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary me-2">Админ-панель</a>
                    <a href="<?= APP_BASE_URL ?>/admin/logout.php" class="btn btn-outline-danger">Выйти</a>
                </div>
            </div>

            <?php if ((int)$user['can_access_tasks'] !== 1): ?>
                <div class="container">
                    <div class="alert alert-warning">
                        Ваша учетная запись активна, но доступ к задачам пока не выдан.
                        Обратитесь к администратору, если считаете это ошибкой.
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($TASK_STATUSES as $statusCode => $statusLabel): ?>
                        <div class="col-12 col-md-6 col-lg">
                            <div class="card h-100">
                                <div class="card-header text-center fw-semibold">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </div>
                                <div class="card-body task-column-dropzone"
                                    data-status="<?= htmlspecialchars($statusCode) ?>"
                                    style="max-height: 70vh; overflow-y: auto;">
                                    <?php if (!empty($tasksByStatus[$statusCode])): ?>
                                        <?php foreach ($tasksByStatus[$statusCode] as $task): ?>
                                            <div class="card mb-2 shadow-sm task-card"
                                                draggable="true"
                                                data-task-id="<?= (int)$task['id'] ?>"
                                                data-status="<?= htmlspecialchars($task['status']) ?>"
                                                data-edit-url="<?= APP_BASE_URL ?>/admin/tasks.php?edit=<?= (int)$task['id'] ?>">
                                                <div class="card-body p-2">
                                                    <div class="fw-semibold small">
                                                        <?= htmlspecialchars($task['title']) ?>
                                                    </div>
                                                    <?php if (!empty($task['description'])): ?>
                                                        <div class="small text-muted">
                                                            <?= nl2br(htmlspecialchars(mb_strimwidth($task['description'], 0, 160, '…'))) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-muted small text-center">
                                            Задач в этом статусе нет.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let draggedCard = null;
            let isDragging = false;

            document.querySelectorAll('.task-card').forEach(function(card) {
                card.addEventListener('dragstart', function(e) {
                    draggedCard = card;
                    isDragging = true;
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.taskId);
                });

                card.addEventListener('dragend', function() {
                    setTimeout(function() {
                        isDragging = false;
                        draggedCard = null;
                    }, 50);
                });

                card.addEventListener('click', function() {
                    if (isDragging) {
                        return;
                    }
                    const url = card.dataset.editUrl;
                    if (url) {
                        window.location.href = url;
                    }
                });
            });

            document.querySelectorAll('.task-column-dropzone').forEach(function(col) {
                col.addEventListener('dragover', function(e) {
                    if (!draggedCard) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    col.classList.add('drag-over');
                });

                col.addEventListener('dragleave', function(e) {
                    col.classList.remove('drag-over');
                });

                col.addEventListener('drop', function(e) {
                    e.preventDefault();
                    col.classList.remove('drag-over');
                    if (!draggedCard) return;

                    const newStatus = col.dataset.status;
                    const oldStatus = draggedCard.dataset.status;
                    const taskId = draggedCard.dataset.taskId;

                    if (!newStatus || !taskId || newStatus === oldStatus) {
                        if (draggedCard.parentNode !== col) {
                            col.appendChild(draggedCard);
                        }
                        return;
                    }

                    col.appendChild(draggedCard);
                    draggedCard.dataset.status = newStatus;

                    fetch('<?= APP_BASE_URL ?>/admin/ajax_update_task_status.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                            },
                            body: new URLSearchParams({
                                task_id: taskId,
                                new_status: newStatus
                            })
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (!data || !data.success) {
                                alert('Не удалось обновить статус задачи: ' + (data && data.error ? data.error : 'ошибка'));
                            }
                        })
                        .catch(function(error) {
                            console.error(error);
                            alert('Ошибка сети при обновлении статуса задачи.');
                        });
                });
            });
        });
    </script>
</body>

</html>