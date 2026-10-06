<?php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$apiBase = $scheme . '://' . $host . $base . '/api/v1';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>راهنمای استفاده از API</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
        font-family: Tahoma, Arial, sans-serif;
        background: #ffffff;
        color: #1f2937;
        line-height: 1.9;
        font-size: 15px;
    }

    /* ============ TOPBAR ============ */
    .topbar {
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        padding: 14px 40px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 24px;
        position: sticky;
        top: 0;
        z-index: 100;
    }

    .topbar-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 15px;
        font-weight: bold;
        color: #1f2937;
        text-decoration: none;
    }

    .topbar-brand .brand-icon {
        width: 36px;
        height: 36px;
        background: #1e88e5;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 16px;
    }

    .topbar-search {
        flex: 1;
        max-width: 480px;
        position: relative;
    }

    .topbar-search input {
        width: 100%;
        padding: 10px 16px 10px 42px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        font-family: Tahoma, sans-serif;
        font-size: 13.5px;
        background: #f9fafb;
        color: #1f2937;
        transition: all 0.15s;
    }

    .topbar-search input::placeholder { color: #9ca3af; }

    .topbar-search input:focus {
        outline: none;
        border-color: #1e88e5;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.08);
    }

    .topbar-search i {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 13px;
    }

    .topbar-actions {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .btn-login {
        background: #1e88e5;
        color: #fff;
        border: none;
        padding: 9px 20px;
        border-radius: 10px;
        font-family: Tahoma, sans-serif;
        font-size: 13.5px;
        font-weight: bold;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: background-color 0.15s;
    }

    .btn-login:hover { background: #1565c0; }

    .btn-icon {
        width: 40px;
        height: 40px;
        border: 1px solid #e5e7eb;
        background: #fff;
        border-radius: 10px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
        font-size: 14px;
        transition: all 0.15s;
    }

    .btn-icon:hover {
        background: #f9fafb;
        color: #1e88e5;
        border-color: #1e88e5;
    }

    /* ============ LAYOUT ============ */
    .container {
        display: grid;
        grid-template-columns: 300px 1fr;
        max-width: 1400px;
        margin: 0 auto;
        padding: 40px 40px;
        gap: 60px;
        direction: rtl;
    }

    /* ============ SIDEBAR ============ */
    .sidebar {
        position: sticky;
        top: 90px;
        align-self: flex-start;
        max-height: calc(100vh - 110px);
        overflow-y: auto;
        padding-left: 20px;
        border-left: 1px solid #f3f4f6;
    }

    .sidebar::-webkit-scrollbar { width: 5px; }
    .sidebar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 3px; }

    .sidebar-badge {
        display: inline-block;
        background: #1f2937;
        color: #fff;
        padding: 9px 16px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: bold;
        margin-bottom: 16px;
    }

    .sidebar-back {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #6b7280;
        text-decoration: none;
        font-size: 13px;
        margin-bottom: 20px;
        padding: 6px 0;
        justify-content: flex-end;
    }

    .sidebar-back:hover { color: #1e88e5; }
    .sidebar-back i { font-size: 11px; }

    .sidebar-divider {
        height: 1px;
        background: #f3f4f6;
        margin: 16px 0;
    }

    .sidebar-heading {
        font-size: 11px;
        font-weight: bold;
        color: #9ca3af;
        padding: 10px 0 8px;
        letter-spacing: 0.3px;
        text-align: right;
    }

    .sidebar-item {
        display: block;
        padding: 9px 14px;
        color: #4b5563;
        text-decoration: none;
        font-size: 13.5px;
        border-radius: 8px;
        transition: all 0.15s;
        text-align: right;
        margin-bottom: 2px;
        position: relative;
    }

    .sidebar-item:hover {
        background: #f9fafb;
        color: #1e88e5;
    }

    .sidebar-item.active {
        background: #f0f7ff;
        color: #1565c0;
        font-weight: bold;
    }

    .sidebar-item.active::before {
        content: '';
        position: absolute;
        right: 0;
        top: 25%;
        bottom: 25%;
        width: 3px;
        background: #1e88e5;
        border-radius: 3px 0 0 3px;
    }

    /* ============ MAIN ============ */
    .main { min-width: 0; }

    .main-title {
        font-size: 34px;
        font-weight: bold;
        color: #111827;
        margin-bottom: 28px;
        text-align: center;
        letter-spacing: -0.5px;
    }

    .main-intro {
        text-align: center;
        font-size: 14.5px;
        color: #4b5563;
        line-height: 2.1;
        margin-bottom: 50px;
    }

    .main-intro p { margin-bottom: 18px; }

    .section-title {
        font-size: 26px;
        font-weight: bold;
        color: #111827;
        margin: 55px 0 22px 0;
        text-align: center;
        letter-spacing: -0.3px;
        padding-top: 20px;
        border-top: 1px solid #f3f4f6;
        scroll-margin-top: 100px;
    }

    .section-title:first-of-type {
        border-top: none;
        padding-top: 0;
        margin-top: 0;
    }

    .section-intro {
        text-align: center;
        font-size: 14.5px;
        color: #4b5563;
        margin-bottom: 28px;
        line-height: 2;
    }

    /* ============ STEPS ============ */
    .steps {
        max-width: 700px;
        margin: 32px auto;
        display: flex;
        flex-direction: column;
        gap: 6px;
        position: relative;
    }

    .steps::before {
        content: '';
        position: absolute;
        top: 22px;
        bottom: 22px;
        right: 19px;
        width: 1px;
        background: #e5e7eb;
        z-index: 0;
    }

    .step {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px 0;
        position: relative;
        z-index: 1;
    }

    .step-num {
        width: 38px;
        height: 38px;
        background: #fff;
        color: #1e88e5;
        border: 1.5px solid #e5e7eb;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
        flex-shrink: 0;
        transition: all 0.15s;
    }

    .step:hover .step-num {
        border-color: #1e88e5;
        background: #f0f7ff;
    }

    .step-text {
        font-size: 14.5px;
        color: #1f2937;
    }

    /* ============ CODE BLOCK ============ */
    .code-block {
        background: #1a2332;
        border-radius: 12px;
        overflow: hidden;
        margin: 18px 0;
        border: 1px solid #232d3d;
    }

    .code-header {
        background: #232d3d;
        padding: 9px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 11.5px;
        color: #7d8a9e;
        font-family: Tahoma, sans-serif;
        direction: ltr;
    }

    .code-header .dots {
        display: flex;
        gap: 6px;
    }

    .code-header .dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        opacity: 0.55;
    }

    .dot.red { background: #d16a6a; }
    .dot.yellow { background: #d1b26a; }
    .dot.green { background: #6ac29a; }

    .code-body {
        padding: 16px 20px;
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #cbd5e1;
        direction: ltr;
        text-align: left;
        overflow-x: auto;
        line-height: 1.85;
        white-space: pre;
    }

    .code-body .hdr { color: #94a3b8; font-weight: bold; }
    .code-body .val { color: #a8b8d0; }
    .code-body .key { color: #7fa8d0; }
    .code-body .num { color: #c4a97d; }
    .code-body .cmt { color: #5a6875; font-style: italic; }
    .code-body .str { color: #94b8a8; }
    .code-body .bool { color: #b39b9b; }

    /* ============ ENDPOINT CARD ============ */
    .endpoint {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 22px;
        margin: 22px 0;
        transition: border-color 0.15s;
        scroll-margin-top: 100px;
    }

    .endpoint:hover { border-color: #d1d5db; }

    .endpoint-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f3f4f6;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    .method {
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: bold;
        letter-spacing: 0.3px;
        font-family: 'Courier New', monospace;
        border: 1px solid;
    }

    .method.get {
        background: #f0f7f3;
        color: #3f7a5c;
        border-color: #d3e6dc;
    }

    .method.post {
        background: #f4f1fb;
        color: #6a5a94;
        border-color: #ddd5ee;
    }

    .endpoint-url {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        color: #4b5563;
        direction: ltr;
        text-align: left;
        background: #f9fafb;
        padding: 6px 12px;
        border-radius: 6px;
        flex: 1;
        min-width: 200px;
        border: 1px solid #f3f4f6;
    }

    .endpoint-desc {
        font-size: 14px;
        color: #6b7280;
        line-height: 1.9;
        margin-bottom: 14px;
    }

    /* ============ TABLE ============ */
    .params-table {
        width: 100%;
        border-collapse: collapse;
        margin: 14px 0;
        font-size: 13px;
        border: 1px solid #f3f4f6;
        border-radius: 10px;
        overflow: hidden;
    }

    .params-table thead { background: #f9fafb; }

    .params-table th {
        padding: 11px 14px;
        text-align: right;
        font-weight: bold;
        color: #374151;
        font-size: 12.5px;
        border-bottom: 1px solid #f3f4f6;
    }

    .params-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f9fafb;
        color: #4b5563;
    }

    .params-table tr:last-child td { border-bottom: none; }
    .params-table tr:hover { background: #fafbfc; }

    .param-name {
        font-family: 'Courier New', monospace;
        font-weight: bold;
        color: #3b6ea5;
        direction: ltr;
    }

    .param-name .star {
        color: #b85450;
        margin-right: 3px;
    }

    .param-type {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 5px;
        font-size: 11px;
        font-weight: bold;
        font-family: 'Courier New', monospace;
        background: #f3f4f6;
        color: #6b7280;
    }

    .param-type.int { background: #f3f1fa; color: #6a5a94; }
    .param-type.enum { background: #f5f3ee; color: #8a7656; }
    .param-type.date { background: #faf1f3; color: #8a5f6b; }
    .param-type.str { background: #eef4f8; color: #4a6f8a; }
    .param-type.array { background: #f0f7f3; color: #3f7a5c; }

    .sub-title {
        font-size: 12.5px;
        font-weight: bold;
        color: #6b7280;
        margin: 20px 0 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .sub-title i { color: #9ca3af; font-size: 12px; }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 1000px) {
        .container {
            grid-template-columns: 1fr;
            padding: 24px 20px;
            gap: 20px;
        }

        .sidebar {
            position: static;
            max-height: none;
            border-left: none;
            border-bottom: 1px solid #f3f4f6;
            padding-left: 0;
            padding-bottom: 16px;
            order: -1;
        }

        .main-title { font-size: 26px; }
        .section-title { font-size: 20px; }

        .topbar {
            padding: 12px 16px;
            flex-wrap: wrap;
        }

        .topbar-search {
            order: 3;
            width: 100%;
            max-width: none;
        }

        .endpoint-header {
            flex-direction: column;
            align-items: stretch;
        }

        .endpoint-url { width: 100%; }
    }
</style>
</head>
<body>

    <!-- ============ TOPBAR ============ -->
    <header class="topbar">
        <a href="#" class="topbar-brand">
            <div class="brand-icon">
                <i class="fas fa-cube"></i>
            </div>
            <div>
                <div style="line-height: 1.2;">سامانه تسک</div>
                <div style="font-size: 11px; color: #9ca3af; font-weight: normal; line-height: 1.2; margin-top: 2px;">Task Manager</div>
            </div>
        </a>

        <div class="topbar-search">
            <input type="text" placeholder="جستجو کنید">
            <i class="fas fa-search"></i>
        </div>

        <div class="topbar-actions">
            <button class="btn-icon" title="تغییر تم">
                <i class="fas fa-moon"></i>
            </button>
            <a href="#" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                <span>ورود به پنل</span>
            </a>
        </div>
    </header>

    <!-- ============ LAYOUT ============ -->
    <div class="container">

        <!-- ============ SIDEBAR ============ -->
        <aside class="sidebar">

            <div class="sidebar-badge">مستندات توسعه‌دهندگان</div>

            <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/tasks/index.php" class="sidebar-back">
                <span>بازگشت</span>
                <i class="fas fa-arrow-left"></i>
            </a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">API</div>
            <a href="#top" class="sidebar-item active">دستورالعمل API</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">توابع تسک‌ها</div>
            <a href="#tasks-list"   class="sidebar-item">لیست تسک‌ها</a>
            <a href="#tasks-show"   class="sidebar-item">جزئیات تسک</a>
            <a href="#tasks-stats"  class="sidebar-item">آمار تسک‌ها</a>
            <a href="#tasks-create" class="sidebar-item">ایجاد تسک</a>
            <a href="#tasks-update" class="sidebar-item">ویرایش تسک</a>
            <a href="#tasks-delete" class="sidebar-item">حذف تسک</a>
            <a href="#tasks-toggle" class="sidebar-item">تغییر وضعیت تسک</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">درخواست‌های تماس</div>
            <a href="#calls-list"   class="sidebar-item">لیست درخواست‌ها</a>
            <a href="#calls-show"   class="sidebar-item">جزئیات درخواست</a>
            <a href="#calls-stats"  class="sidebar-item">آمار درخواست‌ها</a>
            <a href="#calls-create" class="sidebar-item">ایجاد درخواست</a>
            <a href="#calls-update" class="sidebar-item">ویرایش درخواست</a>
            <a href="#calls-status" class="sidebar-item">تغییر وضعیت</a>
            <a href="#calls-delete" class="sidebar-item">حذف درخواست</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">تیکت‌ها</div>
            <a href="#tickets-list"   class="sidebar-item">لیست تیکت‌ها</a>
            <a href="#tickets-show"   class="sidebar-item">جزئیات تیکت</a>
            <a href="#tickets-stats"  class="sidebar-item">آمار تیکت‌ها</a>
            <a href="#tickets-create" class="sidebar-item">ایجاد تیکت</a>
            <a href="#tickets-update" class="sidebar-item">ویرایش تیکت</a>
            <a href="#tickets-status" class="sidebar-item">تغییر وضعیت</a>
            <a href="#tickets-delete" class="sidebar-item">حذف تیکت</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">متن‌های آماده</div>
            <a href="#texts-list"   class="sidebar-item">لیست متن‌ها</a>
            <a href="#texts-show"   class="sidebar-item">جزئیات متن</a>
            <a href="#texts-stats"  class="sidebar-item">آمار متن‌ها</a>
            <a href="#texts-create" class="sidebar-item">ایجاد متن</a>
            <a href="#texts-update" class="sidebar-item">ویرایش متن</a>
            <a href="#texts-delete" class="sidebar-item">حذف متن</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">پروژه‌ها</div>
            <a href="#projects-list"               class="sidebar-item">لیست پروژه‌ها</a>
            <a href="#projects-show"               class="sidebar-item">جزئیات پروژه</a>
            <a href="#projects-stats"              class="sidebar-item">آمار پروژه‌ها</a>
            <a href="#projects-members"            class="sidebar-item">لیست اعضا</a>
            <a href="#projects-create"             class="sidebar-item">ایجاد پروژه</a>
            <a href="#projects-update"             class="sidebar-item">ویرایش پروژه</a>
            <a href="#projects-delete"             class="sidebar-item">حذف پروژه</a>
            <a href="#projects-member-add"         class="sidebar-item">افزودن عضو</a>
            <a href="#projects-member-remove"      class="sidebar-item">حذف عضو</a>
            <a href="#projects-member-permissions" class="sidebar-item">ویرایش دسترسی عضو</a>
            <a href="#projects-leave"              class="sidebar-item">خروج از پروژه</a>
            <a href="#projects-block"              class="sidebar-item">مسدود کردن پروژه</a>

            <div class="sidebar-divider"></div>

            <div class="sidebar-heading">همکاران</div>
            <a href="#colleagues-list"        class="sidebar-item">لیست همکاران</a>
            <a href="#colleagues-followers"   class="sidebar-item">دنبال‌کنندگان من</a>
            <a href="#colleagues-blocked"     class="sidebar-item">مسدودشده‌ها</a>
            <a href="#colleagues-show"        class="sidebar-item">جزئیات همکار</a>
            <a href="#colleagues-stats"       class="sidebar-item">آمار همکاران</a>
            <a href="#colleagues-search"      class="sidebar-item">جستجوی کاربر</a>
            <a href="#colleagues-create"      class="sidebar-item">افزودن همکار</a>
            <a href="#colleagues-permissions" class="sidebar-item">ویرایش دسترسی‌ها</a>
            <a href="#colleagues-block"       class="sidebar-item">مسدود کردن</a>
            <a href="#colleagues-unblock"     class="sidebar-item">رفع مسدودی</a>
            <a href="#colleagues-delete"      class="sidebar-item">حذف همکار</a>

        </aside>

        <!-- ============ MAIN CONTENT ============ -->
        <main class="main" id="top">

            <h1 class="main-title">راهنمای استفاده از API</h1>

            <div class="main-intro">
                <p>
                    API سامانه تسک به شما این امکان را می‌دهد که مدیریت ساده‌تری روی تسک‌ها، پروژه‌ها، موضوعات،
                    درخواست‌های تماس، تیکت‌ها، متن‌های آماده و همکاران خود داشته باشید. از طریق این API می‌توانید
                    داده‌ها را ایجاد، ویرایش، حذف و وضعیت آن‌ها را تغییر دهید.
                </p>
                <p>
                    برای استفاده از API، ابتدا باید احراز هویت انجام دهید و سپس درخواست‌های HTTP را به Endpoint مشخص‌شده ارسال کنید.
                </p>
            </div>

            <!-- ============ AUTH ============ -->
            <h2 class="section-title">احراز هویت</h2>

            <p class="section-intro">
                تمامی درخواست‌ها به API باید شامل یک توکن احراز هویت باشند. برای دریافت توکن زیر را طی کنید:
            </p>

            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-text">وارد پنل کاربری سامانه تسک شوید.</div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-text">از منوی سمت راست به بخش «توکن‌های API» بروید.</div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-text">روی دکمه «ساخت توکن» کلیک کنید و نامی برای آن انتخاب کنید.</div>
                </div>
                <div class="step">
                    <div class="step-num">4</div>
                    <div class="step-text">توکن ساخته‌شده را کپی کرده و در جای امنی ذخیره کنید.</div>
                </div>
            </div>

            <p class="section-intro" style="margin-top: 34px;">
                توکن باید در تمامی درخواست‌ها در بخش Header به شکل زیر ارسال شود:
            </p>

            <div class="code-block">
                <div class="code-header">
                    <div class="dots">
                        <span class="dot red"></span>
                        <span class="dot yellow"></span>
                        <span class="dot green"></span>
                    </div>
                    <span>Headers</span>
                </div>
                <div class="code-body"><span class="hdr">Authorization:</span> <span class="val">Bearer tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</span>
<span class="hdr">Content-Type:</span>  <span class="val">application/json</span>
<span class="hdr">Accept:</span>        <span class="val">application/json</span></div>
            </div>

            <!-- ============ BASE URL ============ -->
            <h2 class="section-title">آدرس پایه</h2>

            <p class="section-intro">همه درخواست‌ها به این آدرس پایه ارسال می‌شوند:</p>

            <div class="code-block">
                <div class="code-header">
                    <div class="dots">
                        <span class="dot red"></span>
                        <span class="dot yellow"></span>
                        <span class="dot green"></span>
                    </div>
                    <span>Base URL</span>
                </div>
                <div class="code-body"><span class="val"><?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?></span></div>
            </div>

            <!-- ===================================================== -->
            <!-- ==================== توابع تسک‌ها ==================== -->
            <!-- ===================================================== -->
            <h2 class="section-title">توابع تسک‌ها</h2>

            <p class="section-intro">در ادامه تمامی توابع مربوط به مدیریت تسک‌ها ارائه شده است.</p>

            <!-- === tasks/list === -->
            <div class="endpoint" id="tasks-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tasks/list/</div>
                </div>
                <p class="endpoint-desc">لیست تمام تسک‌هایی که کاربر سازنده یا مسئول آن‌هاست را برمی‌گرداند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">status</span></td><td><span class="param-type enum">enum</span></td><td>all / pending / completed</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج (پیش‌فرض: 100، حداکثر: 200)</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع (پیش‌فرض: 0)</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/tasks/list/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === tasks/show === -->
            <div class="endpoint" id="tasks-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tasks/show/?id=N</div>
                </div>
                <p class="endpoint-desc">اطلاعات کامل یک تسک به همراه یادداشت‌های آن را برمی‌گرداند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه تسک</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/tasks/show/?id=27"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === tasks/stats === -->
            <div class="endpoint" id="tasks-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tasks/stats/</div>
                </div>
                <p class="endpoint-desc">آمار کلی تسک‌های کاربر شامل تعداد کل، انجام‌شده، در انتظار و توزیع اولویت‌ها.</p>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/tasks/stats/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === tasks/create === -->
            <div class="endpoint" id="tasks-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tasks/create/</div>
                </div>
                <p class="endpoint-desc">یک تسک جدید ایجاد می‌کند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">title<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>عنوان تسک</td></tr>
                        <tr><td><span class="param-name">description</span></td><td><span class="param-type str">str</span></td><td>توضیحات</td></tr>
                        <tr><td><span class="param-name">priority</span></td><td><span class="param-type enum">enum</span></td><td>low / medium / high</td></tr>
                        <tr><td><span class="param-name">subject_id</span></td><td><span class="param-type int">int</span></td><td>شناسه موضوع</td></tr>
                        <tr><td><span class="param-name">project_id</span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                        <tr><td><span class="param-name">assignee_id</span></td><td><span class="param-type int">int</span></td><td>شناسه مسئول</td></tr>
                        <tr><td><span class="param-name">due_date</span></td><td><span class="param-type date">date</span></td><td>تاریخ سررسید</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/tasks/create/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{ <span class="key">"title"</span>: <span class="str">"بررسی تیکت"</span>, <span class="key">"priority"</span>: <span class="str">"high"</span> }'</div>
                </div>
            </div>

            <!-- === tasks/update === -->
            <div class="endpoint" id="tasks-update">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tasks/update/?id=N</div>
                </div>
                <p class="endpoint-desc">یک تسک موجود را ویرایش می‌کند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه تسک (query string)</td></tr>
                        <tr><td><span class="param-name">title</span></td><td><span class="param-type str">str</span></td><td>عنوان جدید</td></tr>
                        <tr><td><span class="param-name">priority</span></td><td><span class="param-type enum">enum</span></td><td>low / medium / high</td></tr>
                        <tr><td><span class="param-name">assignee_id</span></td><td><span class="param-type int">int</span></td><td>شناسه مسئول</td></tr>
                        <tr><td><span class="param-name">due_date</span></td><td><span class="param-type date">date</span></td><td>تاریخ سررسید</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === tasks/delete === -->
            <div class="endpoint" id="tasks-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tasks/delete/?id=N</div>
                </div>
                <p class="endpoint-desc">یک تسک و تمام یادداشت‌های مرتبط را حذف می‌کند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه تسک</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === tasks/toggle === -->
            <div class="endpoint" id="tasks-toggle">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tasks/toggle/?id=N</div>
                </div>
                <p class="endpoint-desc">وضعیت تکمیل تسک را تغییر می‌دهد.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه تسک</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===================================================== -->
            <!-- ============ درخواست‌های تماس ====================== -->
            <!-- ===================================================== -->
            <h2 class="section-title">درخواست‌های تماس</h2>

            <p class="section-intro">توابع مربوط به مدیریت درخواست‌های تماس.</p>

            <!-- === calls/list === -->
            <div class="endpoint" id="calls-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/calls/list/</div>
                </div>
                <p class="endpoint-desc">لیست درخواست‌های تماس را برمی‌گرداند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">status</span></td><td><span class="param-type enum">enum</span></td><td>all / new / done</td></tr>
                        <tr><td><span class="param-name">assignee</span></td><td><span class="param-type enum">enum</span></td><td>all / me</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === calls/show === -->
            <div class="endpoint" id="calls-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/calls/show/?id=N</div>
                </div>
                <p class="endpoint-desc">جزئیات یک درخواست تماس.</p>
            </div>

            <!-- === calls/stats === -->
            <div class="endpoint" id="calls-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/calls/stats/</div>
                </div>
                <p class="endpoint-desc">آمار کلی درخواست‌های تماس کاربر.</p>
            </div>

            <!-- === calls/create === -->
            <div class="endpoint" id="calls-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/calls/create/</div>
                </div>
                <p class="endpoint-desc">ایجاد یک درخواست تماس جدید.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">first_name<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>نام</td></tr>
                        <tr><td><span class="param-name">last_name<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>نام خانوادگی</td></tr>
                        <tr><td><span class="param-name">mobile<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>شماره موبایل (09xxxxxxxxx)</td></tr>
                        <tr><td><span class="param-name">email</span></td><td><span class="param-type str">str</span></td><td>ایمیل</td></tr>
                        <tr><td><span class="param-name">store</span></td><td><span class="param-type str">str</span></td><td>نام فروشگاه</td></tr>
                        <tr><td><span class="param-name">website</span></td><td><span class="param-type str">str</span></td><td>وبسایت</td></tr>
                        <tr><td><span class="param-name">assignee_id</span></td><td><span class="param-type int">int</span></td><td>شناسه مسئول</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === calls/update === -->
            <div class="endpoint" id="calls-update">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/calls/update/?id=N</div>
                </div>
                <p class="endpoint-desc">ویرایش یک درخواست تماس.</p>
            </div>

            <!-- === calls/status === -->
            <div class="endpoint" id="calls-status">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/calls/status/?id=N</div>
                </div>
                <p class="endpoint-desc">تغییر وضعیت درخواست (باز ↔ بسته). اگر body با <code style="background:#f3f4f6;padding:1px 6px;border-radius:4px;direction:ltr;display:inline-block;">{"status": 0|1}</code> بفرستی، صریح تنظیم می‌شود؛ وگرنه toggle.</p>
            </div>

            <!-- === calls/delete === -->
            <div class="endpoint" id="calls-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/calls/delete/?id=N</div>
                </div>
                <p class="endpoint-desc">حذف یک درخواست تماس (فقط سازنده).</p>
            </div>

            <!-- ===================================================== -->
            <!-- ==================== تیکت‌ها ========================= -->
            <!-- ===================================================== -->
            <h2 class="section-title">تیکت‌ها</h2>

            <p class="section-intro">توابع مربوط به مدیریت تیکت‌های پشتیبانی. توجه: این تیکت‌ها سراسری هستند.</p>

            <!-- === tickets/list === -->
            <div class="endpoint" id="tickets-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tickets/list/</div>
                </div>
                <p class="endpoint-desc">لیست تیکت‌ها با قابلیت فیلتر بر اساس وضعیت، اولویت، VIP و جستجو.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">status</span></td><td><span class="param-type enum">enum</span></td><td>all / open / done</td></tr>
                        <tr><td><span class="param-name">priority</span></td><td><span class="param-type enum">enum</span></td><td>all / low / medium / high / very_high</td></tr>
                        <tr><td><span class="param-name">vip</span></td><td><span class="param-type int">int</span></td><td>0 یا 1</td></tr>
                        <tr><td><span class="param-name">q</span></td><td><span class="param-type str">str</span></td><td>جستجو در شماره و موضوع</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === tickets/show === -->
            <div class="endpoint" id="tickets-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tickets/show/?id=N</div>
                </div>
                <p class="endpoint-desc">جزئیات یک تیکت به همراه کامنت‌ها.</p>
            </div>

            <!-- === tickets/stats === -->
            <div class="endpoint" id="tickets-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/tickets/stats/</div>
                </div>
                <p class="endpoint-desc">آمار کلی تیکت‌ها (سراسری).</p>
            </div>

            <!-- === tickets/create === -->
            <div class="endpoint" id="tickets-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tickets/create/</div>
                </div>
                <p class="endpoint-desc">ایجاد تیکت جدید با امکان افزودن اولین کامنت.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">ticket_number<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>شماره تیکت</td></tr>
                        <tr><td><span class="param-name">subject</span></td><td><span class="param-type str">str</span></td><td>موضوع</td></tr>
                        <tr><td><span class="param-name">task_link</span></td><td><span class="param-type str">str</span></td><td>لینک تسک</td></tr>
                        <tr><td><span class="param-name">priority</span></td><td><span class="param-type enum">enum</span></td><td>low / medium / high / very_high</td></tr>
                        <tr><td><span class="param-name">text</span></td><td><span class="param-type str">str</span></td><td>متن تیکت</td></tr>
                        <tr><td><span class="param-name">is_vip</span></td><td><span class="param-type int">int</span></td><td>0 یا 1</td></tr>
                        <tr><td><span class="param-name">comment</span></td><td><span class="param-type str">str</span></td><td>اولین کامنت</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === tickets/update === -->
            <div class="endpoint" id="tickets-update">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tickets/update/?id=N</div>
                </div>
                <p class="endpoint-desc">ویرایش یک تیکت.</p>
            </div>

            <!-- === tickets/status === -->
            <div class="endpoint" id="tickets-status">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tickets/status/?id=N</div>
                </div>
                <p class="endpoint-desc">تغییر وضعیت تیکت (باز ↔ بسته).</p>
            </div>

            <!-- === tickets/delete === -->
            <div class="endpoint" id="tickets-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/tickets/delete/?id=N</div>
                </div>
                <p class="endpoint-desc">حذف یک تیکت به همراه کامنت‌ها.</p>
            </div>

            <!-- ===================================================== -->
            <!-- ================ متن‌های آماده ==================== -->
            <!-- ===================================================== -->
            <h2 class="section-title">متن‌های آماده</h2>

            <p class="section-intro">توابع مربوط به مدیریت متن‌های آماده (پاسخ‌های آماده) کاربر.</p>

            <!-- === texts/list === -->
            <div class="endpoint" id="texts-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/texts/list/</div>
                </div>
                <p class="endpoint-desc">لیست متن‌های آماده کاربر با قابلیت جستجو.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">q</span></td><td><span class="param-type str">str</span></td><td>جستجو در عنوان و محتوا</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === texts/show === -->
            <div class="endpoint" id="texts-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/texts/show/?id=XXX</div>
                </div>
                <p class="endpoint-desc">
                    جزئیات یک متن آماده.
                    <br>
                    <strong>نکته:</strong> شناسه متن‌ها از نوع <code style="background:#f3f4f6;padding:1px 6px;border-radius:4px;direction:ltr;display:inline-block;">string</code> است، نه عدد.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>شناسه متن (مثال: <code style="direction:ltr;display:inline-block;">6a7426ce0349d_1994e054</code>)</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === texts/stats === -->
            <div class="endpoint" id="texts-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/texts/stats/</div>
                </div>
                <p class="endpoint-desc">آمار متن‌های آماده کاربر.</p>
            </div>

            <!-- === texts/create === -->
            <div class="endpoint" id="texts-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/texts/create/</div>
                </div>
                <p class="endpoint-desc">ایجاد یک متن آماده جدید. شناسه به‌صورت خودکار تولید می‌شود.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">title<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>عنوان متن</td></tr>
                        <tr><td><span class="param-name">content<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>محتوای متن</td></tr>
                        <tr><td><span class="param-name">sort_order</span></td><td><span class="param-type int">int</span></td><td>ترتیب نمایش (پیش‌فرض: 0)</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === texts/update === -->
            <div class="endpoint" id="texts-update">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/texts/update/?id=XXX</div>
                </div>
                <p class="endpoint-desc">ویرایش یک متن آماده.</p>
            </div>

            <!-- === texts/delete === -->
            <div class="endpoint" id="texts-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/texts/delete/?id=XXX</div>
                </div>
                <p class="endpoint-desc">حذف یک متن آماده.</p>
            </div>

            <!-- ===================================================== -->
            <!-- ==================== پروژه‌ها ========================= -->
            <!-- ===================================================== -->
            <h2 class="section-title">پروژه‌ها</h2>

            <p class="section-intro">
                توابع مربوط به مدیریت پروژه‌ها، اعضا، دسترسی‌ها و مسدودسازی.
            </p>

            <!-- === projects/list === -->
            <div class="endpoint" id="projects-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/projects/list/</div>
                </div>
                <p class="endpoint-desc">
                    لیست تمام پروژه‌هایی که کاربر سازنده یا عضو آن‌هاست.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">q</span></td><td><span class="param-type str">str</span></td><td>جستجو در عنوان پروژه</td></tr>
                        <tr><td><span class="param-name">role</span></td><td><span class="param-type enum">enum</span></td><td>all / creator / member</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج (پیش‌فرض: 100)</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع (پیش‌فرض: 0)</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/list/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>

                <div class="sub-title"><i class="fas fa-reply"></i> نمونه پاسخ</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>Response 200</span></div>
                    <div class="code-body">{
  <span class="key">"success"</span>: <span class="bool">true</span>,
  <span class="key">"data"</span>: {
    <span class="key">"count"</span>: <span class="num">1</span>,
    <span class="key">"projects"</span>: [
      {
        <span class="key">"id"</span>: <span class="num">9</span>,
        <span class="key">"title"</span>: <span class="str">"تاپین"</span>,
        <span class="key">"description"</span>: <span class="str">"تسک ها و وظایف تاپین"</span>,
        <span class="key">"max_members"</span>: <span class="num">10</span>,
        <span class="key">"profile_image"</span>: <span class="str">"/uploads/projects/xxx.jpg"</span>,
        <span class="key">"is_creator"</span>: <span class="bool">true</span>,
        <span class="key">"members_count"</span>: <span class="num">3</span>,
        <span class="key">"my_permissions"</span>: [<span class="str">"edit_project"</span>, <span class="str">"add_member"</span>, ...]
      }
    ]
  }
}</div>
                </div>
            </div>

            <!-- === projects/show === -->
            <div class="endpoint" id="projects-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/projects/show/?id=N</div>
                </div>
                <p class="endpoint-desc">جزئیات یک پروژه به همراه لیست اعضا و دسترسی‌هایشان.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/show/?id=9"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === projects/stats === -->
            <div class="endpoint" id="projects-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/projects/stats/</div>
                </div>
                <p class="endpoint-desc">آمار کلی پروژه‌های کاربر.</p>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/stats/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>

                <div class="sub-title"><i class="fas fa-reply"></i> نمونه پاسخ</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>Response 200</span></div>
                    <div class="code-body">{
  <span class="key">"success"</span>: <span class="bool">true</span>,
  <span class="key">"data"</span>: {
    <span class="key">"created"</span>: <span class="num">1</span>,
    <span class="key">"total_projects"</span>: <span class="num">3</span>,
    <span class="key">"total_members"</span>: <span class="num">5</span>,
    <span class="key">"blocked"</span>: <span class="num">0</span>
  }
}</div>
                </div>
            </div>

            <!-- === projects/members === -->
            <div class="endpoint" id="projects-members">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/projects/members/?id=N</div>
                </div>
                <p class="endpoint-desc">لیست اعضای یک پروژه به همراه دسترسی‌های هرکدام.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === projects/create === -->
            <div class="endpoint" id="projects-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/create/</div>
                </div>
                <p class="endpoint-desc">ایجاد یک پروژه جدید. اعضای ارسالی باید از بین همکاران مستقیم باشند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">title<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>عنوان پروژه (حداکثر ۲۵۵ کاراکتر)</td></tr>
                        <tr><td><span class="param-name">description</span></td><td><span class="param-type str">str</span></td><td>توضیحات پروژه</td></tr>
                        <tr><td><span class="param-name">max_members</span></td><td><span class="param-type int">int</span></td><td>حداکثر اعضا (۱ تا ۱۰۰، پیش‌فرض: 10)</td></tr>
                        <tr><td><span class="param-name">members</span></td><td><span class="param-type array">array</span></td><td>آرایه شناسه اعضای اولیه</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/create/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{
    <span class="key">"title"</span>: <span class="str">"پروژه جدید"</span>,
    <span class="key">"description"</span>: <span class="str">"توضیحات"</span>,
    <span class="key">"max_members"</span>: <span class="num">10</span>,
    <span class="key">"members"</span>: [<span class="num">9</span>, <span class="num">10</span>]
  }'</div>
                </div>
            </div>

            <!-- === projects/update === -->
            <div class="endpoint" id="projects-update">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/update/?id=N</div>
                </div>
                <p class="endpoint-desc">ویرایش اطلاعات پروژه (نیاز به دسترسی <code style="direction:ltr;display:inline-block;background:#f3f4f6;padding:1px 6px;border-radius:4px;">edit_project</code> یا سازنده بودن).</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه (query string)</td></tr>
                        <tr><td><span class="param-name">title</span></td><td><span class="param-type str">str</span></td><td>عنوان جدید</td></tr>
                        <tr><td><span class="param-name">description</span></td><td><span class="param-type str">str</span></td><td>توضیحات جدید</td></tr>
                        <tr><td><span class="param-name">max_members</span></td><td><span class="param-type int">int</span></td><td>حداکثر اعضا</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === projects/delete === -->
            <div class="endpoint" id="projects-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/delete/?id=N</div>
                </div>
                <p class="endpoint-desc">حذف پروژه (فقط سازنده). تمام اعضا و روابط پاک می‌شوند.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === projects/member-add === -->
            <div class="endpoint" id="projects-member-add">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/member-add/?id=N</div>
                </div>
                <p class="endpoint-desc">
                    افزودن یک عضو جدید به پروژه. نیاز به دسترسی <code style="direction:ltr;display:inline-block;background:#f3f4f6;padding:1px 6px;border-radius:4px;">add_member</code> یا سازنده بودن. کاربر باید همکار مستقیم باشد.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه (query string)</td></tr>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری که باید اضافه شود</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/member-add/?id=9"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{ <span class="key">"user_id"</span>: <span class="num">10</span> }'</div>
                </div>
            </div>

            <!-- === projects/member-remove === -->
            <div class="endpoint" id="projects-member-remove">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/member-remove/?id=N</div>
                </div>
                <p class="endpoint-desc">
                    حذف یک عضو از پروژه. نیاز به دسترسی <code style="direction:ltr;display:inline-block;background:#f3f4f6;padding:1px 6px;border-radius:4px;">remove_member</code> یا سازنده بودن.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری که باید حذف شود</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === projects/member-permissions === -->
            <div class="endpoint" id="projects-member-permissions">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/member-permissions/?id=N</div>
                </div>
                <p class="endpoint-desc">
                    ویرایش دسترسی‌های یک عضو. نیاز به دسترسی <code style="direction:ltr;display:inline-block;background:#f3f4f6;padding:1px 6px;border-radius:4px;">manage_permissions</code> یا سازنده بودن. شما فقط می‌توانید مجوزهایی را اعطا کنید که خودتان دارید.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> دسترسی‌های مجاز پروژه</div>
                <table class="params-table">
                    <thead><tr><th style="width: 35%;">کد</th><th>معنی</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">edit_project</span></td><td>ویرایش اطلاعات پروژه</td></tr>
                        <tr><td><span class="param-name">add_member</span></td><td>افزودن عضو جدید</td></tr>
                        <tr><td><span class="param-name">remove_member</span></td><td>حذف عضو</td></tr>
                        <tr><td><span class="param-name">manage_permissions</span></td><td>ویرایش دسترسی دیگران</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/member-permissions/?id=9"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{
    <span class="key">"user_id"</span>: <span class="num">9</span>,
    <span class="key">"permissions"</span>: [<span class="str">"edit_project"</span>, <span class="str">"add_member"</span>]
  }'</div>
                </div>
            </div>

            <!-- === projects/leave === -->
            <div class="endpoint" id="projects-leave">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/leave/?id=N</div>
                </div>
                <p class="endpoint-desc">
                    خروج خود کاربر از پروژه. سازنده نمی‌تواند از پروژه خودش خارج شود.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/projects/leave/?id=9"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === projects/block === -->
            <div class="endpoint" id="projects-block">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/projects/block/?id=N</div>
                </div>
                <p class="endpoint-desc">
                    مسدود کردن پروژه (پروژه از لیست کاربر مخفی می‌شود).
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه پروژه</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===================================================== -->
            <!-- ==================== همکاران ========================= -->
            <!-- ===================================================== -->
            <h2 class="section-title">همکاران</h2>

            <p class="section-intro">
                توابع مربوط به مدیریت همکاران، دسترسی‌ها، مسدودسازی و روابط بین کاربران.
            </p>

            <!-- === colleagues/list === -->
            <div class="endpoint" id="colleagues-list">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/list/</div>
                </div>
                <p class="endpoint-desc">
                    لیست تمام کاربرانی که شما آن‌ها را به عنوان همکار اضافه کرده‌اید.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (همه اختیاری)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">q</span></td><td><span class="param-type str">str</span></td><td>جستجو در نام یا موبایل</td></tr>
                        <tr><td><span class="param-name">only_active</span></td><td><span class="param-type int">int</span></td><td>فقط همکاران بدون بلاک</td></tr>
                        <tr><td><span class="param-name">limit</span></td><td><span class="param-type int">int</span></td><td>تعداد نتایج</td></tr>
                        <tr><td><span class="param-name">offset</span></td><td><span class="param-type int">int</span></td><td>نقطه شروع</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === colleagues/followers === -->
            <div class="endpoint" id="colleagues-followers">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/followers/</div>
                </div>
                <p class="endpoint-desc">
                    لیست کاربرانی که شما را به عنوان همکار اضافه کرده‌اند.
                </p>
            </div>

            <!-- === colleagues/blocked === -->
            <div class="endpoint" id="colleagues-blocked">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/blocked/</div>
                </div>
                <p class="endpoint-desc">لیست کاربرانی که شما آن‌ها را مسدود کرده‌اید.</p>
            </div>

            <!-- === colleagues/show === -->
            <div class="endpoint" id="colleagues-show">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/show/?user_id=N</div>
                </div>
                <p class="endpoint-desc">جزئیات یک همکار خاص به همراه دسترسی‌ها.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری همکار</td></tr>
                        <tr><td><span class="param-name">row_id</span></td><td><span class="param-type int">int</span></td><td>جایگزین: شناسه ردیف در colleagues</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === colleagues/stats === -->
            <div class="endpoint" id="colleagues-stats">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/stats/</div>
                </div>
                <p class="endpoint-desc">آمار کلی همکاران کاربر.</p>
            </div>

            <!-- === colleagues/search === -->
            <div class="endpoint" id="colleagues-search">
                <div class="endpoint-header">
                    <span class="method get">GET</span>
                    <div class="endpoint-url">/api/v1/colleagues/search/?mobile=09xxxxxxxxx</div>
                </div>
                <p class="endpoint-desc">جستجوی کاربر با شماره موبایل برای بررسی وضعیت همکاری.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">mobile<span class="star">*</span></span></td><td><span class="param-type str">str</span></td><td>شماره موبایل با فرمت 09xxxxxxxxx</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">GET</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/colleagues/search/?mobile=09123905743"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span></div>
                </div>
            </div>

            <!-- === colleagues/create === -->
            <div class="endpoint" id="colleagues-create">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/colleagues/create/</div>
                </div>
                <p class="endpoint-desc">
                    افزودن یک همکار جدید (با شماره موبایل یا شناسه کاربری).
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها (یکی از این دو الزامیه)</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">mobile</span></td><td><span class="param-type str">str</span></td><td>شماره موبایل کاربر</td></tr>
                        <tr><td><span class="param-name">user_id</span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری مستقیم</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/colleagues/create/"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{ <span class="key">"mobile"</span>: <span class="str">"09123905743"</span> }'</div>
                </div>
            </div>

            <!-- === colleagues/permissions === -->
            <div class="endpoint" id="colleagues-permissions">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/colleagues/permissions/?user_id=N</div>
                </div>
                <p class="endpoint-desc">
                    ویرایش دسترسی‌های یک همکار روی تسک‌های شما. دسترسی‌های ارسالی جایگزین قبلی‌ها می‌شن.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> دسترسی‌های مجاز</div>
                <table class="params-table">
                    <thead><tr><th style="width: 35%;">کد</th><th>معنی</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">edit</span></td><td>ویرایش تسک</td></tr>
                        <tr><td><span class="param-name">delete</span></td><td>حذف تسک</td></tr>
                        <tr><td><span class="param-name">complete</span></td><td>تکمیل/برگشت تسک</td></tr>
                        <tr><td><span class="param-name">reassign</span></td><td>تغییر مسئول تسک</td></tr>
                        <tr><td><span class="param-name">notes</span></td><td>افزودن/حذف یادداشت</td></tr>
                        <tr><td><span class="param-name">change_project</span></td><td>تغییر پروژه تسک</td></tr>
                    </tbody>
                </table>

                <div class="sub-title"><i class="fas fa-terminal"></i> نمونه درخواست</div>
                <div class="code-block">
                    <div class="code-header"><div class="dots"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div><span>cURL</span></div>
                    <div class="code-body">curl -X <span class="val">POST</span> <span class="str">"<?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?>/colleagues/permissions/?user_id=9"</span> \
  -H <span class="str">"Authorization: Bearer tk_xxxxxxxxxxxxxxxx"</span> \
  -H <span class="str">"Content-Type: application/json"</span> \
  -d '{ <span class="key">"permissions"</span>: [<span class="str">"edit"</span>, <span class="str">"notes"</span>, <span class="str">"change_project"</span>] }'</div>
                </div>
            </div>

            <!-- === colleagues/block === -->
            <div class="endpoint" id="colleagues-block">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/colleagues/block/</div>
                </div>
                <p class="endpoint-desc">
                    مسدود کردن یک کاربر. کاربر مسدودشده نمی‌تواند شما را همکار کند.
                </p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری که باید مسدود شود</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === colleagues/unblock === -->
            <div class="endpoint" id="colleagues-unblock">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/colleagues/unblock/</div>
                </div>
                <p class="endpoint-desc">رفع مسدودی یک کاربر.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- === colleagues/delete === -->
            <div class="endpoint" id="colleagues-delete">
                <div class="endpoint-header">
                    <span class="method post">POST</span>
                    <div class="endpoint-url">/api/v1/colleagues/delete/?user_id=N</div>
                </div>
                <p class="endpoint-desc">حذف یک همکار از لیست.</p>

                <div class="sub-title"><i class="fas fa-list"></i> پارامترها</div>
                <table class="params-table">
                    <thead><tr><th style="width: 30%;">نام فیلد</th><th style="width: 15%;">نوع</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <tr><td><span class="param-name">user_id<span class="star">*</span></span></td><td><span class="param-type int">int</span></td><td>شناسه کاربری همکار</td></tr>
                        <tr><td><span class="param-name">row_id</span></td><td><span class="param-type int">int</span></td><td>جایگزین: شناسه ردیف در colleagues</td></tr>
                    </tbody>
                </table>
            </div>

        </main>

    </div>

    <script>
        document.querySelectorAll('.sidebar-item[href^="#"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#top') {
                    e.preventDefault();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        const sections = Array.from(document.querySelectorAll('.endpoint[id]'));
        const navLinks = document.querySelectorAll('.sidebar-item[href^="#"]');

        if (sections.length > 0) {
            window.addEventListener('scroll', function() {
                const scrollPos = window.scrollY + 160;
                let current = null;
                sections.forEach(function(sec) {
                    if (sec.offsetTop <= scrollPos) current = sec.id;
                });
                navLinks.forEach(function(link) {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === '#' + current) {
                        link.classList.add('active');
                    }
                });
            }, { passive: true });
        }
    </script>

</body>
</html>