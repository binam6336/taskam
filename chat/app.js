/* ======================================================
   CHAT — app.js (Scroll-Optimized)
   ====================================================== */

const MAX_FILE_SIZE = 50 * 1024 * 1024;
const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf', 'zip', 'txt'];
const FILE_BASE_URL = '../uploads/chat_files/';
const PAGE_SIZE = 30;

const FILE_ICONS = { 'png': 'fa-file-image', 'jpg': 'fa-file-image', 'jpeg': 'fa-file-image', 'pdf': 'fa-file-pdf', 'zip': 'fa-file-archive', 'txt': 'fa-file-alt' };

let selectedFile = null;
let currentChatUserId = null;
let currentIsSaved = false;
let currentIsBot = false;
let pollTimer = null;
let statusTimer = null;
let statusRequestInFlight = false;
let sendingMessage = false;
let lastRenderedDay = null;
let loadedMessageIds = new Set();
let firstLoadedMsgId = 0;
let lastLoadedMsgId = 0;
let hasMoreBefore = false;
let hasMoreAfter = false;
let loadingOlder = false;
let isAtBottom = true;
let pendingHighlightMsgId = null;

/* ⚡ Performance flags */
let _fetchInFlight = false;
let _initialLoadAC = null;
let _scrollRaf = 0;
let _tabVisible = !document.hidden;

/* ⚡ Scroll state — برای غیرفعال کردن انیمیشن‌ها هنگام اسکرول */
let _isScrolling = false;
let _scrollEndTimer = null;
let _pendingMessages = null; // پیام‌هایی که در حین اسکرول رسیدن و باید بعد از توقف اضافه شن

/* ⚡ Cache NodeList — جلوگیری از querySelectorAll تکراری */
let _chatItemsCache = null;
let _chatItemsCacheTime = 0;
function getChatItems() {
    const now = Date.now();
    if (!_chatItemsCache || now - _chatItemsCacheTime > 3000) {
        _chatItemsCache = document.querySelectorAll('.chat-item');
        _chatItemsCacheTime = now;
    }
    return _chatItemsCache;
}
function invalidateChatItemsCache() {
    _chatItemsCache = null;
    _chatItemsCacheTime = 0;
}

/* ⚡ IntersectionObserver برای tracking چت‌های دیده‌شده */
const _visibleChatIds = new Set();
let _chatItemObserver = null;
function initChatItemObserver() {
    if (_chatItemObserver) _chatItemObserver.disconnect();
    const listEl = document.getElementById('chatListContainer');
    if (!listEl) return;

    _chatItemObserver = new IntersectionObserver((entries) => {
        for (let i = 0; i < entries.length; i++) {
            const entry = entries[i];
            const uid = entry.target.getAttribute('data-user-id');
            if (!uid) continue;
            if (entry.isIntersecting) _visibleChatIds.add(uid);
            else _visibleChatIds.delete(uid);
        }
    }, { root: listEl, rootMargin: '80px' });

    const items = listEl.querySelectorAll('.chat-item');
    for (let i = 0; i < items.length; i++) _chatItemObserver.observe(items[i]);
}

// Lightbox
let lightboxZoom = 1;
const ZOOM_MIN = 0.25, ZOOM_MAX = 5, ZOOM_STEP = 0.25;
let lightboxCurrentUrl = '', lightboxCurrentName = '';
let lightboxPanX = 0, lightboxPanY = 0;
let isDragging = false, dragStartX = 0, dragStartY = 0;

const EDIT_DELETE_WINDOW_MS = 60 * 60 * 1000;
let contextMenuMsgData = null;
let pendingDeleteMsgId = null;
let editingMsgId = null;

// Search
let searchScope = 'all';
let searchTimer = null;
let searchAbortController = null;
let lastSearchResults = [];
let searchInputTouched = false;

/* ⚡ Cache DOM refs */
const $cache = Object.create(null);
function $id(id) {
    return $cache[id] || ($cache[id] = document.getElementById(id));
}

/* ⚡ Cache escapeHtml */
const _escapeCache = new Map();
const _ESC_MAX = 500;

/* ⚡ Cache formatDayLabel */
const _dayLabelCache = new Map();

/* ⚡ rIC helper */
function whenIdle(fn) {
    if ('requestIdleCallback' in window) {
        requestIdleCallback(fn, { timeout: 2000 });
    } else {
        setTimeout(fn, 1);
    }
}

/* ======================================================
   TIME HELPERS
   ====================================================== */
function parseServerTime(s) {
    if (!s) return 0;
    const ts = Date.parse(String(s).replace(' ', 'T'));
    return isNaN(ts) ? 0 : ts;
}

/* ======================================================
   MESSAGE PERMISSIONS & CONTEXT MENU
   ====================================================== */
function checkMessagePermissions(el) {
    const senderId = parseInt(el.getAttribute('data-sender-id'), 10);
    const createdMs = parseInt(el.getAttribute('data-created-ms'), 10);
    const currentUserId = parseInt(CURRENT_USER_ID, 10);
    const isMine = !isNaN(senderId) && !isNaN(currentUserId) && senderId === currentUserId;
    const now = Date.now();
    const elapsed = now - createdMs;
    const isWithinWindow = !isNaN(createdMs) && createdMs > 0 && elapsed >= 0 && elapsed <= EDIT_DELETE_WINDOW_MS;
    return {
        isMine, createdMs, elapsed, isWithinWindow,
        hasFile: !!el.querySelector('.chat-file-card, .chat-image-wrapper'),
        hasText: (() => {
            const divs = el.children;
            for (let i = 0; i < divs.length; i++) {
                const c = divs[i];
                if (c.classList.contains('chat-bubble__time')) continue;
                if (c.classList.contains('chat-bubble__attachment')) continue;
                if (c.classList.contains('task-notif-header')) continue;
                if (c.classList.contains('task-notif-actions')) continue;
                if (c.tagName === 'DIV' && (c.innerText || c.textContent || '').trim()) return true;
            }
            return false;
        })(),
    };
}

function extractMessageDataFromBubble(el) {
    const msgId = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
    const senderId = parseInt(el.getAttribute('data-sender-id'), 10) || 0;
    const createdMs = parseInt(el.getAttribute('data-created-ms'), 10) || 0;
    let messageText = '';
    const divs = el.children;
    for (let i = 0; i < divs.length; i++) {
        const c = divs[i];
        if (c.classList.contains('chat-bubble__time')) continue;
        if (c.classList.contains('chat-bubble__attachment')) continue;
        if (c.classList.contains('task-notif-header')) continue;
        if (c.classList.contains('task-notif-actions')) continue;
        if (c.tagName === 'DIV') { messageText = c.innerText || c.textContent || ''; break; }
    }
    let attachmentUrl = null, attachmentName = null;
    const fileCard = el.querySelector('.chat-file-card');
    const imgWrap = el.querySelector('.chat-image-wrapper');
    if (fileCard) {
        attachmentUrl = fileCard.getAttribute('href');
        const nameEl = fileCard.querySelector('.chat-file-card__name');
        attachmentName = nameEl ? nameEl.textContent.trim() : 'file';
    } else if (imgWrap) {
        const img = imgWrap.querySelector('img');
        if (img) { attachmentUrl = img.getAttribute('src'); attachmentName = img.getAttribute('alt') || 'image'; }
    }
    return { id: msgId, sender_id: senderId, message: messageText, attachment: attachmentUrl, attachment_name: attachmentName, createdAtMs: createdMs };
}

