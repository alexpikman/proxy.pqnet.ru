async function loadSubscriptionDetails() {
    const footerSubid = document.getElementById("footerSubid");
    if (subData.id) footerSubid.innerText = subData.id;
    else footerSubid.innerText = "—";
    
    try {
        const resp = await fetch('/api/subscription-details');
        if (resp.ok) {
            const sub = await resp.json();
            subData = sub;
            
            // Формируем правильную ссылку на подписку: /vless/{subId}
            const subId = sub.id;
            if (subId && subId !== '—') {
                window.subLink = `${window.location.protocol}//${window.location.hostname}:2096/vless/${subId}`;
            } else {
                window.subLink = '';
            }
            
            // НЕ ОБНОВЛЯЕМ expiryDate здесь - оно берется из локальной БД в profile-user.js
        } else {
            setDefaultSub();
        }
    } catch(e) {
        setDefaultSub();
    }
    updateSubscriptionLinkState();
}

function setDefaultSub() {
    window.subLink = '';
    // Не трогаем expiryDate здесь
}

function updateSubscriptionLinkState() {
    const hasDays = (userData.daysLeft > 0) || (userData.localexpire > Math.floor(Date.now()/1000));
    const container = document.getElementById('subLinkContainer');
    if (!hasDays || !window.subLink) {
        if (container) container.classList.add('gray-link');
    } else {
        if (container) container.classList.remove('gray-link');
    }
}

// Обработчики кнопок подписки в разделе "Подключить подписку"
document.getElementById('copySubUrlBtn')?.addEventListener('click', () => {
    const hasDays = (userData.daysLeft > 0) || (userData.localexpire > Math.floor(Date.now()/1000));
    if (window.subLink && hasDays) {
        navigator.clipboard.writeText(window.subLink);
        showMsg('Ссылка скопирована');
    } else {
        showMsg('Подписка неактивна, купите дни', true);
    }
});

document.getElementById('openInAppBtn')?.addEventListener('click', () => {
    const hasDays = (userData.daysLeft > 0) || (userData.localexpire > Math.floor(Date.now()/1000));
    if (window.subLink && hasDays) {
        window.open(window.subLink, '_blank');
    } else {
        showMsg('Подписка неактивна, купите дни', true);
    }
});

// Обработчики кнопок подписки в разделе "Профиль подписки"
document.getElementById('copySubProfileBtn')?.addEventListener('click', () => {
    const hasDays = (userData.daysLeft > 0) || (userData.localexpire > Math.floor(Date.now()/1000));
    if (window.subLink && hasDays) {
        navigator.clipboard.writeText(window.subLink);
        showMsg('Ссылка скопирована');
    } else {
        showMsg('Подписка неактивна, купите дни', true);
    }
});

document.getElementById('openSubProfileBtn')?.addEventListener('click', () => {
    const hasDays = (userData.daysLeft > 0) || (userData.localexpire > Math.floor(Date.now()/1000));
    if (window.subLink && hasDays) {
        window.open(window.subLink, '_blank');
    } else {
        showMsg('Подписка неактивна, купите дни', true);
    }
});

// Календарь
document.getElementById('calcDaysBtn')?.addEventListener('click', () => {
    const dateInput = document.getElementById('targetDate').value;
    if (!dateInput) {
        showMsg('Выберите дату', true);
        return;
    }
    const target = new Date(dateInput);
    const now = new Date();
    if (target <= now) {
        showMsg('Дата должна быть в будущем', true);
        return;
    }
    const diffTime = target - now;
    const days = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    if (days <= 0) {
        showMsg('Некорректная дата', true);
        return;
    }
    document.getElementById('daysCount').innerText = days;
    const pricePerDay = getDayPrice();
    const totalCost = days * pricePerDay;
    document.getElementById('customPrice').innerHTML = totalCost + ' coins';
    document.getElementById('customDaysResult').style.display = 'flex';
    document.getElementById('customDaysResult').style.flexWrap = 'wrap';
    document.getElementById('customDaysResult').style.gap = '10px';
    document.getElementById('customDaysResult').style.alignItems = 'center';
    window.customDays = days;
    window.customCost = totalCost;
});

// Загружаем подписку после загрузки пользователя
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(loadSubscriptionDetails, 500);
});
