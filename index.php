<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/db.php';

if (isset($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
$error = isset($_GET['error']);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1020">
<title>PQNET — личный кабинет</title>
<style>
:root{color-scheme:dark;--bg:#080d19;--panel:#10192b;--line:#253149;--muted:#9aa8bd;--text:#f3f6fc;--accent:#6ee7d2;--accent2:#4cb6f5}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 18% 15%,#153044 0,transparent 38%),radial-gradient(ellipse at 85% 90%,#13243a 0,transparent 35%),var(--bg);color:var(--text);font:16px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;padding:28px}
.shell{width:min(100%,1000px);min-height:600px;display:grid;grid-template-columns:1.05fr .95fr;background:rgba(12,19,34,.86);border:1px solid #26334a;border-radius:28px;overflow:hidden;box-shadow:0 32px 100px #0007;backdrop-filter:blur(18px)}
.intro{padding:48px;display:flex;flex-direction:column;justify-content:space-between;background:linear-gradient(145deg,#13283a99,#0b1220aa);border-right:1px solid #26334a}
.brand{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:800;letter-spacing:.12em}
.mark{width:42px;height:42px;display:grid;place-items:center;border-radius:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#081321;font-weight:900;font-size:21px;box-shadow:0 8px 30px #4cb6f533}
.kicker{display:inline-flex;align-items:center;gap:9px;color:var(--accent);font-size:12px;letter-spacing:.16em;text-transform:uppercase;font-weight:800;margin-bottom:18px}
.dot{width:7px;height:7px;background:var(--accent);border-radius:50%;box-shadow:0 0 15px var(--accent)}
h1{font-size:clamp(34px,4vw,48px);line-height:1.07;letter-spacing:-.045em;margin:0 0 20px;max-width:480px}
h1 span{color:var(--accent)}
.lead{color:#aab8ca;max-width:390px;margin:0;font-size:16px}
.features{display:grid;gap:14px;margin-top:34px}
.feature{display:flex;align-items:center;gap:13px;color:#dce5f1;font-size:14px}
.feature-icon{width:34px;height:34px;flex:none;border:1px solid #31445b;background:#15263a;border-radius:11px;display:grid;place-items:center;color:var(--accent);font-size:15px}
.foot{color:#718097;font-size:12px}
.login{padding:48px;display:flex;flex-direction:column;justify-content:center}
.login h2{font-size:26px;letter-spacing:-.03em;margin:0 0 8px}
.sub{color:var(--muted);font-size:14px;margin:0 0 30px}
label{display:block;font-size:13px;font-weight:650;color:#d6dfec;margin:18px 0 8px}
input{width:100%;padding:14px 15px;border:1px solid #2c3a51;border-radius:12px;background:#0b1322;color:var(--text);font:inherit;outline:none;transition:border .15s,box-shadow .15s}
input::placeholder{color:#62728a}
input:focus{border-color:var(--accent2);box-shadow:0 0 0 4px #4cb6f51b}
button{width:100%;margin-top:25px;border:0;border-radius:12px;padding:14px 18px;background:linear-gradient(100deg,var(--accent),#83d9ff);color:#071320;font:inherit;font-weight:800;cursor:pointer;transition:transform .15s,filter .15s}
button:hover{filter:brightness(1.06);transform:translateY(-1px)}
.alert{padding:12px 14px;border:1px solid #7f3448;background:#381c2b;color:#ffc0cd;border-radius:11px;font-size:13px;margin:0 0 16px}
.secure{display:flex;justify-content:center;align-items:center;gap:8px;color:#7f90a8;font-size:12px;margin-top:22px}
.secure svg{width:14px;height:14px;color:var(--accent)}
@media(max-width:760px){body{padding:12px}.shell{grid-template-columns:1fr;min-height:0;border-radius:22px}.intro{padding:28px 25px;border-right:0;border-bottom:1px solid #26334a}.intro-main{margin-top:34px}.intro h1{font-size:34px}.features{grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}.feature{font-size:12px;align-items:flex-start}.feature-icon{width:30px;height:30px;border-radius:9px}.foot{margin-top:26px}.login{padding:30px 25px 32px}.login h2{font-size:24px}}
@media(max-width:380px){.features{grid-template-columns:1fr}body{padding:8px}}
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
<body>
<main class="shell">
  <section class="intro">
    <div class="brand"><span class="mark">P</span><span>PQNET</span></div>
    <div class="intro-main">
      <div class="kicker"><span class="dot"></span> Ваше подключение под контролем</div>
      <h1>Интернет без лишних <span>границ.</span></h1>
      <p class="lead">Управляйте подпиской, настройками подключения и балансом в одном личном кабинете.</p>
      <div class="features">
        <div class="feature"><span class="feature-icon">↗</span><span>Управление подпиской</span></div>
        <div class="feature"><span class="feature-icon">◈</span><span>Баланс и пополнение</span></div>
        <div class="feature"><span class="feature-icon">⌁</span><span>Ключи подключения</span></div>
        <div class="feature"><span class="feature-icon">✓</span><span>Контроль аккаунта</span></div>
      </div>
    </div>
    <div class="foot">© <?= date('Y') ?> PQNET · Личный кабинет</div>
  </section>
  <section class="login">
    <h2>С возвращением</h2>
    <p class="sub">Войдите, чтобы открыть личный кабинет PQNET.</p>
    <?php if ($error): ?>
      <div class="alert" role="alert">Не удалось войти. Проверьте логин и пароль и попробуйте ещё раз.</div>
    <?php endif; ?>
    <form action="login.php" method="post" autocomplete="on">
      <label for="email">Логин или email</label>
      <input id="email" name="email" type="text" autocomplete="username" placeholder="Введите логин или email" required autofocus>
      <label for="password">Пароль</label>
      <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Введите пароль" required>
      <button type="submit">Войти в кабинет <span aria-hidden="true">→</span></button>
    </form>
    <div class="secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> Защищённый вход в аккаунт</div>
  </section>
</main>
</body>
</html>
