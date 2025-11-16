<?php
require_once __DIR__ . '/../includes/auth.php';

start_app_session();

$user = current_user();
if ($user) {
    // Уже авторизованы — сразу в админку
    header('Location: ' . APP_BASE_URL . '/admin/dashboard.php');
    exit;
}

$errors = [];
$login  = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $errors[] = 'Введите логин и пароль.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM " . DB_TABLE_USERS . " WHERE login = :login LIMIT 1");
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Неверный логин или пароль.';
            audit_log(null, 'login_failed', 'Unknown login: ' . $login);
        } else {
            if (!password_verify($password, $user['password_hash'])) {
                $errors[] = 'Неверный логин или пароль.';
                audit_log((int)$user['id'], 'login_failed', 'Incorrect password');
            } else {
                // Проверяем, активирован ли пользователь
                if ((int)$user['is_active'] !== 1) {
                    $errors[] = 'Ваша учетная запись еще не активирована администратором.';
                    audit_log((int)$user['id'], 'login_blocked', 'User not active');
                } else {
                    // Успешный вход
                    login_user($user);

                    // Если нужно сменить пароль — позже сделаем отдельную страницу
                    if ((int)$user['must_change_password'] === 1) {
                        header('Location: ' . APP_BASE_URL . '/admin/change_password.php');
                    } else {
                        header('Location: ' . APP_BASE_URL . '/admin/dashboard.php');
                    }
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Вход - Панель задач</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="mb-4">Вход</h1>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="card p-4">
        <div class="mb-3">
            <label for="login" class="form-label">Логин</label>
            <input type="text" class="form-control" id="login" name="login"
                   value="<?= htmlspecialchars($login) ?>" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Пароль</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary">Войти</button>
        <a href="<?= APP_BASE_URL ?>/admin/register.php" class="btn btn-link">Зарегистрироваться</a>
        <a href="<?= APP_BASE_URL ?>/" class="btn btn-link">На главную</a>
    </form>
</div>
</body>
</html>
