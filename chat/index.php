<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../includes/impersonate_banner.php';
require_once __DIR__ . '/../includes/sidebar.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
try {
    $db->exec("SET NAMES 'utf8mb4'");
    $db->exec("SET CHARACTER SET utf8mb4");
    $db->exec("SET character_set_connection = utf8mb4");
} catch (PDOException $e) {
}

$userId = (int)$_SESSION['user_id'];
$page = 'chat';

// ⭐ اگر از طریق نوتیف مرورگر وارد شده باشد (chat.php?chat=USER_ID)
$autoOpenChatId = (int)($_GET['chat'] ?? 0);

try {
    $db->prepare("UPDATE users SET last_seen=? WHERE id=?")->execute([time(), $userId]);
} catch (PDOException $e) {
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS colleague_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        attachment VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        attachment_size INT UNSIGNED DEFAULT NULL,
        attachment_type VARCHAR(50) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at TIMESTAMP NULL DEFAULT NULL,
        is_edited TINYINT(1) NOT NULL DEFAULT 0,
        edited_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY sender_id (sender_id), KEY receiver_id (receiver_id),
        KEY conversation (sender_id, receiver_id), KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;");
} catch (PDOException $e) {
}

// ⭐ لیست چت: همکاران + کاربرانی که مکالمه قبلی با آن‌ها وجود دارد (برای ربات تسکام)
$chatListStmt = $db->prepare("
    SELECT DISTINCT u.id AS user_id, u.first_name, u.last_name, u.mobile, u.avatar,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=? AND cb.blocked_user_id=u.id) AS is_blocked_by_me,
        (SELECT COUNT(*) FROM colleague_blocks cb WHERE cb.blocker_user_id=u.id AND cb.blocked_user_id=?) AS has_blocked_me,
        (SELECT COUNT(*) FROM colleague_messages cm WHERE cm.sender_id=u.id AND cm.receiver_id=? AND cm.is_read=0) AS unread_count,
        (SELECT MAX(cm.created_at) FROM colleague_messages cm WHERE (cm.sender_id=u.id AND cm.receiver_id=?) OR (cm.sender_id=? AND cm.receiver_id=u.id)) AS last_msg_time
    FROM users u
    WHERE u.id != ? AND u.status = 'active'
      AND (
        EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=? AND c.colleague_user_id=u.id)
        OR EXISTS (SELECT 1 FROM colleagues c WHERE c.user_id=u.id AND c.colleague_user_id=?)
        OR EXISTS (SELECT 1 FROM colleague_messages cm2 WHERE (cm2.sender_id=u.id AND cm2.receiver_id=?) OR (cm2.sender_id=? AND cm2.receiver_id=u.id))
      )
    ORDER BY (last_msg_time IS NULL) ASC, last_msg_time DESC, u.first_name ASC");
$chatListStmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$chatList = $chatListStmt->fetchAll(PDO::FETCH_ASSOC);

$makeAvatarUrl = function ($avatarFile) {
    if (!empty($avatarFile) && file_exists(__DIR__ . '/../uploads/avatars/' . $avatarFile)) return '../uploads/avatars/' . $avatarFile;
    return null;
};

$savedLastStmt = $db->prepare("SELECT id, message, attachment, attachment_name, attachment_type, attachment_size, created_at FROM colleague_messages WHERE sender_id=? AND receiver_id=? ORDER BY id DESC LIMIT 1");
$savedLastStmt->execute([$userId, $userId]);
$savedLastMsg = $savedLastStmt->fetch(PDO::FETCH_ASSOC);

$savedCountStmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE sender_id=? AND receiver_id=?");
$savedCountStmt->execute([$userId, $userId]);
$savedTotalCount = (int)$savedCountStmt->fetchColumn();

$savedItem = [
    'user_id' => $userId,
    'first_name' => 'پیام های ذخیره شده',
    'last_name' => '',
    'mobile' => '',
    'avatar' => null,
    'is_blocked_by_me' => false,
    'has_blocked_me' => false,
    'unread_count' => 0,
    'last_msg_time' => $savedLastMsg['created_at'] ?? null,
    'avatar_url' => null,
    'full_name' => 'پیام های ذخیره شده',
    'initial' => '📌',
    'is_saved' => true,
    'total_count' => $savedTotalCount,
];
array_unshift($chatList, $savedItem);

