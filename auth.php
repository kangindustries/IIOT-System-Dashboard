<?php
require_once __DIR__ . '/config.php';
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
function isLoggedIn() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
function attemptLogin($username, $password) {
    if ($username === AUTH_USERNAME && password_verify($password, AUTH_PASSWORD_HASH)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}