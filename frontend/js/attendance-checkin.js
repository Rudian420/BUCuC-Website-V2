// QR check-in controller for attendance_checkin.php
(function () {
    const configEl = document.getElementById('checkin-config-data');
    let cfg = {};
    if (configEl) {
        try { cfg = JSON.parse(configEl.textContent); } catch (e) {}
    }
    const ID = cfg.id || 0;
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf = csrfMeta ? csrfMeta.content : '';
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let token = null, nextChange = 0, skew = 0, open = Boolean(cfg.open), ended = false, shown = '';
    const qrMs = cfg.qrMs || 30000;

    function drawQr(t) {
        if (!window.qrcode) return;
        const url = new URL(`checkin.php?s=${ID}&t=${encodeURIComponent(t)}`, location.href).href;
        const qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        const box = document.getElementById('qr');
        if (box) {
            box.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
            box.dataset.url = url;
        }
    }

    function renderList(list, expected) {
        const countEl = document.getElementById('count');
        if (countEl) countEl.textContent = list.length;
        const expEl = document.getElementById('expected');
        if (expEl) expEl.textContent = expected;
        const key = list.map(c => c.id).join(',');
        if (key === shown) return;
        shown = key;
        const liveEl = document.getElementById('live');
        if (liveEl) {
            liveEl.innerHTML = list.length ? list.map(c => `<li data-id="${c.id}"><div class="who"><b>${esc(c.name)}</b><br><small>${esc(c.studentId)} · ${esc(c.time)}</small></div>
                <span class="chip ${c.status === 'Late' ? 'late' : 'present'}">${esc(c.status)}</span>
                <button type="button" class="btn btn-sm btn-outline-danger" data-remove="${c.id}" aria-label="Remove ${esc(c.name)}"><i class="fas fa-times"></i></button></li>`).join('')
                : '<li class="note">Nobody has checked in yet.</li>';
        }
    }

    async function poll() {
        try {
            const d = await (await fetch(`attendance_checkin.php?id=${ID}&action=state`, { cache: 'no-store' })).json();
            if (!d.success) return;
            skew = d.serverNow - Date.now();
            ended = d.ended;
            if (open && !d.open) { location.reload(); return; }
            if (d.open && d.token !== token) { token = d.token; drawQr(token); }
            nextChange = d.nextChange;
            renderList(d.checkins, d.expected);
        } catch (e) { }
    }

    function tick() {
        if (open && nextChange) {
            const left = Math.max(0, nextChange - (Date.now() + skew));
            const tickEl = document.getElementById('tick');
            if (tickEl) tickEl.textContent = `New code in ${Math.ceil(left / 1000)} s`;
            const barEl = document.getElementById('bar');
            if (barEl) barEl.style.transform = `scaleX(${left / qrMs})`;
            if (left <= 0) { nextChange = 0; poll(); }
        }
        requestAnimationFrame(tick);
    }

    const liveList = document.getElementById('live');
    if (liveList) {
        liveList.addEventListener('click', async e => {
            const b = e.target.closest('[data-remove]');
            if (!b) return;
            const li = b.closest('li');
            const bEl = li ? li.querySelector('b') : null;
            const name = bEl ? bEl.textContent : 'this member';
            let reason = '';
            if (ended) {
                reason = prompt(`Remove ${name}'s check-in? The session has ended, so write a short reason:`) || '';
                if (reason.trim().length < 5) return;
            } else if (!confirm(`Remove ${name}'s check-in?`)) return;

            const fd = new FormData();
            fd.append('action', 'remove');
            fd.append('id', ID);
            fd.append('member_id', b.dataset.remove);
            fd.append('reason', reason);
            fd.append('csrf_token', csrf);
            let d;
            try {
                d = await (await fetch('attendance_checkin.php', { method: 'POST', body: fd })).json();
            } catch (err) {
                if (window.showNotification) showNotification('Could not reach the server, so the check-in was not removed. Check your connection and try again.', 'error');
                return;
            }
            if (!d.success) {
                if (window.showNotification) showNotification(d.error || 'Could not remove the check-in. Reload the page and try again.', 'error');
            } else {
                if (window.showNotification) showNotification(`${name}'s check-in was removed.`, 'success');
            }
            shown = ''; poll();
        });
    }

    poll();
    setInterval(poll, 2500);
    requestAnimationFrame(tick);
})();
