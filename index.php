<?php
require_once __DIR__ . '/auth.php';
requireAuth();
$db = new SQLite3(DB_PATH);
$db->busyTimeout(5000);
$latest = json_decode(file_get_contents(LATEST_JSON), true);
$faultCount = $db->querySingle("SELECT COUNT(*) FROM readings WHERE fault_code != 0");
$recentFaults = $db->query("SELECT timestamp, fault_code FROM readings WHERE fault_code != 0 ORDER BY timestamp DESC LIMIT 10");
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
$hardware = [
    ['name' => 'ESP32-S3-12K', 'role' => 'Microcontroller running Modbus TCP slave firmware'],
    ['name' => 'HC-SR04', 'role' => 'Ultrasonic sensor, measures distance to the rack'],
    ['name' => 'SG92R Micro Servo', 'role' => 'Drives the rack-and-pinion mechanism'],
    ['name' => 'Rack-and-Pinion Assembly', 'role' => 'Mechanism that converts rotation to linear motion'],
    ['name' => 'Voltage Divider (3x 1kΩ)', 'role' => 'Steps down ECHO signal from 5V to 3.3V'],
    ['name' => 'Breadboard', 'role' => 'Platform connecting all components'],
];
$historyStmt = $db->query("SELECT timestamp, distance, angle, kf_innovation FROM readings ORDER BY timestamp DESC LIMIT 30");
$history = [];
while ($r = $historyStmt->fetchArray(SQLITE3_ASSOC)) { $history[] = $r; }
$history = array_reverse($history);
$histLabels = array_map(function($h) { return date('H:i:s', intval($h['timestamp'])); }, $history);
$histDistance = array_column($history, 'distance');
$histAngle = array_column($history, 'angle');
$histKf = array_column($history, 'kf_innovation');
$faultRows = [];
while ($row = $recentFaults->fetchArray(SQLITE3_ASSOC)) { $faultRows[] = $row; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-size="md">

<head>
  <meta charset="UTF-8">
  <title>Maintenance Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="IIoT Servo System with live data and fault monitoring.">
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
      background: var(--bg);
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
      background: var(--bg);
      border-radius: 8px;
      overflow: hidden;
      gap: 1px;
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
      color: var(--red);
      background: rgba(255, 59, 48, 0.08);
      border: none;
      border-radius: 7px;
      cursor: pointer;
      text-decoration: none;
      transition: opacity 0.15s;
    }

    html[data-theme="dark"] .logout-btn {
      background: rgba(255, 69, 58, 0.12);
    }

    .logout-btn:hover {
      opacity: 0.75;
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

    .device-bar {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      padding: 12px 18px;
      background: var(--card);
      border-radius: var(--r);
      margin-bottom: 20px;
      font-size: 13px;
      color: var(--ink-3);
    }

    .device-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--green);
      flex-shrink: 0;
    }

    @keyframes livePulse {

      0%,
      100% {
        opacity: 1
      }

      50% {
        opacity: 0.4
      }
    }

    .device-dot.live {
      animation: livePulse 2s ease-in-out infinite;
    }

    .device-model {
      color: var(--ink);
      font-weight: 600;
    }

    .device-ip {
      font-family: 'SF Mono', 'Menlo', 'Consolas', monospace;
      color: var(--ink-2);
      font-weight: 500;
    }

    .device-sep {
      opacity: 0.4;
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

    .stat[data-tip]::after {
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
      max-width: 200px;
      text-align: center;
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.15s;
      z-index: 100;
    }

    .stat[data-tip]::before {
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

    .stat[data-tip]:hover::after,
    .stat[data-tip]:hover::before {
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
    }

    .chart-area {
      padding: 4px 10px 8px;
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

    .device-mini-btn:hover {
      opacity: 0.75;
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
      gap: 20px;
      padding: 12px 18px 4px;
      flex-wrap: wrap;
    }
    .legend-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      font-weight: 500;
      color: var(--ink-3); 
    }
    .trends-legend .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
    }
.trends-legend .dot.blue   { background-color: var(--blue); }
.trends-legend .dot.orange { background-color: var(--orange); }
.trends-legend .dot.indigo { background-color: var(--indigo); }
  </style>
</head>

<body>
  <a href="#main-content" class="skip-link">Skip to content</a>
  <div class="wrap">
    <nav class="toolbar" aria-label="Dashboard controls">
      <div class="toolbar-left">
        <button class="tool-btn" id="themeToggle" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
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
        <button class="tool-btn" id="boldToggle" title="Toggle bold text" aria-label="Toggle bold text"
          aria-pressed="false">
          <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
            stroke-linecap="round">
            <path d="M4 8h5a2.5 2.5 0 0 0 0-5H4v5zm0 0h5.5a2.5 2.5 0 0 1 0 5H4V8z" />
          </svg>
        </button>
      </div>
      <div class="toolbar-right">
        <div class="toolbar-user">
          <div class="toolbar-avatar" aria-hidden="true">
            <?= strtoupper(substr($_SESSION['username'] ?? 'Admin', 0, 1)) ?>
          </div>
          <span class="toolbar-username">
            <?= htmlspecialchars($_SESSION['username'] ?? 'Admin' ) ?>
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
    <div class="device-bar" role="status">
      <span class="device-dot live" id="connectionDot" aria-label="Connection status: online"></span>
      <span class="device-model">
        <?= ESP32_MODEL ?>
      </span>
      <span class="device-sep" aria-hidden="true">·</span>
      <span class="device-ip">
        <?= ESP32_IP ?>
      </span>
      <span class="device-sep" aria-hidden="true">·</span>
      <span id="lastUpdated">Updated just now</span>
      <span style="margin-left:auto;display:flex;gap:6px;">
        <a href="#section-hardware" class="device-mini-btn">View all Components</a>
      </span>
    </div>
    <main id="main-content">
      <div class="banner <?= $faultActive ? 'fault' : 'ok' ?>" id="statusBanner" role="alert" aria-live="polite">
        <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
          stroke-linecap="round" stroke-linejoin="round" id="bannerIcon" aria-hidden="true">
          <?php if ($faultActive): ?>
          <circle cx="8" cy="8" r="6" />
          <path d="M8 5v3.5M8 11v.01" />
          <?php else: ?>
          <circle cx="8" cy="8" r="6" />
          <path d="m5.5 8 2 2L11 6" />
          <?php endif; ?>
        </svg>
        <span id="bannerText">
          <?= $faultActive ? 'Fault Active — ' . htmlspecialchars($currentFaultLabel) : 'All Systems Normal' ?>
        </span>
        <span id="bannerTags">
          <?php if ($alertActive): ?><span class="tag">Predictive Alert</span>
          <?php endif; ?>
          <?php if ($serviceDueActive): ?><span class="tag">Service Due</span>
          <?php endif; ?>
        </span>
      </div>
      <section class="section" data-section="readings">
        <button class="section-toggle" aria-expanded="true" aria-controls="content-readings">
          <h2 class="section-title">Readings</h2>
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-readings">
          <div class="section-inner">
            <div class="grid">
              <a href="detail.php?metric=distance" class="stat" style="--c: var(--blue)"
                data-tip="Current distance from the HC-SR04 sensor to the rack surface."
                aria-label="Distance: <?= $latest['distance'] ?? '—' ?> cm — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 2v12M5 5l3-3 3 3M5 11l3 3 3-3" />
                  </svg>
                  Distance
                </div>
                <div class="stat-body">
                  <span class="stat-num" id="val-distance">
                    <?= $latest['distance'] ?? '—' ?>
                  </span>
                  <span class="stat-unit">cm</span>
                </div>
                <?php if (count($histDistance) > 1): ?>
                <div class="sparkline-wrap"><canvas id="spark-distance" aria-hidden="true"></canvas></div>
                <?php endif; ?>
              </a>
              <a href="detail.php?metric=angle" class="stat" style="--c: var(--orange)"
                data-tip="Current servo angle in degrees. Sweeps between 30° and 150°."
                aria-label="Angle: <?= $latest['angle'] ?? '—' ?>° — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 14V4M3 14h9" />
                    <path d="M6.5 11c0-2.5 2-4.5 4.5-4.5" />
                  </svg>
                  Angle
                </div>
                <div class="stat-body">
                  <span class="stat-num" id="val-angle">
                    <?= $latest['angle'] ?? '—' ?>
                  </span>
                  <span class="stat-unit">°</span>
                </div>
                <?php if (count($histAngle) > 1): ?>
                <div class="sparkline-wrap"><canvas id="spark-angle" aria-hidden="true"></canvas></div>
                <?php endif; ?>
              </a>
              <a href="detail.php?metric=sweep_speed" class="stat" style="--c: var(--purple)"
                data-tip="Time in ms between each 1° step. Lower = faster."
                aria-label="Sweep Speed: <?= $latest['sweep_speed'] ?? '—' ?> ms/step — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2 12a6 6 0 1 1 12 0" />
                    <path d="M8 12 5.5 7" />
                  </svg>
                  Sweep Speed
                </div>
                <div class="stat-body">
                  <span class="stat-num" id="val-speed">
                    <?= $latest['sweep_speed'] ?? '—' ?>
                  </span>
                  <span class="stat-unit">ms/step</span>
                </div>
              </a>
              <a href="detail.php?metric=cycle_count" class="stat" style="--c: var(--green)"
                data-tip="Total completed sweeps. Service triggers at 100."
                aria-label="Cycle Count: <?= $latest['cycle_count'] ?? '—' ?> — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 8A6 6 0 1 1 8 2" />
                    <path d="M8 2h3M8 2v3" />
                  </svg>
                  Cycle Count
                </div>
                <div class="stat-body">
                  <span class="stat-num" id="val-cycles">
                    <?= $latest['cycle_count'] ?? '—' ?>
                  </span>
                </div>
              </a>
            </div>
          </div>
        </div>
      </section>
      <section class="section" data-section="diagnostics">
        <button class="section-toggle" aria-expanded="true" aria-controls="content-diagnostics">
          <h2 class="section-title">Diagnostics</h2>
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-diagnostics">
          <div class="section-inner">
            <div class="grid">
              <a href="detail.php?metric=ema_deviation" class="stat" style="--c: var(--teal)"
                data-tip="EMA of deviation between actual and expected distance. Rising = possible wear."
                aria-label="EMA Deviation: <?= $latest['ema_deviation'] ?? '—' ?> — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" aria-hidden="true">
                    <path d="M1 8q2.5-5 5 0t5 0t4 0" />
                  </svg>
                  EMA Deviation
                </div>
                <div class="stat-body">
                  <span class="stat-num" id="val-ema">
                    <?= $latest['ema_deviation'] ?? '—' ?>
                  </span>
                </div>
              </a>
              <a href="detail.php?metric=kf_innovation" class="stat" style="--c: var(--indigo)"
                data-tip="Kalman Filter innovation. Above 3.0 cm = warning."
                aria-label="KF Innovation: <?= $latest['kf_innovation'] ?? '—' ?> cm — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" aria-hidden="true">
                    <circle cx="8" cy="8" r="5" />
                    <path d="M8 2v2M8 12v2M2 8h2M12 8h2" />
                  </svg>
                  KF Innovation
                </div>
                <div class="stat-body">
                  <span class="stat-num <?= ($latest['kf_innovation'] ?? 0) > 3 ? 'warn' : '' ?>" id="val-kf">
                    <?= $latest['kf_innovation'] ?? '—' ?>
                  </span>
                  <span class="stat-unit">cm</span>
                </div>
                <?php if (count($histKf) > 1): ?>
                <div class="sparkline-wrap"><canvas id="spark-kf" aria-hidden="true"></canvas></div>
                <?php endif; ?>
              </a>
              <a href="detail.php?metric=fault_code" class="stat" style="--c: var(--red)"
                data-tip="0 = No fault. 1 = Sensor OOR. 2 = Servo jammed."
                aria-label="Fault Code: <?= $latest['fault_code'] ?? '—' ?> — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 2 14.5 13.5H1.5Z" />
                    <path d="M8 7v3M8 12v.01" />
                  </svg>
                  Fault Code
                </div>
                <div class="stat-body">
                  <span class="stat-num <?= ($latest['fault_code'] ?? 0) != 0 ? 'danger' : '' ?>" id="val-fault">
                    <?= $latest['fault_code'] ?? '—' ?>
                  </span>
                </div>
                <div class="stat-detail" id="val-fault-label">
                  <?= htmlspecialchars($currentFaultLabel) ?>
                </div>
              </a>
              <a href="detail.php?metric=service_due" class="stat" style="--c: var(--orange)"
                data-tip="TRUE when cycle count reaches 100. Operator must acknowledge."
                aria-label="Service Due: <?= $serviceDueActive ? 'Yes' : 'No' ?> — click for details">
                <div class="stat-label">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="8" cy="8" r="6" />
                    <path d="M8 4.5V8l2.5 2.5" />
                  </svg>
                  Service Due
                </div>
                <div class="stat-body">
                  <span class="stat-num <?= $serviceDueActive ? 'warn' : '' ?>" id="val-service">
                    <?= $serviceDueActive ? 'Yes' : 'No' ?>
                  </span>
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
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-trends">
          <div class="section-inner">
            <div class="card">
  <div class="card-title">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M2 12l4-4.5L8.5 10 14 3" />
      <path d="M10 3h4v4" />
    </svg>
    Recent History
    <span style="margin-left:auto;display:flex;gap:6px;">
      <a href="export.php?format=csv" class="device-mini-btn">Export CSV</a>
      <a href="export.php?format=json" class="device-mini-btn">Export JSON</a>
    </span>
  </div>
              <div class="card-subtitle">Last
                <?= count($history) ?> readings — Distance, Angle, KF Innovation
              </div>

              <div class="trends-legend" aria-hidden="true">
                <div class="legend-item"><span class="dot blue"></span>Distance (cm)</div>
                <div class="legend-item"><span class="dot orange"></span>Angle (°)</div>
                <div class="legend-item"><span class="dot indigo"></span>KF Innovation (cm)</div>
              </div>

              <div class="chart-area"><canvas id="trendsChart" height="220" role="img"
                  aria-label="Line chart showing recent readings for distance, angle, and KF innovation"></canvas></div>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>
      <section class="section" data-section="spectrum">
        <button class="section-toggle" aria-expanded="true" aria-controls="content-spectrum">
          <h2 class="section-title">Spectrum Analysis</h2>
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-spectrum">
          <div class="section-inner">
            <div class="card">
              <div class="card-title">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                  stroke-linecap="round" aria-hidden="true">
                  <path d="M2.5 13V9.5M5.5 13V5M8.5 13V7.5M11.5 13V3.5M14.5 13V8" />
                </svg>
                FFT Spectrum
              </div>
              <div class="card-subtitle">Healthy baseline — run fft_analysis.py to capture</div>
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
      <section class="section" data-section="faults">
        <button class="section-toggle" aria-expanded="true" aria-controls="content-faults">
          <h2 class="section-title">Fault History</h2>
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-faults">
          <div class="section-inner">
            <div class="card">
              <div class="card-title">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="3" y="1.5" width="10" height="13" rx="1.5" />
                  <path d="M6 5.5h4M6 8.5h4M6 11.5h2.5" />
                </svg>
                Recent Faults
                <span class="card-count">
                  <?= $faultCount ?> total
                </span>
              </div>
              <?php if (count($faultRows) > 0): ?>
              <?php foreach ($faultRows as $row): ?>
              <div class="fault-row">
                <div class="fault-time">
                  <?= date('H:i:s', $row['timestamp']) ?>
                </div>
                <div class="fault-badge">
                  <?= htmlspecialchars($faultLabels[$row['fault_code']] ?? 'Code ' . $row['fault_code']) ?>
                </div>
              </div>
              <?php endforeach; ?>
              <?php else: ?>
              <div class="empty-state">No faults recorded.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>
      <section class="section" data-section="hardware" id="section-hardware">
        <button class="section-toggle" aria-expanded="true" aria-controls="content-hardware">
          <h2 class="section-title">Hardware</h2>
          <svg class="section-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6l4 4 4-4" />
          </svg>
        </button>
        <div class="section-content" id="content-hardware">
          <div class="section-inner">
            <div class="card">
              <div class="card-title">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="4" y="4" width="8" height="8" rx="1" />
                  <path d="M6 4V2M10 4V2M6 12v2M10 12v2M4 6H2M4 10H2M12 6h2M12 10h2" />
                </svg>
                Components
              </div>
              <?php foreach ($hardware as $item): ?>
              <div class="list-row">
                <div class="list-label">
                  <?= htmlspecialchars($item['name']) ?>
                </div>
                <div class="list-value">
                  <?= htmlspecialchars($item['role']) ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script>
    var isDark = localStorage.getItem('dashboard-theme') === 'dark';
    var C = {
      blue: isDark ? '#0A84FF' : '#007AFF',
      orange: isDark ? '#FF9F0A' : '#FF9500',
      indigo: isDark ? '#5E5CE6' : '#5856D6',
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
    function sparkline(id, data, color) {
      var el = document.getElementById(id);
      if (!el) return;
      new Chart(el.getContext('2d'), {
        type: 'line',
        data: { labels: data.map(function (_, i) { return i }), datasets: [{ data: data, borderColor: color, backgroundColor: hexToRgba(color, 0.08), borderWidth: 1.5, pointRadius: 0, tension: 0.4, fill: true }] },
        options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false } } }
      });
    }
    sparkline('spark-distance',<?= json_encode(array_map('floatval', $histDistance)) ?>, C.blue);
    sparkline('spark-angle',<?= json_encode(array_map('floatval', $histAngle)) ?>, C.orange);
    sparkline('spark-kf',<?= json_encode(array_map('floatval', $histKf)) ?>, C.indigo);