function openContextMenu(e, bubbleEl) {
    e.preventDefault(); e.stopPropagation();
    const msgId = parseInt(bubbleEl.getAttribute('data-msg-id'), 10);
    if (!msgId) return;
    const perm = checkMessagePermissions(bubbleEl);
    contextMenuMsgData = extractMessageDataFromBubble(bubbleEl);
    document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
    bubbleEl.classList.add('context-active');
    const menu = $id('msgContextMenu');
    $id('ctxDownloadBtn').style.display = perm.hasFile ? 'flex' : 'none';
    const showEdit = perm.isMine && perm.hasText && !perm.hasFile && perm.isWithinWindow;
    $id('ctxEditBtn').style.display = showEdit ? 'flex' : 'none';
    $id('ctxEditDivider').style.display = showEdit ? 'block' : 'none';
    const showDelete = perm.isMine && perm.isWithinWindow;
    $id('ctxDeleteBtn').style.display = showDelete ? 'flex' : 'none';
    $id('ctxDeleteDivider').style.display = showDelete ? 'block' : 'none';
    if (!perm.hasText && !perm.hasFile && !showEdit && !showDelete) {
        bubbleEl.classList.remove('context-active'); return;
    }
    menu.style.left = '0px'; menu.style.top = '0px';
    menu.classList.add('active');
    requestAnimationFrame(() => {
        const r = menu.getBoundingClientRect();
        let x = e.clientX, y = e.clientY;
        if (x + r.width > window.innerWidth - 10) x = window.innerWidth - r.width - 10;
        if (x < 10) x = 10;
        if (y + r.height > window.innerHeight - 10) y = window.innerHeight - r.height - 10;
        if (y < 10) y = 10;
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    });
}

function closeContextMenu() {
    const m = $id('msgContextMenu');
    if (m && m.classList.contains('active')) m.classList.remove('active');
    document.querySelectorAll('.chat-bubble.context-active').forEach(el => el.classList.remove('context-active'));
    contextMenuMsgData = null;
}

function handleContextAction(action, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    if (!contextMenuMsgData) return;
    const data = { ...contextMenuMsgData };
    closeContextMenu();
    switch (action) {
        case 'copy': copyMessageText(data.message); break;
        case 'download': downloadAttachment(data.attachment, data.attachment_name); break;
        case 'edit': openEditModal(data); break;
        case 'delete': openDeleteModal(data.id); break;
    }
}

function copyMessageText(text) {
    if (!text || !text.trim()) { showToast('متنی برای کپی وجود ندارد', 'error'); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => showToast('متن پیام کپی شد', 'success')).catch(() => fallbackCopy(text));
    } else fallbackCopy(text);
}
function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); showToast('متن پیام کپی شد', 'success'); }
    catch (e) { showToast('کپی نشد', 'error'); }
    document.body.removeChild(ta);
}

function downloadAttachment(url, name) {
    if (!url) { showToast('فایلی برای دانلود وجود ندارد', 'error'); return; }
    showToast('در حال آماده‌سازی دانلود...', 'info');
    fetch(url).then(r => { if (!r.ok) throw 0; return r.blob(); })
        .then(blob => {
            const bUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = bUrl; a.download = name || 'file';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(bUrl), 1000);
            showToast('دانلود شروع شد', 'success');
        })
        .catch(() => { window.open(url, '_blank'); showToast('فایل در تب جدید باز شد', 'info'); });
}

function openDeleteModal(msgId) {
    pendingDeleteMsgId = msgId;
    $id('deleteModal').classList.add('active');
    const btn = $id('confirmDeleteBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
}
function closeDeleteModal() {
    $id('deleteModal').classList.remove('active');
    pendingDeleteMsgId = null;
}

function confirmDeleteMessage() {
    if (!pendingDeleteMsgId) return;
    const btn = $id('confirmDeleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...';
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('message_id', pendingDeleteMsgId);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const b = document.querySelector(`.chat-bubble[data-msg-id="${pendingDeleteMsgId}"]`);
                if (b) {
                    b.style.transition = 'opacity 0.3s ease';
                    b.style.opacity = '0';
                    setTimeout(() => b.remove(), 300);
                }
                loadedMessageIds.delete(Number(pendingDeleteMsgId));
                closeDeleteModal();
                showToast('پیام برای هر دو طرف حذف شد', 'success');
                refreshSavedBadge();
            } else {
                showToast(data.message || 'خطا در حذف پیام', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
            }
        })
        .catch(() => {
            showToast('خطا در ارتباط با سرور', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> بله، حذف کن';
        });
}

function openEditModal(data) {
    editingMsgId = data.id;
    $id('editMessageTextarea').value = data.message || '';
    $id('editModal').classList.add('active');
    setTimeout(() => {
        const ta = $id('editMessageTextarea');
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }, 200);
    const btn = $id('confirmEditBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
}
function closeEditModal() {
    $id('editModal').classList.remove('active');
    editingMsgId = null;
}

function confirmEditMessage() {
    if (!editingMsgId) return;
    const newText = $id('editMessageTextarea').value.trim();
    if (!newText) { showToast('متن پیام نمی‌تواند خالی باشد', 'error'); return; }
    const btn = $id('confirmEditBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ذخیره...';
    const fd = new FormData();
    fd.append('action', 'edit');
    fd.append('message_id', editingMsgId);
    fd.append('message', newText);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                updateBubbleText(editingMsgId, newText);
                closeEditModal();
                showToast('پیام ویرایش شد', 'success');
            } else {
                showToast(data.message || 'خطا در ویرایش', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
            }
        })
        .catch(() => {
            showToast('خطا در ارتباط با سرور', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
        });
}

function updateBubbleText(msgId, newText) {
    const bubble = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!bubble) return;
    const divs = bubble.children;
    for (let i = 0; i < divs.length; i++) {
        const c = divs[i];
        if (c.classList.contains('chat-bubble__time')) continue;
        if (c.classList.contains('chat-bubble__attachment')) continue;
        if (c.classList.contains('task-notif-header')) continue;
        if (c.classList.contains('task-notif-actions')) continue;
        if (c.tagName === 'DIV') { c.innerHTML = escapeHtml(newText).replace(/\n/g, '<br>'); break; }
    }
    const timeEl = bubble.querySelector('.chat-bubble__time');
    if (timeEl && !bubble.querySelector('.chat-bubble__edited')) {
        const sp = document.createElement('span');
        sp.className = 'chat-bubble__edited';
        sp.textContent = '(ویرایش‌شده)';
        timeEl.insertBefore(sp, timeEl.firstChild);
    }
    bubble.setAttribute('data-edited', '1');
}

let toastTimer = null;
function showToast(text, type = 'success') {
    const toast = $id('chatToast');
    $id('chatToastText').textContent = text;
    const icon = toast.querySelector('i');
    icon.className = 'fas';
    toast.classList.remove('chat-toast--success', 'chat-toast--error', 'chat-toast--info');
    if (type === 'success') { icon.classList.add('fa-check-circle'); toast.classList.add('chat-toast--success'); }
    else if (type === 'error') { icon.classList.add('fa-exclamation-circle'); toast.classList.add('chat-toast--error'); }
    else { icon.classList.add('fa-info-circle'); toast.classList.add('chat-toast--info'); }
    toast.classList.add('active');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('active'), 2400);
}

/* ======================================================
   OPEN CHAT
   ====================================================== */
