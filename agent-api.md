# 📄 فایل `AGENT-API.md`

```markdown
# AGENT-API.md — Task Manager REST API v1

> **هدف این فایل:** مرجع کامل API سامانه تسک برای هر هوش مصنوعی یا توسعه‌دهنده.
> با خواندن این فایل، هر AI می‌تواند بدون نیاز به دیدن کد، API را کامل بفهمد و با آن کار کند.

---

## 📑 فهرست

1. [معرفی کلی](#1-معرفی-کلی)
2. [احراز هویت](#2-احراز-هویت)
3. [ساختار پاسخ‌ها](#3-ساختار-پاسخها)
4. [کدهای خطا](#4-کدهای-خطا)
5. [Endpoint های Tasks (تسک‌ها)](#5-endpoint-های-tasks-تسکها)
6. [Endpoint های Calls (درخواست‌های تماس)](#6-endpoint-های-calls-درخواستهای-تماس)
7. [Endpoint های Tickets (تیکت‌ها)](#7-endpoint-های-tickets-تیکتها)
8. [Endpoint های Texts (متن‌های آماده)](#8-endpoint-های-texts-متنهای-آماده)
9. [ساختار فایل‌های پروژه](#9-ساختار-فایلهای-پروژه)
10. [نکات پیاده‌سازی](#10-نکات-پیادهسازی)
11. [قواعد امنیتی](#11-قواعد-امنیتی)

---

## 1. معرفی کلی

### 1.1. Base URL

```
https://<DOMAIN>/tapin/task/api/v1/
```

مثال واقعی:
```
https://mahyarsalehee.ir/tapin/task/api/v1/
```

### 1.2. پروتکل‌ها و فرمت‌ها

| مورد | مقدار |
|------|-------|
| پروتکل | HTTP/HTTPS |
| فرمت داده | JSON (UTF-8) |
| Content-Type درخواست | `application/json` |
| Content-Type پاسخ | `application/json; charset=utf-8` |
| جهت متن | RTL (فارسی) |
| انکودینگ دیتابیس | utf8mb4 |

### 1.3. نسخه‌بندی

- نسخه فعلی: **v1**
- مسیر نسخه: `/api/v1/`
- نسخه‌های آینده به‌صورت `/api/v2/` اضافه خواهند شد.

### 1.4. گروه‌های منابع

| گروه | پیشوند | توضیح |
|------|--------|-------|
| Tasks | `/api/v1/tasks/` | مدیریت تسک‌ها |
| Calls | `/api/v1/calls/` | درخواست‌های تماس |
| Tickets | `/api/v1/tickets/` | تیکت‌های پشتیبانی (سراسری) |
| Texts | `/api/v1/texts/` | متن‌های آماده |

---

## 2. احراز هویت

### 2.1. روش اصلی: Bearer Token

تمام درخواست‌ها نیاز به توکن احراز هویت دارند. توکن در هدر `Authorization` قرار می‌گیرد:

```http
Authorization: Bearer tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### 2.2. ساخت توکن

کاربر از پنل، در بخش **«توکن‌های API»** (`/api-tokens/index.php`) توکن می‌سازد:
- حداکثر ۵ توکن فعال به ازای هر کاربر
- فرمت توکن: `tk_` + 48 کاراکتر hex
- مثال: `tk_911740fe20c9fa1d66164f20e52be09ffe4425020ad82485`

### 2.3. روش‌های جایگزین

به ترتیب اولویت:

| # | روش | مثال |
|---|-----|------|
| ۱ | هدر `Authorization: Bearer <TOKEN>` | استاندارد |
| ۲ | هدر `X-API-Key: <TOKEN>` | برای بعضی ابزارها |
| ۳ | Query string | `?token=tk_xxx` |
| ۴ | Body (JSON) | `{"token": "tk_xxx"}` |
| ۵ | Session (کوکی) | برای درخواست از مرورگر |

### 2.4. اعتبارسنجی توکن

- توکن در جدول `api_tokens` جستجو می‌شود
- فیلترها: `is_active = 1`
- کاربر باید `status = 'active'` باشد
- بعد از هر استفاده، `last_used_at` و `last_ip` به‌روزرسانی می‌شود

### 2.5. آزمون توکن

برای تست صحت توکن، از Endpoint زیر استفاده کنید:

```http
GET /api/v1/tasks/list/
Authorization: Bearer tk_xxxxxxxxxxxxxxxx
```

