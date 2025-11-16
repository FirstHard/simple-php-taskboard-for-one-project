<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();
    $user = current_user();

    if ((int)$user['can_access_tasks'] !== 1) {
        echo json_encode(['success' => false, 'error' => 'Нет прав для изменения задач']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Неверный метод запроса']);
        exit;
    }

    // Допустимые статусы задач
    $TASK_STATUSES = ['draft', 'planned', 'in_progress', 'review', 'done'];

    $taskId    = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $newStatus = $_POST['new_status'] ?? '';

    if ($taskId <= 0 || !in_array($newStatus, $TASK_STATUSES, true)) {
        echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
        exit;
    }

    $pdo = db();

    // Проверяем, что задача существует
    $stmt = $pdo->prepare("SELECT id, status FROM " . DB_TABLE_TASKS . " WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
        exit;
    }

    // Если статус не меняется — просто ok
    if ($task['status'] === $newStatus) {
        echo json_encode(['success' => true]);
        exit;
    }

    // Обновляем статус и updated_at
    $stmt = $pdo->prepare("
        UPDATE " . DB_TABLE_TASKS . "
        SET status = :status,
            updated_by = :updated_by,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status'     => $newStatus,
        ':updated_by' => $user['id'],
        ':id'         => $taskId,
    ]);

    audit_log(
        $user['id'],
        'task_status_change',
        'Task ID ' . $taskId . ': ' . $task['status'] . ' -> ' . $newStatus
    );

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
    }
}
