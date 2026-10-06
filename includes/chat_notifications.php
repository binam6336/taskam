<?php
// includes/chat_notifications.php
if (!defined('BASE_URL')) { return; }
$u = $_SESSION['user_id'] ?? 0;
if (!$u) { return; }
?>
<script>
window.CHAT_NOTIF_CONFIG = {
    apiUrl:  '<?= BASE_URL ?>/chat/api.php',
    chatUrl: '<?= BASE_URL ?>/chat/index.php'
};
</script>
<script src="<?= BASE_URL ?>/assets/js/chat_notifications.js" defer></script>