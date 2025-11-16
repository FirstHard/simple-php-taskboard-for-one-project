<?php
require_once __DIR__ . '/db.php';

// Старт сессии для всего проекта (фронт + админка)
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

// Получить текущего пользователя (или null)
function current_user(): ?array
{
    start_app_session();

    if (!empty($_SESSION['user_id'])) {
        static $cachedUser = null;

        if ($cachedUser === null || $cachedUser['id'] !== $_SESSION['user_id']) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT * FROM " . DB_TABLE_USERS . " WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            $cachedUser = $user ?: null;
        }

        return $cachedUser;
    }

    return null;
}

// Авторизовать пользователя (после успешного логина)
function login_user(array $user): void
{
    start_app_session();
    $_SESSION['user_id'] = $user['id'];

    // обновим last_login_at
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE " . DB_TABLE_USERS . " SET last_login_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);

    audit_log($user['id'], 'login', 'User logged in');
}

// Разлогиниться
function logout_user(): void
{
    start_app_session();
    $user = current_user();
    if ($user) {
        audit_log($user['id'], 'logout', 'User logged out');
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// Требовать авторизацию (для админ-страниц)
function require_login(): void
{
    if (!current_user()) {
        header('Location: ' . APP_BASE_URL . '/admin/login.php');
        exit;
    }
}

// Запись в audit_log
function audit_log(?int $userId, string $actionType, ?string $details = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO " . DB_TABLE_AUDIT_LOG . " (user_id, action_type, action_details, ip_address, user_agent)
        VALUES (:user_id, :action_type, :details, :ip, :ua)
    ");
    $stmt->execute([
        ':user_id'    => $userId,
        ':action_type'=> $actionType,
        ':details'    => $details,
        ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua'         => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}
