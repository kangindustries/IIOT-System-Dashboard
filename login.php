<?php
require_once __DIR__ . '/auth.php';
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}
$error = '';
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['lockout_time'] = $_SESSION['lockout_time'] ?? 0;
if (time() < $_SESSION['lockout_time']) {
    $remaining = $_SESSION['lockout_time'] - time();
    $error = "Too many failed attempts. Please wait " . $remaining . " seconds.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!attemptLogin($username, $password)) {
        $_SESSION['login_attempts']++;
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['lockout_time'] = time() + 300;
            $error = 'Too many failed attempts. You are locked out for 5 minutes.';
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        unset($_SESSION['login_attempts']);
        unset($_SESSION['lockout_time']);
        header('Location: index.php');
        exit;
    }
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
    font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', Helvetica, Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    display: flex;
    background: #e8e8ed;
    padding: 24px;
  }

  .split {
    display: flex;
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    min-height: 640px;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.12);
  }

  @media (max-width: 900px) {
    .split {
      flex-direction: column;
      min-height: auto;
    }
  }

  .form-side {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    padding: 24px;
  }

  .panel-side {
    flex: 1;
    background: linear-gradient(160deg, #3a3a3c 0%, #1d1d1f 65%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    min-height: 240px;
    position: relative;
    overflow: hidden;
  }

  .panel-decor {
    position: absolute;
    bottom: -140px;
    left: -140px;
    width: 420px;
    height: 420px;
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 50%;
    pointer-events: none;
  }

  .panel-decor::before {
    content: '';
    position: absolute;
    top: 60px;
    left: 60px;
    width: 300px;
    height: 300px;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 50%;
  }

  @media (max-width: 900px) {
    .panel-side {
      order: -1;
      min-height: 160px;
      padding: 32px;
    }
    .panel-decor {
      display: none;
    }
  }

  .panel-content {
    text-align: left;
    position: relative;
    z-index: 1;
  }

  .panel-title {
    font-size: 32px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: #ffffff;
    line-height: 1.25;
  }

  .panel-sub {
    font-size: 15px;
    color: rgba(255,255,255,0.55);
    margin-top: 12px;
    max-width: 300px;
    line-height: 1.5;
  }

  @media (max-width: 900px) {
    .panel-content {
      text-align: center;
    }
    .panel-sub {
      margin-left: auto;
      margin-right: auto;
    }
    .panel-title {
      font-size: 24px;
    }
    .panel-sub {
      font-size: 13px;
    }
  }

  .box {
    width: 100%;
    max-width: 360px;
  }

  h1 {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -0.01em;
    color: #1d1d1f;
    margin-bottom: 15px;
    line-height: 1.3;
  }

  .sub {
    font-size: 14px;
    color: #86868b;
    margin-bottom: 32px;
  }

  label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #515154;
    margin-bottom: 6px;
  }

  input {
    display: block;
    width: 100%;
    padding: 11px 14px;
    font-size: 15px;
    font-family: inherit;
    border: 1px solid #e8e8ed;
    border-radius: 10px;
    background: #fafafa;
    color: #1d1d1f;
    margin-bottom: 18px;
    outline: none;
    transition: border-color 0.15s ease, background 0.15s ease;
  }

  input:focus {
    border-color: #1d1d1f;
    background: #ffffff;
  }

  button {
    width: 100%;
    padding: 13px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    background: #1d1d1f;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    margin-top: 6px;
    transition: opacity 0.15s ease;
  }

  button:hover {
    opacity: 0.85;
  }

  .error {
    background: rgba(255,59,48,0.08);
    color: #d70015;
    font-size: 13px;
    font-weight: 500;
    padding: 11px 14px;
    border-radius: 10px;
    margin-bottom: 18px;
    line-height: 1.4;
  }
</style>
</head>
<body>
<div class="split">
  <div class="panel-side">
    <div class="panel-decor"></div>
    <div class="panel-content">
      <div class="panel-title">Monitoring<br>Dashboard</div>
      <div class="panel-sub">An easy way to monitor the IIoT servo system.</div>
    </div>
  </div>
  <div class="form-side">
    <div class="box">
      <h1>Sign In</h1>
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
  </div>
</div>
</body>
</html>