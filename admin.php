<?php
include_once __DIR__ . '/header_status.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user'])) {
        $email = strtolower(trim($_POST['user_email']));
        $newPass = $_POST['new_password'];
        $customContent = $_POST['custom_content'] ?? '';

        $stmtCheck = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
        $stmtCheck->execute([$email]);
        $exists = $stmtCheck->fetch();

        if (!empty($newPass)) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            if ($exists) {
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, password_changed = 1, custom_content = ? WHERE LOWER(email) = ?");
                $stmt->execute([$hash, $customContent, $email]);
            } else {
                $stmt = $db->prepare("INSERT INTO users (email, password_hash, password_changed, custom_content) VALUES (?, ?, 1, ?)");
                $stmt->execute([$email, $hash, $customContent]);
            }
            $msg = "Параметры пользователя {$email} сохранены.";
        } else {
            if ($exists) {
                $stmt = $db->prepare("UPDATE users SET custom_content = ? WHERE LOWER(email) = ?");
                $stmt->execute([$customContent, $email]);
            } else {
                $defaultHash = password_hash('proxy@PQnet', PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (email, password_hash, password_changed, custom_content) VALUES (?, ?, 0, ?)");
                $stmt->execute([$email, $defaultHash, $customContent]);
            }
            $msg = "Инструкция для {$email} обновлена.";
        }
    } elseif (isset($_POST['reset_password'])) {
        $email = strtolower(trim($_POST['user_email']));
        $defaultHash = password_hash('proxy@PQnet', PASSWORD_DEFAULT);
        
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, password_changed = 0 WHERE LOWER(email) = ?");
            $stmt->execute([$defaultHash, $email]);
        } else {
            $stmt = $db->prepare("INSERT INTO users (email, password_hash, password_changed) VALUES (?, ?, 0)");
            $stmt->execute([$email, $defaultHash]);
        }
        $msg = "Пароль для {$email} сброшен на стандартный: proxy@PQnet";
    } elseif (isset($_POST['add_app'])) {
        $name = trim($_POST['app_name']);
        $url = trim($_POST['app_url']);
        $logo = trim($_POST['app_logo']);
        if ($name && $url) {
            $stmt = $db->prepare("INSERT INTO apps (name, url, logo) VALUES (?, ?, ?)");
            $stmt->execute([$name, $url, $logo]);
            $msg = "Приложение успешно добавлено.";
        }
    } elseif (isset($_POST['delete_app'])) {
        $appId = $_POST['app_id'];
        $stmt = $db->prepare("DELETE FROM apps WHERE id = ?");
        $stmt->execute([$appId]);
        $msg = "Приложение удалено.";
    }
}

$xuiResponse = xui_api_request('/panel/api/inbounds/list');
$xuiClients = [];

if ($xuiResponse && isset($xuiResponse['obj'])) {
    foreach ($xuiResponse['obj'] as $inbound) {
        $settings = is_string($inbound['settings']) ? json_decode($inbound['settings'], true) : $inbound['settings'];
        if (isset($settings['clients'])) {
            foreach ($settings['clients'] as $c) {
                if (!empty($c['email'])) {
                    $xuiClients[] = trim($c['email']);
                }
            }
        }
    }
}
$xuiClients = array_unique($xuiClients);

$dbUsers = $db->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$dbUsersMap = [];
foreach ($dbUsers as $u) {
    $dbUsersMap[strtolower(trim($u['email']))] = $u;
}

$apps = $db->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Панель администратора — Proxy PQNET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-light { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .table-light-custom th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .table-light-custom td { border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.6; }
            100% { transform: scale(1); opacity: 1; }
        }
        .warning-icon { animation: pulse 1.5s infinite; display: inline-flex; align-items: center; vertical-align: middle; }
    