اگر پاسخ ۲۰۰ با `success: true` دریافت شد، توکن معتبر است.

---

## 3. ساختار پاسخ‌ها

### 3.1. پاسخ موفق (Success)

```json
{
    "success": true,
    "status": "success",
    "message": "پیام اختیاری",
    "data": { }
}
```

| فیلد | نوع | توضیح |
|------|-----|-------|
| `success` | boolean | همیشه `true` |
| `status` | string | همیشه `"success"` |
| `message` | string | پیام (فقط در POST ها) |
| `data` | object/array | داده بازگشتی |

### 3.2. پاسخ خطا (Error)

```json
{
    "success": false,
    "status": "error",
    "message": "توضیح خطا به فارسی",
    "error_code": "ERROR_CODE"
}
```

| فیلد | نوع | توضیح |
|------|-----|-------|
| `success` | boolean | همیشه `false` |
| `status` | string | همیشه `"error"` |
| `message` | string | پیام خطا (نمایش به کاربر) |
| `error_code` | string | کد قابل برنامه‌نویسی |

### 3.3. Type Casting

در پاسخ‌ها، اعداد همیشه به‌عنوان `int` برگردانده می‌شوند (نه string):

```json
{
    "id": 27,              // ← int
    "is_completed": false, // ← bool
    "title": "..."         // ← str
}
```

فیلدهای nullable ممکن است `null` باشند.

---

## 4. کدهای خطا

### 4.1. HTTP Status Codes

| کد | توضیح |
|----|-------|
| 200 | موفق |
| 204 | Preflight (CORS) |
| 400 | درخواست نامعتبر |
| 401 | احراز هویت ناموفق |
| 403 | دسترسی رد شد |
| 404 | یافت نشد |
| 405 | متد HTTP اشتباه |
| 500 | خطای سرور |

### 4.2. Error Codes

| Error Code | HTTP | توضیح |
|------------|------|-------|
| `UNAUTHORIZED` | 401 | توکن یا session معتبر نیست |
| `TOKEN_MISSING` | 401 | هدر Authorization ارسال نشده |
| `TOKEN_INVALID` | 401 | توکن در دیتابیس نیست یا غیرفعاله |
| `TOKEN_INVALID_FORMAT` | 401 | طول توکن نامعتبر |
| `ACCOUNT_INACTIVE` | 403 | حساب کاربر فعال نیست |
| `ASSIGNEE_NOT_ALLOWED` | 403 | اجازه واگذاری به این کاربر نیست |
| `FORBIDDEN` | 403 | دسترسی کافی نیست |
| `TASK_NOT_FOUND` | 404 | تسک یافت نشد |
| `CALL_NOT_FOUND` | 404 | درخواست تماس یافت نشد |
| `TICKET_NOT_FOUND` | 404 | تیکت یافت نشد |
| `TEXT_NOT_FOUND` | 404 | متن یافت نشد |
| `TITLE_REQUIRED` | 400 | عنوان اجباری |
| `TITLE_TOO_LONG` | 400 | عنوان بسیار طولانی |
| `DESC_TOO_LONG` | 400 | توضیحات بسیار طولانی |
| `FIRST_NAME_REQUIRED` | 400 | نام اجباری |
| `LAST_NAME_REQUIRED` | 400 | نام خانوادگی اجباری |
| `MOBILE_REQUIRED` | 400 | موبایل اجباری |
| `MOBILE_INVALID` | 400 | فرمت موبایل اشتباه |
| `EMAIL_INVALID` | 400 | فرمت ایمیل اشتباه |
| `INVALID_ID` | 400 | شناسه نامعتبر |
| `INVALID_STATUS` | 400 | مقدار status معتبر نیست |
| `METHOD_NOT_ALLOWED` | 405 | متد HTTP اشتباه |
| `UNKNOWN_ACTION` | 400 | اکشن ناشناخته |
| `CONFIG_MISSING` | 500 | فایل config نیست |
| `DB_CONNECTION_FAILED` | 500 | اتصال دیتابیس ناموفق |
| `TOKENS_TABLE_MISSING` | 500 | جدول api_tokens ساخته نشده |

---

## 5. Endpoint های Tasks (تسک‌ها)

### 5.1. لیست تسک‌ها

```http
GET /api/v1/tasks/list/
```

**پارامترهای Query (اختیاری):**

| نام | نوع | توضیح |
|-----|-----|-------|
| `status` | enum | `all` / `pending` / `completed` (پیش‌فرض: `all`) |
| `limit` | int | تعداد نتایج، حداکثر ۲۰۰ (پیش‌فرض: ۱۰۰) |
| `offset` | int | نقطه شروع (پیش‌فرض: ۰) |

