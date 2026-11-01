(() => {
  "use strict";

  const $ = selector => document.querySelector(selector);
  const chart = $("#xui-network-chart");
  const panel = $("#xui-live-panel");
  if (!panel || !chart) return;

  const state = {
    connectionId: null,
    previous: null,
    history: [],
    timer: null,
    busy: false
  };
  const historyLimit = 42;

  async function api(path) {
    const response = await fetch(path, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      cache: "no-store"
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.detail || "Ошибка запроса: HTTP " + response.status);
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function number(value) {
    if (value === null || value === undefined || value === "") return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function formatBytes(value) {
    if (!Number.isFinite(value) || value < 0) return "—";
    const units = ["Б", "КБ", "МБ", "ГБ", "ТБ"];
    let size = value;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
      size /= 1024;
      unit += 1;
    }
    return (unit === 0 ? size.toFixed(0) : size.toFixed(size >= 100 ? 0 : 1)) + " " + units[unit];
  }

  function formatRate(value) {
    if (!Number.isFinite(value) || value < 0) return "—";
    return formatBytes(value) + "/с";
  }

  function setText(selector, value) {
    const node = $(selector);
    if (node) node.textContent = value;
  }

  function setMeter(valueSelector, meterSelector, value, suffix = "%") {
    const node = $(valueSelector);
    const meter = $(meterSelector);
    if (node) node.textContent = value === null ? "н/д" : value.toFixed(1) + suffix;
    if (meter) meter.style.width = value === null ? "0%" : Math.max(0, Math.min(100, value)) + "%";
  }

  function setConnectionState(kind, label) {
    const indicator = $("#xui-live-indicator");
    indicator?.classList.toggle("is-online", kind === "online");
    indicator?.classList.toggle("is-error", kind === "error");
    setText("#xui-live-state", label);
  }

  function renderMetrics(data) {
    const cpu = number(data.cpu_percent);
    const memoryUsed = number(data.memory?.used_bytes);
    const memoryTotal = number(data.memory?.total_bytes);
    const memoryPercent = memoryUsed !== null && memoryTotal > 0
      ? memoryUsed / memoryTotal * 100
      : null;

    setMeter("#xui-cpu-value", "#xui-cpu-meter", cpu);
    setMeter("#xui-ram-value", "#xui-ram-meter", memoryPercent);
    setText("#xui-ram-detail", memoryUsed !== null && memoryTotal > 0
      ? formatBytes(memoryUsed) + " из " + formatBytes(memoryTotal)
      : "Использование памяти недоступно");
    setText("#xui-tcp-value", number(data.tcp_connections) === null
      ? "н/д" : Math.round(number(data.tcp_connections)).toLocaleString("ru-RU"));
    setText("#xui-online-value", number(data.active_clients) === null
      ? "н/д" : Math.round(number(data.active_clients)).toLocaleString("ru-RU"));
    const xrayState = data.xray?.state || "неизвестно";
    setText("#xui-xray-state", "Xray: " + (xrayState === "running" ? "работает" : xrayState));
    setText("#xui-live-updated", "Обновлено " + new Date().toLocaleTimeString("ru-RU"));

    const current = {
      upload: number(data.network_bytes?.upload),
      download: number(data.network_bytes?.download),
      time: Date.now()
    };
    let uploadRate = null;
    let downloadRate = null;
    if (state.previous && current.upload !== null && current.download !== null) {
      const seconds = (current.time - state.previous.time) / 1000;
      const uploadDelta = current.upload - state.previous.upload;
      const downloadDelta = current.download - state.previous.download;
      if (seconds > 0 && uploadDelta >= 0 && downloadDelta >= 0) {
        uploadRate = uploadDelta / seconds;
        downloadRate = downloadDelta / seconds;
      }
    }
    state.previous = current;
    state.history.push({ upload: uploadRate || 0, download: downloadRate || 0 });
    if (state.history.length > historyLimit) state.history.shift();

    setText("#xui-upload-value", formatRate(uploadRate));
    setText("#xui-download-value", formatRate(downloadRate));
    setText("#xui-network-total", "↑ " + formatRate(uploadRate) + " / ↓ " + formatRate(downloadRate));
    drawChart();
  }

  function drawChart() {
    const context = chart.getContext("2d");
    if (!context) return;
    const bounds = chart.getBoundingClientRect();
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    const width = Math.max(1, Math.round(bounds.width * ratio));
    const height = Math.max(1, Math.round(bounds.height * ratio));
    if (chart.width !== width || chart.height !== height) {
      chart.width = width;
      chart.height = height;
    }
    context.clearRect(0, 0, width, height);

    const padding = { top: 12 * ratio, right: 12 * ratio, bottom: 12 * ratio, left: 12 * ratio };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    context.strokeStyle = "rgba(105, 143, 180, .13)";
    context.lineWidth = 1 * ratio;
    for (let row = 1; row <= 3; row += 1) {
      const y = padding.top + plotHeight * row / 4;
      context.beginPath();
      context.moveTo(padding.left, y);
      context.lineTo(width - padding.right, y);
      context.stroke();
    }

    if (state.history.length < 2) return;
    const maximum = Math.max(1, ...state.history.flatMap(point => [point.upload, point.download])) * 1.15;
    const drawLine = (key, color) => {
      context.beginPath();
      state.history.forEach((point, index) => {
        const x = padding.left + plotWidth * index / (historyLimit - 1);
        const y = padding.top + plotHeight * (1 - point[key] / maximum);
        if (index === 0) context.moveTo(x, y);
        else context.lineTo(x, y);
      });
      context.strokeStyle = color;
      context.lineWidth = 2 * ratio;
      context.lineJoin = "round";
      context.lineCap = "round";
      context.stroke();
    };
    drawLine("download", "#138bdc");
    drawLine("upload", "#ff9b32");
  }

  async function resolveConnection() {
    const result = await api("/api/admin/xui/connections");
    const items = Array.isArray(result.items) ? result.items : [];
    const active = items.find(item => item.is_active && item.enabled);
    if (!active) {
      state.connectionId = null;
      state.previous = null;
      state.history = [];
      setConnectionState("error", "Нет активной панели");
      setText("#xui-live-source", "Активируйте подключение в разделе 3x-ui");
      setText("#xui-cpu-value", "н/д");
      setText("#xui-ram-value", "н/д");
      setText("#xui-tcp-value", "н/д");
      setText("#xui-online-value", "н/д");
      setText("#xui-upload-value", "—");
      setText("#xui-download-value", "—");
      setText("#xui-ram-detail", "Нет активного источника");
      $("#xui-cpu-meter").style.width = "0%";
      $("#xui-ram-meter").style.width = "0%";
      drawChart();
      return false;
    }
    if (state.connectionId !== active.id) {
      state.connectionId = active.id;
      state.previous = null;
      state.history = [];
    }
    setText("#xui-live-source", "Источник: " + active.name);
    return true;
  }

  async function refresh() {
    if (state.busy || document.hidden || !$("#view-overview")?.classList.contains("active")) return;
    state.busy = true;
    panel.classList.add("is-loading");
    try {
      if (!(await resolveConnection())) return;
      const data = await api("/api/admin/xui/connections/" + encodeURIComponent(state.connectionId) + "/metrics");
      renderMetrics(data);
      setConnectionState("online", "Данные поступают");
    } catch (error) {
      state.previous = null;
      setConnectionState("error", error.message || "Нет данных");
      setText("#xui-live-updated", "Последняя ошибка " + new Date().toLocaleTimeString("ru-RU"));
      if (error.status === 404 || error.status === 409) state.connectionId = null;
    } finally {
      state.busy = false;
      panel.classList.remove("is-loading");
    }
  }

  function start() {
    if (state.timer) return;
    refresh();
    state.timer = window.setInterval(refresh, 5000);
  }

  function stop() {
    if (!state.timer) return;
    window.clearInterval(state.timer);
    state.timer = null;
  }

  document.addEventListener("DOMContentLoaded", () => {
    start();
    window.addEventListener("resize", drawChart, { passive: true });
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) stop();
      else start();
    });
    document.addEventListener("click", event => {
      if (event.target.closest('[data-view="overview"]')) refresh();
    });
  });
})();