foreach ($chatList as &$cl) {
    $cl['user_id'] = (int)$cl['user_id'];
    $cl['is_blocked_by_me'] = (int)($cl['is_blocked_by_me'] ?? 0) === 1;
    $cl['has_blocked_me'] = (int)($cl['has_blocked_me'] ?? 0) === 1;
    $cl['unread_count'] = (int)($cl['unread_count'] ?? 0);
    $cl['avatar_url'] = $makeAvatarUrl($cl['avatar'] ?? null);
    $cl['is_saved'] = !empty($cl['is_saved']);
    $cl['total_count'] = (int)($cl['total_count'] ?? 0);
    $fullName = trim(($cl['first_name'] ?? '') . ' ' . ($cl['last_name'] ?? ''));
    if ($fullName === '') $fullName = $cl['mobile'] ?? '';
    $cl['full_name'] = $fullName;
    if ($cl['is_saved']) $cl['initial'] = '📌';
    else $cl['initial'] = mb_substr(trim($cl['first_name'] ?: $cl['mobile']), 0, 1, 'UTF-8');
}
unset($cl);

$totalUnreadStmt = $db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id=? AND is_read=0 AND sender_id != receiver_id");
$totalUnreadStmt->execute([$userId]);
$totalUnread = (int)$totalUnreadStmt->fetchColumn();
$sidebarUnread = $totalUnread;

$displayUser = $_SESSION['user_mobile'] ?? $_SESSION['mobile'] ?? $_SESSION['username'] ?? 'کاربر';

