# 📘 รายงานอธิบายโค้ดทั้งหมดของโปรเจกต์ (Comprehensive Code Explanation)
**โปรเจกต์**: AIIR IAQ Smart Dashboard (ICT BRB)  
**จัดทำเมื่อ**: 3 สิงหาคม 2026  

---

## 📑 สารบัญ (Table of Contents)
1. [ภาพรวมของระบบ (System Overview)](#1-ภาพรวมของระบบ-system-overview)
2. [โครงสร้างไฟล์ (Project File Structure)](#2-โครงสร้างไฟล์-project-file-structure)
3. [อธิบายโค้ดอย่างละเอียดทีละไฟล์ (Detailed File Explanations)](#3-อธิบายโค้ดอย่างละเอียดทีละไฟล์-detailed-file-explanations)
   - [3.1 index.html (ส่วนแสดงผลโครงสร้างเว็บ UI)](#31-indexhtml-ส่วนแสดงผลโครงสร้างเว็บ-ui)
   - [3.2 proxy.php (ส่วน Backend API Proxy & Authentication)](#32-proxyphp-ส่วน-backend-api-proxy--authentication)
   - [3.3 js/app.js (ส่วน Business Logic, Fetching & Charts)](#33-jsappjs-ส่วน-business-logic-fetching--charts)
   - [3.4 css/style.css (ส่วนการจัดสไตล์, ดีไซน์ธีม & Animations)](#34-cssstylecss-ส่วนการจัดสไตล์-ดีไซน์ธีม--animations)
4. [สรุปวงจรการทำงานและการไหลของข้อมูล (Data Flow Architecture)](#4-สรุปวงจรการทำงานและการไหลของข้อมูล-data-flow-architecture)

---

## 1. ภาพรวมของระบบ (System Overview)

โปรเจกต์ **AIIR IAQ Smart Dashboard (ICT BRB)** เป็นระบบเว็บแอปพลิเคชันสำหรับติดตามและมอนิเตอร์คุณภาพอากาศภายในอาคาร (Indoor Air Quality - IAQ) แบบ Real-time โดยดึงข้อมูลจากเซ็นเซอร์ผ่าน **Emtrontech AIIR API Server**

### สถาปัตยกรรมระบบ (Architecture Overview):
```text
[ Browser Client ] ──(AJAX / JSON)──> [ PHP Proxy (proxy.php) ] ──(cURL Session)──> [ AIIR API Server ]
  index.html &                            - Login / SHA256                               emtrontech.com
  js/app.js                               - Cookie Jar Management
                                          - CORS Handling & Data Parsing
```

---

## 2. โครงสร้างไฟล์ (Project File Structure)

```text
c:\Users\tn_setthanan\Desktop\Copy\
├── index.html        # โครงสร้าง HTML5 Semantic หน้าแดชบอร์ด
├── proxy.php         # PHP Proxy จัดการ Authen, Session Cookie และ Route API Request
├── css/
│   └── style.css     # CSS Custom Properties, Design System Palette, Themes & Animations
├── js/
│   └── app.js        # Business Logic, Data Processing, Canvas Gauges, Chart.js & CSV Export
├── README.md         # เอกสารแนะนำโปรเจกต์และการใช้งาน
└── CODE_EXPLANATION.md # [ไฟล์นี้] อธิบายโค้ดโดยละเอียดทุกไฟล์
```

---

## 3. อธิบายโค้ดอย่างละเอียดทีละไฟล์ (Detailed File Explanations)

---

### 3.1 `index.html` (ส่วนแสดงผลโครงสร้างเว็บ UI)

[index.html](file:///c:/Users/tn_setthanan/Desktop/Copy/index.html) เป็นไฟล์โครงสร้างเว็บ (HTML5 Semantic) สำหรับจัดวาง Layout และ Element ทั้งหมดของ Dashboard

#### โครงสร้างหลักภายในไฟล์:
1. **`<head>` (บรรทัด 1–14)**:
   - นำเข้า Font **Inter** จาก Google Fonts
   - นำเข้าไลบรารี **Chart.js v4.4.3** จาก CDN สำหรับแสดงกราฟเส้นแนวโน้ม
   - เชื่อมต่อกับไฟล์สไตล์ [`css/style.css`](file:///c:/Users/tn_setthanan/Desktop/Copy/css/style.css)

2. **`<aside class="sidebar" id="sidebar">` (บรรทัด 18–65)**:
   - **Header & Logo**: แสดงโลโก้ 🌿 และชื่อแบรนด์ AIIR Dashboard
   - **Status Badge (`#statusBadge`)**: แสดงสถานะการเชื่อมต่อ (Connected / Disconnected)
   - **Login Form (`#loginForm`)**: ฟอร์มกรอก Username/Password (จะถูกซ่อนอัตโนมัติเมื่อเข้าสู่ระบบสำเร็จ)
   - **User Session Card (`#userSessionCard`)**: การ์ดแสดงชื่อผู้ใช้ที่กำลังเข้าสู่ระบบและปุ่ม Logout (แสดงผลเฉพาะเมื่อเข้าสู่ระบบสำเร็จ)
   - **Auto-Refresh Toggle (`#autoRefreshToggle`)**: สวิตช์เปิด/ปิดการดึงข้อมูลใหม่อัตโนมัติทุก 30 วินาที
   - **Last Update (`#lastUpdateWrap`)**: แสดงเวลาอัปเดตข้อมูลล่าสุดจากเซ็นเซอร์

3. **`<main class="main-content" id="mainContent">`**:
   - **Topbar (`.topbar`)**: แถบด้านบนแบบตรึง มีปุ่มสลับการพับ/กาง Sidebar (`#sidebarToggleBtn`) และส่วนแสดงชื่อบัญชีผู้ใช้มุมขวาบน (`#topbarUserArea`) พร้อมปุ่ม Logout
   - **Welcome Screen (`#welcomeScreen`)**: หน้าจอต้อนรับ แสดงเมื่อผู้ใช้ยังไม่ได้ Login
   - **Dashboard Container (`#dashboard`)**: หน้าจอแสดงผลหลัก (เปิดใช้งานและซ่อนหน้า Login เมื่อ Login สำเร็จ):
      - **Room Panel: SITE 4 ICT 401 (`#panel-site4`)**:
        - **Hero Gauge Cards (3 ตัววัดหลัก)**: รวมเกจครึ่งวงกลมและตัวเลขของ PM2.5, CO2, และ อุณหภูมิ ไว้ในการ์ดใบเดียวกันเพื่อความสมส่วน
        - **Environmental Telemetry Grid (4 ตัววัดสภาพแวดล้อม)**: PM10, ความชื้น, EVOC และ RSSI Signal จัดวางใน 4 คอลัมน์กะทัดรัด
        - **Control Recommendations**: การ์ดแสดงคำแนะนำการทำงานอุปกรณ์อัตโนมัติ (Air Purifier, Ventilation, AC, Humidity Control)
        - **Historical Trend Chart**: Canvas สำหรับกราฟเส้นแนวโน้ม 10 ครั้งล่าสุด (`#trendChart`)
   - **Toast Notification (`#toast`)**: กรอบแจ้งเตือนข้อความสถานะมุมล่าง

---

### 3.2 `proxy.php` (ส่วน Backend API Proxy, Smart Routing & Authentication)

[proxy.php](file:///c:/Users/tn_setthanan/Desktop/Copy/proxy.php) ทำหน้าที่เป็นตัวกลาง (Middleware/Proxy) ระหว่างหน้าเว็บเบราว์เซอร์กับ **Emtrontech AIIR API Server** ออกแบบใหม่รองรับทั้ง **เซิร์ฟเวอร์ VM ภายในองค์กร** (ผ่าน Corporate Proxy อัตโนมัติ) และ **เซิร์ฟเวอร์เน็ตนอก/Cloud** (ผ่าน Direct Connection) อย่างเสถียร 100%

#### โครงสร้างและการทำงานภายในไฟล์:
1. **Smart Route Detection & Persistence (`getCandidateRoutes()`, `saveCachedRoute()`)**:
   - **เซิร์ฟเวอร์เน็ตนอก (Cloud/Direct)**: เชื่อมต่อไปยัง `https://emtrontech.com` ได้โดยตรง ไม่ต้องผ่าน Proxy
   - **เซิร์ฟเวอร์ VM ภายในองค์กร (10.x.x.x)**: ไฟร์วอลล์บล็อกพอร์ต 443 ขาออกตรง ระบบจะตรวจจับและวิ่งผ่าน Corporate Proxy (`10.7.21.17:8080`, `ssproxy.boonrawd.co.th:8080`) หรือ Environment Variables (`HTTP_PROXY`, `http_proxy`) อัตโนมัติ
   - **Route Caching (5 นาที)**: เมื่อทดสอบพบเส้นทางที่เชื่อมต่อได้สำเร็จ ระบบจะจดจำเส้นทางที่ใช้งานได้ไว้ในไฟล์แคชชั่วคราว (`/tmp/aiir_active_route.json`) ทำให้ Request ถัดไปยิงได้ทันทีโดยไม่ต้องเสียเวลา Probe ทุกครั้ง
   - **Adaptive Failover**: หากเส้นทางเดิมสะดุด ระบบจะสลับเส้นทางสำรอง (Direct <-> Proxy) ให้อัตโนมัติทันที

2. **Direct Single-Call Telemetry (`handleGetSpecData()`)**:
   - ยิงคำขอไปยัง `getSpecSiteData.php` ด้วย `POST site=4&siteType=4` เพียงครั้งเดียว ได้ JSON สดทันที โดยไม่ต้องโหลดหน้า HTML ซ้ำซ้อน และไม่ต้องใช้คุกกี้ล็อกอิน
   - ลดเวลาตอบสนองจาก 3-8 วินาที เหลือเพียง ~300-500ms

3. **Anti-DoS Rate Limiter พร้อม Private Subnet Whitelist (`checkRateLimit()`)**:
   - ยกเว้นการจำกัดอัตราคำขอ (Whitelist) สำหรับ Localhost (`127.0.0.1`, `::1`) และ Private Subnets (`10.*`, `172.16-31.*`, `192.168.*`) เพื่อป้องกันไม่ให้การ Auto-refresh หรือการเปิดหลายแท็บบนเซิร์ฟเวอร์ VM ถูกตัดเป็น HTTP 429
   - เพิ่มเพดานคำขอสำหรับ IP ทั่วไปเป็น 300 ครั้งต่อนาที

4. **Server Diagnostic Mode (`?action=diag`)**:
   - Endpoint สำหรับตรวจสอบและวินิจฉัยสุขภาพเครือข่ายของเซิร์ฟเวอร์ รายงานผลแบบ JSON ประกอบด้วยสถานะการต่อตรง, สถานะการต่อผ่าน Proxy แต่ละตัว, เวลา Latency, ข้อมูลสภาพแวดล้อม และสิทธิ์การเขียนไฟล์

5. **Server-Side Cache & History Logger**:
   - **5s Telemetry Cache**: แคชผลลัพธ์ 5 วินาที ลดภาระเซิร์ฟเวอร์ปลายทาง
   - **History Store**: บันทึกประวัติข้อมูลเซ็นเซอร์ย้อนหลัง 2,000 จุด (~16 ชั่วโมง) ลงใน `aiir_history_ict401.json`
   - **45-Minute Cache**: บันทึกสแนปช็อตระยะยาวลงใน `cache_45m_ict401.json` พร้อมคำนวณ AI IAQ Score (0-100%)

---

### 3.3 `js/app.js` (ส่วน Business Logic, Fetching & Charts)

[js/app.js](file:///c:/Users/tn_setthanan/Desktop/Copy/js/app.js) เป็นส่วนหัวใจสำคัญที่ควบคุมการทำงานฝั่ง Front-end ทั้งหมด

#### โครงสร้างและการทำงานภายในไฟล์:
1. **Config & State Objects (บรรทัด 9–31)**:
   - `CONFIG`: กำหนด URL ปลายทาง `proxy.php?action=getSpecData&site=4&siteType=4` สำหรับดึงข้อมูลห้อง ICT401 โดยเฉพาะ, รอบ Auto-Refresh (30,000 ms), จำนวนจุดย้อนหลังบนกราฟแนวโน้ม (10 จุด)
   - `STATE`: เก็บสถานะแอปพลิเคชัน เช่น `isLoggedIn`, `site4Data`, `historyLogs`, อาร์เรย์เก็บประวัติสำหรับกราฟเส้น, ตัวแปรเก็บ Chart Instance

2. **UI Controls & Theme (บรรทัด 52–112)**:
   - `toggleSidebar()`, `toggleSidebarCollapse()`: เปิด/ปิด หรือซ่อนแถบ Sidebar (รวมถึงปรับขนาด Canvas เกจหลังจากพับเมนู)
   - `toggleTheme()`, `applyTheme()`, `initTheme()`: สลับและบันทึกธีม Light/Dark Mode ลงใน `localStorage`

3. **Authentication Handlers (บรรทัด 166–240)**:
   - `handleLogin(e)`: รับการ Submit ฟอร์ม Login แสดง Spinner โหลด ยิง API ไปยัง `proxy.php?action=login`
   - `setConnected(on)`: ปรับ UI สถานะ Badge ด้านซ้าย (Online/Offline) และเปิด/ซ่อนหน้า Dashboard

4. **Data Fetching Engine (บรรทัด 242–335)**:
   - `fetchData()`: ดึงข้อมูลเฉพาะ **ห้อง SITE 4 ICT 401** จาก `CONFIG.specDataUrl`
   - `realFetchSite4()`: รับ JSON จาก `proxy.php` ตรวจสอบ Session Expired (ถ้า Session หมดอายุจะแจ้งเตือนและปรับสถานะเป็น Disconnected)
   - `startAutoRefresh()` / `stopAutoRefresh()`: จัดการ `setInterval` ตามสวิตช์ Auto-Refresh

5. **Data Rendering**:
   - `appendHistory(data)`: เพิ่มข้อมูลล่าสุดเข้าอาร์เรย์ประวัติ (จำกัดไว้ไม่เกิน 10 จุดล่าสุด) และบันทึกประวัติลงใน `historyLogs`
   - `renderSiteDetail(data)`: อัปเดตการ์ดตัววัดทั้ง 7 ตัวของ Site 4 (คำนวณเปอร์เซ็นต์หลอด Progress Bar และเปลี่ยนสีตามระดับความอันตราย)
   - `downloadCSV()`: ส่งออกไฟล์ CSV ประวัติข้อมูลย้อนหลังของห้อง ICT401

6. **AI Smart HVAC Automation Engine & Real-Time Alert System**:
   - `runAIInferenceEngine(pm25, pm10, co2, temp, humid, evoc)`: ประมวลผลด้วย AI Inference Model จำลองแบบ Multi-Variable Joint Decision Matrix:
     - **AI IAQ Score (0-100%)**: คำนวณคะแนนดัชนีสุขภาพอากาศรวมจาก PM2.5, PM10, CO2, EVOC, อุณหภูมิและความชื้น
     - **Steadman Heat Index Model**: คำนวณอุณหภูมิที่รู้สึกจริง (Perceived Temperature) ตามความชื้นสัมพัทธ์
     - **Air Purifier AI**: ประเมิน HEPA + Carbon Filter Boost (High 85-100% / Eco Auto 45% / Standby 15%)
     - **Ventilation AI**: คำนวณอัตราแลกเปลี่ยนอากาศ (Air Exchange Rate 3.8 ACH / Fresh Air Valve %)
     - **Air Conditioner AI**: ประเมินสภาวะสบายทางความร้อน (PMV Index) ปรับ Cool High / Cool Auto / Eco Saving
     - **Humidity Control AI**: คำนวณโหมดดึงความชื้น (Dehumidifier High/Low) ป้องกันไวรัสและเชื้อรา หรือเติมความชื้น (Humidifier)
     - **AI Reasoning Banner**: สร้างคำอธิบายแผนการทำงานของ AI ในภาษาธรรมชาติ (Natural Language AI Insight)
   - `checkAirQualityAlerts(pm25, pm10, co2, temp, humid, evoc)`: ระบบตรวจสอบค่าเกินมาตรฐาน Real-time:
     - **Threshold Detection**: เช็คเกณฑ์อันตราย (PM2.5 > 35, PM10 > 100, CO2 > 1000, Temp > 30°C, Humid > 70%, EVOC > 50)
     - **Alert Banner**: แสดงแถบแจ้งเตือนสีกระตุ้นฉุกเฉินสไตล์ Glassmorphic ลอยเด่นที่ด้านบน Dashboard
     - **Pulsing Card Effect**: ติดเอฟเฟกต์กระพริบเรืองแสงสีแดง (`card-alert-pulse`) บนการ์ดตัววัดที่ผิดปกติ
     - **Web Audio API Synth & Sound Toggle**: สังเคราะห์เสียง Beep แจ้งเตือนแบบไดนามิกพร้อมปุ่มเปิด/ปิดเสียงบน Top Bar

7. **Semi-Circle Gauges Rendering (บรรทัด 588–693)**:
   - `drawGauge(canvasId, value, max, ranges, label, unit)`: ใช้อัลกอริทึม HTML5 Canvas 2D Context วาดเกจทรงโค้งครึ่งวงกลม
   - รองรับ High-DPI Display (`devicePixelRatio`) การวาดส่วนโค้ง Background, Arc เติมค่าสีแบบ Dynamic Shadow และพิมพ์ตัวเลขพร้อม Label

8. **Historical Trend Line Chart (บรรทัด 703–804)**:
   - `updateTrendChart()`: ใช้ **Chart.js** วาดกราฟเส้นแสดงแนวโน้มย้อนหลัง 10 ครั้งล่าสุด 3 เส้นประกอบด้วย:
     - `PM2.5` (สี Teal `#0D9488`)
     - `CO2 ÷ 10` (สี Azure `#0284C7`)
     - `Temperature` (สี Terracotta `#C36D4B`)
   - รองรับการอัปเดตสีและข้อความอัตโนมัติเมื่อผู้ใช้เปลี่ยนธีม Light/Dark

9. **CSV Export (บรรทัด 806–824)**:
   - `downloadCSV()`: แปลงข้อมูล `STATE.allSitesData` เป็นรูปแบบ CSV สเกลตาม RFC 4180
   - ใส่ **UTF-8 BOM** (`\uFEFF`) นำหน้าเพื่อให้เปิดอ่านภาษาไทยบน Microsoft Excel ได้ถูกต้อง ไม่เป็นภาษาต่างดาว

---

### 3.4 `css/style.css` (ส่วนการจัดสไตล์, ดีไซน์ธีม & Animations)

[css/style.css](file:///c:/Users/tn_setthanan/Desktop/Copy/css/style.css) จัดการรูปแบบความสวยงาม โครงสร้าง Grid/Flexbox ธีมสี และ Effect การเคลื่อนไหว

#### โครงสร้างหลักภายในไฟล์:
1. **Design System & CSS Variables (บรรทัด 7–80)**:
   - **Light Mode Palette (`:root`, `:root[data-theme="light"]`)**:
     - Primary: Teal `#0D9488`
     - Secondary: Azure `#0284C7`
     - Tertiary: Terracotta `#C36D4B`
     - Neutral: Slate `#737877`
     - พื้นหลังหลัก: `linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 50%, #E2E8F0 100%)`
   - **Dark Mode Palette (`:root[data-theme="dark"]`)**:
     - ปรับพื้นหลังและการ์ดกระจก Glassmorphism เป็นโทนเข้ม มืด สบายตา (`rgba(20, 40, 37, 0.75)`)

2. **Sidebar & Responsive Navigation (บรรทัด 99–199)**:
   - สไตล์เมนูด้านข้าง กราฟิกการย่อ/กาง Sidebar (`body.sidebar-collapsed`)
   - ปรับสถานะจุดไฟกะพริบ Connection Dot Pulse Animation (`@keyframes dotPulse`)

3. **Glassmorphism Panels & Metric Cards (บรรทัด 280–480)**:
   - การ์ดกระจกใส (`.glass-panel`) ตกแต่งด้วย `backdrop-filter: blur(...)` พร้อมเงาละมุน
   - การ์ดแสดงผลตัววัด 7 ชนิด ตกแต่งหลอด Progress Bar แบบลื่นไหลด้วย CSS Transition

4. **Animations & Responsive Breakpoints (บรรทัด 750–914)**:
   - `@keyframes floatLogo`: Animation โลโก้ลอยขึ้นลงนุ่มนวล
   - `@keyframes spin`: Animation หมุนปุ่ม Refresh
   - Media Queries (`@media (max-width: 1024px)`, `@media (max-width: 768px)`): รองรับการแสดงผลทุกขนาดหน้าจอ เช่น มือถือ แท็บเล็ต และคอมพิวเตอร์

---

## 4. สรุปวงจรการทำงานและการไหลของข้อมูล (Data Flow Architecture)

```mermaid
sequenceDiagram
    autonumber
    actor User as ผู้ใช้งาน (User)
    participant UI as Browser (index.html / app.js)
    participant PHP as PHP Proxy (proxy.php)
    participant API as Emtrontech AIIR Server

    User->>UI: กรอก Username & Password กด Login
    UI->>PHP: POST action=login {user, pass}
    PHP->>PHP: SHA256 Hash (user, pass)
    PHP->>API: cURL POST userAuthen.php
    API-->>PHP: Authen Success & Set Cookie
    PHP-->>UI: Response {ok: true}
    UI->>UI: แสดง Dashboard & เริ่มต้น Fetch Data

    loop ทุกๆ 30 วินาที (Auto-Refresh)
        UI->>PHP: GET action=getSiteData & action=getSpecData
        PHP->>API: cURL POST getSiteData.php & getSpecSiteData.php (พร้อม Cookie Jar)
        API-->>PHP: ส่งกลับข้อมูลเซ็นเซอร์ (JSON)
        PHP-->>UI: ส่งกลับข้อมูลที่ถูกจัดระเบียบแล้ว (Structured JSON)
        UI->>UI: อัปเดต Metric Cards, วาด Canvas Gauges, อัปเดต Chart.js
    end

    User->>UI: กดปุ่ม "ดาวน์โหลด CSV"
    UI->>User: ส่งออกไฟล์ CSV พร้อม UTF-8 BOM
```

---
*เอกสารนี้ถูกสร้างขึ้นเพื่ออธิบายรายละเอียดการทำงานของโค้ดโปรเจกต์ AIIR IAQ Smart Dashboard ทั้งหมดอย่างสมบูรณ์* 🌿
