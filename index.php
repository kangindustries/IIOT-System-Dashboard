<?php
require_once __DIR__ . '/auth.php';
requireAuth();
$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$db->exec("CREATE TABLE IF NOT EXISTS fault_resolutions (id INTEGER PRIMARY KEY AUTOINCREMENT, fault_timestamp INTEGER UNIQUE, resolved_timestamp INTEGER, operator_name TEXT, resolution_notes TEXT, event_type TEXT DEFAULT 'fault')");
$latest = json_decode(file_get_contents(LATEST_JSON), true);
$faultCount = $db->querySingle("
    WITH fault_lagged AS (
        SELECT timestamp, fault_code, LAG(fault_code) OVER (ORDER BY timestamp) AS prev_code
        FROM readings
    ),
    service_lagged AS (
        SELECT timestamp, service_due, LAG(service_due) OVER (ORDER BY timestamp) AS prev_service
        FROM readings
    ),
    predictive_lagged AS (
        SELECT timestamp, predictive_alert, LAG(predictive_alert) OVER (ORDER BY timestamp) AS prev_predictive
        FROM readings
    ),
    fault_events AS (
        SELECT timestamp FROM fault_lagged
        WHERE fault_code != 0 AND (prev_code IS NULL OR prev_code != fault_code)
    ),
    service_events AS (
        SELECT timestamp FROM service_lagged
        WHERE service_due = 1 AND (prev_service IS NULL OR prev_service != service_due)
    ),
    predictive_events AS (
        SELECT timestamp FROM predictive_lagged
        WHERE predictive_alert = 1 AND (prev_predictive IS NULL OR prev_predictive != predictive_alert)
    )
    SELECT COUNT(*) FROM (
        SELECT timestamp FROM fault_events
        UNION ALL
        SELECT timestamp FROM service_events
        UNION ALL
        SELECT timestamp FROM predictive_events
    )
");
$recentFaults = $db->query("
    WITH fault_lagged AS (
        SELECT timestamp, fault_code, LAG(fault_code) OVER (ORDER BY timestamp) AS prev_code
        FROM readings
    ),
    service_lagged AS (
        SELECT timestamp, service_due, LAG(service_due) OVER (ORDER BY timestamp) AS prev_service
        FROM readings
    ),
    predictive_lagged AS (
        SELECT timestamp, predictive_alert, LAG(predictive_alert) OVER (ORDER BY timestamp) AS prev_predictive
        FROM readings
    ),
    fault_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, fault_code AS event_code, 'fault' AS event_type
        FROM fault_lagged
        WHERE fault_code != 0 AND (prev_code IS NULL OR prev_code != fault_code)
    ),
    service_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, -1 AS event_code, 'service' AS event_type
        FROM service_lagged
        WHERE service_due = 1 AND (prev_service IS NULL OR prev_service != service_due)
    ),
    predictive_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, -2 AS event_code, 'predictive' AS event_type
        FROM predictive_lagged
        WHERE predictive_alert = 1 AND (prev_predictive IS NULL OR prev_predictive != predictive_alert)
    ),
    all_events AS (
        SELECT * FROM fault_events
        UNION ALL
        SELECT * FROM service_events
        UNION ALL
        SELECT * FROM predictive_events
    )
    SELECT e.timestamp, e.event_code, e.event_type, r.resolved_timestamp, r.operator_name, r.resolution_notes
    FROM all_events e
    LEFT JOIN fault_resolutions r ON e.timestamp = r.fault_timestamp AND e.event_type = r.event_type
    ORDER BY e.timestamp DESC
    LIMIT 10
");
$calendarFaults = [];
$allFaultsQuery = $db->query("
    WITH fault_lagged AS (
        SELECT timestamp, fault_code, LAG(fault_code) OVER (ORDER BY timestamp) AS prev_code
        FROM readings
    ),
    service_lagged AS (
        SELECT timestamp, service_due, LAG(service_due) OVER (ORDER BY timestamp) AS prev_service
        FROM readings
    ),
    predictive_lagged AS (
        SELECT timestamp, predictive_alert, LAG(predictive_alert) OVER (ORDER BY timestamp) AS prev_predictive
        FROM readings
    ),
    fault_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, fault_code AS event_code, 'fault' AS event_type
        FROM fault_lagged
        WHERE fault_code != 0 AND (prev_code IS NULL OR prev_code != fault_code)
    ),
    service_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, -1 AS event_code, 'service' AS event_type
        FROM service_lagged
        WHERE service_due = 1 AND (prev_service IS NULL OR prev_service != service_due)
    ),
    predictive_events AS (
        SELECT CAST(timestamp AS INTEGER) AS timestamp, -2 AS event_code, 'predictive' AS event_type
        FROM predictive_lagged
        WHERE predictive_alert = 1 AND (prev_predictive IS NULL OR prev_predictive != predictive_alert)
    ),
    all_events AS (
        SELECT * FROM fault_events
        UNION ALL
        SELECT * FROM service_events
        UNION ALL
        SELECT * FROM predictive_events
    )
    SELECT e.timestamp, e.event_code, e.event_type, r.resolved_timestamp, r.operator_name, r.resolution_notes
    FROM all_events e
    LEFT JOIN fault_resolutions r ON e.timestamp = r.fault_timestamp AND e.event_type = r.event_type
    ORDER BY e.timestamp DESC
    LIMIT 100
