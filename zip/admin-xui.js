async function loadInbounds() {
    try {
        const inbounds = await fetchJson('/admin/inbounds');
        currentInbounds = inbounds;
        const select = document.getElementById('inboundSelect');
        if (!select) return;
        select.innerHTML = '<option value="">-- Выберите подключение --</option>';
        for (const ib of inbounds) {
            const option = document.createElement('option');
            option.value = ib.id;
            option.textContent = `${ib.remark || ib.protocol || 'Inbound'} (ID: ${ib.id}, порт: ${ib.port})`;
            select.appendChild(option);
        }
        return true;
    } catch (e) {
        showMsg('Ошибка загрузки инбондов: ' + e.message, true);
        return false;
    }
}

async function loadClients(inboundId) {
    if (!inboundId) {
        showMsg('Выберите Inbound', true);
        return false;
    }
    try {
        const users = await fetchJson('/admin/users');
        localUsersList = users;
        const data = await fetchJson(`/admin/inbound-clients?inboundId=${inboundId}`);
        const clients = data.clients || [];
        renderClientsTable(clients, inboundId);
        document.getElementById('clientsTableContainer').style.display = 'block';
        return true;
    } catch (e) {
        showMsg('Ошибка загрузки клиентов: ' + e.message, true);
        document.getElementById('clientsTableContainer').style.display = 'none';
        return false;
    }
}

function renderClientsTable(clients, inboundId) {
    const tbody = document.getElementById('clients-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!clients.length) {
        tbody.innerHTML = '<tr><td colspan="6">Нет клиентов в этом inbound</td></tr>';
        return;
    }
    for (let i = 0; i < clients.length; i++) {
        const c = clients[i];
        const row = tbody.insertRow();
        const exists = localUsersList.some(u => u.login === c.email || u.email === c.email);
        if (exists) row.classList.add('row-exists');
        row.insertCell(0).innerText = i + 1;
        row.insertCell(1).innerText = c.email || '—';
        
        const sc = row.insertCell(2);
        const en = c.enable === true;
        sc.innerHTML = `<label class="toggle-switch"><input type="checkbox" ${en ? 'checked' : ''} data-client-id="${c.id}" data-inbound-id="${inboundId}" class="client-toggle"><span class="slider"></span></label>`;
        
        const subCell = row.insertCell(3);
        if (c.subId) {
            const subUrl = `${window.location.protocol}//${window.location.hostname}:2096/vless/${c.subId}`;
            subCell.innerHTML = `<button class="btn-submit" style="padding:0.3rem 0.8rem; background:#2c9e6e;" onclick="window.open('${subUrl}', '_blank')">Подписка</button>`;
        } else {
            subCell.innerHTML = '—';
        }
        
        const expiryCell = row.insertCell(4);
        expiryCell.innerText = c.expiryTime ? new Date(c.expiryTime).toLocaleString() : 'Бессрочно';
        
        const ac = row.insertCell(5);
        ac.innerHTML = `<button class="btn-submit" style="padding:0.3rem 0.8rem;" onclick="prepareAdd('${c.email}', '${inboundId}', '${c.id}', '${c.expiryTime || ''}', '${c.subId || ''}')">Добавить</button>`;
    }
    document.querySelectorAll('.client-toggle').forEach(t => {
        t.removeEventListener('change', handleClientToggle);
        t.addEventListener('change', handleClientToggle);
    });
    applyHideRegisteredFilter();
}

async function handleClientToggle(e) {
    const inboundId = e.target.dataset.inboundId;
    const clientId = e.target.dataset.clientId;
    const enable = e.target.checked;
    e.target.disabled = true;
    try {
        await fetchJson('/admin/client/update-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ inboundId, clientId, enable })
        });
        showMsg(`Статус изменён на ${enable ? 'активен' : 'отключён'}`);
    } catch (err) {
        showMsg('Ошибка изменения статуса', true);
        e.target.checked = !enable;
    } finally {
        e.target.disabled = false;
    }
}

function applyHideRegisteredFilter() {
    const hide = document.getElementById('hideRegisteredCheckbox')?.checked;
    const rows = document.querySelectorAll('#clients-tbody tr');
    rows.forEach(row => {
        if (hide && row.classList.contains('row-exists')) {
            row.style.display = 'none';
        } else {
            row.style.display = '';
        }
    });
}

window.prepareAdd = function(email, inboundId, clientId, expiryTime, subId) {
    console.log('prepareAdd called:', {email, inboundId, clientId, expiryTime, subId});
    
    document.getElementById('userUuid').value = '';
    document.getElementById('userLogin').value = email;
    document.getElementById('userCoins').value = 100;
    document.getElementById('userVip').value = 0;
    document.getElementById('userStatusToggle').checked = true;
    document.getElementById('statusLabel').innerText = 'Активен';
    document.getElementById('userEmail').value = email;
    document.getElementById('userPhone').value = '';
    document.getElementById('userPricePerDay').value = '';
    
    let expiryDateSeconds = 0;
    if (expiryTime && expiryTime !== 'null' && expiryTime !== 'undefined') {
        const expiryNum = parseInt(expiryTime);
        if (!isNaN(expiryNum) && expiryNum > 0) {
            expiryDateSeconds = expiryNum > 10000000000 ? Math.floor(expiryNum / 1000) : expiryNum;
        }
    }
    document.getElementById('userExpiryDate').value = expiryDateSeconds;
    
    document.getElementById('userPassword').value = '';
    document.getElementById('xuiEmail').value = email;
    document.getElementById('xuiInboundId').value = inboundId;
    document.getElementById('xuiClientId').value = clientId;
    document.getElementById('userSubId').value = subId || '';
    document.getElementById('modalTitle').innerText = 'Создание пользователя из 3X-UI';
    document.getElementById('userModal').classList.add('active');
};

document.getElementById('hideRegisteredCheckbox')?.addEventListener('change', applyHideRegisteredFilter);
document.getElementById('loadClientsBtn')?.addEventListener('click', async () => {
    const inboundId = document.getElementById('inboundSelect')?.value;
    if (!inboundId) {
        showMsg('Сначала выберите Inbound', true);
        return;
    }
    await loadClients(inboundId);
});
