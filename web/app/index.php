<?php
require_once __DIR__ . '/../lib/bootstrap.php';
[$config, $db] = LibreApp::boot();

// Prevent caching of the main UI
LibreApp::noCacheHeaders();

$ctx = LibreContext::resolve($config, $db, $_GET);

$roomId             = $ctx->roomId;
$roomConfig         = $ctx->roomConfig;
$isDatabaseRoom     = $ctx->isDatabaseRoom;
$isPersonalizedUser = $ctx->isPersonalizedUser;
$lang               = $ctx->lang;
$view               = $ctx->view;
$displayName        = $ctx->displayName;
$timeFormat         = $ctx->timeFormat;
$activeTimezone     = $ctx->timezone;
$showRss            = $ctx->showRss;
$showWeather        = $ctx->showWeather;
$showClock          = $ctx->showClock;
$powerSave          = $ctx->powerSave;
$weatherLat         = $ctx->weatherLat;
$weatherLon         = $ctx->weatherLon;
$weatherCity        = $ctx->weatherCity;

// The news ticker is the most frequent repaint on the page — it pages every ten
// seconds — and on a battery-powered panel that dominates everything else. It is
// therefore limited to the room view, where the display is a shared noticeboard and
// the churn is the point. Personal dashboards and the grid do without it.
if ($view !== 'room') {
    $showRss = false;
}

// The grid packs a full week plus a detailed today block; there is no space left for
// the weather widget either.
$showWeatherWidget = ($view === 'grid') ? false : $showWeather;

// Capture Device Status (More robust detection for different gateway versions)
$devIp = $_SERVER['HTTP_X_VISIONECT_DEVICE_IP'] ?? $_SERVER['HTTP_X_DEVICE_IP'] ?? $_GET['dev_ip'] ?? null;
$devBatt = $_SERVER['HTTP_X_VISIONECT_BATTERY'] ?? $_SERVER['HTTP_X_BATTERY'] ?? $_GET['dev_batt'] ?? null;
$devSig = $_SERVER['HTTP_X_VISIONECT_SIGNAL'] ?? $_SERVER['HTTP_X_SIGNAL'] ?? $_GET['dev_sig'] ?? null;

// Fallback for some server setups where headers aren't in $_SERVER automatically
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $devIp = $devIp ?? $headers['X-Visionect-Device-IP'] ?? $headers['X-Device-IP'] ?? null;
    $devBatt = $devBatt ?? $headers['X-Visionect-Battery'] ?? $headers['X-Battery'] ?? null;
    $devSig = $devSig ?? $headers['X-Visionect-Signal'] ?? $headers['X-Signal'] ?? null;
}

// Demo mode keys off the feeds actually in use, so a user whose own calendars have
// replaced the personal template is not reported as a demo device.
$usingDemoCalendar = $ctx->usingDemoCalendar;

