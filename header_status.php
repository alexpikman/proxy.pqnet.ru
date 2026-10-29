<?php
function renderServerHeaderStatus($data = []) {
    $cpuPct      = number_format($data['cpuPct'] ?? 18.4, 1);
    $memUsed     = $data['memUsedGb'] ?? 1.68;
    $memTotal    = $data['memTotalGb'] ?? 4.0;
    $memPct      = round(($memUsed / max($memTotal, 1)) * 100);
    
    $netDown     = number_format($data['netDownMb'] ?? 4.2, 1);
    $netUp       = number_format($data['netUpMb'] ?? 1.8, 1);
    $totalTraffic= number_format($data['totalTrafficTb'] ?? 3.84, 2);
    
    $username    = $data['username'] ?? 'AlexPQ';
    $vlessKey    = $data['vlessKey'] ?? 'vless://61a277e8-abce-4744-898e-09de40c0497c@144.31.126.125:443?type=tcp';
    ?>
    <style>
    .pq-wrapper { width: 100%; margin-bottom: 1rem; }
    .pq-account-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.02); }
    .pq-account-title { font-size: 0.7rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
    .pq-account-name { font-size: 1.05rem; font-weight: 700; color: #0f172a; }

    .pq-metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 8px; }
    @media (max-width: 992px) { .pq-metrics-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 576px) { .pq-metrics-grid { grid-template-columns: 1fr; } }

    .pq-metric-item { display: flex; align-items: center; gap: 10px; background: #ffffff; border: 1px solid #e2e8f0; padding: 8px 10px; border-radius: 10px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.02); }
    .pq-metric-icon { width: 32px; height: 32px; min-width: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .pq-svg { width: 16px; height: 16px; }
    .pq-metric-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; color: #64748b; }
    .pq-metric-val { font-size: 0.82rem; font-weight: 700; color: #0f172a; line-height: 1.2; }

    .pq-key-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.02); }
    .pq-key-icon-box { width: 36px; height: 36px; min-width: 36px; background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    .pq-key-input { flex-grow: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; font-family: monospace; font-size: 0.78rem; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 200px; }
    .pq-key-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    </style>

    <svg style="display: none;">
        <symbol id="icon-custom-key" viewBox="0 0 128 128">
            <path d="M125.5,94.6c-1.2-2.9-3.4-4.4-6.5-4.4c-1.1,0-2.4,0.2-3.8,0.6l-21.3,7.4c0-0.1,0-0.1,0-0.2c0-0.1,0-0.2,0-0.3c0-0.2,0-0.3,0-0.5c0-0.1,0-0.2,0-0.2c0,0,0-0.1,0-0.1c0,0,0-0.1,0-0.1c0-0.2-0.1-0.5-0.1-0.7c0,0,0,0,0-0.1l0,0c-0.9-3.9-4.5-6.8-8.7-6.8H68.2c-7.8-9.9-25.9-10-26.7-10h-13v-4.8H2v44.1h26.5v-4.2l32.8,10.4c0.2,0.1,5.1,1.5,10.7,1.5h0c3.4,0,6.4-0.5,9-1.6c6.6-2.7,33.1-16.9,41.1-21.2C124.3,102.1,127.2,98.7,125.5,94.6z M24.5,114.4H6V78.3h18.5V79v33.8V114.4z M120.1,99.8c-7.9,4.3-34.3,18.3-40.7,21c-2,0.8-4.5,1.3-7.4,1.3h0c-5.1,0-9.6-1.3-9.6-1.3l-34-10.7V83h13c0.2,0,17.8,0.1,24,9.2l0.6,0.9h18.9c2.5,0,4.6,1.8,4.9,4.2c0,0,0,0.1,0,0.1c0,0.2,0,0.3,0,0.5c0,0.2,0,0.4-0.1,0.6c0,0.1,0,0.2,0,0.3c0,0.2-0.1,0.4-0.2,0.6c0,0.1,0,0.2-0.1,0.2c-0.1,0.3-0.2,0.5-0.4,0.7l0,0c-0.8,1.2-2.2,2-3.8,2h-26v4h26c2.7,0,5.2-1.3,6.8-3.3l24.3-8.4c1-0.3,1.9-0.4,2.7-0.4c1.5,0,2.2,0.5,2.8,1.9C122.6,98,120.7,99.5,120.1,99.8z" fill="#3EBBC4"/>
            <path d="M99.1,54.7c0.8,0.3,1.7,0.5,2.6,0.5c3.2,0,6.1-2,7.2-5c1.4-4-0.6-8.3-4.6-9.8c-0.8-0.3-1.7-0.5-2.6-0.5c-3.2,0-6.1,2-7.2,5c-0.7,1.9-0.6,4,0.3,5.8C95.6,52.6,97.1,54,99.1,54.7z M98.3,46.3c0.5-1.4,1.9-2.4,3.4-2.4c0.4,0,0.8,0.1,1.2,0.2c1.9,0.7,2.9,2.8,2.2,4.6c-0.5,1.4-1.9,2.4-3.4,2.4c-0.4,0-0.8-0.1-1.2-0.2c-0.9-0.3-1.6-1-2-1.9S97.9,47.2,98.3,46.3z" fill="#3EBBC4"/>
            <path d="M39.5,35.4l3.7-1.7l1.7,3.7l8.6,3.1l3.7-1.7l1.7,3.7l7.9,2.9c0.6,9.4,6.7,18,16.1,21.4c2.7,1,5.5,1.5,8.4,1.5c10.3,0,19.5-6.5,23-16.1c4.6-12.7-1.9-26.8-14.6-31.4c-2.7-1-5.5-1.5-8.4-1.5c-6.8,0-13.1,2.9-17.7,7.6l-36-13.1l-11.6,3.9l4.6,14.5L39.5,35.4z M91.4,23.3c2.4,0,4.7,0.4,7,1.2c10.6,3.9,16.1,15.7,12.3,26.3c-2.9,8.1-10.7,13.5-19.3,13.5c-2.4,0-4.7-0.4-7-1.2c-10.6-3.9-16.1-15.7-12.3-26.3C75.1,28.8,82.9,23.3,91.4,23.3z M71.1,30.2c-1.1,1.6-2,3.4-2.7,5.3c-0.7,1.9-1.1,3.8-1.3,5.8L62,39.4l-2.7-5.9l-5.9,2.7l-5.5-2l-2.7-5.9l-5.9,2.7L34,29.2l-2.2-6.9l31.4,11.4l1.4-3.8L34.7,19.1l3-1L71.1,30.2z" fill="#3EBBC4"/>
        </symbol>
        <symbol id="icon-cpu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/></symbol>
        <symbol id="icon-ram" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 19v2M10 19v2M14 19v2M18 19v2M3 5h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/></symbol>
        <symbol id="icon-speed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
        <symbol id="icon-globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></symbol>
    </svg>

    <div class="pq-wrapper">
        <div class="pq-account-card">
            <div>
                <div class="pq-account-title">Учетная запись</div>
                <div class="pq-account-name"><?= htmlspecialchars($username) ?></div>
            </div>
            <div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm py-1 px-3" style="font-size: 0.8rem;">Выход</a>
            </div>
        </div>

        <div class="pq-metrics-grid">
            <div class="pq-metric-item">
                <div class="pq-metric-icon" style="background:#eef2ff;color:#4f46e5;"><svg class="pq-svg"><use href="#icon-cpu"></use></svg></div>
                <div class="w-100">
                    <div class="pq-metric-label">CPU</div>
                    <div class="pq-metric-val"><?= $cpuPct ?>%</div>
                </div>
            </div>
            <div class="pq-metric-item">
                <div class="pq-metric-icon" style="background:#ecfdf5;color:#059669;"><svg class="pq-svg"><use href="#icon-ram"></use></svg></div>
                <div class="w-100">
                    <div class="pq-metric-label">RAM</div>
                    <div class="pq-metric-val"><?= $memUsed ?> / <?= $memTotal ?> GB</div>
                </div>
            </div>
            <div class="pq-metric-item">
                <div class="pq-metric-icon" style="background:#fffbeb;color:#d97706;"><svg class="pq-svg"><use href="#icon-speed"></use></svg></div>
                <div>
                    <div class="pq-metric-label">Скорость сети</div>
                    <div class="pq-metric-val" style="font-size: 0.78rem;">↓<?= $netDown ?>/↑<?= $netUp ?> МБ/с</div>
                </div>
            </div>
            <div class="pq-metric-item">
                <div class="pq-metric-icon" style="background:#f5f3ff;color:#7c3aed;"><svg class="pq-svg"><use href="#icon-globe"></use></svg></div>
                <div>
                    <div class="pq-metric-label">Трафик</div>
                    <div class="pq-metric-val"><?= $totalTraffic ?> TB</div>
                </div>
            </div>
        </div>

        <div class="pq-key-card">
            <div class="pq-key-icon-box">
                <svg style="width: 20px; height: 20px;"><use href="#icon-custom-key"></use></svg>
            </div>
            <div class="pq-key-input" id="vless-key-text" title="<?= htmlspecialchars($vlessKey) ?>"><?= htmlspecialchars($vlessKey) ?></div>
            <div class="pq-key-actions">
                <button class="btn btn-outline-primary btn-sm py-1 px-2" style="font-size: 0.78rem;" onclick="navigator.clipboard.writeText(document.getElementById('vless-key-text').innerText); alert('Ключ скопирован!');">Копировать</button>
                <a href="download.php?type=txt" class="btn btn-primary btn-sm py-1 px-2" style="font-size: 0.78rem;">TXT</a>
                <a href="download.php?type=json" class="btn btn-info btn-sm text-white py-1 px-2" style="font-size: 0.78rem;">JSON</a>
            </div>
        </div>
    </div>
    <?php
}