**پاسخ:**
```json
{
    "success": true,
    "status": "success",
    "data": {
        "count": 15,
        "limit": 100,
        "offset": 0,
        "status": "all",
        "tasks": [
            {
                "id": 27,
                "title": "ایجاد تسک مشکل دکمه",
                "description": "تسکش رو فرصت کردی بذار",
                "priority": "high",
                "is_completed": false,
                "due_date": null,
                "created_at": "2026-09-19 09:01:01",
                "completed_at": null,
                "user_id": 2,
                "assignee_id": 2,
                "subject_id": 1,
                "project_id": 9,
                "subject_title": "پیگیری فنی",
                "project_title": "تاپین",
                "assignee_first_name": "مهیار",
                "assignee_last_name": "صالحی",
                "assignee_mobile": "09054293192",
                "creator_first_name": "مهیار",
                "creator_last_name": "صالحی",
                "notes_count": 0
            }
        ]
    }
}
```

**منطق فیلتر:**
- فقط تسک‌هایی که کاربر `user_id` (سازنده) یا `assignee_id` (مسئول) آن‌هاست.
- ترتیب: `created_at DESC`
- فیلتر `pending`: `is_completed = 0`
- فیلتر `completed`: `is_completed = 1`

---

### 5.2. جزئیات یک تسک

```http
GET /api/v1/tasks/show/?id=27
```

**پارامترها:**

| نام | نوع | الزامی | توضیح |
|-----|-----|--------|-------|
| `id` | int | ✅ | شناسه تسک |

**پاسخ:** یک تسک کامل + آرایه `notes`

```json
{
    "success": true,
    "data": {
        "id": 27,
        "title": "...",
        "notes": [
            {
                "id": 1,
                "user_id": 2,
                "note": "در حال بررسی",
                "created_at": "2026-09-20 14:30:00",
                "first_name": "مهیار",
                "last_name": "صالحی",
                "mobile": "09054293192"
            }
        ]
    }
}
```

---

### 5.3. آمار تسک‌ها

