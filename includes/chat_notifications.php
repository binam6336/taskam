<?php
// includes/chat_notifications.php
if (!defined('BASE_URL')) {
    return;
}
$u = $_SESSION['user_id'] ?? 0;
if (!$u) {
    return;
}
?>
<script>
    window.CHAT_NOTIF_CONFIG = {
        apiUrl: '<?= BASE_URL ?>/chat/api.php',
        chatUrl: '<?= BASE_URL ?>/chat/index.php'
    };
    window.TASK_NOTIF_CONFIG = {
        apiUrl: '<?= BASE_URL ?>/tasks/notif_api.php',
        taskUrl: '<?= BASE_URL ?>/tasks/index.php',
        iconUrl: 'https://img.icons8.com/color/96/dashboard-layout.png',
        pollIntervalMs: 30000,
        firstDelayMs: 5000
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/chat_notifications.js" defer></script>