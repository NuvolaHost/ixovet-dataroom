<?php
declare(strict_types=1);

const ADMIN_PASSWORD_SHA256 = '548b26dfbbcbda715aefe5e98e1158d969b958b4ca7ba99cf7934750c101cfa3';
const ADMIN_SESSION_KEY = 'ixovet_admin_authenticated';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('ixovet_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /admin.html');
    exit;
}

$error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $candidate = hash('sha256', $password);

    if (hash_equals(ADMIN_PASSWORD_SHA256, $candidate)) {
        session_regenerate_id(true);
        $_SESSION[ADMIN_SESSION_KEY] = true;
        header('Location: /admin.html');
        exit;
    }

    $error = true;
}

if (!empty($_SESSION[ADMIN_SESSION_KEY])) {
    $adminFile = __DIR__ . DIRECTORY_SEPARATOR . 'admin.html';
    if (!is_file($adminFile)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Arquivo admin.html não encontrado.';
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    readfile($adminFile);
    exit;
}

http_response_code($error ? 401 : 200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acesso Admin · Data Room — IXOVET</title>
<style>
:root{
  --bg:#f3ede3;
  --surface:#fffdf9;
  --ink:#17322b;
  --ink-soft:#2a4a40;
  --muted:#66756c;
  --line:#d8cdbd;
  --brand:#0f766e;
  --brand-dark:#0b5b55;
  --bad:#b94c45;
  --shadow:0 24px 60px rgba(23,50,43,.18);
}
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  font-family:"Candara","Trebuchet MS",sans-serif;
  color:var(--ink);
  background:
    radial-gradient(circle at top left, rgba(12,140,233,.10), transparent 28%),
    radial-gradient(circle at top right, rgba(15,118,110,.10), transparent 24%),
    linear-gradient(180deg, #f7f2ea 0%, #f3ede3 55%, #ece2d5 100%);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px;
}
.card{
  background:var(--surface);
  border:1px solid var(--line);
  border-radius:24px;
  padding:32px;
  width:100%;
  max-width:420px;
  box-shadow:var(--shadow);
}
.eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  font-size:11px;
  letter-spacing:.18em;
  text-transform:uppercase;
  color:var(--brand);
  margin-bottom:8px;
}
.eyebrow .dot{
  width:8px;
  height:8px;
  border-radius:50%;
  background:var(--brand);
  box-shadow:0 0 12px rgba(15,118,110,.5);
}
h1{
  margin:0 0 4px;
  font-size:24px;
  font-family:"Palatino Linotype","Book Antiqua",serif;
  letter-spacing:0;
}
.sub{
  color:var(--muted);
  font-size:13px;
  margin-bottom:22px;
}
label{
  display:block;
  font-size:12px;
  color:var(--ink-soft);
  margin-bottom:6px;
}
.field{margin-bottom:14px}
input[type=password]{
  width:100%;
  padding:12px 14px;
  border:1px solid var(--line);
  border-radius:12px;
  font-size:14px;
  background:#fffdf9;
  color:var(--ink);
  transition:border .15s ease, box-shadow .15s ease;
}
input:focus{
  outline:none;
  border-color:var(--brand);
  box-shadow:0 0 0 3px rgba(15,118,110,.15);
}
.btn{
  width:100%;
  padding:12px 16px;
  border:none;
  border-radius:999px;
  cursor:pointer;
  background:var(--brand);
  color:#fff;
  font-size:14px;
  transition:background .15s ease, transform .15s ease;
}
.btn:hover{background:var(--brand-dark);transform:translateY(-1px)}
.error{
  background:rgba(185,76,69,.08);
  color:var(--bad);
  border:1px solid rgba(185,76,69,.25);
  border-radius:12px;
  padding:10px 12px;
  font-size:13px;
  margin-bottom:14px;
}
.foot{
  text-align:center;
  margin-top:18px;
  font-size:12px;
  color:var(--muted);
}
.foot a{color:var(--brand);text-decoration:none}
.foot a:hover{text-decoration:underline}
</style>
</head>
<body>
<form class="card" method="post" action="/admin.html" autocomplete="on">
  <div class="eyebrow"><span class="dot"></span> Painel administrativo</div>
  <h1>Acesso restrito</h1>
  <div class="sub">Informe a senha do administrador para acessar o Data Room IXOVET.</div>

  <?php if ($error): ?>
    <div class="error">Senha incorreta.</div>
  <?php endif; ?>

  <div class="field">
    <label for="password">Senha do administrador</label>
    <input id="password" name="password" type="password" required autocomplete="current-password" autofocus>
  </div>

  <button class="btn" type="submit">Entrar</button>

  <div class="foot">
    <a href="/">Acessar área do investidor →</a>
  </div>
</form>
</body>
</html>
