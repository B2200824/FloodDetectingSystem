(() => {
  'use strict';
  const dataEl = document.getElementById('dashboard-data');
  const config = window.FLOOD_CONFIG || {};
  if (!dataEl) return;
  let data;
  try { data = JSON.parse(dataEl.textContent); } catch { return; }
  let allReadings = Array.isArray(data.readings) ? data.readings.slice() : [];
  let timer;
  const $ = (selector) => document.querySelector(selector);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number = (value, digits = 1) => value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toFixed(digits);
  const timeLabel = (date) => new Intl.DateTimeFormat(undefined, {hour:'2-digit',minute:'2-digit',hour12:false}).format(date);
  const parseReadingTime = (row) => new Date(String(row.recorded_at).replace(' ', 'T')).getTime();

  function setClock() {
    const el = $('#clock');
    if (el) el.textContent = new Intl.DateTimeFormat(undefined, {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date());
  }

  function renderChart() {
    const rangeMinutes = Number($('#chartRange')?.value || 60);
    const ordered = allReadings.slice().sort((a, b) => parseReadingTime(a) - parseReadingTime(b));
    const endTime = ordered.length ? parseReadingTime(ordered.at(-1)) : Date.now();
    const duration = rangeMinutes * 60000;
    const startTime = endTime - duration;
    const source = ordered.filter(row => parseReadingTime(row) >= startTime && parseReadingTime(row) <= endTime);
    const svg = $('#waterChart');
    const path = $('#chartPath');
    const area = $('#chartArea');
    const points = $('#chartPoints');
    const tickEl = $('#chartTimes');
    if (!svg || !path || !area || !points || !tickEl) return;
    const max = Number(data.maxDepth) || 20;
    // Match the chart axis and warning/danger threshold positions to the configured depth.
    const top = max;
    const xy = source.map(row => ({
      x: 8 + Math.max(0, Math.min(duration, parseReadingTime(row) - startTime)) / duration * 884,
      y: 8 + (1 - Math.min(top, Math.max(0, Number(row.water_level_cm) || 0)) / top) * 196,
      row
    }));
    const line = xy.map((p, i) => `${i ? 'L' : 'M'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' ');
    path.setAttribute('d', line);
    area.setAttribute('d', xy.length ? `${line} L ${xy.at(-1).x.toFixed(2)} 220 L ${xy[0].x.toFixed(2)} 220 Z` : '');
    points.innerHTML = xy.map((p, i) => `<circle class="${i === xy.length - 1 ? 'chart-point chart-point-latest' : 'chart-point'}" cx="${p.x.toFixed(2)}" cy="${p.y.toFixed(2)}" r="${i === xy.length - 1 ? 4 : 2.6}"><title>${escapeHtml(new Date(String(p.row.recorded_at).replace(' ', 'T')).toLocaleString())}: ${number(p.row.water_level_cm)} cm</title></circle>`).join('');
    const ticks = [startTime, startTime + duration / 2, endTime];
    const formatTick = timestamp => rangeMinutes === 1
      ? new Intl.DateTimeFormat(undefined, {minute:'2-digit',second:'2-digit',hour12:false}).format(new Date(timestamp))
      : timeLabel(new Date(timestamp));
    tickEl.innerHTML = ticks.map(timestamp => `<span>${escapeHtml(formatTick(timestamp))}</span>`).join('');
    const latest = source.at(-1);
    svg.setAttribute('aria-label', latest ? `Latest water level ${number(latest.water_level_cm)} centimeters; ${source.length} sensor readings displayed` : 'No sensor readings available');
  }

  function refreshVisibleReading(readings) {
    const current = readings[0];
    if (!current) return;
    const currentDate = new Date(String(current.recorded_at).replace(' ', 'T'));
    const level = Number(current.water_level_cm) || 0;
    const windowStart = currentDate.getTime() - 30000;
    const recent = readings.filter(row => {
      const timestamp = new Date(String(row.recorded_at).replace(' ', 'T')).getTime();
      return timestamp >= windowStart && timestamp <= currentDate.getTime();
    });
    const oldest = recent.at(-1) || current;
    const oldestDate = new Date(String(oldest.recorded_at).replace(' ', 'T'));
    const elapsedMinutes = (currentDate.getTime() - oldestDate.getTime()) / 60000;
    const trendRate = elapsedMinutes > 0 ? (level - Number(oldest.water_level_cm)) / elapsedMinutes : 0;
    const limit = Number(config.maxDepthCm) || 20;
    const percentage = Math.min(100, Math.max(0, level / limit * 100));
    const set = (selector, value) => { const el = $(selector); if (el) el.textContent = value; };
    set('#waterLevel', number(level));
    set('#levelPercent', `${Math.round(percentage)}%`);
    set('#temperature', `${number(current.temperature_c)}\u00b0C`);
    set('#humidity', `${number(current.humidity_pct, 0)}%`);
    set('#lastUpdated', currentDate.toLocaleTimeString([], {hour12:false}));
    set('#waterUpdatedAt', currentDate.toLocaleTimeString([], {hour12:false}));
    const fill = $('#levelFill');
    if (fill) fill.style.width = `${percentage}%`;
    const rainShift = Number(config.rainShiftCm) || 2;
    const liveRain = !!Number(current.rain_detected);
    const activeAlert = (Number(config.alertDepthCm) || 9) - (liveRain ? rainShift : 0);
    const activeWarning = (Number(config.warningDepthCm) || 13) - (liveRain ? rainShift : 0);
    const activeDanger = (Number(config.dangerDepthCm) || 17) - (liveRain ? rainShift : 0);
    const thresholds = [
      ['alertMarker', 'alertThreshold', activeAlert, 'Alert'],
      ['warningMarker', 'warningThreshold', activeWarning, 'Warning'],
      ['dangerMarker', 'dangerThreshold', activeDanger, 'Danger']
    ];
    thresholds.forEach(([markerId, labelId, depth, label]) => {
      const marker = document.getElementById(markerId);
      if (marker) marker.style.left = `${depth / limit * 100}%`;
      const thresholdLabel = document.getElementById(labelId);
      if (thresholdLabel) thresholdLabel.innerHTML = `${label} <strong>${depth} cm</strong>`;
    });
    const chartThresholds = [
      ['alertThresholdLine', activeAlert, 'ALERT'],
      ['warningThresholdLine', activeWarning, 'WARNING'],
      ['dangerThresholdLine', activeDanger, 'DANGER']
    ];
    chartThresholds.forEach(([lineId, depth, label]) => {
      const line = document.getElementById(lineId);
      if (!line) return;
      line.style.bottom = `${depth / limit * 100}%`;
      const caption = line.querySelector('span');
      if (caption) caption.textContent = `${label} · ${depth} CM`;
    });
    const steady = Math.abs(trendRate) < .5;
    const reportedStatus = String(current.alert_level || 'normal').toLowerCase();
    let status = reportedStatus;
    if (!['normal','watch','warning','danger'].includes(status)) status = 'normal';
    const titles = {normal:'All systems normal',watch:'Conditions to monitor',warning:'Rising water · Warning',danger:'Critical flood risk'};
    const banner = $('#alertBanner');
    if (banner) banner.className = `alert-banner alert-${status}`;
    set('#alertTitle', titles[status]);
    set('#alertPill', status.toUpperCase());
    set('#alertCaption', status === 'normal' ? 'Sensor readings are within normal operating ranges.' : `Water level ${steady ? 'stable' : trendRate > 0 ? 'increasing' : 'decreasing'} · Last reading ${currentDate.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',hour12:false})}`);
    const rain = document.querySelector('.rain-status');
    if (rain) {
      const active = !!Number(current.rain_detected);
      rain.classList.toggle('rain-active', active);
      rain.innerHTML = `<span class="rain-led"></span> Rain ${active ? 'detected' : 'not detected'}`;
    }
    const chance = current.rain_probability_pct;
    const probabilityEl = $('.rain-probability');
    if (probabilityEl) probabilityEl.textContent = chance == null ? 'No forecast' : `${number(chance,0)}% chance`;
    const footer = $('.page-footer>span:first-child');
    if (footer) footer.innerHTML = `<span class="footer-led is-online"></span> Receiving sensor data`;
  }

  function renderTable(readings, total) {
    const body = $('#readingsBody');
    if (!body) return;
    const shown = readings.slice(0, 8);
    body.innerHTML = shown.map((row, i) => {
      const previous = readings[i + 1];
      const dt = new Date(String(row.recorded_at).replace(' ', 'T'));
      const priorDt = previous ? new Date(String(previous.recorded_at).replace(' ', 'T')) : dt;
      const rate = previous ? (Number(row.water_level_cm) - Number(previous.water_level_cm)) / Math.max((dt-priorDt)/60000,1/60) : 0;
      const status = ['normal','watch','warning','danger'].includes(String(row.alert_level)) ? row.alert_level : 'normal';
      const rain = !!Number(row.rain_detected);
      return `<tr><td class="timestamp-cell"><span class="row-led ${status==='warning'?'row-led-warning':status==='danger'?'row-led-danger':''}"></span><span>${escapeHtml(dt.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}))}</span><span class="timestamp-time">${escapeHtml(dt.toLocaleTimeString([], {hour12:false}))}</span></td><td><strong class="table-water-value">${number(row.water_level_cm)}<span> cm</span></strong></td><td class="rate-cell ${rate>.01?'rate-positive':rate<-.01?'rate-negative':''}">${Math.abs(rate)>.001?`${rate>0?'&#8599;':'↘'} ${number(Math.abs(rate),2)} <span>cm/m</span>`:'—'}</td><td><span class="table-rain ${rain?'rain-active':''}"><span class="rain-led"></span>${rain?'Detected':'None'}</span></td><td>${number(row.temperature_c)}<span class="unit-label">\u00b0C</span></td><td>${number(row.humidity_pct,0)}<span class="unit-label">%</span></td><td><span class="status-pill status-${status}"><span></span>${status.charAt(0).toUpperCase()+status.slice(1)}</span></td></tr>`;
    }).join('');
    const count = $('#readingCount');
    if (count) count.textContent = String(total);
    const totalEl = $('#totalReadings');
    if (totalEl && totalEl.textContent !== '—') totalEl.textContent = String(total);
    const shownEl = $('.table-footer>span');
    if (shownEl) shownEl.innerHTML = `Showing <strong>${shown.length}</strong> of <strong id="totalReadings">${total}</strong> recent readings`;
  }

  function notify(message) {
    document.querySelector('.toast')?.remove();
    const el = document.createElement('div');
    el.className = 'toast'; el.setAttribute('role','status'); el.textContent = message;
    document.body.append(el);
    setTimeout(() => el.remove(), 2600);
  }

  function updateScenario(level, rainy) {
    const rainShift = rainy ? Number(data.rainShift) || 2 : 0;
    const alert = (Number(data.alertDepth) || 9) - rainShift;
    const warning = (Number(data.warningDepth) || 13) - rainShift;
    const danger = (Number(data.dangerDepth) || 17) - rainShift;
    const value = Math.min(Number(data.maxDepth) || 20, Math.max(0, Number(level) || 0));
    const status = value >= danger ? 'danger' : value >= warning ? 'warning' : value >= alert ? 'alert' : 'normal';
    const output = $('#scenarioLevelValue');
    if (output) output.textContent = value.toFixed(1);
    const result = $('#scenarioResult');
    if (result) result.dataset.risk = status;
    const titles = {normal:'Low flood risk',alert:'Alert stage',warning:'Warning stage',danger:'Danger stage'};
    const advice = status === 'danger' ? `Water is at or above the ${danger} cm danger threshold. Prepare an evacuation response.` : status === 'warning' ? `Water is at or above the ${warning} cm warning threshold. Keep monitoring closely.` : status === 'alert' ? `Water is at or above the ${alert} cm alert threshold. Watch the readings.` : 'Conditions are below the alert threshold.';
    const risk = $('#scenarioRisk');
    if (risk) risk.textContent = titles[status];
    const note = $('#scenarioAdvice');
    if (note) note.textContent = advice;
    const rain = $('#scenarioRain');
    if (rain) rain.textContent = rainy ? '☂ Rain active' : '☀ Dry';
    const slider = $('#scenarioLevel');
    if (slider) slider.style.setProperty('--scenario-fill', `${value / (Number(data.maxDepth) || 20) * 100}%`);
  }

  const scenarioSlider = $('#scenarioLevel');
  scenarioSlider?.addEventListener('input', () => updateScenario(scenarioSlider.value, $('#scenarioResult')?.dataset.rain === 'true'));
  document.querySelectorAll('.scenario-preset').forEach(button => button.addEventListener('click', () => {
    const slider = $('#scenarioLevel');
    const result = $('#scenarioResult');
    const rainy = button.dataset.rain === '1';
    if (slider) slider.value = button.dataset.level || 0;
    if (result) result.dataset.rain = String(rainy);
    document.querySelectorAll('.scenario-preset').forEach(item => item.classList.toggle('is-selected', item === button));
    updateScenario(slider?.value, rainy);
  }));
  if ($('#scenarioResult')) {
    $('#scenarioResult').dataset.rain = 'false';
    updateScenario(scenarioSlider?.value, false);
  }

  async function refresh(manual = false) {
    const button = $('#refreshButton');
    if (button) button.disabled = true;
    try {
      const response = await fetch(`${config.site || ''}/api.php`, {headers:{Accept:'application/json'},cache:'no-store'});
      if (!response.ok) throw new Error('No live database connection');
      const result = await response.json();
      if (!result.ok || !Array.isArray(result.readings)) throw new Error(result.error || 'Invalid server response');
      if ($('#chartRange')) {
        const byId = new Map([...allReadings, ...result.readings].map(row => [String(row.id), row]));
        allReadings = [...byId.values()].sort((a, b) => parseReadingTime(a) - parseReadingTime(b)).slice(-5000);
      } else {
        allReadings = result.readings;
      }
      if (allReadings.length) {
        data.demo = false;
        if (!data.dbOnline) location.reload();
        data.dbOnline = result.database_online;
        const pill = $('.local-pill');
        if (pill) pill.innerHTML = '<span class="status-led is-online"></span> Local server';
        const source = $('.data-source');
        if (source) source.innerHTML = '<span class="database-icon">◈</span> MySQL &middot; Local database';
        const demoNotice = document.querySelector('.demo-notice');
        if (demoNotice) demoNotice.remove();
        const newestFirst = $('#chartRange') ? allReadings.slice().reverse() : allReadings;
        refreshVisibleReading(newestFirst);
        renderTable(newestFirst, result.total);
        renderChart();
        if (manual) notify('Sensor readings refreshed.');
      } else if (manual) notify('No sensor readings have been recorded yet.');
    } catch (error) {
      if (manual) notify(error.message || 'Unable to refresh sensor readings.');
    } finally {
      if (button) button.disabled = false;
    }
  }

  const range = $('#chartRange');
  if (range) range.addEventListener('change', renderChart);
  $('#menuButton')?.addEventListener('click', () => $('#sidebar')?.classList.toggle('sidebar-open'));
  document.addEventListener('click', (event) => { if (innerWidth <= 650 && !event.target.closest('#sidebar,#menuButton')) $('#sidebar')?.classList.remove('sidebar-open'); });
  setClock(); setInterval(setClock, 1000);
  renderChart();
  timer = window.setInterval(() => refresh(false), Number(config.pollMs) || 15000);
  void timer;
})();