");
while ($row = $allFaultsQuery->fetchArray(SQLITE3_ASSOC)) {
    $dateKey = date('Y-m-d', $row['timestamp']);
    $calendarFaults[$dateKey][] = [
        'timestamp' => $row['timestamp'],
        'fault_code' => $row['event_code'],
        'event_type' => $row['event_type'],
        'resolved_timestamp' => $row['resolved_timestamp'],
        'operator_name' => $row['operator_name'] ?? '',
        'resolution_notes' => $row['resolution_notes'] ?? ''
    ];
}
$fftFile = FFT_JSON;
$fftData = file_exists($fftFile) ? json_decode(file_get_contents($fftFile), true) : null;
$faultActive = $latest['fault_latched'] ?? false;
$alertActive = $latest['predictive_alert'] ?? false;
$serviceDueActive = $latest['service_due'] ?? false;
$faultLabels = [
    0 => 'No fault',
    1 => 'Sensor out of range',
    2 => 'Servo arm jammed',
];
$currentFaultLabel = $faultLabels[$latest['fault_code'] ?? 0] ?? 'Unknown fault';
$predictiveActive = $latest['predictive_alert'] ?? false;
$hardware = [
    [
        'name' => 'ESP32-S3-12K',
        'role' => 'Microcontroller running Modbus TCP slave firmware',
        'color' => 'var(--blue)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="10" height="10" rx="1.5"/><path d="M9 1v2M9 13v2M1 9h2M13 9h2"/></svg>',
        'links' => [
            ['label' => 'Wiki', 'url' => 'https://www.waveshare.com/wiki/NodeMCU-ESP-S3-12K-Kit'],
            ['label' => 'Schematic', 'url' => 'https://www.waveshare.com/wiki/File:Esp-s3-12k_module_schematic_v1.0_1_.pdf']
        ]
    ],
    [
        'name' => 'HC-SR04',
        'role' => 'Ultrasonic sensor, measures distance to the rack',
        'color' => 'var(--orange)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v12M5 5l3-3 3 3M5 11l3 3 3-3"/></svg>',
        'links' => [
            ['label' => 'HCSR04 Tutorial', 'url' => 'https://howtomechatronics.com/tutorials/arduino/ultrasonic-sensor-hc-sr04/']
        ]
    ],
    [
        'name' => 'SG92R Micro Servo',
        'role' => 'Drives the rack-and-pinion mechanism',
        'color' => 'var(--purple)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2.5"/></svg>',
        'links' => []
    ],
    [
        'name' => 'Rack-and-Pinion Assembly',
        'role' => 'Mechanism that converts rotation to linear motion',
        'color' => 'var(--indigo)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14V4M3 14h9"/><path d="M6.5 11c0-2.5 2-4.5 4.5-4.5"/></svg>',
        'links' => []
    ],
    [
        'name' => 'Voltage Divider (3x 1kΩ)',
        'role' => 'Steps down ECHO signal from 5V to 3.3V',
        'color' => 'var(--teal)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 8q2.5-5 5 0t5 0t4 0"/></svg>',
        'links' => [
            ['label' => 'Resistor Basics', 'url' => 'https://www.eoas.ubc.ca/courses/atsc303/Labs/2020/circuits_lab/circuits_data/circuits_lab_breadboard.pdf']
        ]
    ],
    [
        'name' => 'Breadboard',
        'role' => 'Platform connecting all components',
        'color' => 'var(--green)',
        'icon' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="1.5" width="13" height="13" rx="1.5"/><path d="M4 4h8M4 7h8M4 10h8v2H4z"/></svg>',
        'links' => [
            ['label' => 'Breadboard Basics (Instructables)', 'url' => 'https://www.instructables.com/Breadboard-Basics-for-Absolute-Begginers/'],
            ['label' => 'Breadboard Basics (Sparkfun)', 'url' => 'https://learn.sparkfun.com/tutorials/how-to-use-a-breadboard/all']
        ]
    ]
];
$historyStmt = $db->query("SELECT timestamp, distance, angle, kf_innovation FROM readings ORDER BY timestamp DESC LIMIT 30");
$history = [];
while ($r = $historyStmt->fetchArray(SQLITE3_ASSOC)) {
    $history[] = $r;
}
$history = array_reverse($history);
$histLabels = array_map(function ($h) {
    return date('H:i:s', intval($h['timestamp']));
}, $history);
$histDistance = array_column($history, 'distance');
$histAngle = array_column($history, 'angle');
$histKf = array_column($history, 'kf_innovation');
$faultRows = [];
while ($row = $recentFaults->fetchArray(SQLITE3_ASSOC)) {
    $faultRows[] = $row;
}
$notificationLog = $db->query("SELECT fault_timestamp, fault_code, sent_timestamp, sent_to FROM notification_log ORDER BY sent_timestamp DESC LIMIT 20");
$notificationRows = [];
while ($row = $notificationLog->fetchArray(SQLITE3_ASSOC)) {
    $notificationRows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-size="md">

<head>
    <meta charset="UTF-8">
    <title>Maintenance Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="IIoT Servo System — real-time telemetry and fault monitoring.">
    <script>
        (function () {
            var t = localStorage.getItem('dashboard-theme') || 'light';
            var s = localStorage.getItem('dashboard-textsize') || 'md';
            var b = localStorage.getItem('dashboard-bold') || 'off';
            document.documentElement.dataset.theme = t;
            document.documentElement.dataset.size = s;
            document.documentElement.dataset.bold = b;
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <script>
        var centerTextPlugin = {
            id: 'centerText',
            afterDraw: function (chart) {
                if (chart.canvas.id !== 'faultDoughnutChart') return;
                var total = chart.data.datasets[0].data.reduce(function (a, b) { return a + b; }, 0);
                var ctx = chart.ctx;
                var area = chart.chartArea;
                var cx = (area.left + area.right) / 2;
                var cy = (area.top + area.bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '600 22px -apple-system, sans-serif';
                ctx.fillStyle = (typeof C !== 'undefined' && C.ink) || '#1c1c1e';
                ctx.fillText(total, cx, cy - 8);
                ctx.font = '500 10px -apple-system, sans-serif';
                ctx.fillStyle = (typeof C !== 'undefined' && C.axis) || '#8e8e93';
                ctx.fillText('faults', cx, cy + 10);
                ctx.restore();
            }
        };
        Chart.register(centerTextPlugin);
        Chart.register(ChartDataLabels);
    </script>
    <style>
        :root {
            --bg: #f2f2f7;
            --card: #ffffff;
            --sep-inset: rgba(60, 60, 67, 0.12);
            --ink: #1c1c1e;
            --ink-2: #3a3a3c;
            --ink-3: #8e8e93;
            --blue: #007AFF;
            --orange: #FF9500;
            --purple: #AF52DE;
            --teal: #5AC8FA;
            --indigo: #5856D6;
            --red: #FF3B30;
            --green: #34C759;
            --r: 12px;
            --banner-ok-bg: rgba(52, 199, 89, 0.1);
            --banner-ok-ink: #248a3d;
            --banner-fault-bg: rgba(255, 59, 48, 0.1);
            --banner-fault-ink: #d70015;
            --tag-bg: rgba(255, 204, 0, 0.18);
            --tag-ink: #946800;
        }

        html[data-theme="dark"] {
            --bg: #000000;
            --card: #1c1c1e;
            --sep-inset: rgba(255, 255, 255, 0.08);
            --ink: #f2f2f7;
            --ink-2: #d1d1d6;
            --ink-3: #98989f;
            --blue: #0A84FF;
            --orange: #FF9F0A;
            --purple: #BF5AF2;
            --teal: #64D2FF;
            --indigo: #5E5CE6;
            --red: #FF453A;
            --green: #30D158;
            --banner-ok-bg: rgba(48, 209, 88, 0.15);
            --banner-ok-ink: #30D158;
            --banner-fault-bg: rgba(255, 69, 58, 0.15);
            --banner-fault-ink: #FF453A;
            --tag-bg: rgba(255, 214, 10, 0.15);
            --tag-ink: #FFD60A;
        }

        html[data-size="sm"] .page-title {
            font-size: 28px;
        }

        html[data-size="sm"] .section-title {
            font-size: 18px;
        }

        html[data-size="sm"] .stat-num {
            font-size: 28px;
        }

        html[data-size="sm"] .stat-label {
            font-size: 11px;
        }

        html[data-size="sm"] .stat-unit {
            font-size: 13px;
        }

        html[data-size="sm"] .banner {
            font-size: 13px;
        }

        html[data-size="sm"] .card-title {
            font-size: 15px;
        }

        html[data-size="sm"] .list-label,
        html[data-size="sm"] .list-value,
        html[data-size="sm"] .fault-time {
            font-size: 13px;
        }

        html[data-size="lg"] .page-title {
            font-size: 40px;
        }

        html[data-size="lg"] .section-title {
            font-size: 26px;
        }

        html[data-size="lg"] .stat-num {
            font-size: 40px;
        }

        html[data-size="lg"] .stat-label {
            font-size: 15px;
        }

        html[data-size="lg"] .stat-unit {
            font-size: 18px;
        }

        html[data-size="lg"] .banner {
            font-size: 17px;
        }

        html[data-size="lg"] .card-title {
            font-size: 19px;
        }

        html[data-size="lg"] .list-label,
        html[data-size="lg"] .list-value,
        html[data-size="lg"] .fault-time {
            font-size: 17px;
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                transition-duration: 0.001ms !important;
                animation-duration: 0.001ms !important;
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
            line-height: 1.47;
        }

        .wrap {
            max-width: 980px;
            margin: 0 auto;
            padding: 24px 20px 80px;
        }

        .skip-link {
            position: absolute;
            top: -50px;
            left: 16px;
            padding: 10px 18px;
            background: var(--blue);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            border-radius: 0 0 10px 10px;
            z-index: 1000;
            text-decoration: none;
            transition: top 0.2s;
        }

        .skip-link:focus {
            top: 0;
        }

        :focus-visible {
            outline: 3px solid var(--blue);
            outline-offset: 2px;
            border-radius: 6px;
        }

        :focus:not(:focus-visible) {
            outline: none;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--card);
            border-radius: var(--r);
            padding: 10px 14px;
            margin-bottom: 24px;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toolbar-right {
            gap: 10px;
        }

        .tool-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--ink-2);
            cursor: pointer;
            transition: opacity 0.15s;
        }

        .tool-btn:hover {
            opacity: 0.7;
        }

        .tool-btn svg {
            pointer-events: none;
        }

        .size-group {
            display: flex;
            border-radius: 8px;
            overflow: hidden;
            gap: 1px;
        }

        .toolbar-controls {
            display: flex;
            align-items: center;
            gap: 2px;
            background: var(--bg);
            border-radius: 8px;
            padding: 3px;
        }

        .size-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: none;
            background: transparent;
            color: var(--ink-3);
            cursor: pointer;
            font-family: inherit;
            font-weight: 700;
            border-radius: 7px;
            transition: all 0.15s;
        }

        .size-btn[data-size="sm"] {
            font-size: 11px;
        }

        .size-btn[data-size="md"] {
            font-size: 14px;
        }

        .size-btn[data-size="lg"] {
            font-size: 18px;
        }

        .size-btn.active {
            background: var(--blue);
            color: #fff;
        }

        .toolbar-div {
            width: 1px;
            height: 20px;
            background: var(--sep-inset);
            flex-shrink: 0;
        }

        .toolbar-user {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toolbar-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--blue);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toolbar-username {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }

        .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 500;
            font-family: inherit;
            color: var(--ink-2);
            border: 1px solid var(--sep-inset);
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.15s;
        }

        .logout-btn:hover {
            opacity: 1;
            background: var(--bg);
        }

        .logout-btn svg {
            pointer-events: none;
        }

        .page-header {
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 0.01em;
            line-height: 1.15;
        }

        .page-sub {
            font-size: 15px;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .info-row-group {
            background: transparent;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            align-items: center;
            padding: 12px 4px;
            border-bottom: 0.5px solid var(--sep-inset);
            font-size: 15px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-row-label {
            color: var(--ink);
        }

        .info-row-value {
            margin-left: auto;
            color: var(--ink-3);
        }

        .info-row-link {
            cursor: pointer;
        }

        .info-row-link-text {
            color: var(--blue);
            text-decoration: none;
            font-size: 15px;
        }

        .info-row-chevron {
            margin-left: auto;
            color: var(--ink-3);
            flex-shrink: 0;
        }

        .banner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: var(--r);
            margin-bottom: 24px;
            font-size: 15px;
            font-weight: 600;
        }

        .banner.ok {
            background: var(--banner-ok-bg);
            color: var(--banner-ok-ink);
        }

        .banner.fault {
            background: var(--banner-fault-bg);
            color: var(--banner-fault-ink);
        }

        .banner.banner-pending {
            background: rgba(255, 204, 0, 0.15);
            color: #946800;
        }

        html[data-theme="dark"] .banner.banner-pending {
            background: rgba(255, 214, 10, 0.15);
            color: #FFD60A;
        }

        .banner.banner-pending-service {
            background: rgba(255, 149, 0, 0.12);
            color: #b25400;
        }

        html[data-theme="dark"] .banner.banner-pending-service {
            background: rgba(255, 159, 10, 0.15);
            color: #FF9F0A;
        }

        #pendingAlertText,
        #pendingServiceAlertText,
        #pendingPredictiveAlertText {
            color: var(--ink);
        }

        .banner svg {
            flex-shrink: 0;
        }

        .banner .tag {
            font-size: 13px;
            font-weight: 600;
            color: var(--tag-ink);
            background: var(--tag-bg);
            padding: 3px 10px;
            border-radius: 999px;
            margin-left: auto;
        }

        .pending-tag {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 4px 8px;
            border-radius: 6px;
            flex-shrink: 0;
        }

        .pending-tag-fault {
            color: #d70015;
            background: rgba(255, 59, 48, 0.1);
        }

        html[data-theme="dark"] .pending-tag-fault {
            color: #FF453A;
            background: rgba(255, 69, 58, 0.15);
        }

        .pending-tag-service {
            color: #b25400;
            background: rgba(255, 149, 0, 0.12);
        }

        html[data-theme="dark"] .pending-tag-service {
            color: #FF9F0A;
            background: rgba(255, 159, 10, 0.15);
        }

        .pending-tag-predictive {
            color: var(--indigo);
            background: rgba(88, 86, 214, 0.1);
        }

        .status-line {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 12px 4px;
            margin-bottom: 20px;
            font-size: 15px;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .status-dot-ok {
            background: var(--ink-3);
        }

        .status-dot-danger {
            background: var(--red);
        }

        .status-text {
            color: var(--ink);
        }

        .status-tag {
            font-size: 13px;
            font-weight: 500;
            padding: 2px 10px;
            border-radius: 999px;
        }

        .status-tag-warning {
            color: #b25400;
            background: rgba(255, 149, 0, 0.1);
        }

        html[data-theme="dark"] .status-tag-warning {
            color: #FF9F0A;
            background: rgba(255, 159, 10, 0.15);
        }

        .status-link {
            color: var(--blue);
            font-size: 14px;
            text-decoration: none;
        }

        .device-header-card {
            background: var(--card);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .device-header-top {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 16px;
            border-bottom: 0.5px solid var(--sep-inset);
            margin-bottom: 16px;
        }

        .device-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(120, 120, 128, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ink);
            flex-shrink: 0;
        }

        .device-header-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
        }

        .device-header-ip {
            font-size: 13px;
            color: var(--ink-3);
            font-family: 'SF Mono', Menlo, Consolas, monospace;
        }

        .connection-pill {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--green);
            background: rgba(52, 199, 89, 0.12);
            padding: 6px 14px;
            border-radius: 999px;
            flex-shrink: 0;
        }

        .connection-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
        }

        .status-header-row {
            display: flex;
            gap: 32px;
            padding-bottom: 16px;
            border-bottom: 0.5px solid var(--sep-inset);
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .status-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .status-item-label {
            font-size: 12px;
            color: var(--ink-3);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .status-item-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
        }

        .status-value-danger {
            color: var(--red);
        }

        .status-value-warning {
            color: #b25400;
        }

        html[data-theme="dark"] .status-value-warning {
            color: #FF9F0A;
        }

        .status-detail-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .status-detail {
            flex: 1;
            min-width: 220px;
            border-radius: 14px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .status-detail-danger {
            background: rgba(255, 59, 48, 0.1);
            color: var(--red);
        }

        .status-detail-warning {
            background: rgba(255, 149, 0, 0.12);
            color: #b25400;
        }

        html[data-theme="dark"] .status-detail-warning {
            color: #FF9F0A;
        }

        .status-detail svg {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .status-detail-title {
            font-size: 14px;
            font-weight: 600;
        }

        .status-detail-time {
            font-size: 13px;
            margin-top: 2px;
            opacity: 0.85;
        }

        .status-detail-predictive {
            background: rgba(88, 86, 214, 0.1);
            color: var(--indigo);
        }

        html[data-theme="dark"] .status-detail-predictive {
            color: var(--indigo);
        }

        .status-detail-sub {
            font-size: 13px;
            margin-top: 2px;
            opacity: 0.85;
        }

        .device-header-links {
            display: flex;
            gap: 20px;
            position: relative;
        }

        .link-arrow {
            font-size: 13px;
            font-weight: 500;
            color: var(--blue);
            text-decoration: none;
            cursor: pointer;
        }

        .section {
            margin-bottom: 8px;
        }

        .section-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            padding: 10px 4px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            color: inherit;
            -webkit-tap-highlight-color: transparent;
        }

        .section-toggle:focus-visible {
            border-radius: 8px;
        }

        .section-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin: 0;
            text-align: left;
        }

        .section-chevron {
            transition: transform 0.2s ease;
            color: var(--ink-3);
            flex-shrink: 0;
        }

        .section.collapsed .section-chevron {
            transform: rotate(-90deg);
        }

        .section-content {
            display: grid;
            grid-template-rows: 1fr;
            transition: grid-template-rows 0.3s ease;
        }

        .section.collapsed .section-content {
            grid-template-rows: 0fr;
        }

        .section-inner {
            overflow: hidden;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding-bottom: 24px;
        }

        @media (max-width: 780px) {
            .grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 440px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding-bottom: 24px;
        }

        @media (max-width: 500px) {
            .metric-grid {
                grid-template-columns: 1fr;
            }
        }

        .metric-tile {
            display: block;
            background: rgba(120, 120, 128, 0.06);
            border-radius: 16px;
            padding: 16px;
            text-decoration: none;
            color: inherit;
            transition: opacity 0.15s;
        }

        html[data-theme="dark"] .metric-tile {
            background: rgba(255, 255, 255, 0.06);
        }

        .metric-tile:hover {
            opacity: 0.85;
        }

        .metric-tile-danger {
            background: rgba(255, 59, 48, 0.08);
        }

        .metric-tile-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--ink);
            margin-bottom: 4px;
        }

        .metric-tile-danger .metric-tile-label {
            color: var(--red);
        }

        .metric-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .metric-tile-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.02em;
            margin-top: 4px;
        }

        .metric-tile-danger .metric-tile-value {
            color: var(--red);
        }

        .metric-tile-unit {
            font-size: 15px;
            font-weight: 500;
            color: var(--ink-3);
            margin-left: 2px;
        }

        .metric-value-warn {
            color: var(--tag-ink);
        }

        .metric-tile-sub {
            font-size: 12px;
            color: var(--ink-3);
            margin-top: 2px;
        }

        .metric-tile-danger .metric-tile-sub {
            color: var(--red);
            opacity: 0.8;
        }

        .metric-spark {
            height: 32px;
            margin-top: 8px;
        }

        .metric-tile-warning {
            background: rgba(255, 149, 0, 0.1);
        }

        html[data-theme="dark"] .metric-tile-warning {
            background: rgba(255, 159, 10, 0.15);
        }

        .metric-tile-warning .metric-tile-label {
            color: #b25400;
        }

        html[data-theme="dark"] .metric-tile-warning .metric-tile-label {
            color: #FF9F0A;
        }

        .metric-tile-warning .metric-tile-value {
            color: #b25400;
        }

        html[data-theme="dark"] .metric-tile-warning .metric-tile-value {
            color: #FF9F0A;
        }

        a.stat {
            text-decoration: none;
            color: inherit;
        }

        .stat {
            background: var(--card);
            border-radius: var(--r);
            padding: 16px 18px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            position: relative;
            cursor: pointer;
            transition: transform 0.12s, box-shadow 0.12s;
        }

        .stat:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }

        html[data-theme="dark"] .stat:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
        }

        *[data-tip] {
            position: relative;
        }

        *[data-tip]::after {
            content: attr(data-tip);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--ink);
            color: var(--bg);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.4;
            padding: 7px 11px;
            border-radius: 8px;
            white-space: normal;
            width: max-content;
            max-width: 250px;
            text-align: center;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 100;
        }

        *[data-tip]::before {
            content: '';
            position: absolute;
            bottom: calc(100% + 2px);
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: var(--ink);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 100;
        }

        *[data-tip]:hover::after,
        *[data-tip]:hover::before {
            opacity: 1;
        }

        .stat-label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            font-weight: 600;
            color: var(--c);
        }

        .stat-label svg {
            flex-shrink: 0;
        }

        .stat-body {
            display: flex;
            align-items: baseline;
            gap: 4px;
        }

        .stat-num {
            font-size: 34px;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.05;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        .stat-unit {
            font-size: 16px;
            font-weight: 500;
            color: var(--ink-3);
        }

        .stat-num.warn {
            color: var(--tag-ink);
        }

        .stat-num.danger {
            color: var(--red);
        }

        .stat-detail {
            font-size: 12px;
            color: var(--ink-3);
            font-weight: 400;
            margin-top: -2px;
        }

        .sparkline-wrap {
            height: 36px;
            margin-top: 2px;
        }

        @keyframes valFlash {
            from {
                opacity: 0.5
            }

            to {
                opacity: 1
            }
        }

        .val-flash {
            animation: valFlash 0.3s ease;
        }

        .card {
            background: var(--card);
            border-radius: var(--r);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 17px;
            font-weight: 600;
            padding: 16px 18px 0;
        }

        .card-title svg {
            color: var(--ink-3);
            flex-shrink: 0;
        }

        .card-title .card-count {
            margin-left: auto;
            font-size: 13px;
            font-weight: 400;
            color: var(--ink-3);
        }

        .card-subtitle {
            font-size: 13px;
            color: var(--ink-3);
            padding: 2px 18px 0;
            line-height: 1.5;
        }

        .chart-area {
            padding: 4px 10px 8px;
            height: 450px;
            position: relative;
        }

        .list-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 12px 18px;
            min-height: 44px;
        }

        .list-row+.list-row {
            border-top: 0.5px solid var(--sep-inset);
            margin-left: 18px;
            padding-left: 0;
        }

        .list-label {
            font-size: 15px;
            color: var(--ink);
            white-space: nowrap;
        }

        .list-value {
            font-size: 15px;
            color: var(--ink-3);
            text-align: right;
            line-height: 1.35;
        }

        .fault-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            min-height: 44px;
        }

        .fault-row+.fault-row {
            border-top: 0.5px solid var(--sep-inset);
            margin-left: 18px;
            padding-left: 0;
        }

        .fault-time {
            font-size: 15px;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        .fault-badge {
            font-size: 13px;
            font-weight: 500;
            color: var(--red);
            background: var(--banner-fault-bg);
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .fault-badge-service {
            color: #b25400;
            background: rgba(255, 149, 0, 0.12);
        }

        html[data-theme="dark"] .fault-badge-service {
            color: #FF9F0A;
            background: rgba(255, 159, 10, 0.15);
        }

        .fault-badge-predictive {
            color: var(--indigo);
            background: rgba(88, 86, 214, 0.1);
        }

        .empty-state {
            padding: 32px 18px;
            text-align: center;
            font-size: 15px;
            color: var(--ink-3);
        }

        canvas {
            width: 100% !important;
        }

        @media (max-width: 600px) {
            .toolbar {
                flex-wrap: wrap;
            }

            .toolbar-left,
            .toolbar-right {
                width: 100%;
                justify-content: space-between;
            }
        }

        .device-mini-btn {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 500;
            color: var(--blue);
            background: rgba(0, 122, 255, 0.08);
            border-radius: 6px;
            text-decoration: none;
            transition: opacity 0.15s;
            white-space: nowrap;
        }

        html[data-theme="dark"] .device-mini-btn {
            background: rgba(10, 132, 255, 0.12);
        }

        .device-mini-btn.neutral {
            background: rgba(0, 0, 0, 0.06);
            border: 1px solid var(--sep-inset);
            color: var(--ink-2);
        }

        html[data-theme="dark"] .device-mini-btn.neutral {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--sep-inset);
        }

        .toggle-btn-active {
            background: var(--blue) !important;
            color: #fff !important;
            border: 1px solid var(--blue) !important;
        }

        .toggle-btn-active svg {
            color: #fff !important;
        }

        .device-mini-btn:hover {
            opacity: 0.75;
        }

        .device-mini-btn-outline {
            background: transparent !important;
            border: 1px solid var(--sep-inset) !important;
            color: var(--ink-2) !important;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        html[data-theme="dark"] .device-mini-btn-outline {
            border-color: var(--sep-inset) !important;
        }

        html[data-bold="on"] body {
            font-weight: 500;
        }

        html[data-bold="on"] .stat-num {
            font-weight: 800;
        }

        html[data-bold="on"] .section-title {
            font-weight: 800;
        }

        html[data-bold="on"] .page-title {
            font-weight: 800;
        }

        html[data-bold="on"] .card-title {
            font-weight: 700;
        }

        html[data-bold="on"] .list-label {
            font-weight: 600;
        }

        .trends-legend {
            display: flex;
            gap: 16px;
            padding: 4px 18px 12px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--ink-2);
            background: none;
            border: none;
            font-family: inherit;
            cursor: pointer;
            padding: 4px 8px;
            margin-left: -8px;
            border-radius: 6px;
            transition: opacity 0.15s ease, background 0.15s ease;
            user-select: none;
        }

        .legend-item:hover {
            background: rgba(0, 0, 0, 0.05);
        }

        html[data-theme="dark"] .legend-item:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .trends-legend .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .trends-legend .dot.blue {
            background-color: var(--blue);
        }

        .trends-legend .dot.orange {
            background-color: var(--orange);
        }

        .trends-legend .dot.indigo {
            background-color: var(--indigo);
        }

        .hw-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            min-height: 56px;
        }

        .hw-row+.hw-row {
            border-top: 0.5px solid var(--sep-inset);
            margin-left: 18px;
            padding-left: 0;
        }

        .hw-icon-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            color: #fff;
            background: var(--c);
            flex-shrink: 0;
        }

        .hw-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-grow: 1;
        }

        .hw-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--ink);
        }

        .hw-desc {
            font-size: 13px;
            color: var(--ink-3);
            line-height: 1.3;
        }

        .hw-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .hw-link {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            color: #1d1d1f;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
            text-decoration: none;
            transition: opacity 0.15s;
        }

        html[data-theme="dark"] .hw-link {
            color: #f5f5f7;
            background: rgba(255, 255, 255, 0.1);
        }

        .hw-link:hover {
            opacity: 0.75;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10, 10, 10, 0.35);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        html[data-theme="dark"] .modal-overlay {
            background: rgba(0, 0, 0, 0.58);
        }

        .modal-card {
            background: var(--card);
            border: 1px solid rgba(60, 60, 67, 0.12);
            border-radius: 24px;
            padding: 22px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.65);
            animation: modalFadeIn 0.25s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        html[data-theme="dark"] .modal-card {
            border-color: rgba(255, 255, 255, 0.1);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(8px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
        }

        .modal-heading-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .modal-kicker {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--blue);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: var(--ink);
        }

        .modal-subtitle {
            font-size: 13px;
            line-height: 1.45;
            color: var(--ink-3);
        }

        .modal-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(120, 120, 128, 0.14);
            border: none;
            font-size: 20px;
            color: var(--ink-2);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .modal-close-btn:hover {
            background: rgba(120, 120, 128, 0.2);
            transform: scale(1.02);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink-3);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .form-input,
        .form-textarea {
            background: rgba(118, 118, 128, 0.12);
            border: 1px solid rgba(60, 60, 67, 0.12);
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 15px;
            font-family: inherit;
            color: var(--ink);
            width: 100%;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        html[data-theme="dark"] .form-input,
        html[data-theme="dark"] .form-textarea {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .form-input::placeholder,
        .form-textarea::placeholder {
            color: var(--ink-3);
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.12);
        }

        .form-textarea {
            min-height: 112px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .modal-primary-btn,
        .modal-secondary-btn {
            border: none;
            border-radius: 999px;
            padding: 10px 14px;
            min-height: 42px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .modal-primary-btn:hover,
        .modal-secondary-btn:hover {
            transform: translateY(-1px);
            opacity: 0.95;
        }

        .modal-primary-btn {
            background: var(--blue);
            color: #ffffff;
        }

        .modal-secondary-btn {
            background: rgba(120, 120, 128, 0.12);
            color: var(--ink-2);
        }

        html[data-theme="dark"] .modal-secondary-btn {
            background: rgba(255, 255, 255, 0.1);
            color: var(--ink-2);
        }

        .modal-card-details {
            max-width: 420px;
            padding: 24px;
        }

        .modal-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(52, 199, 89, 0.12);
            color: var(--green);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
            width: fit-content;
        }

        .details-stack {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .detail-row {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid rgba(60, 60, 67, 0.1);
        }

        .detail-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 122, 255, 0.1);
            color: var(--blue);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .detail-body {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .detail-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink-3);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 500;
            color: var(--ink);
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .detail-value-notes {
            color: var(--ink-2);
        }

        .modal-primary-full {
            width: 100%;
            margin-top: 24px;
        }

        .segmented-control {
            display: flex;
            background: rgba(120, 120, 128, 0.08);
            padding: 2px;
            border-radius: 8px;
            margin: 10px 18px 16px;
        }

        html[data-theme="dark"] .segmented-control {
            background: rgba(255, 255, 255, 0.1);
        }

        .segment {
            flex: 1;
            background: transparent;
            border: none;
            padding: 6px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--ink-3);
            cursor: pointer;
            transition: background 0.12s, color 0.12s;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }

        .segment.active {
            background: var(--card);
            color: var(--ink);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        html[data-theme="dark"] .segment.active {
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }

        .calendar-day {
            position: relative;
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--ink);
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .calendar-day:hover:not(.empty-day) {
            background: rgba(0, 0, 0, 0.04);
        }

        html[data-theme="dark"] .calendar-day:hover:not(.empty-day) {
            background: rgba(255, 255, 255, 0.06);
        }

        .calendar-day.empty-day {
            cursor: default;
        }

        .calendar-day.active-day {
            background: var(--blue) !important;
            color: #fff !important;
            font-weight: 600;
        }

        .calendar-day.active-day .day-dot {
            background: #fff !important;
        }

        .day-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            margin-top: 2px;
        }

        .disclosure-chevron {
            color: var(--ink-3);
            margin-left: auto;
            opacity: 0.5;
            transition: transform 0.12s ease, opacity 0.12s ease, color 0.12s ease;
            flex-shrink: 0;
        }

        .stat:hover .disclosure-chevron {
            transform: translateX(2px);
            opacity: 0.9;
            color: var(--c);
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            padding: 10px 18px 0;
        }

        @media (max-width: 600px) {
            .analytics-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }

        .analytics-chart-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            background: rgba(120, 120, 128, 0.04);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--sep-inset);
        }
    </style>
