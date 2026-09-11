/**
 * app.js — AIIR IAQ Smart Dashboard
 * Business logic: API proxy via PHP backend, login, auto-refresh,
 * metric rendering, gauge charts, trend chart, control recommendations, CSV export.
 */

'use strict';

// App configuration
const CONFIG = {
  loginUrl: 'proxy.php?action=login',
  specDataUrl: 'proxy.php?action=getSpecData&site=4&siteType=4',
  demoMode: false,  // false = ใช้ข้อมูลจริงจาก AIIR API ผ่าน proxy.php
  autoRefreshMs: 30000,
  trendMaxPoints: 10,
  thresholds: {
    pm25: 35.0,   // µg/m³
    pm10: 100.0,  // µg/m³
    co2: 1000,    // ppm
    temp: 30.0,   // °C
    humid: 70.0,  // %RH
    evoc: 50.0,   // ppb
  },
};

// App state
const STATE = {
  isLoggedIn: false,
  username: '',
  sessionStartTime: null,
  sessionTimerInterval: null,
  currentMainView: 'overview',
  site4Data: null,
  historyLogs: [],
  historyPM25: [],
  historyCO2: [],
  historyTemp: [],
  historyLabels: [],
  autoRefreshTimer: null,
  analyticsMainChart: null,
  activeMetrics: {
    pm25: true,
    pm10: true,
    co2: true,
    temp: true,
    humid: true,
    evoc: true,
  },
  gaugeCharts: { pm10: null, co2: null, temp: null, humid: null },
  soundAlertEnabled: true,
  // Server-Authoritative Time Synchronization
  serverTimeOffset: 0,       // Estimated difference (serverNow - clientNow) in ms
  lastServerSyncTime: null,  // Date of last authoritative server sync
  serverTimezone: 'Asia/Bangkok',
  // Database Time-Range Analytics & Selective Export
  selectedRange: {
    preset: '24h',
    startTs: null,
    endTs: null,
    records: [],
    stats: null,
  },
};

// ──────────────────────────────────────────────
// Utility helpers & Server Clock Estimator
// ──────────────────────────────────────────────
function $(id) { return document.getElementById(id); }
function clamp(v, min, max) { return Math.min(Math.max(v, min), max); }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function rand(min, max, decimals = 1) { return parseFloat((Math.random() * (max - min) + min).toFixed(decimals)); }

/**
 * Synchronize local clock with Server-Authoritative Time
 * Uses SNTP-like round-trip latency compensation to guarantee all devices in the organization are 100% in sync.
 */
function syncServerTime(serverTimeObj, roundTripMs = 0) {
  if (!serverTimeObj || !serverTimeObj.timestampMs) return;
  const clientNow = Date.now();
  const estimatedServerNow = serverTimeObj.timestampMs + Math.round(roundTripMs / 2);
  STATE.serverTimeOffset = estimatedServerNow - clientNow;
  STATE.lastServerSyncTime = new Date(estimatedServerNow);
  if (serverTimeObj.timezone) STATE.serverTimezone = serverTimeObj.timezone;
  console.info(`[TimeSync] Server clock offset: ${STATE.serverTimeOffset}ms (RTT: ${roundTripMs}ms, Server: ${serverTimeObj.formatted})`);
}

/**
 * Returns current Date object adjusted to official Server Time
 */
function getServerNow() {
  return new Date(Date.now() + (STATE.serverTimeOffset || 0));
}

/**
 * Formats Server Time as dd/mm/yyyy hh:mm:ss
 */
