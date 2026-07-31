/**
 * app.js — AIIR IAQ Smart Dashboard
 * Full business logic: API proxy via PHP backend (or demo mode),
 * login, auto-refresh, metric rendering, gauge charts, trend chart,
 * control recommendations, CSV export.
 *
 * Architecture mirrors the original Streamlit app:
 *   - api.py        → ApiService class
 *   - ui_components.py → renderMetrics(), drawGauge(), renderControlCards()
 *   - all_sites.py  → renderOverview()
 *   - site_detail.py→ renderSiteDetail()
 *   - sidebar.py    → handleLogin(), toggleAutoRefresh()
 */

'use strict';

/* ============================================================
   CONFIGURATION
   ============================================================ */
const CONFIG = {
  loginUrl:       'proxy.php?action=login',
  siteDataUrl:    'proxy.php?action=getSiteData',
  specDataUrl:    'proxy.php?action=getSpecData',
  demoMode:       false,  // false = ใช้ข้อมูลจริงจาก AIIR API ผ่าน proxy.php
  autoRefreshMs:  30000,
  trendMaxPoints: 10,
};

/* ============================================================
   STATE
   ============================================================ */
const STATE = {
  isLoggedIn: false,
  allSitesData: [],
  site4Data: null,
  historyPM25: [],
  historyCO2: [],
  historyTemp: [],
  historyLabels: [],
  autoRefreshTimer: null,
  trendChart: null,
  gaugeCharts: { pm25: null, co2: null, temp: null },
};

/* ============================================================
   UTILITY
   ============================================================ */
function $(id) { return document.getElementById(id); }

function clamp(v, min, max) { return Math.min(Math.max(v, min), max); }

function nowStr() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth()+1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function showToast(msg, type = 'info', duration = 3500) {
  const t = $('toast');
  t.textContent = msg;
  t.className = `toast ${type} show`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.className = 'toast'; }, duration);
}

/* ============================================================
   SIDEBAR & THEME TOGGLES
   ============================================================ */
function toggleSidebar() {
  $('sidebar').classList.toggle('open');
  $('overlay').classList.toggle('show');
}

function toggleSidebarCollapse() {
  const isMobile = window.innerWidth <= 768;
  if (isMobile) {
    toggleSidebar();
  } else {
    document.body.classList.toggle('sidebar-collapsed');
  }
  // Redraw gauges after layout transitions
  setTimeout(() => {
    if (STATE.site4Data) refreshGauges();
  }, 300);
}

function toggleTheme() {
  const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
  const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
  applyTheme(newTheme);
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const icon = $('themeIcon');
  if (icon) icon.textContent = theme === 'light' ? '☀️' : '🌙';
  localStorage.setItem('aiir_theme', theme);

  if (STATE.site4Data) refreshGauges();
  if (STATE.trendChart) {
    STATE.trendChart.destroy();
    STATE.trendChart = null;
    updateTrendChart();
  }
}

function initTheme() {
  const savedTheme = localStorage.getItem('aiir_theme') || 'light';
  applyTheme(savedTheme);
}


/* ============================================================
   PASSWORD TOGGLE
   ============================================================ */
function togglePw() {
  const inp = $('inputPass');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  $('pwToggle').style.opacity = inp.type === 'text' ? '1' : '0.6';
}

/* ============================================================
   TABS
   ============================================================ */
function switchTab(name) {
  ['overview', 'site4'].forEach(n => {
    $(`tab-${n}`).classList.toggle('active', n === name);
    $(`tab-${n}`).setAttribute('aria-selected', n === name);
    $(`panel-${n}`).classList.toggle('active', n === name);
  });
  // Draw trend chart if switching to site4 and chart not yet initialized
  if (name === 'site4' && STATE.site4Data) {
    setTimeout(refreshGauges, 60);
  }
}

/* ============================================================
   AUTO REFRESH
   ============================================================ */
function toggleAutoRefresh() {
  const on = $('autoRefreshToggle').checked;
  if (on) {
    if (STATE.isLoggedIn) startAutoRefresh();
    showToast('Auto-Refresh เปิดแล้ว (ทุก 30 วินาที)', 'info');
  } else {
    stopAutoRefresh();
    showToast('Auto-Refresh ปิดแล้ว', 'info');
  }
}

