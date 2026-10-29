let onlineCheckInterval = null;

async function loadUserData() {
    try {
        const resp = await fetch('/api/profile');
        if (!resp.ok) {
            if (resp.status === 401) window.location.href = '/';
            throw new Error('Unauthorized');
        }
        const profile = await resp.json();

        userData.login = profile.login;
        userData.role = profile.role;
        userData.coins = profile.coins;
        userData.localexpire = profile.localexpire;
        userData.profileStatus = profile.localstatus;
        userData.personalDayPrice = profile.price_per_day || null;
        userData.email = profile.email || '';
        userData.expiryDate = profile.expiry_date || 0;
        userData.vip = profile.vip || 0;

        document.getElementById('userLogin').innerText = userData.login;
        document.getElementById('userRole').innerText = userData.role === 'admin' ? 'Администратор' : 'Пользователь';
        document.getElementById('coinsStat').innerText = userData.coins;

        const coinsPanelElement = document.getElementById('coinsPanel');
        if (userData.coins > 0) {
            coinsPanelElement.className = 'coins-panel has-coins';
        } else {
            coinsPanelElement.className = 'coins-panel no-coins';
        }

        // Блок покупки дней: скрываем/показываем при VIP
        const coinsPanel = document.getElementById('coinsPanel');
        const vipImageBlock = document.getElementById('vipImageBlock');
        if (userData.vip === 1) {
            if (coinsPanel) coinsPanel.style.display = 'none';
            if (vipImageBlock) vipImageBlock.style.display = 'block';
        } else {
            if (coinsPanel) coinsPanel.style.display = 'block';
            if (vipImageBlock) vipImageBlock.style.display = 'none';
        }

        let daysLeftDisplay = '—';
        if (userData.vip === 1) {
            daysLeftDisplay = '∞';
        } else if (userData.expiryDate && userData.expiryDate > 0) {
            const now = Math.floor(Date.now() / 1000);
            const diff = userData.expiryDate - now;
            daysLeftDisplay = diff > 0 ? Math.ceil(diff / 86400) : 0;
        }
        document.getElementById('daysStat').innerText = daysLeftDisplay;
        userData.daysLeft = (daysLeftDisplay !== '—' && daysLeftDisplay !== '∞') ? daysLeftDisplay : 0;

        const statusElem = document.getElementById('profileStatusText');
        if (userData.profileStatus === 'active') {
            statusElem.innerText = 'Активен';
            statusElem.className = 'profile-info-value status-active';
        } else {
            statusElem.innerText = 'Заблокирован';
            statusElem.className = 'profile-info-value status-inactive';
        }

        const expiryDateElem = document.getElementById('expiryDate');
        if (expiryDateElem) {
            if (userData.vip === 1) {
                expiryDateElem.innerText = 'Бессрочно (VIP)';
            } else if (userData.expiryDate && userData.expiryDate > 0) {
                const expiryDate = new Date(userData.expiryDate * 1000);
                expiryDateElem.innerText = expiryDate.toLocaleString();
            } else {
                expiryDateElem.innerText = 'Бессрочно';
            }
        }

        document.getElementById('profileEmail').innerText = userData.email || '—';

        const dayPrice = getDayPrice();
        document.getElementById('price1Day').innerHTML = dayPrice + ' coins';
        document.getElementById('price30Day').innerHTML = (dayPrice * 30) + ' coins';

        // Блокируем кнопки покупки если VIP
        const buy1Btn = document.getElementById('buy1DayBtn');
        const buy30Btn = document.getElementById('buy30DayBtn');
        if (userData.vip === 1) {
            if (buy1Btn) buy1Btn.disabled = true;
            if (buy30Btn) buy30Btn.disabled = true;
        } else {
            if (buy1Btn) buy1Btn.disabled = false;
            if (buy30Btn) buy30Btn.disabled = false;
        }

        return true;
    } catch(e) {
        showMsg('Ошибка загрузки профиля', true);
        return false;
    }
}

async function checkOnlineStatus() {
    try {
        const resp = await fetch('/api/online-status');
        const data = await resp.json();

        const onlineStatusTopElem = document.getElementById('onlineStatusTop');
        if (onlineStatusTopElem) {
            if (data.online) {
                onlineStatusTopElem.innerHTML = '<span style="color:#2c9e6e;"><i class="fas fa-circle"></i> Online</span>';
            } else {
                onlineStatusTopElem.innerHTML = '<span style="color:#b91c1c;"><i class="fas fa-circle"></i> Offline</span>';
            }
        }
    } catch(e) {
        console.error('Online status check error:', e);
    }
}

async function loadFooter() {
    try {
        const resp = await fetch('/api/footer');
        const data = await resp.json();
        document.getElementById('footerText').innerText = data.content || 'Добро пожаловать в PQService!';
    } catch(e) {
        document.getElementById('footerText').innerText = 'Сервис работает.';
    }
}

document.getElementById('logoutBottomBtn')?.addEventListener('click', async () => {
    if (onlineCheckInterval) clearInterval(onlineCheckInterval);
    await fetch('/api/logout', { method: 'POST' });
    window.location.href = '/';
});

document.getElementById('copySubProfileBtn')?.addEventListener('click', () => {
    if (window.subLink) {
        navigator.clipboard.writeText(window.subLink);
        showMsg('Ссылка скопирована');
    } else {
        showMsg('Ссылка недоступна', true);
    }
});

document.getElementById('openSubProfileBtn')?.addEventListener('click', () => {
    if (window.subLink) {
        window.open(window.subLink, '_blank');
    } else {
        showMsg('Ссылка недоступна', true);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    loadUserData();
    loadFooter();
    checkOnlineStatus();
    if (onlineCheckInterval) clearInterval(onlineCheckInterval);
    onlineCheckInterval = setInterval(checkOnlineStatus, 10000);
});
