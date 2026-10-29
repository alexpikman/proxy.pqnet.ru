async function loadTransactions() {
    try {
        const resp = await fetch('/api/transactions');
        if (!resp.ok) throw new Error();
        const txs = await resp.json();
        const tbody = document.getElementById('transactionsTbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        
        if (txs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4">Нет транзакций</td></tr>';
            return;
        }
        
        for (const tx of txs) {
            const row = tbody.insertRow();
            row.insertCell(0).innerText = new Date(tx.created_at).toLocaleString();
            row.insertCell(1).innerText = tx.type === 'buy_days' ? 'Покупка дней' : tx.type;
            const amountCell = row.insertCell(2);
            amountCell.innerHTML = tx.amount > 0 ? `<span class="positive">+${tx.amount}</span>` : `<span class="negative">${tx.amount}</span>`;
            row.insertCell(3).innerText = tx.description || '—';
        }
    } catch(e) {
        const tbody = document.getElementById('transactionsTbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="4">Ошибка загрузки</td></tr>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(loadTransactions, 500);
});
