// Глобальные переменные
let localUsersList = [];
let currentInbounds = [];

// Утилиты
function showMsg(text, isErr = false) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.querySelector('span').innerText = text;
    toast.style.background = isErr ? '#b91c1c' : '#1e2f3c';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2500);
}

async function fetchJson(url, options = {}) {
    const r = await fetch(url, options);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
}

function updateExpiryDateHint() {
    const expiryValue = parseInt(document.getElementById('userExpiryDate')?.value) || 0;
    const hintDiv = document.getElementById('userExpiryDateHint');
    if (hintDiv) {
        if (expiryValue > 0) {
            const expiryDate = new Date(expiryValue * 1000);
            hintDiv.innerHTML = ` (истекает: ${expiryDate.toLocaleDateString()} ${expiryDate.toLocaleTimeString()})`;
        } else {
            hintDiv.innerHTML = ' (0 - бессрочно)';
        }
    }
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (modal) modal.classList.remove('active');
    const form = document.getElementById('userForm');
    if (form) form.reset();
    const uuidField = document.getElementById('userUuid');
    if (uuidField) uuidField.value = '';
    const statusToggle = document.getElementById('userStatusToggle');
    if (statusToggle) {
        statusToggle.checked = true;
        const statusLabel = document.getElementById('statusLabel');
        if (statusLabel) statusLabel.innerText = 'Активен';
    }
}

// Экспортируем в глобальный объект
window.showMsg = showMsg;
window.fetchJson = fetchJson;
window.updateExpiryDateHint = updateExpiryDateHint;
window.closeUserModal = closeUserModal;