```http
GET /api/v1/tasks/stats/
```

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "total": 15,
        "completed": 6,
        "pending": 9,
        "high": 3,
        "medium": 9,
        "low": 3
    }
}
```

**منطق:** فقط تسک‌هایی که `assignee_id = user_id` (تسک‌های واگذارشده به کاربر).

---

### 5.4. ایجاد تسک

```http
POST /api/v1/tasks/create/
Content-Type: application/json
```

**Body:**

| نام | نوع | الزامی | توضیح |
|-----|-----|--------|-------|
| `title` | str | ✅ | عنوان (حداکثر ۲۵۵ کاراکتر) |
| `description` | str | ❌ | توضیحات (حداکثر ۵۰۰۰ کاراکتر) |
| `priority` | enum | ❌ | `low` / `medium` / `high` (پیش‌فرض: `medium`) |
| `subject_id` | int | ❌ | شناسه موضوع (باید مال خود کاربر باشد) |
| `project_id` | int | ❌ | شناسه پروژه (باید کاربر در آن عضو باشد) |
| `assignee_id` | int | ❌ | شناسه مسئول (پیش‌فرض: خودتان) |
| `due_date` | date | ❌ | تاریخ سررسید (`YYYY-MM-DD`) |

**مثال:**
```json
{
    "title": "بررسی تیکت ۹۶۰۶۳",
    "priority": "high",
    "due_date": "2026-09-25"
}
```

**پاسخ موفق:**
```json
{
    "success": true,
    "message": "وظیفه با موفقیت ایجاد شد.",
    "data": {
        "id": 38,
        "title": "بررسی تیکت ۹۶۰۶۳",
        "priority": "high",
        "assignee_id": 2,
        "project_id": null,
        "subject_id": null,
        "due_date": "2026-09-25",
        "created_at": "2026-09-20 14:30:00"
    }
}
```

**قواعد assignee:**
- باید خود کاربر باشد، **یا**
- همکار مستقیم کاربر باشد، **یا**
- عضو پروژه‌ی انتخاب‌شده باشد
- در غیر این صورت: خطای `ASSIGNEE_NOT_ALLOWED`

---

### 5.5. ویرایش تسک

```http
POST /api/v1/tasks/update/?id=27
```

**پارامتر Query:**

| نام | نوع | الزامی |
|-----|-----|--------|
| `id` | int | ✅ |

**Body:** فقط فیلدهایی که می‌خواهید تغییر دهید.

**قواعد:**
- کاربر باید **سازنده** تسک باشد یا دسترسی `edit` داشته باشد.
- تغییر `assignee_id` نیازمند دسترسی `reassign` یا سازنده بودن است.
- تغییر `project_id` نیازمند دسترسی `change_project` یا سازنده بودن است.

---

### 5.6. حذف تسک

```http
POST /api/v1/tasks/delete/?id=27
```

**قواعد:**
- کاربر باید سازنده باشد یا دسترسی `delete` داشته باشد.
- تمام یادداشت‌های تسک هم حذف می‌شوند.

---

### 5.7. تغییر وضعیت تکمیل (Toggle)

```http
POST /api/v1/tasks/toggle/?id=27
```

**رفتار:**
- اگر `is_completed = 0` → `1` و `completed_at = NOW()`
- اگر `is_completed = 1` → `0` و `completed_at = NULL`

**پاسخ:**
```json
{
    "success": true,
    "message": "وضعیت تسک تغییر کرد.",
    "data": {
        "id": 27,
        "is_completed": true
    }
}
```

**قواعد:**
- کاربر باید سازنده باشد یا دسترسی `complete` داشته باشد.

---

## 6. Endpoint های Calls (درخواست‌های تماس)

### 6.1. لیست درخواست‌ها

```http
GET /api/v1/calls/list/
```

**پارامترها:**

| نام | نوع | توضیح |
|-----|-----|-------|
| `status` | enum | `all` / `new` / `done` |
| `assignee` | enum | `all` / `me` |
| `limit` | int | حداکثر ۲۰۰ (پیش‌فرض: ۱۰۰) |
| `offset` | int | پیش‌فرض: ۰ |

**منطق فیلتر:**
- `user_id = current_user` **یا** `assignee_id = current_user`
- `assignee=me`: فقط `assignee_id = current_user`
- `status=new`: `status = 0`
- `status=done`: `status = 1`

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "count": 1,
        "calls": [
            {
                "id": 5,
                "user_id": 2,
                "assignee_id": null,
                "first_name": "مهیار",
                "last_name": "صالحی",
                "mobile": "09991867331",
                "email": "mahyar@example.com",
                "store": "فروشگاه نمونه",
                "website": "https://example.com",
                "status": 0,
                "is_done": false,
                "created_at": "2026-09-15 08:01:56",
                "completed_at": null
            }
        ]
    }
}
```

---

### 6.2. جزئیات درخواست

```http
GET /api/v1/calls/show/?id=5
```

---

### 6.3. آمار درخواست‌ها

```http
GET /api/v1/calls/stats/
```

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "total": 10,
        "new": 3,
        "done": 7,
        "today": 1,
        "unassigned": 2
    }
}
```

---

### 6.4. ایجاد درخواست

```http
POST /api/v1/calls/create/
```

**Body:**

| نام | نوع | الزامی | توضیح |
|-----|-----|--------|-------|
| `first_name` | str | ✅ | نام (حداکثر ۱۵۰) |
| `last_name` | str | ✅ | نام خانوادگی (حداکثر ۱۵۰) |
| `mobile` | str | ✅ | فرمت: `^09\d{9}$` |
| `email` | str | ❌ | ایمیل معتبر |
| `store` | str | ❌ | نام فروشگاه (حداکثر ۲۵۵) |
| `website` | str | ❌ | آدرس سایت (حداکثر ۲۵۵) |
| `assignee_id` | int | ❌ | باید همکار باشد |

**اعتبارسنجی موبایل:** فقط فرمت ایرانی `09xxxxxxxxx`

**اعتبارسنجی ایمیل:** با `filter_var(..., FILTER_VALIDATE_EMAIL)`

---

### 6.5. ویرایش درخواست

```http
POST /api/v1/calls/update/?id=5
```

**نکته:** تغییر `assignee_id` فقط توسط سازنده درخواست.

---

### 6.6. تغییر وضعیت

```http
POST /api/v1/calls/status/?id=5
```

**دو حالت:**

**۱. Toggle (بدون body):**
```bash
POST /api/v1/calls/status/?id=5
```

**۲. تعیین صریح (با body):**
```json
{ "status": 1 }  // 1 = انجام‌شده
```

**پاسخ:**
```json
{
    "success": true,
    "message": "وضعیت درخواست تغییر کرد.",
    "data": {
        "id": 5,
        "status": 1,
        "is_done": true,
        "completed_at": "2026-09-21 08:30:00"
    }
}
```

---

### 6.7. حذف درخواست

```http
POST /api/v1/calls/delete/?id=5
```

**قاعده:** فقط **سازنده** می‌تواند حذف کند.

---

## 7. Endpoint های Tickets (تیکت‌ها)

> **⚠️ توجه:** تیکت‌ها **سراسری** هستند (بین همه کاربران مشترک). جدول `tickets` ستون `user_id` ندارد.

### 7.1. لیست تیکت‌ها

```http
GET /api/v1/tickets/list/
```

**پارامترها:**

| نام | نوع | توضیح |
|-----|-----|-------|
| `status` | enum | `all` / `open` / `done` |
| `priority` | enum | `all` / `low` / `medium` / `high` / `very_high` |
| `vip` | int | `0` / `1` |
| `q` | str | جستجو در `ticket_number` و `subject` |
| `limit` | int | حداکثر ۲۰۰ |
| `offset` | int | پیش‌فرض: ۰ |

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "count": 77,
        "tickets": [
            {
                "id": 74,
                "ticket_number": "96063",
                "subject": "اعلام مغایرت شارژ پنل",
                "task_link": null,
                "priority": "high",
                "status": 0,
                "is_done": false,
                "is_vip": false,
                "text": "گزارش بگیر براش",
                "created_at": "2026-09-14 10:29:08",
                "completed_at": null
            }
        ]
    }
}
```