function startAutoRefresh() {
  stopAutoRefresh();
  STATE.autoRefreshTimer = setInterval(fetchData, CONFIG.autoRefreshMs);
}

function stopAutoRefresh() {
  if (STATE.autoRefreshTimer) {
    clearInterval(STATE.autoRefreshTimer);
    STATE.autoRefreshTimer = null;
  }
}

/* ============================================================
   STATUS BADGE
   ============================================================ */
function setConnected(on) {
  STATE.isLoggedIn = on;
  const badge = $('statusBadge');
  const dot   = $('statusDot');
  const text  = $('statusText');
  if (on) {
    badge.className = 'status-badge badge-online';
    dot.className   = 'dot dot-green';
    text.textContent = 'Connected';
    showDashboard();
  } else {
    badge.className = 'status-badge badge-offline';
    dot.className   = 'dot dot-red';
    text.textContent = 'Disconnected';
    hideDashboard();
  }
}

function updateLastUpdate(timeStr) {
  const t = timeStr || nowStr();
  const wrap = $('lastUpdateWrap');
  if (wrap) wrap.hidden = false;
  const el = $('lastUpdateTime');
  if (el) el.textContent = t;
}

/* ============================================================
   LOGIN
   ============================================================ */
async function handleLogin(e) {
  e.preventDefault();
  const user = $('inputUser').value.trim();
  const pass = $('inputPass').value;
  if (!user || !pass) { showToast('กรอก Username และ Password ด้วย', 'error'); return; }

  $('loginBtnText').hidden = true;
  $('loginSpinner').hidden = false;
  $('loginBtn').disabled = true;

  const ok = CONFIG.demoMode
    ? await mockLogin(user, pass)
    : await realLogin(user, pass);

  $('loginBtnText').hidden = false;
  $('loginSpinner').hidden = true;
  $('loginBtn').disabled = false;

  if (ok) {
    setConnected(true);
    showToast('✅ เชื่อมต่อสำเร็จ!', 'success');
    fetchData();
    if ($('autoRefreshToggle').checked) startAutoRefresh();
  } else {
    showToast('❌ Username หรือ Password ไม่ถูกต้อง', 'error');
    setConnected(false);
  }
}

function showDashboard() {
  const ws = $('welcomeScreen');
  const db = $('dashboard');
  if (ws) {
    ws.hidden = true;
    ws.style.setProperty('display', 'none', 'important');
  }
  if (db) {
    db.hidden = false;
    db.style.setProperty('display', 'block', 'important');
  }
}

function hideDashboard() {
  const ws = $('welcomeScreen');
  const db = $('dashboard');
  if (ws) {
    ws.hidden = false;
    ws.style.setProperty('display', 'flex', 'important');
  }
  if (db) {
    db.hidden = true;
    db.style.setProperty('display', 'none', 'important');
  }
}

/* Mock login (demo mode) */
async function mockLogin(user, pass) {
  await sleep(900);
  return user === 'admin' && pass.length >= 1;
}

/* Real login via PHP proxy — ส่ง JSON body */
async function realLogin(user, pass) {
  try {
    const res  = await fetch(CONFIG.loginUrl, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ user, pass }),
    });
    const json = await res.json();
    if (!json.ok && json.error) console.warn('[AIIR Login]', json.error);
    return json.ok === true;
  } catch (e) {
    console.error('[AIIR Login] fetch error:', e);
    return false;
  }
}

/* ============================================================
   DATA FETCH
   ============================================================ */