// Dummy values for demo rooms if no real data provided
if ($usingDemoCalendar && $devBatt === null && $devSig === null) {
    $devBatt = 69;
    $devSig = 69;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Joanne Room Display</title>
    <link rel="stylesheet" href="assets/css/weather-icons.min.css">

    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            width: 1024px;
            height: 768px;
            background: #ffffff;
            color: #000000;
            overflow: hidden;

            display: flex;
            flex-direction: column;
            font-family: Roboto, system-ui, -apple-system, sans-serif;
        }

        /* ---------- HEADER ---------- */
        .header {
            padding: 24px 48px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            -webkit-user-select: none;
            touch-action: manipulation;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .week-nav {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        #date-display {
            font-size: 28px;
            font-weight: 500;
            min-width: 300px;
            text-align: center;
        }

        /* Standardized button style for header */
        .header-btn {
            background: #fff;
            border: 1px solid #000;
            padding: 8px 16px;
            font-size: 24px;
            font-weight: 700;
            cursor: pointer;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            white-space: nowrap;
        }

        #time-btn {
            min-width: 180px;
            font-size: 22px;
        }

        .header-btn:active {
            background: #000;
            color: #fff;
        }

        .lang-indicator {
            margin-left: 20px;
        }

        /* ---------- DEVICE STATUS ---------- */
        .device-status {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
            font-weight: 700;
            color: #666;
            margin-right: 24px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .battery-icon {
            width: 24px;
            height: 12px;
            border: 2px solid #666;
            border-radius: 2px;
            position: relative;
            padding: 1px;
        }

        .battery-icon::after {
            content: '';
            position: absolute;
            right: -5px;
            top: 2px;
            width: 3px;
            height: 4px;
            background: #666;
            border-radius: 0 1px 1px 0;
        }

        .battery-level {
            height: 100%;
            background: #666;
        }

        .signal-bars {
            display: flex;
            align-items: flex-end;
            gap: 2px;
            height: 14px;
        }

        .bar {
            width: 3px;
            background: #ccc; /* Empty bar */
        }

        .bar.fill {
            background: #666; /* Filled bar */
        }

        /* ---------- MAIN ---------- */
        .main {
            flex: 1;
            padding: 12px 48px;
            display: flex;
            flex-direction: column;
            border-top: 1px solid #000;
            min-height: 0; /* Prevent stretching */
        }

        .view-grid .main {
            padding: 0;
            overflow: hidden;
        }

        .room-name {
            font-size: 24px;
            font-weight: 700;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        .status {
            font-size: 96px;
            font-weight: 700;
            margin-bottom: 24px;
            letter-spacing: 1px;
            line-height: 1;
        }

        /* ---------- DASHBOARD VIEW ---------- */
        .dashboard-container {
            display: flex;
            gap: 32px;
            height: 100%;
        }

        .dashboard-left {
            flex: 0 0 70%;
        }

        .dashboard-right {
            flex: 0 0 30%;
            border-left: 1px solid #eee;
            padding-left: 32px;
        }

        .upcoming-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .upcoming-item {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .upcoming-item:last-child {
            border-bottom: none;
        }

        .upcoming-date {
            font-size: 14px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
        }

        .upcoming-summary {
            font-size: 18px;
            font-weight: 500;
            margin: 2px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .upcoming-time {
            font-size: 16px;
            color: #444;
        }

        /* ---------- GRID VIEW ---------- */
        .grid-container {
            display: grid;
            grid-template-columns: repeat(4, 25%);
            grid-template-rows: 50% 50%;
            gap: 0;
            height: 100%;
            width: 100%;
            overflow: hidden;
        }

        .grid-cell {
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 12px;
            position: relative;
            background: #fff;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0; /* Ensure it doesn't push row height */
        }

        .grid-cell.out-of-horizon {
            background: #eee;
        }

        .grid-cell.merged {
            grid-column: span 2;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        /* Remove outer grid borders */
        .grid-container .grid-cell:nth-child(3),
        .grid-container .grid-cell:nth-child(7) {
            border-right: none;
        }

        .grid-container .grid-cell:nth-child(n+4) {
            border-bottom: none;
        }

        .today-detail {
            display: flex;
            justify-content: space-between;
            height: 100%;
        }

        .today-events {
            flex: 1.2;
            padding-right: 12px;
            border-right: 1px dashed #ccc;
        }

        .today-weather-extra {
            flex: 1;
            padding-left: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #999;
        }

        .today-weather-extra .wi {
            font-size: 64px !important;
            margin-bottom: 10px;
        }

        .today-weather-extra .temp {
            font-size: 42px;
            font-weight: 700;
        }

        .grid-date-num {
            position: absolute;
            top: 4px;
            right: 10px;
            font-size: 40px;
            font-weight: 700;
            color: #666; /* Solid dark grey from 4-bit palette */
            z-index: 0;
        }

        .grid-day-label {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            color: #000;
            margin-bottom: 24px; /* Increased to push events below the number */
            position: relative;
            z-index: 1;
            display: inline-block;
            align-self: flex-start;
        }

        .grid-event-list {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 16px;
            line-height: 1.35;
            position: relative;
            z-index: 1;
        }

        .grid-event-item {
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-left: 3px solid #000;
            padding-left: 6px;
            background: #fff; /* Solid white knockout to prevent collision with big number */
        }

        .grid-weather {
            position: absolute;
            bottom: 4px;
            right: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 32px;
            font-weight: 700;
            background: #fff;
            padding-left: 4px;
            z-index: 1;
            color: #999; /* Back to lighter grey */
        }

        .grid-weather .wi {
            font-size: 36px !important;
            color: #999;
        }

        .grid-weather-label {
            font-size: 10px;
            text-transform: uppercase;
            display: flex;
            flex-direction: column;
            align-items: flex-end; /* Right-align flex items */
            text-align: right;     /* Right-align text content */
            line-height: 1;
            margin-left: -4px;
        }

        .grid-now-label {
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .event {
            font-size: 36px;
            line-height: 1.45;
        }

        .event strong {
            display: block;
            font-size: 42px;
            margin-top: 12px;
            font-weight: 600;
        }

        /* ---------- FOOTER ---------- */
        .footer {
            border-top: 1px solid #000;
            display: flex;
            flex-direction: column;
            padding: 0;
        }

        .news-container {
            width: 100%;
            padding: 12px 24px;
            box-sizing: border-box;
            border-bottom: 1px solid #ccc;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
        }

        .news-label {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1.5px;
            margin-bottom: 4px;
        }

        .news-source {
            position: absolute;
            top: 12px;
            right: 24px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 1px;
        }

        #news-headline {
            font-size: 34px;
            font-weight: 400;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
            display: block;
            width: 100%;
            color: #000;
        }

        .weather-container {
            width: 100%;
            padding: 12px 24px;
            box-sizing: border-box;
        }

        .weather-display {
            display: flex;
            align-items: center;
            gap: 32px;
            font-size: 24px;
            font-weight: 500;
            width: 100%;
        }

        .weather-current {
            display: flex;
            align-items: center;
            gap: 24px;
            flex: 1;
        }

        .weather-forecast {
            display: flex;
            gap: 24px;
            border-left: 1px solid #ccc;
            padding-left: 32px;
        }

        .forecast-day {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 14px;
            font-weight: 700;
            gap: 4px;
        }

        .forecast-day .wi {
            font-size: 24px !important;
        }

        .weather-display .wi {
            font-size: 48px;
            color: #000;
        }

        .weather-temp {
            font-size: 48px;
            font-weight: 700;
        }

        .weather-desc {
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
        }

        a {
            text-decoration: none;
            color: inherit;
        }
    </style>
</head>

<body class="view-<?= htmlspecialchars($view) ?>">

    <!-- ================= HEADER ================= -->
    <div id="header" class="header">
        <div class="header-left">
            <div id="time-btn" class="header-btn" onclick="resetWeek();">--:--</div>
            <div class="week-nav">
                <?php if ($view === 'grid'): ?><div class="header-btn" onclick="changeWeek(-1);">&lt;</div><?php endif; ?>
                <div id="date-display">—</div>
                <?php if ($view === 'grid'): ?><div class="header-btn" onclick="changeWeek(1);">&gt;</div><?php endif; ?>
            </div>
        </div>
        
        <div style="display: flex; align-items: center;">
            <div class="device-status" id="device-status">
                <div class="status-item" id="status-ip" style="<?= $devIp ? '' : 'display:none' ?>">
                    <?= htmlspecialchars($devIp ?? '') ?>
                </div>

                <div class="status-item" id="status-signal" style="<?= $devSig !== null ? '' : 'display:none' ?>">
                    <div class="signal-bars">
                        <div class="bar fill" style="height: 4px;"></div>
                        <div class="bar <?= (int)$devSig > 20 ? 'fill' : '' ?>" style="height: 7px;"></div>
                        <div class="bar <?= (int)$devSig > 40 ? 'fill' : '' ?>" style="height: 10px;"></div>
                        <div class="bar <?= (int)$devSig > 70 ? 'fill' : '' ?>" style="height: 14px;"></div>
                    </div>
                </div>

                <div class="status-item" id="status-battery" style="<?= $devBatt !== null ? '' : 'display:none' ?>">
                    <div class="battery-icon">
                        <div id="battery-fill" class="battery-level" style="width: <?= (int)$devBatt ?>%;"></div>
                    </div>
                    <span id="battery-text"><?= (int)$devBatt ?>%</span>
                </div>
            </div>

            <div id="langIndicator" class="header-btn lang-indicator" onclick="switchLanguage();">FR</div>
        </div>
    </div>

    <!-- ================= MAIN ================= -->
    <div class="main">
        <div id="status" class="status">Loading…</div>
    </div>

    <!-- ================= FOOTER ================= -->
    <?php if ($showRss || $showWeatherWidget): ?>
    <div class="footer">
        <?php if ($showRss): ?>
        <div class="news-container">
            <div class="news-label" id="news-label">News</div>
            <div id="news-source" class="news-source"></div>
            <div id="news-headline">Loading headlines…</div>
        </div>
        <?php endif; ?>
        
        <?php if ($showWeatherWidget): ?>
        <div class="weather-container">
            <div id="weather-display" class="weather-display">
                <!-- Current Weather -->
                <div class="weather-current">
                    <div id="weather-icon" style="width: 48px; height: 48px;"></div>
                    <span class="weather-temp" id="weather-temp">--</span>
                    <div style="display:flex; flex-direction:column;">
                        <span id="weather-city" style="font-weight:700; text-transform:uppercase; font-size:14px;"><?= htmlspecialchars((string)$weatherCity) ?></span>
                        <span class="weather-desc" id="weather-desc">Loading...</span>
                    </div>
                </div>
                
                <!-- Forecast (Next 3 Days) -->
                <div id="weather-forecast" class="weather-forecast"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ================= LOGIC ================= -->
    <script>
        /* ---------- CONFIG / ROOM ---------- */
const urlParams = new URLSearchParams(window.location.search);
const roomId = urlParams.get('room') || 'default';
const view = "<?= htmlspecialchars((string)$view) ?>";
const displayName = "<?= htmlspecialchars((string)$displayName) ?>";
const timeFormat = "<?= htmlspecialchars((string)$timeFormat) ?>";
const showRss = <?= $showRss ? 'true' : 'false' ?>;
const showWeather = <?= $showWeather ? 'true' : 'false' ?>;
const showClock = <?= $showClock ? 'true' : 'false' ?>;
const powerSave = <?= $powerSave ? 'true' : 'false' ?>;
const showWeatherWidget = <?= $showWeatherWidget ? 'true' : 'false' ?>;
const weatherLat = <?= (float)$weatherLat ?>;
const weatherLon = <?= (float)$weatherLon ?>;
const weatherCity = "<?= htmlspecialchars((string)$weatherCity) ?>";
const pastHorizon = <?= (int)$ctx->pastHorizon ?>;
const futureHorizon = <?= (int)$ctx->futureHorizon ?>;
const serverTimezone = "<?= $activeTimezone ?>";
const isPersonalizedUser = <?= $isPersonalizedUser ? 'true' : 'false' ?>;

/* ---------- REPAINT BUDGET ----------
 * The panel is battery powered and repaints by diffing the rendered page, so the
 * cost that matters is how often pixels change, not how much work the script does.
 * Two rules follow:
 *   1. Never write to the DOM unless the value actually differs — setText/setHtml
 *      below return early, so an unchanged clock or headline costs nothing.
 *   2. Keep the intervals as long as the content allows, and lengthen them further
 *      in power-save mode.
 */
const REFRESH = powerSave
  ? { clock: 300000, telemetry: 300000, news: 1800000, newsRotate: 60000, weather: 3600000 }
  : { clock:  60000, telemetry:  60000, news:  600000, newsRotate: 10000, weather: 1800000 };

function setText(el, value) {
  if (!el || el.textContent === value) return false;
  el.textContent = value;
  return true;
}

function setHtml(el, value) {
  if (!el || el.innerHTML === value) return false;
  el.innerHTML = value;
  return true;
}

function setStyle(el, prop, value) {
  if (!el || el.style[prop] === value) return false;
  el.style[prop] = value;
  return true;
}

/**
 * Run on wall-clock boundaries rather than every N ms from load, so the repaint
 * lands just after the minute rolls over instead of drifting into the middle of it.
 */
function scheduleAligned(fn, periodMs) {
  const next = () => setTimeout(tick, periodMs - (Date.now() % periodMs) + 50);
  const tick = () => { fn(); next(); };
  next();
}

        /* ---------- LANGUAGE ---------- */
let lang = "<?= htmlspecialchars((string)$lang) ?>";
let is24h = (timeFormat === '24h' || (timeFormat === 'auto' && lang === 'fr'));
let lastData = null;
let lastWeatherData = null;
let autoLangTimer = null;
let dateOffset = 0; // Number of 7-day periods to offset
let headerLabel = "";
let statusLabel = "";

function updateLabels() {
  const t = i18n[lang];
  const rawRoomName = "<?= htmlspecialchars((string)($roomConfig['name'] ?? '')) ?>";
  
  if (isPersonalizedUser) {
    headerLabel = displayName || (rawRoomName === "My Schedule" ? t.MySchedule : rawRoomName);
    statusLabel = displayName || t.Today;
  } else {
    headerLabel = rawRoomName === "My Schedule" ? t.MySchedule : rawRoomName;
    statusLabel = t.Today;
  }
}

function changeWeek(dir) {
  dateOffset += dir;
  updateClock();
  renderCalendar(lastData);
}

function resetWeek() {
  dateOffset = 0;
  updateClock();
  renderCalendar(lastData);
}

/**
 * Global Time Formatter
 * Handles 12h, 24h, and language-aware defaults
 * @param {string|Date} input - Date object or "HH:MM" string
 */
function formatTime(input) {
  let date;
  if (input instanceof Date) {
    date = input;
  } else if (typeof input === 'string' && input.includes('T')) {
    // Robust handling for ISO strings
    date = new Date(input);
  } else {
    // Fallback for "HH:MM" strings (Legacy/Static data)
    // WARNING: This assumes the browser's local date, which is risky
    const [h, m] = input.split(":").map(Number);
    date = new Date();
    date.setHours(h, m, 0, 0);
  }

  const locale = (lang === "en" ? "en-CA" : "fr-CA");
  const options = {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: serverTimezone
  };

  if (is24h) {
    options.hourCycle = 'h23';
    options.hour12 = false;
  } else {
    options.hour12 = true;
  }

  let timeStr = date.toLocaleTimeString(locale, options);
  
  // Restore/Ensure spaces and convert a.m./p.m. -> AM/PM
  // 1. Convert a.m. -> AM and p.m. -> PM
  timeStr = timeStr.replace(/([ap])\.m\./gi, (m, p1) => p1.toUpperCase() + 'M');
  
  // 2. Normalize all whitespace (including non-breaking spaces) to a single standard space
  timeStr = timeStr.replace(/[\s\u00A0\u202F]+/g, ' ').trim();

  return timeStr;
}

const i18n = {
  en: {
    AVAILABLE: "AVAILABLE",
    IN_USE: "IN USE",
    Next: "Next",
    NextMeeting: "Next meeting",
    NextEvent: "Next event",
    EndsIn: mins => `Ends in ${mins} minutes`,
    At: "at",
    NoMeetings: "No upcoming meetings",
    NoEvents: "No upcoming events",
    ReturnToToday: "Today",
    News: "News",
    Upcoming: "Upcoming Events",
    Today: "Today",
    AllDay: "All day",
    MySchedule: "My Schedule"
  },
  fr: {
    AVAILABLE: "DISPONIBLE",
    IN_USE: "OCCUPÉ",
    Next: "Prochain",
    NextMeeting: "Prochaine réunion",
    NextEvent: "Prochain événement",
    EndsIn: mins => `Se termine dans ${mins} minutes`,
    At: "à",
    NoMeetings: "Aucune réunion à venir",
    NoEvents: "Aucun événement à venir",
    ReturnToToday: "Aujourd'hui",
    News: "Nouvelles",
    Upcoming: "À venir",
    Today: "Aujourd'hui",
    AllDay: "Toute la journée",
    MySchedule: "Mon horaire"
  }
};

function updateUI() {
  const langInd = document.getElementById("langIndicator");
  if (langInd) langInd.textContent = lang === "en" ? "FR" : "EN";
  const newsLabel = document.getElementById("news-label");
  if (newsLabel) newsLabel.textContent = i18n[lang].News;
  updateLabels();
  updateClock();
  renderCalendar(lastData);
}

function switchLanguage() {
  lang = lang === "en" ? "fr" : "en";
  is24h = (timeFormat === '24h' || (timeFormat === 'auto' && lang === 'fr'));
  updateUI();
  if (showRss) fetchNews(); // Refresh news for the new language
  if (showWeather) fetchWeather(); // Refresh weather for the new language
  resetAutoLanguageTimer();
}

/* ---------- AUTO LANGUAGE ROTATION ---------- */
function resetAutoLanguageTimer() {
  if (autoLangTimer) clearInterval(autoLangTimer);
  const interval = <?= (int)$config['ui']['rotation_interval'] ?>;
  if (interval > 0) {
    autoLangTimer = setInterval(switchLanguage, interval);
  }
}

resetAutoLanguageTimer();

/* ---------- DEVICE TELEMETRY ---------- */
function updateTelemetry() {
  if (typeof(okular) !== "undefined") {
    // Battery
    if (okular.hasOwnProperty('BatteryLevel')) {
      const batt = parseInt(okular.BatteryLevel);
      const battItem = document.getElementById("status-battery");
      if (battItem) {
          setStyle(battItem, "display", "flex");
          setStyle(document.getElementById("battery-fill"), "display", "block");
          setStyle(document.getElementById("battery-fill"), "width", batt + "%");
          setText(document.getElementById("battery-text"), batt + "%");
      }
    }

    // Signal (RSSI)
    if (okular.hasOwnProperty('RSSI')) {
      const rssi = parseInt(okular.RSSI);
      const sigItem = document.getElementById("status-signal");
      if (sigItem) {
          const bars = sigItem.querySelectorAll(".bar");
          setStyle(sigItem, "display", "flex");
          // classList.toggle with an explicit force flag is already a no-op when the
          // class is in the requested state, so this does not dirty the DOM.
          bars[1].classList.toggle("fill", rssi > 20);
          bars[2].classList.toggle("fill", rssi > 40);
          bars[3].classList.toggle("fill", rssi > 70);
      }
    }
  }
}

updateTelemetry();
setInterval(updateTelemetry, REFRESH.telemetry);

/* ---------- CLOCK ---------- */
function updateClock() {
  const t = i18n[lang];
  // In power-save mode the clock only repaints every few minutes, so round the value
  // down to that step: a coarse clock is honest, a stale precise one is not.
  const now = powerSave
    ? new Date(Math.floor(Date.now() / REFRESH.clock) * REFRESH.clock)
    : new Date();
  const locale = lang === "en" ? "en-CA" : "fr-CA";
  
  // 1. Handle the Time / Reset Button
  const timeBtn = document.getElementById("time-btn");
  if (timeBtn) {
      if (dateOffset !== 0) {
        // While browsing other weeks the button is the way back to today, so it stays
        // even when the clock is hidden.
        setStyle(timeBtn, "display", "flex");
        setHtml(timeBtn, t.ReturnToToday);
      } else if (!showClock) {
        // Hiding the clock removes a guaranteed repaint every single minute, which on
        // a battery panel is the largest avoidable cost on the page.
        setStyle(timeBtn, "display", "none");
      } else {
        setStyle(timeBtn, "display", "flex");
        let timeStr = formatTime(now);

        // Always append a small timezone/offset label
        const tzFormatter = new Intl.DateTimeFormat(locale, {
            timeZone: serverTimezone,
            timeZoneName: 'short',
            hour: 'numeric' // Force time parts to ensure tz is separate
        });
        const tzParts = tzFormatter.formatToParts(now);
        const tzPart = tzParts.find(p => p.type === 'timeZoneName');
        if (tzPart) {
            timeStr += `<span style="font-size: 14px; margin-left: 8px; font-weight: 500;">${tzPart.value}</span>`;
        }
        setHtml(timeBtn, timeStr);
      }
  }

  // 2. Handle the Date Display (in the middle of < >)
  const viewDate = new Date();
  if (dateOffset !== 0) {
    viewDate.setDate(viewDate.getDate() + (dateOffset * 7));
  }

  const dateStr = viewDate.toLocaleDateString(locale, {
    weekday: "short",
    year: "numeric",
    month: "short",
    day: "numeric",
    timeZone: serverTimezone
  });

  setText(document.getElementById("date-display"), dateStr);
}

// Aligned to the minute (or the power-save step) so the repaint happens right
// after the boundary rather than drifting into the middle of it.
scheduleAligned(updateClock, REFRESH.clock);
updateClock();

/**
 * Helper to get the current time in the server's timezone
 * This returns a Date object that has been shifted so that its "local"
 * methods (getHours, getMinutes) return values for the server timezone.
 */
function getServerNow() {
  const now = new Date();
  const serverStr = now.toLocaleString("en-US", { timeZone: serverTimezone });
  return new Date(serverStr);
}

/* ---------- CALENDAR ---------- */
function minutesUntil(endIso, serverNowStr) {
  const end = new Date(endIso);
  const now = serverNowStr ? new Date(serverNowStr) : new Date();
  return Math.max(0, Math.round((end - now) / 60000));
}

function renderCalendar(data) {
  if (!data) return;

  const t = i18n[lang];
  const mainEl = document.querySelector(".main");

  if (view === "grid") {
    // Group events by day
    const days = [];
    const today = getServerNow();
    today.setHours(0,0,0,0);
    
    // Calculate horizon thresholds
    const pastLimit = new Date(today);
    pastLimit.setDate(today.getDate() - pastHorizon);
    const futureLimit = new Date(today);
    futureLimit.setDate(today.getDate() + futureHorizon);

    const startOfView = new Date(today);
    startOfView.setDate(today.getDate() + (dateOffset * 7));
    
    for (let i = 0; i < 7; i++) {
      const d = new Date(startOfView);
      d.setDate(startOfView.getDate() + i);
      const dateStr = d.toISOString().split('T')[0];
      
      const isOut = d < pastLimit || d > futureLimit;
      const dayEvents = (data.upcoming || []).filter(e => e.date === dateStr);
      
      const dayName = d.toLocaleDateString(lang === 'en' ? 'en-CA' : 'fr-CA', { 
        weekday: 'short',
        timeZone: serverTimezone 
      });
      const label = i === 0 && dateOffset === 0 ? `${dayName} (${t.Today})` : dayName;

      days.push({
        dateStr: dateStr,
        dayNum: d.getDate(),
        dayLabel: label,
        events: dayEvents,
        isOut: isOut
      });
    }

    // Prepare Today's Detailed Weather for merged cell
    let todayWeatherHtml = "";
    if (dateOffset === 0 && showWeather && lastWeatherData) {
        const iconClass = wmoIcons[lastWeatherData.code] || "wi-cloudy";
        const desc = wmoCodes[lastWeatherData.code] ? wmoCodes[lastWeatherData.code][lang] : "Weather";
        todayWeatherHtml = `
            <div class="grid-weather">
              <div class="grid-weather-label">
                <span style="font-weight: 800; color: #666; margin-bottom: 2px;">${weatherCity}</span>
                <span>${desc}</span>
              </div>
              <i class="wi ${iconClass}"></i>
              <span>${lastWeatherData.temp}${lastWeatherData.unit}</span>
            </div>
        `;
    }

    const todayObj = days[0];
    const fullDayName = new Date(todayObj.dateStr + "T00:00:00").toLocaleDateString(lang === 'en' ? 'en-CA' : 'fr-CA', { 
      weekday: 'long',
      timeZone: serverTimezone
    });
    
    const mergedCellHtml = `
      <div class="grid-cell merged">
        <div class="grid-date-num" style="font-size: 52px; top: 2px; color: #666;">${todayObj.dayNum}</div>
        <div class="grid-now-label">
            <div style="font-size: 16px; color: #666; text-transform: uppercase;">${statusLabel}</div>
            <div style="font-size: 24px; color: #000; margin-top: 2px;">${fullDayName}</div>
        </div>
        <ul class="grid-event-list" style="font-size: 24px; margin-top: 12px;">
            ${todayObj.events.slice(0, 5).map(e => `
                <li class="grid-event-item" title="${e.summary}">
                    <span style="font-weight: 700; font-size: 14px; display: block; line-height: 1;">${e.is_allday ? t.AllDay : formatTime(e.start_iso) + " — " + formatTime(e.end_iso)}</span>
                    ${e.summary}
                </li>
            `).join('')}
        </ul>
        ${todayWeatherHtml}
      </div>
    `;

    const dayCellsHtml = days.slice(1).map(day => {
      let weatherHtml = "";
      if (dateOffset === 0 && showWeather && lastWeatherData && lastWeatherData.daily) {
        const dayWeather = lastWeatherData.daily.find(dw => dw.day === day.dateStr);
        if (dayWeather) {
          const iconClass = wmoIcons[dayWeather.code] || "wi-cloudy";
          const desc = wmoCodes[dayWeather.code] ? wmoCodes[dayWeather.code][lang] : "Weather";
          weatherHtml = `
            <div class="grid-weather">
              <div class="grid-weather-label">
                <span>${desc}</span>
              </div>
              <i class="wi ${iconClass}"></i>
              <span>${dayWeather.max}${lastWeatherData.unit}</span>
            </div>
          `;
        }
      }

      return `
        <div class="grid-cell ${day.isOut ? 'out-of-horizon' : ''}">
          <div class="grid-date-num">${day.dayNum}</div>
          <div class="grid-day-label">${day.dayLabel}</div>
          <ul class="grid-event-list">
            ${day.events.slice(0, 3).map(e => `
              <li class="grid-event-item" title="${e.summary}">
                <span style="font-weight: 700; font-size: 12px; display: block; line-height: 1;">${e.is_allday ? t.AllDay : formatTime(e.start_iso) + " — " + formatTime(e.end_iso)}</span>
                ${e.summary}
              </li>
            `).join('')}
            ${day.events.length > 3 ? `<li style="font-size:10px; color:#999;">+ ${day.events.length - 3} more</li>` : ''}
          </ul>
          ${weatherHtml}
        </div>
      `;
    }).join('');

    mainEl.innerHTML = `
      <div class="grid-container">
        ${mergedCellHtml}
        ${dayCellsHtml}
      </div>
    `;
    
    const eventEl = mainEl.querySelector("#event");
    // eventEl is not needed in the detailed merged view, we list events directly
    // renderEventInfo(data, eventEl, t); // Removing to avoid confusion

  } else if (view === "dashboard" || view === "room") {
    const isRoom = (view === "room");
    
    // 1. Upcoming list (always shown now)
    let upcomingHtml = "";
    if (data.upcoming && data.upcoming.length > 0) {
      const nowTs = data.now_ts;
      const futureEvents = data.upcoming.filter(e => e.end_ts > nowTs);

      let upcomingLimit = isRoom ? 3 : 4;
      if (!showRss) upcomingLimit += 1;
      if (!showWeather) upcomingLimit += 2;
      
      upcomingHtml = `
        <div class="room-name" style="position:static; font-size:16px; margin-bottom:8px; color:#666; letter-spacing:1px;">${t.Upcoming}</div>
        <ul class="upcoming-list">
          ${futureEvents.slice(0, upcomingLimit).map(ev => `
            <li class="upcoming-item">
              <div class="upcoming-date">${ev.is_today ? t.Today : ev.date}</div>
              <div class="upcoming-summary">${ev.summary}</div>
              <div class="upcoming-time">${ev.is_allday ? t.AllDay : formatTime(ev.start_iso) + " — " + formatTime(ev.end_iso)}</div>
            </li>
          `).join('')}
        </ul>
      `;
    }

    // 2. Status & Event sizing
    const statusSize = isRoom ? "96px" : "52px";
    const eventSize = isRoom ? "36px" : "28px";
    const roomNameSize = isRoom ? "24px" : "20px";

    mainEl.innerHTML = `
      <div class="room-name" style="position:static; margin-bottom: 20px; font-size: ${roomNameSize};">${headerLabel}</div>
      <div class="dashboard-container">
        <div class="dashboard-left" id="dashboard-left-content">
          <div id="status" class="status" style="font-size: ${statusSize}; margin-bottom: 16px;">${t[data.status]}</div>
          <div id="event" class="event" style="font-size: ${eventSize};"></div>
        </div>
        <div class="dashboard-right">
          ${upcomingHtml}
        </div>
      </div>
    `;
    
    const eventEl = mainEl.querySelector("#event");
    renderEventInfo(data, eventEl, t);
  }
}

function renderEventInfo(data, eventEl, t) {
  if (!eventEl) return;
  const isPersonal = (view === "dashboard" || view === "grid");
  const nextLabel = isPersonal ? t.NextEvent : t.NextMeeting;
  const noLabel   = isPersonal ? t.NoEvents : t.NoMeetings;
  const currentLabel = statusLabel;

  if (data.status === "IN_USE" && data.current) {
    if (data.current.is_allday) {
      eventEl.innerHTML = `
        <div style="color:#666; margin-bottom:4px;">${currentLabel}:</div>
        <strong>${data.current.summary}</strong>
        <div style="margin-top: 12px; font-size: 1em;">
          ${t.AllDay}
        </div>
      `;
    } else {
      const mins = minutesUntil(data.current.ends_iso, data.now);
      eventEl.innerHTML = `
        <div style="color:#666; margin-bottom:4px;">${currentLabel}:</div>
        <strong>${data.current.summary}</strong>
        <div style="margin-top: 12px; font-size: 1em;">
          ${t.EndsIn(mins)}<br>
          <span style="font-size: 0.85em;">
            ${t.At} ${formatTime(data.current.ends_iso)}
          </span>
        </div>
      `;
    }
  } else if (data.next) {
    if (data.next.is_allday) {
      eventEl.innerHTML = `
        <div style="color:#666; margin-bottom:4px;">${nextLabel}:</div>
        <strong>
          ${data.next.date}<br>
          ${data.next.summary}
        </strong>
      `;
    } else {
      const dur = data.next.duration + "m";
      eventEl.innerHTML = data.next.same_day
        ? `
          <div style="color:#666; margin-bottom:4px;">${t.Next}:</div>
          <strong>
            ${formatTime(data.next.start_iso)} — ${formatTime(data.next.end_iso)} (${dur}) : ${data.next.summary}
          </strong>
        `
        : `
          <div style="color:#666; margin-bottom:4px;">${nextLabel}:</div>
          <strong>
            ${data.next.date} @ ${formatTime(data.next.start_iso)} — ${formatTime(data.next.end_iso)} (${dur})<br>
            ${data.next.summary}
          </strong>
        `;
    }
  } else {
    eventEl.innerHTML = `<div style="color:#666;">${noLabel}</div>`;
  }
}

/* ---------- FETCH CALENDAR ---------- */
const userIdParam = urlParams.get('userid') ? `&userid=${encodeURIComponent(urlParams.get('userid'))}` : '';
const calOverrides = urlParams.getAll('cal').map(c => `&cal=${encodeURIComponent(c)}`).join('');
fetch(`./calendar.php?room=${roomId}${userIdParam}${calOverrides}`)
  .then(r => r.json())
  .then(data => {
    lastData = data;
    renderCalendar(data);
  })
  .catch(() => {
    const statusEl = document.getElementById("status");
    if (statusEl) statusEl.textContent = "Calendar unavailable";
  });

/* ---------- FETCH WEATHER ---------- */
const wmoCodes = {
  0: { en: "Clear sky", fr: "Ciel dégagé" },
  1: { en: "Mainly clear", fr: "Principalement dégagé" },
  2: { en: "Partly cloudy", fr: "Partiellement nuageux" },
  3: { en: "Overcast", fr: "Couvert" },
  45: { en: "Foggy", fr: "Brouillard" },
  48: { en: "Foggy", fr: "Brouillard" },
  51: { en: "Light drizzle", fr: "Bruine légère" },
  53: { en: "Drizzle", fr: "Bruine" },
  55: { en: "Heavy drizzle", fr: "Forte bruine" },
  61: { en: "Light rain", fr: "Pluie légère" },
  63: { en: "Rain", fr: "Pluie" },
  65: { en: "Heavy rain", fr: "Forte pluie" },
  71: { en: "Light snow", fr: "Neige légère" },
  73: { en: "Snow", fr: "Neige" },
  75: { en: "Heavy snow", fr: "Forte neige" },
  80: { en: "Rain showers", fr: "Averses" },
  95: { en: "Thunderstorm", fr: "Orage" }
};

const wmoIcons = {
  0: "wi-day-sunny",
  1: "wi-day-cloudy",
  2: "wi-cloud",
  3: "wi-cloudy",
  45: "wi-fog",
  48: "wi-fog",
  51: "wi-sprinkle",
  53: "wi-sprinkle",
  55: "wi-sprinkle",
  61: "wi-rain",
  63: "wi-rain",
  65: "wi-rain",
  71: "wi-snow",
  73: "wi-snow",
  75: "wi-snow",
  80: "wi-showers",
  95: "wi-thunderstorm"
};

function fetchWeather() {
  if (!showWeather) return;
  fetch(`./weather.php?lat=${weatherLat}&lon=${weatherLon}&city=${encodeURIComponent(weatherCity)}`)
    .then(r => r.json())
    .then(data => {
      if (data.error) throw new Error(data.error);
      lastWeatherData = data;
      
      // Update Current Widget (if visible)
      if (showWeatherWidget) {
        const tempEl = document.getElementById("weather-temp");
        const descEl = document.getElementById("weather-desc");
        const iconEl = document.getElementById("weather-icon");
        
        if (tempEl) tempEl.textContent = `${data.temp}${data.unit}`;
        if (descEl) descEl.textContent = wmoCodes[data.code] ? wmoCodes[data.code][lang] : "Weather";
        if (iconEl) {
          const iconClass = wmoIcons[data.code] || "wi-cloudy";
          iconEl.innerHTML = `<i class="wi ${iconClass}"></i>`;
        }

        // Update Forecast Widget (if visible)
        const forecastEl = document.getElementById("weather-forecast");
        if (forecastEl && data.daily && data.daily.length > 0) {
          forecastEl.innerHTML = data.daily.slice(1, 4).map(day => {
            const date = new Date(day.day + "T00:00:00");
            const dayName = date.toLocaleDateString(lang === 'en' ? 'en-CA' : 'fr-CA', { 
              weekday: 'short',
              timeZone: serverTimezone
            });
            const dayIcon = wmoIcons[day.code] || "wi-cloudy";
            return `
              <div class="forecast-day">
                <span style="text-transform:uppercase;">${dayName}</span>
                <i class="wi ${dayIcon}"></i>
                <span>${day.max}${data.unit}</span>
              </div>
            `;
          }).join('');
        }
      }

      // If in grid view, re-render to show integrated weather
      if (view === "grid") renderCalendar(lastData);
    })
    .catch(() => {
      const descEl = document.getElementById("weather-desc");
      if (descEl) descEl.textContent = "Weather unavailable";
    });
}

fetchWeather();
setInterval(fetchWeather, REFRESH.weather);

/* ---------- FETCH NEWS ---------- */
let newsItems = [];
let newsIndex = 0;
let newsCharOffset = 0;
const SEGMENT_SIZE = 60; // Approximate characters that fit the width

function rotateNews() {
  if (newsItems.length === 0) return;
  
  const item = newsItems[newsIndex];
  const headline = item.title;
  const headlineEl = document.getElementById("news-headline");
  const sourceEl = document.getElementById("news-source");
  
  if (headlineEl) {
      if (headline.length <= SEGMENT_SIZE) {
        // Fits in one go
        setText(headlineEl, headline);
        newsIndex = (newsIndex + 1) % newsItems.length;
        newsCharOffset = 0;
      } else {
        // Needs paging
        const segment = headline.substring(newsCharOffset, newsCharOffset + SEGMENT_SIZE);
        const hasMore = (newsCharOffset + SEGMENT_SIZE) < headline.length;

        setText(headlineEl, (newsCharOffset > 0 ? "... " : "") + segment + (hasMore ? " ..." : ""));

        if (hasMore) {
          newsCharOffset += (SEGMENT_SIZE - 10); // Overlap 10 chars for readability
        } else {
          newsIndex = (newsIndex + 1) % newsItems.length;
          newsCharOffset = 0;
        }
      }
  }
  setText(sourceEl, item.source || "");
}

function fetchNews() {
  fetch(`./rss.php?lang=${lang}`)
    .then(r => r.json())
    .then(data => {
      if (Array.isArray(data)) {
        newsItems = data;
        newsIndex = 0;
        newsCharOffset = 0;
        rotateNews();
      }
    })
    .catch(() => {
      const headlineEl = document.getElementById("news-headline");
      if (headlineEl) headlineEl.textContent = "News unavailable";
    });
}

if (showRss) {
  fetchNews();
  // The ticker is the most frequent repaint on the page; power-save slows it
  // rather than removing it, so the panel still shows fresh headlines.
  setInterval(rotateNews, REFRESH.newsRotate);
  setInterval(fetchNews, REFRESH.news);
}
updateUI();
    </script>

</body>

</html>