let promocodesData = [];

async function loadPromocodes() {
    try {
        const data = await fetchJson('/admin/promocodes');
        promocodesData = data;
        renderPromocodesTable();
    } catch (e) {
        showMsg('Ошибка загрузки промокодов', true);
    }
}

function renderPromocodesTable() {
    const tbody = document.getElementById('promocodes-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    
    if (promocodesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10">Нет промокодов</td></tr>';
        return;
    }
    
    for (const p of promocodesData) {
        const row = tbody.insertRow();
        row.insertCell(0).innerText = p.id;
        row.insertCell(1).innerHTML = `<code style="background:#f0f0f0; padding:2px 6px; border-radius:8px;">${p.code}</code>`;
        
        let typeText = '';
        if (p.type === 'coins') typeText = '💰 Монеты';
        else if (p.type === 'days') typeText = '📅 Дни';
        else typeText = '🎁 Монеты + Дни';
        row.insertCell(2).innerText = typeText;
        
        let rewardText = '';
        if (p.type === 'coins') rewardText = `${p.coins_amount} мон.`;
        else if (p.type === 'days') rewardText = `${p.days_amount} дн.`;
        else rewardText = `${p.coins_amount} мон. + ${p.days_amount} дн.`;
        row.insertCell(3).innerText = rewardText;
        
        row.insertCell(4).innerText = p.max_uses === 0 ? '∞' : p.max_uses;
        row.insertCell(5).innerText = p.used_count || 0;
        
        const expires = p.expires_at ? new Date(p.expires_at * 1000).toLocaleString() : '∞';
        row.insertCell(6).innerText = expires;
        
        const statusHtml = `<label class="toggle-switch"><input type="checkbox" ${p.is_active ? 'checked' : ''} data-id="${p.id}" class="promocode-toggle"><span class="slider"></span></label>`;
        row.insertCell(7).innerHTML = statusHtml;
        
        row.insertCell(8).innerText = p.description || '—';
        
        const actions = row.insertCell(9);
        actions.innerHTML = `<button class="btn-icon btn-danger-icon" onclick="deletePromocode(${p.id})"><i class="fas fa-trash-alt"></i></button>`;
    }
    
    document.querySelectorAll('.promocode-toggle').forEach(toggle => {
        toggle.removeEventListener('change', handlePromocodeToggle);
        toggle.addEventListener('change', handlePromocodeToggle);
    });
}

async function handlePromocodeToggle(e) {
    const id = e.target.dataset.id;
    const is_active = e.target.checked ? 1 : 0;
    try {
        await fetchJson('/admin/promocode/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, is_active })
        });
        showMsg(`Промокод ${is_active ? 'активирован' : 'деактивирован'}`);
        loadPromocodes();
    } catch (err) {
        showMsg('Ошибка', true);
        e.target.checked = !e.target.checked;
    }
}

window.deletePromocode = async function(id) {
    if (!confirm('Удалить промокод?')) return;
    try {
        await fetchJson('/admin/promocode/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        showMsg('Промокод удален');
        loadPromocodes();
    } catch (err) {
        showMsg('Ошибка удаления', true);
    }
};

function showCreatePromocodeModal() {
    document.getElementById('promocodeModal').classList.add('active');
}

function closePromocodeModal() {
    document.getElementById('promocodeModal').classList.remove('active');
    document.getElementById('promocodeForm').reset();
}

document.getElementById('createPromocodeBtn')?.addEventListener('click', showCreatePromocodeModal);
document.getElementById('closePromocodeModal')?.addEventListener('click', closePromocodeModal);

document.getElementById('promocodeForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const code = document.getElementById('promoCode').value.trim().toUpperCase();
    const type = document.getElementById('promoType').value;
    const coins_amount = parseInt(document.getElementById('promoCoins').value) || 0;
    const days_amount = parseInt(document.getElementById('promoDays').value) || 0;
    const max_uses = parseInt(document.getElementById('promoMaxUses').value) || 1;
    const expires_at = document.getElementById('promoExpires').value;
    const description = document.getElementById('promoDesc').value;
    
    if (!code) {
        showMsg('Введите код промокода', true);
        return;
    }
    
    let expiryTimestamp = 0;
    if (expires_at) {
        expiryTimestamp = Math.floor(new Date(expires_at).getTime() / 1000);
    }
    
    try {
        await fetchJson('/admin/promocode/create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                code, type, coins_amount, days_amount, max_uses,
                expires_at: expiryTimestamp, description
            })
        });
        showMsg('Промокод создан');
        closePromocodeModal();
        loadPromocodes();
    } catch (err) {
        showMsg('Ошибка: ' + err.message, true);
    }
});

document.getElementById('promoType')?.addEventListener('change', (e) => {
    const type = e.target.value;
    const coinsRow = document.getElementById('promoCoinsRow');
    const daysRow = document.getElementById('promoDaysRow');
    
    if (type === 'coins') {
        coinsRow.style.display = 'block';
        daysRow.style.display = 'none';
    } else if (type === 'days') {
        coinsRow.style.display = 'none';
        daysRow.style.display = 'block';
    } else {
        coinsRow.style.display = 'block';
        daysRow.style.display = 'block';
    }
});

if (document.getElementById('promocodes-tab-check')) {
    loadPromocodes();
}
