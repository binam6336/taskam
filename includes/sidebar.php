<?php

function sidebar(){

    global $page, $sidebarUnread;

    // ⭐ اگر sidebarUnread در صفحه ست نشده باشد، خودمان حساب می‌کنیم
    if (!isset($sidebarUnread)) {
        $sidebarUnread = 0;
        try {
            $__db = Database::getInstance();
            $__uid = (int)($_SESSION['user_id'] ?? 0);
            if ($__uid > 0) {
                $__stmt = $__db->prepare("SELECT COUNT(*) FROM colleague_messages WHERE receiver_id = ? AND is_read = 0 AND sender_id != receiver_id");
                $__stmt->execute([$__uid]);
                $sidebarUnread = (int)$__stmt->fetchColumn();
            }
        } catch (Exception $e) {
            $sidebarUnread = 0;
        }
    }

    $dashboardOpen  = ($page === 'dashboard');
    $tasksOpen      = in_array($page, ['list','subjects'], true);
    $ticketsOpen    = in_array($page, ['tickets','texts'], true);
    $callOpen       = ($page === 'call_requests');
    $colleaguesOpen = in_array($page, ['colleagues','projects'], true);
    $chatOpen       = ($page === 'chat');
    $webserviceOpen = ($page === 'webservice');
    // ⭐ پروفایل حالا شامل صفحه پشتیبانی هم می‌شود
    $profileOpen    = in_array($page, ['profile', 'support'], true);

    ?>

    <style>

        /* =========================================================
           متغیرهای انیمیشن سایدبار
        ========================================================= */

        :root {
            --sb-width: 260px;
            --sb-width-collapsed: 72px;

            --sb-duration: 0.9s;
            --sb-easing: cubic-bezier(0.4, 0, 0.2, 1);

            --sb-text-duration-out: 0.15s;
            --sb-text-duration-in: 0.35s;
            --sb-text-delay-in: 0.6s;
            --sb-text-easing: ease;

            --sb-submenu-duration: 0.4s;
            --sb-submenu-easing: cubic-bezier(0.65, 0, 0.35, 1);
        }


        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            width: var(--sb-width);
            background: linear-gradient(180deg, #4c8bf5 0%, #2f6bdc 100%) !important;
            color: #fff !important;
            box-shadow: -6px 0 28px rgba(76, 139, 245, 0.18);

            overflow: visible;

            padding: 0;
            gap: 0;
            display: flex;
            flex-direction: column;

            transition:
                width var(--sb-duration) var(--sb-easing),
                min-width var(--sb-duration) var(--sb-easing);

            will-change: width, min-width;
            position: relative;
        }

        .sidebar.is-collapsed {
            width: var(--sb-width-collapsed) !important;
            min-width: var(--sb-width-collapsed) !important;
        }


        .sidebar-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;

            padding: 22px 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 4px;

            transition: padding var(--sb-duration) var(--sb-easing);
        }

        .sidebar.is-collapsed .sidebar-scroll {
            padding: 22px 10px 18px;
            overflow: visible;
        }

        .sidebar-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.18);
            border-radius: 10px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.28);
        }


        /* =========================================================
           COLLAPSE TOGGLE BUTTON
        ========================================================= */

        .sidebar-collapse-btn {
            position: absolute;
            top: 26px;
            left: -14px;

            width: 28px;
            height: 28px;

            border-radius: 50%;
            border: 2px solid #fff;

            background: #2f6bdc;
            color: #fff;

            display: flex;
            align-items: center;
            justify-content: center;

            cursor: pointer;
            z-index: 10;

            box-shadow: 0 3px 10px rgba(0,0,0,0.2);

            transition:
                background-color 0.3s ease,
                box-shadow 0.3s ease;
        }

        .sidebar-collapse-btn:hover {
            background: #1e4fb8;
        }

        .sidebar-collapse-btn .collapse-arrow {
            font-size: 0.7rem;
            transition: transform var(--sb-duration) var(--sb-easing);
        }

        .sidebar.is-collapsed .sidebar-collapse-btn .collapse-arrow {
            transform: rotate(180deg);
        }


        /* =========================================================
           MENU GROUP
        ========================================================= */

        .menu-group {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0;
        }


        /* =========================================================
           انیمیشن متن‌ها
        ========================================================= */

        .sidebar-brand span,
        .menu-parent .menu-text,
        .menu-parent .menu-arrow,
        .menu-item span {
            white-space: nowrap;
            opacity: 1;

            transition:
                opacity var(--sb-text-duration-in) var(--sb-text-easing) var(--sb-text-delay-in);
        }

        .sidebar.is-collapsed .sidebar-brand span,
        .sidebar.is-collapsed .menu-parent .menu-text,
        .sidebar.is-collapsed .menu-item span {
            opacity: 0;

            transition:
                opacity var(--sb-text-duration-out) var(--sb-text-easing) 0s;
        }


        /* =========================================================
           BRAND
        ========================================================= */

        .sidebar .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            font-weight: 800;
            color: #fff !important;
            padding: 0 8px 20px;
            margin-bottom: 6px;
            border-bottom: 1px solid rgba(255,255,255,0.15) !important;
            letter-spacing: -0.3px;

            overflow: hidden;

            transition:
                padding var(--sb-duration) var(--sb-easing),
                gap var(--sb-duration) var(--sb-easing);
        }

        .sidebar .sidebar-brand i {
            width: 34px;
            height: 34px;
            background: rgba(255,255,255,0.22);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem !important;
            flex-shrink: 0;

            transition:
                transform var(--sb-duration) var(--sb-easing),
                border-radius var(--sb-duration) var(--sb-easing);
        }

        .sidebar.is-collapsed .sidebar-brand {
            padding: 0 calc(50% - 17px) 20px !important;
            gap: 0;
        }

        .sidebar.is-collapsed .sidebar-brand i {
            border-radius: 50%;
            transform: scale(1.05);
        }


        /* =========================================================
           MENU PARENT
        ========================================================= */

        .menu-parent {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 13px;
            color: rgba(255,255,255,0.82) !important;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            user-select: none;

            overflow: hidden;

            transition:
                padding var(--sb-duration) var(--sb-easing),
                gap var(--sb-duration) var(--sb-easing),
                background-color 0.35s ease,
                color 0.35s ease,
                box-shadow 0.35s ease;

            background: none;
            border: none;
            font-family: inherit;
            text-align: right;
            width: 100%;

            position: relative;
        }

        .menu-parent:hover {
            background: rgba(255,255,255,0.14) !important;
            color: #fff !important;
        }

        .menu-parent.is-open,
        .menu-parent.is-current {
            background: rgba(255,255,255,0.2) !important;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .menu-parent i.menu-icon {
            width: 18px;
            text-align: center;
            font-size: 0.92rem;
            flex-shrink: 0;
        }

        .menu-parent .menu-arrow {
            margin-right: auto;
            font-size: 0.65rem;
            color: rgba(255,255,255,0.6) !important;
            flex-shrink: 0;

            transition:
                transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                opacity var(--sb-text-duration-in) var(--sb-text-easing) var(--sb-text-delay-in),
                color 0.3s ease;
        }

        .menu-parent.is-open .menu-arrow {
            transform: rotate(180deg);
            color: #fff !important;
        }

        .sidebar.is-collapsed .menu-parent .menu-arrow {
            opacity: 0;
            transition:
                transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                opacity var(--sb-text-duration-out) var(--sb-text-easing) 0s,
                color 0.3s ease;
        }

        .sidebar.is-collapsed .menu-parent {
            padding: 11px 17px !important;
            gap: 0 !important;
        }

        .sidebar.is-collapsed .menu-parent i.menu-icon {
            width: auto;
            font-size: 1.05rem;
        }


/* =========================================================
   ⭐ UNREAD BADGE روی آیکون چت — دایره سفید با متن سیاه
============================================================= */

/* آیکون چت باید position: relative باشه تا badge absolute بشه */
.menu-parent[data-group="chat"] .menu-icon {
    position: relative;
}

.menu-parent .badge-unread {
    position: absolute;
    top: 50%;
    right: 12px;
    transform: translateY(-50%);

    background: #ffffff !important;
    color: #1e293b !important;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: -0.3px;
    padding: 0;
    min-width: 22px;
    height: 22px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    flex-shrink: 0;
    line-height: 1;
    direction: ltr;
    font-variant-numeric: tabular-nums;
    pointer-events: none;
    z-index: 5;
    animation: badgePulse 2s ease-in-out infinite;
}

@keyframes badgePulse {
    0%, 100% { transform: translateY(-50%) scale(1); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); }
    50% { transform: translateY(-50%) scale(1.1); box-shadow: 0 3px 12px rgba(0, 0, 0, 0.35); }
}

