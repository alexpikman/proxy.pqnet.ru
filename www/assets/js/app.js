(() => {
  "use strict";

  const API_BASE = "/api";
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  async function api(path, options = {}) {
    const response = await fetch(`${API_BASE}${path}`, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", ...(options.headers || {}) },
      ...options
    });
    const contentType = response.headers.get("content-type") || "";
    const payload = contentType.includes("application/json")
      ? await response.json()
      : { message: await response.text() };
    if (!response.ok) {
      const error = new Error(payload.message || payload.error || `Ошибка запроса (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function toast(message, isError = false) {
    const node = $("#toast");
    if (!node) return;
    node.textContent = message;
    node.classList.toggle("toast-error", isError);
    node.classList.add("visible");
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => node.classList.remove("visible"), 3200);
  }

  function formatDate(value) {
    if (!value) return "—";
    const date = typeof value === "number"
      ? new Date(value < 1000000000000 ? value * 1000 : value)
      : new Date(value);
    return Number.isNaN(date.getTime()) ? "—" : date.toLocaleDateString("ru-RU", { day: "2-digit", month: "long", year: "numeric" });
  }

  function formatNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? new Intl.NumberFormat("ru-RU").format(number) : "—";
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, char => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[char]);
  }

  async function requireRole(expected) {
    try {
      const session = await api("/session");
      if (!session || !session.authenticated) {
        window.location.replace("/login.html");
        return null;
      }
      if (session.role !== expected) {
        window.location.replace(session.role === "admin" ? "/admin.html" : "/account.html");
        return null;
      }
      return session;
    } catch {
      window.location.replace("/login.html");
      return null;
    }
  }

  function initLogin() {
    const form = $("#login-form");
    if (!form) return;
    $("#year").textContent = new Date().getFullYear();
    const password = $("#password");
    $("[data-password-toggle]")?.addEventListener("click", event => {
      const visible = password.type === "text";
      password.type = visible ? "password" : "text";
      event.currentTarget.textContent = visible ? "Показать" : "Скрыть";
      event.currentTarget.setAttribute("aria-label", visible ? "Показать пароль" : "Скрыть пароль");
    });
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = $("#login-message");
      const button = $("#login-submit");
      message.hidden = true;
      const login = $("#login").value.trim();
      const secret = password.value;
      if (!login || !secret) {
        message.textContent = "Введите логин и пароль.";
        message.hidden = false;
        return;
      }
      button.disabled = true;
      button.textContent = "Проверяем данные…";
      try {
        const result = await api("/login", {
          method: "POST",
          body: JSON.stringify({ login, password: secret })
        });
        if (!result.success) throw new Error(result.message || "Не удалось выполнить вход.");
        window.location.replace(result.role === "admin" ? "/admin.html" : "/account.html");
      } catch (error) {
        message.textContent = error.status === 404
          ? "Серверная авторизация ещё не подключена. Интерфейс готов, требуется настроить backend."
          : error.message || "Не удалось войти. Проверьте данные.";
        message.hidden = false;
      } finally {
        button.disabled = false;
        button.innerHTML = 'Войти в кабинет <span>→</span>';
      }
    });
  }

  function initWorkspaceShell() {
    const area = document.body.dataset.area;
    if (!area) return;
    const titles = {
      overview: "Обзор", subscription: "Подписка", coins: "PQcoins", programs: "Программы",
      users: "Пользователи", finance: "PQcoins", promocodes: "Промокоды", xui: "3x-ui", system: "Система"
    };
    const showView = view => {
      const target = $(`#view-${view}`);
      if (!target) return;
      $$(".view").forEach(node => node.classList.toggle("active", node === target));
      $$(".side-link").forEach(node => node.classList.toggle("active", node.dataset.view === view));
      $("#page-title").textContent = titles[view] || "Обзор";
      history.replaceState(null, "", `#${view}`);
      $("#sidebar")?.classList.remove("sidebar-open");
      if (area === "account") loadAccountView(view);
    };
    $$("[data-view]").forEach(link => link.addEventListener("click", event => {
      event.preventDefault();
      showView(link.dataset.view);
    }));
    $$("[data-navigate]").forEach(button => button.addEventListener("click", () => showView(button.dataset.navigate)));
    $("[data-menu]")?.addEventListener("click", () => $("#sidebar")?.classList.toggle("sidebar-open"));
    $$("[data-logout]").forEach(button => button.addEventListener("click", async () => {
      try { await api("/logout", { method: "POST" }); } catch {}
      window.location.replace("/login.html");
    }));
    $("[data-refresh]")?.addEventListener("click", () => loadAdminOverview(true));
    const initial = location.hash.slice(1);
    showView(titles[initial] ? initial : "overview");
    if (area === "account") bootAccount();
    if (area === "admin") bootAdmin();
  }

  let accountData = null;
  let accountTransactions = [];
  let programs = [];
  let activePlatform = "all";

  async function bootAccount() {
    const session = await requireRole("user");
    if (!session) return;
    try {
      const [profile, tx, appList] = await Promise.all([
        api("/profile"), api("/transactions").catch(() => []), api("/programs").catch(() => [])
      ]);
      accountData = profile;
      accountTransactions = Array.isArray(tx) ? tx : (tx.items || []);
      programs = Array.isArray(appList) ? appList : (appList.items || []);
      const user = profile.user || profile;
      const name = user.login || user.email || "Пользователь";
      $("#top-user-name").textContent = name;
      $("#hello-name").textContent = `, ${name}`;
      $("#today-date").textContent = new Date().toLocaleDateString("ru-RU", { day: "numeric", month: "long", year: "numeric" });
      $("#stat-coins").textContent = formatNumber(user.coins);
      $("#coins-balance").textContent = formatNumber(user.coins);
      $("#stat-days").textContent = formatNumber(user.days_left);
      $("#stat-status").textContent = user.localstatus ? "Активна" : "Отключена";
      $("#stat-expiry").textContent = `Дата окончания: ${formatDate(user.localexpire || user.expiry_date)}`;
      $("#subscription-status").textContent = user.localstatus ? "● Активна" : "● Отключена";
      $("#detail-sub-status").textContent = $("#subscription-status").textContent;
      $("#detail-status").textContent = user.localstatus ? "Активна" : "Отключена";
      $("#detail-expiry").textContent = formatDate(user.localexpire || user.expiry_date);
      $("#detail-days").textContent = formatNumber(user.days_left);
      $("#detail-price").textContent = `${formatNumber(user.price_per_day)} PQcoins`;
      $("#coins-price").textContent = formatNumber(user.price_per_day);
      const subUrl = profile.subscription_url || profile.subscriptionUrl || user.subscription_url || "";
      if (subUrl) {
        ["#subscription-url", "#detail-sub-url"].forEach(id => $(id).textContent = subUrl);
        $$("[data-copy-sub], [data-open-sub]").forEach(button => button.disabled = false);
      }
      renderTransactions("#recent-transactions", accountTransactions.slice(0, 5));
      renderTransactions("#transactions", accountTransactions);
      renderPrograms("#program-list", programs);
      $("#program-list").dataset.loaded = "true";
    } catch (error) {
      toast(error.status === 404 ? "API профиля ещё не подключён." : error.message, true);
    }
    $$("[data-copy-sub]").forEach(button => button.addEventListener("click", copySubscription));
    $$("[data-open-sub]").forEach(button => button.addEventListener("click", openSubscription));
    $("#program-search")?.addEventListener("input", renderProgramsForUser);
    $("#program-filters")?.addEventListener("click", event => {
      const button = event.target.closest("[data-platform]");
      if (!button) return;
      activePlatform = button.dataset.platform;
      $$(".filter-chip").forEach(chip => chip.classList.toggle("active", chip === button));
      renderProgramsForUser();
    });
  }

  function loadAccountView(view) {
    if (view === "coins") renderTransactions("#transactions", accountTransactions);
    if (view === "programs") renderProgramsForUser();
  }

  function renderTransactions(selector, items) {
    const body = $(selector);
    if (!body) return;
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="4" class="empty-state">Операций пока нет.</td></tr>';
      return;
    }
    body.innerHTML = items.map(item => {
      const amount = Number(item.amount ?? item.coins_spent ?? 0);
      const type = item.description || item.type || item.status || "Операция";
      return `<tr><td>${escapeHtml(formatDate(item.created_at))}</td><td>${escapeHtml(type)}</td><td class="${amount >= 0 ? "amount-positive" : "amount-negative"}">${amount > 0 ? "+" : ""}${formatNumber(amount)}</td><td>${escapeHtml(item.comment || item.balance_after || "—")}</td></tr>`;
    }).join("");
  }

  function renderPrograms(selector, items) {
    const node = $(selector);
    if (!node) return;
    if (!items.length) {
      node.innerHTML = '<div class="empty-panel">Опубликованных программ пока нет.</div>';
      return;
    }
    node.innerHTML = items.map(program => {
      const platform = escapeHtml(program.platform || "other");
      const title = escapeHtml(program.name || program.title || "Программа");
      const description = escapeHtml(program.description || "Приложение для подключения.");
      const url = escapeHtml(program.download_url || program.url || "#");
      return `<article class="program-card" data-platform-value="${platform}" data-search-value="${(title + " " + description).toLowerCase()}"><div class="program-icon">${escapeHtml((program.name || "P").slice(0, 1).toUpperCase())}</div><div class="program-meta"><span class="program-platform">${platform}</span><h3>${title}</h3><p>${description}</p><small>${escapeHtml(program.version || "")}</small></div><a class="button button-outline button-small" href="${url}" target="_blank" rel="noopener noreferrer">Скачать ↗</a></article>`;
    }).join("");
  }

  function renderProgramsForUser() {
    const query = ($("#program-search")?.value || "").trim().toLowerCase();
    $$(".program-card").forEach(card => {
      const matchesPlatform = activePlatform === "all" || card.dataset.platformValue === activePlatform;
      const matchesQuery = !query || card.dataset.searchValue.includes(query);
      card.hidden = !(matchesPlatform && matchesQuery);
    });
  }

  async function copySubscription() {
    const url = accountData?.subscription_url || accountData?.subscriptionUrl || accountData?.user?.subscription_url;
    if (!url) return toast("Ссылка подписки недоступна.", true);
    try { await navigator.clipboard.writeText(url); toast("Ссылка скопирована."); }
    catch { toast("Не удалось скопировать ссылку.", true); }
  }

  function openSubscription() {
    const url = accountData?.subscription_url || accountData?.subscriptionUrl || accountData?.user?.subscription_url;
    if (url) window.open(url, "_blank", "noopener,noreferrer");
  }

  async function bootAdmin() {
    const session = await requireRole("admin");
    if (!session) return;
    $("#top-user-name").textContent = session.user?.login || session.login || "Администратор";
    await loadAdminOverview();
    bindAdminControls();
  }

  async function loadAdminOverview(showToast = false) {
    try {
      const data = await api("/admin/overview");
      $("#admin-total-users").textContent = formatNumber(data.total_users);
      $("#admin-active-users").textContent = formatNumber(data.active_users);
      $("#admin-disabled-users").textContent = formatNumber(data.disabled_users);
      $("#admin-total-coins").textContent = formatNumber(data.total_coins);
      $("#finance-total-coins").textContent = formatNumber(data.total_coins);
      $("#xui-status").textContent = data.xui?.connected ? "● Подключено" : "● Нет соединения";
      $("#xui-status-text").textContent = data.xui?.message || (data.xui?.connected ? "Соединение установлено" : "Нет соединения с 3x-ui");
      $("#xui-dot").classList.toggle("is-online", Boolean(data.xui?.connected));
      $("#xui-last-sync").textContent = data.xui?.last_sync ? `Последняя синхронизация: ${formatDate(data.xui.last_sync)}` : "Синхронизация ещё не выполнялась";
      if (showToast) toast("Данные обновлены.");
    } catch (error) {
      $("#xui-status").textContent = "Не подключено";
      $("#xui-status-text").textContent = "Backend API пока не подключён";
      $("#xui-last-sync").textContent = error.status === 404 ? "Ожидается реализация административного API." : error.message;
      if (showToast) toast("Не удалось обновить данные.", true);
    }
  }

  function bindAdminControls() {
    $("[data-sync-users]")?.addEventListener("click", async () => {
      try {
        await api("/admin/xui/sync", { method: "POST", body: JSON.stringify({}) });
        toast("Синхронизация запущена.");
        loadAdminUsers();
      } catch (error) { toast(error.message, true); }
    });
    $("[data-check-xui]")?.addEventListener("click", async () => {
      try {
        const result = await api("/admin/xui/status");
        $("#xui-api-value").textContent = result.connected ? "Подключено" : "Нет соединения";
        $("#xui-inbounds-count").textContent = formatNumber(result.inbounds_count);
        $("#xui-sync-details").textContent = result.message || "Проверка завершена.";
      } catch (error) { toast(error.message, true); }
    });
    $("#user-search")?.addEventListener("input", loadAdminUsers);
    $("#user-status-filter")?.addEventListener("change", loadAdminUsers);
    $("#user-source-filter")?.addEventListener("change", loadAdminUsers);
    $("[data-add-program]")?.addEventListener("click", () => openEntityModal("program"));
    $("[data-add-promo]")?.addEventListener("click", () => openEntityModal("promo"));
    $("#credit-form")?.addEventListener("submit", creditUser);
    $("#admin-program-search")?.addEventListener("input", loadAdminPrograms);
    $("#admin-program-platform")?.addEventListener("change", loadAdminPrograms);
  }

  async function loadAdminUsers() {
    const body = $("#admin-users");
    if (!body) return;
    const params = new URLSearchParams({
      search: $("#user-search")?.value || "",
      status: $("#user-status-filter")?.value || "all",
      source: $("#user-source-filter")?.value || "all"
    });
    try {
      const result = await api(`/admin/users?${params}`);
      const users = Array.isArray(result) ? result : (result.items || []);
      if (!users.length) {
        body.innerHTML = '<tr><td colspan="8" class="empty-state">Пользователи не найдены.</td></tr>';
        return;
      }
      body.innerHTML = users.map(user => `<tr><td><b>${escapeHtml(user.login || user.email || user.uuid)}</b><small class="table-sub">${escapeHtml(user.email || user.uuid || "")}</small></td><td><span class="status-pill ${user.localstatus ? "status-good" : "status-muted"}">${user.localstatus ? "Активен" : "Отключён"}</span></td><td>${escapeHtml(formatDate(user.localexpire || user.expiry_date))}</td><td>${escapeHtml(formatNumber(user.days_left))}</td><td><b>${escapeHtml(formatNumber(user.coins))}</b></td><td>${escapeHtml(formatNumber(user.price_per_day))}</td><td>${user.vip ? "VIP" : "—"}</td><td><button class="icon-button" data-edit-user="${escapeHtml(user.uuid)}" aria-label="Редактировать">↗</button></td></tr>`).join("");
      $$("[data-edit-user]", body).forEach(button => button.addEventListener("click", () => openUserEditor(button.dataset.editUser)));
    } catch (error) {
      body.innerHTML = `<tr><td colspan="8" class="empty-state">${escapeHtml(error.status === 404 ? "API пользователей ещё не подключён." : error.message)}</td></tr>`;
    }
  }

  async function openUserEditor(uuid) {
    try {
      const user = await api(`/admin/user/${encodeURIComponent(uuid)}`);
      showModal("Пользователь", "Управление пользователем", [
        field("uuid", "UUID", user.uuid || uuid, "text", true),
        field("coins", "Баланс PQcoins", user.coins ?? 0, "number"),
        field("price_per_day", "Цена за день", user.price_per_day ?? 0, "number"),
        field("vip", "VIP (0 или 1)", user.vip ? 1 : 0, "number"),
        field("localstatus", "Статус (1 — активен)", user.localstatus ? 1 : 0, "number")
      ], async values => {
        await api("/admin/user/update", { method: "POST", body: JSON.stringify({ ...user, ...values, coins: Number(values.coins), price_per_day: Number(values.price_per_day), vip: Number(values.vip), localstatus: Number(values.localstatus) }) });
        toast("Изменения сохранены.");
        loadAdminUsers();
      });
    } catch (error) { toast(error.message, true); }
  }

  async function creditUser(event) {
    event.preventDefault();
    const payload = {
      user: $("#credit-user").value.trim(),
      amount: Number($("#credit-amount").value),
      comment: $("#credit-note").value.trim()
    };
    if (!payload.user || !Number.isInteger(payload.amount) || payload.amount <= 0) return toast("Проверьте пользователя и количество монет.", true);
    try {
      await api("/admin/coins/credit", { method: "POST", body: JSON.stringify(payload) });
      event.currentTarget.reset();
      toast("Монеты начислены.");
      loadAdminOverview();
    } catch (error) { toast(error.message, true); }
  }

  async function loadAdminPrograms() {
    const node = $("#admin-program-list");
    if (!node) return;
    try {
      const result = await api("/admin/programs");
      const items = Array.isArray(result) ? result : (result.items || []);
      renderPrograms("#admin-program-list", items);
    } catch (error) { node.innerHTML = `<div class="empty-panel">${escapeHtml(error.status === 404 ? "API каталога ещё не подключён." : error.message)}</div>`; }
  }

  function field(name, label, value, type = "text", readonly = false) {
    return { name, label, value, type, readonly };
  }

  function showModal(kicker, title, fields, onSave) {
    const dialog = $("#entity-modal");
    $("#modal-kicker").textContent = kicker;
    $("#modal-title").textContent = title;
    $("#modal-fields").innerHTML = fields.map(item => `<label class="modal-field">${escapeHtml(item.label)}<input class="input-control" name="${escapeHtml(item.name)}" type="${escapeHtml(item.type)}" value="${escapeHtml(item.value)}" ${item.readonly ? "readonly" : ""} required></label>`).join("");
    dialog.showModal();
    $("#entity-form").onsubmit = async event => {
      if (event.submitter?.value === "cancel") return;
      event.preventDefault();
      const values = Object.fromEntries(new FormData(event.currentTarget).entries());
      try { await onSave(values); dialog.close(); }
      catch (error) { toast(error.message, true); }
    };
  }

  function openEntityModal(type) {
    if (type === "program") {
      showModal("КАТАЛОГ ПРОГРАММ", "Добавить программу", [
        field("name", "Название", ""), field("platform", "Платформа (windows/android/ios/macos/linux)", ""),
        field("version", "Версия", ""), field("download_url", "Ссылка на скачивание", ""),
        field("description", "Описание", "")
      ], async values => {
        await api("/admin/programs", { method: "POST", body: JSON.stringify(values) });
        toast("Программа добавлена.");
        loadAdminPrograms();
      });
    } else {
      showModal("PQCOINS", "Создать промокод", [
        field("code", "Промокод", ""), field("type", "Тип (coins/days)", "coins"),
        field("coins_amount", "Количество монет", "0", "number"), field("days_amount", "Количество дней", "0", "number"),
        field("max_uses", "Лимит использований (0 — без лимита)", "1", "number")
      ], async values => {
        await api("/admin/promocodes", { method: "POST", body: JSON.stringify(values) });
        toast("Промокод создан.");
      });
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    initLogin();
    initWorkspaceShell();
  });
})();