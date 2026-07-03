<?php
require_once __DIR__ . '/auth.php';
requireAuth();
$metrics = [
    'distance' => [
        'name' => 'Distance',
        'unit' => 'cm',
        'colorLight' => '#007AFF',
        'colorDark' => '#0A84FF',
        'cssVar' => '--blue',
        'dbColumn' => 'distance',
        'description' => 'Measured by the HC-SR04 ultrasonic sensor. Represents the distance from the sensor face to the rack surface. Changes as the SG92R servo sweeps the rack-and-pinion mechanism through its range.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v12M5 5l3-3 3 3M5 11l3 3 3-3"/></svg>',
    ],
    'angle' => [
        'name' => 'Angle',
        'unit' => '°',
        'colorLight' => '#FF9500',
        'colorDark' => '#FF9F0A',
        'cssVar' => '--orange',
        'dbColumn' => 'angle',
        'description' => 'Current position of the SG92R servo motor in degrees. The servo sweeps between 30 and 150 degrees during normal operation, driving the rack-and-pinion mechanism.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14V4M3 14h9"/><path d="M6.5 11c0-2.5 2-4.5 4.5-4.5"/></svg>',
    ],
    'sweep_speed' => [
        'name' => 'Sweep Speed',
        'unit' => 'ms/step',
        'colorLight' => '#AF52DE',
        'colorDark' => '#BF5AF2',
        'cssVar' => '--purple',
        'dbColumn' => null,
        'description' => 'Time in milliseconds between each 1-degree servo step. Lower values mean faster sweeping. Adjustable at runtime via Modbus register writes.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12a6 6 0 1 1 12 0"/><path d="M8 12 5.5 7"/></svg>',
    ],
    'cycle_count' => [
        'name' => 'Cycle Count',
        'unit' => '',
        'colorLight' => '#34C759',
        'colorDark' => '#30D158',
        'cssVar' => '--green',
        'dbColumn' => null,
        'description' => 'Total number of completed servo sweeps since the last reset. The preventive maintenance system triggers a service-due alert when this count reaches 100 cycles.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 8A6 6 0 1 1 8 2"/><path d="M8 2h3M8 2v3"/></svg>',
    ],
    'ema_deviation' => [
        'name' => 'EMA Deviation',
        'unit' => '',
        'colorLight' => '#5AC8FA',
        'colorDark' => '#64D2FF',
        'cssVar' => '--teal',
        'dbColumn' => null,
        'description' => 'Exponential Moving Average of the deviation between actual measured distance and expected distance based on the current servo angle. A rising trend may indicate mechanical wear, backlash, or sensor drift.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M1 8q2.5-5 5 0t5 0t4 0"/></svg>',
    ],
    'kf_innovation' => [
        'name' => 'KF Innovation',
        'unit' => 'cm',
        'colorLight' => '#5856D6',
        'colorDark' => '#5E5CE6',
        'cssVar' => '--indigo',
        'dbColumn' => 'kf_innovation',
        'description' => 'Kalman Filter innovation — the difference between the predicted distance and the actual measured distance. Values above 3.0 cm trigger a warning, suggesting the system model no longer matches physical behavior.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="8" r="5"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2"/></svg>',
    ],
    'fault_code' => [
        'name' => 'Fault Code',
        'unit' => '',
        'colorLight' => '#FF3B30',
        'colorDark' => '#FF453A',
        'cssVar' => '--red',
        'dbColumn' => 'fault_code',
        'description' => 'Active fault code reported by the ESP32 firmware. 0 = No fault. 1 = Sensor out of range (HC-SR04 returned 0 cm or greater than 400 cm). 2 = Servo arm jammed (angle did not change after command).',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2 14.5 13.5H1.5Z"/><path d="M8 7v3M8 12v.01"/></svg>',
    ],
    'service_due' => [
        'name' => 'Service Due',
        'unit' => '',
        'colorLight' => '#FF9500',
        'colorDark' => '#FF9F0A',
        'cssVar' => '--orange',
        'dbColumn' => null,
        'description' => 'Preventive maintenance flag. Set to TRUE when the cycle count reaches the threshold of 100 cycles. The servo stops sweeping until the operator acknowledges service via a Modbus register write.',
        'icon' => '<svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2.5"/></svg>',
    ],
];
$metric = $_GET['metric'] ?? '';
if (!array_key_exists($metric, $metrics)) {
    header('Location: index.php');
    exit;
}
$meta = $metrics[$metric];
$currentValue = '—';
$latestRaw = @file_get_contents(LATEST_JSON);
if ($latestRaw !== false) {
    $latestData = json_decode($latestRaw, true);
    if (is_array($latestData) && isset($latestData[$metric])) {
        if ($metric === 'service_due') {
            $currentValue = $latestData[$metric] ? 'Yes' : 'No';
        } else {
            $currentValue = $latestData[$metric];
        }
    }
}
$historyData = [];
$statsMin = null;
$statsMax = null;
$statsAvg = null;
$statsCount = 0;
if ($meta['dbColumn'] !== null) {
    try {
        $db = new SQLite3(DB_PATH);
        $db->busyTimeout(5000);
        $col = $meta['dbColumn'];
        $stmt = $db->prepare('SELECT timestamp, ' . $col . ' FROM readings ORDER BY timestamp DESC LIMIT 100');
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        $db->close();
        $rows = array_reverse($rows);
        $historyData = $rows;
        if (count($rows) > 0) {
            $values = array_map(function ($r) use ($col) {
                return (float)$r[$col];
            }, $rows);
            $statsMin = min($values);
            $statsMax = max($values);
            $statsAvg = round(array_sum($values) / count($values), 2);
            $statsCount = count($values);
        }
    } catch (Exception $e) {
        $historyData = [];
    }
}
$metricName = htmlspecialchars($meta['name'], ENT_QUOTES, 'UTF-8');
$metricUnit = htmlspecialchars($meta['unit'], ENT_QUOTES, 'UTF-8');
$metricDesc = htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8');
$displayValue = htmlspecialchars((string)$currentValue, ENT_QUOTES, 'UTF-8');
$metricCssVar = $meta['cssVar'];
$metricIcon = $meta['icon'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= $metricName ?> — detailed view and history for the IIoT monitoring dashboard.">
<title><?= $metricName ?> — Dashboard</title>
<script>
(function(){
    var t = localStorage.getItem('dashboard-theme');
    var s = localStorage.getItem('dashboard-textsize');
    if(t) document.documentElement.setAttribute('data-theme', t);
    if(s) document.documentElement.setAttribute('data-size', s);
})();
</script>
<?php if (count($historyData) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<style>
:root {
    --bg: #f2f2f7; --card: #ffffff; --sep-inset: rgba(60,60,67,0.12);
    --ink: #1c1c1e; --ink-2: #3a3a3c; --ink-3: #8e8e93;
    --blue: #007AFF; --orange: #FF9500; --purple: #AF52DE; --teal: #5AC8FA;
    --indigo: #5856D6; --red: #FF3B30; --green: #34C759; --r: 12px;
}
html[data-theme='dark'] {
    --bg: #000000; --card: #1c1c1e; --sep-inset: rgba(255,255,255,0.08);
    --ink: #f2f2f7; --ink-2: #d1d1d6; --ink-3: #98989f;
    --blue: #0A84FF; --orange: #FF9F0A; --purple: #BF5AF2; --teal: #64D2FF;
    --indigo: #5E5CE6; --red: #FF453A; --green: #30D158;
}
html[data-size='sm'] h1 { font-size: 24px; }
html[data-size='sm'] .value-main { font-size: 44px; }
html[data-size='sm'] .value-unit { font-size: 20px; }
html[data-size='sm'] .metric-unit-sub { font-size: 13px; }
html[data-size='sm'] .card-title { font-size: 14px; }
html[data-size='lg'] h1 { font-size: 34px; }
html[data-size='lg'] .value-main { font-size: 68px; }
html[data-size='lg'] .value-unit { font-size: 28px; }
html[data-size='lg'] .metric-unit-sub { font-size: 17px; }
html[data-size='lg'] .card-title { font-size: 18px; }
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}
.container {
    max-width: 640px;
    margin: 0 auto;
    padding: 20px 16px 40px;
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 15px;
    font-weight: 500;
    color: var(--blue);
    text-decoration: none;
    margin-bottom: 24px;
    transition: opacity 0.15s ease;
}
.back-link:hover { opacity: 0.7; }
.metric-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 4px;
}
.metric-header .icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: color-mix(in srgb, var(<?= $metricCssVar ?>) 15%, transparent);
    color: var(<?= $metricCssVar ?>);
    flex-shrink: 0;
}
.metric-header h1 {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
}
.metric-unit-sub {
    font-size: 15px;
    color: var(--ink-3);
    margin-bottom: 20px;
    padding-left: 52px;
}
.card {
    background: var(--card);
    border-radius: var(--r);
    margin-bottom: 16px;
    overflow: hidden;
    transition: background 0.2s ease;
}
.card-head {
    padding: 14px 18px 0;
}
.card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--ink);
}
.card-subtitle {
    font-size: 13px;
    color: var(--ink-3);
    margin-top: 2px;
}
.value-card-body {
    padding: 24px 18px 20px;
    text-align: center;
}
.value-row {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 8px;
}
.value-main {
    font-size: 56px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(<?= $metricCssVar ?>);
    line-height: 1;
    transition: color 0.2s ease;
}
.value-unit {
    font-size: 24px;
    color: var(--ink-3);
    font-weight: 400;
}
.value-label {
    font-size: 13px;
    color: var(--ink-3);
    margin-top: 8px;
}
.chart-wrap {
    padding: 12px 18px 18px;
}
.chart-wrap canvas {
    width: 100% !important;
    height: 220px !important;
}
.list-group {
    padding: 0;
}
.list-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    border-bottom: 1px solid var(--sep-inset);
}
.list-row:last-child { border-bottom: none; }
.list-label {
    font-size: 15px;
    color: var(--ink);
}
.list-value {
    font-size: 15px;
    color: var(--ink-3);
    font-variant-numeric: tabular-nums;
}
.about-text {
    font-size: 15px;
    color: var(--ink-2);
    line-height: 1.6;
    padding: 14px 18px 18px;
}
:focus-visible {
    outline: 3px solid var(--blue);
    outline-offset: 2px;
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
</head>
<body>
<div class="container">
    <a href="index.php" class="back-link">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2L4 8l6 6"/></svg>
        Dashboard
    </a>
    <div class="metric-header">
        <div class="icon-wrap"><?= $metricIcon ?></div>
        <h1><?= $metricName ?></h1>
    </div>
    <?php if ($metricUnit !== ''): ?>
    <div class="metric-unit-sub">Unit: <?= $metricUnit ?></div>
    <?php else: ?>
    <div class="metric-unit-sub">&nbsp;</div>
    <?php endif; ?>
    <div class="card">
        <div class="value-card-body">
            <div class="value-row">
                <span class="value-main" id="currentValue"><?= $displayValue ?></span>
                <?php if ($metricUnit !== ''): ?>
                <span class="value-unit"><?= $metricUnit ?></span>
                <?php endif; ?>
            </div>
            <div class="value-label">Current reading</div>
        </div>
    </div>
    <?php if (count($historyData) > 0): ?>
    <div class="card">
        <div class="card-head">
            <div class="card-title">History</div>
            <div class="card-subtitle">Last <?= $statsCount ?> readings</div>
        </div>
        <div class="chart-wrap">
            <canvas id="historyChart"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-head">
            <div class="card-title">Statistics</div>
        </div>
        <div class="list-group">
            <div class="list-row">
                <span class="list-label">Minimum</span>
                <span class="list-value"><?= htmlspecialchars($statsMin, ENT_QUOTES, 'UTF-8') ?> <?= $metricUnit ?></span>
            </div>
            <div class="list-row">
                <span class="list-label">Maximum</span>
                <span class="list-value"><?= htmlspecialchars($statsMax, ENT_QUOTES, 'UTF-8') ?> <?= $metricUnit ?></span>
            </div>
            <div class="list-row">
                <span class="list-label">Average</span>
                <span class="list-value"><?= htmlspecialchars($statsAvg, ENT_QUOTES, 'UTF-8') ?> <?= $metricUnit ?></span>
            </div>
            <div class="list-row">
                <span class="list-label">Data Points</span>
                <span class="list-value"><?= $statsCount ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-head">
            <div class="card-title">About This Metric</div>
        </div>
        <div class="about-text"><?= $metricDesc ?></div>
    </div>
</div>
<script>
var metricKey = '<?= $metric ?>';
function poll() {
    fetch('api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var el = document.getElementById('currentValue');
            if (!el) return;
            if (metricKey === 'service_due') {
                el.textContent = d.service_due ? 'Yes' : 'No';
            } else if (d[metricKey] !== undefined) {
                el.textContent = d[metricKey];
            }
        })
        .catch(function() {});
}
setInterval(poll, 2000);
<?php if (count($historyData) > 0): ?>
(function() {
    var labels = <?= json_encode(array_map(function($r) {
        return date('H:i:s', strtotime($r['timestamp']));
    }, $historyData), JSON_UNESCAPED_UNICODE) ?>;
    var values = <?= json_encode(array_map(function($r) use ($meta) {
        return (float)$r[$meta['dbColumn']];
    }, $historyData)) ?>;
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var accentColor = isDark ? '<?= $meta['colorDark'] ?>' : '<?= $meta['colorLight'] ?>';
    var gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    var tickColor = isDark ? '#98989f' : '#8e8e93';
    var tooltipBg = isDark ? '#2c2c2e' : '#ffffff';
    var tooltipText = isDark ? '#f2f2f7' : '#1c1c1e';
    var tooltipBorder = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)';
    var ctx = document.getElementById('historyChart').getContext('2d');
    var gradient = ctx.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, accentColor + '33');
    gradient.addColorStop(1, accentColor + '00');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                borderColor: accentColor,
                backgroundColor: gradient,
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: accentColor,
                pointHoverBorderColor: tooltipBg,
                pointHoverBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: tooltipBg,
                    titleColor: tooltipText,
                    bodyColor: tooltipText,
                    borderColor: tooltipBorder,
                    borderWidth: 1,
                    cornerRadius: 8,
                    padding: 10,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' <?= addslashes($metricUnit) ?>';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: gridColor, drawBorder: false },
                    ticks: {
                        color: tickColor,
                        font: { size: 11 },
                        maxRotation: 0,
                        maxTicksLimit: 6
                    }
                },
                y: {
                    grid: { color: gridColor, drawBorder: false },
                    ticks: {
                        color: tickColor,
                        font: { size: 11 }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>
</script>
</body>
</html>