/* حالت جمع‌شده: badge گوشه بالا-راست آیکون */
.sidebar.is-collapsed .menu-parent .badge-unread {
    top: 4px;
    right: 4px;
    transform: none;
    margin: 0 !important;
    font-size: 0.6rem;
    font-weight: 700;
    min-width: 17px;
    height: 17px;
    border-radius: 50%;
    border: 2px solid #4c8bf5;
    animation: none;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25);
    line-height: 1;
}

/* اگر صفر بود، مخفی بشه */
.menu-parent .badge-unread.is-empty {
    display: none !important;
}
        /* =========================================================
           FLYOUT SUBMENU (حالت جمع‌شده)
        ========================================================= */

        .sidebar.is-collapsed .menu-group .submenu-collapsible {
            position: absolute;
            top: 0;
            right: calc(100% + 6px);

            width: 200px;
            max-width: 60vw;

            grid-template-rows: 1fr !important;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateX(10px);

            background: #2f6bdc;
            border-radius: 12px;
            padding: 8px 6px;

            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);

            z-index: 2000;

            transition:
                opacity 0.25s cubic-bezier(0.25, 1, 0.5, 1),
                visibility 0s linear 0.25s,
                transform 0.3s cubic-bezier(0.25, 1, 0.5, 1);
        }

        .sidebar.is-collapsed .menu-group .submenu-collapsible.is-flyout-open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateX(0);

            transition:
                opacity 0.25s cubic-bezier(0.25, 1, 0.5, 1),
                visibility 0s linear 0s,
                transform 0.3s cubic-bezier(0.25, 1, 0.5, 1);
        }

        .sidebar.is-collapsed .menu-group .submenu-collapsible::before {
            content: '';
            position: absolute;
            top: -10px;
            bottom: -10px;
            right: 100%;
            left: -10px;
            background: transparent;
            pointer-events: auto;
        }

        .sidebar.is-collapsed .menu-group .submenu-collapsible.is-open {
            grid-template-rows: 1fr !important;
        }

        .sidebar.is-collapsed .menu-group .submenu-inner {
            padding: 0 !important;
            gap: 4px;
            overflow: visible;
        }

        .sidebar.is-collapsed .menu-group .submenu-inner a span {
            display: inline !important;
            opacity: 1 !important;
            transform: none !important;
        }

        .sidebar.is-collapsed .menu-group .submenu-inner a {
            color: rgba(255,255,255,0.85) !important;
            font-size: 0.82rem;
            padding: 8px 12px;
            border-radius: 8px;
            transform: none;
            transition: background-color 0.25s ease, color 0.25s ease;
        }

        .sidebar.is-collapsed .menu-group .submenu-inner a:hover {
            background: rgba(255,255,255,0.15) !important;
            color: #fff !important;
            transform: none;
        }

        .sidebar.is-collapsed .menu-group .submenu-inner a.active {
            background: rgba(255,255,255,0.22) !important;
            color: #fff !important;
        }

        .sidebar.is-collapsed .menu-item {
            position: relative;
        }

        .sidebar.is-collapsed .menu-item:hover::after {
            content: attr(data-title);
            position: absolute;
            right: calc(100% + 12px);
            top: 50%;
            transform: translateY(-50%);

            background: #1e293b;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 8px;

            white-space: nowrap;
            z-index: 1000;
            pointer-events: none;

            box-shadow: 0 4px 14px rgba(0,0,0,0.25);

            animation: tooltipIn 0.25s cubic-bezier(0.25, 1, 0.5, 1);
        }

        @keyframes tooltipIn {
            from { opacity: 0; transform: translateY(-50%) translateX(-6px); }
            to   { opacity: 1; transform: translateY(-50%) translateX(0); }
        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 768px) {
            .sidebar-collapse-btn {
                display: none !important;
            }

            .sidebar.is-collapsed {
                width: auto !important;
                min-width: auto !important;
            }

            .sidebar.is-collapsed .sidebar-brand span,
            .sidebar.is-collapsed .menu-parent .menu-text,
            .sidebar.is-collapsed .menu-item span {
                opacity: 1 !important;
            }
        }


        /* =========================================================
           SUBMENU ACCORDION
        ========================================================= */

        .submenu-collapsible {
            display: grid;
            grid-template-rows: 0fr;
            opacity: 0;
            transition:
                grid-template-rows var(--sb-submenu-duration) var(--sb-submenu-easing),
                opacity 0.3s var(--sb-submenu-easing);
        }

        .submenu-collapsible.is-open {
            grid-template-rows: 1fr;
            opacity: 1;
        }

        .submenu-inner {
            min-height: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 3px;
            padding-right: 22px;
            padding-top: 0;
            padding-bottom: 0;
            transition:
                padding-top var(--sb-submenu-duration) var(--sb-submenu-easing),
                padding-bottom var(--sb-submenu-duration) var(--sb-submenu-easing);
        }

        .submenu-collapsible.is-open .submenu-inner {
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .submenu-inner a {
            color: rgba(255,255,255,0.78) !important;
            text-decoration: none;
            font-size: 0.79rem;
            font-weight: 500;
            padding: 8px 12px;
            border-radius: 9px;

            transition:
                background-color 0.3s cubic-bezier(0.25, 1, 0.5, 1),
                color 0.3s cubic-bezier(0.25, 1, 0.5, 1),
                transform 0.35s cubic-bezier(0.25, 1, 0.5, 1);

            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateX(0);
        }

        .submenu-inner a:hover {
            color: #fff !important;
            background: rgba(255,255,255,0.13) !important;
            transform: translateX(-3px);
        }

        .submenu-inner a.active {
            color: #fff !important;
            background: rgba(255,255,255,0.22) !important;
            font-weight: 700;
        }

        .submenu-inner a i {
            width: 16px;
            text-align: center;
            font-size: 0.82rem;
        }


        /* =========================================================
           SINGLE MENU ITEM (logout)
        ========================================================= */

        .sidebar .menu-item {
            display: flex;
            align-items: center;
            gap: 11px;

            padding: 11px 13px;

            color: rgba(255,255,255,0.82) !important;
            text-decoration: none;
            border-radius: 12px;

            font-weight: 600;
            font-size: 0.85rem;

            overflow: hidden;

            transition:
                padding var(--sb-duration) var(--sb-easing),
                gap var(--sb-duration) var(--sb-easing),
                background-color 0.3s ease,
                color 0.3s ease;
        }

        .sidebar .menu-item:hover {
            background: rgba(255,255,255,0.14) !important;
            color: #fff !important;
        }

        .sidebar .menu-item.active {
            background: rgba(255,255,255,0.2) !important;
            color: #fff !important;
        }

        .sidebar .menu-item i {
            width: 18px;
            text-align: center;
            font-size: 0.92rem;
            flex-shrink: 0;
        }

        .sidebar.is-collapsed .menu-item {
            padding: 11px 17px !important;
            gap: 0 !important;
        }

        .sidebar.is-collapsed .menu-item i {
            width: auto;
            font-size: 1.05rem;
        }

        .sidebar .menu-item[href*="logout"] {
            color: #ffd6d6 !important;
            border-top: 1px solid rgba(255,255,255,0.12);
            padding-top: 14px;
            margin-top: 16px !important;
        }

        .sidebar.is-collapsed .menu-item[href*="logout"] {
            padding-top: 14px !important;
        }

        .sidebar .menu-item[href*="logout"]:hover {
            background: rgba(255, 80, 120, 0.25) !important;
            color: #fff !important;
        }

    </style>


    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>


    <div class="sidebar" id="appSidebar">

        <button
            type="button"
            class="sidebar-collapse-btn"
            id="sidebarCollapseBtn"
            onclick="toggleSidebarCollapse()"
            title="جمع / باز کردن منو">

            <i class="fas fa-chevron-right collapse-arrow"></i>

        </button>


        <div class="sidebar-scroll">

            <div class="sidebar-brand">
                <i class="fas fa-check-circle"></i>
                <span>تسکام</span>
            </div>


            <div class="menu-group" data-group="dashboard">
                <button
                    type="button"
                    class="menu-parent <?= $dashboardOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('dashboard')"
                    data-group="dashboard"
                    data-title="داشبورد تحلیل">
                    <i class="fas fa-th-large menu-icon"></i>
                    <span class="menu-text">داشبورد تحلیل</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $dashboardOpen ? 'is-open' : '' ?>" data-group="dashboard">
                    <div class="submenu-inner">
                        <a href="../tasks/index.php?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                            <i class="fas fa-chart-line"></i>
                            <span>نمای کلی</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="tasks">
                <button
                    type="button"
                    class="menu-parent <?= $tasksOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('tasks')"
                    data-group="tasks"
                    data-title="وظایف">
                    <i class="fas fa-tasks menu-icon"></i>
                    <span class="menu-text">وظایف</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $tasksOpen ? 'is-open' : '' ?>" data-group="tasks">
                    <div class="submenu-inner">
                        <a href="../tasks/index.php?page=list" class="<?= $page === 'list' ? 'active' : '' ?>">
                            <i class="fas fa-list"></i>
                            <span>لیست وظیفه‌ها</span>
                        </a>
                        <a href="../tasks/index.php?page=subjects" class="<?= $page === 'subjects' ? 'active' : '' ?>">
                            <i class="fas fa-tag"></i>
                            <span>موضوع‌ها</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="tickets">
                <button
                    type="button"
                    class="menu-parent <?= $ticketsOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('tickets')"
                    data-group="tickets"
                    data-title="تیکت‌ها">
                    <i class="fas fa-ticket-alt menu-icon"></i>
                    <span class="menu-text">تیکت‌ها</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $ticketsOpen ? 'is-open' : '' ?>" data-group="tickets">
                    <div class="submenu-inner">
                        <a href="../ticket/index.php" class="<?= $page === 'tickets' ? 'active' : '' ?>">
                            <i class="fas fa-list"></i>
                            <span>لیست تیکت‌ها</span>
                        </a>
                        <a href="../texts/index.php" class="<?= $page === 'texts' ? 'active' : '' ?>">
                            <i class="fa-solid fa-text-height"></i>
                            <span>متن آماده</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="call_requests">
                <button
                    type="button"
                    class="menu-parent <?= $callOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('call_requests')"
                    data-group="call_requests"
                    data-title="درخواست تماس">
                    <i class="fas fa-phone-volume menu-icon"></i>
                    <span class="menu-text">درخواست تماس</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $callOpen ? 'is-open' : '' ?>" data-group="call_requests">
                    <div class="submenu-inner">
                        <a href="../call_requests/index.php" class="<?= $page === 'call_requests' ? 'active' : '' ?>">
                            <i class="fas fa-list"></i>
                            <span>لیست درخواست‌ها</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="colleagues">
                <button
                    type="button"
                    class="menu-parent <?= $colleaguesOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('colleagues')"
                    data-group="colleagues"
                    data-title="همکاران">
                    <i class="fas fa-users menu-icon"></i>
                    <span class="menu-text">همکاران</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $colleaguesOpen ? 'is-open' : '' ?>" data-group="colleagues">
                    <div class="submenu-inner">
                        <a href="../colleagues/index.php" class="<?= $page === 'colleagues' ? 'active' : '' ?>">
                            <i class="fas fa-user-friends"></i>
                            <span>لیست همکاران</span>
                        </a>
                        <a href="../project/" class="<?= $page === 'projects' ? 'active' : '' ?>">
                            <i class="fas fa-diagram-project"></i>
                            <span>پروژه‌ها</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="chat">
                <button
                    type="button"
                    class="menu-parent <?= $chatOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('chat')"
                    data-group="chat"
                    data-title="گفتگو">

                    <i class="fas fa-comments menu-icon"></i>

                    <span class="menu-text">گفتگو</span>

                    <span class="badge-unread <?= (empty($sidebarUnread) || $sidebarUnread <= 0) ? 'is-empty' : '' ?>"
                          id="sidebarChatBadge"
                          data-count="<?= (int)$sidebarUnread ?>">
                        <?= (int)$sidebarUnread ?>
                    </span>

                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $chatOpen ? 'is-open' : '' ?>" data-group="chat">
                    <div class="submenu-inner">
                        <a href="../chat/index.php" class="<?= $page === 'chat' ? 'active' : '' ?>">
                            <i class="fas fa-comments"></i>
                            <span>گفتگوها</span>
                        </a>
                    </div>
                </div>
            </div>


            <div class="menu-group" data-group="webservice">
                <button
                    type="button"
                    class="menu-parent <?= $webserviceOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('webservice')"
                    data-group="webservice"
                    data-title="وب سرویس">
                    <i class="fas fa-globe menu-icon"></i>
                    <span class="menu-text">وب سرویس</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $webserviceOpen ? 'is-open' : '' ?>" data-group="webservice">
                    <div class="submenu-inner">
                        <a href="../token" class="<?= $page === 'webservice' ? 'active' : '' ?>">
                            <i class="fas fa-key"></i>
                            <span>مدیریت توکن</span>
                        </a>
                        <a href="../docs">
                            <i class="fas fa-code"></i>
                            <span>مستندات وب سرویس</span>
                        </a>
                    </div>
                </div>
            </div>


            <!-- ============ گروه پروفایل (شامل تنظیمات و پشتیبانی) ============ -->
            <div class="menu-group" data-group="profile">
                <button
                    type="button"
                    class="menu-parent <?= $profileOpen ? 'is-open is-current' : '' ?>"
                    onclick="toggleSidebarGroup('profile')"
                    data-group="profile"
                    data-title="پروفایل">
                    <i class="fas fa-user menu-icon"></i>
                    <span class="menu-text">پروفایل</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </button>
                <div class="submenu-collapsible <?= $profileOpen ? 'is-open' : '' ?>" data-group="profile">
                    <div class="submenu-inner">
                        <a href="../profile" class="<?= $page === 'profile' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i>
                            <span>تنظیمات</span>
                        </a>
                        <!-- ⭐ آیتم جدید: پشتیبانی -->
                        <a href="../support/index.php" class="<?= $page === 'support' ? 'active' : '' ?>">
                            <i class="fas fa-headset"></i>
                            <span>پشتیبانی</span>
                        </a>
                    </div>
                </div>
            </div>


            <a href="../auth/login.php?logout=1" class="menu-item" data-title="خروج از سامانه">
                <i class="fas fa-sign-out-alt"></i>
                <span>خروج از سامانه</span>
            </a>

        </div>

    </div>


    <script>

        function isMobileViewport() {
            return window.matchMedia('(max-width: 768px)').matches;
        }


        if (typeof window.toggleSidebarGroup !== 'function') {

            window.toggleSidebarGroup = function(groupName) {

                const sidebar = document.getElementById('appSidebar');

                if (sidebar && sidebar.classList.contains('is-collapsed') && !isMobileViewport()) return;

                const clickedParent = document.querySelector('.menu-parent[data-group="' + groupName + '"]');
                const clickedSubmenu = document.querySelector('.submenu-collapsible[data-group="' + groupName + '"]');

                if (!clickedParent || !clickedSubmenu) return;

                const isAlreadyOpen = clickedSubmenu.classList.contains('is-open');

                document.querySelectorAll('.menu-parent.is-open').forEach(function(parent) {
                    if (parent !== clickedParent) parent.classList.remove('is-open');
                });

                document.querySelectorAll('.submenu-collapsible.is-open').forEach(function(submenu) {
                    if (submenu !== clickedSubmenu) submenu.classList.remove('is-open');
                });

                if (isAlreadyOpen) {
                    clickedParent.classList.remove('is-open');
                    clickedSubmenu.classList.remove('is-open');
                } else {
                    clickedParent.classList.add('is-open');
                    clickedSubmenu.classList.add('is-open');
                }

            };

        }


        if (typeof window.toggleSidebarCollapse !== 'function') {

            window.toggleSidebarCollapse = function() {

                if (isMobileViewport()) return;

                const sidebar = document.getElementById('appSidebar');
                if (!sidebar) return;

                sidebar.classList.toggle('is-collapsed');

                if (sidebar.classList.contains('is-collapsed')) {
                    document.querySelectorAll('.submenu-collapsible.is-flyout-open').forEach(function(el) {
                        el.classList.remove('is-flyout-open');
                    });
                }

                try {
                    const isCollapsed = sidebar.classList.contains('is-collapsed');
                    localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
                } catch (e) {}

            };

        }


        /* =========================================================
           ✨ FLYOUT HOVER LOGIC
        ========================================================= */

        (function initFlyoutHoverLogic() {

            const LINGER_MS = 400;

            if (isMobileViewport()) return;

            const sidebar = document.getElementById('appSidebar');
            if (!sidebar) return;

            const menuGroups = sidebar.querySelectorAll('.menu-group');
            if (!menuGroups.length) return;

            const timers = new WeakMap();

            function openFlyout(group) {

                const flyout = group.querySelector('.submenu-collapsible');
                if (!flyout) return;

                const t = timers.get(group);
                if (t) {
                    clearTimeout(t);
                    timers.delete(group);
                }

                menuGroups.forEach(function(otherGroup) {
                    if (otherGroup === group) return;

                    const otherFlyout = otherGroup.querySelector('.submenu-collapsible');
                    const otherTimer = timers.get(otherGroup);

                    if (otherTimer) {
                        clearTimeout(otherTimer);
                        timers.delete(otherGroup);
                    }

                    if (otherFlyout) {
                        otherFlyout.classList.remove('is-flyout-open');
                    }
                });

                flyout.classList.add('is-flyout-open');
            }


            function scheduleClose(group) {

                const flyout = group.querySelector('.submenu-collapsible');
                if (!flyout) return;

                const existing = timers.get(group);
                if (existing) clearTimeout(existing);

                const timer = setTimeout(function() {
                    flyout.classList.remove('is-flyout-open');
                    timers.delete(group);
                }, LINGER_MS);

                timers.set(group, timer);
            }


            menuGroups.forEach(function(group) {

                group.addEventListener('mouseenter', function() {
                    if (!sidebar.classList.contains('is-collapsed')) return;
                    openFlyout(group);
                });

                group.addEventListener('mouseleave', function() {
                    if (!sidebar.classList.contains('is-collapsed')) return;
                    scheduleClose(group);
                });

            });


            const observer = new MutationObserver(function(mutations) {

                mutations.forEach(function(m) {

                    if (m.attributeName === 'class') {

                        if (!sidebar.classList.contains('is-collapsed')) {

                            menuGroups.forEach(function(group) {
                                const t = timers.get(group);
                                if (t) {
                                    clearTimeout(t);
                                    timers.delete(group);
                                }

                                const flyout = group.querySelector('.submenu-collapsible');
                                if (flyout) flyout.classList.remove('is-flyout-open');
                            });
                        }
                    }
                });
            });

            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

        })();


        (function initSidebarState() {
            const sidebar = document.getElementById('appSidebar');
            if (!sidebar) return;

            if (isMobileViewport()) {
                sidebar.classList.remove('is-collapsed');
                return;
            }

            try {
                const saved = localStorage.getItem('sidebarCollapsed');
                if (saved === '1') sidebar.classList.add('is-collapsed');
            } catch (e) {}
        })();


        (function watchViewportChanges() {
            let wasMobile = isMobileViewport();

            window.addEventListener('resize', function() {
                const isMobileNow = isMobileViewport();
                const sidebar = document.getElementById('appSidebar');
                if (!sidebar) return;

                if (!wasMobile && isMobileNow) {
                    sidebar.classList.remove('is-collapsed');
                }

                if (wasMobile && !isMobileNow) {
                    try {
                        const saved = localStorage.getItem('sidebarCollapsed');
                        if (saved === '1') sidebar.classList.add('is-collapsed');
                    } catch (e) {}
                }

                wasMobile = isMobileNow;
            });
        })();


        /* =========================================================
           ⭐ AJAX: به‌روزرسانی خودکار badge پیام‌های خوانده‌نشده
        ========================================================= */

        (function initSidebarUnreadPolling() {

            const POLL_INTERVAL_MS = 15000; // هر ۱۵ ثانیه
            let pollingTimer = null;
            let requestInFlight = false;

            const ENDPOINT = '../chat/api.php?action=total_unread';

       function updateBadge(count) {
    const badge = document.getElementById('sidebarChatBadge');
    if (!badge) return;

    const n = parseInt(count, 10);
    const safeCount = isNaN(n) ? 0 : Math.max(0, n);

    badge.setAttribute('data-count', String(safeCount));

    if (safeCount > 0) {
        // ⭐ عدد انگلیسی — بدون تبدیل به فارسی
        const display = safeCount > 99 ? '99+' : String(safeCount);
        badge.textContent = display;
        badge.classList.remove('is-empty');

        // ⭐ بازگرداندن اجباری استایل‌ها (به‌خاطر اطمینان از اینکه CSS override نمی‌شه)
        badge.style.fontFamily = "'Segoe UI', Tahoma, Arial, sans-serif";
        badge.style.fontWeight = '700';
        badge.style.color = '#1e293b';
        badge.style.background = '#ffffff';
        badge.style.direction = 'ltr';
        badge.style.fontVariantNumeric = 'tabular-nums';
    } else {
        badge.textContent = '0';
        badge.classList.add('is-empty');
    }
}

            function fetchUnreadCount() {
                if (requestInFlight) return;
                requestInFlight = true;

                fetch(ENDPOINT, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                .then(r => r.text())
                .then(text => {
                    let data;
                    try { data = JSON.parse(text); }
                    catch (e) { return; }
                    if (data && data.status === 'success' && typeof data.total !== 'undefined') {
                        updateBadge(data.total);
                    }
                })
                .catch(() => {})
                .finally(() => {
                    requestInFlight = false;
                });
            }

            // اجرای اولیه پس از ۲ ثانیه
            setTimeout(fetchUnreadCount, 2000);

            // شروع polling
            pollingTimer = setInterval(fetchUnreadCount, POLL_INTERVAL_MS);

            // به‌روزرسانی اضافی وقتی تب دوباره فعال می‌شود
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) fetchUnreadCount();
            });

            // به‌روزرسانی فوری وقتی یک پیام خوانده می‌شود (از صفحه چت)
            window.addEventListener('message', function(e) {
                if (e.data && e.data.type === 'sidebar-unread-update') {
                    updateBadge(e.data.count);
                }
            });

            // expoz به‌عنوان تابع عمومی
            window.refreshSidebarUnread = fetchUnreadCount;

        })();

    </script>

    <?php
}