let allUsersList = [];

async function loadUsersForMass() {
    try {
        const users = await fetchJson('/admin/users');
        allUsersList = users;
        renderUsersChecklist();
    } catch (e) {
        showMsg('Ошибка загрузки пользователей', true);
    }
}

function renderUsersChecklist() {
    const container = document.getElementById('usersChecklist');
    if (!container) return;
    
    container.innerHTML = '';
    for (const user of allUsersList) {
        const label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0; cursor: pointer;';
        label.innerHTML = `
            <input type="checkbox" class="user-checkbox" data-uuid="${user.uuid}" data-login="${user.login}">
            <span>${user.login}</span>
        `;
        container.appendChild(label);
    }
    
    document.getElementById('selectAllUsers')?.addEventListener('change', (e) => {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
    });
}

function openMassModal() {
    loadUsersForMass();
    document.getElementById('massModal').classList.add('active');
}

function closeMassModal() {
    document.getElementById('massModal').classList.remove('active');
}

async function applyMassUpdate() {
    const selectedUsers = [];
    document.querySelectorAll('.user-checkbox:checked').forEach(cb => {
        selectedUsers.push({
            uuid: cb.dataset.uuid,
            login: cb.dataset.login
        });
    });
    
    if (selectedUsers.length === 0) {
        showMsg('Выберите хотя бы одного пользователя', true);
        return;
    }
    
    const coins = document.getElementById('massCoins').value;
    const expiryDate = document.getElementById('massExpiryDate').value;
    
    let expiryTimestamp = null;
    if (expiryDate) {
        expiryTimestamp = Math.floor(new Date(expiryDate).getTime() / 1000);
    }
    
    if (!coins && !expiryTimestamp) {
        showMsg('Укажите монеты или дату окончания', true);
        return;
    }
    
    showMsg(`Обработка ${selectedUsers.length} пользователей...`);
    
    for (const user of selectedUsers) {
        try {
            const currentUser = await fetchJson('/admin/user/' + user.uuid);
            
            const updateData = {
                uuid: user.uuid,
                login: currentUser.login,
                coins: coins !== '' ? parseInt(coins) : currentUser.coins,
                vip: currentUser.vip,
                localstatus: currentUser.localstatus,
                email: currentUser.email,
                phone: currentUser.phone,
                price_per_day: currentUser.price_per_day,
                expiry_date: expiryTimestamp !== null ? expiryTimestamp : (currentUser.expiry_date || 0)
            };
            
            await fetchJson('/admin/user/update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(updateData)
            });
            
            // Синхронизируем с 3X-UI
            if (currentUser.xui_inbound_id && currentUser.xui_client_id && expiryTimestamp !== null) {
                await fetchJson('/admin/client/update-expiry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        inboundId: parseInt(currentUser.xui_inbound_id),
                        clientId: currentUser.xui_client_id,
                        expiryDate: expiryTimestamp
                    })
                });
            }
        } catch (e) {
            console.error(`Ошибка при обработке ${user.login}:`, e);
        }
    }
    
    showMsg(`Готово! Обработано ${selectedUsers.length} пользователей`);
    closeMassModal();
    loadLocalUsers();
}

document.getElementById('massActionBtn')?.addEventListener('click', openMassModal);
document.getElementById('applyMassBtn')?.addEventListener('click', applyMassUpdate);
document.getElementById('massModal')?.addEventListener('click', (e) => {
    if (e.target === document.getElementById('massModal')) closeMassModal();
});
