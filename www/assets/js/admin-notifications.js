document.addEventListener('DOMContentLoaded', function () {
    var bell     = document.getElementById('notifBell');
    var badge    = document.getElementById('notifBadge');
    var dropdown = document.getElementById('notifDropdown');
    var list     = document.getElementById('notifList');
    if (!bell || !badge || !dropdown || !list) return;

    function setCount(n) {
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }
    }

    function pollCount() {
        fetch('/admin/notifications/unread-count', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { setCount(data.count || 0); })
            .catch(function () {});
    }

    function renderItems(items) {
        list.innerHTML = '';
        if (items.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'admin-notif-empty';
            empty.textContent = list.dataset.emptyText || '';
            list.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var el = document.createElement(item.url ? 'a' : 'div');
            el.className = 'admin-notif-item';
            if (item.url) el.href = item.url;

            var msg = document.createElement('span');
            msg.textContent = item.message;
            el.appendChild(msg);

            var time = document.createElement('span');
            time.className = 'admin-notif-item-time';
            time.textContent = item.created_at;
            el.appendChild(time);

            list.appendChild(el);
        });
    }

    function openDropdown() {
        dropdown.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        fetch('/admin/notifications/open', { method: 'POST', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderItems(data.items || []);
                setCount(0);
            })
            .catch(function () {});
    }

    function closeDropdown() {
        dropdown.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', function (e) {
        e.stopPropagation();
        if (dropdown.hidden) {
            openDropdown();
        } else {
            closeDropdown();
        }
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.hidden && !dropdown.contains(e.target) && e.target !== bell) {
            closeDropdown();
        }
    });

    pollCount();
    setInterval(pollCount, 30000);
});
