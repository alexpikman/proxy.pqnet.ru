(() => {
  "use strict";

  const $ = selector => document.querySelector(selector);
  const list = $("#xui-connection-list");
  const dialog = $("#xui-connection-modal");
  const form = $("#xui-connection-form");
  const resultBox = $("#xui-connection-sync-result");
  const API_BASE = "/api/index.php?route=";
  let connections = [];
  let csrfToken = "";

  async function api(path, options = {}) {
    if (options.method && options.method !== "GET" && !csrfToken) {
      const session = await api("/session");
      csrfToken = session.csrf_token || "";
    }
    const [route, query = ""] = path.split("?", 2);
    const params = new URLSearchParams(query);
    params.set("route", route);
    const response = await fetch("/api/index.php?" + params.toString(), {
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
    notify.timer = window.setTimeout(() => toast.classList.remove("visible"), 3500);
  }

  function flag(label, enabled) {
    return '<span class="xui-flag ' + (enabled ? "is-enabled" : "") + '">' +
      escapeHtml(label) + ': ' + (enabled ? "да" : "нет") + '</span>';
  }

  function render() {
    if (!connections.length) {
      list.innerHTML = '<div class="empty-panel">Подключений пока нет. Добавьте первую панель 3x-ui.</div>';
      return;
    }
    list.innerHTML = connections.map(item => {
      const status = item.last_test_ok === true ? "Проверено" :
        item.last_test_ok === false ? "Ошибка соединения" : "Не проверено";
      return '<article class="panel xui-connection-card" data-connection="' + escapeHtml(item.id) + '">' +
        '<div class="xui-card-top"><div><div class="xui-card-title">' + escapeHtml(item.name) +
        (item.is_active ? ' <span class="status-pill status-good">Активный</span>' : '') + '</div>' +
        '<div class="xui-card-url">' + escapeHtml(item.base_url) + '</div></div>' +
        '<span class="status-pill ' + (item.last_test_ok ? "status-good" : "status-muted") + '">' + status + '</span></div>' +
        '<div class="xui-card-meta"><span>Таймаут: ' + escapeHtml(item.timeout_seconds) + ' сек.</span>' +
        '<span>Inbounds: ' + escapeHtml(item.panel_inbounds ?? "—") + '</span>' +
        '<span>Проверка: ' + escapeHtml(item.last_test_at || "не выполнялась") + '</span></div>' +
        '<div class="xui-flags">' + flag("Клиенты", item.load_clients) + flag("Inbounds", item.load_inbounds) +
        flag("Логи", item.load_logs) + flag("Телеметрия", item.load_telemetry) + '</div>' +
        (item.last_test_error ? '<p class="xui-card-error">' + escapeHtml(item.last_test_error) + '</p>' : '') +
        '<div class="xui-card-actions"><button class="button button-outline button-small" data-action="test">Проверить</button>' +
        '<button class="button button-outline button-small" data-action="edit">Настройки</button>' +
        '<button class="button button-outline button-small" data-action="sync" ' + (!item.enabled || !item.verified ? "disabled" : "") + '>Сверить</button>' +
        '<button class="button button-outline button-small" data-action="toggle" ' + (!item.verified ? "disabled" : "") + '>' +
        (item.enabled ? "Отключить" : "Включить") + '</button></div></article>';
    }).join("");
  }

  async function loadConnections() {
    try {
      const data = await api("/admin/xui/connections");
      connections = data.items || [];
      render();
      document.dispatchEvent(new CustomEvent("xui-connections-updated", { detail: connections }));
    } catch (error) {
      list.innerHTML = '<div class="empty-panel">' + escapeHtml(error.message) + '</div>';
    }
  }

  function fillOptions(item) {
    $("#xui-option-active").checked = Boolean(item.is_active);
    $("#xui-option-clients").checked = Boolean(item.load_clients);
    $("#xui-option-inbounds").checked = Boolean(item.load_inbounds);
    $("#xui-option-logs").checked = Boolean(item.load_logs);
    $("#xui-option-telemetry").checked = Boolean(item.load_telemetry);
    $("#xui-connection-options").hidden = !item.verified;
  }

  function openModal(item = null) {
    form.reset();
    $("#xui-connection-id").value = item?.id || "";
    $("#xui-form-title").textContent = item ? "Настройки подключения" : "Добавить сервер 3x-ui";
    $("#xui-connection-name").value = item?.name || "";
    $("#xui-connection-url").value = item?.base_url || "";
    $("#xui-connection-token").value = "";
    $("#xui-connection-token").required = !item;
    $("#xui-connection-token").placeholder = item ? "Пусто — оставить прежний токен" : "API Token";
    $("#xui-connection-timeout").value = item?.timeout_seconds || 15;
    fillOptions(item || {});
    dialog.showModal();
  }

  async function saveConnection() {
    const id = $("#xui-connection-id").value;
    const payload = {
      name: $("#xui-connection-name").value.trim(),
      base_url: $("#xui-connection-url").value.trim(),
      api_token: $("#xui-connection-token").value,
      timeout_seconds: Number($("#xui-connection-timeout").value)
    };
    if (!payload.name || !payload.base_url || (!id && !payload.api_token)) {
      throw new Error("Заполните название, URL и API Token.");
    }
    const data = await api(id ? "/admin/xui/connections/" + id : "/admin/xui/connections", {
      method: id ? "PUT" : "POST",
      body: JSON.stringify(payload)
    });
    const saved = data.item;
    $("#xui-connection-id").value = saved.id;
    $("#xui-connection-token").value = "";
    $("#xui-connection-token").required = false;
    $("#xui-connection-token").placeholder = "Пусто — оставить прежний токен";
    await loadConnections();
    return saved;
  }

  async function testConnection(id) {
    const result = await api("/admin/xui/connections/" + id + "/test", {
      method: "POST", body: "{}"
    });
    await loadConnections();
    if (!result.connected) throw new Error(result.message || "Не удалось подключиться к панели.");
    const item = connections.find(connection => connection.id === id);
    fillOptions(item || {});
    notify("Соединение успешно проверено.");
    return item;
  }

  $("#xui-connection-add")?.addEventListener("click", () => openModal());
  $("#xui-connections-refresh")?.addEventListener("click", loadConnections);
  $("#xui-modal-close")?.addEventListener("click", () => dialog.close());
  $("#xui-modal-cancel")?.addEventListener("click", () => dialog.close());

  form?.addEventListener("submit", async event => {
    event.preventDefault();
    const save = $("#xui-connection-save");
    save.disabled = true;
    try {
      const item = await saveConnection();
      await testConnection(item.id);
      notify("Подключение сохранено и проверено.");
    } catch (error) {
      notify(error.message, true);
    } finally {
      save.disabled = false;
    }
  });

  $("#xui-connection-test")?.addEventListener("click", async () => {
    const button = $("#xui-connection-test");
    button.disabled = true;
    try {
      const item = await saveConnection();
      await testConnection(item.id);
    } catch (error) {
      notify(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  $("#xui-options-save")?.addEventListener("click", async () => {
    const id = $("#xui-connection-id").value;
    const payload = {
      is_active: $("#xui-option-active").checked,
      load_clients: $("#xui-option-clients").checked,
      load_inbounds: $("#xui-option-inbounds").checked,
      load_logs: $("#xui-option-logs").checked,
      load_telemetry: $("#xui-option-telemetry").checked
    };
    try {
      await api("/admin/xui/connections/" + id + "/settings", {
        method: "PATCH", body: JSON.stringify(payload)
      });
      await loadConnections();
      notify("Параметры подключения сохранены.");
    } catch (error) {
      notify(error.message, true);
    }
  });

  list?.addEventListener("click", async event => {
    const button = event.target.closest("[data-action]");
    const card = event.target.closest("[data-connection]");
    if (!button || !card) return;
    const item = connections.find(connection => connection.id === card.dataset.connection);
    if (!item) return;
    button.disabled = true;
    try {
      if (button.dataset.action === "edit") {
        openModal(item);
      } else if (button.dataset.action === "test") {
        await testConnection(item.id);
      } else if (button.dataset.action === "toggle") {
        await api("/admin/xui/connections/" + item.id + "/settings", {
          method: "PATCH", body: JSON.stringify({ enabled: !item.enabled })
        });
        await loadConnections();
        notify(item.enabled ? "Подключение отключено." : "Подключение включено.");
      } else if (button.dataset.action === "sync") {
        const data = await api("/admin/xui/connections/" + item.id + "/sync", {
          method: "POST", body: "{}"
        });
        const counts = data.counts || {};
        resultBox.textContent = item.name + ": клиентов в панели — " + (counts.remote_clients || 0) +
          ", сопоставлено — " + (counts.matched || 0) + ", только в панели — " +
          (counts.panel_only || 0) + ", отсутствуют в панели — " + (counts.service_only || 0) +
          ". Сверка только для чтения; изменения не применялись.";
        notify("Сверка завершена. Данные не изменялись.");
      }
    } catch (error) {
      notify(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  document.addEventListener("DOMContentLoaded", () => {
    if (document.body.dataset.area === "admin") loadConnections();
  });
})();
