// Загрузка настроек
async function loadSettings() {
    try {
        const cfg = await fetchJson('/admin/settings');
        const xuiHost = document.getElementById('xuiHost');
        const xuiUsername = document.getElementById('xuiUsername');
        const subPort = document.getElementById('subPort');
        const subPath = document.getElementById('subPath');
        if (xuiHost) xuiHost.value = cfg.XUI_HOST || '';
        if (xuiUsername) xuiUsername.value = cfg.XUI_USERNAME || '';
        if (subPort) subPort.value = cfg.SUB_PORT || 2096;
        if (subPath) subPath.value = cfg.SUB_PATH || '/sub/';
    } catch (e) {}
}

// Сохранение настроек
document.getElementById('saveSettingsBtn')?.addEventListener('click', async () => {
    const settings = {
        XUI_HOST: document.getElementById('xuiHost')?.value,
        XUI_USERNAME: document.getElementById('xuiUsername')?.value,
        XUI_PASSWORD: document.getElementById('xuiPassword')?.value,
        SUB_PORT: parseInt(document.getElementById('subPort')?.value) || 2096,
        SUB_PATH: document.getElementById('subPath')?.value
    };
    try {
        await fetchJson('/admin/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(settings)
        });
        showMsg('Настройки сохранены (требуется перезапуск)');
    } catch (e) {
        showMsg('Ошибка сохранения', true);
    }
});

// Загрузка списка бэкапов
async function loadBackupList() {
    try {
        const backups = await fetchJson('/admin/backup-list');
        const listElement = document.getElementById('backupFilesList');
        if (!listElement) return;
        
        if (backups.length === 0) {
            listElement.innerHTML = '<li style="color: #5b6f82;">Нет созданных бэкапов</li>';
            return;
        }
        
        listElement.innerHTML = '';
        for (const backup of backups) {
            const li = document.createElement('li');
            li.style.cssText = 'padding: 0.5rem 0; border-bottom: 1px solid #edf2f7; display: flex; justify-content: space-between; align-items: center;';
            li.innerHTML = `
                <span><i class="fas fa-archive"></i> ${backup.name} (${backup.size})</span>
                <a href="/admin/download-backup?file=${encodeURIComponent(backup.name)}" class="btn-submit" style="padding: 0.2rem 0.8rem; text-decoration: none; font-size: 0.7rem;"><i class="fas fa-download"></i> Скачать</a>
            `;
            listElement.appendChild(li);
        }
    } catch (e) {
        console.error('Ошибка загрузки списка бэкапов:', e);
    }
}

// Создание бэкапа
async function createBackup() {
    try {
        showMsg('Создание бэкапа...');
        const result = await fetchJson('/admin/create-backup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        showMsg(`Бэкап создан: ${result.filename}`);
        await loadBackupList();
    } catch (e) {
        showMsg('Ошибка создания бэкапа: ' + e.message, true);
    }
}

// Восстановление из бэкапа
async function restoreBackup() {
    const filename = prompt('Введите имя файла бэкапа для восстановления:');
    if (!filename) return;
    
    if (!confirm(`Восстановить из бэкапа ${filename}? Сервер будет перезапущен.`)) return;
    
    try {
        showMsg('Восстановление...');
        await fetchJson('/admin/restore-backup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filename: filename })
        });
        showMsg('Восстановление завершено, сервер перезапускается');
        setTimeout(() => location.reload(), 3000);
    } catch (e) {
        showMsg('Ошибка восстановления: ' + e.message, true);
    }
}

// Загрузка времени последнего запуска
async function loadLastRestartTime() {
    try {
        const info = await fetchJson('/admin/last-restart-time');
        const element = document.getElementById('lastRestartTime');
        if (element) {
            element.innerHTML = `<i class="fas fa-clock"></i> Последний запуск: ${info.lastRestartTime}`;
        }
    } catch (e) {
        const element = document.getElementById('lastRestartTime');
        if (element) {
            element.innerHTML = '<i class="fas fa-clock"></i> Последний запуск: неизвестно';
        }
    }
}

// Перезапуск сервера
document.getElementById('restartServiceBtn')?.addEventListener('click', async () => {
    if (!confirm('Перезапустить сервер?')) return;
    try {
        await fetchJson('/admin/restart-service', { method: 'POST' });
        showMsg('Сервер перезапущен, страница будет обновлена');
        setTimeout(() => location.reload(), 2000);
    } catch (e) {
        showMsg('Ошибка перезапуска', true);
    }
});

// Загрузка системной информации
async function loadSystemInfo() {
    try {
        const info = await fetchJson('/admin/system-info');
        const container = document.getElementById('systemInfo');
        if (!container) return;
        container.innerHTML = `
            <strong>Хост:</strong> ${info.hostname}<br>
            <strong>Платформа:</strong> ${info.platform} ${info.arch}<br>
            <strong>CPU:</strong> ${info.cpus} ядер, ${info.cpuModel}<br>
            <strong>Нагрузка:</strong> ${info.loadAvg['1min']}, ${info.loadAvg['5min']}, ${info.loadAvg['15min']}<br>
            <strong>Память:</strong> ${(info.memory.used / 1e9).toFixed(1)} GB / ${(info.memory.total / 1e9).toFixed(1)} GB (${info.memory.percent}%)<br>
            <strong>Аптайм:</strong> ${info.uptimeHuman}<br>
        `;
    } catch (e) {
        const container = document.getElementById('systemInfo');
        if (container) container.innerHTML = 'Ошибка загрузки системной информации';
    }
}

// Загрузка информации о пользователе
async function loadUserInfo() {
    try {
        const profile = await fetchJson('/api/profile');
        const userInfo = document.getElementById('userInfo');
        if (userInfo) {
            userInfo.innerHTML = `<i class="fas fa-user-shield"></i> ${profile.login} (${profile.role})`;
        }
    } catch (e) {
        const userInfo = document.getElementById('userInfo');
        if (userInfo) userInfo.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Ошибка';
    }
}

// Загрузка глобальной цены
async function loadGlobalPrice() {
    try {
        const data = await fetchJson('/admin/global-price');
        const priceContainer = document.getElementById('globalPrice');
        if (priceContainer) {
            priceContainer.innerHTML = `<strong>Глобальная цена за день:</strong> ${data.price_per_day} монет
                <button id="editGlobalPriceBtn" class="btn-submit" style="padding: 0.2rem 0.8rem; margin-left: 1rem; background: #2c9e6e;"><i class="fas fa-edit"></i> Изменить</button>`;
            
            document.getElementById('editGlobalPriceBtn')?.addEventListener('click', () => {
                const newPrice = prompt('Введите новую цену за день (в монетах):', data.price_per_day);
                if (newPrice && !isNaN(newPrice) && newPrice > 0) {
                    fetch('/admin/global-price', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ price_per_day: parseInt(newPrice) })
                    }).then(() => {
                        showMsg('Цена обновлена');
                        loadGlobalPrice();
                    }).catch(() => showMsg('Ошибка', true));
                }
            });
        }
    } catch (e) {
        console.error('Ошибка загрузки цены:', e);
    }
}

// Выход
document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    await fetch('/api/logout', { method: 'POST' });
    window.location.href = '/';
});

// Инициализация событий для бэкапов
document.getElementById('createBackupBtn')?.addEventListener('click', createBackup);
document.getElementById('restoreBackupBtn')?.addEventListener('click', restoreBackup);

// Загружаем при старте
loadUserInfo();
loadBackupList();
loadLastRestartTime();
loadInbounds();
loadGlobalPrice();