async function fetchData() {
  if (!STATE.isLoggedIn) return;

  const btn = $('manualRefreshBtn');
  if (btn) btn.classList.add('spinning');

  try {
    const [sites, spec] = CONFIG.demoMode
      ? await mockFetchAll()
      : await realFetchAll();

    if (sites && sites.length > 0) {
      STATE.allSitesData = sites;
      renderOverview(sites);
    }

    const site4Raw = sites.find(s => String(s.Site) === '4') || {};
    const specData = spec && typeof spec === 'object' ? spec : {};

    const specTemp  = specData.temp  ?? specData.Temp  ?? null;
    const specHumid = specData.humid ?? specData.Humid ?? null;
    const specEvoc  = specData.evoc  ?? specData.eVOC  ?? null;
    const specPm25  = specData.pm25  ?? specData.PM25  ?? null;
    const specPm10  = specData.pm10  ?? specData.PM10  ?? null;
    const specCo2   = specData.co2   ?? specData.CO2   ?? null;
    const specRssi  = specData.rssi  ?? specData.RSSI  ?? null;
    const specUpd   = specData.lastUpdate || site4Raw['Update'] || '';

    if (Object.keys(site4Raw).length > 0 || spec) {
      const pm25Val  = specPm25  !== null ? parseFloat(specPm25)  : (parseFloat(site4Raw['PM2.5']) || 0);
      const pm10Val  = specPm10  !== null ? parseFloat(specPm10)  : (parseFloat(site4Raw['PM10'])  || 0);
      const co2Val   = specCo2   !== null ? parseFloat(specCo2)   : (parseFloat(site4Raw['CO2'])   || 0);
      const tempVal  = specTemp  !== null ? parseFloat(specTemp)  : (parseFloat(site4Raw.temp)  || 0);
      const humidVal = specHumid !== null ? parseFloat(specHumid) : (parseFloat(site4Raw.humid) || 0);
      const evocVal  = specEvoc  !== null ? parseFloat(specEvoc)  : (parseFloat(site4Raw.evoc)  || 0);
      const rssiVal  = specRssi  !== null ? String(specRssi)      : (site4Raw['RSSI'] || '0');

      STATE.site4Data = {
        ...site4Raw,
        'PM2.5': pm25Val,
        'PM10':  pm10Val,
        'CO2':   co2Val,
        'RSSI':  rssiVal,
        temp:  tempVal,
        humid: humidVal,
        evoc:  evocVal,
        lastUpdate: specUpd,
      };
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


async function realFetchAll() {
  const [r1, r2] = await Promise.all([
    fetch(CONFIG.siteDataUrl),
    fetch(CONFIG.specDataUrl + '&site=4&siteType=4'),
  ]);

  const j1 = await r1.json();
  const j2 = await r2.json();

  console.log('%c[AIIR Realtime Data]', 'color:#4ade80;font-weight:700', j2);

  if (!j1.ok) {
    if (j1.error === 'session_expired') {
      setConnected(false);
      showToast('Session หมดอายุ กรุณา Login ใหม่', 'error', 5000);
    } else {
      console.warn('[getSiteData]', j1.error, j1.raw ?? '');
    }
    return [[], null];
  }

  const sites = (j1.data || []).map(s => ({
    ...s,
    SiteName: s.SiteName || ('Site ' + s.Site),
  }));

  const spec = j2.ok ? j2 : null;
  if (!j2.ok) console.warn('[getSpecData]', j2.error, j2.raw ?? '');

  return [sites, spec];
}

/* ============================================================
   DEMO / MOCK DATA
   ============================================================ */
const SITE_NAMES = [
  'Site 1 - ห้องเรียน 101',
  'Site 2 - ห้องประชุม',
  'Site 3 - โถงกลาง',
  'Site 4 - ICT401',
  'Site 5 - ห้องพักครู',
];

function rand(min, max, decimals = 1) {
  return parseFloat((Math.random() * (max - min) + min).toFixed(decimals));
}

let _demoCycle = 0;

async function mockFetchAll() {
  await sleep(600 + Math.random() * 400);
  _demoCycle++;

  const sites = SITE_NAMES.map((name, i) => {
    const pm25 = rand(5, 60);
    const pm10 = rand(pm25, pm25 * 1.8);
    const co2  = rand(400, 1400);
    const rssi = rand(-85, -40, 0);
    const up   = nowStr();
    const isOnline = Math.random() > 0.1;
    return {
      Site:   String(i + 1),
      SiteName: name,
      Status: isOnline ? 'Online' : 'Offline',
      RSSI:   rssi,
      'PM2.5': pm25,
      PM10:   pm10,
      CO2:    co2,
      Update: up,
    };
  });

  const s4 = sites[3];
  const spec = {
    temp:  rand(22, 32),
    humid: rand(35, 75),
  };

  return [sites, { ...s4, ...spec }];
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

/* ============================================================
   HISTORY (for trend chart)
   ============================================================ */
function appendHistory(data) {
  const label = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const push = (arr, val) => {
    arr.push(val ?? 0);
    if (arr.length > CONFIG.trendMaxPoints) arr.shift();
  };
  push(STATE.historyLabels, label);
  push(STATE.historyPM25,   data['PM2.5'] ?? data.pm25 ?? 0);
  push(STATE.historyCO2,    data.CO2 ?? data.co2 ?? 0);
  push(STATE.historyTemp,   data.temp ?? 0);
  updateTrendChart();
}

/* ============================================================
   RENDER OVERVIEW TAB
   ============================================================ */
function renderOverview(sites) {
  const grid = $('sitesGrid');
  const empty = document.getElementById('overviewEmpty');
  if (empty) empty.remove();

  // Remove old cards
  grid.querySelectorAll('.site-card').forEach(c => c.remove());

  sites.forEach((s, i) => {
    const pm25 = parseFloat(s['PM2.5'] ?? 0);
    const pm10 = parseFloat(s.PM10 ?? 0);
    const co2  = parseFloat(s.CO2 ?? 0);
    const isOn = (s.Status || '').toLowerCase().includes('online') || (s.Status || '').toLowerCase().includes('ok');

    const { label: pm25Lbl, pillClass } = pm25Level(pm25);
    const isOff = !isOn;

    const card = document.createElement('div');
    card.className = 'site-card';
    card.style.animationDelay = `${i * 0.06}s`;
    card.innerHTML = `
      <div class="site-card-header">
        <div class="site-name">${s.SiteName || 'Site ' + s.Site}</div>
        <div class="site-status-pill ${isOff ? 'pill-off' : pillClass}">${isOff ? 'Offline' : pm25Lbl}</div>
      </div>
      <div class="site-stats">
        <div class="stat-item">
          <div class="stat-val" style="color:${pm25Color(pm25)}">${pm25.toFixed(1)}</div>
          <div class="stat-label">PM2.5</div>
        </div>
        <div class="stat-item">
          <div class="stat-val" style="color:${co2Color(co2)}">${co2.toFixed(0)}</div>
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
    const co2  = parseFloat(s.CO2 ?? 0);
    const isOn = (s.Status || '').toLowerCase().includes('online');
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${s.SiteName || 'Site ' + s.Site}</strong></td>
      <td><span class="site-status-pill ${isOn ? 'pill-ok' : 'pill-off'}">${s.Status}</span></td>
      <td style="color:${pm25Color(pm25)}">${pm25.toFixed(1)}</td>
      <td>${parseFloat(s.PM10 ?? 0).toFixed(1)}</td>
      <td style="color:${co2Color(co2)}">${parseFloat(co2).toFixed(0)}</td>
      <td>${s.RSSI} dBm</td>
      <td style="font-size:0.8rem;color:var(--text-muted)">${s.Update}</td>
    `;
    tbody.appendChild(tr);
  });
}

/* ============================================================
   RENDER SITE 4 DETAIL TAB
   ============================================================ */
function renderSiteDetail(data) {
  const pm25  = parseFloat(data['PM2.5'] ?? data.pm25 ?? 0);
  const pm10  = parseFloat(data.PM10 ?? data.pm10 ?? 0);
  const co2   = parseFloat(data.CO2 ?? data.co2 ?? 0);
  const temp  = parseFloat(data.temp ?? 0);
  const humid = parseFloat(data.humid ?? 0);
  const evoc  = parseFloat(data.evoc ?? 0);
  const rssi  = parseFloat(data.RSSI ?? data.rssi ?? -70);

  // PM2.5
  setMetric('pm25', pm25.toFixed(1), pm25 / 75 * 100, ...pm25LevelFull(pm25));
  // PM10
  setMetric('pm10', pm10.toFixed(1), pm10 / 150 * 100, ...pm10LevelFull(pm10));
  // CO2
  setMetric('co2', co2.toFixed(0), co2 / 1500 * 100, ...co2LevelFull(co2));
  // Temp
  const tempPct = clamp((temp - 16) / (40 - 16) * 100, 0, 100);
  setMetricRaw('temp', temp.toFixed(1), tempPct, temp < 26 ? 'เย็นสบาย' : temp < 30 ? 'อุ่น' : 'ร้อน', temp < 26 ? 'good' : temp < 30 ? 'warn' : 'bad', '#C36D4B');
  // Humid
  const humidPct = clamp(humid, 0, 100);
  const humidStatus = humid < 40 ? 'แห้งเกิน' : humid <= 60 ? 'เหมาะสม' : 'ชื้นเกิน';
  const humidCls    = humid < 40 ? 'warn' : humid <= 60 ? 'good' : 'warn';
  setMetricRaw('humid', humid.toFixed(1), humidPct, humidStatus, humidCls, '#0284C7');
  // EVOC
  const evocPct    = clamp((evoc / 50) * 100, 0, 100);
  const evocStatus = evoc <= 10 ? 'ดีเยี่ยม' : evoc <= 50 ? 'ปานกลาง' : 'สูง';
  const evocCls    = evoc <= 10 ? 'good' : evoc <= 50 ? 'warn' : 'bad';
  const evocColor  = evoc <= 10 ? '#0D9488' : evoc <= 50 ? '#0284C7' : '#C36D4B';
  setMetricRaw('evoc', evoc.toFixed(0), evocPct, evocStatus, evocCls, evocColor);
  // RSSI
  const rssiNorm = clamp(((rssi + 100) / 60) * 100, 0, 100);
  const rssiStatus = rssi >= -60 ? 'สัญญาณดีมาก' : rssi >= -70 ? 'ดี' : rssi >= -80 ? 'พอใช้' : 'อ่อน';
  const rssiCls    = rssi >= -60 ? 'good' : rssi >= -70 ? 'info' : 'warn';
  setMetricRaw('rssi', rssi.toFixed(0), rssiNorm, rssiStatus, rssiCls, '#737877');

  // Control recommendations
  renderControlCards(pm25, co2, temp, humid);

  // Gauges
  refreshGauges(pm25, co2, temp);
}

function setMetric(key, valStr, barPct, label, cls, color, barColor) {
  $(`val-${key}`).textContent = valStr;
  const bar = $(`bar-${key}`);
  bar.style.width = clamp(barPct, 0, 100) + '%';
  if (barColor) bar.style.background = barColor;
  else bar.style.background = color;
  const statusEl = $(`status-${key}`);
  statusEl.textContent = '● ' + label;
  statusEl.className = `metric-status ${cls}`;
}

function setMetricRaw(key, valStr, barPct, label, cls, barColor) {
  $(`val-${key}`).textContent = valStr;
  const bar = $(`bar-${key}`);
  bar.style.width = clamp(barPct, 0, 100) + '%';
  bar.style.background = barColor;
  const statusEl = $(`status-${key}`);
  statusEl.textContent = '● ' + label;
  statusEl.className = `metric-status ${cls}`;
}

/* ============================================================
   AIR QUALITY LEVELS (mirror ui_components.py)
   ============================================================ */
function pm25LevelFull(v) {
  if (v <= 12)  return ['ดีเยี่ยม', 'good', '#0D9488', '#0D9488'];
  if (v <= 35)  return ['ปานกลาง', 'warn', '#0284C7', '#0284C7'];
  return              ['อันตราย',  'bad',  '#C36D4B', '#C36D4B'];
}
function pm10LevelFull(v) {
  if (v <= 50)  return ['ดีเยี่ยม', 'good', '#0D9488', '#0D9488'];
  if (v <= 100) return ['ปานกลาง', 'warn', '#0284C7', '#0284C7'];
  return              ['อันตราย',  'bad',  '#C36D4B', '#C36D4B'];
}
function co2LevelFull(v) {
  if (v < 800)  return ['สะอาด',   'good', '#0D9488', '#0D9488'];
  if (v < 1000) return ['เพิ่มขึ้น', 'warn', '#0284C7', '#0284C7'];
  return              ['อับชื้น',  'bad',  '#C36D4B', '#C36D4B'];
}

function pm25Level(v) {
  if (v <= 12)  return { label: 'ดีเยี่ยม', pillClass: 'pill-ok' };
  if (v <= 35)  return { label: 'ปานกลาง',  pillClass: 'pill-warn' };
  return              { label: 'อันตราย',   pillClass: 'pill-bad' };
}
function pm25Color(v) {
  if (v <= 12) return '#0D9488';
  if (v <= 35) return '#0284C7';
  return '#C36D4B';
}
function co2Color(v) {
  if (v < 800)  return '#0D9488';
  if (v < 1000) return '#0284C7';
  return '#C36D4B';
}

/* ============================================================
   CONTROL RECOMMENDATIONS (mirrors app logic)
   ============================================================ */
function renderControlCards(pm25, co2, temp, humid) {
  // Air Purifier
  let purifier;
  if (pm25 > 35)     purifier = '🔴 เปิด (High Speed) — PM2.5 เกินมาตรฐาน';
  else if (pm25 > 12) purifier = '🟡 เปิด (Low Speed) — PM2.5 ปานกลาง';
  else                purifier = '🟢 ปิด — อากาศสะอาดดี';

  // Ventilation
  let ventilation;
  if (co2 > 1000)     ventilation = '🔴 เปิด (Max) — CO2 สูงมาก';
  else if (co2 > 800) ventilation = '🟡 เปิด — CO2 เพิ่มขึ้น';
  else                ventilation = '🟢 ปิด — CO2 ปกติ';

  // AC
  let ac;
  if (temp > 30)      ac = '🔴 เปิด (Cool 22°C) — ร้อนมาก';
  else if (temp > 26) ac = '🟡 เปิด (Cool 25°C) — อุ่น';
  else                ac = '🟢 ปิด / Eco Mode — อุณหภูมิเหมาะสม';

  // Humidity
  let humidity;
  if (humid < 40)      humidity = '🟡 Humidifier ON — อากาศแห้งเกิน';
  else if (humid > 60) humidity = '🟡 Dehumidifier ON — ความชื้นสูง';
  else                 humidity = '🟢 ปิด — ความชื้นเหมาะสม (40-60%)';

  $('cmd-purifier').textContent   = purifier;
  $('cmd-ventilation').textContent = ventilation;
  $('cmd-ac').textContent          = ac;
  $('cmd-humidity').textContent    = humidity;
}

/* ============================================================
   GAUGE CHARTS (Canvas-based semi-circle gauge)
   Uses Chart.js doughnut trick
   ============================================================ */
function drawGauge(canvasId, value, max, ranges, label, unit) {
  const canvas = $(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const theme = document.documentElement.getAttribute('data-theme') || 'light';
  const isLight = theme === 'light';

  // Determine color
  let color = '#0D9488';
  for (const r of ranges) {
    if (value >= r.min && value <= r.max) { color = r.color; break; }
  }

  const pct = clamp(value / max, 0, 1);
  const dpr = window.devicePixelRatio || 1;

  // Get reliable dimensions from bounding rect
  const rect = canvas.getBoundingClientRect();
  const W = rect.width  > 0 ? rect.width  : 220;
  const H = rect.height > 0 ? rect.height : 170;

  // Set actual canvas pixel size
  canvas.width  = Math.round(W * dpr);
  canvas.height = Math.round(H * dpr);
  ctx.scale(dpr, dpr);

  // Layout constants — gauge sits at bottom-center of canvas
  const ro  = Math.min(W / 2, H) - 20;   // outer radius
  const ri  = ro - Math.max(18, ro * 0.28); // inner radius (ring thickness)
  const cxr = W / 2;
  const cyr = H - 16;                     // pivot point near bottom

  ctx.clearRect(0, 0, W, H);

  // ── Background arc (track) ──
  ctx.beginPath();
  ctx.arc(cxr, cyr, ro, Math.PI, 2 * Math.PI);
  ctx.arc(cxr, cyr, ri, 2 * Math.PI, Math.PI, true);
  ctx.closePath();
  ctx.fillStyle = isLight ? 'rgba(15, 23, 42, 0.07)' : 'rgba(255, 255, 255, 0.08)';
  ctx.fill();

  // ── Colored value arc ──
  const startAngle = Math.PI;
  const endAngle   = Math.PI + pct * Math.PI;
  ctx.beginPath();
  ctx.arc(cxr, cyr, ro, startAngle, endAngle);
  ctx.arc(cxr, cyr, ri, endAngle, startAngle, true);
  ctx.closePath();
  ctx.fillStyle = color;
  ctx.shadowBlur = isLight ? 6 : 14;
  ctx.shadowColor = color;
  ctx.fill();
  ctx.shadowBlur = 0;

  // ── Value text (large, centered inside arc) ──
  const valueFontSize = Math.max(14, Math.floor(ro * 0.42));
  ctx.fillStyle = isLight ? '#0F172A' : '#FFFFFF';
  ctx.font = `800 ${valueFontSize}px Inter, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'alphabetic';
  const textY = cyr - ro * 0.28;
  ctx.fillText(Number.isInteger(value) ? value : Number(value).toFixed(1), cxr, textY);

  // ── Unit text (below value) ──
  const unitFontSize = Math.max(9, Math.floor(ro * 0.19));
  ctx.fillStyle = isLight ? '#475569' : 'rgba(255,255,255,0.6)';
  ctx.font = `500 ${unitFontSize}px Inter, sans-serif`;
  ctx.fillText(unit, cxr, textY + valueFontSize * 0.85);

  // ── Min / Max edge labels ──
  const edgeFontSize = Math.max(8, Math.floor(ro * 0.17));
  ctx.fillStyle = isLight ? '#94A3B8' : 'rgba(255,255,255,0.35)';
  ctx.font = `400 ${edgeFontSize}px Inter, sans-serif`;
  ctx.textBaseline = 'top';
  ctx.textAlign = 'left';
  ctx.fillText('0', cxr - ro + 2, cyr + 4);
  ctx.textAlign = 'right';
  ctx.fillText(max, cxr + ro - 2, cyr + 4);

  // ── Label (sensor name) below arc, centered ──
  const labelFontSize = Math.max(9, Math.floor(ro * 0.18));
  ctx.fillStyle = isLight ? '#475569' : 'rgba(255,255,255,0.6)';
  ctx.font = `600 ${labelFontSize}px Inter, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'top';
  ctx.fillText(label, cxr, cyr + edgeFontSize + 6);
}

const PM25_RANGES = [
  { min: 0,  max: 12,   color: '#0D9488' },
  { min: 12, max: 35,   color: '#0284C7' },
  { min: 35, max: 9999, color: '#C36D4B' },
];
const CO2_RANGES = [
  { min: 0,    max: 800,  color: '#0D9488' },
  { min: 800,  max: 1000, color: '#0284C7' },
  { min: 1000, max: 9999, color: '#C36D4B' },
];
const TEMP_RANGES = [
  { min: 0,  max: 26,   color: '#0D9488' },
  { min: 26, max: 30,   color: '#0284C7' },
  { min: 30, max: 9999, color: '#C36D4B' },
];

function refreshGauges(pm25, co2, temp) {
  if (pm25 === undefined && STATE.site4Data) {
    pm25 = parseFloat(STATE.site4Data['PM2.5'] ?? 0);
    co2  = parseFloat(STATE.site4Data.CO2 ?? 0);
    temp = parseFloat(STATE.site4Data.temp ?? 0);
  }
  drawGauge('gauge-pm25', pm25 || 0, 75,   PM25_RANGES, 'PM2.5',       'µg/m³');
  drawGauge('gauge-co2',  co2  || 0, 1500, CO2_RANGES,  'CO2',         'ppm');
  drawGauge('gauge-temp', temp || 0, 45,   TEMP_RANGES, 'Temperature', '°C');
}

/* Re-draw gauges on window resize */
let _resizeTimer;
window.addEventListener('resize', () => {
  clearTimeout(_resizeTimer);
  _resizeTimer = setTimeout(() => {
    if (STATE.site4Data) refreshGauges();
  }, 200);
});

/* ============================================================
   TREND CHART (Chart.js Line)
   ============================================================ */
function updateTrendChart() {
  const ctx = $('trendChart');
  if (!ctx) return;

  const theme = document.documentElement.getAttribute('data-theme') || 'light';
  const isLight = theme === 'light';
  const textColor = isLight ? '#475569' : 'rgba(255,255,255,0.6)';
  const gridColor = isLight ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.06)';

  if (STATE.trendChart) {
    STATE.trendChart.data.labels                  = [...STATE.historyLabels];
    STATE.trendChart.data.datasets[0].data        = [...STATE.historyPM25];
    STATE.trendChart.data.datasets[1].data        = [...STATE.historyCO2].map(v => v / 10);
    STATE.trendChart.data.datasets[2].data        = [...STATE.historyTemp];
    STATE.trendChart.options.plugins.legend.labels.color = textColor;
    STATE.trendChart.options.scales.x.ticks.color = textColor;
    STATE.trendChart.options.scales.y.ticks.color = textColor;
    STATE.trendChart.options.scales.x.grid.color  = gridColor;
    STATE.trendChart.options.scales.y.grid.color  = gridColor;
    STATE.trendChart.update('active');
    return;
  }

  STATE.trendChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [...STATE.historyLabels],
      datasets: [
        {
          label: 'PM2.5 (µg/m³)',
          data:  [...STATE.historyPM25],
          borderColor: '#0D9488',
          backgroundColor: 'rgba(13,148,136,0.12)',
          borderWidth: 2.5,
          pointRadius: 4,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.4,
        },
        {
          label: 'CO2 ÷10 (ppm/10)',
          data:  STATE.historyCO2.map(v => v / 10),
          borderColor: '#0284C7',
          backgroundColor: 'rgba(2,132,199,0.1)',
          borderWidth: 2.5,
          pointRadius: 4,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.4,
        },
        {
          label: 'Temp (°C)',
          data:  [...STATE.historyTemp],
          borderColor: '#C36D4B',
          backgroundColor: 'rgba(195,109,75,0.1)',
          borderWidth: 2.5,
          pointRadius: 4,
          pointHoverRadius: 6,
          fill: false,
          tension: 0.4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: {
            color: textColor,
            font: { family: 'Inter', size: 12 },
            boxWidth: 14,
          },
        },
        tooltip: {
          backgroundColor: isLight ? 'rgba(255,255,255,0.96)' : 'rgba(10,8,32,0.92)',
          titleColor: isLight ? '#0F172A' : 'rgba(255,255,255,0.9)',
          bodyColor:  isLight ? '#475569' : 'rgba(255,255,255,0.7)',
          borderColor: isLight ? '#E2E8F0' : 'rgba(13,148,136,0.35)',
          borderWidth: 1,
          padding: 12,
          cornerRadius: 10,
          bodyFont: { family: 'Inter' },
          titleFont: { family: 'Inter', weight: '600' },
        },
      },
      scales: {
        x: {
          ticks: { color: textColor, font: { size: 11, family: 'Inter' } },
          grid:  { color: gridColor },
        },
        y: {
          ticks: { color: textColor, font: { size: 11, family: 'Inter' } },
          grid:  { color: gridColor },
          beginAtZero: true,
        },
      },
    },
  });
}

/* ============================================================
   CSV EXPORT
   ============================================================ */
function downloadCSV() {
  if (!STATE.allSitesData.length) { showToast('ไม่มีข้อมูล', 'error'); return; }
  const headers = ['Site', 'Status', 'PM2.5', 'PM10', 'CO2', 'RSSI', 'Update'];
  const rows    = STATE.allSitesData.map(s =>
    [s.SiteName || ('Site '+s.Site), s.Status, s['PM2.5'], s.PM10, s.CO2, s.RSSI, s.Update]
      .map(v => `"${String(v ?? '').replace(/"/g, '""')}"`)
      .join(',')
  );
  const csv   = [headers.join(','), ...rows].join('\r\n');
  const blob  = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url   = URL.createObjectURL(blob);
  const a     = document.createElement('a');
  a.href      = url;
  a.download  = `AIIR_IAQ_${nowStr().replace(/[/:]/g, '-')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  showToast('✅ ดาวน์โหลด CSV สำเร็จ', 'success');
}

/* ============================================================
   INIT
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  initTheme();

  // Redraw gauges dynamically on window resize
  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (STATE.site4Data) refreshGauges();
    }, 150);
  });

  console.log('%c🌿 AIIR IAQ Dashboard loaded — Demo Mode: ' + CONFIG.demoMode,
    'color:#a78bfa;font-weight:700;font-size:14px;');
  console.log('%cLogin with username: admin, any password', 'color:#60a5fa;font-size:12px;');
});