function openChat(userId, element) {
    if (currentChatUserId === userId && !pendingHighlightMsgId) return;

    if (_initialLoadAC) { try { _initialLoadAC.abort(); } catch (e) { } _initialLoadAC = null; }

    if (window.ChatNotifications) window.ChatNotifications.setActiveChat(userId);

    const isSaved = element && element.getAttribute('data-is-saved') === '1';
    const isBot = element && element.getAttribute('data-is-bot') === '1';
    currentChatUserId = userId;
    currentIsSaved = isSaved;
    currentIsBot = isBot;
    lastRenderedDay = null;
    loadedMessageIds = new Set();
    firstLoadedMsgId = 0;
    lastLoadedMsgId = 0;
    hasMoreBefore = false;
    hasMoreAfter = false;
    loadingOlder = false;
    _fetchInFlight = false;
    _pendingMessages = null;

    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    if (element) element.classList.add('active');

    let person = element ? {
        name: element.getAttribute('data-name') || '',
        initial: element.getAttribute('data-initial') || '?',
        avatar_url: element.getAttribute('data-avatar-url') || null,
    } : (CHAT_LIST.find(c => Number(c.user_id) === Number(userId)) || { name: '', initial: '?', avatar_url: null });
    if (!element) currentIsSaved = !!person.is_saved;

    $id('chatHeaderName').textContent = person.name;
    const headerAvatar = $id('chatHeaderAvatar');
    const headerStatus = $id('chatHeaderStatus');
    const headerStatusText = $id('chatHeaderStatusText');
    const chatInputEl = $id('chatInput');

    headerAvatar.classList.remove('is-saved-avatar', 'is-bot-avatar', 'online', 'offline');

    if (currentIsSaved) {
        headerAvatar.innerHTML = '<i class="fas fa-bookmark"></i>';
        headerAvatar.classList.add('is-saved-avatar');
        headerStatus.className = 'chat-header__status saved';
        headerStatusText.textContent = 'ذخیره‌سازی شخصی';
        chatInputEl.placeholder = 'یادداشت، لینک یا فایل خود را اینجا بنویسید...';
    } else if (currentIsBot) {
        headerAvatar.innerHTML = '<i class="fas fa-robot"></i>';
        headerAvatar.classList.add('is-bot-avatar');
        headerStatus.className = 'chat-header__status bot';
        headerStatusText.textContent = 'اطلاع‌رسانی خودکار وظایف';
        chatInputEl.placeholder = 'این حساب فقط اطلاع‌رسانی می‌کند';
    } else {
        if (person.avatar_url) headerAvatar.innerHTML = `<img src="${escapeHtml(person.avatar_url)}" alt="${escapeHtml(person.name)}">`;
        else headerAvatar.textContent = person.initial || '?';
        chatInputEl.placeholder = 'پیام خود را بنویسید...';
    }

    $id('chatEmptyState').style.display = 'none';
    $id('chatActiveArea').style.display = 'flex';
    $id('chatBody').innerHTML = '';

    const container = $id('chatContainer');
    if (container) container.classList.add('chat-open');

    const badge = document.querySelector(`.chat-item__badge[data-user-id="${userId}"]`);
    if (badge) badge.remove();

    if (pendingHighlightMsgId) {
        loadMessagesAround(pendingHighlightMsgId);
        pendingHighlightMsgId = null;
    } else {
        loadInitialMessages();
    }

    setTimeout(() => chatInputEl.focus(), 200);

    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(pollNewMessages, 3000);

    if (statusTimer) clearInterval(statusTimer);
    if (!currentIsSaved && !currentIsBot) {
        fetchUserStatus();
        statusTimer = setInterval(fetchUserStatus, 5000);
    }
}

function closeChatOnMobile() {
    if (window.ChatNotifications) window.ChatNotifications.clearActiveChat();

    const c = $id('chatContainer');
    if (c) c.classList.remove('chat-open');
    if (_initialLoadAC) { try { _initialLoadAC.abort(); } catch (e) { } _initialLoadAC = null; }
    currentChatUserId = null;
    currentIsSaved = false;
    currentIsBot = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (statusTimer) { clearInterval(statusTimer); statusTimer = null; }
    _pendingMessages = null;
    clearFilePreview();
    closeContextMenu();
}

/* ======================================================
   ⚡ CHUNKED RENDERING — جلوگیری از freeze اولیه
   ====================================================== */
function renderMessagesChunked(body, messages, onComplete) {
    const CHUNK_SIZE = 20;
    let index = 0;
    let lastDay = null;

    function renderChunk() {
        const frag = document.createDocumentFragment();
        const end = Math.min(index + CHUNK_SIZE, messages.length);
        for (; index < end; index++) {
            const msg = messages[index];
            const createdMs = parseServerTime(msg.created_at);
            const dateObj = createdMs ? new Date(createdMs) : new Date();
            const dayLabel = formatDayLabel(dateObj);
            if (lastDay !== dayLabel) {
                const d = document.createElement('div');
                d.className = 'chat-day';
                d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
                frag.appendChild(d);
                lastDay = dayLabel;
            }
            frag.appendChild(createMessageElement(msg, true));
            loadedMessageIds.add(Number(msg.id));
        }
        body.appendChild(frag);
        lastRenderedDay = lastDay;

        if (index < messages.length) {
            requestAnimationFrame(renderChunk);
        } else if (onComplete) {
            onComplete();
        }
    }
    renderChunk();
}

/* ======================================================
   LAZY LOADING
   ====================================================== */
function loadInitialMessages() {
    if (!currentChatUserId) return;
    const body = $id('chatBody');
    body.innerHTML = '<div class="chat-load-more loading" id="loadMoreEl"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

    _initialLoadAC = new AbortController();

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=initial&limit=${PAGE_SIZE}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: _initialLoadAC.signal
    })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') { showToast('خطا در دریافت پیام‌ها', 'error'); return; }
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;
            hasMoreAfter = !!data.has_more_after;

            body.innerHTML = '';
            lastRenderedDay = null;

            if (messages.length === 0) {
                if (currentIsSaved) {
                    body.innerHTML = `<div class="chat-empty"><i class="fas fa-bookmark" style="color:#fbbf24;opacity:0.6;"></i><h3>پیام‌های ذخیره شده</h3><p>اینجا فضای شخصی شماست. می‌توانید یادداشت‌ها، لینک‌ها و فایل‌های خود را ذخیره کنید.</p></div>`;
                } else if (currentIsBot) {
                    body.innerHTML = `<div class="chat-empty"><i class="fas fa-robot" style="color:#a78bfa;opacity:0.6;"></i><h3>اطلاع‌رسانی تسکام</h3><p>هر زمان شما در وظیفه‌ای منشن شوید، اطلاع‌رسانی آن در اینجا نمایش داده می‌شود.</p></div>`;
                } else {
                    body.innerHTML = '<div class="chat-day"><span>هنوز پیامی رد و بدل نشده</span></div>';
                }
                return;
            }

            const loader = document.createElement('div');
            loader.className = 'chat-load-more';
            loader.id = 'loadMoreEl';
            loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
            body.appendChild(loader);

            // ⚡ رندر چانکی — صفحه اول قفل نمی‌شود
            renderMessagesChunked(body, messages, () => {
                if (messages.length > 0) {
                    firstLoadedMsgId = Number(messages[0].id);
                    lastLoadedMsgId = Number(messages[messages.length - 1].id);
                }
                updateLoadMoreUI();
                requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
            });
        })
        .catch(err => { if (err.name !== 'AbortError') showToast('خطا در ارتباط با سرور', 'error'); })
        .finally(() => { _initialLoadAC = null; });
}

function loadOlderMessages() {
    if (!currentChatUserId || loadingOlder || !hasMoreBefore || firstLoadedMsgId <= 0) return;
    loadingOlder = true;

    const body = $id('chatBody');
    const loader = $id('loadMoreEl');
    if (loader) loader.classList.add('loading');

    const prevScrollHeight = body.scrollHeight;
    const prevScrollTop = body.scrollTop;

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=older&before_id=${firstLoadedMsgId}&limit=${PAGE_SIZE}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;

            if (messages.length === 0) {
                updateLoadMoreUI();
                return;
            }

            firstLoadedMsgId = Number(messages[0].id);

            const frag = buildMessagesFragment(messages, true);
            messages.forEach(m => loadedMessageIds.add(Number(m.id)));

            const loaderEl = $id('loadMoreEl');
            if (loaderEl) {
                loaderEl.insertAdjacentElement('afterend', frag);
            } else {
                body.insertBefore(frag, body.firstChild);
            }

            // ⚡ حفظ موقعیت اسکرول بدون layout thrashing
            requestAnimationFrame(() => {
                const newScrollHeight = body.scrollHeight;
                body.scrollTop = prevScrollTop + (newScrollHeight - prevScrollHeight);
            });

            updateLoadMoreUI();
        })
        .catch(() => { })
        .finally(() => { loadingOlder = false; });
}

