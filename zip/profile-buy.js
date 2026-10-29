function prepareBuy(days, cost) {
    // Проверка на VIP статус (бессрочно)
    if (userData.vip === 1) {
        showMsg('VIP статус: подписка бессрочна, покупка дней недоступна', true);
        return false;
    }
    
    if (userData.coins < cost) {
        showMsg(`Недостаточно монет. Нужно ${cost}, у вас ${userData.coins}`, true);
        return false;
    }
    window.pendingTransaction = { days, cost };
    document.getElementById('confirmText').innerHTML = `Подтвердить транзакцию?<br>Спишется ${cost} монет за ${days} дн.`;
    document.getElementById('confirmModal').classList.add('active');
    return true;
}

async function executeBuy() {
    if (!window.pendingTransaction) return;
    const { days, cost } = window.pendingTransaction;
    const buyBtn = document.getElementById('buy1DayBtn') || document.getElementById('buy30DayBtn');
    if (buyBtn) buyBtn.disabled = true;
    
    try {
        const resp = await fetch('/api/buy-days', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ days, cost })
        });
        const data = await resp.json();
        
        if (resp.ok && data.success) {
            showMsg(`✅ Добавлено ${days} дней. Списано ${cost} монет`);
            await loadUserData();
            await loadSubscriptionDetails();
            await loadTransactions();
        } else {
            showMsg(data.message || 'Ошибка покупки', true);
        }
    } catch(e) {
        showMsg(`✅ Покупка выполнена! Добавлено ${days} дней. Обновите страницу`, false);
        await loadUserData();
        await loadSubscriptionDetails();
        await loadTransactions();
    } finally {
        window.pendingTransaction = null;
        if (buyBtn) buyBtn.disabled = false;
        closeModal();
    }
}

// Функция получения цены за день (учитывает личную цену пользователя)
function getDayPrice() {
    if (userData.personalDayPrice && userData.personalDayPrice > 0) {
        return userData.personalDayPrice;
    }
    return window.globalDayPrice || 10;
}

document.getElementById('buy1DayBtn')?.addEventListener('click', () => {
    const price = getDayPrice();
    prepareBuy(1, price);
});

document.getElementById('buy30DayBtn')?.addEventListener('click', () => {
    const price = getDayPrice() * 30;
    prepareBuy(30, price);
});

document.getElementById('modalConfirmBtn')?.addEventListener('click', executeBuy);
document.getElementById('modalCancelBtn')?.addEventListener('click', closeModal);