<?php
// includes/chat_notifications.php
// اگر BASE_URL تعریف نشده، خودکار محاسبه کن
if (!defined('BASE_URL')) {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $root    = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $rel     = ($docRoot && $root && strpos($root, $docRoot) === 0)
        ? substr($root, strlen($docRoot))
        : '';
    define('BASE_URL', $rel);
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