function loadMessagesAround(targetId) {
    const body = $id('chatBody');
    body.innerHTML = '<div class="chat-load-more loading"><span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span></div>';

    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=around&target_id=${targetId}&limit=${PAGE_SIZE}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            hasMoreBefore = !!data.has_more_before;
            hasMoreAfter = !!data.has_more_after;

            body.innerHTML = '';
            lastRenderedDay = null;

            const loader = document.createElement('div');
            loader.className = 'chat-load-more';
            loader.id = 'loadMoreEl';
            loader.innerHTML = '<span class="chat-load-more__spinner"></span><span class="chat-load-more__text">در حال بارگذاری...</span>';
            body.appendChild(loader);

            renderMessagesChunked(body, messages, () => {
                if (messages.length > 0) {
                    firstLoadedMsgId = Number(messages[0].id);
                    lastLoadedMsgId = Number(messages[messages.length - 1].id);
                }
                updateLoadMoreUI();

                requestAnimationFrame(() => {
                    const target = document.querySelector(`.chat-bubble[data-msg-id="${targetId}"]`);
                    if (target) {
                        target.scrollIntoView({ block: 'center', behavior: 'auto' });
                        target.classList.add('search-highlight');
                        setTimeout(() => target.classList.remove('search-highlight'), 2400);
                    }
                });
            });
        })
        .catch(() => { });
}

/* ======================================================
   ⚡ POLL NEW MESSAGES — با محافظت از اسکرول
   ====================================================== */
function pollNewMessages() {
    if (!currentChatUserId) return;
    if (lastLoadedMsgId <= 0) return;
    if (_fetchInFlight) return;
    if (!_tabVisible) return;

    _fetchInFlight = true;
    fetch(`api.php?action=fetch&user_id=${currentChatUserId}&mode=newer&after_id=${lastLoadedMsgId}&limit=50`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const messages = data.messages || [];
            if (messages.length === 0) return;

            const body = $id('chatBody');
            isAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;

            // ⚡ اگر کاربر در حال اسکروله، پیام‌های جدید را در صف نگه می‌داریم
            // تا بعد از توقف اسکرول، بدون jank اضافه شن
            if (_isScrolling && !messages.some(m => Number(m.sender_id) === CURRENT_USER_ID)) {
                if (!_pendingMessages) _pendingMessages = [];
                _pendingMessages.push(...messages);
                lastLoadedMsgId = Math.max(lastLoadedMsgId, Number(messages[messages.length - 1].id));
                return;
            }

            applyNewMessages(body, messages, isAtBottom);
        })
        .catch(() => { })
        .finally(() => { _fetchInFlight = false; });
}

/* ⚡ اعمال پیام‌های جدید به‌صورت batch */
function applyNewMessages(body, messages, shouldScroll) {
    let lastDay = lastRenderedDay;
    const frag = document.createDocumentFragment();
    let addedAny = false;
    let hasNewFromMe = false;

    for (let i = 0; i < messages.length; i++) {
        const msg = messages[i];
        const msgId = Number(msg.id);
        if (!loadedMessageIds.has(msgId)) {
            const createdMs = parseServerTime(msg.created_at);
            const dateObj = createdMs ? new Date(createdMs) : new Date();
            const dayLabel = formatDayLabel(dateObj);
            if (lastDay !== dayLabel) {
                const d = document.createElement('div');
                d.className = 'chat-day';
                d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
                frag.appendChild(d);
                lastDay = dayLabel;
            }
            // ⚡ بدون انیمیشن اگر در حال اسکرول هستیم
            frag.appendChild(createMessageElement(msg, _isScrolling));
            loadedMessageIds.add(msgId);
            addedAny = true;
            if (Number(msg.sender_id) === CURRENT_USER_ID) hasNewFromMe = true;
        } else {
            updateMessageReadStatus(msgId, Number(msg.is_read));
            updateMessageTextIfEdited(msgId, msg);
        }
    }
    if (addedAny) {
        body.appendChild(frag);
        lastRenderedDay = lastDay;
    }
    lastLoadedMsgId = Math.max(lastLoadedMsgId, Number(messages[messages.length - 1].id));

    if ((shouldScroll || hasNewFromMe) && addedAny) {
        requestAnimationFrame(() => { body.scrollTop = body.scrollHeight; });
    }
}

function updateLoadMoreUI() {
    const loader = $id('loadMoreEl');
    if (!loader) return;
    loader.classList.remove('loading');
    if (!hasMoreBefore) {
        loader.classList.add('no-more');
        loader.querySelector('.chat-load-more__text').textContent = 'ابتدای گفتگو';
    } else {
        loader.querySelector('.chat-load-more__text').textContent = 'برای دیدن پیام‌های قدیمی‌تر اسکرول کنید';
    }
}

function updateMessageTextIfEdited(msgId, msg) {
    const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!b) return;
    if (Number(msg.is_edited) === 1 && msg.message) {
        const divs = b.children;
        let textDiv = null;
        for (let i = 0; i < divs.length; i++) {
            const c = divs[i];
            if (c.classList.contains('chat-bubble__time')) continue;
            if (c.classList.contains('chat-bubble__attachment')) continue;
            if (c.classList.contains('task-notif-header')) continue;
            if (c.classList.contains('task-notif-actions')) continue;
            if (c.tagName === 'DIV') { textDiv = c; break; }
        }
        const cur = textDiv ? (textDiv.innerText || textDiv.textContent || '') : '';
        if (cur !== msg.message) {
            if (textDiv) textDiv.innerHTML = escapeHtml(msg.message).replace(/\n/g, '<br>');
            const timeEl = b.querySelector('.chat-bubble__time');
            if (timeEl && !b.querySelector('.chat-bubble__edited')) {
                const sp = document.createElement('span');
                sp.className = 'chat-bubble__edited';
                sp.textContent = '(ویرایش‌شده)';
                timeEl.insertBefore(sp, timeEl.firstChild);
            }
        }
    }
}

function updateMessageReadStatus(msgId, isRead) {
    const b = document.querySelector(`.chat-bubble[data-msg-id="${msgId}"]`);
    if (!b) return;
    const c = b.querySelector('.chat-bubble__time .check');
    if (!c) return;
    const wasRead = c.classList.contains('read');
    const nowRead = isRead === 1;
    if (wasRead === nowRead) return;
    if (nowRead) { c.classList.remove('fa-check'); c.classList.add('fa-check-double', 'read'); }
    else { c.classList.remove('fa-check-double', 'read'); c.classList.add('fa-check'); }
}

function getFileExtension(n) { return n.split('.').pop().toLowerCase(); }
function getFileIcon(e) { return FILE_ICONS[e] || 'fa-file'; }
function formatFileSize(b) {
    if (!b || b === 0) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, i)).toFixed(1) + ' ' + u[i];
}

function handleFileSelect(input) {
    if (!input.files || !input.files[0]) { clearFilePreview(); return; }
    const f = input.files[0];
    const ext = getFileExtension(f.name);
    if (f.size > MAX_FILE_SIZE) { alert('حجم فایل نباید بیشتر از ۵۰ مگابایت باشد.'); input.value = ''; clearFilePreview(); return; }
    if (!ALLOWED_EXTENSIONS.includes(ext)) { alert('فرمت فایل مجاز نیست.\nفرمت‌های مجاز: PNG, JPG, PDF, ZIP, TXT'); input.value = ''; clearFilePreview(); return; }
    selectedFile = f;
    const preview = $id('filePreview');
    const iconEl = $id('filePreviewIcon');
    const nameEl = $id('filePreviewName');
    const sizeEl = $id('filePreviewSize');
    iconEl.className = 'file-preview__icon';
    if (ext === 'pdf') iconEl.classList.add('type-pdf');
    else if (ext === 'zip') iconEl.classList.add('type-zip');
    else if (ext === 'txt') iconEl.classList.add('type-txt');
    else if (['png', 'jpg', 'jpeg'].includes(ext)) iconEl.classList.add('type-' + (ext === 'jpeg' ? 'jpg' : ext));
    iconEl.innerHTML = `<i class="fas ${getFileIcon(ext)}"></i>`;
    nameEl.textContent = f.name;
    sizeEl.textContent = formatFileSize(f.size);
    preview.classList.add('active');
    setTimeout(() => $id('chatInput').focus(), 100);
}

