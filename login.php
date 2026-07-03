<?php
require_once __DIR__ . '/auth.php';
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attemptLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login — Maintenance Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: #f2f2f7;
    font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 24px;
  }
  .box {
    background: #fff;
    border-radius: 16px;
    padding: 36px 32px;
    width: 100%;
    max-width: 360px;
  }
  h1 {
    font-size: 22px;
    font-weight: 700;
    margin-bottom: 4px;
  }
  .sub {
    font-size: 14px;
    color: #8e8e93;
    margin-bottom: 28px;
  }
  label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #3a3a3c;
    margin-bottom: 6px;
  }
  input {
    display: block;
    width: 100%;
    padding: 11px 14px;
    font-size: 15px;
    font-family: inherit;
    border: 1px solid rgba(60,60,67,0.18);
    border-radius: 10px;
    background: #f2f2f7;
    color: #1c1c1e;
    margin-bottom: 16px;
    outline: none;
  }
  input:focus { border-color: #007AFF; background: #fff; }
  button {
    width: 100%;
    padding: 13px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    background: #007AFF;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    margin-top: 4px;
  }
  button:hover { opacity: 0.85; }
  .error {
    background: rgba(255,59,48,0.1);
    color: #d70015;
    font-size: 13px;
    font-weight: 500;
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 16px;
  }
</style>
</head>
<body>
<div class="box">
  <h1>Dashboard</h1>
  <p class="sub">IIoT Servo System</p>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" autocomplete="username" required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
</div>
</body>
</html>