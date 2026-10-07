/**
 * Global Notifications — Chat + Tasks
 * نیازمند:
 *   window.CHAT_NOTIF_CONFIG = { apiUrl, chatUrl }
 *   window.TASK_NOTIF_CONFIG = { apiUrl, taskUrl, iconUrl, pollIntervalMs, firstDelayMs }
 */
(function () {
    'use strict';
    if (window.__GLOBAL_NOTIF_LOADED__) return;
    window.__GLOBAL_NOTIF_LOADED__ = true;

    /* ====================== CONFIG ====================== */
    const chatCfg = window.CHAT_NOTIF_CONFIG || {};
    const taskCfg = window.TASK_NOTIF_CONFIG || {};

    const CHAT_API = chatCfg.apiUrl || 'api.php';
    const CHAT_URL = chatCfg.chatUrl || 'index.php';
    const TASK_API = taskCfg.apiUrl || '';
    const TASK_URL = taskCfg.taskUrl || '';
    const TASK_ICON = taskCfg.iconUrl || 'https://img.icons8.com/color/96/dashboard-layout.png';
    const TASK_POLL_MS = parseInt(taskCfg.pollIntervalMs, 10) || 30000;
    const TASK_FIRST_DELAY = parseInt(taskCfg.firstDelayMs, 10) || 5000;

    const DISMISS_KEY = 'chat_notif_dismissed_at';
    const DISMISS_DAYS = 3;
    const CHAT_POLL_MS = 6000;

    const notifiedLatestIds = {};
    let notifInitialized = false;
    let pollInFlight = false;
    let taskPollInFlight = false;
    let lastPermTime = 0;
    let taskPollerStarted = false;

    const supported = () => ('Notification' in window);
    const activeChatId = () => Number(window.__chatActiveUserId || 0);

    /* ====================== Dismiss helper ====================== */
    function isDismissedRecently() {
        try {
            const v = localStorage.getItem(DISMISS_KEY);
            if (!v) return false;
            const ts = parseInt(v, 10);
            if (!ts) return false;
            return ((Date.now() - ts) / 86400000) < DISMISS_DAYS;
        } catch (e) { return false; }
    }
    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) { }
    }

    /* ====================== UI (Modal) ====================== */
    function injectUI() {
        if (document.getElementById('__chatNotifModalOverlay')) return;

        if (!document.getElementById('__chatNotifStyles')) {
            const st = document.createElement('style');
            st.id = '__chatNotifStyles';
            st.textContent = `
.chat-notif-overlay{position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:999999;display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .3s;font-family:Tahoma,sans-serif;direction:rtl}
.chat-notif-overlay.active{display:flex;opacity:1}
.chat-notif-box{background:#fff;border-radius:22px;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(15,23,42,.45);overflow:hidden;transform:scale(.9) translateY(20px);transition:transform .35s cubic-bezier(.34,1.56,.64,1);text-align:center}
.chat-notif-overlay.active .chat-notif-box{transform:scale(1) translateY(0)}
.chat-notif-hero{padding:34px 24px 22px;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 50%,#bfdbfe 100%);position:relative;overflow:hidden}
.chat-notif-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;background:radial-gradient(circle,rgba(37,99,235,.18),transparent 70%);border-radius:50%}
.chat-notif-hero::after{content:'';position:absolute;bottom:-30px;left:-30px;width:110px;height:110px;background:radial-gradient(circle,rgba(37,99,235,.12),transparent 70%);border-radius:50%}
.chat-notif-bell{position:relative;width:82px;height:82px;margin:0 auto 16px;border-radius:24px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2.1rem;box-shadow:0 12px 28px rgba(37,99,235,.45);animation:chatNotifBellShake 2.6s ease-in-out infinite;z-index:1}
.chat-notif-bell::after{content:'';position:absolute;inset:-8px;border-radius:28px;border:2px solid rgba(37,99,235,.25);animation:chatNotifPulseRing 2s ease-in-out infinite}
@keyframes chatNotifPulseRing{0%{transform:scale(.92);opacity:.8}70%{transform:scale(1.1);opacity:0}100%{transform:scale(1.1);opacity:0}}
@keyframes chatNotifBellShake{0%,70%,100%{transform:rotate(0)}75%{transform:rotate(-12deg)}80%{transform:rotate(10deg)}85%{transform:rotate(-8deg)}90%{transform:rotate(6deg)}95%{transform:rotate(-3deg)}}
.chat-notif-title{position:relative;font-size:1.15rem;font-weight:800;color:#1e293b;margin:0 0 8px;z-index:1}
.chat-notif-subtitle{position:relative;font-size:.82rem;color:#475569;margin:0;line-height:1.7;z-index:1}
.chat-notif-body{padding:20px 24px 4px;text-align:right}
.chat-notif-feature{display:flex;align-items:center;gap:12px;padding:11px 4px;font-size:.84rem;color:#334155;line-height:1.6}
.chat-notif-feature + .chat-notif-feature{border-top:1px dashed #e2e8f0}
.chat-notif-feature i{width:34px;height:34px;border-radius:11px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.chat-notif-footer{padding:16px 24px 22px;display:flex;flex-direction:column;gap:8px}
.chat-notif-btn{padding:13px 20px;border-radius:13px;border:none;font-family:Tahoma,sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;justify-content:center;gap:9px;width:100%}
.chat-notif-btn--primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 6px 16px rgba(37,99,235,.4)}
.chat-notif-btn--primary:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(37,99,235,.55)}
.chat-notif-btn--primary:disabled{opacity:.6;cursor:not-allowed;transform:none}
.chat-notif-btn--ghost{background:transparent;color:#64748b;font-weight:600;font-size:.82rem}
.chat-notif-btn--ghost:hover{color:#334155;background:#f1f5f9}
@media (max-width:500px){.chat-notif-hero{padding:26px 20px 18px}.chat-notif-bell{width:70px;height:70px;font-size:1.8rem;border-radius:20px}.chat-notif-title{font-size:1.02rem}.chat-notif-body{padding:16px 18px 2px}.chat-notif-footer{padding:14px 18px 18px}}
@media (prefers-reduced-motion:reduce){.chat-notif-bell,.chat-notif-bell::after{animation:none!important}}
`;
            document.head.appendChild(st);
        }

        const wrap = document.createElement('div');
        wrap.id = '__chatNotifModalOverlay';
        wrap.className = 'chat-notif-overlay';
        wrap.innerHTML = `
            <div class="chat-notif-box" role="dialog" aria-modal="true">
                <div class="chat-notif-hero">
                    <div class="chat-notif-bell"><i class="fas fa-bell"></i></div>
                    <h3 class="chat-notif-title">اعلان‌های پیام و وظایف را فعال کنید</h3>
                    <p class="chat-notif-subtitle">با فعال‌سازی اعلان‌ها، از پیام‌های جدید همکاران و زمان انجام وظایف باخبر می‌شوید.</p>
                </div>
                <div class="chat-notif-body">
                    <div class="chat-notif-feature"><i class="fas fa-comments"></i><span>اطلاع‌رسانی آنی پیام‌های همکاران</span></div>
                    <div class="chat-notif-feature"><i class="fas fa-clock"></i><span>یادآوری خودکار وظایف در زمان مقرر</span></div>
                    <div class="chat-notif-feature"><i class="fas fa-mouse-pointer"></i><span>با کلیک روی اعلان، مستقیم وارد صفحه مربوطه می‌شوید</span></div>
                </div>
                <div class="chat-notif-footer">
                    <button type="button" class="chat-notif-btn chat-notif-btn--primary" id="__chatNotifAllowBtn"><i class="fas fa-bell"></i> بله، اعلان‌ها را فعال کن</button>
                    <button type="button" class="chat-notif-btn chat-notif-btn--ghost" id="__chatNotifDismissBtn">بعداً یادآوری کن</button>
                </div>
            </div>
        `;
        document.body.appendChild(wrap);

        document.getElementById('__chatNotifAllowBtn').addEventListener('click', allowNotifications);
        document.getElementById('__chatNotifDismissBtn').addEventListener('click', dismissNotifModal);
    }

    function showModal() {
        injectUI();
        const el = document.getElementById('__chatNotifModalOverlay');
        if (el) el.classList.add('active');
    }
    function hideModal() {
        const el = document.getElementById('__chatNotifModalOverlay');
        if (el) el.classList.remove('active');
    }
    function dismissNotifModal() { hideModal(); markDismissed(); }

    function allowNotifications() {
        if (!supported()) { hideModal(); return; }
        const btn = document.getElementById('__chatNotifAllowBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال دریافت مجوز...';
        }

        try {
            const req = Notification.requestPermission(function (perm) { handlePerm(perm, btn); });
            if (req && typeof req.then === 'function') {
                req.then(function (perm) {
                    if (typeof perm === 'string') handlePerm(perm, btn);
                }).catch(function () { handlePerm('denied', btn); });
            }
        } catch (e) { handlePerm('denied', btn); }
    }

    function handlePerm(perm, btn) {
        const now = Date.now();
        if (now - lastPermTime < 300) return;
        lastPermTime = now;

        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-bell"></i> بله، اعلان‌ها را فعال کن';
        }

        if (perm === 'granted') {
            hideModal();
            try { localStorage.removeItem(DISMISS_KEY); } catch (e) { }
            Object.keys(notifiedLatestIds).forEach(k => delete notifiedLatestIds[k]);
            notifInitialized = false;
            startChatPolling(true);
            startTaskPoller(true);
        } else if (perm === 'denied') {
            hideModal();
            markDismissed();
        }
    }

    /* ====================== Chat notification ====================== */
    function showBrowserNotification(item) {
        if (!supported() || Notification.permission !== 'granted') return;

        let body = (item.message || '').trim();
        if (!body && item.attachment_name) body = '📎 ' + item.attachment_name;
        if (body.length > 120) body = body.substring(0, 120) + '…';
        if (!body) body = 'پیام جدید دریافت کردید';

        let n;
        try {
            n = new Notification('پیام جدید از ' + item.name, {
                body, dir: 'rtl', lang: 'fa',
                icon: 'https://img.icons8.com/color/96/chat.png',
                badge: 'https://img.icons8.com/color/96/chat.png',
                tag: 'chat-' + item.sender_id + '-' + item.message_id,
                data: { senderId: item.sender_id, messageId: item.message_id }
            });
        } catch (e) { return; }

        n.onclick = function (ev) {
            ev.preventDefault();
            try { window.focus(); } catch (e) { }

            if (activeChatId() === Number(item.sender_id)) {
                try { n.close(); } catch (e) { }
                return;
            }
            try {
                const url = new URL(CHAT_URL, window.location.href);
                url.searchParams.set('chat', item.sender_id);
                window.location.href = url.toString();
            } catch (e) {
                window.location.href = CHAT_URL + '?chat=' + item.sender_id;
            }
            try { n.close(); } catch (e) { }
        };

        setTimeout(() => { try { n.close(); } catch (e) { } }, 15000);
    }

    function pollLatestUnread() {
        if (!supported() || Notification.permission !== 'granted') return;
        if (pollInFlight) return;
        pollInFlight = true;

        fetch(CHAT_API + '?action=latest_unread', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(r => r.json())
            .then(data => {
                if (!data || data.status !== 'success') return;
                const items = data.items || [];
                const chatId = activeChatId();

                items.forEach(item => {
                    const sid = Number(item.sender_id);
                    const mid = Number(item.message_id);
                    const prev = notifiedLatestIds[sid];
                    const beingViewed = (chatId === sid) && !document.hidden;

                    if (!notifInitialized) { notifiedLatestIds[sid] = mid; return; }

                    if (prev === undefined && !beingViewed) showBrowserNotification(item);
                    else if (prev !== undefined && mid > prev && !beingViewed) showBrowserNotification(item);

                    notifiedLatestIds[sid] = mid;
                });

                notifInitialized = true;
            })
            .catch(() => { })
            .finally(() => { pollInFlight = false; });
    }

    let chatPollTimer = null;
    function startChatPolling(fresh) {
        pollLatestUnread();
        setTimeout(pollLatestUnread, 1500);
        if (chatPollTimer) clearInterval(chatPollTimer);
        chatPollTimer = setInterval(pollLatestUnread, CHAT_POLL_MS);
    }

    /* ====================== Task notification ====================== */
    function showTaskToast(title) {
        let el = document.getElementById('__globalTaskToast');
        if (!el) {
            el = document.createElement('div');
            el.id = '__globalTaskToast';
            el.style.cssText = [
                'position:fixed', 'bottom:30px', 'left:50%',
                'transform:translateX(-50%) translateY(120px)',
                'background:linear-gradient(135deg,#7c3aed,#6d28d9)',
                'color:#fff', 'padding:14px 22px', 'border-radius:26px',
                'font-family:Tahoma,sans-serif', 'font-size:.88rem', 'font-weight:700',
                'box-shadow:0 12px 32px rgba(124,58,237,.5)',
                'z-index:9999998', 'opacity:0', 'direction:rtl',
                'display:flex', 'align-items:center', 'gap:10px',
                'pointer-events:none', 'max-width:90vw',
                'transition:all .35s cubic-bezier(.34,1.56,.64,1)'
            ].join(';');
            document.body.appendChild(el);
        }
        el.innerHTML = '<span style="font-size:1.15rem">⏰</span><span>' +
            String(title || '').replace(/[<>&"]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c])) +
            '</span>';
        el.style.opacity = '1';
        el.style.transform = 'translateX(-50%) translateY(0)';
        clearTimeout(el.__timer);
        el.__timer = setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateX(-50%) translateY(120px)';
        }, 8000);
    }

    function showTaskBrowserNotification(task) {
        const lines = [];
        if (task.description) lines.push(task.description);
        if (task.project_title) lines.push('پروژه: ' + task.project_title);
        const body = lines.join('\n') || 'زمان انجام این وظیفه رسیده است.';
        const title = 'یادآوری وظیف : ' + task.title;

        /* ساخت URL مقصد */
        let target = TASK_URL || '';
        if (task.share_token) {
            target += (target.indexOf('?') === -1 ? '?' : '&') + 'task=' + encodeURIComponent(task.share_token);
        } else {
            target += (target.indexOf('?') === -1 ? '?' : '&') + 'page=list';
        }

        let n;
        try {
            n = new Notification(title, {
                body, dir: 'rtl', lang: 'fa',
                icon: TASK_ICON, badge: TASK_ICON,
                tag: 'task-due-' + task.id,
                requireInteraction: true,
                data: { taskId: task.id, token: task.share_token || '' }
            });
        } catch (e) { return; }

        n.onclick = function (ev) {
            ev.preventDefault();
            try { window.focus(); } catch (e) { }
            try { n.close(); } catch (e) { }
            if (target) {
                try { window.location.href = target; } catch (e) { }
            }
        };

        setTimeout(() => { try { n.close(); } catch (e) { } }, 60000);
    }

    function pollTaskReminders() {
        if (!TASK_API) return;
        if (!supported() || Notification.permission !== 'granted') return;
        if (taskPollInFlight) return;
        taskPollInFlight = true;

        fetch(TASK_API + '?action=check_due', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data || data.status !== 'success') return;
                const tasks = data.tasks || [];
                tasks.forEach(function (task) {
                    showTaskBrowserNotification(task);
                    showTaskToast('یادآوری وظیف: ' + task.title);
                });
            })
            .catch(() => { })
            .finally(() => { taskPollInFlight = false; });
    }

    function startTaskPoller(force) {
        if (taskPollerStarted && !force) return;
        if (!TASK_API) return;
        taskPollerStarted = true;
        setTimeout(pollTaskReminders, TASK_FIRST_DELAY);
        setInterval(pollTaskReminders, TASK_POLL_MS);
    }

    /* ====================== Init ====================== */
    function init() {
        if (!supported()) return;

        if (Notification.permission === 'default' && !isDismissedRecently()) {
            setTimeout(showModal, 1200);
        }
        if (Notification.permission === 'granted') {
            startChatPolling();
            startTaskPoller();
        }

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && Notification.permission === 'granted') {
                setTimeout(pollLatestUnread, 300);
                setTimeout(pollTaskReminders, 800);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* ====================== Public API ====================== */
    window.ChatNotifications = {
        setActiveChat: function (uid) { window.__chatActiveUserId = Number(uid) || 0; },
        clearActiveChat: function () { window.__chatActiveUserId = 0; },
        pollNow: pollLatestUnread,
        pollTasksNow: pollTaskReminders,
        resetState: function () {
            Object.keys(notifiedLatestIds).forEach(k => delete notifiedLatestIds[k]);
            notifInitialized = false;
        }
    };
})();