function clearFilePreview() {
    selectedFile = null;
    $id('fileInput').value = '';
    $id('filePreview').classList.remove('active');
}

function updateUploadProgress(p) {
    const w = $id('uploadProgress');
    const b = $id('uploadProgressBar');
    if (p > 0 && p < 100) { w.classList.add('active'); b.style.width = p + '%'; }
    else if (p >= 100) { b.style.width = '100%'; setTimeout(() => { w.classList.remove('active'); b.style.width = '0%'; }, 300); }
    else { w.classList.remove('active'); b.style.width = '0%'; }
}

/* ⚡ createMessageElement با پارامتر skipAnimation */
function createMessageElement(msg, skipAnimation) {
    const senderIdNum = Number(msg.sender_id);
    const isSent = senderIdNum === CURRENT_USER_ID;
    let cls;
    if (currentIsSaved) cls = 'saved-self';
    else cls = isSent ? 'sent' : 'received';

    const createdMs = parseServerTime(msg.created_at);
    const dateObj = createdMs ? new Date(createdMs) : new Date();

    let checkIcon = '';
    if (isSent && !currentIsSaved) {
        checkIcon = Number(msg.is_read) === 1 ? '<i class="fas fa-check-double check read"></i>' : '<i class="fas fa-check check"></i>';
    } else if (currentIsSaved) {
        checkIcon = '<i class="fas fa-bookmark" style="font-size:0.65rem;color:#d97706;"></i>';
    }

    let contentHtml = '';
    let isTaskNotification = false;
    let taskBtnHtml = '';

    if (msg.message && msg.message.trim() !== '') {
        let rawMsg = msg.message;

        rawMsg = rawMsg.replace(/\[\[task:([a-fA-F0-9]+)\]\]/g, function (m, token) {
            isTaskNotification = true;
            const url = '../tasks/index.php?task=' + encodeURIComponent(token);
            taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> باز کردن وظیفه</a>';
            return '';
        });

        rawMsg = rawMsg.replace(/\[\[call:([a-fA-F0-9]+)\]\]/g, function (m, token) {
            isTaskNotification = true;
            const url = '../call_requests/index.php?call=' + encodeURIComponent(token);
            taskBtnHtml += '<a href="' + url + '" class="chat-task-action-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 12px rgba(245,158,11,0.4);" target="_blank" rel="noopener"><i class="fas fa-phone-volume"></i> باز کردن درخواست تماس</a>';
            return '';
        });

        rawMsg = rawMsg.replace(/\s+$/, '').replace(/\n{3,}/g, '\n\n');

        if (isTaskNotification) {
            let headerLabel = 'اطلاع‌رسانی';
            let headerIcon = 'fa-bell';
            let headerColor = '';

            if (rawMsg.indexOf('📌') === 0 || rawMsg.indexOf('📌 وظیفه جدید') !== -1) {
                headerLabel = 'وظیفه جدید';
                headerIcon = 'fa-plus-circle';
                headerColor = 'style="color:#059669;"';
            } else if (rawMsg.indexOf('📞') === 0 || rawMsg.indexOf('📞 درخواست تماس') !== -1) {
                headerLabel = 'درخواست تماس جدید';
                headerIcon = 'fa-phone-volume';
                headerColor = 'style="color:#d97706;"';
            } else if (rawMsg.indexOf('📝') === 0 || rawMsg.indexOf('📝 یادداشت') !== -1) {
                headerLabel = 'یادداشت جدید';
                headerIcon = 'fa-comment-dots';
                headerColor = 'style="color:#7c3aed;"';
            }

            let headerHtml = '<div class="task-notif-header"><span class="tn-icon" ' + headerColor + '><i class="fas ' + headerIcon + '"></i></span><span>' + headerLabel + '</span></div>';
            let bodyHtml = rawMsg.trim() ? '<div class="task-notif-body">' + escapeHtml(rawMsg.trim()).replace(/\n/g, '<br>') + '</div>' : '';
            let actionsHtml = taskBtnHtml ? '<div class="task-notif-actions">' + taskBtnHtml + '</div>' : '';
            contentHtml = headerHtml + bodyHtml + actionsHtml;
        } else {
            if (rawMsg.trim()) {
                contentHtml += '<div>' + escapeHtml(rawMsg).replace(/\n/g, '<br>') + '</div>';
            }
            if (taskBtnHtml) {
                contentHtml += '<div class="task-notif-actions">' + taskBtnHtml + '</div>';
            }
        }
    }

    if (msg.attachment) {
        const ext = (msg.attachment_type || getFileExtension(msg.attachment)).toLowerCase();
        const fileUrl = FILE_BASE_URL + msg.attachment;
        const fileName = msg.attachment_name || msg.attachment;
        const fileSize = formatFileSize(msg.attachment_size || 0);
        const icon = getFileIcon(ext);
        if (['png', 'jpg', 'jpeg'].includes(ext)) {
            contentHtml += `<div class="chat-bubble__attachment"><div class="chat-image-wrapper" onclick="event.stopPropagation();openLightbox('${escapeHtml(fileUrl)}','${escapeHtml(fileName)}')"><img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName)}" class="chat-image-preview" loading="lazy" decoding="async"><div class="image-zoom-hint"><i class="fas fa-expand"></i></div></div></div>`;
        } else {
            contentHtml += `<div class="chat-bubble__attachment"><a href="${escapeHtml(fileUrl)}" class="chat-file-card" download="${escapeHtml(fileName)}" target="_blank" onclick="event.stopPropagation();"><div class="chat-file-card__icon type-${ext}"><i class="fas ${icon}"></i></div><div class="chat-file-card__info"><div class="chat-file-card__name">${escapeHtml(fileName)}</div><div class="chat-file-card__size"><i class="fas fa-download"></i> ${escapeHtml(fileSize)}</div></div></a></div>`;
        }
    }

    const editedHtml = Number(msg.is_edited) === 1 ? '<span class="chat-bubble__edited">(ویرایش‌شده)</span>' : '';

    const bubble = document.createElement('div');
    let clsExtra = isTaskNotification ? ' task-notification' : '';
    // ⚡ اگر skipAnimation باشد، انیمیشن را حذف می‌کنیم (huge scroll improvement)
    if (skipAnimation) clsExtra += ' no-anim';
    bubble.className = 'chat-bubble ' + cls + clsExtra;
    bubble.setAttribute('data-msg-id', String(msg.id));
    bubble.setAttribute('data-created-ms', String(createdMs || Date.now()));
    bubble.setAttribute('data-sender-id', String(senderIdNum));
    if (Number(msg.is_edited) === 1) bubble.setAttribute('data-edited', '1');

    bubble.innerHTML = `
        ${contentHtml}
        <div class="chat-bubble__time">
            ${editedHtml}
            <span>${formatTime(dateObj)}</span>
            ${checkIcon}
        </div>
    `;
    return bubble;
}

