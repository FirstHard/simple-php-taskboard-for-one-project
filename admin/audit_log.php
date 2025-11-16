<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();
$user = current_user();

if ((int)$user['is_superadmin'] !== 1) {
    // Только супер-администратор может просматривать полный лог
    header('Location: ' . APP_BASE_URL . '/admin/dashboard.php');
    exit;
}

$pdo = db();

// Варианты количества записей на странице
$PER_PAGE_OPTIONS = [5, 10, 20, 50, 100, 500];
$DEFAULT_PER_PAGE = 20;

// per_page из GET
$perPageParam = $_GET['per_page'] ?? '';
if (ctype_digit($perPageParam) && in_array((int)$perPageParam, $PER_PAGE_OPTIONS, true)) {
    $perPage = (int)$perPageParam;
} else {
    $perPage = $DEFAULT_PER_PAGE;
}

// Текущая страница
$page = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

// Общее количество записей
$totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM " . DB_TABLE_AUDIT_LOG)->fetchColumn();

$totalPages = 1;
$logs = [];

if ($totalLogs > 0) {
    $totalPages = (int)ceil($totalLogs / $perPage);
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT l.*, u.login AS user_login
        FROM " . DB_TABLE_AUDIT_LOG . " l
        LEFT JOIN " . DB_TABLE_USERS . " u ON l.user_id = u.id
        ORDER BY l.id DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <title>Все действия пользователей - Панель задач</title>
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>

<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">Все действия пользователей</h1>
                <div class="text-muted">
                    Вы вошли как:
                    <strong><?= htmlspecialchars($user['first_name'] ?: $user['login']) ?></strong>
                    <span class="badge bg-danger ms-2">Супер-администратор</span>
                </div>
            </div>
            <div>
                <a href="<?= APP_BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary me-2">Панель управления</a>
                <a href="<?= APP_BASE_URL ?>/" class="btn btn-outline-secondary me-2">На главную</a>
                <a href="<?= APP_BASE_URL ?>/admin/logout.php" class="btn btn-outline-danger">Выйти</a>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <p class="mb-0 text-muted">
                    Всего записей: <strong><?= $totalLogs ?></strong>
                </p>
            </div>
            <div class="d-flex align-items-center">
                <label for="auditPerPageSelect" class="form-label me-2 mb-0 small text-muted">
                    Записей на странице:
                </label>
                <select id="auditPerPageSelect" class="form-select form-select-sm" style="width:auto;">
                    <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
                        <option value="<?= (int)$opt ?>" <?= $perPage === (int)$opt ? 'selected' : '' ?>>
                            <?= (int)$opt ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                                <th>User-Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="7" class="text-muted text-center py-3">
                                        Записей не найдено.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= (int)$log['id'] ?></td>
                                        <td><?= htmlspecialchars($log['created_at']) ?></td>
                                        <td><?= htmlspecialchars($log['user_login'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($log['action_type']) ?></td>
                                        <td class="small">
                                            <?= nl2br(htmlspecialchars($log['action_details'] ?? '')) ?>
                                        </td>
                                        <td><?= htmlspecialchars($log['ip_address'] ?: '—') ?></td>
                                        <td class="small">
                                            <?= htmlspecialchars($log['user_agent'] ?: '—') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($totalLogs > $perPage): ?>
                <div class="px-3 py-2">
                    <nav aria-label="Навигация по логам">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php
                            $baseUrl = APP_BASE_URL . '/admin/audit_log.php';
                            $buildUrl = function (int $targetPage) use ($baseUrl, $perPage) {
                                $params = [
                                    'per_page' => $perPage,
                                    'page'     => $targetPage,
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userId = <?= (int)$user['id'] ?>;
            var select = document.getElementById('auditPerPageSelect');
            if (!select) return;

            var storageKey = 'taskboard_audit_per_page_user_' + userId;
            var urlParams = new URLSearchParams(window.location.search);
            var hasPerPageParam = urlParams.has('per_page');

            if (!hasPerPageParam) {
                var saved = localStorage.getItem(storageKey);
                if (saved && saved !== select.value) {
                    urlParams.set('per_page', saved);
                    urlParams.delete('page');
                    window.location.search = urlParams.toString();
                    return;
                }
            }

            select.addEventListener('change', function() {
                var value = select.value;
                localStorage.setItem(storageKey, value);

                var params = new URLSearchParams(window.location.search);
                params.set('per_page', value);
                params.delete('page');
                window.location.search = params.toString();
            });
        });
    </script>
</body>

</html>