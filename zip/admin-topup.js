async function loadTopupRequests() {
    try {
        const requests = await fetchJson('/admin/topup-requests');
        const tbody = document.getElementById('topup-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        
        if (requests.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6">Нет заявок</td></tr>';
            return;
        }
        
        for (const req of requests) {
            const row = tbody.insertRow();
            row.insertCell(0).innerText = req.id;
            row.insertCell(1).innerText = req.login;
            row.insertCell(2).innerText = req.amount + ' руб.';
            
            let statusText = '';
            let statusColor = '';
            if (req.status === 'pending') {
                statusText = '⏳ Ожидает';
                statusColor = '#ff9800';
            } else if (req.status === 'approved') {
                statusText = '✅ Одобрено';
                statusColor = '#2c9e6e';
            } else {
                statusText = '❌ Отклонено';
                statusColor = '#b91c1c';
            }
            row.insertCell(3).innerHTML = `<span style="color: ${statusColor};">${statusText}</span>`;
            row.insertCell(4).innerText = new Date(req.created_at * 1000).toLocaleString();
            
            const actions = row.insertCell(5);
            if (req.status === 'pending') {
                actions.innerHTML = `
                    <button class="btn-submit" style="background: #2c9e6e; padding: 0.2rem 0.5rem; margin-right: 0.3rem;" onclick="approveTopup(${req.id})"><i class="fas fa-check"></i> Начислить</button>
                    <button class="btn-submit" style="background: #b91c1c; padding: 0.2rem 0.5rem;" onclick="rejectTopup(${req.id})"><i class="fas fa-times"></i> Отклонить</button>
                `;
            } else {
                actions.innerHTML = '—';
            }
        }
    } catch (e) {
        showMsg('Ошибка загрузки заявок', true);
    }
}

async function approveTopup(id) {
    const coins = prompt('Введите количество монет для начисления (например: 1000):');
    if (!coins || isNaN(coins) || coins <= 0) {
        showMsg('Введите корректное количество монет', true);
        return;
    }
    
    try {
        await fetchJson('/admin/topup-approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, coins_amount: parseInt(coins) })
        });
        showMsg('Монеты начислены');
        loadTopupRequests();
    } catch (e) {
        showMsg('Ошибка', true);
    }
}

async function rejectTopup(id) {
    if (!confirm('Отклонить заявку?')) return;
    try {
        await fetchJson('/admin/topup-reject', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        showMsg('Заявка отклонена');
        loadTopupRequests();
    } catch (e) {
        showMsg('Ошибка', true);
    }
}

if (document.getElementById('topup-tab')) {
    loadTopupRequests();
}