function buildMessagesFragment(messages, skipAnimation) {
    const frag = document.createDocumentFragment();
    let lastDay = null;
    for (let i = 0; i < messages.length; i++) {
        const msg = messages[i];
        const createdMs = parseServerTime(msg.created_at);
        const dateObj = createdMs ? new Date(createdMs) : new Date();
        const dayLabel = formatDayLabel(dateObj);
        if (lastDay !== dayLabel) {
            const d = document.createElement('div');
            d.className = 'chat-day';
            d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
            frag.appendChild(d);
            lastDay = dayLabel;
        }
        frag.appendChild(createMessageElement(msg, skipAnimation));
    }
    return frag;
}

function appendMessage(msg) {
    const body = $id('chatBody');
    const createdMs = parseServerTime(msg.created_at);
    const dateObj = createdMs ? new Date(createdMs) : new Date();
    const dayLabel = formatDayLabel(dateObj);
    if (lastRenderedDay !== dayLabel) {
        const d = document.createElement('div');
        d.className = 'chat-day';
        d.innerHTML = `<span>${escapeHtml(dayLabel)}</span>`;
        body.appendChild(d);
        lastRenderedDay = dayLabel;
    }
    body.appendChild(createMessageElement(msg, _isScrolling));
}

function formatTime(d) {
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

function formatDayLabel(d) {
    const key = d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
    const cached = _dayLabelCache.get(key);
    if (cached) return cached;

    const t = new Date(), y = new Date();
    y.setDate(t.getDate() - 1);
    const s = d.toDateString();
    let result;
    if (s === t.toDateString()) result = 'امروز';
    else if (s === y.toDateString()) result = 'دیروز';
    else {
        const m = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        result = d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
    }
    if (_dayLabelCache.size > 200) _dayLabelCache.clear();
    _dayLabelCache.set(key, result);
    return result;
}

function sendHeartbeat() {
    if (!_tabVisible) return;
    fetch('api.php?action=heartbeat', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        keepalive: true
    }).catch(() => { });
}

function fetchUserStatus() {
    if (!currentChatUserId || currentIsSaved || currentIsBot) return;
    if (statusRequestInFlight) return;
    if (!_tabVisible) return;
    statusRequestInFlight = true;
    fetch('api.php?action=user_status&user_id=' + currentChatUserId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => { if (data.status === 'success') updateStatusUI(currentChatUserId, data.is_online, data.last_seen, data.last_seen_text); })
        .catch(() => { })
        .finally(() => { statusRequestInFlight = false; });
}

function updateStatusUI(targetUserId, isOnline, lastSeenTs, lastSeenText) {
    if (currentIsSaved && Number(targetUserId) === Number(CURRENT_USER_ID)) return;
    if (currentChatUserId && Number(currentChatUserId) === Number(targetUserId)) {
        if (currentIsBot) return;
        const s = $id('chatHeaderStatus');
        const t = $id('chatHeaderStatusText');
        const a = $id('chatHeaderAvatar');
        s.classList.remove('saved', 'bot');
        if (isOnline) {
            s.classList.remove('offline'); s.classList.add('online');
            t.textContent = 'آنلاین';
            if (a) { a.classList.remove('offline'); a.classList.add('online'); }
        } else {
            s.classList.remove('online'); s.classList.add('offline');
            t.textContent = 'آفلاین — ' + (lastSeenText || 'نامشخص');
            if (a) { a.classList.remove('online'); a.classList.add('offline'); }
        }
    }
    const la = document.querySelector(`.chat-item__avatar[data-avatar-for="${targetUserId}"]`);
    if (la && !la.classList.contains('is-saved-avatar') && !la.classList.contains('is-bot-avatar')) {
        if (isOnline) { la.classList.remove('offline'); la.classList.add('online'); }
        else { la.classList.remove('online'); la.classList.add('offline'); }
    }
}

/* ⚡ بهینه‌شده: استفاده از IntersectionObserver cache به‌جای getBoundingClientRect */
function fetchAllUsersStatus() {
    if (!_tabVisible) return;

    const ids = [];
    // ⚡ فقط چت‌های دیده‌شده (با IntersectionObserver track شدن)
    _visibleChatIds.forEach(uid => {
        if (uid && Number(uid) !== currentChatUserId) ids.push(Number(uid));
    });

    if (ids.length === 0) return;
    fetch('api.php?action=users_status&user_ids=' + ids.join(','), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success' || !data.users) return;
            Object.keys(data.users).forEach(uid => {
                const info = data.users[uid];
                updateStatusUI(Number(uid), info.is_online, info.last_seen, info.last_seen_text);
            });
        })
        .catch(() => { });
}

function sendFileWithMessage() {
    if (!currentChatUserId || !selectedFile || sendingMessage) return;
    const inputEl = $id('chatInput');
    const text = inputEl.value.trim();
    sendingMessage = true;
    $id('chatSendBtn').disabled = true;
    $id('attachBtn').disabled = true;
    const fd = new FormData();
    fd.append('action', 'send_file');
    fd.append('receiver_id', currentChatUserId);
    fd.append('message', text);
    fd.append('attachment', selectedFile);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) updateUploadProgress(Math.round((e.loaded / e.total) * 100));
    });
    xhr.addEventListener('load', () => {
        updateUploadProgress(100);
        sendingMessage = false;
        $id('chatSendBtn').disabled = false;
        $id('attachBtn').disabled = false;
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.status === 'success') {
                inputEl.value = ''; autoResize(inputEl); clearFilePreview();
                pollNewMessages();
                refreshSavedBadge();
            } else alert(data.message || 'خطا در ارسال فایل');
        } catch (e) { alert('خطا در پاسخ سرور'); }
    });
    xhr.addEventListener('error', () => {
        updateUploadProgress(0);
        sendingMessage = false;
        $id('chatSendBtn').disabled = false;
        $id('attachBtn').disabled = false;
        alert('خطا در ارتباط با سرور');
    });
    xhr.open('POST', 'api.php');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(fd);
}

function sendMessage(e) {
    e.preventDefault();
    if (!currentChatUserId || sendingMessage) return;
    if (currentIsBot) {
        showToast('این حساب ربات اطلاع‌رسانی است', 'info');
        return;
    }
    if (selectedFile) { sendFileWithMessage(); return; }
    const inputEl = $id('chatInput');
    const text = inputEl.value.trim();
    if (!text) return;
    sendingMessage = true;
    $id('chatSendBtn').disabled = true;
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('receiver_id', currentChatUserId);
    fd.append('message', text);
    fetch('api.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                inputEl.value = ''; autoResize(inputEl);
                pollNewMessages();
                refreshSavedBadge();
            } else alert(data.message || 'خطا در ارسال');
        })
        .catch(() => alert('خطا در ارتباط با سرور'))
        .finally(() => {
            sendingMessage = false;
            $id('chatSendBtn').disabled = false;
        });
}

const chatInputEl = $id('chatInput');
function autoResize(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 100) + 'px'; }
chatInputEl.addEventListener('input', function () { autoResize(this); });
chatInputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        $id('chatForm').dispatchEvent(new Event('submit'));
    }
});

let _searchListTimer = null;
$id('chatSearchInput').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    clearTimeout(_searchListTimer);
    _searchListTimer = setTimeout(() => {
        const items = getChatItems();
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            const name = (item.getAttribute('data-name') || '').toLowerCase();
            item.style.display = (!q || name.includes(q)) ? 'flex' : 'none';
        }
    }, 80);
});

/* ======================================================
   ⚡ SCROLL HANDLER — قلب روانی اسکرول
   ====================================================== */