<?php if (count($history) > 1): ?>
      (function () {
        new Chart(document.getElementById('trendsChart').getContext('2d'), {
          type: 'line',
          data: {
            labels:<?= json_encode($histLabels) ?>,
            datasets: [
              { label: 'Distance (cm)', data:<?= json_encode(array_map('floatval', $histDistance)) ?>, borderColor: C.blue, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.blue, tension: 0.3 },
              { label: 'Angle (°)', data:<?= json_encode(array_map('floatval', $histAngle)) ?>, borderColor: C.orange, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.orange, tension: 0.3 },
              { label: 'KF Innovation (cm)', data:<?= json_encode(array_map('floatval', $histKf)) ?>, borderColor: C.indigo, backgroundColor: 'transparent', borderWidth: 2, pointRadius: 2, pointBackgroundColor: C.indigo, tension: 0.3 }
            ]
          },
          options: {
            responsive: true, animation: false, interaction: { intersect: false, mode: 'index' },
            plugins: {
              legend: { display:false },
              tooltip: { backgroundColor: C.ttBg, titleColor: C.ttTitle, bodyColor: C.ttBody, borderColor: C.ttBord, borderWidth: 0.5, cornerRadius: 10, padding: 10, titleFont: { weight: '600', size: 13 }, bodyFont: { size: 13 }, usePointStyle: true }
            },
            scales: {
              x: { ticks: { color: C.axis, font: { size: 10 }, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: C.grid }, border: { display: false } },
              y: { ticks: { color: C.axis, font: { size: 10 } }, grid: { color: C.grid }, border: { display: false } }
            }
          }
        });
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
          data: { labels:<?= json_encode($fftData['freqs']) ?>, datasets: [{ data:<?= json_encode($fftData['magnitudes']) ?>, borderColor: C.blue, backgroundColor: fg, fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 3, pointHoverBackgroundColor: C.blue, borderWidth: 1.5 }] },
          options: {
            responsive: true, animation: false, interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: C.ttBg, titleColor: C.ttTitle, bodyColor: C.ttBody, borderColor: C.ttBord, borderWidth: 0.5, cornerRadius: 10, padding: 10, titleFont: { weight: '600', size: 13 }, bodyFont: { size: 13 }, displayColors: false } },
            scales: {
              x: { title: { display: true, text: 'Frequency (Hz)', color: C.axis, font: { size: 12 } }, ticks: { color: C.axis, font: { size: 11 }, maxTicksLimit: 10 }, grid: { color: C.grid }, border: { display: false } },
              y: { title: { display: true, text: 'Amplitude', color: C.axis, font: { size: 12 } }, ticks: { color: C.axis, font: { size: 11 } }, grid: { color: C.grid }, border: { display: false } }
            }
          }
        });
      })();