</head>

<body>
    <a href="#main-content" class="skip-link">Skip to content</a>
    <div class="wrap">
        <nav class="toolbar" aria-label="Dashboard controls">
            <div class="toolbar-left">
                <div class="toolbar-controls">
                    <button class="tool-btn" id="themeToggle" title="Toggle dark/light mode"
                        aria-label="Toggle dark or light mode">
                        <svg id="iconSun" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round">
                            <circle cx="8" cy="8" r="3" />
                            <path
                                d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" />
                        </svg>
                        <svg id="iconMoon" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round" style="display:none">
                            <path d="M13.5 9.2A5.5 5.5 0 1 1 6.8 2.5 4.5 4.5 0 0 0 13.5 9.2z" />
                        </svg>
                    </button>
                    <div class="toolbar-div" role="separator"></div>
                    <div class="size-group" role="radiogroup" aria-label="Text size">
                        <button class="size-btn" data-size="sm" role="radio" aria-label="Small text">A</button>
                        <button class="size-btn" data-size="md" role="radio" aria-label="Default text">A</button>
                        <button class="size-btn" data-size="lg" role="radio" aria-label="Large text">A</button>
                    </div>
                    <div class="toolbar-div" role="separator"></div>
                    <button class="tool-btn" id="boldToggle" title="Toggle bold text" aria-label="Toggle bold text"
                        aria-pressed="false">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round">
                            <path d="M4 8h5a2.5 2.5 0 0 0 0-5H4v5zm0 0h5.5a2.5 2.5 0 0 1 0 5H4V8z" />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="toolbar-right">
                <div class="toolbar-user">
                    <div class="toolbar-avatar" aria-hidden="true">
                        <?= strtoupper(substr($_SESSION['username'] ?? 'Admin', 0, 1)) ?>
                    </div>
                    <span class="toolbar-username">
                        <?= htmlspecialchars(ucfirst($_SESSION['username'] ?? 'Admin')) ?>
                    </span>
                </div>
                <div class="toolbar-div" role="separator"></div>
                <a href="logout.php" class="logout-btn" aria-label="Log Out">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3M11 11l3-3-3-3M14 8H6" />
                    </svg>
                    Log Out
                </a>
            </div>
        </nav>
        <header class="page-header">
            <h1 class="page-title">Monitoring Dashboard</h1>
            <p class="page-sub">IIoT Servo System</p>
        </header>
        <div class="device-header-card">
            <div class="device-header-top">
                <div class="device-icon-box">
                    <svg width="22" height="22" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="3" width="10" height="10" rx="1.5" />
                        <path d="M9 1v2M9 13v2M1 9h2M13 9h2" />
                    </svg>
                </div>
                <div class="device-header-info">
                    <div class="device-header-name"><?= ESP32_MODEL ?></div>
                    <div class="device-header-ip"><?= ESP32_IP ?></div>
                </div>
                <div class="connection-pill" id="connectionPill">
                    <span class="connection-dot" id="connectionDot"></span>
                    <span id="connectionStatusText">Online</span>
                </div>
            </div>

            <div class="status-header-row">
                <div class="status-item">
                    <span class="status-item-label">Last updated</span>
                    <span class="status-item-value" id="lastUpdated">Updated just now</span>
                    <span style="font-size: 11px; color: var(--ink-3); margin-top: 2px;" id="userTimezoneLabel"></span>
                </div>
                <div class="status-item">
                    <span class="status-item-label">Servo status</span>
                    <span class="status-item-value <?= $faultActive ? 'status-value-danger' : '' ?>" id="bannerText">
                        <?= $faultActive ? 'Fault active' : 'Normal' ?>
                    </span>
                </div>
                <div class="status-item">
                    <span class="status-item-label">Maintenance</span>
                    <span class="status-item-value <?= $serviceDueActive ? 'status-value-warning' : '' ?>"
                        id="serviceStatusText">
                        <?= $serviceDueActive ? 'Service due' : 'Up to date' ?>
                    </span>
                </div>
            </div>

            <div class="status-detail-row" id="statusDetailRow">
                <?php
                $faultDetailTs = null;
                foreach ($faultRows as $fr) {
                    if ($fr['event_type'] === 'fault') {
                        $faultDetailTs = $fr['timestamp'];
                        break;
                    }
                }
                $serviceDetailTs = null;
                foreach ($faultRows as $fr) {
                    if ($fr['event_type'] === 'service') {
                        $serviceDetailTs = $fr['timestamp'];
                        break;
                    }
                }
                $predictiveDetailTs = null;
                foreach ($faultRows as $fr) {
                    if ($fr['event_type'] === 'predictive') {
                        $predictiveDetailTs = $fr['timestamp'];
                        break;
                    }
                }
                ?>
                <?php if ($faultActive): ?>
                    <div class="status-detail status-detail-danger" id="faultDetailBox">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 1.5l7 12.5H1L8 1.5z" />
                            <path d="M8 6v3.5M8 12v.01" />
                        </svg>
                        <div>
                            <div class="status-detail-title" id="faultDetailTitle">Code <?= $latest['fault_code'] ?>:
                                <?= htmlspecialchars($currentFaultLabel) ?>
                            </div>
                            <div class="status-detail-time" id="faultDetailTime">
                                <?= $faultDetailTs ? date('d M, H:i:s', $faultDetailTs) : '' ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($serviceDueActive): ?>
                    <div class="status-detail status-detail-warning" id="serviceDetailBox">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="8" cy="8" r="6" />
                            <path d="M8 4.5V8l2.5 2.5" />
                        </svg>
                        <div>
                            <div class="status-detail-title">Servicing due</div>
                            <div class="status-detail-time" id="serviceDetailTime">
                                <?= $serviceDetailTs ? date('d M, H:i:s', $serviceDetailTs) : '' ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($predictiveActive): ?>
                    <div class="status-detail status-detail-predictive" id="predictiveDetailBox">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="8" cy="8" r="6" />
                            <circle cx="8" cy="8" r="2" />
                            <path d="M8 2v2M8 12v2M2 8h2M12 8h2" />
                        </svg>
                        <div>
                            <div class="status-detail-title">Predictive alert</div>
                            <div class="status-detail-sub">EMA deviation exceeded threshold for 5 seconds</div>
                            <div class="status-detail-time" id="predictiveDetailTime">
                                <?= $predictiveDetailTs ? date('d M, H:i:s', $predictiveDetailTs) : '' ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="device-header-links">
                <a href="#section-hardware" class="link-arrow">View all components ›</a>
            </div>
        </div>
        <main id="main-content">
            <div id="pendingAlertBanner" class="banner banner-pending"
                style="display:none; align-items:center; background:var(--card);" role="alert" aria-live="assertive">
                <span class="pending-tag pending-tag-fault">Fault</span>
                <span id="pendingAlertText">A fault email alert will be sent in <strong><span
                            id="pendingAlertSeconds">60</span>s</strong></span>
                <button id="cancelAlertBtn" class="device-mini-btn"
                    style="margin-left:auto; background:transparent; border:1px solid var(--sep-inset); color:var(--ink-2); cursor:pointer; font-weight:500;">
                    Cancel Alert
                </button>
            </div>

            <div id="pendingServiceAlertBanner" class="banner banner-pending-service"
                style="display:none; align-items:center; background:var(--card);" role="alert" aria-live="assertive">
                <span class="pending-tag pending-tag-service">Servicing</span>
                <span id="pendingServiceAlertText">A servicing due email will be sent in <strong><span
                            id="pendingServiceAlertSeconds">60</span>s</strong></span>
                <button id="cancelServiceAlertBtn" class="device-mini-btn"
                    style="margin-left:auto; background:transparent; border:1px solid var(--sep-inset); color:var(--ink-2); cursor:pointer; font-weight:500;">
                    Cancel Alert
                </button>
            </div>

            <div id="pendingPredictiveAlertBanner" class="banner banner-pending-predictive"
                style="display:none; align-items:center; background:var(--card);" role="alert" aria-live="assertive">
                <span class="pending-tag pending-tag-predictive">Predictive</span>
                <span id="pendingPredictiveAlertText">A predictive alert email will be sent in <strong><span
                            id="pendingPredictiveAlertSeconds">60</span>s</strong></span>
                <button id="cancelPredictiveAlertBtn" class="device-mini-btn"
                    style="margin-left:auto; background:transparent; border:1px solid var(--sep-inset); color:var(--ink-2); cursor:pointer; font-weight:500;">
                    Cancel Alert
                </button>
            </div>

            <input type="hidden" id="csrfTokenGlobal" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <section class="section" data-section="readings">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-readings">
                    <h2 class="section-title">Readings</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-readings">
                    <div class="section-inner">
                        <div class="metric-grid">
                            <a href="detail.php?metric=distance" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--blue)"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M8 2v12M5 5l3-3 3 3M5 11l3 3 3-3" />
                                    </svg>
                                    Distance
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-distance"><?= $latest['distance'] ?? '—' ?></span>
                                    <span class="metric-tile-unit">cm</span>
                                </div>
                                <?php if (count($histDistance) > 1): ?>
                                    <div class="metric-spark"><canvas id="spark-distance" aria-hidden="true"></canvas></div>
                                <?php endif; ?>
                            </a>
                            <a href="detail.php?metric=angle" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--orange)"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M3 14V4M3 14h9" />
                                        <path d="M6.5 11c0-2.5 2-4.5 4.5-4.5" />
                                    </svg>
                                    Angle
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-angle"><?= $latest['angle'] ?? '—' ?></span>
                                    <span class="metric-tile-unit">°</span>
                                </div>
                                <?php if (count($histAngle) > 1): ?>
                                    <div class="metric-spark"><canvas id="spark-angle" aria-hidden="true"></canvas></div>
                                <?php endif; ?>
                            </a>
                            <a href="detail.php?metric=sweep_speed" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--purple)"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M2 12a6 6 0 1 1 12 0" />
                                        <path d="M8 12 5.5 7" />
                                    </svg>
                                    Sweep speed
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-speed"><?= $latest['sweep_speed'] ?? '—' ?></span>
                                    <span class="metric-tile-unit">ms</span>
                                </div>
                            </a>
                            <a href="detail.php?metric=cycle_count" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--green)"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M14 8A6 6 0 1 1 8 2" />
                                        <path d="M8 2h3M8 2v3" />
                                    </svg>
                                    Cycle count
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-cycles"><?= $latest['cycle_count'] ?? '—' ?></span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" data-section="diagnostics">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-diagnostics">
                    <h2 class="section-title">Diagnostics</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-diagnostics">
                    <div class="section-inner">
                        <div class="metric-grid">
                            <a href="detail.php?metric=ema_deviation" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--teal)"
                                        stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                                        <path d="M1 8q2.5-5 5 0t5 0t4 0" />
                                    </svg>
                                    EMA deviation
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-ema"><?= $latest['ema_deviation'] ?? '—' ?></span>
                                </div>
                            </a>
                            <a href="detail.php?metric=kf_innovation" class="metric-tile">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--indigo)"
                                        stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                                        <circle cx="8" cy="8" r="5" />
                                        <path d="M8 2v2M8 12v2M2 8h2M12 8h2" />
                                    </svg>
                                    KF innovation
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-kf"
                                        class="<?= ($latest['kf_innovation'] ?? 0) > 3 ? 'metric-value-warn' : '' ?>"><?= $latest['kf_innovation'] ?? '—' ?></span>
                                    <span class="metric-tile-unit">cm</span>
                                </div>
                                <?php if (count($histKf) > 1): ?>
                                    <div class="metric-spark"><canvas id="spark-kf" aria-hidden="true"></canvas></div>
                                <?php endif; ?>
                            </a>
                            <a href="detail.php?metric=fault_code"
                                class="metric-tile <?= ($latest['fault_code'] ?? 0) != 0 ? 'metric-tile-danger' : '' ?>">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                                        stroke="<?= ($latest['fault_code'] ?? 0) != 0 ? 'var(--red)' : 'var(--red)' ?>"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M8 2 14.5 13.5H1.5Z" />
                                        <path d="M8 7v3M8 12v.01" />
                                    </svg>
                                    Fault code
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-fault"><?= $latest['fault_code'] ?? '—' ?></span>
                                </div>
                                <div class="metric-tile-sub" id="val-fault-label">
                                    <?= htmlspecialchars($currentFaultLabel) ?>
                                </div>
                            </a>
                            <a href="detail.php?metric=service_due"
                                class="metric-tile <?= $serviceDueActive ? 'metric-tile-warning' : '' ?>">
                                <div class="metric-tile-label">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="var(--orange)"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <circle cx="8" cy="8" r="6" />
                                        <path d="M8 4.5V8l2.5 2.5" />
                                    </svg>
                                    Service due
                                </div>
                                <div class="metric-tile-value">
                                    <span id="val-service"><?= $serviceDueActive ? 'Yes' : 'No' ?></span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (count($history) > 1): ?>
                <section class="section" data-section="trends">
                    <button class="section-toggle" aria-expanded="true" aria-controls="content-trends">
                        <h2 class="section-title">Trends</h2>
                        <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M4 6l4 4 4-4" />
                        </svg>
                    </button>
                    <div class="section-content" id="content-trends">
                        <div class="section-inner">
                            <div class="card">
                                <div class="card-title" style="margin-bottom: 8px;">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                        aria-hidden="true">
                                        <path d="M2 12l4-4.5L8.5 10 14 3" />
                                        <path d="M10 3h4v4" />
                                    </svg>
                                    Recent History
                                    <span style="margin-left:auto;display:flex;gap:6px;">
                                        <button id="toggleAxesBtn" class="device-mini-btn toggle-btn-active"
                                            aria-pressed="true"
                                            style="border:none;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"
                                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                stroke-linejoin="round" aria-hidden="true">
                                                <path d="M2 2v11a1 1 0 0 0 1 1h11" />
                                                <path d="M5 10l3-3 2 2 3-4" />
                                            </svg>
                                            Multi-axis Charting
                                            <span id="toggleAxesSwitch"
                                                style="width:26px; height:15px; border-radius:999px; background:rgba(255,255,255,0.35); position:relative; display:inline-block; margin-left:2px; transition: background 0.15s;">
                                                <span id="toggleAxesDot"
                                                    style="width:11px; height:11px; border-radius:50%; background:#fff; position:absolute; top:2px; right:2px; transition: right 0.15s;"></span>
                                            </span>
                                        </button>
                                        <a href="export.php?format=csv"
                                            class="device-mini-btn neutral device-mini-btn-outline">
                                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none"
                                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                stroke-linejoin="round" aria-hidden="true">
                                                <path
                                                    d="M3 1.5h7L13 4.5v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1z" />
                                                <path d="M9.5 1.5V5h3.5" />
                                            </svg>
                                            Export CSV
                                        </a>
                                        <a href="export.php?format=json"
                                            class="device-mini-btn neutral device-mini-btn-outline">
                                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none"
                                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                stroke-linejoin="round" aria-hidden="true">
                                                <path
                                                    d="M3 1.5h7L13 4.5v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1z" />
                                                <path d="M9.5 1.5V5h3.5" />
                                            </svg>
                                            Export JSON
                                        </a>
                                    </span>
                                </div>
                                <div class="card-subtitle">Recent History shows the last <?= count($history) ?> Distance and
                                    Angle readings from the system, along with Kalman Filter values received from the
                                    firmware, displayed as a trending chart. Kalman filter values may not appear depending
                                    on the maintenance mode running.</div>
                                <div class="trends-legend" aria-hidden="true">
                                    <button class="legend-item" data-dataset="0"><span class="dot blue"></span>Distance
                                        (cm)</button>
                                    <button class="legend-item" data-dataset="1"><span class="dot orange"></span>Angle
                                        (°)</button>
                                    <button class="legend-item" data-dataset="2"><span class="dot indigo"></span>KF
                                        Innovation (cm)</button>
                                </div>
                                <div class="chart-area"><canvas id="trendsChart" height="220" role="img"
                                        aria-label="Line chart showing recent readings for distance, angle, and KF innovation"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
            <section class="section" data-section="spectrum">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-spectrum">
                    <h2 class="section-title">Spectrum Analysis</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-spectrum">
                    <div class="section-inner">
                        <div class="card">
                            <div class="card-title" style="margin-bottom: 12px;">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                    stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                                    <path d="M2.5 13V9.5M5.5 13V5M8.5 13V7.5M11.5 13V3.5M14.5 13V8" />
                                </svg>
                                FFT Spectrum
                            </div>
                            <div class="card-subtitle" style="margin-bottom: 10px;">The FFT Spectrum chart shows the
                                frequency content of the servo's vibration signal. A healthy system produces a
                                consistent set of frequency peaks. If the frequency signature changes, new peaks will
                                appear or existing peaks will shift, indicating that a fault may be occuring.</div>
                            <?php if ($fftData): ?>
                                <div class="chart-area"><canvas id="fftChart" height="240" role="img"
                                        aria-label="FFT frequency spectrum chart"></canvas></div>
                            <?php else: ?>
                                <div class="empty-state">No FFT data captured.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" data-section="faults" id="section-faults">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-faults">
                    <h2 class="section-title">Fault &amp; Service History</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-faults">
                    <div class="section-inner">
                        <div class="card">
                            <div class="card-title">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <rect x="3" y="1.5" width="10" height="13" rx="1.5" />
                                    <path d="M6 5.5h4M6 8.5h4M6 11.5h2.5" />
                                </svg>
                                Recent Activity
                                <span class="card-count" id="faultCountHeader">
                                    <?= $faultCount ?> total
                                </span>
                            </div>
                            <div class="card-subtitle">Recent Activity shows a summary of all faults and maintenance
                                activities that have occurred recently, and details about how the default was resolved.
                            </div>
                            <div class="segmented-control" role="tablist">
                                <button class="segment active" role="tab" aria-selected="true"
                                    data-view="list">List</button>
                                <button class="segment" role="tab" aria-selected="false"
                                    data-view="calendar">Calendar</button>
                            </div>
                            <div id="faultListContainer">
                                <?php if (count($faultRows) > 0): ?>
                                    <?php foreach ($faultRows as $row): ?>
                                        <div class="fault-row">
                                            <div class="fault-time">
                                                <?= date('d F Y H:i:s', $row['timestamp']) ?>
                                            </div>
                                            <div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">
                                                <div
                                                    class="fault-badge <?= $row['event_code'] == -1 ? 'fault-badge-service' : ($row['event_code'] == -2 ? 'fault-badge-predictive' : '') ?>">
                                                    <?php if ($row['event_code'] == -1): ?>
                                                        Servicing due
                                                    <?php elseif ($row['event_code'] == -2): ?>
                                                        Predictive alert
                                                    <?php else: ?>
                                                        Code <?= $row['event_code'] ?>:
                                                        <?= htmlspecialchars($faultLabels[$row['event_code']] ?? 'Unknown') ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($row['resolved_timestamp'])): ?>
                                                    <div style="display:flex; align-items:center; gap:6px;">
                                                        <span class="device-mini-btn"
                                                            style="background:rgba(52, 199, 89, 0.08); color:var(--green); border:none; padding:4px 8px; display:inline-flex; align-items:center; gap:4px;">
                                                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"
                                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                                stroke-linejoin="round">
                                                                <path d="M3 8.5L6.5 12L13 4.5" />
                                                            </svg>
                                                            Resolved
                                                        </span>
                                                        <button class="btn-info"
                                                            style="background:transparent; border:none; padding:2px; cursor:pointer; color:var(--ink-3); display:flex; align-items:center;"
                                                            data-operator="<?= htmlspecialchars($row['operator_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-notes="<?= htmlspecialchars($row['resolution_notes'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-time="<?= date('d F Y H:i:s', $row['resolved_timestamp']) ?>"
                                                            aria-label="View resolution details">
                                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                                                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                                stroke-linejoin="round">
                                                                <circle cx="8" cy="8" r="7" />
                                                                <line x1="8" y1="11" x2="8" y2="8" />
                                                                <line x1="8" y1="5" x2="8.01" y2="5" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <button class="device-mini-btn btn-resolve" style="border:none; cursor:pointer;"
                                                        data-timestamp="<?= $row['timestamp'] ?>"
                                                        data-event-type="<?= htmlspecialchars($row['event_type']) ?>">
                                                        Mark as Resolved
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state" id="faultListEmptyState">No activity recorded.</div>
                                <?php endif; ?>
                            </div>

                            <div id="faultCalendarView"
                                style="display:none; flex-direction:column; gap:16px; padding:0 18px 18px;">
                                <div class="calendar-wrapper"
                                    style="max-width:340px; width:100%; margin:0 auto; display:flex; flex-direction:column; gap:12px;">
                                    <div class="calendar-header"
                                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <span id="calendarMonthLabel"
                                            style="font-size:16px; font-weight:600; color:var(--ink);"></span>
                                        <div style="display:flex; gap:8px;">
                                            <button id="prevMonthBtn"
                                                style="background:transparent; border:none; color:var(--blue); cursor:pointer; padding:4px; display:flex; align-items:center;">
                                                <svg width="20" height="20" viewBox="0 0 16 16" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                                    <path d="M10 2L4 8l6 6" />
                                                </svg>
                                            </button>
                                            <button id="nextMonthBtn"
                                                style="background:transparent; border:none; color:var(--blue); cursor:pointer; padding:4px; display:flex; align-items:center;">
                                                <svg width="20" height="20" viewBox="0 0 16 16" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                                    <path d="M6 2l6 6-6 6" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="calendar-grid-header"
                                        style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:11px; font-weight:600; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">
                                        <div>S</div>
                                        <div>M</div>
                                        <div>T</div>
                                        <div>W</div>
                                        <div>T</div>
                                        <div>F</div>
                                        <div>S</div>
                                    </div>
                                    <div id="calendarDaysGrid"
                                        style="display:grid; grid-template-columns:repeat(7, 1fr); gap:6px; text-align:center;">
                                    </div>
                                </div>
                                <div class="calendar-agenda"
                                    style="border-top:1px solid var(--sep-inset); padding-top:16px; margin-top:8px;">
                                    <h4 style="font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:12px;"
                                        id="agendaHeader">Agenda</h4>
                                    <div id="calendarAgendaList" style="display:flex; flex-direction:column; gap:8px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" data-section="analytics" id="section-analytics">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-analytics">
                    <h2 class="section-title">Fault Analytics</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-analytics">
                    <div class="section-inner">
                        <div class="card" style="padding:18px 0 18px;">
                            <div class="card-subtitle" style="padding: 0 18px 12px;">Fault Analytics breaks down fault
                                frequencies and distributions over the past week. The charts adapt automatically
                                depending on the number of faults recorded.</div>
                            <div class="analytics-grid">
                                <div class="analytics-chart-box">
                                    <h4 style="font-size:13px; font-weight:600; color:var(--ink-2); margin:0;">Fault
                                        Distribution</h4>
                                    <div
                                        style="width:100%; height:180px; position:relative; display:flex; justify-content:center;">
                                        <canvas id="faultDoughnutChart"></canvas>
                                    </div>
                                    <div id="faultLegend"
                                        style="display:flex; flex-direction:column; gap:6px; margin-top:12px; padding:0 8px; width:100%;">
                                    </div>
                                </div>
                                <div class="analytics-chart-box">
                                    <h4 style="font-size:13px; font-weight:600; color:var(--ink-2); margin:0;">Daily
                                        Fault Frequency (7 Days)</h4>
                                    <div
                                        style="width:100%; height:180px; position:relative; display:flex; justify-content:center;">
                                        <canvas id="faultBarChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" data-section="notifications">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-notifications">
                    <h2 class="section-title">Notification History</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-notifications">
                    <div class="section-inner">
                        <div class="card">
                            <div class="card-title">
                                Notification Emails Sent
                                <span class="card-count" id="notificationCountHeader"><?= count($notificationRows) ?>
                                    total</span>
                            </div>
                            <div id="notificationListContainer">
                                <?php if (count($notificationRows) > 0): ?>
                                    <?php foreach ($notificationRows as $row): ?>
                                        <div class="fault-row">
                                            <div class="fault-time"><?= date('d M Y H:i:s', $row['sent_timestamp']) ?></div>
                                            <div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">
                                                <div
                                                    class="fault-badge <?= $row['fault_code'] == -1 ? 'fault-badge-service' : ($row['fault_code'] == -2 ? 'fault-badge-predictive' : '') ?>">
                                                    <?php if ($row['fault_code'] == -1): ?>
                                                        Servicing due
                                                    <?php elseif ($row['fault_code'] == -2): ?>
                                                        Predictive alert
                                                    <?php else: ?>
                                                        Code <?= $row['fault_code'] ?>:
                                                        <?= htmlspecialchars($faultLabels[$row['fault_code']] ?? 'Unknown') ?>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="device-mini-btn"
                                                    style="background:rgba(52,199,89,0.08); color:var(--green); border:none;">
                                                    <?= htmlspecialchars($row['sent_to']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state" id="notificationEmptyState">No notifications sent yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section" data-section="hardware" id="section-hardware">
                <button class="section-toggle" aria-expanded="true" aria-controls="content-hardware">
                    <h2 class="section-title">Hardware</h2>
                    <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M4 6l4 4 4-4" />
                    </svg>
                </button>
                <div class="section-content" id="content-hardware">
                    <div class="section-inner">
                        <div class="card">
                            <div class="card-title">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <rect x="3" y="3" width="10" height="10" rx="1.5" />
                                    <path d="M9 1v2M9 13v2M1 9h2M13 9h2" />
                                </svg>
                                Components
                            </div>
                            <?php foreach ($hardware as $item): ?>
                                <div class="hw-row">
                                    <div class="hw-icon-wrap" style="--c: <?= $item['color'] ?>;">
                                        <?= $item['icon'] ?>
                                    </div>
                                    <div class="hw-details">
                                        <div class="hw-name"><?= htmlspecialchars($item['name']) ?></div>
                                        <div class="hw-desc"><?= htmlspecialchars($item['role']) ?></div>
                                    </div>
                                    <?php if (!empty($item['links'])): ?>
                                        <div class="hw-actions">
                                            <?php foreach ($item['links'] as $lnk): ?>
                                                <a href="<?= htmlspecialchars($lnk['url']) ?>" class="hw-link" target="_blank"
                                                    rel="noopener"><?= htmlspecialchars($lnk['label']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <div id="resolveModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-heading-block">
                    <h3 class="modal-title">Resolve Fault</h3>
                    <p class="modal-subtitle">Capture the fix details and close the issue cleanly.</p>
                </div>
                <button id="closeModalBtn" class="modal-close-btn" aria-label="Close dialog">&times;</button>
            </div>
            <form id="resolveForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" id="modalFaultTimestamp" name="fault_timestamp">
                <input type="hidden" id="modalEventType" name="event_type" value="fault">
                <div class="form-group">
                    <label class="form-label" for="operatorName">Operator Name</label>
                    <input type="text" id="operatorName" name="operator_name" class="form-input"
                        value="<?= htmlspecialchars(ucfirst($_SESSION['username'] ?? 'Admin')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="resolvedTime">Resolution Time</label>
                    <input type="datetime-local" id="resolvedTime" name="resolved_time" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="resolutionNotes">Resolution Actions / Notes</label>
                    <textarea id="resolutionNotes" name="resolution_notes" class="form-textarea"
                        placeholder="Describe how the fault was resolved..." required></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" id="cancelModalBtn" class="modal-secondary-btn">Cancel</button>
                    <button type="submit" class="modal-primary-btn">Submit Resolution</button>
                </div>
            </form>
        </div>
    </div>

    <div id="detailsModal" class="modal-overlay" style="display:none; padding: 20px;">
        <div class="modal-card modal-card-details">
            <div class="modal-header">
                <div class="modal-heading-block">
                    <div class="modal-badge">
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 8.5L6.5 12L13 4.5" />
                        </svg>
                        Resolved
                    </div>
                    <h3 class="modal-title">Resolution Details</h3>
                </div>
                <button id="closeDetailsModalBtn" class="modal-close-btn" aria-label="Close dialog">&times;</button>
            </div>

            <div class="details-stack">
                <div class="detail-row">
                    <div class="detail-icon">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 14a5 5 0 0 0-10 0M9 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" />
                        </svg>
                    </div>
                    <div class="detail-body">
                        <div class="detail-label">Operator</div>
                        <div id="detailsOperator" class="detail-value"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="8" cy="8" r="7" />
                            <polyline points="8 3.5 8 8 11 8" />
                        </svg>
                    </div>
                    <div class="detail-body">
                        <div class="detail-label">Time</div>
                        <div id="detailsTime" class="detail-value"></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-icon">
                        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 3h10v10H3z" />
                            <path d="M5.5 6.5h5M5.5 9h3" />
                        </svg>
                    </div>
                    <div class="detail-body">
                        <div class="detail-label">Notes</div>
                        <div id="detailsNotes" class="detail-value detail-value-notes"></div>
                    </div>
                </div>
            </div>

            <button id="doneDetailsBtn" class="modal-primary-btn modal-primary-full">Done</button>
        </div>
    </div>

    <script>
        var isDark = localStorage.getItem('dashboard-theme') === 'dark';
        var C = {
            blue: isDark ? '#0A84FF' : '#007AFF',
            orange: isDark ? '#FF9F0A' : '#FF9500',
            indigo: isDark ? '#5E5CE6' : '#5856D6',
            red: isDark ? '#FF453A' : '#FF3B30',
            grid: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(60,60,67,0.06)',
            ttBg: isDark ? '#2c2c2e' : '#ffffff',
            ttTitle: isDark ? '#f2f2f7' : '#1c1c1e',
            ttBody: isDark ? '#98989f' : '#8e8e93',
            ttBord: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(60,60,67,0.12)',
            axis: isDark ? '#98989f' : '#8e8e93'
        };
        function hexToRgba(hex, a) {
            var r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
        }
        window.sparkCharts = {};
        function sparkline(id, data, color) {
            var el = document.getElementById(id);
            if (!el) return;
            window.sparkCharts[id] = new Chart(el.getContext('2d'), {
                type: 'line',
                data: { labels: data.map(function (_, i) { return i }), datasets: [{ data: data, borderColor: color, backgroundColor: hexToRgba(color, 0.08), borderWidth: 1.5, pointRadius: 0, tension: 0.4, fill: true }] },
                options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { enabled: false }, datalabels: { display: false } }, scales: { x: { display: false }, y: { display: false } } }
            });
        }
        sparkline('spark-distance', <?= json_encode(array_map('floatval', $histDistance)) ?>, C.blue);
        sparkline('spark-angle', <?= json_encode(array_map('floatval', $histAngle)) ?>, C.orange);
        sparkline('spark-kf', <?= json_encode(array_map('floatval', $histKf)) ?>, C.indigo);

        <?php if (count($history) > 1): ?>
                (function () {
                    var splitAxes = true;

                    function buildTrendsChart(labelsData, distData, angleData, kfData) {
                        if (window.trendsChartInstance && typeof window.trendsChartInstance.destroy === 'function') {
                            window.trendsChartInstance.destroy();
                        }

                        window.trendsChartInstance = new Chart(document.getElementById('trendsChart').getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: labelsData,
                                datasets: [
                                    { label: 'Distance (cm)', data: distData, borderColor: C.blue, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.blue, tension: 0.3, yAxisID: 'y' },
                                    { label: 'Angle (°)', data: angleData, borderColor: C.orange, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.orange, tension: 0.3, yAxisID: splitAxes ? 'yAngle' : 'y' },
                                    { label: 'KF Innovation (cm)', data: kfData, borderColor: C.indigo, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.indigo, tension: 0.3, yAxisID: 'y' }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                interaction: { intersect: false, mode: 'index' },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { backgroundColor: C.ttBg, titleColor: C.ttTitle, bodyColor: C.ttBody, borderColor: C.ttBord, borderWidth: 0.5, cornerRadius: 10, padding: 10, titleFont: { weight: '600', size: 13 }, bodyFont: { size: 13 }, usePointStyle: true },
                                    datalabels: { display: false }
                                },
                                scales: {
                                    x: { ticks: { color: C.axis, font: { size: 10 }, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: C.grid }, border: { display: false } },
                                    y: { type: 'linear', display: true, position: 'left', ticks: { color: splitAxes ? C.blue : C.axis, font: { size: 10 } }, grid: { color: C.grid }, border: { display: false } },
                                    yAngle: { type: 'linear', display: splitAxes, position: 'right', min: 0, max: 180, ticks: { color: C.orange, font: { size: 10 } }, grid: { drawOnChartArea: false }, border: { display: false } }
                                }
                            }
                        });
                    }

                    buildTrendsChart(
                        <?= json_encode($histLabels) ?>,
                        <?= json_encode(array_map('floatval', $histDistance)) ?>,
                        <?= json_encode(array_map('floatval', $histAngle)) ?>,
                        <?= json_encode(array_map('floatval', $histKf)) ?>
                    );

                    document.querySelectorAll('.trends-legend .legend-item').forEach(function (item) {
                        item.addEventListener('click', function () {
                            var index = parseInt(this.getAttribute('data-dataset'), 10);
                            var meta = window.trendsChartInstance.getDatasetMeta(index);
                            meta.hidden = meta.hidden === null ? !window.trendsChartInstance.data.datasets[index].hidden : null;
                            this.style.opacity = meta.hidden ? '0.4' : '1';
                            window.trendsChartInstance.update();
                        });
                    });

                    var toggleBtn = document.getElementById('toggleAxesBtn');
                    if (toggleBtn) {
                        var switchDot = document.getElementById('toggleAxesDot');
                        toggleBtn.addEventListener('click', function () {
                            splitAxes = !splitAxes;
                            toggleBtn.setAttribute('aria-pressed', String(splitAxes));
                            toggleBtn.classList.toggle('toggle-btn-active', splitAxes);
                            toggleBtn.classList.toggle('neutral', !splitAxes);

                            if (switchDot) {
                                switchDot.style.right = splitAxes ? '2px' : '13px';
                            }
                            var switchTrack = document.getElementById('toggleAxesSwitch');
                            if (switchTrack) {
                                switchTrack.style.background = splitAxes ? 'rgba(255,255,255,0.35)' : 'rgba(0,0,0,0.15)';
                            }

                            var currentLabels = window.trendsChartInstance.data.labels;
                            var currentDist = window.trendsChartInstance.data.datasets[0].data;
                            var currentAngle = window.trendsChartInstance.data.datasets[1].data;
                            var currentKf = window.trendsChartInstance.data.datasets[2].data;
                            buildTrendsChart(currentLabels, currentDist, currentAngle, currentKf);
                        });
                    }
                })();
        <?php endif; ?>

        <?php if ($fftData): ?>
                (function () {
                    var ctx = document.getElementById('fftChart').getContext('2d');
                    var fg = ctx.createLinearGradient(0, 0, 0, 240);
                    fg.addColorStop(0, hexToRgba(C.blue, 0.1));
                    fg.addColorStop(1, hexToRgba(C.blue, 0.0));
                    new Chart(ctx, {
                        type: 'line',
                        data: { labels: <?= json_encode($fftData['freqs']) ?>, datasets: [{ data: <?= json_encode($fftData['magnitudes']) ?>, borderColor: C.blue, backgroundColor: fg, fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 3, pointHoverBackgroundColor: C.blue, borderWidth: 1.5 }] },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            interaction: { intersect: false, mode: 'index' },
                            plugins: { legend: { display: false }, tooltip: { backgroundColor: C.ttBg, titleColor: C.ttTitle, bodyColor: C.ttBody, borderColor: C.ttBord, borderWidth: 0.5, cornerRadius: 10, padding: 10, titleFont: { weight: '600', size: 13 }, bodyFont: { size: 13 }, displayColors: false }, datalabels: { display: false } },
                            scales: {
                                x: { title: { display: true, text: 'Frequency (Hz)', color: C.axis, font: { size: 12 } }, ticks: { color: C.axis, font: { size: 11 }, maxTicksLimit: 10 }, grid: { color: C.grid }, border: { display: false } },
                                y: { title: { display: true, text: 'Amplitude', color: C.axis, font: { size: 12 } }, ticks: { color: C.axis, font: { size: 11 } }, grid: { color: C.grid }, border: { display: false } }
                            }
                        }
                    });
                })();
        <?php endif; ?>

        var calendarFaults = <?= json_encode($calendarFaults) ?>;
        var faultLabelsJS = { 0: 'No fault', 1: 'Sensor out of range', 2: 'Servo arm jammed' };
        function setVal(id, val) {
            var el = document.getElementById(id);
            if (!el) return;
            var s = String(val == null ? '—' : val);
            if (el.textContent !== s) {
                el.textContent = s;
                el.classList.remove('val-flash');
                void el.offsetWidth;
                el.classList.add('val-flash');
            }
        }

        function bindResolveButton(btn) {
            btn.addEventListener('click', function () {
                var ts = this.dataset.timestamp;
                var eventType = this.dataset.eventType || 'fault';
                if (ts) {
                    document.getElementById('modalFaultTimestamp').value = ts;
                    document.getElementById('modalEventType').value = eventType;
                    document.getElementById('resolutionNotes').value = '';
                    var now = new Date();
                    var tzOffset = now.getTimezoneOffset() * 60000;
                    var localISOTime = (new Date(now - tzOffset)).toISOString().slice(0, 16);
                    var timeEl = document.getElementById('resolvedTime');
                    if (timeEl) timeEl.value = localISOTime;
                    document.getElementById('resolveModal').style.display = 'flex';
                }
            });
        }

        var lastDashboardTimestamp = null;
        var lastFaultCode = <?= intval($latest['fault_code'] ?? 0) ?>;
        var lastServiceDue = <?= $serviceDueActive ? 'true' : 'false' ?>;
        var lastPredictiveAlert = <?= $predictiveActive ? 'true' : 'false' ?>;
        var globalFaultCount = <?= intval($faultCount) ?>;
        var lastNotifiedFaultTimestamp = <?= !empty($notificationRows) ? intval($notificationRows[0]['fault_timestamp']) : 'null' ?>;
        var lastNotifiedServiceTimestamp = null;
        var lastNotifiedPredictiveTimestamp = null;
        var pageLoadTime = Date.now() / 1000;

        function updateDashboard(d) {
            var isNewData = (lastDashboardTimestamp !== d.timestamp);

            var isNewFault = isNewData && (d.fault_code !== 0) && (d.fault_code !== lastFaultCode);
            lastFaultCode = d.fault_code;

            if (isNewFault) {
                var emptyEl = document.getElementById('faultListEmptyState');
                if (emptyEl) {
                    emptyEl.style.display = 'none';
                }
                globalFaultCount++;
                var countHeader = document.getElementById('faultCountHeader');
                if (countHeader) {
                    countHeader.textContent = globalFaultCount + ' total';
                }
                var dateObj = new Date(d.timestamp * 1000);
                var day = String(dateObj.getDate()).padStart(2, '0');
                var month = dateObj.toLocaleString('en-US', { month: 'long' });
                var year = dateObj.getFullYear();
                var timePart = dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var timeStr = day + ' ' + month + ' ' + year + ' ' + timePart;
                var container = document.getElementById('faultListContainer');
                if (container) {
                    var row = document.createElement('div');
                    row.className = 'fault-row';
                    row.innerHTML =
                        '<div class="fault-time">' + timeStr + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge">Code ' + d.fault_code + ': ' + (faultLabelsJS[d.fault_code] || 'Unknown') + '</div>' +
                        '<button class="device-mini-btn btn-resolve" style="border:none; cursor:pointer;" data-timestamp="' + d.timestamp + '">Mark as Resolved</button>' +
                        '</div>';

                    bindResolveButton(row.querySelector('.btn-resolve'));
                    addCalendarEvent(d.timestamp, d.fault_code, 'fault');
                    container.insertBefore(row, container.firstChild);
                }
            }

            var isNewService = isNewData && !!d.service_due && !lastServiceDue;
            lastServiceDue = !!d.service_due;

            if (isNewService) {
                var emptyEl2 = document.getElementById('faultListEmptyState');
                if (emptyEl2) emptyEl2.style.display = 'none';
                globalFaultCount++;
                var countHeader2 = document.getElementById('faultCountHeader');
                if (countHeader2) countHeader2.textContent = globalFaultCount + ' total';
                var dateObj2 = new Date(d.timestamp * 1000);
                var timeStr2 = String(dateObj2.getDate()).padStart(2, '0') + ' ' +
                    dateObj2.toLocaleString('en-US', { month: 'long' }) + ' ' + dateObj2.getFullYear() + ' ' +
                    dateObj2.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var container2 = document.getElementById('faultListContainer');
                if (container2) {
                    var row2 = document.createElement('div');
                    row2.className = 'fault-row';
                    row2.innerHTML =
                        '<div class="fault-time">' + timeStr2 + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge fault-badge-service">Servicing due</div>' +
                        '<button class="device-mini-btn btn-resolve" style="border:none; cursor:pointer;" data-timestamp="' + d.timestamp + '" data-event-type="service">Mark as Resolved</button>' +
                        '</div>';
                    bindResolveButton(row2.querySelector('.btn-resolve'));
                    addCalendarEvent(d.timestamp, -1, 'service');
                    container2.insertBefore(row2, container2.firstChild);
                }
            }

            var isNewPredictive = isNewData && !!d.predictive_alert && !lastPredictiveAlert;
            lastPredictiveAlert = !!d.predictive_alert;

            if (isNewPredictive) {
                var emptyEl3 = document.getElementById('faultListEmptyState');
                if (emptyEl3) emptyEl3.style.display = 'none';
                globalFaultCount++;
                var countHeader3 = document.getElementById('faultCountHeader');
                if (countHeader3) countHeader3.textContent = globalFaultCount + ' total';
                var dateObj3 = new Date(d.timestamp * 1000);
                var timeStr3 = String(dateObj3.getDate()).padStart(2, '0') + ' ' +
                    dateObj3.toLocaleString('en-US', { month: 'long' }) + ' ' + dateObj3.getFullYear() + ' ' +
                    dateObj3.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var container3 = document.getElementById('faultListContainer');
                if (container3) {
                    var row3 = document.createElement('div');
                    row3.className = 'fault-row';
                    row3.innerHTML =
                        '<div class="fault-time">' + timeStr3 + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge fault-badge-predictive">Predictive alert</div>' +
                        '<button class="device-mini-btn btn-resolve" style="border:none; cursor:pointer;" data-timestamp="' + d.timestamp + '" data-event-type="predictive">Mark as Resolved</button>' +
                        '</div>';
                    bindResolveButton(row3.querySelector('.btn-resolve'));
                    addCalendarEvent(d.timestamp, -2, 'predictive');
                    container3.insertBefore(row3, container3.firstChild);
                }
            }

            if ((isNewFault || isNewService || isNewPredictive) && calendarContainer && calendarContainer.style.display !== 'none') {
                renderCalendar();
            }

            lastDashboardTimestamp = d.timestamp;

            setVal('val-distance', d.distance);
            setVal('val-angle', d.angle);
            setVal('val-speed', d.sweep_speed);
            setVal('val-cycles', d.cycle_count);
            setVal('val-ema', d.ema_deviation);
            var kfEl = document.getElementById('val-kf');
            if (kfEl) {
                setVal('val-kf', d.kf_innovation);
                kfEl.className = (d.kf_innovation ?? 0) > 3 ? 'metric-value-warn' : '';
            }
            var fEl = document.getElementById('val-fault');
            if (fEl) {
                setVal('val-fault', d.fault_code);
                var faultTile = fEl.closest('.metric-tile');
                if (faultTile) {
                    faultTile.classList.toggle('metric-tile-danger', (d.fault_code ?? 0) != 0);
                }
            }
            document.getElementById('val-fault-label').textContent = faultLabelsJS[d.fault_code ?? 0] || 'Unknown fault';
            var sEl = document.getElementById('val-service');
            if (sEl) {
                var sv = d.service_due ? 'Yes' : 'No';
                setVal('val-service', sv);
                var serviceTile = sEl.closest('.metric-tile');
                if (serviceTile) {
                    serviceTile.classList.toggle('metric-tile-warning', !!d.service_due);
                }
            }
            var fa = !!d.fault_latched;
            var sd = !!d.service_due;
            var bannerText = document.getElementById('bannerText');
            bannerText.textContent = fa ? 'Fault active' : 'Normal';
            bannerText.className = 'status-item-value' + (fa ? ' status-value-danger' : '');

            var serviceStatusText = document.getElementById('serviceStatusText');
            serviceStatusText.textContent = sd ? 'Service due' : 'Up to date';
            serviceStatusText.className = 'status-item-value' + (sd ? ' status-value-warning' : '');

            var detailRow = document.getElementById('statusDetailRow');

            if (fa) {
                var faultBox = document.getElementById('faultDetailBox');
                var fTime = new Date(d.timestamp * 1000).toLocaleDateString([], { day: '2-digit', month: 'short' }) + ', ' + new Date(d.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                if (!faultBox) {
                    faultBox = document.createElement('div');
                    faultBox.className = 'status-detail status-detail-danger';
                    faultBox.id = 'faultDetailBox';
                    faultBox.innerHTML =
                        '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1.5l7 12.5H1L8 1.5z"/><path d="M8 6v3.5M8 12v.01"/></svg>' +
                        '<div><div class="status-detail-title" id="faultDetailTitle">Code ' + d.fault_code + ': ' + (faultLabelsJS[d.fault_code] || 'Unknown') + '</div>' +
                        '<div class="status-detail-time" id="faultDetailTime">' + fTime + '</div></div>';
                    detailRow.insertBefore(faultBox, detailRow.firstChild);
                } else {
                    document.getElementById('faultDetailTitle').textContent = 'Code ' + d.fault_code + ': ' + (faultLabelsJS[d.fault_code] || 'Unknown');
                }
            } else {
                var staleFault = document.getElementById('faultDetailBox');
                if (staleFault) staleFault.remove();
            }

            if (sd) {
                var serviceBox = document.getElementById('serviceDetailBox');
                var sTime = new Date(d.timestamp * 1000).toLocaleDateString([], { day: '2-digit', month: 'short' }) + ', ' + new Date(d.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                if (!serviceBox) {
                    serviceBox = document.createElement('div');
                    serviceBox.className = 'status-detail status-detail-warning';
                    serviceBox.id = 'serviceDetailBox';
                    serviceBox.innerHTML =
                        '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2.5"/></svg>' +
                        '<div><div class="status-detail-title">Servicing due</div>' +
                        '<div class="status-detail-time" id="serviceDetailTime">' + sTime + '</div></div>';
                    detailRow.appendChild(serviceBox);
                }
            } else {
                var staleService = document.getElementById('serviceDetailBox');
                if (staleService) staleService.remove();
            }

            var pa = !!d.predictive_alert;
            if (pa) {
                var predictiveBox = document.getElementById('predictiveDetailBox');
                var pTime = new Date(d.timestamp * 1000).toLocaleDateString([], { day: '2-digit', month: 'short' }) + ', ' + new Date(d.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                if (!predictiveBox) {
                    predictiveBox = document.createElement('div');
                    predictiveBox.className = 'status-detail status-detail-predictive';
                    predictiveBox.id = 'predictiveDetailBox';
                    predictiveBox.innerHTML =
                        '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><circle cx="8" cy="8" r="2"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2"/></svg>' +
                        '<div><div class="status-detail-title">Predictive alert</div>' +
                        '<div class="status-detail-sub">EMA deviation exceeded threshold for 5 seconds</div>' +
                        '<div class="status-detail-time" id="predictiveDetailTime">' + pTime + '</div></div>';
                    detailRow.appendChild(predictiveBox);
                }
            } else {
                var stalePredictive = document.getElementById('predictiveDetailBox');
                if (stalePredictive) stalePredictive.remove();
            }

            var pendingBanner = document.getElementById('pendingAlertBanner');
            var pendingSecondsEl = document.getElementById('pendingAlertSeconds');
            if (d.pending_alert) {
                pendingBanner.style.display = 'flex';
                pendingSecondsEl.textContent = d.pending_alert.seconds_remaining;
            } else {
                pendingBanner.style.display = 'none';
            }

            var pendingServiceBanner = document.getElementById('pendingServiceAlertBanner');
            var pendingServiceSecondsEl = document.getElementById('pendingServiceAlertSeconds');
            if (d.pending_service_alert) {
                pendingServiceBanner.style.display = 'flex';
                pendingServiceSecondsEl.textContent = d.pending_service_alert.seconds_remaining;
            } else {
                pendingServiceBanner.style.display = 'none';
            }

            var pendingPredictiveBanner = document.getElementById('pendingPredictiveAlertBanner');
            var pendingPredictiveSecondsEl = document.getElementById('pendingPredictiveAlertSeconds');
            if (d.pending_predictive_alert) {
                pendingPredictiveBanner.style.display = 'flex';
                pendingPredictiveSecondsEl.textContent = d.pending_predictive_alert.seconds_remaining;
            } else {
                pendingPredictiveBanner.style.display = 'none';
            }

            if (d.last_notification && d.last_notification.fault_timestamp !== lastNotifiedFaultTimestamp && d.last_notification.fault_timestamp > pageLoadTime) {
                lastNotifiedFaultTimestamp = d.last_notification.fault_timestamp;

                var notifEmptyEl = document.getElementById('notificationEmptyState');
                if (notifEmptyEl) notifEmptyEl.style.display = 'none';

                var notifCountEl = document.getElementById('notificationCountHeader');
                if (notifCountEl) {
                    var currentCount = parseInt(notifCountEl.textContent, 10) || 0;
                    notifCountEl.textContent = (currentCount + 1) + ' total';
                }

                var sentDate = new Date(d.timestamp * 1000);
                var sentDay = String(sentDate.getDate()).padStart(2, '0');
                var sentMonth = sentDate.toLocaleString('en-US', { month: 'long' });
                var sentYear = sentDate.getFullYear();
                var sentTime = sentDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var sentTimeStr = sentDay + ' ' + sentMonth + ' ' + sentYear + ' ' + sentTime;

                var notifContainer = document.getElementById('notificationListContainer');
                if (notifContainer) {
                    var notifRow = document.createElement('div');
                    notifRow.className = 'fault-row';
                    notifRow.innerHTML =
                        '<div class="fault-time">' + sentTimeStr + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge' + (d.last_notification.fault_code === -1 ? ' fault-badge-service' : '') + '">' + (d.last_notification.fault_code === -1 ? 'Servicing due' : ('Code ' + d.last_notification.fault_code + ': ' + (faultLabelsJS[d.last_notification.fault_code] || 'Unknown'))) + '</div>' +
                        '<span class="device-mini-btn" style="background:rgba(52,199,89,0.08); color:var(--green); border:none;">Sent</span>' +
                        '</div>';
                    notifContainer.insertBefore(notifRow, notifContainer.firstChild);
                }
            }

            if (d.last_service_notification && d.last_service_notification.triggered_at !== lastNotifiedServiceTimestamp && d.last_service_notification.triggered_at > pageLoadTime) {
                lastNotifiedServiceTimestamp = d.last_service_notification.triggered_at;

                var notifEmptyEl2 = document.getElementById('notificationEmptyState');
                if (notifEmptyEl2) notifEmptyEl2.style.display = 'none';

                var notifCountEl2 = document.getElementById('notificationCountHeader');
                if (notifCountEl2) {
                    var currentCount2 = parseInt(notifCountEl2.textContent, 10) || 0;
                    notifCountEl2.textContent = (currentCount2 + 1) + ' total';
                }

                var sentDate2 = new Date(d.timestamp * 1000);
                var sentDay2 = String(sentDate2.getDate()).padStart(2, '0');
                var sentMonth2 = sentDate2.toLocaleString('en-US', { month: 'long' });
                var sentYear2 = sentDate2.getFullYear();
                var sentTime2 = sentDate2.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var sentTimeStr2 = sentDay2 + ' ' + sentMonth2 + ' ' + sentYear2 + ' ' + sentTime2;

                var notifContainer2 = document.getElementById('notificationListContainer');
                if (notifContainer2) {
                    var notifRow2 = document.createElement('div');
                    notifRow2.className = 'fault-row';
                    notifRow2.innerHTML =
                        '<div class="fault-time">' + sentTimeStr2 + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge fault-badge-service">Servicing due</div>' +
                        '<span class="device-mini-btn" style="background:rgba(52,199,89,0.08); color:var(--green); border:none;">Sent</span>' +
                        '</div>';
                    notifContainer2.insertBefore(notifRow2, notifContainer2.firstChild);
                }
            }

            if (d.last_predictive_notification && d.last_predictive_notification.triggered_at !== lastNotifiedPredictiveTimestamp && d.last_predictive_notification.triggered_at > pageLoadTime) {
                lastNotifiedPredictiveTimestamp = d.last_predictive_notification.triggered_at;

                var notifEmptyEl3 = document.getElementById('notificationEmptyState');
                if (notifEmptyEl3) notifEmptyEl3.style.display = 'none';

                var notifCountEl3 = document.getElementById('notificationCountHeader');
                if (notifCountEl3) {
                    var currentCount3 = parseInt(notifCountEl3.textContent, 10) || 0;
                    notifCountEl3.textContent = (currentCount3 + 1) + ' total';
                }

                var sentDate3 = new Date(d.timestamp * 1000);
                var sentTimeStr3 = String(sentDate3.getDate()).padStart(2, '0') + ' ' +
                    sentDate3.toLocaleString('en-US', { month: 'long' }) + ' ' + sentDate3.getFullYear() + ' ' +
                    sentDate3.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

                var notifContainer3 = document.getElementById('notificationListContainer');
                if (notifContainer3) {
                    var notifRow3 = document.createElement('div');
                    notifRow3.className = 'fault-row';
                    notifRow3.innerHTML =
                        '<div class="fault-time">' + sentTimeStr3 + '</div>' +
                        '<div class="fault-badge-wrap" style="display:flex; align-items:center; gap:8px;">' +
                        '<div class="fault-badge fault-badge-predictive">Predictive alert</div>' +
                        '<span class="device-mini-btn" style="background:rgba(52,199,89,0.08); color:var(--green); border:none;">Sent</span>' +
                        '</div>';
                    notifContainer3.insertBefore(notifRow3, notifContainer3.firstChild);
                }
            }

            if (window.trendsChartInstance) {
                var timeStr = new Date(d.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var lastLabel = window.trendsChartInstance.data.labels[window.trendsChartInstance.data.labels.length - 1];
                if (lastLabel !== timeStr) {
                    window.trendsChartInstance.data.labels.push(timeStr);
                    window.trendsChartInstance.data.datasets[0].data.push(parseFloat(d.distance));
                    window.trendsChartInstance.data.datasets[1].data.push(parseFloat(d.angle));
                    window.trendsChartInstance.data.datasets[2].data.push(parseFloat(d.kf_innovation));
                    if (window.trendsChartInstance.data.labels.length > 30) {
                        window.trendsChartInstance.data.labels.shift();
                        window.trendsChartInstance.data.datasets[0].data.shift();
                        window.trendsChartInstance.data.datasets[1].data.shift();
                        window.trendsChartInstance.data.datasets[2].data.shift();
                    }
                    window.trendsChartInstance.update();
                }
            }

            function updateSpark(id, val) {
                var spk = window.sparkCharts && window.sparkCharts[id];
                if (spk && val !== undefined) {
                    spk.data.labels.push(spk.data.labels.length);
                    spk.data.datasets[0].data.push(parseFloat(val));
                    if (spk.data.labels.length > 30) {
                        spk.data.labels.shift();
                        spk.data.datasets[0].data.shift();
                    }
                    spk.update();
                }
            }

            if (isNewData) {
                updateSpark('spark-distance', d.distance);
                updateSpark('spark-angle', d.angle);
                updateSpark('spark-kf', d.kf_innovation);
            }
        }

        (function () {
            var cancelBtn = document.getElementById('cancelAlertBtn');
            var csrfInput = document.getElementById('csrfTokenGlobal');
            if (!cancelBtn) return;

            cancelBtn.addEventListener('click', function () {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancelling...';

                var formData = new FormData();
                formData.append('csrf_token', csrfInput.value);

                fetch('cancel_alert.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            document.getElementById('pendingAlertBanner').style.display = 'none';
                        } else {
                            alert('Error: ' + (res.error || 'Failed to cancel alert'));
                            cancelBtn.disabled = false;
                            cancelBtn.textContent = 'Cancel Alert';
                        }
                    })
                    .catch(function () {
                        alert('Network error, please try again.');
                        cancelBtn.disabled = false;
                        cancelBtn.textContent = 'Cancel Alert';
                    });
            });
        })();

        (function () {
            var cancelServiceBtn = document.getElementById('cancelServiceAlertBtn');
            var csrfInput = document.getElementById('csrfTokenGlobal');
            if (!cancelServiceBtn) return;

            cancelServiceBtn.addEventListener('click', function () {
                cancelServiceBtn.disabled = true;
                cancelServiceBtn.textContent = 'Cancelling...';

                var formData = new FormData();
                formData.append('csrf_token', csrfInput.value);

                fetch('cancel_service_alert.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            document.getElementById('pendingServiceAlertBanner').style.display = 'none';
                        } else {
                            alert('Error: ' + (res.error || 'Failed to cancel alert'));
                            cancelServiceBtn.disabled = false;
                            cancelServiceBtn.textContent = 'Cancel Alert';
                        }
                    })
                    .catch(function () {
                        alert('Network error, please try again.');
                        cancelServiceBtn.disabled = false;
                        cancelServiceBtn.textContent = 'Cancel Alert';
                    });
            });
        })();

        (function () {
            var cancelPredictiveBtn = document.getElementById('cancelPredictiveAlertBtn');
            var csrfInput = document.getElementById('csrfTokenGlobal');
            if (!cancelPredictiveBtn) return;

            cancelPredictiveBtn.addEventListener('click', function () {
                cancelPredictiveBtn.disabled = true;
                cancelPredictiveBtn.textContent = 'Cancelling...';

                var formData = new FormData();
                formData.append('csrf_token', csrfInput.value);

                fetch('cancel_predictive_alert.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            document.getElementById('pendingPredictiveAlertBanner').style.display = 'none';
                        } else {
                            alert('Error: ' + (res.error || 'Failed to cancel alert'));
                            cancelPredictiveBtn.disabled = false;
                            cancelPredictiveBtn.textContent = 'Cancel Alert';
                        }
                    })
                    .catch(function () {
                        alert('Network error, please try again.');
                        cancelPredictiveBtn.disabled = false;
                        cancelPredictiveBtn.textContent = 'Cancel Alert';
                    });
            });
        })();

        var connectionDot = document.getElementById('connectionDot');
        var connectionStatusText = document.getElementById('connectionStatusText');
        var connectionPill = document.getElementById('connectionPill');
        var updEl = document.getElementById('lastUpdated');

        function pollData() {
            fetch('api.php')
                .then(function (r) { return r.json() })
                .then(function (d) {
                    updateDashboard(d);
                    connectionStatusText.textContent = 'Online';
                    connectionDot.style.background = 'var(--green)';
                    connectionPill.style.color = 'var(--green)';
                    connectionPill.style.background = 'rgba(52, 199, 89, 0.12)';
                    updEl.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                })
                .catch(function (err) {
                    console.error('API fetch error:', err);
                    connectionStatusText.textContent = 'Offline';
                    connectionDot.style.background = 'var(--red)';
                    connectionPill.style.color = 'var(--red)';
                    connectionPill.style.background = 'rgba(255, 59, 48, 0.1)';
                    updEl.textContent = 'Connection lost';
                });
        }
        setInterval(pollData, 2000);

        (function () {
            var html = document.documentElement;
            var sunI = document.getElementById('iconSun');
            var moonI = document.getElementById('iconMoon');
            function applyThemeIcon() {
                var dk = html.dataset.theme === 'dark';
                sunI.style.display = dk ? 'none' : 'block';
                moonI.style.display = dk ? 'block' : 'none';
            }
            applyThemeIcon();
            document.getElementById('themeToggle').addEventListener('click', function () {
                html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
                localStorage.setItem('dashboard-theme', html.dataset.theme);
                applyThemeIcon();
            });
            var sizeBtns = document.querySelectorAll('.size-btn');
            function applySizeActive() {
                var cur = localStorage.getItem('dashboard-textsize') || 'md';
                sizeBtns.forEach(function (b) {
                    var a = b.dataset.size === cur;
                    b.classList.toggle('active', a);
                    b.setAttribute('aria-checked', a);
                });
            }
            applySizeActive();
            sizeBtns.forEach(function (b) {
                b.addEventListener('click', function () {
                    html.dataset.size = this.dataset.size;
                    localStorage.setItem('dashboard-textsize', this.dataset.size);
                    applySizeActive();
                });
            });
            var boldBtn = document.getElementById('boldToggle');
            function applyBold() {
                var on = html.dataset.bold === 'on';
                boldBtn.setAttribute('aria-pressed', String(on));
                boldBtn.style.color = on ? 'var(--blue)' : '';
            }
            applyBold();
            boldBtn.addEventListener('click', function () {
                html.dataset.bold = html.dataset.bold === 'on' ? 'off' : 'on';
                localStorage.setItem('dashboard-bold', html.dataset.bold);
                applyBold();
            });
        })();

        (function () {
            var userTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            var tzAbbrEl = document.getElementById('userTimezoneLabel');
            if (tzAbbrEl) {
                var tzShort = new Date().toLocaleTimeString('en-US', { timeZoneName: 'short' }).split(' ').pop();
                tzAbbrEl.textContent = 'Times shown in: ' + tzShort;
            }
        })();

        (function () {
            var saved = JSON.parse(localStorage.getItem('dashboard-collapsed') || '{}');
            document.querySelectorAll('.section-toggle').forEach(function (btn) {
                var sec = btn.closest('.section');
                var key = sec.dataset.section;
                if (saved[key]) {
                    sec.classList.add('collapsed');
                    btn.setAttribute('aria-expanded', 'false');
                }
                btn.addEventListener('click', function () {
                    sec.classList.toggle('collapsed');
                    var expanded = !sec.classList.contains('collapsed');
                    btn.setAttribute('aria-expanded', String(expanded));
                    var st = JSON.parse(localStorage.getItem('dashboard-collapsed') || '{}');
                    st[key] = !expanded;
                    localStorage.setItem('dashboard-collapsed', JSON.stringify(st));
                    if (expanded) {
                        setTimeout(function () {
                            sec.querySelectorAll('canvas').forEach(function (cv) {
                                var ch = Chart.getChart(cv);
                                if (ch) ch.resize();
                            });
                        }, 350);
                    }
                });
            });
        })();

        (function () {
            var modal = document.getElementById('resolveModal');
            var form = document.getElementById('resolveForm');
            var tsInput = document.getElementById('modalFaultTimestamp');
            var notesText = document.getElementById('resolutionNotes');
            document.querySelectorAll('.btn-resolve').forEach(function (btn) {
                bindResolveButton(btn);
            });
            function closeModal() {
                modal.style.display = 'none';
            }
            document.getElementById('closeModalBtn').addEventListener('click', closeModal);
            document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var formData = new FormData(form);
                fetch('resolve_fault.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert('Error: ' + (res.error || 'Failed to save resolution'));
                        }
                    })
                    .catch(function () {
                        alert('Network error, please try again.');
                    });
            });
        })();
        var detailsModal = document.getElementById('detailsModal');
        function handleInfoClick(e) {
            var btn = e.target.closest('.btn-info');
            if (btn) {
                document.getElementById('detailsOperator').textContent = btn.dataset.operator;
                document.getElementById('detailsTime').textContent = btn.dataset.time;
                document.getElementById('detailsNotes').textContent = btn.dataset.notes;
                detailsModal.style.display = 'flex';
            }
        }
        var listContainer = document.getElementById('faultListContainer');
        if (listContainer && detailsModal) {
            listContainer.addEventListener('click', handleInfoClick);
        }
        var calendarContainer = document.getElementById('faultCalendarView');
        if (calendarContainer && detailsModal) {
            calendarContainer.addEventListener('click', handleInfoClick);
        }
        var closeDetailsBtn = document.getElementById('closeDetailsModalBtn');
        var doneDetailsBtn = document.getElementById('doneDetailsBtn');
        function closeDetails() {
            detailsModal.style.display = 'none';
        }
        if (closeDetailsBtn && detailsModal) {
            closeDetailsBtn.addEventListener('click', closeDetails);
        }
        if (doneDetailsBtn && detailsModal) {
            doneDetailsBtn.addEventListener('click', closeDetails);
        }
        if (detailsModal) {
            detailsModal.addEventListener('click', function (e) {
                if (e.target === detailsModal) {
                    closeDetails();
                }
            });
        }

        var viewSegments = document.querySelectorAll('.segmented-control .segment');
        var calendarContainer = document.getElementById('faultCalendarView');
        var currentCalDate = new Date();
        var selectedCalDateStr = null;
        viewSegments.forEach(function (seg) {
            seg.addEventListener('click', function () {
                viewSegments.forEach(function (s) {
                    s.classList.remove('active');
                    s.setAttribute('aria-selected', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');

                var targetView = this.dataset.view;
                if (targetView === 'list') {
                    listContainer.style.display = 'block';
                    calendarContainer.style.display = 'none';
                } else {
                    listContainer.style.display = 'none';
                    calendarContainer.style.display = 'flex';
                    renderCalendar();
                }
            });
        });

        function renderCalendar() {
            var year = currentCalDate.getFullYear();
            var month = currentCalDate.getMonth();
            var monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            document.getElementById('calendarMonthLabel').textContent = monthNames[month] + ' ' + year;
            var firstDayIndex = new Date(year, month, 1).getDay();
            var lastDayDate = new Date(year, month + 1, 0).getDate();
            var grid = document.getElementById('calendarDaysGrid');
            grid.innerHTML = '';
            for (var i = 0; i < firstDayIndex; i++) {
                var blank = document.createElement('div');
                blank.className = 'calendar-day empty-day';
                grid.appendChild(blank);
            }
            for (var day = 1; day <= lastDayDate; day++) {
                var cell = document.createElement('div');
                cell.className = 'calendar-day';
                var formattedDay = String(day).padStart(2, '0');
                var formattedMonth = String(month + 1).padStart(2, '0');
                var dateStr = year + '-' + formattedMonth + '-' + formattedDay;
                cell.dataset.date = dateStr;
                var numSpan = document.createElement('span');
                numSpan.textContent = day;
                numSpan.style.fontWeight = '600';
                cell.appendChild(numSpan);
                var faults = calendarFaults[dateStr] || [];
                if (faults.length > 0) {
                    var dot = document.createElement('span');
                    dot.className = 'day-dot';
                    dot.style.background = 'var(--red)';
                    cell.appendChild(dot);
                } else {
                    var blankDot = document.createElement('span');
                    blankDot.className = 'day-dot';
                    blankDot.style.background = 'transparent';
                    cell.appendChild(blankDot);
                }
                if (selectedCalDateStr === dateStr) {
                    cell.classList.add('active-day');
                }
                cell.addEventListener('click', function () {
                    document.querySelectorAll('#calendarDaysGrid .calendar-day').forEach(function (c) {
                        c.classList.remove('active-day');
                    });
                    this.classList.add('active-day');
                    selectedCalDateStr = this.dataset.date;
                    renderAgenda(selectedCalDateStr);
                });
                grid.appendChild(cell);
            }
            if (!selectedCalDateStr) {
                var today = new Date();
                var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
                selectedCalDateStr = todayStr;
            }
            var matchCell = grid.querySelector('.calendar-day[data-date="' + selectedCalDateStr + '"]');
            if (matchCell) {
                matchCell.classList.add('active-day');
            }
            renderAgenda(selectedCalDateStr);
        }

        function renderAgenda(dateStr) {
            var parts = dateStr.split('-');
            var y = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10) - 1;
            var d = parseInt(parts[2], 10);
            var dateObj = new Date(y, m, d);
            var monthName = dateObj.toLocaleString('en-US', { month: 'long' });
            document.getElementById('agendaHeader').textContent = 'Agenda — ' + d + ' ' + monthName + ' ' + y;
            var agendaList = document.getElementById('calendarAgendaList');
            agendaList.innerHTML = '';
            var faults = calendarFaults[dateStr] || [];
            if (faults.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'empty-state';
                empty.textContent = 'No faults recorded on this day.';
                empty.style.padding = '12px';
                agendaList.appendChild(empty);
                return;
            }
            faults.forEach(function (f) {
                var row = document.createElement('div');
                row.className = 'fault-row';
                row.style.padding = '10px 0';
                row.style.minHeight = 'auto';
                row.style.border = 'none';
                row.style.marginLeft = '0';
                var tObj = new Date(f.timestamp * 1000);
                var timeStr = tObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                var badgeHtml = '';
                if (f.resolved_timestamp) {
                    var displayTime = new Date(f.resolved_timestamp * 1000).toLocaleString('en-US', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).replace('at ', '');
                    badgeHtml =
                        '<div style="display:flex; align-items:center; gap:6px;">' +
                        '<span class="device-mini-btn" style="background:rgba(52, 199, 89, 0.08); color:var(--green); border:none; padding:3px 6px; font-size:11px; display:inline-flex; align-items:center; gap:4px;">' +
                        '<svg width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5L6.5 12L13 4.5"/></svg>' +
                        'Resolved</span>' +
                        '<button class="btn-info" style="background:transparent; border:none; padding:2px; cursor:pointer; color:var(--ink-3); display:flex; align-items:center;" ' +
                        'data-operator="' + escapeHtml(f.operator_name) + '" ' +
                        'data-notes="' + escapeHtml(f.resolution_notes) + '" ' +
                        'data-time="' + displayTime + '">' +
                        '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
                        '<circle cx="8" cy="8" r="7"/>' +
                        '<line x1="8" y1="11" x2="8" y2="8"/>' +
                        '<line x1="8" y1="5" x2="8.01" y2="5"/>' +
                        '</svg>' +
                        '</button>' +
                        '</div>';
                } else {
                    badgeHtml = '<button class="device-mini-btn btn-resolve" style="border:none; cursor:pointer; padding:3px 8px; font-size:11px;" data-timestamp="' + f.timestamp + '">Mark as Resolved</button>';
                }
                var badgeLabel = (f.event_type === 'service') ? 'Servicing due' : (f.event_type === 'predictive') ? 'Predictive alert' : ('Code ' + f.fault_code + ': ' + (faultLabelsJS[f.fault_code] || 'Unknown'));
                row.innerHTML =
                    '<div class="fault-time" style="font-size:13px;">' + timeStr + '</div>' +
                    '<div style="display:flex; align-items:center; gap:8px;">' +
                    '<div class="fault-badge' + (f.event_type === 'service' ? ' fault-badge-service' : f.event_type === 'predictive' ? ' fault-badge-predictive' : '') + '" style="font-size:11px; padding:2px 8px;">' + badgeLabel + '</div>' +
                    badgeHtml +
                    '</div>';
                var btnResolve = row.querySelector('.btn-resolve');
                if (btnResolve) {
                    bindResolveButton(btnResolve);
                }
                agendaList.appendChild(row);
            });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function addCalendarEvent(timestamp, eventCode, eventType) {
            var dateObj = new Date(timestamp * 1000);
            var dateKey = dateObj.getFullYear() + '-' +
                String(dateObj.getMonth() + 1).padStart(2, '0') + '-' +
                String(dateObj.getDate()).padStart(2, '0');
            if (!calendarFaults[dateKey]) {
                calendarFaults[dateKey] = [];
            }
            calendarFaults[dateKey].unshift({
                timestamp: timestamp,
                fault_code: eventCode,
                event_type: eventType,
                resolved_timestamp: null,
                operator_name: '',
                resolution_notes: ''
            });
        }

        document.getElementById('prevMonthBtn').addEventListener('click', function () {
            currentCalDate.setMonth(currentCalDate.getMonth() - 1);
            renderCalendar();
        });

        document.getElementById('nextMonthBtn').addEventListener('click', function () {
            currentCalDate.setMonth(currentCalDate.getMonth() + 1);
            renderCalendar();
        });

        renderAnalytics();

        function renderAnalytics() {
            var countOOR = 0;
            var countJammed = 0;
            for (var dateKey in calendarFaults) {
                var faults = calendarFaults[dateKey] || [];
                faults.forEach(function (f) {
                    if (f.event_type === 'fault' && f.fault_code === 1) countOOR++;
                    else if (f.event_type === 'fault' && f.fault_code === 2) countJammed++;
                });
            }
            if (window.faultDoughnutInstance && typeof window.faultDoughnutInstance.destroy === 'function') {
                window.faultDoughnutInstance.destroy();
            }
            var doughnutCtx = document.getElementById('faultDoughnutChart').getContext('2d');
            window.faultDoughnutInstance = new Chart(doughnutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Sensor Out of Range', 'Servo Jammed'],
                    datasets: [{
                        data: [countOOR, countJammed],
                        backgroundColor: [C.red || '#FF3B30', C.orange || '#FF9500'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: C.ttBg || '#ffffff',
                            titleColor: C.ttTitle || '#1c1c1e',
                            bodyColor: C.ttBody || '#8e8e93',
                            borderColor: C.ttBord || 'rgba(60,60,67,0.12)',
                            borderWidth: 0.5,
                            cornerRadius: 8,
                            padding: 8
                        },
                        datalabels: {
                            display: false
                        }
                    },
                    cutout: '65%'
                }
            });

            var legendEl = document.getElementById('faultLegend');
            if (legendEl) {
                var total = countOOR + countJammed;
                var items = [
                    { label: 'Sensor Out of Range', val: countOOR, color: C.red || '#FF3B30' },
                    { label: 'Servo Jammed', val: countJammed, color: C.orange || '#FF9500' }
                ];
                legendEl.innerHTML = items.map(function (it) {
                    var pct = total > 0 ? Math.round((it.val / total) * 100) : 0;
                    var isZero = it.val === 0;
                    var swatch = isZero ? (C.grid || '#d1d1d6') : it.color;
                    return '<div style="display:flex; align-items:center; justify-content:space-between; font-size:13px;">' +
                        '<span style="display:flex; align-items:center; gap:6px; color:' + (isZero ? (C.axis || '#8e8e93') : 'inherit') + ';">' +
                        '<span style="width:10px; height:10px; border-radius:2px; background:' + swatch + '; display:inline-block;"></span>' +
                        it.label + '</span><span style="font-weight:600;">' + it.val + ' (' + pct + '%)</span></div>';
                }).join('');
            }

            var weeklyLabels = [];
            var weeklyCounts = [];
            var dayShortNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            for (var i = 6; i >= 0; i--) {
                var d = new Date();
                d.setDate(d.getDate() - i);
                var formattedDay = String(d.getDate()).padStart(2, '0');
                var formattedMonth = String(d.getMonth() + 1).padStart(2, '0');
                var dateStr = d.getFullYear() + '-' + formattedMonth + '-' + formattedDay;
                weeklyLabels.push(dayShortNames[d.getDay()] + ' ' + d.getDate());
                var faultsOnDay = calendarFaults[dateStr] || [];
                weeklyCounts.push(faultsOnDay.length);
            }
            if (window.faultBarInstance && typeof window.faultBarInstance.destroy === 'function') {
                window.faultBarInstance.destroy();
            }
            var barCtx = document.getElementById('faultBarChart').getContext('2d');
            window.faultBarInstance = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: weeklyLabels,
                    datasets: [{
                        label: 'Faults',
                        data: weeklyCounts,
                        backgroundColor: C.blue || '#007AFF',
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: C.ttBg || '#ffffff',
                            titleColor: C.ttTitle || '#1c1c1e',
                            bodyColor: C.ttBody || '#8e8e93',
                            borderColor: C.ttBord || 'rgba(60,60,67,0.12)',
                            borderWidth: 0.5,
                            cornerRadius: 8,
                            padding: 8
                        },
                        datalabels: {
                            color: C.ink || '#1c1c1e',
                            anchor: 'end',
                            align: 'top',
                            font: { size: 10, weight: '600' },
                            formatter: function (value) { return value; }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: C.axis || '#8e8e93', font: { size: 10, weight: '500' } }
                        },
                        y: {
                            suggestedMax: Math.max(...weeklyCounts) + 1,
                            grid: { color: C.grid || 'rgba(60,60,67,0.06)', drawBorder: false },
                            ticks: {
                                color: C.axis || '#8e8e93',
                                font: { size: 10 },
                                stepSize: 1,
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>

</html>