---

### 7.2. جزئیات تیکت

```http
GET /api/v1/tickets/show/?id=74
```

**پاسخ:** تیکت + آرایه `comments` از جدول `ticket_comments`

```json
{
    "success": true,
    "data": {
        "id": 74,
        "ticket_number": "96063",
        "subject": "اعلام مغایرت شارژ پنل",
        "priority": "high",
        "status": 0,
        "is_vip": false,
        "comments": [
            {
                "id": 68,
                "comment": "گزارش بگیر براش",
                "created_at": "2026-09-14 10:29:08"
            }
        ]
    }
}
```

---

### 7.3. آمار تیکت‌ها

```http
GET /api/v1/tickets/stats/
```

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "total": 77,
        "open": 5,
        "done": 72,
        "vip": 4,
        "very_high": 20,
        "high": 14,
        "medium": 29,
        "low": 14,
        "today": 0
    }
}
```

---

### 7.4. ایجاد تیکت

```http
POST /api/v1/tickets/create/
```

**Body:**

| نام | نوع | الزامی | توضیح |
|-----|-----|--------|-------|
| `ticket_number` | str | ✅ | حداکثر ۵۰ کاراکتر |
| `subject` | str | ❌ | حداکثر ۲۵۵ |
| `task_link` | str | ❌ | حداکثر ۵۰۰ |
| `priority` | enum | ❌ | `low`/`medium`/`high`/`very_high` |
| `text` | str | ❌ | متن تیکت |
| `is_vip` | int | ❌ | 0 یا 1 |
| `comment` | str | ❌ | اولین کامنت |

**رفتار:**
- تیکت با `status = 0` (باز) ایجاد می‌شود.
- اگر `comment` ارسال شده باشد، در جدول `ticket_comments` ثبت می‌شود.
- عملیات در یک **تراکنش** انجام می‌شود.

---

### 7.5. ویرایش تیکت

```http
POST /api/v1/tickets/update/?id=74
```

**قابل ویرایش:** `ticket_number`, `subject`, `task_link`, `priority`, `text`, `is_vip`

---

### 7.6. تغییر وضعیت تیکت

```http
POST /api/v1/tickets/status/?id=74
```

**رفتار:**
- بدون body → toggle
- با `{"status": 0|1}` → تعیین صریح

---

### 7.7. حذف تیکت

```http
POST /api/v1/tickets/delete/?id=74
```

**رفتار:** تیکت + تمام کامنت‌های آن حذف می‌شوند.

---

## 8. Endpoint های Texts (متن‌های آماده)

> **⚠️ نکته کلیدی:** `id` در جدول `texts` از نوع **`varchar(50)`** است، نه `int`.
> الگوی id: `<hex1>_<hex2>` مثل `6a7426ce0349d_1994e054`

### 8.1. لیست متن‌ها

```http
GET /api/v1/texts/list/
```

**پارامترها:**

| نام | نوع | توضیح |
|-----|-----|-------|
| `q` | str | جستجو در `title` و `content` |
| `limit` | int | حداکثر ۲۰۰ |
| `offset` | int | پیش‌فرض: ۰ |

**منطق:** فقط متن‌های `user_id = current_user`

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "count": 33,
        "texts": [
            {
                "id": "6a7426ce0349d_1994e054",
                "title": "عودت وجه",
                "content": "باسلام و احترام؛ ...",
                "sort_order": 0,
                "created_at": "2026-08-06 10:16:46",
                "updated_at": "2026-09-20 05:58:20"
            }
        ]
    }
}
```

