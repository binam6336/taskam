
document.addEventListener('contextmenu', function (e) {
    e.preventDefault();
    return false;
});

/* ================== غیرفعال کردن سلکت همه با Ctrl+A ================== */
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag !== 'input' && tag !== 'textarea') {
            e.preventDefault();
            return false;
        }
    }

    if ((e.ctrlKey || e.metaKey) && (e.key === 'u' || e.key === 'U')) {
        e.preventDefault();
        return false;
    }
});

/* ================== غیرفعال کردن درگ تصاویر/متن ================== */
document.addEventListener('dragstart', function (e) {
    if (e.target.tagName === 'IMG' || e.target.tagName === 'A') {
        e.preventDefault();
    }
});

/* ============================================================
   ⌨️ Ctrl+S / Cmd+S — ذخیره درخواست (ثبت یا ویرایش)
============================================================ */
document.addEventListener('keydown', function (e) {
    const isSaveCombo = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey &&
        (e.key === 's' || e.key === 'S' || e.keyCode === 83);

    if (!isSaveCombo) return;

    const createModal = document.getElementById('createRequestModal');
    const editModal = document.getElementById('editRequestModal');

    const isCreateOpen = createModal && createModal.classList.contains('active');
    const isEditOpen = editModal && editModal.classList.contains('active');

    if (isCreateOpen) {
        e.preventDefault();
        e.stopPropagation();
        const btn = document.getElementById('createRequestSubmitBtn');
        if (btn && !btn.disabled) btn.click();
        return false;
    }

    if (isEditOpen) {
        e.preventDefault();
        e.stopPropagation();
        const btn = document.getElementById('editRequestSubmitBtn');
        if (btn && !btn.disabled) btn.click();
        return false;
    }

    e.preventDefault();
    return false;
}, true);

/* ============================================================
   Toast
============================================================ */
let appToastTimer = null;

function showAppToast(text, type = 'success') {
    const toast = document.getElementById('appToast');
    if (!toast) return;
    document.getElementById('appToastText').textContent = text;
    const icon = toast.querySelector('i');
    toast.classList.remove('toast-success', 'toast-error', 'toast-info');
    if (type === 'success') {
        icon.className = 'fas fa-check-circle';
        toast.classList.add('toast-success');
    } else if (type === 'error') {
        icon.className = 'fas fa-exclamation-circle';
        toast.classList.add('toast-error');
    } else {
        icon.className = 'fas fa-info-circle';
        toast.classList.add('toast-info');
    }
    toast.classList.add('active');
    clearTimeout(appToastTimer);
    appToastTimer = setTimeout(() => toast.classList.remove('active'), 2500);
}

/* ============================================================
   🖱️ کلیک روی ردیف → باز شدن مودال ویرایش
============================================================ */
function handleRequestRowClick(row, event) {
    const target = event.target;
    if (target.closest('.custom-checkbox') ||
        target.closest('.action-link-delete') ||
        target.closest('.assignee-picker') ||
        target.closest('a') ||
        target.closest('button')) {
        return;
    }

    if (row.getAttribute('data-can-edit') !== '1') return;

    try {
        const req = JSON.parse(row.getAttribute('data-request'));
        const canReassign = row.getAttribute('data-can-reassign') === '1';
        openEditModal(req, canReassign);
    } catch (err) {
        console.error('خطا در باز کردن مودال ویرایش:', err);
    }
}

/* ================== Assignee Picker ================== */
function renderAssigneeDropdown(pickerId, selectedId) {
    const picker = document.getElementById(pickerId);
    const dropdown = picker.querySelector('.assignee-picker__dropdown');
    const colleagues = ASSIGNEE_OPTIONS.colleagues || [];
    const self = ASSIGNEE_OPTIONS.self;

    let html = `<div class="assignee-picker__section-label">خودم</div>`;
    html += renderAssigneeOption(self, selectedId);

    if (colleagues.length > 0) {
        html += `<div class="assignee-picker__section-label">همکاران</div>`;
        colleagues.forEach(c => {
            html += renderAssigneeOption(c, selectedId);
        });
    } else {
        html += `<div class="assignee-picker__section-label">همکاران</div>`;
        html += `<div class="assignee-picker__empty">هنوز همکاری اضافه نکرده‌اید.<br><a href="../colleagues/index.php"><i class="fas fa-user-plus"></i> افزودن همکار</a></div>`;
    }

    dropdown.innerHTML = html;
    dropdown.querySelectorAll('.assignee-picker__option').forEach(opt => {
        opt.addEventListener('click', function (e) {
            e.stopPropagation();
            selectAssignee(pickerId, this.getAttribute('data-id'));
        });
    });
}

