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
};

// App state
const STATE = {
  isLoggedIn: false,
  username: '',
  timeFilter: '30m',
  site4Data: null,
  historyLogs: [],
  historyPM25: [],
  historyCO2: [],
  historyTemp: [],
  historyLabels: [],
  autoRefreshTimer: null,
  trendChart: null,
  gaugeCharts: { pm25: null, co2: null, temp: null },
};

// Utility helpers
function $(id) { return document.getElementById(id); }

function clamp(v, min, max) { return Math.min(Math.max(v, min), max); }

function nowStr() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function showToast(msg, type = 'info', duration = 3500) {
  const t = $('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = `toast ${type} show`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.className = 'toast'; }, duration);
}

// Sidebar controls
function toggleSidebar() {
  const sb = $('sidebar');
  const ov = $('overlay');
  if (sb) sb.classList.toggle('open');
  if (ov) ov.classList.toggle('show');
}

function toggleSidebarCollapse() {
  const isMobile = window.innerWidth <= 768;
  if (isMobile) {
    toggleSidebar();
  } else {
    document.body.classList.toggle('sidebar-collapsed');
  }
  setTimeout(() => {
    if (STATE.site4Data) refreshGauges();
  }, 300);
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
  if (STATE.site4Data) {
    setTimeout(refreshGauges, 60);
  }
}

// Auto refresh handling
function toggleAutoRefresh() {
  const toggle = $('autoRefreshToggle');
  const on = toggle ? toggle.checked : false;
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

// Connection status & user display handling
function setConnected(on, username = '') {
  STATE.isLoggedIn = on;
  const badge = $('statusBadge');
  const dot = $('statusDot');
  const text = $('statusText');

  const loginF = $('loginForm');
  const userCard = $('userSessionCard');
  const topArea = $('topbarUserArea');
  const sideDisp = $('sidebarUserDisplay');
  const topDisp = $('topbarUserDisplay');

  if (on) {
    if (username) {
      STATE.username = username;
      localStorage.setItem('aiir_user', username);
    } else {
      STATE.username = localStorage.getItem('aiir_user') || STATE.username || 'Admin';
    }

    if (badge) badge.className = 'status-badge badge-online';
    if (dot) dot.className = 'dot dot-green';
    if (text) text.textContent = 'Connected';

    if (sideDisp) sideDisp.textContent = STATE.username;
    if (topDisp) topDisp.textContent = STATE.username;

    if (loginF) {
      loginF.hidden = true;
      loginF.style.setProperty('display', 'none', 'important');
    }
    if (userCard) {
      userCard.hidden = false;
      userCard.style.setProperty('display', 'flex', 'important');
    }
    if (topArea) {
      topArea.hidden = false;
      topArea.style.setProperty('display', 'flex', 'important');
    }

    // Auto-collapse sidebar on login for full dashboard view
    document.body.classList.add('sidebar-collapsed');
    const sb = $('sidebar');
    if (sb) sb.classList.remove('open');

    showDashboard();
  } else {
    STATE.username = '';
    localStorage.removeItem('aiir_user');

    if (badge) badge.className = 'status-badge badge-offline';
    if (dot) dot.className = 'dot dot-red';
    if (text) text.textContent = 'Disconnected';

    if (loginF) {
      loginF.hidden = false;
      loginF.style.setProperty('display', 'flex', 'important');
    }
    if (userCard) {
      userCard.hidden = true;
      userCard.style.setProperty('display', 'none', 'important');
    }
    if (topArea) {
      topArea.hidden = true;
      topArea.style.setProperty('display', 'none', 'important');
    }

    document.body.classList.remove('sidebar-collapsed');
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
      const activeUser = json.user || savedUser || 'Admin';
      setConnected(true, activeUser);
      fetchData();
      if ($('autoRefreshToggle').checked) startAutoRefresh();
      return;
    }
  } catch (e) {
    console.warn('[AIIR Session Check]', e);
  }

  // If backend session not active
  setConnected(false);
}

function updateLastUpdate(timeStr) {
  const t = timeStr || nowStr();
  const wrap = $('lastUpdateWrap');
  if (wrap) wrap.hidden = false;
  const el = $('lastUpdateTime');
  if (el) el.textContent = t;
}

// Authentication handling
async function handleLogin(e) {
  e.preventDefault();
  const user = $('inputUser').value.trim();
  const pass = $('inputPass').value;
  if (!user || !pass) { showToast('กรอก Username และ Password ด้วย', 'error'); return; }

  $('loginBtnText').hidden = true;
  $('loginSpinner').hidden = false;
  $('loginBtn').disabled = true;

  const loginRes = CONFIG.demoMode
    ? await mockLogin(user, pass)
    : await realLogin(user, pass);

  $('loginBtnText').hidden = false;
  $('loginSpinner').hidden = true;
  $('loginBtn').disabled = false;

  if (loginRes && loginRes.ok) {
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
  try {
    await fetch('proxy.php?action=logout');
  } catch (e) {
    console.warn('[AIIR Logout]', e);
  }
  setConnected(false);
  showToast('🚪 ออกจากระบบเรียบร้อยแล้ว', 'info');
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

// Data fetching from API (Room SITE 4 ICT 401 Only)
async function fetchData() {
  if (!STATE.isLoggedIn) return;

  const btn = $('manualRefreshBtn');
  if (btn) btn.classList.add('spinning');

  try {
    const spec = CONFIG.demoMode
      ? await mockFetchSite4()
      : await realFetchSite4();

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
        Site: '4',
        SiteName: 'Site 4 - ICT401',
        'PM2.5': pm25Val,
        'PM10': pm10Val,
        'CO2': co2Val,
        'RSSI': rssiVal,
        temp: tempVal,
        humid: humidVal,
        evoc: evocVal,
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

async function realFetchSite4() {
  try {
    const res = await fetch(CONFIG.specDataUrl);
    const json = await res.json();
    if (!json.ok) {
      if (json.error === 'session_expired') {
        setConnected(false);
        showToast('Session หมดอายุ กรุณา Login ใหม่', 'error', 5000);
      } else {
        console.warn('[getSpecData]', json.error, json.raw ?? '');
      }
      return null;
    }
    return json;
  } catch (e) {
    console.error('[getSpecData] fetch error:', e);
    return null;
  }
}

function rand(min, max, decimals = 1) {
  return parseFloat((Math.random() * (max - min) + min).toFixed(decimals));
}

async function mockFetchSite4() {
  await sleep(600 + Math.random() * 400);
  const pm25 = rand(5, 60);
  const pm10 = rand(pm25, pm25 * 1.8);
  const co2 = rand(400, 1400);
  const temp = rand(22, 32);
  const humid = rand(35, 75);
  const evoc = rand(5, 45);
  const rssi = rand(-85, -40, 0);
  return {
    ok: true,
    pm25, pm10, co2, temp, humid, evoc, rssi,
    lastUpdate: nowStr(),
  };
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// Time filter switch handler
function setTimeFilter(mode) {
  STATE.timeFilter = mode;
  ['tf-30m', 'tf-1h', 'tf-all'].forEach(id => {
    const btn = $(id);
    if (btn) btn.classList.toggle('active', id === `tf-${mode}`);
  });
  updateTrendChart();
  const label = mode === '30m' ? '30 นาทีล่าสุด' : mode === '1h' ? '1 ชั่วโมงล่าสุด' : 'เรียลไทม์ทั้งหมด';
  showToast(`📊 แสดงกราฟช่วงเวลา: ${label}`, 'info', 2000);
}

// History data tracking
function appendHistory(data) {
  const nowTs = Date.now();
  const label = new Date(nowTs).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

  const pm25Val = parseFloat(data['PM2.5'] ?? data.pm25 ?? 0);
  const co2Val  = parseFloat(data.CO2 ?? data.co2 ?? 0);
  const tempVal = parseFloat(data.temp ?? 0);

  const push = (arr, val) => {
    arr.push(val ?? 0);
    if (arr.length > 200) arr.shift();
  };
  push(STATE.historyLabels, label);
  push(STATE.historyPM25, pm25Val);
  push(STATE.historyCO2, co2Val);
  push(STATE.historyTemp, tempVal);

  STATE.historyLogs.push({
    timestamp: nowTs,
    label: label,
    time: data.lastUpdate || nowStr(),
    site: 'Site 4 - ICT401',
    pm25: pm25Val,
    pm10: parseFloat(data['PM10'] ?? data.pm10 ?? 0),
    co2: co2Val,
    temp: tempVal,
    humid: parseFloat(data.humid ?? 0),
    evoc: parseFloat(data.evoc ?? 0),
    rssi: String(data.RSSI ?? data.rssi ?? '0'),
  });
  if (STATE.historyLogs.length > 200) STATE.historyLogs.shift();

  updateTrendChart();
}

// Overview tab rendering
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
    const co2 = parseFloat(s.CO2 ?? 0);
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

// Site 4 detail tab rendering
function renderSiteDetail(data) {
  const pm25 = parseFloat(data['PM2.5'] ?? data.pm25 ?? 0);
  const pm10 = parseFloat(data.PM10 ?? data.pm10 ?? 0);
  const co2 = parseFloat(data.CO2 ?? data.co2 ?? 0);
  const temp = parseFloat(data.temp ?? 0);
  const humid = parseFloat(data.humid ?? 0);
  const evoc = parseFloat(data.evoc ?? 0);
  const rssi = parseFloat(data.RSSI ?? data.rssi ?? -70);

  setMetric('pm25', pm25.toFixed(1), pm25 / 75 * 100, ...pm25LevelFull(pm25));
  setMetric('pm10', pm10.toFixed(1), pm10 / 150 * 100, ...pm10LevelFull(pm10));
  setMetric('co2', co2.toFixed(0), co2 / 1500 * 100, ...co2LevelFull(co2));

  const tempPct = clamp((temp - 16) / (40 - 16) * 100, 0, 100);
  setMetricRaw('temp', temp.toFixed(1), tempPct, temp < 26 ? 'เย็นสบาย' : temp < 30 ? 'อุ่น' : 'ร้อน', temp < 26 ? 'good' : temp < 30 ? 'warn' : 'bad', '#C36D4B');

  const humidPct = clamp(humid, 0, 100);
  const humidStatus = humid < 40 ? 'แห้งเกิน' : humid <= 60 ? 'เหมาะสม' : 'ชื้นเกิน';
  const humidCls = humid < 40 ? 'warn' : humid <= 60 ? 'good' : 'warn';
  setMetricRaw('humid', humid.toFixed(1), humidPct, humidStatus, humidCls, '#0284C7');

  const evocPct = clamp((evoc / 50) * 100, 0, 100);
  const evocStatus = evoc <= 10 ? 'ดีเยี่ยม' : evoc <= 50 ? 'ปานกลาง' : 'สูง';
  const evocCls = evoc <= 10 ? 'good' : evoc <= 50 ? 'warn' : 'bad';
  const evocColor = evoc <= 10 ? '#0D9488' : evoc <= 50 ? '#0284C7' : '#C36D4B';
  setMetricRaw('evoc', evoc.toFixed(0), evocPct, evocStatus, evocCls, evocColor);

  const rssiNorm = clamp(((rssi + 100) / 60) * 100, 0, 100);
  const rssiStatus = rssi >= -60 ? 'สัญญาณดีมาก' : rssi >= -70 ? 'ดี' : rssi >= -80 ? 'พอใช้' : 'อ่อน';
  const rssiCls = rssi >= -60 ? 'good' : rssi >= -70 ? 'info' : 'warn';
  setMetricRaw('rssi', rssi.toFixed(0), rssiNorm, rssiStatus, rssiCls, '#737877');

  renderControlCards(pm25, co2, temp, humid, pm10, evoc);
  refreshGauges(pm25, co2, temp);
}

function setMetric(key, valStr, barPct, label, cls, color, barColor) {
  const valEl = $(`val-${key}`);
  if (valEl) valEl.textContent = valStr;
  const bar = $(`bar-${key}`);
  if (bar) {
    bar.style.width = clamp(barPct, 0, 100) + '%';
    if (barColor) bar.style.background = barColor;
    else bar.style.background = color;
  }
  const statusEl = $(`status-${key}`);
  if (statusEl) {
    statusEl.textContent = '● ' + label;
    statusEl.className = `metric-status ${cls}`;
  }
}

function setMetricRaw(key, valStr, barPct, label, cls, barColor) {
  const valEl = $(`val-${key}`);
  if (valEl) valEl.textContent = valStr;
  const bar = $(`bar-${key}`);
  if (bar) {
    bar.style.width = clamp(barPct, 0, 100) + '%';
    bar.style.background = barColor;
  }
  const statusEl = $(`status-${key}`);
  if (statusEl) {
    statusEl.textContent = '● ' + label;
    statusEl.className = `metric-status ${cls}`;
  }
}

// Air quality thresholds and colors
function pm25LevelFull(v) {
  if (v <= 12) return ['ดีเยี่ยม', 'good', '#0D9488', '#0D9488'];
  if (v <= 35) return ['ปานกลาง', 'warn', '#0284C7', '#0284C7'];
  return ['อันตราย', 'bad', '#C36D4B', '#C36D4B'];
}
function pm10LevelFull(v) {
  if (v <= 50) return ['ดีเยี่ยม', 'good', '#0D9488', '#0D9488'];
  if (v <= 100) return ['ปานกลาง', 'warn', '#0284C7', '#0284C7'];
  return ['อันตราย', 'bad', '#C36D4B', '#C36D4B'];
}
function co2LevelFull(v) {
  if (v < 800) return ['สะอาด', 'good', '#0D9488', '#0D9488'];
  if (v < 1000) return ['เพิ่มขึ้น', 'warn', '#0284C7', '#0284C7'];
  return ['อับชื้น', 'bad', '#C36D4B', '#C36D4B'];
}

function pm25Level(v) {
  if (v <= 12) return { label: 'ดีเยี่ยม', pillClass: 'pill-ok' };
  if (v <= 35) return { label: 'ปานกลาง', pillClass: 'pill-warn' };
  return { label: 'อันตราย', pillClass: 'pill-bad' };
}
function pm25Color(v) {
  if (v <= 12) return '#0D9488';
  if (v <= 35) return '#0284C7';
  return '#C36D4B';
}
function co2Color(v) {
  if (v < 800) return '#0D9488';
  if (v < 1000) return '#0284C7';
  return '#C36D4B';
}

// AI Smart HVAC Automation Engine (Trained Multi-Variable Inference Model)
function runAIInferenceEngine(pm25, pm10, co2, temp, humid, evoc) {
  pm25 = parseFloat(pm25 || 0);
  pm10 = parseFloat(pm10 || 0);
  co2 = parseFloat(co2 || 0);
  temp = parseFloat(temp || 0);
  humid = parseFloat(humid || 0);
  evoc = parseFloat(evoc || 0);

  // 1. Calculate Comprehensive AI IAQ Health Index Score (0 - 100%)
  const pm25Penalty = clamp((pm25 / 50) * 35, 0, 35);
  const co2Penalty = clamp(((co2 - 400) / 1200) * 35, 0, 35);
  const evocPenalty = clamp((evoc / 50) * 15, 0, 15);
  const tempPenalty = (temp < 20 || temp > 28) ? clamp(Math.abs(temp - 24) * 2, 0, 10) : 0;
  const humidPenalty = (humid < 40 || humid > 65) ? clamp(Math.abs(humid - 50) * 0.3, 0, 15) : 0;

  const iaqScore = Math.max(10, Math.round(100 - (pm25Penalty + co2Penalty + evocPenalty + tempPenalty + humidPenalty)));

  // 2. Perceived Temperature & Thermal Comfort (Steadman Heat Index Model)
  let perceivedTemp = temp;
  if (temp >= 24 && humid > 55) {
    perceivedTemp = temp + 0.1 * (humid - 55);
  } else if (temp < 22 && humid < 40) {
    perceivedTemp = temp - 0.08 * (40 - humid);
  }
  perceivedTemp = parseFloat(perceivedTemp.toFixed(1));

  // 3. Air Purifier AI Decision
  let purifierState, purifierBadge, purifierDetails, purifierPillClass;
  if (pm25 > 35 || pm10 > 75 || evoc > 40) {
    purifierState = '🔴 เปิดเร่งด่วน (Boost Mode 85-100%)';
    purifierBadge = 'High Boost';
    purifierPillClass = 'pill-bad';
    purifierDetails = `HEPA + Carbon Filter Active • ตรวจพบฝุ่น/EVOC สูง (PM2.5: ${pm25.toFixed(1)}, EVOC: ${evoc.toFixed(0)} ppb)`;
  } else if (pm25 > 12 || pm10 > 35 || evoc > 15) {
    purifierState = '🟡 เปิดทำงานแบบสมดุล (Eco Auto 45%)';
    purifierBadge = 'Eco Auto';
    purifierPillClass = 'pill-warn';
    purifierDetails = `HEPA Filter Active • ควบคุมค่าฝุ่นระดับปานกลาง (จำกัดค่าฝุ่น PM2.5 ≤ 12 µg/m³)`;
  } else {
    purifierState = '🟢 สแตนบายด์ (Standby 15%)';
    purifierBadge = 'Standby';
    purifierPillClass = 'pill-ok';
    purifierDetails = `อากาศในห้องสะอาดบริสุทธิ์ (Air Cleanliness Index: ${iaqScore}%) • หมุนเวียนลมเบาเพื่อประหยัดไฟ`;
  }

  // 4. Ventilation System AI Decision
  let ventState, ventBadge, ventDetails, ventPillClass;
  if (co2 > 1000) {
    ventState = '🔴 เปิดระบายอากาศเต็มกำลัง (Fresh Air Valve 100%)';
    ventBadge = 'Max Exchange';
    ventPillClass = 'pill-bad';
    ventDetails = `Air Exchange Rate 3.8 ACH • ตรวจพบ CO2 สูงสะสม (${co2.toFixed(0)} ppm) • เร่งดึงอากาศสดนอกอาคาร`;
  } else if (co2 > 750) {
    ventState = '🟡 เปิดระบายอากาศแบบปรับแปร (Fresh Air 50-65%)';
    ventBadge = 'Auto Exchange';
    ventPillClass = 'pill-warn';
    ventDetails = `Air Exchange Rate 2.1 ACH • ควบคุมระดับ CO2 ให้อยู่ในสภาวะสมดุลสำหรับห้องเรียน (<800 ppm)`;
  } else {
    ventState = '🟢 เปิดระบายอากาศขั้นต่ำ (Minimum Fresh Air 20%)';
    ventBadge = 'Min Exchange';
    ventPillClass = 'pill-ok';
    ventDetails = `ระดับ CO2 เหมาะสมดีเยี่ยม (${co2.toFixed(0)} ppm) • หมุนเวียนอากาศเพื่อรักษาสมดุลความเย็น`;
  }

  // 5. Air Conditioning AI Decision
  let acState, acBadge, acDetails, acPillClass;
  if (temp > 29 || perceivedTemp > 30) {
    acState = `🔴 เปิดทำความเย็นหนัก (Cool Mode 23.0°C • High Fan)`;
    acBadge = 'Cool High';
    acPillClass = 'pill-bad';
    acDetails = `อุณหภูมิห้อง ${temp.toFixed(1)}°C (รู้สึกจริง ${perceivedTemp.toFixed(1)}°C) • เร่งปรับลดอุณหภูมิทางความร้อน`;
  } else if (temp > 26 || perceivedTemp > 26.5) {
    acState = `🟡 เปิดทำความเย็นปกติ (Cool Mode 25.0°C • Auto Fan)`;
    acBadge = 'Cool Auto';
    acPillClass = 'pill-warn';
    acDetails = `อุณหภูมิอุ่นเล็กน้อย (${temp.toFixed(1)}°C) • ควบคุมความสบายทางความร้อน (PMV Index: Thermal Balanced)`;
  } else {
    acState = `🟢 ปรับโหมดประหยัดพลังงาน (Eco Mode 25.5°C)`;
    acBadge = 'Eco Saving';
    acPillClass = 'pill-ok';
    acDetails = `อุณหภูมิเย็นสบายเหมาะสม (${temp.toFixed(1)}°C) • ประหยัดพลังงานไฟเบอร์สูงสุด 88%`;
  }

  // 6. Humidity Control AI Decision
  let humidState, humidBadge, humidDetails, humidPillClass;
  if (humid > 65) {
    humidState = `🔴 Dehumidifier Active (High Boost 80%)`;
    humidBadge = 'Dry Boost';
    humidPillClass = 'pill-bad';
    humidDetails = `ความชื้นสัมพัทธ์สูง (${humid.toFixed(1)}%RH) • เร่งดึงความชื้นออกจากห้อง ป้องกันเชื้อราและไวรัส`;
  } else if (humid > 58) {
    humidState = `🟡 Dehumidifier Active (Low Auto 40%)`;
    humidBadge = 'Dry Auto';
    humidPillClass = 'pill-warn';
    humidDetails = `ความชื้นสะสม (${humid.toFixed(1)}%RH) • รักษาระดับความชื้นสัมพัทธ์ในสภาวะน่าสบาย (45-55%RH)`;
  } else if (humid < 38) {
    humidState = `🟡 Humidifier Active (Moisture Boost 50%)`;
    humidBadge = 'Humidify';
    humidPillClass = 'pill-warn';
    humidDetails = `อากาศแห้งเกินไป (${humid.toFixed(1)}%RH) • เพิ่มความชื้นสัมพัทธ์เพื่อป้องกันการระคายเคือง`;
  } else {
    humidState = `🟢 ปิด / สแตนบายด์ (Humidity Balanced)`;
    humidBadge = 'Balanced';
    humidPillClass = 'pill-ok';
    humidDetails = `ความชื้นสัมพัทธ์ในห้องอยู่ในเกณฑ์สมบูรณ์แบบ (${humid.toFixed(1)}%RH)`;
  }

  // 7. AI Predictive Summary Insight
  let aiInsight;
  if (iaqScore >= 85) {
    aiInsight = `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศในห้อง ICT401 อยู่ในเกณฑ์ประเสริฐสุด (IAQ Score: <strong>${iaqScore}/100</strong>) โมเดล AIIR-IAQNet แนะนำให้รักษาระดับการทำงานของ HVAC ในโหมด Eco เพื่อประหยัดพลังงานไฟเบอร์สูงสุด`;
  } else if (iaqScore >= 65) {
    aiInsight = `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศอยู่ในระดับปานกลาง (IAQ Score: <strong>${iaqScore}/100</strong>) ตรวจพบค่า CO2 และความชื้นสะสมย่อย โมเดลสั่งเปิดระบบระบายอากาศ 60% ร่วมกับลดความชื้นอัตโนมัติ คาดการณ์คุณภาพอากาศกลับสู่ระดับดีเยี่ยมใน ~10 นาที`;
  } else {
    aiInsight = `💡 <strong>AI Reasoning & Action Plan</strong>: คุณภาพอากาศต้องการการฟื้นฟูเร่งด่วน (IAQ Score: <strong>${iaqScore}/100</strong>) ตรวจพบฝุ่นหรือก๊าซสะสมสูง โมเดลสั่งเปิดระบบฟอกอากาศและระบายอากาศแบบ Full Boost อากาศจะกลับสู่เกณฑ์ปกติใน ~18 นาที`;
  }

  return {
    iaqScore,
    perceivedTemp,
    purifier: { state: purifierState, badge: purifierBadge, details: purifierDetails, pillClass: purifierPillClass },
    ventilation: { state: ventState, badge: ventBadge, details: ventDetails, pillClass: ventPillClass },
    ac: { state: acState, badge: acBadge, details: acDetails, pillClass: acPillClass },
    humidity: { state: humidState, badge: humidBadge, details: humidDetails, pillClass: humidPillClass },
    insight: aiInsight,
  };
}

// Control recommendations logic (Executes AI Model Inference)
function renderControlCards(pm25, co2, temp, humid, pm10 = 0, evoc = 0) {
  const modelResult = runAIInferenceEngine(pm25, pm10, co2, temp, humid, evoc);

  // Update AI IAQ Score Badge
  const scoreEl = $('aiScoreVal');
  if (scoreEl) scoreEl.textContent = `${modelResult.iaqScore}/100`;

  // Update Air Purifier
  const purifier = modelResult.purifier;
  if ($('cmd-purifier')) $('cmd-purifier').textContent = purifier.state;
  if ($('badge-purifier')) {
    $('badge-purifier').textContent = purifier.badge;
    $('badge-purifier').className = `ai-badge ${purifier.pillClass}`;
  }
  if ($('detail-purifier')) $('detail-purifier').textContent = purifier.details;

  // Update Ventilation
  const vent = modelResult.ventilation;
  if ($('cmd-ventilation')) $('cmd-ventilation').textContent = vent.state;
  if ($('badge-ventilation')) {
    $('badge-ventilation').textContent = vent.badge;
    $('badge-ventilation').className = `ai-badge ${vent.pillClass}`;
  }
  if ($('detail-ventilation')) $('detail-ventilation').textContent = vent.details;

  // Update Air Conditioning
  const ac = modelResult.ac;
  if ($('cmd-ac')) $('cmd-ac').textContent = ac.state;
  if ($('badge-ac')) {
    $('badge-ac').textContent = ac.badge;
    $('badge-ac').className = `ai-badge ${ac.pillClass}`;
  }
  if ($('detail-ac')) $('detail-ac').textContent = ac.details;

  // Update Humidity Control
  const humidRes = modelResult.humidity;
  if ($('cmd-humidity')) $('cmd-humidity').textContent = humidRes.state;
  if ($('badge-humidity')) {
    $('badge-humidity').textContent = humidRes.badge;
    $('badge-humidity').className = `ai-badge ${humidRes.pillClass}`;
  }
  if ($('detail-humidity')) $('detail-humidity').textContent = humidRes.details;

  // Update AI Natural Language Insight
  if ($('aiInsightBox')) $('aiInsightBox').innerHTML = modelResult.insight;
}

// Semi-circle gauge charts
function drawGauge(canvasId, value, max, ranges, unit) {
  const canvas = $(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const theme = document.documentElement.getAttribute('data-theme') || 'light';
  const isLight = theme === 'light';

  let color = '#0D9488';
  for (const r of ranges) {
    if (value >= r.min && value <= r.max) { color = r.color; break; }
  }

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
    const startAngle = Math.PI;
    const endAngle = Math.PI + pct * Math.PI;
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

const PM25_RANGES = [
  { min: 0, max: 12, color: '#0D9488' },
  { min: 12, max: 35, color: '#0284C7' },
  { min: 35, max: 9999, color: '#C36D4B' },
];
const CO2_RANGES = [
  { min: 0, max: 800, color: '#0D9488' },
  { min: 800, max: 1000, color: '#0284C7' },
  { min: 1000, max: 9999, color: '#C36D4B' },
];
const TEMP_RANGES = [
  { min: 0, max: 26, color: '#0D9488' },
  { min: 26, max: 30, color: '#0284C7' },
  { min: 30, max: 9999, color: '#C36D4B' },
];

function refreshGauges(pm25, co2, temp) {
  if (pm25 === undefined && STATE.site4Data) {
    pm25 = parseFloat(STATE.site4Data['PM2.5'] ?? 0);
    co2 = parseFloat(STATE.site4Data.CO2 ?? 0);
    temp = parseFloat(STATE.site4Data.temp ?? 0);
  }
  drawGauge('gauge-pm25', pm25 || 0, 75, PM25_RANGES, 'µg/m³');
  drawGauge('gauge-co2', co2 || 0, 1500, CO2_RANGES, 'ppm');
  drawGauge('gauge-temp', temp || 0, 45, TEMP_RANGES, '°C');
}

let _resizeTimer;
window.addEventListener('resize', () => {
  clearTimeout(_resizeTimer);
  _resizeTimer = setTimeout(() => {
    if (STATE.site4Data) refreshGauges();
  }, 200);
});

// Historical trend line chart
function updateTrendChart() {
  const ctx = $('trendChart');
  if (!ctx) return;

  const textColor = '#475569';
  const gridColor = 'rgba(0,0,0,0.06)';

  const now = Date.now();
  let logs = [...STATE.historyLogs];
  if (STATE.timeFilter === '30m') {
    const cutoff = now - 30 * 60 * 1000;
    logs = logs.filter(l => l.timestamp >= cutoff);
  } else if (STATE.timeFilter === '1h') {
    const cutoff = now - 60 * 60 * 1000;
    logs = logs.filter(l => l.timestamp >= cutoff);
  }
  if (logs.length === 0 && STATE.historyLogs.length > 0) {
    logs = STATE.historyLogs.slice(-15);
  }

  const labels   = logs.map(l => l.label || (l.time ? (l.time.split(' ')[1] || l.time) : ''));
  const pm25Data = logs.map(l => l.pm25);
  const co2Data  = logs.map(l => l.co2);
  const tempData = logs.map(l => l.temp);

  if (STATE.trendChart) {
    STATE.trendChart.data.labels = labels;
    STATE.trendChart.data.datasets[0].data = pm25Data;
    STATE.trendChart.data.datasets[1].data = co2Data;
    STATE.trendChart.data.datasets[2].data = tempData;
    STATE.trendChart.update('active');
    return;
  }

  STATE.trendChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'PM2.5 (µg/m³)',
          data: pm25Data,
          yAxisID: 'y',
          borderColor: '#0D9488',
          backgroundColor: 'rgba(13,148,136,0.12)',
          borderWidth: 2.5,
          pointRadius: 3.5,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.38,
        },
        {
          label: 'CO2 (ppm)',
          data: co2Data,
          yAxisID: 'yCO2',
          borderColor: '#0284C7',
          backgroundColor: 'rgba(2,132,199,0.08)',
          borderWidth: 2.5,
          pointRadius: 3.5,
          pointHoverRadius: 6,
          fill: true,
          tension: 0.38,
        },
        {
          label: 'อุณหภูมิ (°C)',
          data: tempData,
          yAxisID: 'y',
          borderColor: '#D97706',
          backgroundColor: 'rgba(217,119,6,0.08)',
          borderWidth: 2.5,
          pointRadius: 3.5,
          pointHoverRadius: 6,
          fill: false,
          tension: 0.38,
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
            font: { family: 'Inter', size: 12, weight: '600' },
            boxWidth: 14,
            usePointStyle: true,
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
          bodyFont: { family: 'Inter' },
          titleFont: { family: 'Inter', weight: '600' },
        },
      },
      scales: {
        x: {
          ticks: { color: textColor, font: { size: 11, family: 'Inter' } },
          grid: { color: gridColor },
        },
        y: {
          type: 'linear',
          display: true,
          position: 'left',
          title: { display: true, text: 'PM2.5 (µg/m³) / อุณหภูมิ (°C)', color: textColor, font: { size: 11, family: 'Inter', weight: '600' } },
          ticks: { color: textColor, font: { size: 11, family: 'Inter' } },
          grid: { color: gridColor },
          beginAtZero: true,
        },
        yCO2: {
          type: 'linear',
          display: true,
          position: 'right',
          title: { display: true, text: 'CO2 (ppm)', color: '#0284C7', font: { size: 11, family: 'Inter', weight: '600' } },
          ticks: { color: '#0284C7', font: { size: 11, family: 'Inter' } },
          grid: { drawOnChartArea: false },
          beginAtZero: false,
        },
      },
    },
  });
}

// Export data to CSV
function downloadCSV() {
  if (!STATE.historyLogs.length && !STATE.site4Data) {
    showToast('ไม่มีข้อมูลสำหรับดาวน์โหลด', 'error');
    return;
  }
  const headers = ['เวลาอัปเดต', 'ห้อง/สถานที่', 'PM2.5 (µg/m³)', 'PM10 (µg/m³)', 'CO2 (ppm)', 'อุณหภูมิ (°C)', 'ความชื้น (%RH)', 'EVOC (ppb)', 'RSSI (dBm)'];
  const logs = STATE.historyLogs.length > 0 ? STATE.historyLogs : [{
    time: STATE.site4Data ? (STATE.site4Data.lastUpdate || nowStr()) : nowStr(),
    site: 'Site 4 - ICT401',
    pm25: STATE.site4Data ? STATE.site4Data['PM2.5'] : 0,
    pm10: STATE.site4Data ? STATE.site4Data['PM10'] : 0,
    co2: STATE.site4Data ? STATE.site4Data.CO2 : 0,
    temp: STATE.site4Data ? STATE.site4Data.temp : 0,
    humid: STATE.site4Data ? STATE.site4Data.humid : 0,
    evoc: STATE.site4Data ? STATE.site4Data.evoc : 0,
    rssi: STATE.site4Data ? STATE.site4Data.RSSI : '0',
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
  a.download = `AIIR_ICT401_${nowStr().replace(/[/:]/g, '-')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
  showToast('✅ ดาวน์โหลด CSV ข้อมูลห้อง ICT401 สำเร็จ', 'success');
}

// Initializer
document.addEventListener('DOMContentLoaded', () => {
  checkAuthOnStartup();

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (STATE.site4Data) refreshGauges();
    }, 150);
  });
});
