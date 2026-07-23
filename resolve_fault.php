<?php
require_once __DIR__ . '/auth.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method Not Allowed');
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}
$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$faultTimestamp = isset($_POST['fault_timestamp']) ? intval($_POST['fault_timestamp']) : 0;
$operatorName = isset($_POST['operator_name']) ? trim($_POST['operator_name']) : '';
$resolutionNotes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : '';
$resolvedTimeStr = isset($_POST['resolved_time']) ? trim($_POST['resolved_time']) : '';
$eventType = isset($_POST['event_type']) ? trim($_POST['event_type']) : 'fault';
if (!in_array($eventType, ['fault', 'service'], true)) {
    $eventType = 'fault';
}
$resolvedTimestamp = strtotime($resolvedTimeStr);
if ($resolvedTimestamp === false || $resolvedTimestamp <= 0) {
    $resolvedTimestamp = time();
}
if ($faultTimestamp <= 0 || $operatorName === '' || $resolutionNotes === '') {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Missing or invalid fields']);
    exit;
}
$stmt = $db->prepare("INSERT OR REPLACE INTO fault_resolutions (fault_timestamp, resolved_timestamp, operator_name, resolution_notes, event_type) VALUES (:fault_timestamp, :resolved_timestamp, :operator_name, :resolution_notes, :event_type)");
$stmt->bindValue(':fault_timestamp', $faultTimestamp, SQLITE3_INTEGER);
$stmt->bindValue(':resolved_timestamp', $resolvedTimestamp, SQLITE3_INTEGER);
$stmt->bindValue(':operator_name', $operatorName, SQLITE3_TEXT);
$stmt->bindValue(':resolution_notes', $resolutionNotes, SQLITE3_TEXT);
$stmt->bindValue(':event_type', $eventType, SQLITE3_TEXT);
$result = $stmt->execute();
if ($result) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to save resolution']);
}