**ترتیب:** `sort_order ASC`, سپس `created_at DESC`

---

### 8.2. جزئیات متن

```http
GET /api/v1/texts/show/?id=6a7426ce0349d_1994e054
```

**پارامتر:** `id` (str — نه int!)

---

### 8.3. آمار متن‌ها

```http
GET /api/v1/texts/stats/
```

**پاسخ:**
```json
{
    "success": true,
    "data": {
        "total": 33,
        "today": 0,
        "updated_today": 2
    }
}
```

---

### 8.4. ایجاد متن

```http
POST /api/v1/texts/create/
```

**Body:**

| نام | نوع | الزامی | توضیح |
|-----|-----|--------|-------|
| `title` | str | ✅ | حداکثر ۲۵۵ |
| `content` | str | ✅ | محتوای متن |
| `sort_order` | int | ❌ | پیش‌فرض: ۰ |

**رفتار:**
- `id` به‌صورت خودکار تولید می‌شود: `bin2hex(random_bytes(6)) . '_' . bin2hex(random_bytes(4))`
- مثال خروجی: `a4f3b2c1d5e6_7f8a9b0c`

**پاسخ:**
```json
{
    "success": true,
    "message": "متن با موفقیت ایجاد شد.",
    "data": {
        "id": "a4f3b2c1d5e6_7f8a9b0c",
        "title": "پاسخ به مشتری",
        "content": "با سلام...",
        "sort_order": 0,
        "created_at": "2026-09-21 08:00:00"
    }
}
```

---

### 8.5. ویرایش متن

```http
POST /api/v1/texts/update/?id=6a7426ce0349d_1994e054
```

**قابل ویرایش:** `title`, `content`, `sort_order`

**رفتار:** `updated_at` به‌روزرسانی می‌شود.

---

### 8.6. حذف متن

```http
POST /api/v1/texts/delete/?id=6a7426ce0349d_1994e054
```

**قاعده:** فقط سازنده (کاربر خودش).

---

## 9. ساختار فایل‌های پروژه

### 9.1. ساختار پوشه API

```
api/
├── install.php                       ← نصب‌کننده (بعد از نصب حذف شود)
├── v1/
│   ├── _bootstrap.php                ← هسته مشترک (auth, db, helpers)
│   ├── index.php                     ← GET /api/v1/ — لیست endpoints
│   │
│   ├── tasks/
│   │   ├── list/index.php            ← GET
│   │   ├── show/index.php            ← GET
│   │   ├── stats/index.php           ← GET
│   │   ├── create/index.php          ← POST
│   │   ├── update/index.php          ← POST
│   │   ├── delete/index.php          ← POST
│   │   └── toggle/index.php          ← POST
│   │
│   ├── calls/
│   │   ├── list/index.php
│   │   ├── show/index.php
│   │   ├── stats/index.php
│   │   ├── create/index.php
│   │   ├── update/index.php
│   │   ├── status/index.php
│   │   └── delete/index.php
│   │
│   ├── tickets/
│   │   ├── list/index.php
│   │   ├── show/index.php
│   │   ├── stats/index.php
│   │   ├── create/index.php
│   │   ├── update/index.php
│   │   ├── status/index.php
│   │   └── delete/index.php
│   │
│   └── texts/
│       ├── list/index.php
│       ├── show/index.php
│       ├── stats/index.php
│       ├── create/index.php
│       ├── update/index.php
│       └── delete/index.php
│
└── v1/tasks/, v1/calls/, ...        ← بقیه Endpoint های آینده
```

### 9.2. Bootstrap مشترک

فایل `api/v1/_bootstrap.php` کارهای زیر را انجام می‌دهد:

