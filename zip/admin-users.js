let autoRefreshInterval = null;
let autoRefreshSeconds = 10;
let globalPrice = 10;

async function loadGlobalPriceForTable() {
    try {
        const data = await fetchJson('/admin/global-price');
        globalPrice = data.price_per_day;
    } catch (e) {
        globalPrice = 10;
    }
}

async function loadLocalUsers() {
    try {
        await loadGlobalPriceForTable();
        const users = await fetchJson('/admin/users');
        const tbody = document.getElementById('users-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        let rowNumber = 1;
        for (const u of users) {
            const row = tbody.insertRow();
            if (u.login && u.login.endsWith('_db')) row.classList.add('row-duplicate');
            
            row.insertCell(0).innerText = rowNumber++;
            row.insertCell(1).innerText = u.login || '—';
            row.insertCell(2).innerText = u.email || '—';
            
            const isActive = u.localstatus === 'active';
            const statusToggle = `<label class="toggle-switch"><input type="checkbox" ${isActive ? 'checked' : ''} data-uuid="${u.uuid}" class="local-status-toggle"><span class="slider"></span></label>`;
            row.insertCell(3).innerHTML = statusToggle;
            
            // Осталось дней: если VIP то ∞, иначе из БД
            let daysLeft = '—';
            if (u.vip === 1) {
                daysLeft = '∞';
            } else if (u.days_left !== null && u.days_left !== undefined) {
                daysLeft = u.days_left;
            }
            row.insertCell(4).innerText = daysLeft;
            
            // Дата окончания: если VIP то "Бессрочно"
            let expiryDateStr = '—';
            if (u.vip === 1) {
                expiryDateStr = 'Бессрочно (VIP)';
            } else if (u.expiry_date && u.expiry_date > 0) {
                expiryDateStr = new Date(u.expiry_date * 1000).toLocaleString();
            } else {
                expiryDateStr = 'Бессрочно';
            }
            row.insertCell(5).innerText = expiryDateStr;
            
            row.insertCell(6).innerText = u.coins ?? 0;
            
            let priceDisplay = '—';
            if (u.price_per_day && u.price_per_day !== globalPrice) {
                priceDisplay = `${u.price_per_day} мон. (личная)`;
            } else if (u.price_per_day === globalPrice) {
                priceDisplay = `${u.price_per_day} мон.`;
            } else {
                priceDisplay = `${globalPrice} мон. (глобальная)`;
            }
            row.insertCell(7).innerHTML = priceDisplay;
            
            const isVip = u.vip === 1;
            const vipToggle = `<label class="toggle-switch"><input type="checkbox" ${isVip ? 'checked' : ''} data-uuid="${u.uuid}" class="vip-toggle"><span class="slider"></span></label>`;
            row.insertCell(8).innerHTML = vipToggle;
            
            const actions = row.insertCell(9);
            actions.innerHTML = `<a href="/admin_edit.html?uuid=${u.uuid}" class="btn-icon" style="text-decoration:none;"><i class="fas fa-edit"></i></a>
                                 <button class="btn-icon btn-danger-icon" onclick="deleteUser('${u.uuid}')"><i class="fas fa-trash-alt"></i></button>`;
        }
        
        document.querySelectorAll('.local-status-toggle').forEach(toggle => {
            toggle.removeEventListener('change', handleLocalToggle);
            toggle.addEventListener('change', handleLocalToggle);
        });
        
        document.querySelectorAll('.vip-toggle').forEach(toggle => {
            toggle.removeEventListener('change', handleVipToggle);
            toggle.addEventListener('change', handleVipToggle);
        });
        
    } catch (e) {
        showMsg('Ошибка загрузки пользователей', true);
    }
}

function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(() => {
        loadLocalUsers();
    }, autoRefreshSeconds * 1000);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

function setupAutoRefreshControls() {
    const tableWrapper = document.querySelector('.table-wrapper');
    if (!tableWrapper) return;
    
    const controlDiv = document.createElement('div');
    controlDiv.style.cssText = 'display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem; padding: 0.5rem; background: #f8fafc; border-radius: 1rem;';
    controlDiv.innerHTML = `
        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
            <input type="checkbox" id="autoRefreshToggle"> 
            <span>Автообновление</span>
        </label>
        <label style="display: flex; align-items: center; gap: 0.5rem;">
            <span>Сек:</span>
            <input type="number" id="autoRefreshSeconds" value="${autoRefreshSeconds}" min="3" max="300" style="width: 70px; padding: 0.3rem; border-radius: 40px; border: 1px solid #dce5ec; text-align: center;">
        </label>
        <span id="refreshStatus" style="font-size: 0.7rem; color: #2c9e6e;"></span>
    `;
    
    tableWrapper.parentNode.insertBefore(controlDiv, tableWrapper);
    
    const toggle = document.getElementById('autoRefreshToggle');
    const secondsInput = document.getElementById('autoRefreshSeconds');
    const statusSpan = document.getElementById('refreshStatus');
    
    toggle.addEventListener('change', (e) => {
        if (e.target.checked) {
            autoRefreshSeconds = parseInt(secondsInput.value) || 10;
            startAutoRefresh();
            statusSpan.innerHTML = '🟢 обновляется каждые ' + autoRefreshSeconds + ' сек';
        } else {
            stopAutoRefresh();
            statusSpan.innerHTML = '⏹ остановлено';
        }
    });
    
    secondsInput.addEventListener('change', () => {
        autoRefreshSeconds = parseInt(secondsInput.value) || 10;
        if (toggle.checked) {
            startAutoRefresh();
            statusSpan.innerHTML = '🟢 обновляется каждые ' + autoRefreshSeconds + ' сек';
        }
    });
}

async function handleVipToggle(e) {
    const uuid = e.target.dataset.uuid;
    const isVip = e.target.checked ? 1 : 0;
    e.target.disabled = true;
    try {
        const user = await fetchJson('/admin/user/' + uuid);
        
        let expiryDate = user.expiry_date || 0;
        let xuiExpiryDate = expiryDate;
        
        if (isVip) {
            expiryDate = 0;
            xuiExpiryDate = 0;
        } else {
            expiryDate = user.expiry_date || 0;
            xuiExpiryDate = expiryDate;
        }
        
        await fetchJson('/admin/user/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                uuid: uuid,
                login: user.login,
                coins: user.coins,
                vip: isVip,
                localstatus: user.localstatus,
                email: user.email,
                phone: user.phone,
                price_per_day: user.price_per_day,
                expiry_date: expiryDate
            })
        });
        
        if (user.xui_inbound_id && user.xui_client_id) {
            try {
                await fetchJson('/admin/client/update-expiry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        inboundId: parseInt(user.xui_inbound_id),
                        clientId: user.xui_client_id,
                        expiryDate: xuiExpiryDate
                    })
                });
            } catch (err) {
                console.error('Error updating 3X-UI expiry:', err);
            }
        }
        
        showMsg(isVip ? 'VIP включен (бессрочно)' : 'VIP отключен');
        loadLocalUsers();
        
    } catch (err) {
        showMsg('Ошибка: ' + err.message, true);
        e.target.checked = !e.target.checked;
    } finally {
        e.target.disabled = false;
    }
}