<?php endif; ?>
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
    function updateDashboard(d) {
      setVal('val-distance', d.distance);
      setVal('val-angle', d.angle);
      setVal('val-speed', d.sweep_speed);
      setVal('val-cycles', d.cycle_count);
      setVal('val-ema', d.ema_deviation);
      var kfEl = document.getElementById('val-kf');
      if (kfEl) {
        setVal('val-kf', d.kf_innovation);
        kfEl.className = 'stat-num' + ((d.kf_innovation ?? 0) > 3 ? ' warn' : '');
      }
      var fEl = document.getElementById('val-fault');
      if (fEl) {
        setVal('val-fault', d.fault_code);
        fEl.className = 'stat-num' + ((d.fault_code ?? 0) != 0 ? ' danger' : '');
      }
      document.getElementById('val-fault-label').textContent = faultLabelsJS[d.fault_code ?? 0] || 'Unknown fault';
      var sEl = document.getElementById('val-service');
      if (sEl) {
        var sv = d.service_due ? 'Yes' : 'No';
        setVal('val-service', sv);
        sEl.className = 'stat-num' + (d.service_due ? ' warn' : '');
      }
      var fa = !!d.fault_latched;
      var bn = document.getElementById('statusBanner');
      bn.className = 'banner ' + (fa ? 'fault' : 'ok');
      document.getElementById('bannerIcon').innerHTML = fa
        ? '<circle cx="8" cy="8" r="6"/><path d="M8 5v3.5M8 11v.01"/>'
        : '<circle cx="8" cy="8" r="6"/><path d="m5.5 8 2 2L11 6"/>';
      document.getElementById('bannerText').textContent = fa
        ? 'Fault Active — ' + (faultLabelsJS[d.fault_code ?? 0] || 'Unknown fault')
        : 'Servo System Normal';
      var tg = '';
      if (d.predictive_alert) tg += '<span class="tag">Predictive Alert</span>';
      if (d.service_due) tg += '<span class="tag">Service Due</span>';
      document.getElementById('bannerTags').innerHTML = tg;
    }
    var dot = document.getElementById('connectionDot');
    var updEl = document.getElementById('lastUpdated');
    function pollData() {
      fetch('api.php')
        .then(function (r) { return r.json() })
        .then(function (d) {
          updateDashboard(d);
          dot.style.background = '';
          dot.className = 'device-dot live';
          dot.setAttribute('aria-label', 'Connection status: online');
          updEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
        })
        .catch(function () {
          dot.style.background = 'var(--red)';
          dot.className = 'device-dot';
          dot.setAttribute('aria-label', 'Connection status: offline');
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
  </script>
</body>

</html>