/* PQNET v2 visual layer */
:root{color-scheme:dark;--pq-bg:#080d19;--pq-panel:#10192b;--pq-line:#26344b;--pq-text:#f3f6fc;--pq-muted:#9aa8bd;--pq-accent:#6ee7d2}
body{background:radial-gradient(ellipse at 12% 0%,#153044 0,transparent 36%),var(--pq-bg)!important;color:var(--pq-text)!important;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif!important}
.container{max-width:1040px!important}
.card,.card-light,.bg-white,.login-card{background:rgba(16,25,43,.92)!important;color:var(--pq-text)!important;border:1px solid var(--pq-line)!important;border-radius:18px!important;box-shadow:0 16px 44px #0002!important}
.border-bottom,.border,.table td,.table th{border-color:var(--pq-line)!important}
.text-muted,.text-secondary,.small.text-muted{color:var(--pq-muted)!important}
.form-control,.form-select,input,textarea,select{background:#0b1322!important;color:var(--pq-text)!important;border:1px solid #2c3a51!important;border-radius:10px!important}
.form-control:focus,input:focus,textarea:focus,select:focus{border-color:#4cb6f5!important;box-shadow:0 0 0 3px #4cb6f51b!important}
.btn-primary,.btn-gradient-blue,button[type=submit]{background:linear-gradient(100deg,#6ee7d2,#83d9ff)!important;color:#071320!important;border:0!important;border-radius:10px!important;font-weight:750!important}
.btn-outline-primary,.btn-outline-secondary{border-radius:10px!important}
.table{color:var(--pq-text)!important;--bs-table-bg:transparent;--bs-table-color:var(--pq-text);--bs-table-border-color:var(--pq-line)}
.table-light-custom th,.table-light th{background:#152238!important;color:#aab8ca!important}
a{color:#79d9f2}a:hover{color:var(--pq-accent)}
.alert{border-radius:12px!important}
@media(max-width:576px){body{padding-top:1rem!important}.container{padding-left:14px;padding-right:14px}.card,.card-light{padding:16px!important}}

</style>
<style>
:root{color-scheme:light!important;--bg:#f4f7fb!important;--panel:#ffffff!important;--text:#172033!important;--muted:#667085!important;--line:#e3e8f0!important;--accent:#0f766e!important;--accent2:#0284c7!important;--pq-bg:#f4f7fb!important;--pq-panel:#ffffff!important;--pq-text:#172033!important;--pq-muted:#667085!important;--pq-line:#e3e8f0!important;--pq-accent:#0f766e!important}
html,body{background:#f4f7fb!important;color:#172033!important;color-scheme:light!important}
body{background:linear-gradient(135deg,#f8fafc,#edf4fb)!important;color:#172033!important}
.shell,.intro,.login,.card,.card-light,.bg-white,.login-card,.container .card,.modal-content,.dropdown-menu{background:#fff!important;color:#172033!important;border-color:#e3e8f0!important;box-shadow:0 12px 36px rgba(15,23,42,.07)!important}
.intro{background:linear-gradient(145deg,#f0fdfa,#eff6ff)!important;border-color:#e3e8f0!important}
.intro h1,.intro h2,.login h2,h1,h2,h3,h4,h5,h6,label,.form-label,.modal-title{color:#172033!important}
.sub,.text-muted,.text-secondary,.small.text-muted,.secure,.foot,.feature{color:#667085!important}
input,textarea,select,.form-control,.form-select{background:#fff!important;color:#172033!important;border:1px solid #d5deea!important}
input::placeholder,textarea::placeholder{color:#98a2b3!important}
input:focus,textarea:focus,select:focus,.form-control:focus,.form-select:focus{border-color:#0f9b8e!important;box-shadow:0 0 0 3px rgba(15,155,142,.12)!important}
button,.btn-primary,.btn-gradient-blue,button[type=submit]{background:linear-gradient(100deg,#0f9b8e,#0284c7)!important;color:#fff!important;border:0!important}
.btn-outline-primary{color:#0369a1!important;border-color:#93c5fd!important;background:#fff!important}
.btn-outline-secondary{color:#475467!important;border-color:#d0d5dd!important;background:#fff!important}
.table{color:#344054!important;--bs-table-bg:transparent;--bs-table-color:#344054;--bs-table-border-color:#e3e8f0}
.table-light-custom th,.table-light th,thead th{background:#f1f5f9!important;color:#475467!important}
.table td,.table th,.border,.border-bottom{border-color:#e3e8f0!important}
a{color:#087f8c!important}a:hover{color:#0369a1!important}
.alert{background:#fff7ed!important;color:#9a3412!important;border-color:#fed7aa!important}
.badge{color:inherit}
.modal-header,.modal-footer{border-color:#e3e8f0!important}
@media(prefers-color-scheme:dark){html,body{color-scheme:light!important;background:#f4f7fb!important;color:#172033!important}}
</style>
</head>
<body class="py-4">
<?php renderServerHeaderStatus(); ?>
<div class="container" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <h4 class="fw-bold mb-0" style="color: #0f172a;">Панель администратора</h4>
        <a href="logout.php" class="btn btn-outline-danger btn-sm px-3">Выход</a>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success py-2 px-3 small border-0 mb-4"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Управление списком приложений -->
    <div class="card card-light p-4 mb-4">
        <h5 class="fw-bold mb-3" style="color: #0f172a;">Управление списком приложений</h5>
        <form method="POST" class="row g-2 mb-4">
            <input type="hidden" name="add_app" value="1">
            <div class="col-md-3">
                <input type="text" name="app_name" class="form-control form-control-sm" placeholder="Наименование" required>
            </div>
            <div class="col-md-4">
                <input type="url" name="app_url" class="form-control form-control-sm" placeholder="Ссылка на скачивание" required>
            </div>
            <div class="col-md-3">
                <input type="text" name="app_logo" class="form-control form-control-sm" placeholder="URL иконки (логотипа)">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Добавить</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-light-custom mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">Лого</th>
                        <th>Наименование</th>
                        <th>Ссылка</th>
                        <th>Дата</th>
                        <th class="text-end">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($apps)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3 small">Нет добавленных приложений</td></tr>
                    <?php else: ?>
                        <?php foreach ($apps as $app): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($app['logo'])): ?>
                                        <img src="<?= htmlspecialchars($app['logo']) ?>" style="width: 28px; height: 28px; object-fit: contain;" class="rounded">
                                    <?php else: ?>
                                        <div class="bg-secondary bg-opacity-10 rounded d-flex align-items-center justify-content-center text-secondary small" style="width: 28px; height: 28px;">APP</div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-medium"><?= htmlspecialchars($app['name']) ?></td>
                                <td><a href="<?= htmlspecialchars($app['url']) ?>" target="_blank" class="small text-truncate d-inline-block" style="max-width: 250px;"><?= htmlspecialchars($app['url']) ?></a></td>
                                <td class="text-muted small"><?= htmlspecialchars($app['added_date']) ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Удалить приложение?');">
                                        <input type="hidden" name="delete_app" value="1">
                                        <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm py-0 px-2">Удалить</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Список клиентов 3X-UI -->
    <div class="card card-light p-4">
        <h5 class="fw-bold mb-3" style="color: #0f172a;">Клиенты 3X-UI</h5>
        <div class="table-responsive">
            <table class="table table-light-custom mt-2 mb-0">
                <thead>
                    <tr>
                        <th>Email / Статус пароля</th>
                        <th>Телефон</th>
                        <th>Последний вход</th>
                        <th class="text-end">Управление</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($xuiClients)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Клиенты не найдены через API 3X-UI</td></tr>
                    <?php else: ?>
                        <?php foreach ($xuiClients as $clientEmail): ?>
                            <?php 
                                $lowerEmailKey = strtolower(trim($clientEmail));
                                $uData = $dbUsersMap[$lowerEmailKey] ?? null;
                                $hasPassChanged = ($uData && $uData['password_changed'] == 1);
                                $lastLogin = $uData['last_login'] ?? 'Никогда';
                                $phone = $uData['phone'] ?? '';
                            ?>
                            <tr>
                                <td>
                                    <span class="fw-medium"><?= htmlspecialchars($clientEmail) ?></span>
                                    <?php if (!$hasPassChanged): ?>
                                        <span class="ms-1 warning-icon text-warning" title="Пароль стандартный (не изменен)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22M12 6l7.53 13H4.47M11 10v4h2v-4m-2 6v2h2v-2"/></svg>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-secondary"><?= htmlspecialchars($phone ?: 'не указан') ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($lastLogin) ?></td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        <form method="POST" onsubmit="return confirm('Сбросить пароль пользователя <?= htmlspecialchars($clientEmail) ?> на стандартный proxy@PQnet?');">
                                            <input type="hidden" name="reset_password" value="1">
                                            <input type="hidden" name="user_email" value="<?= htmlspecialchars($clientEmail) ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm" title="Сбросить пароль на proxy@PQnet">Сбросить пароль</button>
                                        </form>
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?= md5($clientEmail) ?>">
                                            Настроить
                                        </button>
                                    </div>

                                    <div class="modal fade" id="editModal<?= md5($clientEmail) ?>" tabindex="-1" align="left">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 card-light">
                                                <form method="POST">
                                                    <div class="modal-header border-bottom">
                                                        <h6 class="modal-title fw-bold" style="color: #0f172a;"><?= htmlspecialchars($clientEmail) ?></h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="user_email" value="<?= htmlspecialchars($clientEmail) ?>">
                                                        
                                                        <div class="mb-3 text-start">
                                                            <label class="form-label small fw-medium text-secondary">Установить новый пароль</label>
                                                            <input type="text" name="new_password" class="form-control form-control-sm" placeholder="Оставьте пустым, чтобы не менять">
                                                        </div>

                                                        <div class="mb-3 text-start">
                                                            <label class="form-label small fw-medium text-secondary">Индивидуальный текст / Инструкция</label>
                                                            <textarea name="custom_content" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($uData['custom_content'] ?? '') ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-top">
                                                        <button type="submit" name="save_user" class="btn btn-primary btn-sm px-3">Сохранить</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