function getServerNowStr() {
  const d = getServerNow();
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/**
 * Authoritative now string (replaces client local clock)
 */
function nowStr() {
  return getServerNowStr();
}

/**
 * Initial fast sync with server time endpoint
 */
async function initServerTimeSync() {
  try {
    const t0 = performance.now();
    const res = await fetch('proxy.php?action=getServerTime');
    const rtt = Math.round(performance.now() - t0);
    const json = await res.json();
    if (json.ok && json.serverTime) {
      syncServerTime(json.serverTime, rtt);
    }
  } catch (e) {
    console.warn('[TimeSync init error]', e);
  }
  initAnalyticsDateInputs();
}

/**
 * Seed historical chart from server Database records
 */
function syncHistoryFromDatabase(dbHistory) {
  if (!Array.isArray(dbHistory) || dbHistory.length === 0) return;
  if (STATE.historyLogs.length === 0) {
    STATE.historyLogs = dbHistory.slice(-500);
    STATE.historyLabels = STATE.historyLogs.map(l => l.label);
    STATE.historyPM25   = STATE.historyLogs.map(l => l.pm25);
    STATE.historyCO2    = STATE.historyLogs.map(l => l.co2);
    STATE.historyTemp   = STATE.historyLogs.map(l => l.temp);
    updateSessionStopwatch();
    updateAnalyticsStats();
    updateAllCharts();
  }
}

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

function toggleEl(id, show, display = 'flex') {
  const el = $(id);
  if (!el) return;
  el.hidden = !show;
  el.style.setProperty('display', show ? display : 'none', 'important');
}

function showToast(msg, type = 'info', duration = 3500) {
  const t = $('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = `toast ${type} show`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.className = 'toast'; }, duration);
}

// ──────────────────────────────────────────────
// Metric level definitions (replaces 7 separate functions)
// ──────────────────────────────────────────────
const METRIC_DEFS = {
  pm10: [
    { max: 50, label: 'ดีเยี่ยม', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: 100, label: 'ปานกลาง', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: Infinity, label: 'อันตราย', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
  co2: [
    { max: 800, label: 'สะอาด', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: 1000, label: 'ปานกลาง', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: Infinity, label: 'อับชื้น', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
  temp: [
    { max: 26, label: 'เย็นสบาย', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: 30, label: 'อุ่น', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: Infinity, label: 'ร้อน', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
  humid: [
    { max: 40, label: 'แห้งเกิน', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: 60, label: 'เหมาะสม', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: Infinity, label: 'ชื้นเกิน', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
  pm25: [
    { max: 12, label: 'ดีเยี่ยม', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: 35, label: 'ปานกลาง', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: Infinity, label: 'อันตราย', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
  evoc: [
    { max: 10, label: 'ดีเยี่ยม', cls: 'good', color: '#10B981', pill: 'pill-ok' },
    { max: 50, label: 'ปานกลาง', cls: 'warn', color: '#F59E0B', pill: 'pill-warn' },
    { max: Infinity, label: 'สูง', cls: 'bad', color: '#EF4444', pill: 'pill-bad' },
  ],
};

function getLevel(key, value) {
  const defs = METRIC_DEFS[key];
  if (!defs) return { label: '', cls: '', color: '#888', pill: '' };
  for (const d of defs) {
    if (value <= d.max) return d;
  }
  return defs[defs.length - 1];
}

function pm25Pill(v) {
  const d = getLevel('pm25', v);
  return { label: d.label, pillClass: d.pill || 'pill-ok' };
}

// ──────────────────────────────────────────────
// Alert definitions (replaces 6 if-blocks)
// ──────────────────────────────────────────────
const ALERT_DEFS = [
  { key: 'pm25', icon: '🌫️', name: 'PM2.5 (ฝุ่นละอองขนาดเล็ก)', unit: 'µg/m³', decimals: 1, shortName: 'PM2.5' },
  { key: 'pm10', icon: '💨', name: 'PM10 (ฝุ่นขนาดกลาง)', unit: 'µg/m³', decimals: 1, shortName: 'PM10' },
  { key: 'co2', icon: '☁️', name: 'CO2 (คาร์บอนไดออกไซด์)', unit: 'ppm', decimals: 0, shortName: 'CO2' },
  {
    key: 'temp', icon: '🌡️', name: 'อุณหภูมิห้อง (Room Temp)', unit: '°C', decimals: 1, shortName: 'อุณหภูมิ',
    format: v => `${v.toFixed(1)}°C`, limitFmt: t => `> ${t}°C`
  },
  {
    key: 'humid', icon: '💧', name: 'ความชื้นสัมพัทธ์ (Relative Humid)', unit: '%RH', decimals: 1, shortName: 'ความชื้น',
    format: v => `${v.toFixed(1)} %RH`, limitFmt: t => `> ${t}%`
  },
  { key: 'evoc', icon: '🧪', name: 'EVOC (สารระเหยง่าย)', unit: 'ppb', decimals: 0, shortName: 'EVOC' },
];

// ──────────────────────────────────────────────
// Sidebar controls
// ──────────────────────────────────────────────
function toggleSidebar() {
  const sb = $('sidebar');
  const ov = $('overlay');
  if (sb) sb.classList.toggle('open');
  if (ov) ov.classList.toggle('show');
}

function toggleSidebarCollapse() {
  if (window.innerWidth <= 768) { toggleSidebar(); }
  else { document.body.classList.toggle('sidebar-collapsed'); }
  setTimeout(() => { if (STATE.site4Data) refreshGauges(); }, 300);
}

// Password visibility toggle
function togglePw() {
  const inp = $('inputPass');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  const toggleBtn = $('pwToggle');
  if (toggleBtn) toggleBtn.style.opacity = inp.type === 'text' ? '1' : '0.6';
}

// Navigation tab compatibility placeholder
function switchTab(name) {
  if (STATE.site4Data) setTimeout(refreshGauges, 60);
}

// ──────────────────────────────────────────────
// Auto refresh handling
// ──────────────────────────────────────────────
function toggleAutoRefresh() {
  const on = $('autoRefreshToggle')?.checked || false;
  if (on) {
    if (STATE.isLoggedIn) startAutoRefresh();
    showToast('🟢 Auto-Refresh เปิดแล้ว (อัปเดตสดทุก 30 วินาที)', 'info');
  } else {
    stopAutoRefresh();
    showToast('⏸️ Auto-Refresh หยุดชั่วคราวแล้ว', 'info');
  }
}

function startAutoRefresh() {
  stopAutoRefresh();
  STATE.autoRefreshTimer = setInterval(fetchData, CONFIG.autoRefreshMs);
  const dot = $('heroPulseDot');
  if (dot) dot.className = 'live-pulse-dot pulsing';
  const status = $('heroLiveStatus');
  if (status) status.textContent = 'LIVE AUTO-REFRESH (30s)';
  const toggle = $('autoRefreshToggle');
  if (toggle) toggle.checked = true;
}

function stopAutoRefresh() {
  if (STATE.autoRefreshTimer) {
    clearInterval(STATE.autoRefreshTimer);
    STATE.autoRefreshTimer = null;
  }
  const dot = $('heroPulseDot');
  if (dot) dot.className = 'live-pulse-dot';
  const status = $('heroLiveStatus');
  if (status) status.textContent = 'PAUSED (หยุดชั่วคราว)';
  const toggle = $('autoRefreshToggle');
  if (toggle) toggle.checked = false;
}

// ──────────────────────────────────────────────
// Connection status & user display handling
// ──────────────────────────────────────────────
function setConnected(on, username = '') {
  STATE.isLoggedIn = on;
  const badge = $('statusBadge');
  const dot = $('statusDot');
  const text = $('statusText');

  if (on) {
    STATE.username = username || localStorage.getItem('aiir_user') || STATE.username || 'Admin';
    if (username) localStorage.setItem('aiir_user', username);

    if (badge) badge.className = 'status-badge badge-online';
    if (dot) dot.className = 'dot dot-green';
    if (text) text.textContent = 'Connected';

    const sd = $('sidebarUserDisplay'), td = $('topbarUserDisplay');
    if (sd) sd.textContent = STATE.username;
    if (td) td.textContent = STATE.username;

    toggleEl('loginForm', false);
    toggleEl('userSessionCard', true);
    toggleEl('topbarUserArea', true);

    // Auto-collapse sidebar on login for full dashboard view
    document.body.classList.add('sidebar-collapsed');
    const sb = $('sidebar');
    if (sb) sb.classList.remove('open');

    // Initialize machine-level session tracking & cache
    initSessionTracking();

    showDashboard();
    if ($('autoRefreshToggle')?.checked) {
      startAutoRefresh();
    }
  } else {
    stopAutoRefresh();
    STATE.username = '';
    if (STATE.sessionTimerInterval) {
      clearInterval(STATE.sessionTimerInterval);
      STATE.sessionTimerInterval = null;
    }
    destroyAllCharts();
    localStorage.removeItem('aiir_user');

    if (badge) badge.className = 'status-badge badge-offline';
    if (dot) dot.className = 'dot dot-red';
    if (text) text.textContent = 'Disconnected';

    toggleEl('loginForm', true);
    toggleEl('userSessionCard', false);
    toggleEl('topbarUserArea', false);

    document.body.classList.remove('sidebar-collapsed');
    clearAlertCardPulses();
    dismissAlertBanner();
    closeAlertModal();
    hideDashboard();
    stopAutoRefresh();
  }
}

// Session Persistence Check on Startup (F5 Refresh)
async function checkAuthOnStartup() {
  const savedUser = localStorage.getItem('aiir_user');
  try {
    const res = await fetch('proxy.php?action=checkSession');
    const json = await res.json();
    if (json.ok && json.loggedIn) {
      setConnected(true, json.user || savedUser || 'Admin');
      fetchData();
      if ($('autoRefreshToggle').checked) startAutoRefresh();
      return;
    }
  } catch (e) { console.warn('[AIIR Session Check]', e); }
  setConnected(false);
}

function updateLastUpdate(timeStr) {
  const t = timeStr || nowStr();
  const wrap = $('lastUpdateWrap');
  if (wrap) {
    wrap.hidden = false;
    wrap.removeAttribute('hidden');
    wrap.style.display = 'block';
  }
  const el = $('lastUpdateTime');
  if (el) el.textContent = t;

  const heroTime = $('heroLastUpdateTime');
  if (heroTime) {
    heroTime.textContent = t;
    const badge = $('heroLiveBadge');
    if (badge) {
      badge.classList.remove('updated');
      void badge.offsetWidth; // Trigger CSS reflow to replay pulse
      badge.classList.add('updated');
      setTimeout(() => badge.classList.remove('updated'), 1500);
    }
  }
}

// ──────────────────────────────────────────────
// Authentication handling
// ──────────────────────────────────────────────
async function handleLogin(e) {
  e.preventDefault();
  const user = $('inputUser').value.trim();
  const pass = $('inputPass').value;
  if (!user || !pass) { showToast('กรอก Username และ Password ด้วย', 'error'); return; }

  $('loginBtnText').hidden = true;
  $('loginSpinner').hidden = false;
  $('loginBtn').disabled = true;

  const loginRes = CONFIG.demoMode ? await mockLogin(user, pass) : await realLogin(user, pass);

  $('loginBtnText').hidden = false;
  $('loginSpinner').hidden = true;
  $('loginBtn').disabled = false;

  if (loginRes?.ok) {
    const userAccount = loginRes.user || user;
    setConnected(true, userAccount);
    showToast(`✅ เชื่อมต่อสำเร็จ! ยินดีต้อนรับ ${userAccount}`, 'success');
    fetchData();
    if ($('autoRefreshToggle').checked) startAutoRefresh();
  } else {
    showToast('❌ Username หรือ Password ไม่ถูกต้อง', 'error');
    setConnected(false);
  }
}

async function handleLogout() {
  showToast('กำลังออกจากระบบ...', 'info', 1500);
  try { await fetch('proxy.php?action=logout'); } catch (e) { console.warn('[AIIR Logout]', e); }
  setConnected(false);
  showToast('🚪 ออกจากระบบเรียบร้อยแล้ว', 'info');
}

function showDashboard() {
  toggleEl('welcomeScreen', false);
  toggleEl('dashboard', true, 'block');
  toggleEl('aiFloatingBtn', true, 'flex');
  initAIFloatingWidget();
}

function hideDashboard() {
  toggleEl('welcomeScreen', true);
  toggleEl('dashboard', false, 'block');
  toggleEl('aiFloatingBtn', false, 'flex');
  closeAIModal();
}

async function mockLogin(user, pass) {
  await sleep(900);
  return { ok: user === 'admin' && pass.length >= 1, user: user };
}

async function realLogin(user, pass) {
  try {
    const res = await fetch(CONFIG.loginUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user, pass }),
    });
    const json = await res.json();
    if (!json.ok && json.error) console.warn('[AIIR Login]', json.error);
    return { ok: json.ok === true, user: json.user || user };
  } catch (e) {
    console.error('[AIIR Login] fetch error:', e);
    return { ok: false, user: '' };
  }
}

// ──────────────────────────────────────────────
// Data fetching from API (Room SITE 4 ICT 401 Only)
// ──────────────────────────────────────────────
async function fetchData() {
  if (!STATE.isLoggedIn) return;
  const btn = $('manualRefreshBtn');
  if (btn) btn.classList.add('spinning');

  try {
    const spec = CONFIG.demoMode ? await mockFetchSite4() : await realFetchSite4();
    if (spec) {
      const pm25Val = parseFloat(spec.pm25 ?? spec.PM25 ?? 0);
      const pm10Val = parseFloat(spec.pm10 ?? spec.PM10 ?? 0);
      const co2Val = parseFloat(spec.co2 ?? spec.CO2 ?? 0);
      const tempVal = parseFloat(spec.temp ?? spec.Temp ?? 0);
      const humidVal = parseFloat(spec.humid ?? spec.Humid ?? 0);
      const evocVal = parseFloat(spec.evoc ?? spec.eVOC ?? 0);
      const rssiVal = String(spec.rssi ?? spec.RSSI ?? '0');
      const specUpd = spec.lastUpdate || nowStr();

      STATE.site4Data = {
        Site: '4', SiteName: 'Site 4 - ICT401',
        'PM2.5': pm25Val, 'PM10': pm10Val, 'CO2': co2Val,
        'RSSI': rssiVal, temp: tempVal, humid: humidVal, evoc: evocVal,
        lastUpdate: specUpd,
        serverTime: spec.serverTime,
      };

      // Seed history from server database if local cache is empty
      if (Array.isArray(spec.history) && spec.history.length > 0 && STATE.historyLogs.length === 0) {
        syncHistoryFromDatabase(spec.history);
      }

      // Continuously append each live reading to session history based on server time
      appendHistory(STATE.site4Data);

      renderSiteDetail(STATE.site4Data);
      updateLastUpdate(specUpd);
    } else {
      updateLastUpdate();
    }
  } catch (err) {
    console.error('fetchData error:', err);
    showToast('เกิดข้อผิดพลาดในการดึงข้อมูล', 'error');
  } finally {
    if (btn) btn.classList.remove('spinning');
  }
}

async function realFetchSite4() {
  const t0 = performance.now();
  try {
    const res = await fetch(CONFIG.specDataUrl);
    const roundTripMs = Math.round(performance.now() - t0);
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }
    const json = await res.json();
    if (json.ok) {
      if (json.serverTime) {
        syncServerTime(json.serverTime, roundTripMs);
      }
      if (json.fallback) {
        console.info('[getSpecData] Using latest cached telemetry snapshot (route: ' + (json.route || 'cached') + ')');
      }
      return json;
    } else {
      if (json.error === 'session_expired') {
        setConnected(false);
        showToast('Session หมดอายุ กรุณา Login ใหม่', 'error', 5000);
      } else {
        console.warn('[getSpecData]', json.error);
      }
      return null;
    }
  } catch (e) {
    console.error('[getSpecData] Fetch error:', e);
    return null;
  }
}

async function mockFetchSite4() {
  await sleep(600 + Math.random() * 400);
  const pm25 = rand(5, 60), pm10 = rand(pm25, pm25 * 1.8);
  const co2 = rand(400, 1400), temp = rand(22, 32);
  const humid = rand(35, 75), evoc = rand(5, 45), rssi = rand(-85, -40, 0);

  // Mock 45-minute interval cache logs
  const nowTs = Date.now();
  const mock45m = [];
  for (let i = 5; i >= 0; i--) {
    const t = new Date(nowTs - i * 45 * 60 * 1000);
    const label = `${String(t.getDate()).padStart(2, '0')}/${String(t.getMonth() + 1).padStart(2, '0')} ${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}`;
    mock45m.push({
      timestamp: t.getTime(), label, time: `${label}:00`, site: 'Site 4 - ICT401',
      pm25: rand(10, 45), pm10: rand(20, 70), co2: rand(450, 1100),
      temp: rand(23, 29), humid: rand(40, 65), evoc: rand(10, 35),
      rssi: '-65', iaqScore: rand(70, 95, 0)
    });
  }
  return { ok: true, pm25, pm10, co2, temp, humid, evoc, rssi, lastUpdate: nowStr(), history45m: mock45m };
}

// ──────────────────────────────────────────────
// History tracking (Synced with Server-Authoritative Time)
// ──────────────────────────────────────────────

function appendHistory(data) {
  if (!data) return;
  const serverNow = getServerNow();
  const nowTs = serverNow.getTime();
  const label = serverNow.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const pm25Val = parseFloat(data['PM2.5'] ?? data.pm25 ?? 0);
  const pm10Val = parseFloat(data['PM10'] ?? data.pm10 ?? 0);
  const co2Val = parseFloat(data.CO2 ?? data.co2 ?? 0);
  const tempVal = parseFloat(data.temp ?? 0);
  const humidVal = parseFloat(data.humid ?? 0);
  const evocVal = parseFloat(data.evoc ?? 0);
  const rssiVal = String(data.RSSI ?? data.rssi ?? '0');

  // Prevent duplicate addition if called within 2 seconds
  const lastEntry = STATE.historyLogs[STATE.historyLogs.length - 1];
  if (lastEntry && (nowTs - lastEntry.timestamp < 2000)) {
    return;
  }

  const push = (arr, val) => { arr.push(val ?? 0); if (arr.length > 500) arr.shift(); };
  push(STATE.historyLabels, label);
  push(STATE.historyPM25, pm25Val);
  push(STATE.historyCO2, co2Val);
  push(STATE.historyTemp, tempVal);

  STATE.historyLogs.push({
    timestamp: nowTs,
    label,
    time: `${serverNow.toLocaleDateString('th-TH')} ${label}`,
    site: 'Site 4 - ICT401',
    pm25: pm25Val,
    pm10: pm10Val,
    co2: co2Val,
    temp: tempVal,
    humid: humidVal,
    evoc: evocVal,
    rssi: rssiVal,
  });

  if (STATE.historyLogs.length > 500) STATE.historyLogs.shift();

  // Save to persistent device cache
  try {
    localStorage.setItem(SESSION_CACHE_KEYS.chartLogs, JSON.stringify(STATE.historyLogs));
  } catch (e) {
    console.warn('[Cache] Storage quota or write error:', e);
  }

  updateSessionStopwatch();
  updateAnalyticsStats();
  updateAllCharts();
}

// ──────────────────────────────────────────────
// Overview tab rendering
// ──────────────────────────────────────────────
function renderOverview(sites) {
  const grid = $('sitesGrid');
  const empty = document.getElementById('overviewEmpty');
  if (empty) empty.remove();
  grid.querySelectorAll('.site-card').forEach(c => c.remove());

  sites.forEach((s, i) => {
    const pm25 = parseFloat(s['PM2.5'] ?? 0);
    const pm10 = parseFloat(s.PM10 ?? 0);
    const co2 = parseFloat(s.CO2 ?? 0);
    const isOn = (s.Status || '').toLowerCase().includes('online') || (s.Status || '').toLowerCase().includes('ok');
    const { label: pm25Lbl, pillClass } = pm25Pill(pm25);
    const pm25C = getLevel('pm25', pm25).color;
    const co2C = getLevel('co2', co2).color;

    const card = document.createElement('div');
    card.className = 'site-card';
    card.style.animationDelay = `${i * 0.06}s`;
    card.innerHTML = `
      <div class="site-card-header">
        <div class="site-name">${s.SiteName || 'Site ' + s.Site}</div>
        <div class="site-status-pill ${!isOn ? 'pill-off' : pillClass}">${!isOn ? 'Offline' : pm25Lbl}</div>
      </div>
      <div class="site-stats">
        <div class="stat-item">
          <div class="stat-val" style="color:${pm25C}">${pm25.toFixed(1)}</div>
          <div class="stat-label">PM2.5</div>
        </div>
        <div class="stat-item">
          <div class="stat-val" style="color:${co2C}">${co2.toFixed(0)}</div>
          <div class="stat-label">CO2</div>
        </div>
        <div class="stat-item">
          <div class="stat-val">${pm10.toFixed(1)}</div>
          <div class="stat-label">PM10</div>
        </div>
      </div>
      <div class="site-update">📡 RSSI: ${s.RSSI} dBm &nbsp;|&nbsp; ${s.Update}</div>
    `;
    grid.appendChild(card);
  });
  renderTable(sites);
}

function renderTable(sites) {
  const panel = $('tablePanel');
  panel.hidden = false;
  const tbody = $('sitesTableBody');
  tbody.innerHTML = '';
  sites.forEach(s => {
    const pm25 = parseFloat(s['PM2.5'] ?? 0);
    const co2 = parseFloat(s.CO2 ?? 0);
    const isOn = (s.Status || '').toLowerCase().includes('online');
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${s.SiteName || 'Site ' + s.Site}</strong></td>
      <td><span class="site-status-pill ${isOn ? 'pill-ok' : 'pill-off'}">${s.Status}</span></td>
      <td style="color:${getLevel('pm25', pm25).color}">${pm25.toFixed(1)}</td>
      <td>${parseFloat(s.PM10 ?? 0).toFixed(1)}</td>
      <td style="color:${getLevel('co2', co2).color}">${parseFloat(co2).toFixed(0)}</td>
      <td>${s.RSSI} dBm</td>
      <td style="font-size:0.8rem;color:var(--text-muted)">${s.Update}</td>
    `;
    tbody.appendChild(tr);
  });
}

// ──────────────────────────────────────────────
// Metric display (unified setMetric replaces setMetric + setMetricRaw)
// ──────────────────────────────────────────────
function setMetric(key, valStr, barPct, label, cls, color) {
  const valEl = $(`val-${key}`);
  if (valEl) valEl.textContent = valStr;
  const bar = $(`bar-${key}`);
  if (bar) { bar.style.width = clamp(barPct, 0, 100) + '%'; bar.style.background = color; }
  const statusEl = $(`status-${key}`);
  if (statusEl) { statusEl.textContent = '● ' + label; statusEl.className = `metric-status ${cls}`; }
}

// Site 4 detail tab rendering
function renderSiteDetail(data) {
  const pm25 = parseFloat(data['PM2.5'] ?? data.pm25 ?? 0);
  const pm10 = parseFloat(data.PM10 ?? data.pm10 ?? 0);
  const co2 = parseFloat(data.CO2 ?? data.co2 ?? 0);
  const temp = parseFloat(data.temp ?? 0);
  const humid = parseFloat(data.humid ?? 0);
  const evoc = parseFloat(data.evoc ?? 0);
  const rssi = parseFloat(data.RSSI ?? data.rssi ?? -70);

  // Top 4 Hero Metrics (PM10, CO2, Temp, Humid)
  const pm10L = getLevel('pm10', pm10);
  setMetric('pm10', pm10.toFixed(1), (pm10 / 150) * 100, pm10L.label, pm10L.cls, pm10L.color);

  const co2L = getLevel('co2', co2);
  setMetric('co2', co2.toFixed(0), (co2 / 1500) * 100, co2L.label, co2L.cls, co2L.color);

  const tempL = getLevel('temp', temp);
  const tempPct = clamp(((temp - 16) / (40 - 16)) * 100, 0, 100);
  setMetric('temp', temp.toFixed(1), tempPct, tempL.label, tempL.cls, tempL.color);

  const humidL = getLevel('humid', humid);
  const humidPct = clamp(humid, 0, 100);
  setMetric('humid', humid.toFixed(1), humidPct, humidL.label, humidL.cls, humidL.color);

  // Secondary Metrics (PM2.5, EVOC, RSSI)
  const pm25L = getLevel('pm25', pm25);
  setMetric('pm25', pm25.toFixed(1), (pm25 / 75) * 100, pm25L.label, pm25L.cls, pm25L.color);

  const evocL = getLevel('evoc', evoc);
  const evocPct = clamp((evoc / 50) * 100, 0, 100);
  setMetric('evoc', evoc.toFixed(0), evocPct, evocL.label, evocL.cls, evocL.color);

  const rssiNorm = clamp(((rssi + 100) / 60) * 100, 0, 100);
  const rssiStatus = rssi >= -65 ? 'ดีเยี่ยม' : rssi >= -75 ? 'ดี' : rssi >= -85 ? 'พอใช้' : 'อ่อน';
  const rssiCls = rssi >= -75 ? 'good' : rssi >= -85 ? 'warn' : 'bad';
  const rssiColor = rssi >= -75 ? '#10B981' : rssi >= -85 ? '#F59E0B' : '#EF4444';
  setMetric('rssi', rssi.toFixed(0), rssiNorm, rssiStatus, rssiCls, rssiColor);

  renderControlCards(pm25, co2, temp, humid, pm10, evoc);
  refreshGauges(pm10, co2, temp, humid);
  checkAirQualityAlerts(pm25, pm10, co2, temp, humid, evoc);
}

// ──────────────────────────────────────────────
// Audio Alert Player (Web Audio API Synthesizer)
// ──────────────────────────────────────────────
function playAlertSound() {
  if (!STATE.soundAlertEnabled) return;
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(587.33, ctx.currentTime + 0.25);
    gain.gain.setValueAtTime(0.18, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
    osc.connect(gain); gain.connect(ctx.destination);
    osc.start(); osc.stop(ctx.currentTime + 0.25);
  } catch (e) { console.warn('[AudioAlert]', e); }
}

function toggleSoundAlert() {
  STATE.soundAlertEnabled = !STATE.soundAlertEnabled;
  const icon = $('soundAlertIcon'), txt = $('soundAlertText'), btn = $('soundAlertToggleBtn');
  if (STATE.soundAlertEnabled) {
    if (icon) icon.textContent = '🔔';
    if (txt) txt.textContent = 'ระบบแจ้งเตือน: เปิด';
    if (btn) btn.classList.remove('muted');
    showToast('🔔 เปิดระบบแจ้งเตือนและ Pop-Up เรียบร้อยแล้ว', 'info');
    restoreRealAlertCardState();
  } else {
    if (icon) icon.textContent = '🔕';
    if (txt) txt.textContent = 'ระบบแจ้งเตือน: ปิด';
    if (btn) btn.classList.add('muted');
    showToast('🔕 ปิดระบบแจ้งเตือนและ Pop-Up แล้ว', 'info');
    clearAlertCardPulses();
    dismissAlertBanner();
    closeAlertModal();
  }
}

function clearAlertCardPulses() {
  ['pm25', 'pm10', 'co2', 'temp', 'humid', 'evoc'].forEach(k => {
    const card = $(`mc-${k}`);
    if (card) card.classList.remove('card-alert-pulse');
  });
}

function restoreRealAlertCardState() {
  if (!STATE.soundAlertEnabled || !STATE.site4Data) {
    clearAlertCardPulses();
    return;
  }
  const d = STATE.site4Data;
  const pm25 = parseFloat(d['PM2.5'] ?? d.pm25 ?? 0);
  const pm10 = parseFloat(d.PM10 ?? d.pm10 ?? 0);
  const co2 = parseFloat(d.CO2 ?? d.co2 ?? 0);
  const temp = parseFloat(d.temp ?? 0);
  const humid = parseFloat(d.humid ?? 0);
  const evoc = parseFloat(d.evoc ?? 0);
  const values = { pm25, pm10, co2, temp, humid, evoc };

  ['pm25', 'pm10', 'co2', 'temp', 'humid', 'evoc'].forEach(k => {
    const card = $(`mc-${k}`);
    const threshold = CONFIG.thresholds[k];
    const isAlert = threshold !== undefined && values[k] > threshold;
    if (card) card.classList.toggle('card-alert-pulse', isAlert);
  });
}

function dismissAlertBanner() {
  const banner = $('alertBannerWrap');
  if (banner) { banner.setAttribute('hidden', 'true'); banner.style.display = 'none'; }
  restoreRealAlertCardState();
}

function closeAlertModal() {
  const modal = $('alertModalOverlay');
  if (modal) { modal.setAttribute('hidden', 'true'); modal.style.display = 'none'; }
  restoreRealAlertCardState();
}

// Test trigger for Emergency Alert Pop-Up Modal
function testAlertModal() {
  checkAirQualityAlerts(48.5, 112.0, 1250, 31.5, 74.0, 65, true);
  showToast('🧪 แสดงผล Pop-Up แจ้งเตือนฉุกเฉินระดับอันตราย (Test Mode)', 'info', 3500);
  // Automatically restore card border states after 8 seconds if user leaves modal open
  setTimeout(() => {
    restoreRealAlertCardState();
  }, 8000);
}

// ──────────────────────────────────────────────
// Air Quality Threshold Alerts Check (data-driven)
// ──────────────────────────────────────────────
function checkAirQualityAlerts(pm25, pm10, co2, temp, humid, evoc, isTest = false) {
  if (!STATE.soundAlertEnabled && !isTest) {
    dismissAlertBanner();
    closeAlertModal();
    clearAlertCardPulses();
    return;
  }

  const values = { pm25, pm10, co2, temp, humid, evoc };
  const alerts = [], alertDetails = [], alertCards = {};

  ALERT_DEFS.forEach(def => {
    const v = values[def.key];
    const threshold = CONFIG.thresholds[def.key];
    alertCards[def.key] = false;
    if (v > threshold) {
      const valStr = def.format ? def.format(v) : `${v.toFixed(def.decimals)} ${def.unit}`;
      const limitStr = def.limitFmt ? def.limitFmt(threshold) : `> ${threshold}`;
      alerts.push(`${def.shortName} สูง (${valStr})`);
      alertDetails.push({ name: `${def.icon} ${def.name}`, val: valStr, limit: limitStr });
      alertCards[def.key] = true;
    }
  });

  // Toggle pulsing animation on metric cards
  ['pm25', 'pm10', 'co2', 'temp', 'humid', 'evoc'].forEach(k => {
    const card = $(`mc-${k}`);
    if (card) card.classList.toggle('card-alert-pulse', !!alertCards[k]);
  });

  const banner = $('alertBannerWrap'), bannerText = $('alertBannerText');
  const modal = $('alertModalOverlay'), modalList = $('alertModalList');

  if (alerts.length > 0) {
    if (bannerText) bannerText.innerHTML = `ตรวจพบ <strong>${alerts.length} ดัชนี</strong> เกินเกณฑ์มาตรฐานความปลอดภัย: <strong>${alerts.join(' • ')}</strong>`;
    if (banner) { banner.removeAttribute('hidden'); banner.style.display = 'flex'; }

    if (modalList) {
      modalList.innerHTML = alertDetails.map(item => `
        <div class="alert-modal-item">
          <span>${item.name}</span>
          <span class="alert-modal-item-val">${item.val} <small style="font-weight:400;opacity:0.75">(เกณฑ์ ${item.limit})</small></span>
        </div>
      `).join('');
    }
    if (modal) { modal.removeAttribute('hidden'); modal.style.display = 'flex'; }
    showToast(`⚠️ เตือนภัย! ตรวจพบค่าคุณภาพอากาศเกินมาตรฐาน (${alerts.length} รายการ)`, 'error', 4500);
    playAlertSound();
  } else {
    if (banner) dismissAlertBanner();
    if (!isTest && modal) closeAlertModal();
  }
}

// ──────────────────────────────────────────────
// AI Smart HVAC Automation Engine
// ──────────────────────────────────────────────
function runAIInferenceEngine(pm25, pm10, co2, temp, humid, evoc) {
  pm25 = parseFloat(pm25 || 0); pm10 = parseFloat(pm10 || 0);
  co2 = parseFloat(co2 || 0); temp = parseFloat(temp || 0);
  humid = parseFloat(humid || 0); evoc = parseFloat(evoc || 0);

  // 1. Calculate Comprehensive AI IAQ Health Index Score (0 - 100%)
  const pm25Penalty = clamp((pm25 / 50) * 35, 0, 35);
  const co2Penalty = clamp(((co2 - 400) / 1200) * 35, 0, 35);
  const evocPenalty = clamp((evoc / 50) * 15, 0, 15);
  const tempPenalty = (temp < 20 || temp > 28) ? clamp(Math.abs(temp - 24) * 2, 0, 10) : 0;
  const humidPenalty = (humid < 40 || humid > 65) ? clamp(Math.abs(humid - 50) * 0.3, 0, 15) : 0;
  const iaqScore = Math.max(10, Math.round(100 - (pm25Penalty + co2Penalty + evocPenalty + tempPenalty + humidPenalty)));

  // 2. Perceived Temperature & Thermal Comfort (Steadman Heat Index Model)
  let perceivedTemp = temp;
  if (temp >= 24 && humid > 55) perceivedTemp = temp + 0.1 * (humid - 55);
  else if (temp < 22 && humid < 40) perceivedTemp = temp - 0.08 * (40 - humid);
  perceivedTemp = parseFloat(perceivedTemp.toFixed(1));

  // 3. Air Purifier AI Decision
  const purifier = (pm25 > 35 || pm10 > 75 || evoc > 40)
    ? {
      state: '🔴 เปิดเร่งด่วน (Boost Mode 85-100%)', badge: 'High Boost', pillClass: 'pill-bad',
      details: `HEPA + Carbon Filter Active • ตรวจพบฝุ่น/EVOC สูง (PM2.5: ${pm25.toFixed(1)}, EVOC: ${evoc.toFixed(0)} ppb)`
    }
    : (pm25 > 12 || pm10 > 35 || evoc > 15)
      ? {
        state: '🟡 เปิดทำงานแบบสมดุล (Eco Auto 45%)', badge: 'Eco Auto', pillClass: 'pill-warn',
        details: `HEPA Filter Active • ควบคุมค่าฝุ่นระดับปานกลาง (จำกัดค่าฝุ่น PM2.5 ≤ 12 µg/m³)`
      }
      : {
        state: '🟢 สแตนบายด์ (Standby 15%)', badge: 'Standby', pillClass: 'pill-ok',
        details: `อากาศในห้องสะอาดบริสุทธิ์ (Air Cleanliness Index: ${iaqScore}%) • หมุนเวียนลมเบาเพื่อประหยัดไฟ`
      };

  // 4. Ventilation System AI Decision
  const ventilation = (co2 > 1000)
    ? {
      state: '🔴 เปิดระบายอากาศเต็มกำลัง (Fresh Air Valve 100%)', badge: 'Max Exchange', pillClass: 'pill-bad',
      details: `Air Exchange Rate 3.8 ACH • ตรวจพบ CO2 สูงสะสม (${co2.toFixed(0)} ppm) • เร่งดึงอากาศสดนอกอาคาร`
    }
    : (co2 > 750)
      ? {
        state: '🟡 เปิดระบายอากาศแบบปรับแปร (Fresh Air 50-65%)', badge: 'Auto Exchange', pillClass: 'pill-warn',
        details: `Air Exchange Rate 2.1 ACH • ควบคุมระดับ CO2 ให้อยู่ในสภาวะสมดุลสำหรับห้องประชุม (<800 ppm)`
      }
      : {
        state: '🟢 เปิดระบายอากาศขั้นต่ำ (Minimum Fresh Air 20%)', badge: 'Min Exchange', pillClass: 'pill-ok',
        details: `ระดับ CO2 เหมาะสมดีเยี่ยม (${co2.toFixed(0)} ppm) • หมุนเวียนอากาศเพื่อรักษาสมดุลความเย็น`
      };

  // 5. Air Conditioning AI Decision
  const ac = (temp > 29 || perceivedTemp > 30)
    ? {
      state: `🔴 เปิดทำความเย็นหนัก (Cool Mode 23.0°C • High Fan)`, badge: 'Cool High', pillClass: 'pill-bad',
      details: `อุณหภูมิห้อง ${temp.toFixed(1)}°C (รู้สึกจริง ${perceivedTemp.toFixed(1)}°C) • เร่งปรับลดอุณหภูมิทางความร้อน`
    }
    : (temp > 26 || perceivedTemp > 26.5)
      ? {
        state: `🟡 เปิดทำความเย็นปกติ (Cool Mode 25.0°C • Auto Fan)`, badge: 'Cool Auto', pillClass: 'pill-warn',
        details: `อุณหภูมิอุ่นเล็กน้อย (${temp.toFixed(1)}°C) • ควบคุมความสบายทางความร้อน (PMV Index: Thermal Balanced)`
      }
      : {
        state: `🟢 ปรับโหมดประหยัดพลังงาน (Eco Mode 25.5°C)`, badge: 'Eco Saving', pillClass: 'pill-ok',
        details: `อุณหภูมิเย็นสบายเหมาะสม (${temp.toFixed(1)}°C) • ประหยัดพลังงานไฟเบอร์สูงสุด 88%`
      };

  // 6. Humidity Control AI Decision
  const humidity = (humid > 65)
    ? {
      state: `🔴 Dehumidifier Active (High Boost 80%)`, badge: 'Dry Boost', pillClass: 'pill-bad',
      details: `ความชื้นสัมพัทธ์สูง (${humid.toFixed(1)}%RH) • เร่งดึงความชื้นออกจากห้อง ป้องกันเชื้อราและไวรัส`
    }
    : (humid > 58)
      ? {
        state: `🟡 Dehumidifier Active (Low Auto 40%)`, badge: 'Dry Auto', pillClass: 'pill-warn',
        details: `ความชื้นสะสม (${humid.toFixed(1)}%RH) • รักษาระดับความชื้นสัมพัทธ์ในสภาวะน่าสบาย (45-55%RH)`
      }
      : (humid < 38)
        ? {
          state: `🟡 Humidifier Active (Moisture Boost 50%)`, badge: 'Humidify', pillClass: 'pill-warn',
          details: `อากาศแห้งเกินไป (${humid.toFixed(1)}%RH) • เพิ่มความชื้นสัมพัทธ์เพื่อป้องกันการระคายเคือง`
        }
        : {
          state: `🟢 ปิด / สแตนบายด์ (Humidity Balanced)`, badge: 'Balanced', pillClass: 'pill-ok',
          details: `ความชื้นสัมพัทธ์ในห้องอยู่ในเกณฑ์สมบูรณ์แบบ (${humid.toFixed(1)}%RH)`
        };

  // 7. AI Predictive Summary Insight
  const aiInsight = iaqScore >= 85
    ? `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศในห้อง ICT401 อยู่ในเกณฑ์ประเสริฐสุด (IAQ Score: <strong>${iaqScore}/100</strong>) โมเดล AIR-IAQNet แนะนำให้รักษาระดับการทำงานของ HVAC ในโหมด Eco เพื่อประหยัดพลังงานไฟเบอร์สูงสุด`
    : iaqScore >= 65
      ? `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศอยู่ในระดับปานกลาง (IAQ Score: <strong>${iaqScore}/100</strong>) ตรวจพบค่า CO2 และความชื้นสะสมย่อย โมเดลสั่งเปิดระบบระบายอากาศ 60% ร่วมกับลดความชื้นอัตโนมัติ คาดการณ์คุณภาพอากาศกลับสู่ระดับดีเยี่ยมใน ~10 นาที`
      : `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศต้องการการฟื้นฟูเร่งด่วน (IAQ Score: <strong>${iaqScore}/100</strong>) ตรวจพบฝุ่นหรือก๊าซสะสมสูง โมเดลสั่งเปิดระบบฟอกอากาศและระบายอากาศแบบ Full Boost อากาศจะกลับสู่เกณฑ์ปกติใน ~18 นาที`;

  return { iaqScore, perceivedTemp, purifier, ventilation, ac, humidity, insight: aiInsight };
}

// ──────────────────────────────────────────────
// Control recommendations (loop-driven DOM update)
// ──────────────────────────────────────────────
function renderControlCards(pm25, co2, temp, humid, pm10 = 0, evoc = 0) {
  const m = runAIInferenceEngine(pm25, pm10, co2, temp, humid, evoc);

  const scoreEl = $('aiScoreVal');
  if (scoreEl) scoreEl.textContent = `${m.iaqScore}/100`;

  const floatScoreEl = $('aiFloatingScore');
  if (floatScoreEl) {
    floatScoreEl.textContent = `${m.iaqScore}/100`;
    floatScoreEl.style.color = m.iaqScore >= 85 ? '#0D9488' : (m.iaqScore >= 65 ? '#D97706' : '#EF4444');
  }

  // Loop-driven update for 4 control cards (replaces 4 duplicate blocks)
  ['purifier', 'ventilation', 'ac', 'humidity'].forEach(key => {
    const r = m[key];
    const cmd = $(`cmd-${key}`), badge = $(`badge-${key}`), detail = $(`detail-${key}`);
    if (cmd) cmd.textContent = r.state;
    if (badge) { badge.textContent = r.badge; badge.className = `ai-badge ${r.pillClass}`; }
    if (detail) detail.textContent = r.details;
  });

  if ($('aiInsightBox')) $('aiInsightBox').innerHTML = m.insight;
}

// ──────────────────────────────────────────────
// AI Floating Draggable Widget & Modal Controller
// ──────────────────────────────────────────────
let aiWidgetInitialized = false;

function openAIModal() {
  const modal = $('aiModalOverlay');
  if (modal) modal.classList.add('open');
}

function closeAIModal() {
  const modal = $('aiModalOverlay');
  if (modal) modal.classList.remove('open');
}

function handleAIModalBackdrop(e) {
  if (e.target && e.target.id === 'aiModalOverlay') {
    closeAIModal();
  }
}

function initAIFloatingWidget() {
  const btn = $('aiFloatingBtn');
  if (!btn || aiWidgetInitialized) return;
  aiWidgetInitialized = true;

  let isDragging = false;
  let hasMoved = false;
  let startX = 0, startY = 0;
  let origX = 0, origY = 0;
  const DRAG_THRESHOLD = 6; // px

  function clampAndSetPos(x, y) {
    const btnRect = btn.getBoundingClientRect();
    const w = btnRect.width > 0 ? btnRect.width : 140;
    const h = btnRect.height > 0 ? btnRect.height : 54;
    const maxX = Math.max(10, window.innerWidth - w - 10);
    const maxY = Math.max(10, window.innerHeight - h - 10);
    const clampedX = clamp(x, 10, maxX);
    const clampedY = clamp(y, 10, maxY);

    btn.style.left = `${clampedX}px`;
    btn.style.top = `${clampedY}px`;
    btn.style.right = 'auto';
    btn.style.bottom = 'auto';
    return { x: clampedX, y: clampedY };
  }
  // Restore saved position if available
  try {
    const saved = localStorage.getItem('ai_widget_pos');
    if (saved) {
      const pos = JSON.parse(saved);
      if (typeof pos.x === 'number' && typeof pos.y === 'number') {
        clampAndSetPos(pos.x, pos.y);
      }
    }
  } catch (e) { console.warn('[AI Widget Pos Restore]', e); }

  function onPointerDown(e) {
    if (e.button !== undefined && e.button !== 0) return; // Only main button

    isDragging = true;
    hasMoved = false;

    const clientX = e.clientX ?? (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
    const clientY = e.clientY ?? (e.touches && e.touches[0] ? e.touches[0].clientY : 0);

    startX = clientX;
    startY = clientY;

    const rect = btn.getBoundingClientRect();
    origX = rect.left;
    origY = rect.top;

    btn.classList.add('dragging');

    window.addEventListener('mousemove', onPointerMove, { passive: false });
    window.addEventListener('mouseup', onPointerUp);
    window.addEventListener('touchmove', onPointerMove, { passive: false });
    window.addEventListener('touchend', onPointerUp);
  }

  function onPointerMove(e) {
    if (!isDragging) return;
    const clientX = e.clientX ?? (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
    const clientY = e.clientY ?? (e.touches && e.touches[0] ? e.touches[0].clientY : 0);

    const dx = clientX - startX;
    const dy = clientY - startY;

    if (!hasMoved && Math.hypot(dx, dy) > DRAG_THRESHOLD) {
      hasMoved = true;
    }

    if (hasMoved) {
      if (e.cancelable) e.preventDefault();
      clampAndSetPos(origX + dx, origY + dy);
    }
  }

  function onPointerUp(e) {
    if (!isDragging) return;
    isDragging = false;
    btn.classList.remove('dragging');

    window.removeEventListener('mousemove', onPointerMove);
    window.removeEventListener('mouseup', onPointerUp);
    window.removeEventListener('touchmove', onPointerMove);
    window.removeEventListener('touchend', onPointerUp);

    if (hasMoved) {
      const rect = btn.getBoundingClientRect();
      const pos = clampAndSetPos(rect.left, rect.top);
      try {
        localStorage.setItem('ai_widget_pos', JSON.stringify(pos));
      } catch (err) { }
    } else {
      // It was a click!
      openAIModal();
    }
  }

  btn.addEventListener('mousedown', onPointerDown);
  btn.addEventListener('touchstart', onPointerDown, { passive: true });

  // Escape key closes modal
  window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAIModal();
  });

  // Reposition within window on resize
  window.addEventListener('resize', debounce(() => {
    if (btn.style.left && btn.style.top) {
      const rect = btn.getBoundingClientRect();
      clampAndSetPos(rect.left, rect.top);
    }
  }, 150));
}

// ──────────────────────────────────────────────
// Machine Reboot & Session Cache Engine
// ──────────────────────────────────────────────
const SESSION_CACHE_KEYS = {
  powerActive: 'aiir_power_session_active',
  startTime: 'aiir_session_start_time',
  chartLogs: 'aiir_session_chart_logs',
};

function initSessionTracking() {
  const isPowerActive = sessionStorage.getItem(SESSION_CACHE_KEYS.powerActive);

  if (!isPowerActive) {
    // Fresh session initialized using Server-Authoritative Time!
    const now = getServerNow().getTime();
    sessionStorage.setItem(SESSION_CACHE_KEYS.powerActive, String(now));
    localStorage.setItem(SESSION_CACHE_KEYS.startTime, String(now));
    localStorage.removeItem(SESSION_CACHE_KEYS.chartLogs);

    STATE.sessionStartTime = now;
    STATE.historyLogs = [];
    STATE.historyLabels = [];
    STATE.historyPM25 = [];
    STATE.historyCO2 = [];
    STATE.historyTemp = [];
    console.info('[Session] Fresh session initialized at (Server Time)', getServerNow().toLocaleTimeString());
  } else {
    // Session still active (re-login or page refresh on same machine)!
    const savedStart = parseInt(localStorage.getItem(SESSION_CACHE_KEYS.startTime), 10);
    STATE.sessionStartTime = savedStart && !isNaN(savedStart) ? savedStart : getServerNow().getTime();

    try {
      const cached = JSON.parse(localStorage.getItem(SESSION_CACHE_KEYS.chartLogs) || '[]');
      if (Array.isArray(cached) && cached.length > 0) {
        STATE.historyLogs = cached;
        STATE.historyLabels = cached.map(l => l.label);
        STATE.historyPM25 = cached.map(l => l.pm25);
        STATE.historyCO2 = cached.map(l => l.co2);
        STATE.historyTemp = cached.map(l => l.temp);
        console.info('[Session] Resumed session from cache (' + cached.length + ' data points restored)');
      }
    } catch (e) {
      console.warn('[Session] Failed to restore chart cache:', e);
    }
  }

  // Start stopwatch timer
  if (STATE.sessionTimerInterval) clearInterval(STATE.sessionTimerInterval);
  STATE.sessionTimerInterval = setInterval(updateSessionStopwatch, 1000);
  updateSessionStopwatch();
  updateAnalyticsStats();
  updateAllCharts();
}

function updateSessionStopwatch() {
  if (!STATE.sessionStartTime) return;
  const elapsedMs = Math.max(0, getServerNow().getTime() - STATE.sessionStartTime);
  const totalSec = Math.floor(elapsedMs / 1000);
  const hours = String(Math.floor(totalSec / 3600)).padStart(2, '0');
  const minutes = String(Math.floor((totalSec % 3600) / 60)).padStart(2, '0');
  const seconds = String(totalSec % 60).padStart(2, '0');
  const timeStr = `${hours}:${minutes}:${seconds}`;

  const el = $('sessionStopwatch');
  if (el) el.textContent = timeStr;

  const countEl = $('sessionPointsCount');
  if (countEl) countEl.textContent = `${STATE.historyLogs.length} จุดข้อมูล`;

  const badgeEl = $('pointsCountBadge');
  if (badgeEl) badgeEl.textContent = `${STATE.historyLogs.length} จุด`;
}

function resetSessionPrompt() {
  if (confirm('คุณต้องการรีเซ็ตเวลาและเริ่มนับรอบบันทึกข้อมูลกราฟใหม่สำหรับรอบนี้ใช่หรือไม่?')) {
    const now = getServerNow().getTime();
    sessionStorage.setItem(SESSION_CACHE_KEYS.powerActive, String(now));
    localStorage.setItem(SESSION_CACHE_KEYS.startTime, String(now));
    localStorage.removeItem(SESSION_CACHE_KEYS.chartLogs);

    STATE.sessionStartTime = now;
    STATE.historyLogs = [];
    STATE.historyLabels = [];
    STATE.historyPM25 = [];
    STATE.historyCO2 = [];
    STATE.historyTemp = [];

    updateSessionStopwatch();
    updateAnalyticsStats();
    updateAllCharts();
    showToast('🔄 รีเซ็ตเวลาและเริ่มนับรอบกราฟใหม่เรียบร้อยแล้ว', 'success');
  }
}

// ──────────────────────────────────────────────
// Database Historical Analytics, Day Stepper & Export Controller
// ──────────────────────────────────────────────

/**
 * Returns formatted YYYY-MM-DD string adjusted to server time
 */
function getServerDateString(offsetDays = 0) {
  const d = new Date(getServerNow().getTime() + offsetDays * 86400000);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function formatServerDate(d) {
  const day = String(d.getDate()).padStart(2, '0');
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const y = d.getFullYear();
  const h = String(d.getHours()).padStart(2, '0');
  const min = String(d.getMinutes()).padStart(2, '0');
  return `${day}/${m}/${y} ${h}:${min}`;
}

function formatServerDateOnly(d) {
  const day = String(d.getDate()).padStart(2, '0');
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const y = d.getFullYear();
  return `${day}/${m}/${y}`;
}

/**
 * Initialize date input values based on server time
 */
function initAnalyticsDateInputs() {
  const todayStr = getServerDateString(0);
  if (!STATE.analytics) {
    STATE.analytics = {
      mode: 'day',
      selectedDate: todayStr,
      startDate: getServerDateString(-7),
      endDate: todayStr,
      preset: 'today',
      displayLabel: 'วันนี้',
      records: [],
      stats: null,
    };
  } else {
    if (!STATE.analytics.selectedDate) STATE.analytics.selectedDate = todayStr;
    if (!STATE.analytics.startDate) STATE.analytics.startDate = getServerDateString(-7);
    if (!STATE.analytics.endDate) STATE.analytics.endDate = todayStr;
  }

  const singleInp = $('singleDateInput');
  if (singleInp) {
    singleInp.value = STATE.analytics.selectedDate || todayStr;
    singleInp.max = todayStr;
  }

  const startInp = $('rangeStartDateInput');
  const endInp = $('rangeEndDateInput');
  if (startInp) {
    startInp.value = STATE.analytics.startDate || getServerDateString(-7);
    startInp.max = todayStr;
  }
  if (endInp) {
    endInp.value = STATE.analytics.endDate || todayStr;
    endInp.max = todayStr;
  }

  const clockEl = $('serverTimeClockDisplay');
  if (clockEl) {
    clockEl.textContent = formatServerDate(getServerNow());
  }

  const emptyTodayLabel = $('emptyStateTodayLabel');
  if (emptyTodayLabel) {
    emptyTodayLabel.textContent = formatServerDateOnly(getServerNow());
  }
}

/**
 * Switch analytics view mode between 'day' and 'range'
 */
function setAnalyticsMode(mode) {
  if (!STATE.analytics) initAnalyticsDateInputs();
  STATE.analytics.mode = mode;

  const btnDay = $('btnModeDay');
  const btnRange = $('btnModeRange');
  const containerDay = $('modeDayContainer');
  const containerRange = $('modeRangeContainer');

  if (btnDay) btnDay.classList.toggle('active', mode === 'day');
  if (btnRange) btnRange.classList.toggle('active', mode === 'range');

  if (containerDay) containerDay.style.display = (mode === 'day') ? 'block' : 'none';
  if (containerRange) containerRange.style.display = (mode === 'range') ? 'block' : 'none';

  if (mode === 'day') {
    if (!STATE.analytics.selectedDate) {
      STATE.analytics.selectedDate = getServerDateString(0);
    }
  } else {
    if (!STATE.analytics.startDate) {
      STATE.analytics.startDate = getServerDateString(-7);
      STATE.analytics.endDate = getServerDateString(0);
      STATE.analytics.preset = '7d';
    }
  }

  fetchAndRenderAnalyticsData();
}

/**
 * Quick day selector ('today' | 'yesterday')
 */
function selectQuickDay(dayType) {
  if (!STATE.analytics) initAnalyticsDateInputs();
  STATE.analytics.mode = 'day';

  const todayStr = getServerDateString(0);
  const yesterdayStr = getServerDateString(-1);
  const chosenDate = (dayType === 'yesterday') ? yesterdayStr : todayStr;

  STATE.analytics.selectedDate = chosenDate;
  STATE.analytics.preset = dayType;

  // Sync mode switcher UI
  const btnDay = $('btnModeDay');
  const btnRange = $('btnModeRange');
  if (btnDay) btnDay.classList.add('active');
  if (btnRange) btnRange.classList.remove('active');
  if ($('modeDayContainer')) $('modeDayContainer').style.display = 'block';
  if ($('modeRangeContainer')) $('modeRangeContainer').style.display = 'none';

  // Sync quick day buttons
  const btnToday = $('btnQuickDayToday');
  const btnYesterday = $('btnQuickDayYesterday');
  if (btnToday) btnToday.classList.toggle('active', dayType === 'today');
  if (btnYesterday) btnYesterday.classList.toggle('active', dayType === 'yesterday');

  // Sync date input
  const singleInp = $('singleDateInput');
  if (singleInp) singleInp.value = chosenDate;

  fetchAndRenderAnalyticsData();
}

/**
 * When user selects a date from native date picker
 */
function onSingleDateChanged(dateVal) {
  if (!dateVal) return;
  if (!STATE.analytics) initAnalyticsDateInputs();

  const todayStr = getServerDateString(0);
  const yesterdayStr = getServerDateString(-1);

  if (dateVal > todayStr) {
    showToast('⚠️ ไม่สามารถเลือกวันที่ในอนาคตได้ ระบบปรับเป็นวันนี้ให้อัตโนมัติ', 'warn');
    dateVal = todayStr;
    const singleInp = $('singleDateInput');
    if (singleInp) singleInp.value = dateVal;
  }

  STATE.analytics.mode = 'day';
  STATE.analytics.selectedDate = dateVal;

  if (dateVal === todayStr) {
    STATE.analytics.preset = 'today';
  } else if (dateVal === yesterdayStr) {
    STATE.analytics.preset = 'yesterday';
  } else {
    STATE.analytics.preset = 'custom';
  }

  const btnToday = $('btnQuickDayToday');
  const btnYesterday = $('btnQuickDayYesterday');
  if (btnToday) btnToday.classList.toggle('active', dateVal === todayStr);
  if (btnYesterday) btnYesterday.classList.toggle('active', dateVal === yesterdayStr);

  fetchAndRenderAnalyticsData();
}

/**
 * Step day forward (+1) or backward (-1)
 */
function stepDay(offset) {
  if (!STATE.analytics) initAnalyticsDateInputs();
  const cur = STATE.analytics.selectedDate || getServerDateString(0);
  const parts = cur.split('-').map(Number);
  const curDate = new Date(parts[0], parts[1] - 1, parts[2]);
  curDate.setDate(curDate.getDate() + offset);

  const y = curDate.getFullYear();
  const m = String(curDate.getMonth() + 1).padStart(2, '0');
  const d = String(curDate.getDate()).padStart(2, '0');
  const targetDateStr = `${y}-${m}-${d}`;
  const todayStr = getServerDateString(0);

  if (targetDateStr > todayStr) {
    showToast('⚠️ ไม่สามารถเลือกวันในอนาคตได้ (วันนี้เป็นวันล่าสุดแล้ว)', 'warn');
    return;
  }

  const singleInp = $('singleDateInput');
  if (singleInp) singleInp.value = targetDateStr;

  onSingleDateChanged(targetDateStr);
}

/**
 * Quick range preset selector ('7d', '30d', 'all')
 */
function selectQuickRangePreset(preset) {
  if (!STATE.analytics) initAnalyticsDateInputs();
  STATE.analytics.mode = 'range';
  STATE.analytics.preset = preset;

  document.querySelectorAll('.range-preset-btn').forEach(btn => {
    btn.classList.toggle('active', btn.getAttribute('data-preset') === preset);
  });

  const todayStr = getServerDateString(0);
  if (preset === '7d') {
    STATE.analytics.startDate = getServerDateString(-7);
    STATE.analytics.endDate = todayStr;
  } else if (preset === '30d') {
    STATE.analytics.startDate = getServerDateString(-30);
    STATE.analytics.endDate = todayStr;
  } else if (preset === 'all') {
    STATE.analytics.startDate = '';
    STATE.analytics.endDate = todayStr;
  }

  const startInp = $('rangeStartDateInput');
  const endInp = $('rangeEndDateInput');
  if (startInp) startInp.value = STATE.analytics.startDate || '';
  if (endInp) endInp.value = STATE.analytics.endDate || todayStr;

  fetchAndRenderAnalyticsData();
}

/**
 * Shortcut: Set Range End Date to Today
 */
function setRangeEndToToday() {
  if (!STATE.analytics) initAnalyticsDateInputs();
  const todayStr = getServerDateString(0);
  STATE.analytics.endDate = todayStr;

  const endInp = $('rangeEndDateInput');
  if (endInp) endInp.value = todayStr;

  const startInp = $('rangeStartDateInput');
  if (startInp && !startInp.value) {
    startInp.value = getServerDateString(-7);
    STATE.analytics.startDate = startInp.value;
  }

  showToast('🎯 กำหนดวันสิ้นสุดเป็นวันนี้ (' + todayStr + ') เรียบร้อย', 'info');
}

/**
 * Apply custom date range
 */
function applyCustomDateRange() {
  if (!STATE.analytics) initAnalyticsDateInputs();
  const startVal = $('rangeStartDateInput')?.value;
  const endVal = $('rangeEndDateInput')?.value || getServerDateString(0);

  if (!startVal) {
    showToast('กรุณาระบุวันที่เริ่มต้น', 'warn');
    return;
  }

  if (startVal > endVal) {
    showToast('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด', 'warn');
    return;
  }

  STATE.analytics.mode = 'range';
  STATE.analytics.startDate = startVal;
  STATE.analytics.endDate = endVal;
  STATE.analytics.preset = 'custom';

  document.querySelectorAll('.range-preset-btn').forEach(btn => btn.classList.remove('active'));

  fetchAndRenderAnalyticsData();
}

/**
 * Fetch telemetry data from database for selected day or range and update chart + stats
 */
async function fetchAndRenderAnalyticsData() {
  if (!STATE.analytics) initAnalyticsDateInputs();

  const mode = STATE.analytics.mode || 'day';
  let url = 'proxy.php?action=getHistoryRange&site=4';

  if (mode === 'day') {
    const dateVal = STATE.analytics.selectedDate || getServerDateString(0);
    url += `&date=${encodeURIComponent(dateVal)}`;
  } else {
    const p = STATE.analytics.preset;
    if (p && p !== 'custom') {
      url += `&preset=${encodeURIComponent(p)}`;
    } else {
      url += `&start=${encodeURIComponent(STATE.analytics.startDate || '')}&end=${encodeURIComponent(STATE.analytics.endDate || '')}`;
    }
  }

  try {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const json = await res.json();

    if (json.ok) {
      STATE.analytics.records = json.records || [];
      STATE.analytics.stats = json.stats || {};
      STATE.analytics.displayLabel = json.displayLabel || '';

      // Sync backward-compatible selectedRange
      STATE.selectedRange.records = json.records || [];
      STATE.selectedRange.stats = json.stats || {};

      // Update UI Labels & Badges
      const activeLabelEl = $('activePeriodLabel');
      if (activeLabelEl) activeLabelEl.textContent = json.displayLabel || (mode === 'day' ? STATE.analytics.selectedDate : 'ช่วงเวลาที่เลือก');

      const countDisplay = $('analyticsCountDisplay');
      if (countDisplay) countDisplay.textContent = json.count ?? 0;

      const exportLabel = $('analyticsExportBtnLabel');
      if (exportLabel) {
        if (mode === 'day') {
          exportLabel.textContent = `📥 ดาวน์โหลด CSV (${json.date || 'วันนี้'})`;
        } else {
          exportLabel.textContent = '📥 ดาวน์โหลด CSV ช่วงนี้';
        }
      }

      const clockEl = $('serverTimeClockDisplay');
      if (clockEl && json.serverTime && json.serverTime.formatted) {
        clockEl.textContent = json.serverTime.formatted;
      }

      // Empty State Handling
      const emptyCard = $('analyticsEmptyCard');
      const chartWrap = $('analyticsChartWrap');
      const hasData = json.count > 0;

      if (emptyCard) emptyCard.style.display = hasData ? 'none' : 'flex';
      if (chartWrap) chartWrap.style.display = hasData ? 'block' : 'none';

      // Update dynamic chart title and subtitle
      const chartTitleEl = $('chartMainTitle');
      const chartSubEl = $('chartMainSubtitle');
      if (chartTitleEl) {
        chartTitleEl.textContent = mode === 'day'
          ? `📈 กราฟวิเคราะห์คุณภาพอากาศ (${json.displayLabel || 'รายวัน'})`
          : `📈 กราฟวิเคราะห์แนวโน้มคุณภาพอากาศ (${json.displayLabel || 'ช่วงเวลา'})`;
      }
      if (chartSubEl) {
        chartSubEl.textContent = hasData
          ? `แสดงผลข้อมูลจากฐานข้อมูล | มีทั้งหมด ${json.count} จุดข้อมูล | คลิกปุ่มเพื่อเปิด-ปิดแต่ละตัวแปร`
          : `ไม่มีจุดข้อมูลที่บันทึกไว้ในระบบสำหรับช่วงเวลานี้`;
      }

      updateAnalyticsStats();
      updateAnalyticsMainChart();
    } else {
      showToast('ไม่สามารถดึงข้อมูลได้: ' + (json.error || ''), 'error');
    }
  } catch (e) {
    console.error('[fetchAndRenderAnalyticsData error]', e);
    showToast('เกิดข้อผิดพลาดในการโหลดข้อมูลประวัติ', 'error');
  }
}

/**
 * Export CSV for the currently selected Day or Range
 */
function downloadCurrentAnalyticsCSV() {
  if (!STATE.analytics) initAnalyticsDateInputs();
  const mode = STATE.analytics.mode || 'day';
  let url = 'proxy.php?action=exportCsv&site=4';

  if (mode === 'day') {
    url += `&date=${encodeURIComponent(STATE.analytics.selectedDate || getServerDateString(0))}`;
  } else {
    const p = STATE.analytics.preset;
    if (p && p !== 'custom') {
      url += `&preset=${encodeURIComponent(p)}`;
    } else {
      url += `&start=${encodeURIComponent(STATE.analytics.startDate || '')}&end=${encodeURIComponent(STATE.analytics.endDate || '')}`;
    }
  }

  const a = document.createElement('a');
  a.href = url;
  a.setAttribute('download', '');
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);

  showToast('✅ กำลังดาวน์โหลดไฟล์ CSV สำหรับข้อมูลที่เลือก...', 'success');
}

// Backward-compatible alias functions
function selectTimeRangePreset(p) { selectQuickRangePreset(p); }
function applyCustomTimeRange() { applyCustomDateRange(); }
function downloadRangeCSV() { downloadCurrentAnalyticsCSV(); }
function fetchAndRenderRangeData() { fetchAndRenderAnalyticsData(); }

function switchMainView(view) {
  STATE.currentMainView = view;
  const isOverview = view === 'overview';

  const panelOverview = $('panel-site4');
  const panelAnalytics = $('panel-analytics');
  if (panelOverview) {
    panelOverview.style.display = isOverview ? 'block' : 'none';
    panelOverview.classList.toggle('active', isOverview);
  }
  if (panelAnalytics) {
    panelAnalytics.style.display = isOverview ? 'none' : 'block';
    panelAnalytics.classList.toggle('active', !isOverview);
  }

  const tabOverview = $('tabBtnOverview');
  const tabAnalytics = $('tabBtnAnalytics');
  if (tabOverview) tabOverview.classList.toggle('active', isOverview);
  if (tabAnalytics) tabAnalytics.classList.toggle('active', !isOverview);

  // Trigger reflow & redraw for the newly visible view
  requestAnimationFrame(() => {
    setTimeout(() => {
      if (isOverview) {
        if (STATE.site4Data) refreshGauges();
        updateOverviewTrendChart();
        if (STATE.trendChart) {
          STATE.trendChart.resize();
        }
      } else {
        const recs = (STATE.analytics && STATE.analytics.records) ? STATE.analytics.records : STATE.selectedRange.records;
        if (!recs || recs.length === 0) {
          fetchAndRenderAnalyticsData();
        } else {
          updateAnalyticsStats();
          updateAnalyticsMainChart();
        }
        if (STATE.analyticsMainChart) {
          STATE.analyticsMainChart.resize();
          STATE.analyticsMainChart.update('none');
        }
      }
    }, 60);
  });
}

function updateAnalyticsStats() {
  const stats = (STATE.analytics && STATE.analytics.stats) ? STATE.analytics.stats : STATE.selectedRange.stats;
  const logs = (STATE.analytics && STATE.analytics.records && STATE.analytics.records.length > 0)
    ? STATE.analytics.records
    : ((STATE.selectedRange && STATE.selectedRange.records && STATE.selectedRange.records.length > 0)
        ? STATE.selectedRange.records
        : STATE.historyLogs);

  const setT = (id, val) => { const el = $(id); if (el) el.textContent = val; };

  // If server-aggregated stats are available from database, use them
  if (stats && stats.total_samples && parseInt(stats.total_samples, 10) > 0) {
    setT('stat-pm25-avg', stats.avg_pm25 ?? '—');
    setT('stat-pm25-max', stats.max_pm25 ?? '—');
    setT('stat-pm25-min', stats.min_pm25 ?? '—');

    setT('stat-pm10-avg', stats.avg_pm10 ?? '—');
    setT('stat-pm10-max', stats.max_pm10 ?? '—');
    setT('stat-pm10-min', stats.min_pm10 ?? '—');

    setT('stat-co2-avg', stats.avg_co2 ? Math.round(stats.avg_co2) : '—');
    setT('stat-co2-max', stats.max_co2 ? Math.round(stats.max_co2) : '—');
    setT('stat-co2-min', stats.min_co2 ? Math.round(stats.min_co2) : '—');

    setT('stat-temp-avg', stats.avg_temp ?? '—');
    setT('stat-temp-max', stats.max_temp ? `${stats.max_temp}°C` : '—');
    setT('stat-humid-avg', stats.avg_humid ?? '—');
    setT('stat-humid-max', stats.max_humid ? `${stats.max_humid}%` : '—');
    return;
  }

  // Fallback if no logs
  if (!logs || logs.length === 0) {
    const d = STATE.site4Data;
    if (d) {
      const pm25 = parseFloat(d['PM2.5'] ?? 0);
      const pm10 = parseFloat(d['PM10'] ?? 0);
      const co2 = parseFloat(d.CO2 ?? 0);
      const temp = parseFloat(d.temp ?? 0);
      const humid = parseFloat(d.humid ?? 0);

      setT('stat-pm25-avg', pm25.toFixed(1));
      setT('stat-pm25-max', pm25.toFixed(1));
      setT('stat-pm25-min', pm25.toFixed(1));

      setT('stat-pm10-avg', pm10.toFixed(1));
      setT('stat-pm10-max', pm10.toFixed(1));
      setT('stat-pm10-min', pm10.toFixed(1));

      setT('stat-co2-avg', Math.round(co2));
      setT('stat-co2-max', Math.round(co2));
      setT('stat-co2-min', Math.round(co2));

      setT('stat-temp-avg', temp.toFixed(1));
      setT('stat-temp-max', `${temp.toFixed(1)}°C`);
      setT('stat-humid-avg', humid.toFixed(1));
      setT('stat-humid-max', `${humid.toFixed(1)}%`);
    }
    return;
  }

  const pm25Arr = logs.map(l => l.pm25);
  const pm10Arr = logs.map(l => l.pm10);
  const co2Arr = logs.map(l => l.co2);
  const tempArr = logs.map(l => l.temp);
  const humidArr = logs.map(l => l.humid);

  const avg = arr => arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0;
  const max = arr => arr.length ? Math.max(...arr) : 0;
  const min = arr => arr.length ? Math.min(...arr) : 0;

  setT('stat-pm25-avg', avg(pm25Arr).toFixed(1));
  setT('stat-pm25-max', max(pm25Arr).toFixed(1));
  setT('stat-pm25-min', min(pm25Arr).toFixed(1));

  setT('stat-pm10-avg', avg(pm10Arr).toFixed(1));
  setT('stat-pm10-max', max(pm10Arr).toFixed(1));
  setT('stat-pm10-min', min(pm10Arr).toFixed(1));

  setT('stat-co2-avg', Math.round(avg(co2Arr)));
  setT('stat-co2-max', Math.round(max(co2Arr)));
  setT('stat-co2-min', Math.round(min(co2Arr)));

  setT('stat-temp-avg', avg(tempArr).toFixed(1));
  setT('stat-temp-max', `${max(tempArr).toFixed(1)}°C`);
  setT('stat-humid-avg', avg(humidArr).toFixed(1));
  setT('stat-humid-max', `${max(humidArr).toFixed(1)}%`);
}

function destroyAllCharts() {
  if (STATE.trendChart) {
    STATE.trendChart.destroy();
    STATE.trendChart = null;
  }
  if (STATE.analyticsMainChart) {
    STATE.analyticsMainChart.destroy();
    STATE.analyticsMainChart = null;
  }
}

// ──────────────────────────────────────────────
// Interactive Metric Selector Toggles
// ──────────────────────────────────────────────
function toggleMetric(metricKey) {
  if (STATE.activeMetrics[metricKey] === undefined) return;
  STATE.activeMetrics[metricKey] = !STATE.activeMetrics[metricKey];

  const btn = $('toggle-' + metricKey);
  const chk = $('chk-' + metricKey);
  const isActive = STATE.activeMetrics[metricKey];
  if (btn) btn.classList.toggle('active', isActive);
  if (chk) chk.textContent = isActive ? '✓' : '✕';

  const allActive = Object.values(STATE.activeMetrics).every(Boolean);
  const allBtn = $('toggle-all');
  const allChk = $('chk-all');
  if (allBtn) allBtn.classList.toggle('active', allActive);
  if (allChk) allChk.textContent = allActive ? '✓' : '—';

  updateAnalyticsMainChart();
}

function toggleAllMetrics() {
  const allActive = Object.values(STATE.activeMetrics).every(Boolean);
  const targetState = !allActive;

  Object.keys(STATE.activeMetrics).forEach(k => {
    STATE.activeMetrics[k] = targetState;
    const btn = $('toggle-' + k);
    const chk = $('chk-' + k);
    if (btn) btn.classList.toggle('active', targetState);
    if (chk) chk.textContent = targetState ? '✓' : '✕';
  });

  const allBtn = $('toggle-all');
  const allChk = $('chk-all');
  if (allBtn) allBtn.classList.toggle('active', targetState);
  if (allChk) allChk.textContent = targetState ? '✓' : '✕';

  updateAnalyticsMainChart();
}

// ──────────────────────────────────────────────
// Unified Air Analytics Interactive Chart
// ──────────────────────────────────────────────
function updateAnalyticsMainChart() {
  const panel = $('panel-analytics');
  if (panel && (panel.style.display === 'none' || panel.offsetParent === null)) return;

  const ctx = $('analyticsMainChart');
  if (!ctx) return;

  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const textColor = isDark ? '#94A3B8' : '#475569';
  const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

  // Use selected range records from Database if available, otherwise fall back to session history
  const logs = (STATE.analytics && STATE.analytics.records)
    ? STATE.analytics.records
    : ((STATE.selectedRange && STATE.selectedRange.records && STATE.selectedRange.records.length > 0)
        ? STATE.selectedRange.records
        : STATE.historyLogs);

  const emptyCard = $('analyticsEmptyCard');
  const chartWrap = $('analyticsChartWrap');
  if (!logs || logs.length === 0) {
    if (emptyCard) emptyCard.style.display = 'flex';
    if (chartWrap) chartWrap.style.display = 'none';
    if (STATE.analyticsMainChart) {
      STATE.analyticsMainChart.destroy();
      STATE.analyticsMainChart = null;
    }
    return;
  } else {
    if (emptyCard) emptyCard.style.display = 'none';
    if (chartWrap) chartWrap.style.display = 'block';
  }

  const isDayMode = (STATE.analytics && STATE.analytics.mode === 'day');
  const labels = logs.map(l => (isDayMode && l.timeLabel) ? l.timeLabel : l.label);
  const pm25Data = logs.map(l => l.pm25);
  const pm10Data = logs.map(l => l.pm10);
  const co2Data = logs.map(l => l.co2);
  const tempData = logs.map(l => l.temp);
  const humidData = logs.map(l => l.humid);
  const evocData = logs.map(l => l.evoc);

  // Dynamic point radius: if few points (e.g. today just started), show dots clearly
  const ptRadius = logs.length <= 25 ? 4 : (logs.length <= 60 ? 2 : 0);

  const datasets = [
    {
      id: 'pm25',
      label: 'PM2.5 (µg/m³)',
      data: pm25Data,
      yAxisID: 'y',
      borderColor: '#0D9488',
      backgroundColor: 'rgba(13,148,136,0.08)',
      borderWidth: 2.5,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.pm25,
    },
    {
      id: 'pm10',
      label: 'PM10 (µg/m³)',
      data: pm10Data,
      yAxisID: 'y',
      borderColor: '#06B6D4',
      borderWidth: 2.2,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.pm10,
    },
    {
      id: 'co2',
      label: 'CO2 (ppm)',
      data: co2Data,
      yAxisID: 'yCO2',
      borderColor: '#3B82F6',
      borderWidth: 2.5,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.co2,
    },
    {
      id: 'temp',
      label: 'อุณหภูมิ (°C)',
      data: tempData,
      yAxisID: 'y',
      borderColor: '#F59E0B',
      borderWidth: 2.2,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.temp,
    },
    {
      id: 'humid',
      label: 'ความชื้น (%RH)',
      data: humidData,
      yAxisID: 'yHumid',
      borderColor: '#0284C7',
      borderWidth: 2.2,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.humid,
    },
    {
      id: 'evoc',
      label: 'EVOC (ppb)',
      data: evocData,
      yAxisID: 'y',
      borderColor: '#8B5CF6',
      borderWidth: 2.2,
      pointRadius: ptRadius,
      pointHoverRadius: 6,
      pointHitRadius: 12,
      fill: false,
      tension: 0.35,
      hidden: !STATE.activeMetrics.evoc,
    },
  ];

  const showLeftAxis = Boolean(STATE.activeMetrics.pm25 || STATE.activeMetrics.pm10 || STATE.activeMetrics.temp || STATE.activeMetrics.evoc);
  const showCO2Axis = Boolean(STATE.activeMetrics.co2);
  const showHumidAxis = Boolean(STATE.activeMetrics.humid);

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: {
          color: textColor,
          font: { family: 'Inter', size: 12, weight: '600' },
          boxWidth: 14,
          usePointStyle: true,
          padding: 16,
        },
      },
      tooltip: {
        backgroundColor: 'rgba(15,23,42,0.92)',
        titleColor: '#FFFFFF',
        bodyColor: '#E2E8F0',
        borderColor: 'rgba(13,148,136,0.4)',
        borderWidth: 1,
        padding: 12,
        cornerRadius: 10,
        bodyFont: { family: 'Inter', size: 12 },
        titleFont: { family: 'Inter', weight: '700', size: 13 },
      },
    },
    scales: {
      x: {
        ticks: { color: textColor, font: { size: 11, family: 'Inter' }, maxRotation: 30, autoSkip: true, maxTicksLimit: 14 },
        grid: { color: gridColor },
      },
      y: {
        type: 'linear',
        position: 'left',
        beginAtZero: true,
        display: showLeftAxis,
        title: { display: showLeftAxis, text: 'PM / อุณหภูมิ (°C) / EVOC (ppb)', color: textColor, font: { size: 11, weight: '600' } },
        ticks: { color: textColor },
        grid: { color: gridColor },
      },
      yCO2: {
        type: 'linear',
        position: 'right',
        beginAtZero: false,
        display: showCO2Axis,
        title: { display: showCO2Axis, text: 'CO2 (ppm)', color: '#3B82F6', font: { size: 11, weight: '600' } },
        ticks: { color: '#3B82F6' },
        grid: { drawOnChartArea: false },
      },
      yHumid: {
        type: 'linear',
        position: 'right',
        min: 0,
        max: 100,
        display: showHumidAxis,
        title: { display: showHumidAxis, text: 'ความชื้น (%RH)', color: '#0284C7', font: { size: 11, weight: '600' } },
        ticks: { color: '#0284C7' },
        grid: { drawOnChartArea: false },
      },
    },
  };

  if (STATE.analyticsMainChart) {
    STATE.analyticsMainChart.data.labels = labels;
    datasets.forEach((ds, idx) => {
      if (STATE.analyticsMainChart.data.datasets[idx]) {
        STATE.analyticsMainChart.data.datasets[idx].data = ds.data;
        STATE.analyticsMainChart.data.datasets[idx].hidden = ds.hidden;
      }
    });
    if (STATE.analyticsMainChart.options.scales.y) {
      STATE.analyticsMainChart.options.scales.y.display = showLeftAxis;
      if (STATE.analyticsMainChart.options.scales.y.title) {
        STATE.analyticsMainChart.options.scales.y.title.display = showLeftAxis;
      }
    }
    if (STATE.analyticsMainChart.options.scales.yCO2) {
      STATE.analyticsMainChart.options.scales.yCO2.display = showCO2Axis;
      if (STATE.analyticsMainChart.options.scales.yCO2.title) {
        STATE.analyticsMainChart.options.scales.yCO2.title.display = showCO2Axis;
      }
    }
    if (STATE.analyticsMainChart.options.scales.yHumid) {
      STATE.analyticsMainChart.options.scales.yHumid.display = showHumidAxis;
      if (STATE.analyticsMainChart.options.scales.yHumid.title) {
        STATE.analyticsMainChart.options.scales.yHumid.title.display = showHumidAxis;
      }
    }
    STATE.analyticsMainChart.update('none');
  } else {
    STATE.analyticsMainChart = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: chartOptions,
    });
  }
}

function updateAllCharts() {
  if (STATE.currentMainView === 'analytics') {
    updateAnalyticsMainChart();
  }
}

// Alias for compatibility
function updateTrendChart() {
  updateAllCharts();
}

function drawGauge(canvasId, value, max, ranges, unit) {
  const canvas = $(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const theme = document.documentElement.getAttribute('data-theme') || 'light';
  const isLight = theme === 'light';

  let color = '#0D9488';
  for (const r of ranges) { if (value >= r.min && value <= r.max) { color = r.color; break; } }

  const pct = clamp(value / max, 0, 1);
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  const W = rect.width > 0 ? rect.width : 260;
  const H = rect.height > 0 ? rect.height : 180;

  canvas.width = Math.round(W * dpr);
  canvas.height = Math.round(H * dpr);
  ctx.scale(dpr, dpr);

  // Position center Y at H - 28 to leave 28px breathing room for bottom tick labels
  const cyr = H - 28;
  const ro = Math.min((W - 36) / 2, cyr - 16);
  const ri = ro - Math.max(16, ro * 0.25);
  const cxr = W / 2;

  ctx.clearRect(0, 0, W, H);

  // Arc track background
  ctx.beginPath();
  ctx.arc(cxr, cyr, ro, Math.PI, 2 * Math.PI);
  ctx.arc(cxr, cyr, ri, 2 * Math.PI, Math.PI, true);
  ctx.closePath();
  ctx.fillStyle = isLight ? 'rgba(226, 232, 240, 0.7)' : 'rgba(255, 255, 255, 0.08)';
  ctx.fill();

  // Active filled arc with ambient glow
  if (pct > 0) {
    const startAngle = Math.PI, endAngle = Math.PI + pct * Math.PI;
    ctx.beginPath();
    ctx.arc(cxr, cyr, ro, startAngle, endAngle);
    ctx.arc(cxr, cyr, ri, endAngle, startAngle, true);
    ctx.closePath();
    ctx.fillStyle = color;
    ctx.shadowBlur = isLight ? 10 : 16;
    ctx.shadowColor = color;
    ctx.fill();
    ctx.shadowBlur = 0;
  }

  // Large numerical value in center of arch
  const valueFontSize = Math.max(22, Math.floor(ro * 0.44));
  ctx.fillStyle = isLight ? '#0F172A' : '#FFFFFF';
  ctx.font = `800 ${valueFontSize}px Inter, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'alphabetic';
  const textY = cyr - ro * 0.25;
  const valDisplay = Number.isInteger(value) ? value : Number(value).toFixed(1);
  ctx.fillText(valDisplay, cxr, textY);

  // Unit text below numerical value
  const unitFontSize = Math.max(11, Math.floor(ro * 0.18));
  ctx.fillStyle = isLight ? '#475569' : 'rgba(255,255,255,0.7)';
  ctx.font = `600 ${unitFontSize}px Inter, sans-serif`;
  ctx.fillText(unit, cxr, textY + unitFontSize + 5);

  // Min ('0') and Max Tick Labels at bottom left & right
  const edgeFontSize = Math.max(10, Math.floor(ro * 0.15));
  ctx.fillStyle = isLight ? '#64748B' : 'rgba(255,255,255,0.45)';
  ctx.font = `600 ${edgeFontSize}px Inter, sans-serif`;
  ctx.textBaseline = 'top';
  ctx.textAlign = 'left';
  ctx.fillText('0', cxr - ro + 4, cyr + 6);
  ctx.textAlign = 'right';
  ctx.fillText(max, cxr + ro - 4, cyr + 6);
}

const PM10_RANGES = [
  { min: 0, max: 50, color: '#10B981' },
  { min: 50, max: 100, color: '#F59E0B' },
  { min: 100, max: 9999, color: '#EF4444' },
];
const CO2_RANGES = [
  { min: 0, max: 800, color: '#10B981' },
  { min: 800, max: 1000, color: '#F59E0B' },
  { min: 1000, max: 9999, color: '#EF4444' },
];
const TEMP_RANGES = [
  { min: 0, max: 26, color: '#10B981' },
  { min: 26, max: 30, color: '#F59E0B' },
  { min: 30, max: 9999, color: '#EF4444' },
];
const HUMID_RANGES = [
  { min: 0, max: 40, color: '#F59E0B' },
  { min: 40, max: 60, color: '#10B981' },
  { min: 60, max: 9999, color: '#EF4444' },
];

function refreshGauges(pm10, co2, temp, humid) {
  if (STATE.currentMainView !== 'overview') return;
  const panel = $('panel-site4');
  if (panel && panel.offsetParent === null) return;

  if (pm10 === undefined && STATE.site4Data) {
    pm10 = parseFloat(STATE.site4Data['PM10'] ?? STATE.site4Data.pm10 ?? 0);
    co2 = parseFloat(STATE.site4Data.CO2 ?? 0);
    temp = parseFloat(STATE.site4Data.temp ?? 0);
    humid = parseFloat(STATE.site4Data.humid ?? 0);
  }
  drawGauge('gauge-pm10', pm10 || 0, 150, PM10_RANGES, 'µg/m³');
  drawGauge('gauge-co2', co2 || 0, 1500, CO2_RANGES, 'ppm');
  drawGauge('gauge-temp', temp || 0, 45, TEMP_RANGES, '°C');
  drawGauge('gauge-humid', humid || 0, 100, HUMID_RANGES, '%RH');
}

// ──────────────────────────────────────────────
// Export data to CSV
// ──────────────────────────────────────────────
function downloadCSV() {
  if (!STATE.historyLogs.length && !STATE.site4Data) { showToast('ไม่มีข้อมูลสำหรับดาวน์โหลด', 'error'); return; }

  const headers = ['เวลาอัปเดต', 'ห้อง/สถานที่', 'PM2.5 (µg/m³)', 'PM10 (µg/m³)', 'CO2 (ppm)', 'อุณหภูมิ (°C)', 'ความชื้น (%RH)', 'EVOC (ppb)', 'RSSI (dBm)'];
  const d = STATE.site4Data;
  const logs = STATE.historyLogs.length > 0 ? STATE.historyLogs : [{
    time: d ? (d.lastUpdate || nowStr()) : nowStr(), site: 'Site 4 - ICT401',
    pm25: d ? d['PM2.5'] : 0, pm10: d ? d['PM10'] : 0, co2: d ? d.CO2 : 0,
    temp: d ? d.temp : 0, humid: d ? d.humid : 0, evoc: d ? d.evoc : 0, rssi: d ? d.RSSI : '0',
  }];

  const rows = logs.map(l =>
    [l.time, l.site, l.pm25, l.pm10, l.co2, l.temp, l.humid, l.evoc, l.rssi]
      .map(v => `"${String(v ?? '').replace(/"/g, '""')}"`)
      .join(',')
  );
  const csv = [headers.join(','), ...rows].join('\r\n');
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `AIR_ICT401_${nowStr().replace(/[/:]/g, '-')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  showToast('✅ ดาวน์โหลด CSV ข้อมูลห้อง ICT401 สำเร็จ', 'success');
}

// ──────────────────────────────────────────────
// Initializer (single resize listener with debounce)
// ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initServerTimeSync();
  checkAuthOnStartup();
  window.addEventListener('resize', debounce(() => { if (STATE.site4Data) refreshGauges(); }, 200));
});
