<?php
/**
 * GET /api/v1/
 * اطلاعات کامل API
 */
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');

apiSuccess([
    'name'    => 'Task Manager API',
    'version' => 'v1',
    'base_url' => $scheme . '://' . $host . $base,
    'auth' => [
        'type'   => 'Bearer Token',
        'header' => 'Authorization: Bearer <token>',
    ],
    'endpoints' => [
        'tasks' => [
            ['method' => 'GET',  'url' => '/api/v1/tasks/list/',        'desc' => 'لیست تسک‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/tasks/show/?id=N',   'desc' => 'جزئیات تسک'],
            ['method' => 'GET',  'url' => '/api/v1/tasks/stats/',       'desc' => 'آمار تسک‌ها'],
            ['method' => 'POST', 'url' => '/api/v1/tasks/create/',      'desc' => 'ایجاد تسک'],
            ['method' => 'POST', 'url' => '/api/v1/tasks/update/?id=N', 'desc' => 'ویرایش تسک'],
            ['method' => 'POST', 'url' => '/api/v1/tasks/delete/?id=N', 'desc' => 'حذف تسک'],
            ['method' => 'POST', 'url' => '/api/v1/tasks/toggle/?id=N', 'desc' => 'تغییر وضعیت تسک'],
        ],
        'calls' => [
            ['method' => 'GET',  'url' => '/api/v1/calls/list/',        'desc' => 'لیست درخواست‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/calls/show/?id=N',   'desc' => 'جزئیات درخواست'],
            ['method' => 'GET',  'url' => '/api/v1/calls/stats/',       'desc' => 'آمار درخواست‌ها'],
            ['method' => 'POST', 'url' => '/api/v1/calls/create/',      'desc' => 'ایجاد درخواست'],
            ['method' => 'POST', 'url' => '/api/v1/calls/update/?id=N', 'desc' => 'ویرایش درخواست'],
            ['method' => 'POST', 'url' => '/api/v1/calls/status/?id=N', 'desc' => 'تغییر وضعیت'],
            ['method' => 'POST', 'url' => '/api/v1/calls/delete/?id=N', 'desc' => 'حذف درخواست'],
        ],
        'tickets' => [
            ['method' => 'GET',  'url' => '/api/v1/tickets/list/',        'desc' => 'لیست تیکت‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/tickets/show/?id=N',   'desc' => 'جزئیات تیکت'],
            ['method' => 'GET',  'url' => '/api/v1/tickets/stats/',       'desc' => 'آمار تیکت‌ها'],
            ['method' => 'POST', 'url' => '/api/v1/tickets/create/',      'desc' => 'ایجاد تیکت'],
            ['method' => 'POST', 'url' => '/api/v1/tickets/update/?id=N', 'desc' => 'ویرایش تیکت'],
            ['method' => 'POST', 'url' => '/api/v1/tickets/status/?id=N', 'desc' => 'تغییر وضعیت'],
            ['method' => 'POST', 'url' => '/api/v1/tickets/delete/?id=N', 'desc' => 'حذف تیکت'],
        ],
        'texts' => [
            ['method' => 'GET',  'url' => '/api/v1/texts/list/',           'desc' => 'لیست متن‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/texts/show/?id=XXX',    'desc' => 'جزئیات متن'],
            ['method' => 'GET',  'url' => '/api/v1/texts/stats/',          'desc' => 'آمار متن‌ها'],
            ['method' => 'POST', 'url' => '/api/v1/texts/create/',         'desc' => 'ایجاد متن'],
            ['method' => 'POST', 'url' => '/api/v1/texts/update/?id=XXX',  'desc' => 'ویرایش متن'],
            ['method' => 'POST', 'url' => '/api/v1/texts/delete/?id=XXX',  'desc' => 'حذف متن'],
        ],
        'colleagues' => [
            ['method' => 'GET',  'url' => '/api/v1/colleagues/list/',                    'desc' => 'لیست همکاران'],
            ['method' => 'GET',  'url' => '/api/v1/colleagues/followers/',               'desc' => 'کسانی که منو همکار کردن'],
            ['method' => 'GET',  'url' => '/api/v1/colleagues/blocked/',                 'desc' => 'لیست مسدودشده‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/colleagues/show/?user_id=N',          'desc' => 'جزئیات همکار'],
            ['method' => 'GET',  'url' => '/api/v1/colleagues/stats/',                   'desc' => 'آمار همکاران'],
            ['method' => 'GET',  'url' => '/api/v1/colleagues/search/?mobile=09xxx',     'desc' => 'جستجوی کاربر'],
            ['method' => 'POST', 'url' => '/api/v1/colleagues/create/',                  'desc' => 'افزودن همکار'],
            ['method' => 'POST', 'url' => '/api/v1/colleagues/permissions/?user_id=N',   'desc' => 'ویرایش دسترسی‌ها'],
            ['method' => 'POST', 'url' => '/api/v1/colleagues/block/',                   'desc' => 'مسدود کردن'],
            ['method' => 'POST', 'url' => '/api/v1/colleagues/unblock/',                 'desc' => 'رفع مسدودی'],
            ['method' => 'POST', 'url' => '/api/v1/colleagues/delete/?user_id=N',        'desc' => 'حذف همکار'],
        ],
        'projects' => [
            ['method' => 'GET',  'url' => '/api/v1/projects/list/',                             'desc' => 'لیست پروژه‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/projects/show/?id=N',                        'desc' => 'جزئیات پروژه'],
            ['method' => 'GET',  'url' => '/api/v1/projects/stats/',                            'desc' => 'آمار پروژه‌ها'],
            ['method' => 'GET',  'url' => '/api/v1/projects/members/?id=N',                     'desc' => 'لیست اعضا'],
            ['method' => 'POST', 'url' => '/api/v1/projects/create/',                           'desc' => 'ایجاد پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/update/?id=N',                      'desc' => 'ویرایش پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/delete/?id=N',                      'desc' => 'حذف پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-add/?id=N',                  'desc' => 'افزودن عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-remove/?id=N',               'desc' => 'حذف عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/member-permissions/?id=N',          'desc' => 'ویرایش دسترسی عضو'],
            ['method' => 'POST', 'url' => '/api/v1/projects/leave/?id=N',                       'desc' => 'خروج از پروژه'],
            ['method' => 'POST', 'url' => '/api/v1/projects/block/?id=N',                       'desc' => 'مسدود کردن پروژه'],
        ],

    ],
        'auth_method' => $authMethod,
    'user_id'     => $userId,
]);