const _chatBodyEl = $id('chatBody');
_chatBodyEl.addEventListener('scroll', function () {
    // ⚡ علامت‌گذاری حالت اسکرول — انیمیشن‌ها موقتاً غیرفعال می‌شن
    if (!_isScrolling) {
        _isScrolling = true;
        _chatBodyEl.classList.add('is-scrolling');
    }
    clearTimeout(_scrollEndTimer);
    _scrollEndTimer = setTimeout(() => {
        _isScrolling = false;
        _chatBodyEl.classList.remove('is-scrolling');

        // ⚡ اعمال پیام‌های معلق بعد از توقف اسکرول
        if (_pendingMessages && _pendingMessages.length > 0) {
            const body = $id('chatBody');
            isAtBottom = (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;
            applyNewMessages(body, _pendingMessages, isAtBottom);
            _pendingMessages = null;
        }
    }, 120);

    // ⚡ throttle با rAF برای logic اصلی
    if (_scrollRaf) return;
    _scrollRaf = requestAnimationFrame(() => {
        _scrollRaf = 0;
        if (_chatBodyEl.scrollTop < 120 && hasMoreBefore && !loadingOlder) {
            loadOlderMessages();
        }
    });
}, { passive: true });

function pollUnreadCounts() {
    if (!_tabVisible) return;
    fetch('api.php?action=unread_counts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const counts = data.counts || {};
            const items = getChatItems();
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                if (item.getAttribute('data-is-saved') === '1') continue;
                const uid = item.getAttribute('data-user-id');
                if (!uid) continue;
                if (currentChatUserId && String(currentChatUserId) === uid && !currentIsSaved) continue;
                const ex = item.querySelector('.chat-item__badge');
                const c = counts[uid] || 0;
                if (c > 0) {
                    if (ex) { if (ex.textContent !== String(c)) ex.textContent = c; }
                    else {
                        const b = document.createElement('div');
                        b.className = 'chat-item__badge';
                        b.setAttribute('data-user-id', uid);
                        b.textContent = c;
                        item.appendChild(b);
                    }
                } else if (ex) ex.remove();
            }
        })
        .catch(() => { });
}

function refreshSavedBadge() {
    if (!currentIsSaved) return;
    fetch('api.php?action=saved_info', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const item = document.querySelector('.chat-item.is-saved');
            if (!item) return;
            let badge = item.querySelector('.saved-count-badge');
            if (data.total_count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'saved-count-badge';
                    const n = item.querySelector('.chat-item__name');
                    if (n) n.appendChild(badge);
                }
                badge.textContent = data.total_count;
            } else if (badge) badge.remove();
            const lastEl = item.querySelector('.chat-item__last');
            if (lastEl && data.last_message) {
                let p = '';
                if (data.last_message.message) p = data.last_message.message.substring(0, 40);
                else if (data.last_message.attachment_name) p = '📎 ' + data.last_message.attachment_name.substring(0, 35);
                else p = 'فایل';
                lastEl.textContent = p;
            }
        })
        .catch(() => { });
}

/* ======================================================
   SEARCH
   ====================================================== */
function openSearchModal(defaultScope) {
    const overlay = $id('searchModalOverlay');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    const convoBtn = $id('scopeConversationBtn');
    if (currentChatUserId && !currentIsSaved) {
        convoBtn.disabled = false;
        if (defaultScope === 'conversation') {
            setSearchScope('conversation');
        } else {
            setSearchScope('all');
        }
    } else {
        convoBtn.disabled = true;
        setSearchScope('all');
    }

    setTimeout(() => {
        const inp = $id('searchQueryInput');
        inp.focus();
        inp.select();
    }, 150);
}

function closeSearchModal() {
    const overlay = $id('searchModalOverlay');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    if (searchAbortController) { searchAbortController.abort(); searchAbortController = null; }
    clearTimeout(searchTimer);
}

function setSearchScope(scope) {
    searchScope = scope;
    document.querySelectorAll('.search-modal__scope-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-scope') === scope);
    });
    if (searchInputTouched) triggerSearch();
}

$id('searchQueryInput').addEventListener('input', function () {
    searchInputTouched = true;
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) {
        $id('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-keyboard"></i>حداقل ۲ کاراکتر تایپ کنید تا جستجو شروع شود...</div>';
        return;
    }
    $id('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-spinner fa-spin"></i>در حال جستجو...</div>';
    searchTimer = setTimeout(triggerSearch, 400);
});

function triggerSearch() {
    const q = $id('searchQueryInput').value.trim();
    if (q.length < 2) return;

    if (searchAbortController) searchAbortController.abort();
    searchAbortController = new AbortController();

    let url = `api.php?action=search&q=${encodeURIComponent(q)}&scope=${searchScope}`;
    if (searchScope === 'conversation' && currentChatUserId) url += `&user_id=${currentChatUserId}`;

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: searchAbortController.signal })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') {
                $id('searchResultsBody').innerHTML = `<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>${escapeHtml(data.message || 'خطا در جستجو')}</div>`;
                return;
            }
            renderSearchResults(data.results || [], q);
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            $id('searchResultsBody').innerHTML = '<div class="search-modal__empty"><i class="fas fa-exclamation-circle"></i>خطا در ارتباط با سرور</div>';
        });
}

function renderSearchResults(results, query) {
    lastSearchResults = results;
    const body = $id('searchResultsBody');
    if (results.length === 0) {
        body.innerHTML = `<div class="search-modal__empty"><i class="fas fa-search-minus"></i>نتیجه‌ای برای «${escapeHtml(query)}» یافت نشد</div>`;
        return;
    }
    const qLower = query.toLowerCase();
    const parts = [`<div class="search-modal__info">${results.length} نتیجه یافت شد</div>`];

    for (let i = 0; i < results.length; i++) {
        const r = results[i];
        const createdMs = parseServerTime(r.created_at);
        const dateObj = createdMs ? new Date(createdMs) : new Date();
        const timeStr = formatTime(dateObj);
        const dayLabel = formatDayLabel(dateObj);

        let avatar;
        if (r.from_saved) avatar = '<i class="fas fa-bookmark"></i>';
        else avatar = escapeHtml(r.from_name ? r.from_name.charAt(0) : '?');

        let preview = '';
        if (r.message && r.message.trim()) preview = r.message.replace(/\[\[task:[a-fA-F0-9]+\]\]/g, '').trim();
        else if (r.attachment_name) preview = '📎 ' + r.attachment_name;

        const highlighted = highlightQuery(escapeHtml(preview), qLower);

        let badges = '';
        if (r.is_mine) badges += '<span class="search-result__badge">شما</span>';
        if (r.attachment_name) badges += '<span class="search-result__badge file">📎 فایل</span>';

        parts.push(`
            <div class="search-result" onclick="jumpToResult(${r.id}, ${r.other_user_id}, ${r.from_saved ? 1 : 0})">
                <div class="search-result__avatar ${r.from_saved ? 'is-saved-avatar' : ''}">${avatar}</div>
                <div class="search-result__info">
                    <div class="search-result__top">
                        <div class="search-result__name">${escapeHtml(r.from_saved ? 'پیام های ذخیره شده' : (r.from_name || 'کاربر'))} ${badges}</div>
                        <div class="search-result__time">${escapeHtml(dayLabel)} • ${timeStr}</div>
                    </div>
                    <div class="search-result__text">${highlighted || '(بدون متن)'}</div>
                </div>
            </div>`);
    }
    body.innerHTML = parts.join('');
}

function highlightQuery(escapedText, qLower) {
    if (!escapedText) return '';
    const q = qLower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (!q) return escapedText;
    try {
        const re = new RegExp('(' + q + ')', 'gi');
        return escapedText.replace(re, '<mark>$1</mark>');
    } catch (e) { return escapedText; }
}

function jumpToResult(msgId, otherUserId, fromSaved) {
    closeSearchModal();

    pendingHighlightMsgId = msgId;

    if (currentChatUserId === otherUserId && currentIsSaved === !!fromSaved) {
        loadMessagesAround(msgId);
        pendingHighlightMsgId = null;
        return;
    }

    const item = document.querySelector(`.chat-item[data-user-id="${otherUserId}"]`);
    openChat(otherUserId, item);
}

/* ======================================================
   LIGHTBOX
   ====================================================== */
