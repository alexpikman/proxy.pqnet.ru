<?php
include_once __DIR__ . '/header_status.php';
ini_set('display_errors', 0);
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$email = strtolower($_SESSION['user']);
$msg = '';
$err = '';
$plainPassToShow = '';

if (isset($_SESSION['new_pass_plain'])) {
    $plainPassToShow = $_SESSION['new_pass_plain'];
    unset($_SESSION['new_pass_plain']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_phone'])) {
        $phone = trim($_POST['phone'] ?? '');
        $stmt = $db->prepare("UPDATE users SET phone = ? WHERE LOWER(email) = ?");
        $stmt->execute([$phone, $email]);
        $msg = "Номер телефона успешно сохранен.";
    } elseif (isset($_POST['change_password'])) {
        $newPass = trim($_POST['new_password'] ?? '');

        if (!empty($newPass)) {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtUpd = $db->prepare("UPDATE users SET password_hash = ?, password_changed = 1 WHERE LOWER(email) = ?");
            $stmtUpd->execute([$newHash, $email]);
            
            $_SESSION['new_pass_plain'] = $newPass;
            header('Location: dashboard.php');
            exit;
        } else {
            $err = "Пароль не может быть пустым.";
        }
    }
}

$stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
$stmt->execute([$email]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

$clientsData = xui_api_request('/panel/api/inbounds/list');
$clientSubUrl = '';
$clientVlessKey = '';
$realEmail = $email;

if ($clientsData && isset($clientsData['obj'])) {
    foreach ($clientsData['obj'] as $inbound) {
        $settings = is_string($inbound['settings']) ? json_decode($inbound['settings'], true) : $inbound['settings'];
        if (isset($settings['clients'])) {
            foreach ($settings['clients'] as $c) {
                if (isset($c['email']) && strtolower(trim($c['email'])) === $email) {
                    $realEmail = $c['email'];
                    $subId = $c['subId'] ?? '';
                    if ($subId) {
                        $clientSubUrl = "https://144.31.126.125:2096/" . $subId;
                    }
                    $streamSettings = is_string($inbound['streamSettings']) ? json_decode($inbound['streamSettings'], true) : $inbound['streamSettings'];
                    $network = isset($streamSettings['network']) ? $streamSettings['network'] : 'tcp';
                    $clientVlessKey = "vless://" . $c['id'] . "@" . parse_url(XUI_HOST, PHP_URL_HOST) . ":" . $inbound['port'] . "?type=" . $network . "#" . urlencode($realEmail);
                    break 2;
                }
            }
        }
    }
}

$apps = $db->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$needsPasswordChange = ($userData['password_changed'] ?? 0) == 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет — Proxy PQNET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        body { background-color: #f4f6f9; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card-light { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .form-control-code { background-color: #f8fafc; border: 1px solid #cbd5e1; font-family: monospace; font-size: 0.875rem; color: #0f172a; }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.6; }
            100% { transform: scale(1); opacity: 1; }
        }
        .warning-icon { animation: pulse 1.5s infinite; display: inline-flex; align-items: center; vertical-align: middle; transform: scale(2); transform-origin: left center; margin-left: 8px; }
        .btn-gradient-blue {
            background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
            color: #fff;
            border: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(14, 165, 233, 0.25);
        }
        .btn-gradient-blue:hover {
            background: linear-gradient(135deg, #1e40af, #0284c7);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(14, 165, 233, 0.35);
        }
    
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
<style id="pqnet-mobile-fix">
/* Layout pass: light UI, fluid width, mobile-first controls */
:root{color-scheme:light!important}
html{width:100%;min-width:0;overflow-x:hidden}
body{margin:0!important;padding:24px 0!important;background:#f4f7fb!important;color:#172033!important;overflow-x:hidden}
body> .container{width:100%!important;max-width:760px!important;padding-left:20px!important;padding-right:20px!important;margin-left:auto!important;margin-right:auto!important;box-sizing:border-box}
body .border-bottom{border-color:#e3e8f0!important}
body .fw-bold.fs-5{color:#172033!important;overflow-wrap:anywhere}
body .card-light{min-width:0;overflow:hidden;background:#fff!important;border:1px solid #e3e8f0!important;border-radius:18px!important;box-shadow:0 8px 28px rgba(15,23,42,.055)!important}
body .card-light h5,body .card-light h6{color:#172033!important}
body .text-secondary,body .text-muted,body .small.text-muted{color:#667085!important}
body .form-control,body .form-select,body input,body textarea{min-width:0;max-width:100%;background:#fff!important;color:#172033!important;border-color:#d5deea!important}
body .form-control:focus,body .form-select:focus,body input:focus,body textarea:focus{border-color:#0f9b8e!important;box-shadow:0 0 0 3px rgba(15,155,142,.12)!important}
body .form-control-code{background:#f8fafc!important;color:#172033!important;overflow-wrap:anywhere;word-break:break-all}
body .input-group{min-width:0;flex-wrap:nowrap}
body #vlessKey{width:1%;flex:1 1 auto;min-width:0;font-size:12px}
body .btn{white-space:normal;touch-action:manipulation}
body .btn-primary,body .btn-gradient-blue{background:linear-gradient(100deg,#0f9b8e,#0284c7)!important;color:#fff!important;border:0!important}
body .btn-outline-primary{background:#fff!important;color:#0369a1!important;border-color:#93c5fd!important}
body .btn-outline-danger{color:#b42318!important;border-color:#fda29b!important}
body .table{--bs-table-color:#344054;--bs-table-bg:transparent;--bs-table-border-color:#e3e8f0;color:#344054!important}
body .table thead th{background:#f1f5f9!important;color:#475467!important;white-space:nowrap}
body .table td{vertical-align:middle}
body .modal-content{background:#fff!important;color:#172033!important;border:1px solid #e3e8f0!important;border-radius:18px!important}
body .modal-content h6{color:#172033!important}
body .alert-warning{background:#fffbeb!important;color:#92400e!important;border:1px solid #fde68a!important}
body .alert-success{background:#ecfdf3!important;color:#027a48!important}
body .alert-danger{background:#fef3f2!important;color:#b42318!important}
@media(max-width:576px){
  body{padding:12px 0!important}
  body> .container{padding-left:12px!important;padding-right:12px!important}
  body .container .d-flex.justify-content-between.align-items-center.mb-4{align-items:flex-start!important;gap:12px;flex-wrap:wrap}
  body .container .d-flex.justify-content-between.align-items-center.mb-4> .d-flex:first-child{flex:1 1 100%;min-width:0;align-items:flex-start!important;flex-wrap:wrap}
  body .container .d-flex.justify-content-between.align-items-center.mb-4> .d-flex:last-child{width:100%;justify-content:flex-end}
  body .container .d-flex.justify-content-between.align-items-center.mb-4 .fs-5{font-size:1rem!important}
  body .container .card-light{padding:16px!important;margin-bottom:14px!important;border-radius:15px!important}
  body .container .input-group{display:flex;flex-wrap:wrap;gap:8px}
  body .container .input-group #vlessKey{flex:1 1 100%;width:100%;border-radius:10px!important;min-height:44px}
  body .container .input-group #vlessKey+button{flex:1 1 100%;width:100%;border-radius:10px!important;min-height:42px}
  body .container .d-flex.justify-content-center.gap-2{flex-direction:column}
  body .container .d-flex.justify-content-center.gap-2 .btn{width:100%;justify-content:center;min-height:42px}
  body .container .row.g-2>[class*="col-"],body .container .row.g-3>[class*="col-"]{width:100%}
  body .container .row.g-2 .btn,body .container .row.g-3 .btn{min-height:42px}
  body #phoneInput{font-size:16px;min-height:42px}
  body .table-responsive{margin:0 -4px}
  body .table{font-size:13px}
  body .table td,body .table th{padding:10px 8px}
  body .table td:nth-child(2){min-width:120px;overflow-wrap:anywhere}
  body .table td:nth-child(3){min-width:92px}
  body .table td:nth-child(4){min-width:112px}
  body .modal-dialog{margin:12px}
}
@media(max-width:360px){
  body> .container{padding-left:8px!important;padding-right:8px!important}
  body .container .card-light{padding:12px!important}
  body .container .btn{font-size:13px}
}
</style>
    <link rel="stylesheet" href="pqnet-theme.css?v=1">
</head>
<body class="py-5">
<?php renderServerHeaderStatus(); ?>
<div class="container" style="max-width: 760px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <div class="d-flex align-items-center">
            <div>
                <span class="text-muted small d-block">Учетная запись</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold fs-5" style="color: #0f172a;"><?= htmlspecialchars($realEmail) ?></span>
                    <?php if ($clientVlessKey): ?>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#qrModal" class="text-decoration-none d-inline-flex align-items-center" title="Открыть QR-код">
                            <svg width="22" height="22" viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M336 32H448C465.673 32 480 46.3269 480 64V176" stroke="#60a5fa" stroke-width="40" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M176 32H64C46.3269 32 32 46.3269 32 64V176" stroke="#60a5fa" stroke-width="40" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M336 480H448C465.673 480 480 465.673 480 448V336" stroke="#60a5fa" stroke-width="40" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M176 480H64C46.3269 480 32 465.673 32 448V336" stroke="#60a5fa" stroke-width="40" stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="112" y="112" width="96" height="96" rx="16" fill="#60a5fa"/>
                                <rect x="304" y="112" width="96" height="96" rx="16" fill="#60a5fa"/>
                                <rect x="112" y="304" width="96" height="96" rx="16" fill="#60a5fa"/>
                                <rect x="240" y="240" width="48" height="48" rx="8" fill="#93c5fd"/>
                                <rect x="336" y="304" width="64" height="64" rx="12" fill="#93c5fd"/>
                                <rect x="304" y="400" width="48" height="48" rx="8" fill="#93c5fd"/>
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($needsPasswordChange): ?>
                <span class="badge bg-warning text-dark warning-icon ms-4" title="Требуется смена стандартного пароля!">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22M12 6l7.53 13H4.47M11 10v4h2v-4m-2 6v2h2v-2"/></svg>
                </span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="logout.php" class="btn btn-outline-danger btn-sm px-3">Выход</a>
            <?php if ($clientSubUrl): ?>
                <!-- Полный SVG код пользовательской иконки -->
                <a href="<?= htmlspecialchars($clientSubUrl) ?>" target="_blank" class="btn btn-sm p-0 border-0 bg-transparent d-inline-flex align-items-center justify-content-center" title="Получить подписку">
                    <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 48 48" width="34" height="34"><defs><style>.cls-1{fill:url(#linear-gradient);}.cls-2{fill:url(#linear-gradient-2);}.cls-3{fill:url(#linear-gradient-3);}.cls-4{fill:url(#linear-gradient-4);}.cls-5{fill:url(#linear-gradient-5);}.cls-6{fill:url(#linear-gradient-6);}.cls-7{fill:url(#linear-gradient-7);}.cls-8{fill:url(#linear-gradient-8);}</style><linearGradient id="linear-gradient" x1="24" y1="64" x2="24" y2="-23.3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#f3c57a"/><stop offset="0.49" stop-color="#f39369"/><stop offset="1" stop-color="#e94867"/></linearGradient><linearGradient id="linear-gradient-2" y1="63.12" x2="24" y2="6.69" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-3" x1="24" y1="29.25" x2="24" y2="-16.52" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#fff"/><stop offset="0.52" stop-color="#cce2e6"/><stop offset="1" stop-color="#8fa1bb"/></linearGradient><linearGradient id="linear-gradient-4" x1="24" y1="53.75" x2="24" y2="-20.15" xlink:href="#linear-gradient-3"/><linearGradient id="linear-gradient-5" x1="36" y1="48.82" x2="36" y2="20.31" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#273a9b"/><stop offset="0.56" stop-color="#202f65"/><stop offset="1" stop-color="#021e2f"/></linearGradient><linearGradient id="linear-gradient-6" x1="36" y1="47.73" x2="36" y2="24.68" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-7" x1="36" y1="42.34" x2="36" y2="33.95" xlink:href="#linear-gradient"/><linearGradient id="linear-gradient-8" x1="36" y1="38.05" x2="36" y2="29.47" xlink:href="#linear-gradient-3"/></defs><circle class="cls-1" cx="24" cy="24" r="21"/><path class="cls-2" d="M12,17.83l1.47,24.32a20.88,20.88,0,0,0,21,0L36,17.83,24,20.25Z"/><path class="cls-3" d="M23.81,8a3.64,3.64,0,0,0-3.32,3.7v2.24A1.07,1.07,0,0,0,21.56,15h4.88a1.07,1.07,0,0,0,1.07-1.07V11.49A3.51,3.51,0,0,0,23.81,8Z"/><path class="cls-4" d="M34.67,16.5H13.33a1.33,1.33,0,0,0-.26,2.63l5.36,1.06a.94.94,0,0,1,.71.62l-2.35,9.51a1,1,0,0,0,.92,1.17h.79l-.39,5.8A1.56,1.56,0,0,0,19.38,39a1.49,1.49,0,0,0,1.69-1.25l.69-4.46a2.09,2.09,0,0,1,2.07-1.77h.34a2.09,2.09,0,0,1,2.07,1.77l.69,4.46A1.49,1.49,0,0,0,28.62,39a1.56,1.56,0,0,0,1.27-1.68l-.39-5.8h.81a1,1,0,0,0,.92-1.16L29,20.57a.94.94,0,0,1,.58-.38l5.36-1.06a1.33,1.33,0,0,0-.26-2.63Z"/><circle class="cls-5" cx="36" cy="36" r="9"/><path class="cls-6" d="M39.92,36h0a1.32,1.32,0,0,1,.38-.93l.86-.87a.63.63,0,0,0,.1-.76l-.41-.71a.63.63,0,0,0-.7-.29l-1.2.31a1.31,1.31,0,0,1-1-.14h0a1.29,1.29,0,0,1-.58-.77L37,30.63a.63.63,0,0,0-.61-.46h-.82a.63.63,0,0,0-.61.46l-.33,1.2a1.29,1.29,0,0,1-.58.77h0a1.31,1.31,0,0,1-1,.14l-1.2-.31a.63.63,0,0,0-.7.29l-.41.71a.63.63,0,0,0,.1.76l.9.91a1.24,1.24,0,0,1,.34.89h0a1.24,1.24,0,0,1-.34.89l-.9.91a.63.63,0,0,0-.1.76l.41.71a.63.63,0,0,0,.7.29l1.25-.33a1.22,1.22,0,0,1,.94.15l0,0a1.21,1.21,0,0,1,.56.72L35,41.37a.63.63,0,0,0,.61.46h.82a.63.63,0,0,0,.61-.46l.33-1.21a1.27,1.27,0,0,1,.58-.76l.06,0a1.18,1.18,0,0,1,.9-.13l1.25.33a.63.63,0,0,0,.7-.29l.41-.71a.63.63,0,0,0-.1-.76l-.86-.87a1.32,1.32,0,0,1-.38-.93Z"/><path class="cls-7" d="M34.58,40a1.13,1.13,0,0,1,.06.12L35,41.37a.63.63,0,0,0,.61.46h.82a.63.63,0,0,0,.61-.46l.33-1.21a1.24,1.24,0,0,1,.07-.15V36H34.58Z"/><circle class="cls-8" cx="36" cy="36" r="1.42"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success py-2 px-3 small border-0 mb-4"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger py-2 px-3 small border-0 mb-4"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <!-- 1. САМЫЙ ВЕРХНИЙ БЛОК: КЛЮЧ VLESS -->
    <div class="card card-light p-4 mb-4">
        <h5 class="fw-bold mb-3" style="color: #0f172a;">Ключ VLESS</h5>
        <?php if ($clientVlessKey): ?>
            <div class="mb-0">
                <div class="input-group">
                    <input type="text" id="vlessKey" class="form-control form-control-code" value="<?= htmlspecialchars($clientVlessKey) ?>" readonly>
                    <button class="btn btn-outline-primary btn-sm px-3" onclick="navigator.clipboard.writeText(document.getElementById('vlessKey').value)">Копировать</button>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning border-0 small mb-0">Конфигурация не найдена в 3X-UI.</div>
        <?php endif; ?>
    </div>

    <!-- 2. НИЖЕ: КНОПКИ НА ФАЙЛЫ СО ССЫЛКОЙ VLESS -->
    <?php if ($clientVlessKey): ?>
        <div class="card card-light p-3 mb-4 text-center">
            <span class="text-secondary small d-block mb-2">Скачать конфигурационный ключ в файл</span>
            <div class="d-flex justify-content-center gap-2">
                <a href="download_key.php?format=txt" class="btn btn-gradient-blue btn-sm px-4 d-inline-flex align-items-center gap-1.5 fw-medium">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Скачать TXT</span>
                </a>
                <a href="download_key.php?format=json" class="btn btn-gradient-blue btn-sm px-4 d-inline-flex align-items-center gap-1.5 fw-medium">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Скачать JSON</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. ФОРМА ПРЕДУПРЕЖДЕНИЯ ДЛЯ СМЕНЫ ПАРОЛЯ -->
    <?php if ($needsPasswordChange): ?>
        <div class="card card-light p-4 mb-4 border-warning shadow-sm" style="background-color: #fffbeb;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <svg width="20" height="20" class="text-warning" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22M12 6l7.53 13H4.47M11 10v4h2v-4m-2 6v2h2v-2"/></svg>
                <h5 class="fw-bold text-warning-emphasis mb-0">Требуется смена временного пароля</h5>
            </div>
            <p class="small text-secondary mb-3">Вы используете стандартный пароль. Пожалуйста, установите собственный новый пароль.</p>
            <form method="POST">
                <input type="hidden" name="change_password" value="1">
                <div class="row g-2">
                    <div class="col-md-8">
                        <input type="password" name="new_password" class="form-control form-control-sm" placeholder="Новый пароль" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-warning btn-sm w-100 fw-medium">Сменить</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Настройки профиля (Телефон и смена пароля если уже изменен) -->
    <div class="card card-light p-4 mb-4">
        <h5 class="fw-bold mb-3" style="color: #0f172a;">Персональные данные</h5>
        <form method="POST" class="row g-3">
            <input type="hidden" name="update_phone" value="1">
            <div class="col-md-8">
                <label class="form-label small fw-medium text-secondary">Номер телефона</label>
                <input type="text" id="phoneInput" name="phone" class="form-control form-control-sm" value="<?= htmlspecialchars($userData['phone'] ?? '') ?>" placeholder="+7 (999) 999 - 99 - 99" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">Сохранить телефон</button>
            </div>
        </form>

        <?php if (!$needsPasswordChange): ?>
            <hr class="text-secondary opacity-25 my-4">
            <h6 class="fw-semibold mb-3 small text-uppercase text-secondary">Сменить пароль</h6>
            <form method="POST">
                <input type="hidden" name="change_password" value="1">
                <div class="row g-2">
                    <div class="col-md-8">
                        <input type="text" name="new_password" class="form-control form-control-sm" placeholder="Новый пароль" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100 fw-medium">Сменить</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- 5. СПИСОК ССЫЛОК НА ПРИЛОЖЕНИЯ -->
    <div class="card card-light p-4">
        <h5 class="fw-bold mb-3" style="color: #0f172a;">Список приложений</h5>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr class="text-secondary small">
                        <th style="width: 50px;">Лого</th>
                        <th>Наименование</th>
                        <th>Ссылка</th>
                        <th class="text-end">Дата добавления</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($apps)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3 small">Список приложений пуст</td></tr>
                    <?php else: ?>
                        <?php foreach ($apps as $app): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($app['logo'])): ?>
                                        <img src="<?= htmlspecialchars($app['logo']) ?>" alt="logo" style="width: 32px; height: 32px; object-fit: contain;" class="rounded">
                                    <?php else: ?>
                                        <div class="bg-secondary bg-opacity-10 rounded d-flex align-items-center justify-content-center text-secondary small" style="width: 32px; height: 32px;">APP</div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-medium"><?= htmlspecialchars($app['name']) ?></td>
                                <td><a href="<?= htmlspecialchars($app['url']) ?>" target="_blank" class="btn btn-outline-primary btn-sm px-3 py-1">Скачать</a></td>
                                <td class="text-end text-muted small"><?= htmlspecialchars($app['added_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модальное окно для QR-кода -->
<?php if ($clientVlessKey): ?>
<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content card-light border-0 text-center p-4">
            <h6 class="fw-bold mb-3" style="color: #0f172a;">QR-код для подключения</h6>
            <div class="d-flex justify-content-center mb-3">
                <div id="qrcode-modal" class="p-3 bg-white border rounded"></div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Закрыть</button>
        </div>
    </div>
</div>
<script>
    new QRCode(document.getElementById("qrcode-modal"), {
        text: "<?= $clientVlessKey ?>",
        width: 160,
        height: 160
    });
</script>
<?php endif; ?>

<!-- Модальное окно показа нового пароля при успешной смене -->
<?php if ($plainPassToShow): ?>
<div class="modal fade" id="newPasswordModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content card-light border-0 text-center p-4">
            <h6 class="fw-bold mb-2" style="color: #0f172a;">Пароль успешно изменен</h6>
            <p class="small text-secondary mb-3">Ваш новый пароль:</p>
            <div class="input-group mb-3">
                <input type="text" id="newPassField" class="form-control form-control-code text-center" value="<?= htmlspecialchars($plainPassToShow) ?>" readonly>
            </div>
            <button type="button" class="btn btn-primary btn-sm mb-2" onclick="navigator.clipboard.writeText(document.getElementById('newPassField').value); alert('Пароль скопирован в буфер обмена!');">Копировать</button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Закрыть</button>
        </div>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        var pwModal = new bootstrap.Modal(document.getElementById('newPasswordModal'));
        pwModal.show();
    });
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('phoneInput').addEventListener('input', function (e) {
    let x = e.target.value.replace(/\D/g, '').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
    if (!x) return;
    let res = '+7';
    if (x[2]) res += ' (' + x[2];
    if (x[3]) res += ') ' + x[3];
    if (x[4]) res += ' - ' + x[4];
    if (x[5]) res += ' - ' + x[5];
    e.target.value = res;
});
</script>
</body>
</html>
