<?php
require_once __DIR__ . '/auth.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method Not Allowed');
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$pendingFile = __DIR__ . '/data/pending_predictive_alert.json';
header('Content-Type: application/json');

if (file_exists($pendingFile)) {
    $data = json_decode(file_get_contents($pendingFile), true);
    if (!is_array($data)) {
        echo json_encode(['error' => 'Pending alert file corrupted']);
        exit;
    }
    $data['cancelled'] = true;
    $written = file_put_contents($pendingFile, json_encode($data));
    if ($written === false) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => 'Failed to write cancellation']);
        exit;
    }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => true, 'note' => 'No pending alert']);
}