1. پاک کردن buffer و تنظیم هدرها (`Content-Type: application/json`)
2. راه‌اندازی CORS
3. تعریف توابع پاسخ (`apiSuccess`, `apiError`, `apiResponse`)
4. تعریف `getRequestBody()` برای خواندن JSON
5. تعریف `getAuthorizationHeader()` با ۴ روش fallback
6. لود `config/config.php`, `database/Database.php`, `core/Auth.php`
7. اتصال دیتابیس (PDO) با `utf8mb4`
8. احراز هویت با ۵ روش (Bearer، X-API-Key، query، body، session)
9. تعریف توابع کمکی: `getTaskPermission`, `isAssigneeAllowed`, `requireMethod`

### 9.3. الگوی ساخت هر Endpoint

هر فایل endpoint:

```php
<?php
// 1. لود bootstrap
require_once dirname(__DIR__, 2) . '/_bootstrap.php';

// 2. چک متد HTTP
requireMethod('GET'); // یا POST

// 3. خواندن پارامترها (از $_GET یا $body)

// 4. اعتبارسنجی

// 5. کوئری دیتابیس

// 6. برگرداندن پاسخ
apiSuccess([...]);
// یا apiError('...', 400, 'ERROR_CODE');
```

---

## 10. نکات پیاده‌سازی

### 10.1. الگوی URL

- همیشه به `/` ختم می‌شود: `/tasks/list/`
- بدون `?action=...` (استاندارد RESTful)
- شناسه‌ها در query string: `?id=27`
- متد HTTP نشان‌دهنده عملیات است

### 10.2. Date Format

- **تاریخ**: `YYYY-MM-DD` (مثل `2026-09-25`)
- **Timestamp**: `YYYY-MM-DD HH:MM:SS` (مثل `2026-09-20 14:30:00`)
- **Timezone**: سرور (معمولاً `Asia/Tehran` یا `UTC`)

### 10.3. Priority Values

**Tasks:**

| مقدار | معنی |
|-------|------|
| `low` | کم |
| `medium` | متوسط (پیش‌فرض) |
| `high` | زیاد |

**Tickets:**

| مقدار | معنی |
|-------|------|
| `low` | کم |
| `medium` | متوسط |
| `high` | زیاد |
| `very_high` | خیلی زیاد |

**Calls:** اولویت ندارد.

### 10.4. Status Values

**Tasks:**
- `is_completed = 0` → در انتظار
- `is_completed = 1` → انجام‌شده

**Calls:**
- `status = 0` → جدید
- `status = 1` → انجام‌شده

**Tickets:**
- `status = 0` → باز
- `status = 1` → بسته

### 10.5. Pagination

```bash
GET /api/v1/tasks/list/?limit=20&offset=40
```

- `limit`: تعداد در هر صفحه (max: 200)
- `offset`: نقطه شروع
- برای صفحه بعد: `offset += limit`

### 10.6. CORS

هدرهای CORS تنظیم شده‌اند:
```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type, Accept
```

درخواست `OPTIONS` پاسخ `204 No Content` می‌گیرد.

---

## 11. قواعد امنیتی

### 11.1. Ownership Enforcement

هر کاربر فقط به رکوردهای خودش دسترسی دارد:

- **Tasks**: `user_id = current_user` OR `assignee_id = current_user`
- **Calls**: `user_id = current_user` OR `assignee_id = current_user`
- **Texts**: `user_id = current_user`
- **Tickets**: سراسری (بدون فیلتر)

### 11.2. Assignee Validation

هنگام واگذاری تسک/درخواست به کاربر دیگر:

```php
function isAssigneeAllowed($db, $userId, $assigneeId, $projectId): bool {
    if ($assigneeId === $userId) return true;
    
    // 1. همکار مستقیم؟
    // SELECT 1 FROM colleagues WHERE user_id = ? AND colleague_user_id = ?
    
    // 2. عضو پروژه؟
    // SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?
    
    return false;
}
```

اگر مجاز نبود: خطای `ASSIGNEE_NOT_ALLOWED` (HTTP 403).

### 11.3. Token Storage

- توکن‌ها به‌صورت **plain text** ذخیره می‌شوند (نه hash).
- این تصمیم به‌دلیل امکان نمایش به کاربر در پنل است.
- توصیه: توکن نباید در لاگ‌ها ظاهر شود.

### 11.4. SQL Injection

- تمام کوئری‌ها با **Prepared Statements**
- هیچ ورودی مستقیم در SQL نیست
- id ها با `(int)` cast می‌شوند

### 11.5. Rate Limiting

- **پیاده‌سازی نشده**.
- پیشنهاد برای آینده: محدودیت بر اساس IP یا توکن.

