(() => {
  "use strict";

  const $ = selector => document.querySelector(selector);
  const API_BASE = "/api/index.php?route=";
  const sourceSelect = $("#user-source-filter");
  const body = $("#admin-users");
  let csrfToken = "";
  let connections = [];
  let refreshTimer = null;

  async function api(path, options = {}) {
    if (options.method && options.method !== "GET" && !csrfToken) {
      const session = await api("/session");
      csrfToken = session.csrf_token || "";
    }
    const response = await fetch(API_BASE + encodeURIComponent(path), {
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
        ...(options.headers || {})
      },
      ...options
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success === false) {
      throw new Error(payload.error || payload.message || "Ошибка запроса: HTTP " + response.status);
    }
    return Object.prototype.hasOwnProperty.call(payload, "data") ? payload.data : payload;
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, char => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[char]);
  }

  function notify(message, isError = false) {
    const toast = $("#toast");
    if (!toast) return;
    toast.textContent = message;
    toast.classList.toggle("toast-error", isError);
    toast.classList.add("visible");
    window.clearTimeout(notify.timer);
    notify.timer = window.setTimeout(() => toast.classList.remove("visible"), 3200);
  }

  function renderSources() {
    const selected = sourceSelect.value;
    const eligible = connections.filter(item => item.load_clients);
    sourceSelect.innerHTML = '<option value="">Выберите сервер 3x-ui</option>' +
      eligible.map(item => '<option value="' + escapeHtml(item.id) + '">' + escapeHtml(item.name) + '</option>').join("");
    if (eligible.some(item => item.id === selected)) sourceSelect.value = selected;
    else if (eligible.length === 1) sourceSelect.value = eligible[0].id;
    updateSourceLabel();
    if (sourceSelect.value) loadUsers();
    else body.innerHTML = '<tr><td colspan="6" class="empty-state">Нет подключений с включённой загрузкой клиентов.</td></tr>';
  }

  function updateSourceLabel() {
    const item = connections.find(connection => connection.id === sourceSelect.value);
    $("#xui-users-source-label").textContent = item ? item.name : "выберите сервер";
  }

  async function loadUsers() {
    const connectionId = sourceSelect.value;
    if (!connectionId) return;
    const params = new URLSearchParams({ search: $("#user-search").value.trim() });
    body.innerHTML = '<tr><td colspan="6" class="empty-state">Загрузка клиентов…</td></tr>';
    try {
      const data = await api("/admin/xui/connections/" + connectionId + "/users?" + params.toString());
      const users = data.items || [];
      if (!users.length) {
        body.innerHTML = '<tr><td colspan="6" class="empty-state">Клиенты не найдены. Нажмите «Обновить» для немедленной синхронизации.</td></tr>';
        return;
      }
      body.innerHTML = users.map(user => '<tr data-user-id="' + escapeHtml(user.id) + '">' +
        '<td><b>' + escapeHtml(user.login || user.email || "Без логина") + '</b><small class="table-sub">' + escapeHtml(user.uuid || "") + '</small></td>' +
        '<td>' + escapeHtml(user.inbound_id) + '</td>' +
        '<td><label class="switch-control"><input type="checkbox" data-client-enabled ' + (user.enabled ? "checked" : "") + '><span class="switch-track"></span><span class="switch-label">' + (user.enabled ? "Включён" : "Выключен") + '</span></label></td>' +
        '<td><span class="status-pill status-good">В панели</span></td>' +
        '<td>' + escapeHtml(new Intl.NumberFormat("ru-RU").format(Number(user.coins || 0))) + '</td>' +
        '<td>' + escapeHtml(new Intl.NumberFormat("ru-RU").format(Number(user.price_per_day || 0))) + '</td></tr>').join("");
    } catch (error) {
      body.innerHTML = '<tr><td colspan="6" class="empty-state">' + escapeHtml(error.message) + '</td></tr>';
    }
  }

  async function refreshSelected() {
    const connectionId = sourceSelect.value;
    if (!connectionId) {
      notify("Сначала выберите сервер 3x-ui.", true);
      return;
    }
    const button = $("#xui-users-refresh");
    button.disabled = true;
    button.textContent = "Обновляем…";
    try {
      const result = await api("/admin/xui/connections/" + connectionId + "/refresh", {
        method: "POST", body: "{}"
      });
      await loadUsers();
      notify("Загрузка завершена. Новых клиентов: " + (result.counts?.imported || 0) + ".");
    } catch (error) {
      notify(error.message, true);
    } finally {
      button.disabled = false;
      button.textContent = "↻ Обновить";
    }
  }

  sourceSelect?.addEventListener("change", () => {
    updateSourceLabel();
    loadUsers();
  });
  $("#user-search")?.addEventListener("input", () => {
    window.clearTimeout(loadUsers.timer);
    loadUsers.timer = window.setTimeout(loadUsers, 220);
  });
  $("#xui-users-refresh")?.addEventListener("click", refreshSelected);

  body?.addEventListener("change", async event => {
    const toggle = event.target.closest("[data-client-enabled]");
    const row = event.target.closest("[data-user-id]");
    if (!toggle || !row) return;
    const enabled = toggle.checked;
    toggle.disabled = true;
    try {
      await api("/admin/xui/connections/" + sourceSelect.value + "/users", {
        method: "POST",
        body: JSON.stringify({ user_id: row.dataset.userId, enabled })
      });
      const label = toggle.parentElement.querySelector(".switch-label");
      if (label) label.textContent = enabled ? "Включён" : "Выключен";
      notify(enabled ? "Клиент включён в 3x-ui." : "Клиент выключен в 3x-ui.");
    } catch (error) {
      toggle.checked = !enabled;
      notify(error.message, true);
    } finally {
      toggle.disabled = false;
    }
  });

  document.addEventListener("xui-connections-updated", event => {
    connections = event.detail || [];
    renderSources();
  });

  document.addEventListener("DOMContentLoaded", () => {
    if (document.body.dataset.area !== "admin" || !sourceSelect) return;
    refreshTimer = window.setInterval(() => {
      if (location.hash === "#users" && sourceSelect.value && !document.hidden) loadUsers();
    }, 30000);
  });

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden && location.hash === "#users" && sourceSelect.value) loadUsers();
  });
})();