async function handleLocalToggle(e) {
    const uuid = e.target.dataset.uuid;
    const enable = e.target.checked;
    e.target.disabled = true;
    try {
        await fetchJson('/admin/user/sync-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ uuid, enable })
        });
        showMsg(`Статус изменён на ${enable ? 'активен' : 'заблокирован'}`);
        await loadLocalUsers();
    } catch (err) {
        showMsg('Ошибка синхронизации статуса', true);
        e.target.checked = !enable;
        e.target.disabled = false;
    }
}

window.deleteUser = async function(uuid) {
    if (!confirm('Удалить пользователя?')) return;
    await fetchJson('/admin/user/delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ uuid })
    });
    showMsg('Удалён');
    loadLocalUsers();
};

document.addEventListener('DOMContentLoaded', function() {
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const uuid = document.getElementById('userUuid').value;
            let login = document.getElementById('userLogin').value.trim();
            const coins = parseInt(document.getElementById('userCoins').value) || 0;
            const vip = parseInt(document.getElementById('userVip').value) || 0;
            const localstatus = document.getElementById('userStatusToggle').checked ? 'active' : 'disabled';
            const email = document.getElementById('userEmail').value;
            const phone = document.getElementById('userPhone').value;
            const price_per_day = parseInt(document.getElementById('userPricePerDay').value) || null;
            const expiry_date = parseInt(document.getElementById('userExpiryDate').value) || 0;
            const password = document.getElementById('userPassword').value;
            const xui_email = document.getElementById('xuiEmail').value;
            const xui_inbound_id = document.getElementById('xuiInboundId').value;
            const xui_client_id = document.getElementById('xuiClientId').value;
            const subId = document.getElementById('userSubId').value;

            let payload = { login, coins, vip, localstatus, email, phone, price_per_day, expiry_date, xui_email, xui_inbound_id, xui_client_id, subId };
            let url, method;

            if (uuid) {
                payload.uuid = uuid;
                url = '/admin/user/update';
                method = 'POST';
            } else {
                const users = await fetchJson('/admin/users');
                if (users.some(u => u.login === login)) login = login + '_db';
                payload.login = login;
                url = '/admin/user/create-from-xui';
                method = 'POST';
                if (!password) { showMsg('Укажите пароль', true); return; }
                payload.password = password;
            }

            try {
                await fetchJson(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                showMsg(uuid ? 'Обновлён' : 'Создан');
                closeUserModal();
                loadLocalUsers();
            } catch (err) {
                showMsg('Ошибка сохранения: ' + err.message, true);
            }
        });
    }
    
    const statusToggle = document.getElementById('userStatusToggle');
    if (statusToggle) {
        statusToggle.addEventListener('change', function(e) {
            document.getElementById('statusLabel').innerText = e.target.checked ? 'Активен' : 'Заблокирован';
        });
    }
    
    setupAutoRefreshControls();
});

if (document.getElementById('local-tab')) {
    loadLocalUsers();
}
// Синхронизация дат с 3X-UI
document.getElementById('syncToXuiBtn')?.addEventListener('click', async () => {
    if (!confirm('Выгрузить даты окончания всех пользователей в 3X-UI?')) return;
    
    showMsg('Синхронизация началась...');
    
    try {
        const users = await fetchJson('/admin/users');
        let successCount = 0;
        let failCount = 0;
        
        for (const user of users) {
            if (user.xui_inbound_id && user.xui_client_id && user.expiry_date) {
                try {
                    await fetchJson('/admin/client/update-expiry', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            inboundId: parseInt(user.xui_inbound_id),
                            clientId: user.xui_client_id,
                            expiryDate: user.expiry_date
                        })
                    });
                    successCount++;
                } catch (e) {
                    failCount++;
                    console.error(`${user.login}: ${e.message}`);
                }
            }
        }
        
        showMsg(`Готово! Обновлено: ${successCount}, Ошибок: ${failCount}`);
    } catch (e) {
        showMsg('Ошибка синхронизации', true);
    }
});
