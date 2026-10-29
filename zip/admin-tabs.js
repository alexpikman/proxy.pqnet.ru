// Управление вкладками
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        const tid = btn.dataset.tab;
        const activeTab = document.getElementById(tid + '-tab');
        if (activeTab) activeTab.classList.add('active');
        
        // Загружаем данные при переключении
        if (tid === 'local') {
            loadLocalUsers();
        }
        if (tid === 'sync') {
            if (currentInbounds.length === 0) loadInbounds();
            const container = document.getElementById('clientsTableContainer');
            if (container) container.style.display = 'none';
        }
        if (tid === 'settings') {
            loadSettings();
        }
        if (tid === 'monitoring') {
            loadSystemInfo();
        }
    });
});
