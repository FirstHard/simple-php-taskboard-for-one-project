<?php
require_once __DIR__ . '/../includes/auth.php';

start_app_session();

// Если уже авторизованы — нет смысла регистрироваться
if (current_user()) {
    header('Location: ' . APP_BASE_URL . '/admin/dashboard.php');
    exit;
}

$errors = [];
$success = false;

$first_name = '';
$last_name  = '';
$login      = '';
$email      = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $login      = trim($_POST['login'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $password2  = $_POST['password2'] ?? '';

    // Валидация
    if ($login === '') {
        $errors[] = 'Логин обязателен.';
    } elseif (mb_strlen($login) < 3) {
        $errors[] = 'Логин должен быть не короче 3 символов.';
    }

    if ($password === '' || $password2 === '') {
        $errors[] = 'Пароль и подтверждение пароля обязательны.';
    } elseif ($password !== $password2) {
        $errors[] = 'Пароли не совпадают.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не короче 6 символов.';
    }

    // Проверка уникальности логина
    if ($login !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB_TABLE_USERS . " WHERE login = :login");
        $stmt->execute([':login' => $login]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Пользователь с таким логином уже существует.';
        }
    }

    // Проверка уникальности email (если указан)
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный формат электронной почты.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB_TABLE_USERS . " WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Пользователь с такой электронной почтой уже существует.';
            }
        }
    }

    if (!$errors) {
        // Проверяем, есть ли уже пользователи в системе
        $stmt = $pdo->query("SELECT COUNT(*) FROM " . DB_TABLE_USERS);
        $usersCount = (int)$stmt->fetchColumn();

        $isFirstUser    = ($usersCount === 0);
        $is_superadmin  = $isFirstUser ? 1 : 0;
        $can_access     = $isFirstUser ? 1 : 0;
        $is_active      = $isFirstUser ? 1 : 0; // первый активируется сразу, остальные ждут подтверждения
        $must_change    = 0;

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO " . DB_TABLE_USERS . " 
                (first_name, last_name, login, email, password_hash, 
                 is_superadmin, can_access_tasks, is_active, must_change_password, created_at)
            VALUES 
                (:first_name, :last_name, :login, :email, :password_hash,
                 :is_superadmin, :can_access_tasks, :is_active, :must_change_password, NOW())
        ");

        $stmt->execute([
            ':first_name'         => $first_name !== '' ? $first_name : null,
            ':last_name'          => $last_name !== '' ? $last_name : null,
            ':login'              => $login,
            ':email'              => $email !== '' ? $email : null,
            ':password_hash'      => $password_hash,
            ':is_superadmin'      => $is_superadmin,
            ':can_access_tasks'   => $can_access,
            ':is_active'          => $is_active,
            ':must_change_password'=> $must_change,
        ]);

        $newUserId = (int)$pdo->lastInsertId();

        // Запишем в лог
        audit_log($newUserId, 'register', $isFirstUser ? 'First user registered (superadmin)' : 'User registered (pending approval)');

        if ($isFirstUser) {
            // Автоматически логиним первого пользователя и отправляем в админку
            $stmt = $pdo->prepare("SELECT * FROM " . DB_TABLE_USERS . " WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $newUserId]);
            $user = $stmt->fetch();
            if ($user) {
                login_user($user);
                header('Location: ' . APP_BASE_URL . '/admin/dashboard.php');
                exit;
            }
        } else {
            // Остальным показываем сообщение, что нужно дождаться активации
            $success = true;
            // очищаем введённые логин/почту из формы
            $first_name = $last_name = $login = $email = '';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Регистрация - Панель задач</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="mb-4">Регистрация</h1>

    <?php if ($success): ?>
        <div class="alert alert-success">
            Регистрация успешно завершена. После одобрения вашей учетной записи администратором
            вы сможете войти в систему.
        </div>
        <p>
            <a href="<?= APP_BASE_URL ?>/admin/login.php" class="btn btn-primary">Перейти к входу</a>
            <a href="<?= APP_BASE_URL ?>/" class="btn btn-outline-secondary ms-2">На главную</a>
        </p>
    <?php else: ?>
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
                <label for="first_name" class="form-label">Имя (необязательно)</label>
                <input type="text" class="form-control" id="first_name" name="first_name"
                       value="<?= htmlspecialchars($first_name) ?>">
            </div>
            <div class="mb-3">
                <label for="last_name" class="form-label">Фамилия (необязательно)</label>
                <input type="text" class="form-control" id="last_name" name="last_name"
                       value="<?= htmlspecialchars($last_name) ?>">
            </div>
            <div class="mb-3">
                <label for="login" class="form-label">Логин <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="login" name="login" required
                       value="<?= htmlspecialchars($login) ?>">
                <div class="form-text">
                    Можно использовать электронную почту в качестве логина.
                </div>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Электронная почта (необязательно)</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= htmlspecialchars($email) ?>">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Пароль <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password" name="password" required>
                <div class="form-text">
                    Минимум 6 символов.
                </div>
            </div>
            <div class="mb-3">
                <label for="password2" class="form-label">Подтверждение пароля <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password2" name="password2" required>
            </div>
            <button type="submit" class="btn btn-primary">Зарегистрироваться</button>
            <a href="<?= APP_BASE_URL ?>/admin/login.php" class="btn btn-link">Уже есть аккаунт? Войти</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
