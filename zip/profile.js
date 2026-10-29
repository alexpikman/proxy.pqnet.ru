let userData = {
    login: '', role: '', coins: 0, daysLeft: 0, profileStatus: 'active', 
    personalDayPrice: null, email: '', expiryDate: 0, subId: ''
};
let pendingTransaction = null;

function showMsg(text, isErr = false) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.querySelector('span').innerText = text;
    toast.style.background = isErr ? '#b91c1c' : '#1e2f3c';
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2500);
}

function getDayPrice() {
    return (userData.personalDayPrice && userData.personalDayPrice > 0) ? userData.personalDayPrice : 10;
}

function closeModal() {
    document.getElementById('confirmModal')?.classList.remove('active');
}

async function loadSubscriptionDetails() {
    try {
        const resp = await fetch('/api/profile');
        if (resp.ok) {
            const profile = await resp.json();
            userData.subId = profile.subId || '';
            
            // Формируем ссылку подписки
            if (userData.subId) {
                window.subLink = `https://proxy.pqnet.ru:2096/vless/${userData.subId}`;
            } else {
                window.subLink = '';
            }
        }
    } catch(e) {
        window.subLink = '';
    }
}

window.showMsg = showMsg;
window.getDayPrice = getDayPrice;
window.closeModal = closeModal;
window.loadSubscriptionDetails = loadSubscriptionDetails;
window.userData = userData;

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(loadSubscriptionDetails, 500);
});

// Пополнение баланса
function openTopupModal() {
    const login = document.getElementById('userLogin').innerText;
    document.getElementById('topupLogin').innerText = login;
    document.getElementById('topupModal').classList.add('active');
}

function closeTopupModal() {
    document.getElementById('topupModal').classList.remove('active');
}

document.getElementById('addCoinsBtn')?.addEventListener('click', openTopupModal);

document.getElementById('confirmTopupBtn')?.addEventListener('click', async () => {
    const amount = prompt('Введите сумму пополнения (в рублях):');
    if (!amount || isNaN(amount) || amount <= 0) {
        showMsg('Введите корректную сумму', true);
        return;
    }
    
    const login = document.getElementById('userLogin').innerText;
    
    try {
        const resp = await fetch('/api/topup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ login, amount: parseInt(amount) })
        });
        const data = await resp.json();
        
        if (resp.ok && data.success) {
            showMsg(`Заявка на пополнение ${amount} руб. отправлена администратору`);
            closeTopupModal();
        } else {
            showMsg(data.error || 'Ошибка', true);
        }
    } catch(e) {
        showMsg('Ошибка соединения', true);
    }
});