const lightboxEl = $id('imageLightbox');
const lightboxImg = $id('lightboxImage');
const lightboxCanvas = $id('lightboxCanvas');
const zoomLevelEl = $id('zoomLevel');

function openLightbox(src, name) {
    lightboxCurrentUrl = src;
    lightboxCurrentName = name || 'image';
    lightboxImg.src = src;
    lightboxImg.alt = name || '';
    lightboxZoom = 1; lightboxPanX = 0; lightboxPanY = 0;
    updateLightboxTransform();
    lightboxEl.classList.add('active');
    document.body.style.overflow = 'hidden';
    lightboxImg.onload = function () {
        const w = lightboxImg.naturalWidth, h = lightboxImg.naturalHeight;
        const vw = window.innerWidth * 0.9, vh = window.innerHeight * 0.8;
        if (w > vw || h > vh) {
            const r = Math.min(vw / w, vh / h);
            if (r < 1) { lightboxZoom = r; updateLightboxTransform(); }
        }
    };
}
function closeLightbox() {
    lightboxEl.classList.remove('active');
    document.body.style.overflow = '';
    lightboxImg.src = '';
    lightboxCurrentUrl = ''; lightboxCurrentName = '';
}
function updateLightboxTransform() {
    lightboxImg.style.transform = `translate3d(${lightboxPanX}px, ${lightboxPanY}px, 0) scale(${lightboxZoom})`;
    zoomLevelEl.textContent = Math.round(lightboxZoom * 100) + '%';
}
function zoomIn() { if (lightboxZoom < ZOOM_MAX) { lightboxZoom = Math.min(ZOOM_MAX, lightboxZoom + ZOOM_STEP); updateLightboxTransform(); } }
function zoomOut() { if (lightboxZoom > ZOOM_MIN) { lightboxZoom = Math.max(ZOOM_MIN, lightboxZoom - ZOOM_STEP); if (lightboxZoom <= 1) { lightboxPanX = 0; lightboxPanY = 0; } updateLightboxTransform(); } }
function resetZoom() { lightboxZoom = 1; lightboxPanX = 0; lightboxPanY = 0; updateLightboxTransform(); }
function downloadLightboxImage() {
    if (!lightboxCurrentUrl) return;
    fetch(lightboxCurrentUrl).then(r => r.blob()).then(blob => {
        const u = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = u; a.download = lightboxCurrentName || 'image';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(u), 1000);
    }).catch(() => window.open(lightboxCurrentUrl, '_blank'));
}

lightboxCanvas.addEventListener('mousedown', e => {
    if (lightboxZoom <= 1) return;
    isDragging = true; lightboxCanvas.classList.add('dragging');
    dragStartX = e.clientX - lightboxPanX;
    dragStartY = e.clientY - lightboxPanY;
    e.preventDefault();
});
document.addEventListener('mousemove', e => {
    if (!isDragging) return;
    lightboxPanX = e.clientX - dragStartX;
    lightboxPanY = e.clientY - dragStartY;
    updateLightboxTransform();
}, { passive: true });
document.addEventListener('mouseup', () => {
    if (isDragging) { isDragging = false; lightboxCanvas.classList.remove('dragging'); }
});
lightboxEl.addEventListener('wheel', e => {
    if (!lightboxEl.classList.contains('active')) return;
    e.preventDefault();
    if (e.deltaY < 0) zoomIn(); else zoomOut();
}, { passive: false });

document.addEventListener('contextmenu', e => {
    const b = e.target.closest('.chat-bubble');
    if (b && b.hasAttribute('data-msg-id')) {
        if (b.classList.contains('task-notification')) {
            e.preventDefault();
            const txt = (b.querySelector('.task-notif-body') || {}).innerText || '';
            if (txt.trim()) {
                if (navigator.clipboard) navigator.clipboard.writeText(txt).then(() => showToast('متن کپی شد', 'success')).catch(() => { });
            }
            return;
        }
        openContextMenu(e, b);
    }
});
document.addEventListener('click', e => {
    const m = $id('msgContextMenu');
    if (!m || !m.classList.contains('active')) return;
    if (e.target.closest('#msgContextMenu')) return;
    closeContextMenu();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeContextMenu();
        closeSidebar();
        if (lightboxEl.classList.contains('active')) closeLightbox();
        closeDeleteModal();
        closeEditModal();
        const sm = $id('searchModalOverlay');
        if (sm.classList.contains('active')) closeSearchModal();
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'k')) {
        e.preventDefault();
        openSearchModal(currentChatUserId && !currentIsSaved ? 'conversation' : 'all');
    }
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        const em = $id('editModal');
        if (em && em.classList.contains('active')) { e.preventDefault(); confirmEditMessage(); }
    }
    if (lightboxEl.classList.contains('active')) {
        switch (e.key) {
            case '+': case '=': e.preventDefault(); zoomIn(); break;
            case '-': case '_': e.preventDefault(); zoomOut(); break;
            case '0': e.preventDefault(); resetZoom(); break;
            case 'd': case 'D': e.preventDefault(); downloadLightboxImage(); break;
        }
    }
});

/* ======================================================
   INIT & TIMERS
   ====================================================== */

let _hbTimer = null;
let _unreadTimer = null;
let _statusTimerAll = null;

function startTimers() {
    if (_hbTimer) clearInterval(_hbTimer);
    if (_unreadTimer) clearInterval(_unreadTimer);
    if (_statusTimerAll) clearInterval(_statusTimerAll);

    _hbTimer = setInterval(sendHeartbeat, 10000);
    _unreadTimer = setInterval(pollUnreadCounts, 8000);
    _statusTimerAll = setInterval(fetchAllUsersStatus, 6000);
}

function stopTimers() {
    if (_hbTimer) { clearInterval(_hbTimer); _hbTimer = null; }
    if (_unreadTimer) { clearInterval(_unreadTimer); _unreadTimer = null; }
    if (_statusTimerAll) { clearInterval(_statusTimerAll); _statusTimerAll = null; }
}

document.addEventListener('visibilitychange', () => {
    _tabVisible = !document.hidden;
    if (_tabVisible) {
        sendHeartbeat();
        if (currentChatUserId) pollNewMessages();
        pollUnreadCounts();
        fetchAllUsersStatus();
        startTimers();
    } else {
        stopTimers();
    }
});

sendHeartbeat();
startTimers();
initChatItemObserver();
whenIdle(() => fetchAllUsersStatus());

if (AUTO_OPEN_CHAT_ID > 0) {
    const autoItem = document.querySelector(`.chat-item[data-user-id="${AUTO_OPEN_CHAT_ID}"]`);
    if (autoItem) {
        setTimeout(() => openChat(AUTO_OPEN_CHAT_ID, autoItem), 350);
    }
    try {
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('chat');
        window.history.replaceState({}, '', cleanUrl.toString());
    } catch (e) { }
}

function escapeHtml(t) {
    if (t === null || t === undefined) return '';
    const str = String(t);
    const cached = _escapeCache.get(str);
    if (cached !== undefined) return cached;
    const m = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    const result = str.replace(/[&<>"']/g, c => m[c]);
    if (_escapeCache.size > _ESC_MAX) _escapeCache.clear();
    _escapeCache.set(str, result);
    return result;
}

function toggleSidebar() {
    const s = $id('appSidebar');
    const o = $id('sidebarOverlay');
    const b = $id('hamburgerBtn');
    if (s.classList.contains('open')) closeSidebar();
    else {
        s.classList.add('open');
        o.classList.add('active');
        b.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}
function closeSidebar() {
    const s = $id('appSidebar');
    const o = $id('sidebarOverlay');
    const b = $id('hamburgerBtn');
    if (!s || !o || !b) return;
    s.classList.remove('open');
    o.classList.remove('active');
    b.classList.remove('active');
    document.body.style.overflow = '';
}
let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (window.innerWidth > 900) closeSidebar(); }, 150);
}, { passive: true });