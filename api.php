<?php
require_once __DIR__ . '/auth.php';
requireAuth();
header('Content-Type: application/json');

$latestRaw = file_get_contents(LATEST_JSON);
$latest = json_decode($latestRaw, true);
if (!is_array($latest)) {
    $latest = [];
}

$pendingFile = __DIR__ . '/data/pending_alert.json';
$latest['pending_alert'] = null;
if (file_exists($pendingFile)) {
    $pending = json_decode(file_get_contents($pendingFile), true);
    if (is_array($pending) && !($pending['cancelled'] ?? false) && !($pending['sent'] ?? false)) {
        $latest['pending_alert'] = [
            'fault_code' => $pending['fault_code'],
            'triggered_at' => $pending['triggered_at'],
            'seconds_remaining' => max(0, (int) round(ALERT_DELAY_SECONDS - (time() - $pending['triggered_at']))),
        ];
    }
}

$latest['last_notification'] = null;
if (file_exists($pendingFile)) {
    $pendingCheck = json_decode(file_get_contents($pendingFile), true);
    if (is_array($pendingCheck) && ($pendingCheck['sent'] ?? false)) {
        $latest['last_notification'] = [
            'fault_timestamp' => $pendingCheck['triggered_at'],
            'fault_code' => $pendingCheck['fault_code'],
        ];
    }
}

$pendingServiceFile = __DIR__ . '/data/pending_service_alert.json';
$latest['pending_service_alert'] = null;
if (file_exists($pendingServiceFile)) {
    $pendingService = json_decode(file_get_contents($pendingServiceFile), true);
    if (is_array($pendingService) && !($pendingService['cancelled'] ?? false) && !($pendingService['sent'] ?? false)) {
        $latest['pending_service_alert'] = [
            'cycle_count' => $pendingService['cycle_count'],
            'triggered_at' => $pendingService['triggered_at'],
            'seconds_remaining' => max(0, (int) round(ALERT_DELAY_SECONDS - (time() - $pendingService['triggered_at']))),
        ];
    }
}

$latest['last_service_notification'] = null;
if (file_exists($pendingServiceFile)) {
    $pendingServiceCheck = json_decode(file_get_contents($pendingServiceFile), true);
    if (is_array($pendingServiceCheck) && ($pendingServiceCheck['sent'] ?? false)) {
        $latest['last_service_notification'] = [
            'triggered_at' => $pendingServiceCheck['triggered_at'],
            'cycle_count' => $pendingServiceCheck['cycle_count'],
        ];
    }
}

$pendingPredictiveFile = __DIR__ . '/data/pending_predictive_alert.json';
$latest['pending_predictive_alert'] = null;
if (file_exists($pendingPredictiveFile)) {
    $pendingPredictive = json_decode(file_get_contents($pendingPredictiveFile), true);
    if (is_array($pendingPredictive) && !($pendingPredictive['cancelled'] ?? false) && !($pendingPredictive['sent'] ?? false)) {
        $latest['pending_predictive_alert'] = [
            'kf_innovation' => $pendingPredictive['kf_innovation'],
            'triggered_at' => $pendingPredictive['triggered_at'],
            'seconds_remaining' => max(0, (int) round(ALERT_DELAY_SECONDS - (time() - $pendingPredictive['triggered_at']))),
        ];
    }
}

$latest['last_predictive_notification'] = null;
if (file_exists($pendingPredictiveFile)) {
    $pendingPredictiveCheck = json_decode(file_get_contents($pendingPredictiveFile), true);
    if (is_array($pendingPredictiveCheck) && ($pendingPredictiveCheck['sent'] ?? false)) {
        $latest['last_predictive_notification'] = [
            'triggered_at' => $pendingPredictiveCheck['triggered_at'],
        ];
    }
}

echo json_encode($latest);