$chatListJson = json_encode(array_map(function ($cl) {
    return ['user_id' => (int)$cl['user_id'], 'name' => $cl['full_name'], 'initial' => $cl['initial'], 'avatar_url' => $cl['avatar_url'], 'is_saved' => !empty($cl['is_saved'])];
}, $chatList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>گفتگو با همکاران</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://img.icons8.com/color/48/dashboard-layout.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>

    </style>
</head>

<body>

    <?php sidebar(); ?>

    <div class="main-wrapper">
        <div class="topbar">
            <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="باز کردن منو">
                <div class="hamburger-lines"><span></span><span></span><span></span></div>
            </button>
            <h1><i class="fas fa-comments" style="color: var(--accent);"></i> گفتگو با همکاران</h1>
            <div class="topbar-user" style="font-size: 0.9rem; font-weight: bold;">کاربر: <?= htmlspecialchars($displayUser) ?></div>
        </div>

        <div class="content-area">
            <div class="chat-container" id="chatContainer">

                <div class="chat-sidebar">
                    <div class="chat-sidebar__header">
                        <div class="chat-sidebar__title"><i class="fas fa-inbox" style="color: var(--accent);"></i> صندوق پیام‌ها</div>
                        <div class="chat-sidebar__subtitle"><?= count($chatList) ?> مکالمه</div>
                    </div>
                    <div class="chat-sidebar__search">
                        <input type="text" id="chatSearchInput" placeholder="🔍 جستجو در همکاران...">
                    </div>
                    <div class="chat-list" id="chatListContainer">
                        <?php if (empty($chatList)): ?>
                            <div class="empty-state" style="text-align:center;padding:30px 20px;color:#94a3b8;">
                                <i class="fas fa-comment-slash" style="font-size:2.5rem;opacity:0.5;display:block;margin-bottom:12px;"></i>
                                <div style="font-size:0.82rem;">هنوز همکاری ندارید</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($chatList as $cl): ?>
                                <?php
                                $isBlocked = $cl['is_blocked_by_me'] || $cl['has_blocked_me'];
                                $isSaved = !empty($cl['is_saved']);
                                $isBot = ($cl['mobile'] ?? '') === '00000000000';
                                ?>
                                <div class="chat-item <?= $isBlocked ? 'is-blocked' : '' ?> <?= $isSaved ? 'is-saved' : '' ?>"
                                    data-user-id="<?= (int)$cl['user_id'] ?>"
                                    data-name="<?= htmlspecialchars($cl['full_name'], ENT_QUOTES) ?>"
                                    data-initial="<?= htmlspecialchars($cl['initial'], ENT_QUOTES) ?>"
                                    data-avatar-url="<?= htmlspecialchars($cl['avatar_url'] ?? '', ENT_QUOTES) ?>"
                                    data-is-saved="<?= $isSaved ? '1' : '0' ?>"
                                    data-is-bot="<?= $isBot ? '1' : '0' ?>"
                                    onclick="openChat(<?= (int)$cl['user_id'] ?>, this)">
                                    <div class="chat-item__avatar <?= $isSaved ? 'is-saved-avatar' : '' ?> <?= $isBot ? 'is-bot-avatar' : '' ?>" data-avatar-for="<?= (int)$cl['user_id'] ?>">
                                        <?php if ($isSaved): ?><i class="fas fa-bookmark"></i>
                                        <?php elseif ($isBot): ?><i class="fas fa-robot"></i>
                                        <?php elseif ($cl['avatar_url']): ?><img src="<?= htmlspecialchars($cl['avatar_url']) ?>" alt="">
                                            <?php else: ?><?= htmlspecialchars($cl['initial']) ?><?php endif; ?>
                                    </div>
                                    <div class="chat-item__info">
                                        <div class="chat-item__name">
                                            <?= htmlspecialchars($cl['full_name']) ?>
                                            <?php if ($isSaved && $cl['total_count'] > 0): ?>
                                                <span class="saved-count-badge"><?= $cl['total_count'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="chat-item__last" data-status-for="<?= (int)$cl['user_id'] ?>">
                                            <?php if ($isSaved):
                                                if (!empty($savedLastMsg)):
                                                    $previewText = !empty($savedLastMsg['message']) ? mb_substr($savedLastMsg['message'], 0, 40) : (!empty($savedLastMsg['attachment_name']) ? '📎 ' . mb_substr($savedLastMsg['attachment_name'], 0, 35) : 'فایل');
                                                    echo htmlspecialchars($previewText);
                                                else: ?>یادداشت‌ها و فایل‌های شخصی شما
                                            <?php endif;
                                            elseif ($isBot): ?>اطلاع‌رسانی خودکار وظایف
                                            <?php elseif ($cl['has_blocked_me']): ?>🚫 شما را مسدود کرده
                                            <?php elseif ($cl['is_blocked_by_me']): ?>🚫 مسدود توسط شما
                                            <?php else: ?><?= htmlspecialchars($cl['mobile']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!$isSaved && $cl['unread_count'] > 0): ?>
                                        <div class="chat-item__badge" data-user-id="<?= (int)$cl['user_id'] ?>"><?= $cl['unread_count'] ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chat-window">
                    <div class="chat-empty" id="chatEmptyState">
                        <i class="fas fa-comments"></i>
                        <h3>یک گفتگو را انتخاب کنید</h3>
                        <p>از لیست سمت راست، یکی از همکاران خود را انتخاب کنید تا گفتگو را شروع کنید.</p>
                    </div>

                    <div id="chatActiveArea" style="display:none;flex-direction:column;height:100%;">
                        <div class="chat-header">
                            <button type="button" class="chat-header__back" onclick="closeChatOnMobile()" aria-label="بازگشت">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                            <div class="chat-header__avatar" id="chatHeaderAvatar">?</div>
                            <div class="chat-header__info">
                                <div class="chat-header__name" id="chatHeaderName">...</div>
                                <div class="chat-header__status" id="chatHeaderStatus">
                                    <i class="fas fa-circle"></i>
                                    <span id="chatHeaderStatusText">در حال بررسی...</span>
                                </div>
                            </div>
                            <div class="chat-header__actions">
                                <button class="chat-header__action" title="جستجو در پیام‌ها" onclick="openSearchModal('conversation')">
                                    <i class="fas fa-search"></i>
                                </button>
                                <button class="chat-header__action" title="اطلاعات" onclick="showToast('اطلاعات مکالمه', 'info')">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <button class="chat-header__action" title="منو" onclick="showToast('گزینه‌های بیشتر', 'info')">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                            </div>
                        </div>

                        <div class="chat-body" id="chatBody"></div>

                        <form class="chat-footer" id="chatForm" onsubmit="sendMessage(event)">
                            <button type="button" class="chat-attach-btn" id="attachBtn" title="پیوست فایل" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <input type="file" id="fileInput" style="display:none;"
                                accept=".png,.jpg,.jpeg,.pdf,.zip,.txt,image/png,image/jpeg,application/pdf,application/zip,text/plain"
                                onchange="handleFileSelect(this)">
                            <div class="chat-footer__input-wrap">
                                <textarea class="chat-footer__input" id="chatInput" placeholder="پیام خود را بنویسید..." rows="1"></textarea>
                            </div>
                            <button type="submit" class="chat-send-btn" id="chatSendBtn" title="ارسال">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                            <div class="upload-progress" id="uploadProgress">
                                <div class="upload-progress__bar" id="uploadProgressBar"></div>
                            </div>
                        </form>

                        <div class="file-preview" id="filePreview">
                            <div class="file-preview__icon" id="filePreviewIcon"><i class="fas fa-file"></i></div>
                            <div class="file-preview__info">
                                <div class="file-preview__name" id="filePreviewName">فایل</div>
                                <div class="file-preview__size" id="filePreviewSize">0 KB</div>
                            </div>
                            <button type="button" class="file-preview__close" onclick="clearFilePreview()" title="حذف">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ SEARCH MODAL ============ -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="if(event.target === this) closeSearchModal()">
        <div class="search-modal" role="dialog" aria-modal="true">
            <div class="search-modal__header">
                <div class="search-modal__title-row">
                    <div class="search-modal__title">
                        <i class="fas fa-search"></i>
                        جستجو در پیام‌ها
                    </div>
                    <button type="button" class="search-modal__close" onclick="closeSearchModal()" aria-label="بستن">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="search-modal__input-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-modal__input" id="searchQueryInput"
                        placeholder="کلمه یا عبارت موردنظر را تایپ کنید..." autocomplete="off">
                </div>
                <div class="search-modal__scope" id="searchScopeRow">
                    <button type="button" class="search-modal__scope-btn active" data-scope="all" onclick="setSearchScope('all')">
                        <i class="fas fa-globe"></i> همه گفتگوها
                    </button>
                    <button type="button" class="search-modal__scope-btn" data-scope="conversation" id="scopeConversationBtn" onclick="setSearchScope('conversation')">
                        <i class="fas fa-comment"></i> این گفتگو
                    </button>
                </div>
            </div>
            <div class="search-modal__body" id="searchResultsBody">
                <div class="search-modal__empty">
                    <i class="fas fa-keyboard"></i>
                    حداقل ۲ کاراکتر تایپ کنید تا جستجو شروع شود...
                </div>
            </div>
        </div>
    </div>

    <!-- ============ LIGHTBOX ============ -->
    <div class="image-lightbox" id="imageLightbox">
        <button type="button" class="image-lightbox__close" onclick="closeLightbox()" title="بستن (Esc)"><i class="fas fa-times"></i></button>
        <div class="image-lightbox__topbar"><i class="fas fa-search-plus"></i><span id="zoomLevel">100%</span></div>
        <div class="image-lightbox__canvas" id="lightboxCanvas" onclick="if(event.target === this) closeLightbox()">
            <img src="" alt="" class="image-lightbox__img" id="lightboxImage" draggable="false">
        </div>
        <div class="image-lightbox__controls" onclick="event.stopPropagation()">
            <button type="button" class="image-lightbox__btn" data-tooltip="کوچک‌نمایی (-)" onclick="zoomOut()"><i class="fas fa-search-minus"></i></button>
            <button type="button" class="image-lightbox__btn" data-tooltip="بازنشانی (0)" onclick="resetZoom()"><i class="fas fa-compress-arrows-alt"></i></button>
            <button type="button" class="image-lightbox__btn" data-tooltip="بزرگ‌نمایی (+)" onclick="zoomIn()"><i class="fas fa-search-plus"></i></button>
            <div class="image-lightbox__divider"></div>
            <button type="button" class="image-lightbox__btn image-lightbox__btn--primary" data-tooltip="دانلود" onclick="downloadLightboxImage()"><i class="fas fa-download"></i></button>
        </div>
    </div>

    <!-- ============ CONTEXT MENU ============ -->
    <div class="msg-context-menu" id="msgContextMenu">
        <button type="button" class="msg-context-menu__item" onclick="handleContextAction('copy', event)"><i class="fas fa-copy"></i><span>کپی متن</span></button>
        <button type="button" class="msg-context-menu__item" id="ctxDownloadBtn" onclick="handleContextAction('download', event)" style="display:none;"><i class="fas fa-download"></i><span>دانلود فایل</span></button>
        <div class="msg-context-menu__divider" id="ctxEditDivider" style="display:none;"></div>
        <button type="button" class="msg-context-menu__item" id="ctxEditBtn" onclick="handleContextAction('edit', event)" style="display:none;"><i class="fas fa-pen"></i><span>ویرایش</span></button>
        <div class="msg-context-menu__divider" id="ctxDeleteDivider" style="display:none;"></div>
        <button type="button" class="msg-context-menu__item msg-context-menu__item--danger" id="ctxDeleteBtn" onclick="handleContextAction('delete', event)" style="display:none;"><i class="fas fa-trash-alt"></i><span>حذف</span></button>
    </div>

    <!-- ============ DELETE MODAL ============ -->
    <div class="chat-modal-overlay" id="deleteModal" onclick="if(event.target === this) closeDeleteModal()">
        <div class="chat-modal">
            <div class="chat-modal__header">
                <div class="chat-modal__icon chat-modal__icon--danger"><i class="fas fa-trash-alt"></i></div>
                <div>
                    <h3 class="chat-modal__title">حذف پیام</h3>
                    <div class="chat-modal__subtitle">این عملیات قابل بازگشت نیست</div>
                </div>
            </div>
            <div class="chat-modal__body">
                <p>آیا از حذف این پیام مطمئن هستید؟</p>
                <p style="color:#dc2626;font-size:0.82rem;">
                    <i class="fas fa-exclamation-triangle" style="margin-left:4px;"></i>
                    <strong>توجه:</strong> این پیام برای <strong>هر دو طرف</strong> حذف خواهد شد.
                </p>
            </div>
            <div class="chat-modal__footer">
                <button class="chat-modal__btn chat-modal__btn--danger" id="confirmDeleteBtn" onclick="confirmDeleteMessage()">
                    <i class="fas fa-trash-alt"></i> بله، حذف کن
                </button>
                <button class="chat-modal__btn chat-modal__btn--ghost" onclick="closeDeleteModal()">انصراف</button>
            </div>
        </div>
    </div>

    <!-- ============ EDIT MODAL ============ -->
    <div class="chat-modal-overlay" id="editModal" onclick="if(event.target === this) closeEditModal()">
        <div class="chat-modal">
            <div class="chat-modal__header">
                <div class="chat-modal__icon chat-modal__icon--info"><i class="fas fa-pen"></i></div>
                <div>
                    <h3 class="chat-modal__title">ویرایش پیام</h3>
                    <div class="chat-modal__subtitle">ویرایش تا ۱ ساعت پس از ارسال امکان‌پذیر است</div>
                </div>
            </div>
            <div class="chat-modal__body">
                <textarea class="chat-modal__textarea" id="editMessageTextarea" placeholder="متن پیام..."></textarea>
            </div>
            <div class="chat-modal__footer">
                <button class="chat-modal__btn chat-modal__btn--primary" id="confirmEditBtn" onclick="confirmEditMessage()"><i class="fas fa-check"></i> ذخیره تغییرات</button>
                <button class="chat-modal__btn chat-modal__btn--ghost" onclick="closeEditModal()">انصراف</button>
            </div>
        </div>
    </div>

    <div class="chat-toast" id="chatToast">
        <i class="fas fa-check-circle"></i>
        <span id="chatToastText"></span>
    </div>

    <script>
        const CURRENT_USER_ID = <?= (int)$userId ?>;
        const CHAT_LIST = <?= $chatListJson ?>;
        const AUTO_OPEN_CHAT_ID = <?= (int)$autoOpenChatId ?>;
    </script>
    <script src="app.js"></script>
</body>

</html>