### 11.6. اطلاعات حساس

**هرگز در پاسخ‌ها نیاید:**
- `password_hash`
- `api_tokens.token` (به جز پنل توکن‌ها)
- اطلاعات هویتی کاربران دیگر (بدون همکاری)

---

## 12. نمونه سناریوهای کامل

### 12.1. ایجاد تسک و تکمیل آن

```bash
# 1. ایجاد تسک
curl -X POST "https://example.com/tapin/task/api/v1/tasks/create/" \
  -H "Authorization: Bearer tk_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "بررسی تیکت جدید",
    "priority": "high",
    "due_date": "2026-09-25"
  }'
# Response: { "success": true, "data": { "id": 38, ... } }

# 2. تکمیل تسک
curl -X POST "https://example.com/tapin/task/api/v1/tasks/toggle/?id=38" \
  -H "Authorization: Bearer tk_xxx"
# Response: { "success": true, "data": { "id": 38, "is_completed": true } }

# 3. بررسی آمار
curl -X GET "https://example.com/tapin/task/api/v1/tasks/stats/" \
  -H "Authorization: Bearer tk_xxx"
```

### 12.2. جستجوی متن آماده

```bash
curl -X GET "https://example.com/tapin/task/api/v1/texts/list/?q=عودت" \
  -H "Authorization: Bearer tk_xxx"
```

### 12.3. ثبت تیکت جدید

```bash
curl -X POST "https://example.com/tapin/task/api/v1/tickets/create/" \
  -H "Authorization: Bearer tk_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "ticket_number": "97000",
    "subject": "مشکل فنی",
    "priority": "high",
    "is_vip": 1,
    "comment": "اولین کامنت"
  }'
```

---

## 13. محدودیت‌ها و Future Work

### 13.1. محدودیت‌های فعلی

- ❌ Rate limiting پیاده‌سازی نشده
- ❌ Webhook پشتیبانی نمی‌شود
- ❌ Bulk operations وجود ندارد
- ❌ Filtering پیشرفته محدود است
- ❌ Pagination فقط offset-based

### 13.2. قابل اضافه شدن

- ✅ فیلتر تاریخی: `?from=2026-09-01&to=2026-09-30`
- ✅ Sort سفارشی: `?sort=created_at&order=asc`
- ✅ Bulk create/update/delete
- ✅ Export CSV/JSON
- ✅ Webhook برای رویدادها
- ✅ GraphQL endpoint

---

## 14. تست با Postman

### 14.1. تنظیمات Postman

**Headers:**
```
Authorization: Bearer tk_xxxxxxxxxxxxxxxx
Content-Type: application/json
Accept: application/json
```

**Collection Variables:**
```
base_url = https://<DOMAIN>/tapin/task/api/v1
token    = tk_xxxxxxxxxxxxxxxx
```

### 14.2. نمونه Request

```
Method: POST
URL:    {{base_url}}/tasks/create/
Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
Body (raw JSON):
  {
    "title": "تست از Postman",
    "priority": "high"
  }
```

---

## 15. تماس با توسعه‌دهنده

- برای سؤالات: از خود پنل پیام دهید
- برای گزارش باگ: از طریق تیکت
- مستندات آنلاین: `/api-docs/`

---

## 🔑 خلاصه یک‌خطی

```
REST API v1 با Bearer Token، چهار گروه منابع (tasks، calls، tickets، texts)،
پاسخ JSON استاندارد، ۵ روش احراز هویت، فیلترهای متنوع، و ساختار فایل RESTful.
```

---

**نسخه:** 1.0.0
**آخرین به‌روزرسانی:** 2026-09-21
**مخاطب:** هوش مصنوعی / توسعه‌دهندگان
```

---

## 📌 نکته پایانی

این فایل رو بذار توی:

```
api/AGENT-API.md
```

یا هرجایی که تیم/هوش مصنوعی بهش دسترسی داره. **هر AI که این فایل رو ببینه، دقیقاً می‌فهمه:**

✅ چه Endpoint هایی وجود داره
✅ چه پارامترهایی می‌گیرن
✅ چطور احراز هویت کنه
✅ چطور درخواست بفرسته
✅ چطور خطاها رو تفسیر کنه
✅ ساختار فایل‌ها کجاست
✅ قواعد امنیتی چیه
✅ چه محدودیت‌هایی داره

**بعد از این، هر AI می‌تونه بدون دیدن کد، مستقیماً با API کار کنه.** 🎯