function renderAssigneeOption(person, selectedId) {
    const isSelected = String(person.id) === String(selectedId);
    const avatarClass = person.is_self ? 'is-self' : '';
    const selectedClass = isSelected ? 'selected' : '';
    const avatarContent = person.avatar_url ?
        `<img src="${escapeHtml(person.avatar_url)}" alt="">` :
        escapeHtml(person.initial || '?');

    return `
            <div class="assignee-picker__option ${selectedClass}" data-id="${person.id}">
                <div class="assignee-picker__option-avatar ${avatarClass}">${avatarContent}</div>
                <div class="assignee-picker__option-info">
                    <div class="assignee-picker__option-name">${escapeHtml(person.name)}</div>
                    ${person.mobile ? `<div class="assignee-picker__option-mobile">${escapeHtml(person.mobile)}</div>` : ''}
                </div>
            </div>
        `;
}

function selectAssignee(pickerId, id) {
    const picker = document.getElementById(pickerId);
    const input = picker.querySelector('input[type="hidden"]');
    const avatarEl = picker.querySelector('.assignee-picker__avatar');
    const nameEl = picker.querySelector('.assignee-picker__name');
    const mobileEl = picker.querySelector('.assignee-picker__mobile');

    let person = String(ASSIGNEE_OPTIONS.self.id) === String(id) ?
        ASSIGNEE_OPTIONS.self :
        (ASSIGNEE_OPTIONS.colleagues || []).find(c => String(c.id) === String(id));
    if (!person) return;

    input.value = person.id;

    if (person.avatar_url) {
        avatarEl.innerHTML = `<img src="${escapeHtml(person.avatar_url)}" alt="">`;
    } else {
        avatarEl.textContent = person.initial || '?';
    }
    avatarEl.className = 'assignee-picker__avatar' + (person.is_self ? ' is-self' : '');

    nameEl.textContent = person.name;
    mobileEl.textContent = person.mobile || '';

    picker.classList.remove('open');
    renderAssigneeDropdown(pickerId, person.id);
}

function toggleAssigneePicker(pickerId) {
    const picker = document.getElementById(pickerId);
    if (picker.classList.contains('is-disabled')) return;
    const wasOpen = picker.classList.contains('open');

    document.querySelectorAll('.assignee-picker.open').forEach(p => {
        if (p.id !== pickerId) p.classList.remove('open');
    });

    if (wasOpen) {
        picker.classList.remove('open');
    } else {
        renderAssigneeDropdown(pickerId, picker.querySelector('input[type="hidden"]').value);
        picker.classList.add('open');
    }
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.assignee-picker')) {
        document.querySelectorAll('.assignee-picker.open').forEach(p => p.classList.remove('open'));
    }
});

/* ================== توابع اصلی ================== */
function switchTab(tabName, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    btn.classList.add('active');
}

