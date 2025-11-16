<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
$currentUser = current_user();
$pdo = db();

$TASK_STATUSES = [
    'draft'       => 'Черновик',
    'planned'     => 'Запланировано',
    'in_progress' => 'В процессе',
    'review'      => 'Готово (ревью)',
    'done'        => 'Завершено',
];

$errors   = [];
$messages = [];

// Проверка доступа к задачам
if ((int)$currentUser['can_access_tasks'] !== 1) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <title>Доступ к задачам - Панель задач</title>
        <link rel="stylesheet"
              href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
        <div class="container py-5">
            <h1 class="mb-4">Доступ к задачам</h1>
            <div class="alert alert-warning">
                Ваша учетная запись не имеет доступа к задачам проекта.
                Обратитесь к администратору, если считаете, что это ошибка.
            </div>
            <a href="<?= APP_BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary">
                Назад в панель управления
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Загрузка задачи для редактирования (GET ?edit=ID)
$editTask = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM " . DB_TABLE_TASKS . " WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $editId]);
        $editTask = $stmt->fetch();
        if (!$editTask) {
            $errors[] = 'Задача для редактирования не найдена.';
        }
    }
}

// Обработка сохранения (создание / обновление)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_task') {
        $taskId      = (int)($_POST['task_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = $_POST['status'] ?? 'draft';

        if ($title === '') {
            $errors[] = 'Название задачи обязательно.';
        }
        if (!array_key_exists($status, $TASK_STATUSES)) {
            $errors[] = 'Некорректный статус задачи.';
        }

        if (!$errors) {
            if ($taskId > 0) {
                // Обновление существующей задачи
                $stmt = $pdo->prepare("
                    UPDATE " . DB_TABLE_TASKS . "
                    SET title = :title,
                        description = :description,
                        status = :status,
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description !== '' ? $description : null,
                    ':status'      => $status,
                    ':updated_by'  => $currentUser['id'],
                    ':id'          => $taskId,
                ]);

                $messages[] = 'Задача #' . $taskId . ' обновлена.';
                audit_log($currentUser['id'], 'task_update', 'Updated task ID ' . $taskId);

                // Обновляем данные формы
                $editTask = [
                    'id'          => $taskId,
                    'title'       => $title,
                    'description' => $description,
                    'status'      => $status,
                ];
            } else {
                // Создание новой задачи
                $stmt = $pdo->prepare("
                    INSERT INTO " . DB_TABLE_TASKS . " (title, description, status, created_by, updated_by, created_at)
                    VALUES (:title, :description, :status, :created_by, :updated_by, NOW())
                ");
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description !== '' ? $description : null,
                    ':status'      => $status,
                    ':created_by'  => $currentUser['id'],
                    ':updated_by'  => $currentUser['id'],
                ]);

                $newId = (int)$pdo->lastInsertId();
                $messages[] = 'Создана новая задача #' . $newId . '.';
                audit_log($currentUser['id'], 'task_create', 'Created task ID ' . $newId);

                // После создания очищаем форму
                $editTask = null;
            }
        }
    }
}

// Значения для формы
$formTaskId      = $editTask['id'] ?? 0;
$formTitle       = $editTask['title'] ?? '';
$formDescription = $editTask['description'] ?? '';
$formStatus      = $editTask['status'] ?? 'draft';
$isEditing       = $formTaskId > 0;
?>
<!doctype html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <title><?= $isEditing ? 'Редактирование задачи' : 'Создание задачи' ?> - Панель задач</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>

<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <?= $isEditing ? 'Редактирование задачи #' . (int)$formTaskId : 'Создание новой задачи' ?>
            </h1>
            <div class="text-muted">
                Вы вошли как:
                <strong><?= htmlspecialchars($currentUser['first_name'] ?: $currentUser['login']) ?></strong>
                <?php if ((int)$currentUser['is_superadmin'] === 1): ?>
                    <span class="badge bg-danger ms-2">Супер-администратор</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="<?= APP_BASE_URL ?>/" class="btn btn-outline-secondary me-2">На главную</a>
            <a href="<?= APP_BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary me-2">Панель управления</a>
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

    <div class="card">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="save_task">
                <input type="hidden" name="task_id" value="<?= (int)$formTaskId ?>">

                <div class="mb-3">
                    <label for="title" class="form-label">
                        Название задачи <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="title" name="title" required
                           value="<?= htmlspecialchars($formTitle) ?>">
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Описание (необязательно)</label>
                    <textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($formDescription) ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="status" class="form-label">Статус</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($TASK_STATUSES as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $code === $formStatus ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?= $isEditing ? 'Сохранить изменения' : 'Создать задачу' ?>
                </button>
                <?php if ($isEditing): ?>
                    <a href="<?= APP_BASE_URL ?>/admin/tasks.php" class="btn btn-outline-secondary ms-2">Создать новую</a>
                <?php endif; ?>
                <a href="<?= APP_BASE_URL ?>/admin/dashboard.php" class="btn btn-link ms-2">К списку задач</a>
            </form>
        </div>
    </div>
</div>
</body>
</html>
