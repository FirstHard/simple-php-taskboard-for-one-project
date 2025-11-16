<?php
require_once __DIR__ . '/../includes/auth.php';

logout_user();

// После выхода отправляем на главную
header('Location: ' . APP_BASE_URL . '/');
exit;