function openModal(id) {
    document.getElementById(id).classList.add('active');
    if (id === 'createRequestModal') {
        document.getElementById('createAssigneeInput').value = CURRENT_USER_ID;
        const createAvatarEl = document.getElementById('createAssigneeAvatar');
        if (ASSIGNEE_OPTIONS.self.avatar_url) {
            createAvatarEl.innerHTML = `<img src="${escapeHtml(ASSIGNEE_OPTIONS.self.avatar_url)}" alt="من">`;
        } else {
            createAvatarEl.textContent = ASSIGNEE_OPTIONS.self.initial || '?';
        }
        createAvatarEl.className = 'assignee-picker__avatar is-self';
        document.getElementById('createAssigneeName').textContent = 'خودم';
        document.getElementById('createAssigneeMobile').textContent = ASSIGNEE_OPTIONS.self.mobile || '';
        renderAssigneeDropdown('createAssigneePicker', CURRENT_USER_ID);
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function openEditModal(req, canReassign) {
    document.getElementById('edit_request_id').value = req.id;
    document.getElementById('edit_first_name').value = req.first_name || '';
    document.getElementById('edit_last_name').value = req.last_name || '';
    document.getElementById('edit_mobile').value = req.mobile || '';
    document.getElementById('edit_email').value = req.email || '';
    document.getElementById('edit_store').value = req.store || '';
    document.getElementById('edit_website').value = req.website || '';
    document.getElementById('edit_description').value = req.description || '';

    const targetId = req.assignee_id ? String(req.assignee_id) : String(CURRENT_USER_ID);
    selectAssignee('editAssigneePicker', targetId);

    const picker = document.getElementById('editAssigneePicker');
    if (canReassign) picker.classList.remove('is-disabled');
    else picker.classList.add('is-disabled');

    // ⭐ نمایش/مخفی کردن دکمه اشتراک‌گذاری
    const shareBtn = document.getElementById('edit_share_btn');
    if (shareBtn) shareBtn.style.display = 'inline-flex';

    openModal('editRequestModal');
}

/* ============================================================
   ⭐ کپی لینک اختصاصی درخواست
============================================================ */
function copyCallLink() {
    const reqId = document.getElementById('edit_request_id').value;
    if (!reqId) return;
    const btn = document.getElementById('edit_share_btn');
    if (btn) btn.classList.add('copied');

    fetch('index.php?get_call_token=' + encodeURIComponent(reqId), {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(r => r.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('❌ copyCallLink raw:', text);
                throw new Error('invalid');
            }
            if (data.status !== 'success') {
                showAppToast(data.message || 'خطا در ساخت لینک', 'error');
                if (btn) btn.classList.remove('copied');
                return;
            }
            const fullUrl = new URL(data.url, window.location.href).href;
            const done = () => {
                showAppToast('لینک اختصاصی درخواست کپی شد', 'success');
                setTimeout(() => {
                    if (btn) btn.classList.remove('copied');
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(fullUrl).then(done).catch(() => {
                    fallbackCopyText(fullUrl);
                    done();
                });
            } else {
                fallbackCopyText(fullUrl);
                done();
            }
        })
        .catch(err => {
            console.error('copyCallLink error:', err);
            showAppToast('خطا در ساخت لینک', 'error');
            if (btn) btn.classList.remove('copied');
        });
}

function fallbackCopyText(text) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    } catch (e) {
        console.error('fallback copy error', e);
    }
}

function submitCallRequest(e) {
    e.preventDefault();
    const form = document.getElementById('createRequestForm');
    const formData = new FormData(form);
    formData.append('add_call_request', '1');

    fetch('index.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.reload();
            } else {
                alert(data.message || 'خطا در ثبت درخواست');
            }
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}

function completeRequest(id, checkbox) {
    const row = document.getElementById('request-row-' + id);
    row.classList.add('animating');
    checkbox.disabled = true;

    fetch('index.php?complete=1&id=' + id, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message || 'شما دسترسی تکمیل این درخواست را ندارید.');
                checkbox.disabled = false;
                row.classList.remove('animating');
                return;
            }
            if (data.status === 'success' && data.affected > 0) {
                row.style.transition = 'opacity 0.25s ease';
                row.style.opacity = '0';
                setTimeout(() => window.location.reload(), 250);
            } else {
                window.location.reload();
            }
        })
        .catch(() => {
            checkbox.disabled = false;
            row.classList.remove('animating');
        });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function (m) {
        return map[m];
    });
}

/* ================== HAMBURGER ================== */
function toggleSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');

    if (sidebar.classList.contains('open')) {
        closeSidebar();
    } else {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        btn.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');
    if (!sidebar || !overlay || !btn) return;
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    btn.classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});

let resizeTimer;
window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
        if (window.innerWidth > 900) closeSidebar();
    }, 150);
});

/* ============================================================
   ⭐ باز کردن خودکار مودال با لینک اشتراکی
============================================================ */
document.addEventListener('DOMContentLoaded', function () {
    if (window.SHARED_CALL_DATA) {
        try {
            openEditModal(window.SHARED_CALL_DATA, window.SHARED_CALL_PERMS.reassign);
        } catch (err) {
            console.error('auto-open shared call error:', err);
        }
    }
});