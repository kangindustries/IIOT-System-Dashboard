<?php
require_once __DIR__ . '/auth.php';
requireAuth();

$format = $_GET['format'] ?? 'csv';
$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$results = $db->query("SELECT timestamp, distance, angle, kf_innovation, fault_code FROM readings ORDER BY timestamp DESC");

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="data_history.json"');
    $data = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $row['timestamp'] = date('Y-m-d H:i:s', $row['timestamp']);
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="data_history.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Timestamp', 'Distance (cm)', 'Angle (deg)', 'KF Innovation (cm)', 'Fault Code']);

while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    fputcsv($output, [
        date('Y-m-d H:i:s', $row['timestamp']),
        $row['distance'],
        $row['angle'],
        $row['kf_innovation'],
        $row['fault_code']
    ]);
}
fclose($output);
exit;