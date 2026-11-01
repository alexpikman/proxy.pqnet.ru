(function () {
  "use strict";

  const $ = selector => document.querySelector(selector);
  const list = $("#xui-connection-list");
  const form = $("#xui-connection-form");
  const resultBox = $("#xui-connection-sync-result");
  let connections = [];

  async function api(path, options = {}) {
    const response = await fetch("/api/admin/xui/connections" + path, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", ...(options.headers || {}) },
      ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.detail || data.message || "Ошибка запроса: HTTP " + response.status);
    return data;
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
    window.setTimeout(() => toast.classList.remove("visible"), 3500);
  }

  function statusText(item) {
    if (!item.enabled) return "Отключено";
    if (item.last_test_ok === true) return "Соединение проверено";
    if (item.last_test_ok === false) return "Ошибка соединения";
    return "Не проверено";
  }

  function render() {
    if (!connections.length) {
      list.innerHTML = '<div class="empty-panel">Подключений пока нет. Добавьте первую панель 3x-ui выше.</div>';
      return;
    }
    list.innerHTML = connections.map(item => {
      const caps = item.capabilities || {};
      const capabilityText = Object.entries(caps).filter(([, value]) => value === true).map(([key]) => key.replaceAll("_", " ")).join(", ");
      return '<article class="panel xui-connection-card" data-connection="' + escapeHtml(item.id) + '">' +
        '<div class="xui-card-top"><div><div class="xui-card-title">' + escapeHtml(item.name) +
        (item.is_active ? ' <span class="status-pill status-good">Активный</span>' : '') + '</div>' +
        '<div class="xui-card-url">' + escapeHtml(item.base_url) + '</div></div>' +
        '<span class="status-pill ' + (item.last_test_ok ? 'status-good' : item.last_test_ok === false ? 'status-muted' : '') + '">' + escapeHtml(statusText(item)) + '</span></div>' +
        '<div class="xui-card-meta"><span>Версия: ' + escapeHtml(item.panel_version || "не определена") + '</span>' +
        '<span>API: ' + escapeHtml(item.api_family || "не определён") + '</span>' +
        '<span>Таймаут: ' + escapeHtml(item.timeout_seconds) + ' сек.</span>' +
        '<span>Проверка: ' + escapeHtml(item.last_test_at || "ещё не выполнялась") + '</span></div>' +
        (item.last_test_error ? '<p class="xui-card-error">' + escapeHtml(item.last_test_error) + '</p>' : '') +
        (capabilityText ? '<p class="subtle-note">Возможности API: ' + escapeHtml(capabilityText) + '</p>' : '') +
        '<div class="xui-card-actions">' +
        '<button class="button button-outline button-small" data-action="test">Проверить</button>' +
        '<button class="button button-outline button-small" data-action="edit">Изменить</button>' +
        '<button class="button button-outline button-small" data-action="sync" ' + (!item.enabled ? "disabled" : "") + '>Сверить</button>' +
        (item.is_active ? '<button class="button button-outline button-small" data-action="deactivate">Снять активность</button>' :
          '<button class="button button-primary button-small" data-action="activate" ' + (!item.enabled ? "disabled" : "") + '>Сделать активным</button>') +
        (item.enabled ? '<button class="button button-outline button-small" data-action="disable">Отключить</button>' :
          '<button class="button button-outline button-small" data-action="enable">Включить</button>') +
        '</div></article>';
    }).join("");
  }

  function resetForm() {
    form.reset();
    $("#xui-connection-id").value = "";
    $("#xui-connection-timeout").value = "15";
    $("#xui-connection-token").required = true;
    $("#xui-connection-token").placeholder = "Токен хранится в зашифрованном виде";
    $("#xui-form-title").textContent = "Добавить сервер 3x-ui";
    $("#xui-connection-save").textContent = "Сохранить подключение";
    $("#xui-connection-cancel").hidden = true;
  }

  function editConnection(item) {
    $("#xui-connection-id").value = item.id;
    $("#xui-connection-name").value = item.name;
    $("#xui-connection-url").value = item.base_url;
    $("#xui-connection-timeout").value = item.timeout_seconds;
    $("#xui-connection-token").value = "";
    $("#xui-connection-token").required = false;
    $("#xui-connection-token").placeholder = "Пусто — оставить текущий токен";
    $("#xui-form-title").textContent = "Изменить подключение";
    $("#xui-connection-save").textContent = "Сохранить изменения";
    $("#xui-connection-cancel").hidden = false;
    form.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  async function loadConnections() {
    try {
      const data = await api("");
      connections = data.items || [];
      render();
    } catch (error) {
      list.innerHTML = '<div class="empty-panel">' + escapeHtml(error.message) + '</div>';
    }
  }

  form.addEventListener("submit", async event => {
    event.preventDefault();
    const id = $("#xui-connection-id").value;
    const payload = {
      name: $("#xui-connection-name").value.trim(),
      base_url: $("#xui-connection-url").value.trim(),
      api_token: $("#xui-connection-token").value,
      timeout_seconds: Number($("#xui-connection-timeout").value)
    };
    if (!id && !payload.api_token.trim()) {
      notify("Для нового подключения требуется API Token.", true);
      return;
    }
    try {
      await api(id ? "/" + encodeURIComponent(id) : "", {
        method: id ? "PUT" : "POST",
        body: JSON.stringify(payload)
      });
      resetForm();
      await loadConnections();
      notify(id ? "Подключение обновлено." : "Подключение добавлено.");
    } catch (error) { notify(error.message, true); }
  });

  $("#xui-connection-cancel").addEventListener("click", resetForm);
  $("#xui-connections-refresh").addEventListener("click", loadConnections);

  list.addEventListener("click", async event => {
    const button = event.target.closest("[data-action]");
    const card = event.target.closest("[data-connection]");
    if (!button || !card) return;
    const id = card.dataset.connection;
    const item = connections.find(connection => connection.id === id);
    if (!item) return;
    const action = button.dataset.action;
    button.disabled = true;
    try {
      if (action === "edit") {
        editConnection(item);
      } else if (action === "test") {
        const tested = await api("/" + encodeURIComponent(id) + "/test", { method: "POST", body: "{}" });
        await loadConnections();
        notify(tested.message || "Проверка завершена.", !tested.connected);
      } else if (action === "activate") {
        await api("/" + encodeURIComponent(id) + "/activate", { method: "POST", body: "{}" });
        await loadConnections();
        notify("Активный сервер переключён.");
      } else if (action === "deactivate") {
        await api("/" + encodeURIComponent(id) + "/deactivate", { method: "POST", body: "{}" });
        await loadConnections();
        notify("Активный сервер отключён.");
      } else if (action === "disable" || action === "enable") {
        await api("/" + encodeURIComponent(id) + "/" + action, { method: "POST", body: "{}" });
        await loadConnections();
        notify(action === "disable" ? "Подключение отключено." : "Подключение включено.");
      } else if (action === "sync") {
        const data = await api("/" + encodeURIComponent(id) + "/sync", { method: "POST", body: "{}" });
        const counts = data.counts || {};
        resultBox.textContent = item.name + ": совпадают — " + (counts.matched || 0) +
          ", только в панели — " + (counts.panel_only || 0) + ", только в PQService — " +
          (counts.service_only || 0) + ", конфликтов — " + (counts.conflicts || 0) +
          ". Сверка только для чтения; изменения не применялись.";
        notify("Сверка завершена. Данные не изменялись.");
        await loadConnections();
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
