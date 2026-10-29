<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($email === strtolower(ADMIN_USER) && $password === ADMIN_PASSWORD) {
            $_SESSION['is_admin'] = true;
            header('Location: admin.php');
            exit;
        }

        $clientsData = xui_api_request('/panel/api/inbounds/list');
        $xuiEmails = [];
        if ($clientsData && isset($clientsData['obj'])) {
            foreach ($clientsData['obj'] as $inbound) {
                $settings = is_string($inbound['settings']) ? json_decode($inbound['settings'], true) : $inbound['settings'];
                if (isset($settings['clients'])) {
                    foreach ($settings['clients'] as $c) {
                        if (!empty($c['email'])) {
                            $xuiEmails[] = strtolower(trim($c['email']));
                        }
                    }
                }
            }
        }

        if (in_array($email, $xuiEmails)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $defaultHash = password_hash('proxy@PQnet', PASSWORD_DEFAULT);
                $stmtIns = $db->prepare("INSERT INTO users (email, password_hash, password_changed) VALUES (?, ?, 0)");
                $stmtIns->execute([$email, $defaultHash]);
                
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($user && password_verify($password, $user['password_hash'])) {
                $updateLogin = $db->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE LOWER(email) = ?");
                $updateLogin->execute([$email]);

                $_SESSION['user'] = $email;
                header('Location: dashboard.php');
                exit;
            }
        }
    }
    header('Location: index.php?error=1');
    exit;
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; color: #991b1b; padding: 20px; font-family: monospace; margin: 20px; border-radius: 8px;'>";
    echo "<strong>Критическая ошибка авторизации:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
