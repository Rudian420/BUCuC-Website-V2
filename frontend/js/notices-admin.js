// Notices compose and batch sending progress controller
(function () {
    const configEl = document.getElementById('notices-config-data');
    let cfg = {};
    if (configEl) {
        try { cfg = JSON.parse(configEl.textContent); } catch (e) {}
    }
    const tok = () => {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    };

    // Compose form helpers
    const compose = document.getElementById('composeForm');
    if (compose) {
        const show = () => {
            const checked = compose.querySelector('input[name=audience]:checked');
            const v = checked ? checked.value : '';
            const gb = document.getElementById('groupBox');
            if (gb) gb.classList.toggle('d-none', v !== 'group');
            const pb = document.getElementById('pickBox');
            if (pb) pb.classList.toggle('d-none', v !== 'pick');
        };
        compose.querySelectorAll('input[name=audience]').forEach(r => r.addEventListener('change', show));
        const count = () => {
            const cntEl = document.getElementById('pickCount');
            if (cntEl) cntEl.textContent = compose.querySelectorAll('input[name="member_ids[]"]:checked').length + ' selected';
        };
        compose.querySelectorAll('input[name="member_ids[]"]').forEach(c => c.addEventListener('change', count));
        const pf = document.getElementById('pickFilter');
        if (pf) {
            pf.addEventListener('input', e => {
                const q = e.target.value.toLowerCase().trim();
                compose.querySelectorAll('.picklist label').forEach(l => {
                    const lq = l.dataset.q || '';
                    l.style.display = !q || lq.includes(q) ? '' : 'none';
                });
            });
        }
        const body = document.getElementById('body');
        document.querySelectorAll('.ph .phbtn').forEach(c => c.addEventListener('click', () => {
            if (!body) return;
            const s = body.selectionStart ?? body.value.length;
            body.value = body.value.slice(0, s) + c.textContent + body.value.slice(body.selectionEnd ?? s);
            body.focus();
        }));
        const rt = document.getElementById('resetTpl');
        if (rt) {
            rt.addEventListener('click', e => {
                e.preventDefault();
                const subj = document.getElementById('subject');
                if (subj && cfg.defaultSubject) subj.value = cfg.defaultSubject;
                if (body && cfg.defaultBody) body.value = cfg.defaultBody;
            });
        }
        compose.addEventListener('submit', e => {
            const checked = compose.querySelector('input[name=audience]:checked');
            const v = checked ? checked.value : '';
            if (v === 'pick' && !compose.querySelector('input[name="member_ids[]"]:checked')) {
                e.preventDefault();
                if (window.showNotification) showNotification('Pick at least one member in the list, or choose a different audience.', 'warning');
                return;
            }
            const confirmPrompt = cfg.confirmMsg || 'Send this notice now?';
            if (!confirm(confirmPrompt)) e.preventDefault();
        });
    }

    // Sending progress
    const runPanel = document.getElementById('runPanel');
    if (runPanel) {
        const id = runPanel.dataset.id;
        const msg = document.getElementById('runMsg');
        const resume = document.getElementById('resumeBtn');
        const paint = d => {
            for (const [k, el] of [['total', 'nTotal'], ['sent', 'nSent'], ['failed', 'nFailed'], ['pending', 'nPending']]) {
                const targetEl = document.getElementById(el);
                if (targetEl) targetEl.textContent = d[k];
            }
            const bar = document.getElementById('bar');
            if (bar) bar.style.width = (d.total ? Math.round(100 * (d.sent + d.failed) / d.total) : 100) + '%';
        };
        const step = async () => {
            if (resume) resume.classList.add('d-none');
            if (msg) msg.textContent = 'Sending…';
            try {
                const fd = new FormData();
                fd.append('id', id);
                const r = await fetch('notices.php?action=batch', { method: 'POST', headers: { 'X-CSRF-Token': tok() }, body: fd });
                const d = await r.json();
                if (!d.success) throw new Error(d.message || 'Sending failed');
                paint(d);
                if (d.done) {
                    if (msg) msg.textContent = `Finished: ${d.sent} sent, ${d.failed} failed.`;
                    if (d.failed) setTimeout(() => location.reload(), 1200);
                    return;
                }
                if (d.limitReached) {
                    if (msg) msg.textContent = 'Paused: the daily sending limit is reached. Come back tomorrow and press Resume.';
                    if (resume) resume.classList.remove('d-none');
                    return;
                }
                setTimeout(step, 400);
            } catch (e) {
                if (msg) msg.textContent = 'Stopped: ' + e.message + '. Press Resume to continue.';
                if (resume) resume.classList.remove('d-none');
            }
        };
        if (resume) resume.addEventListener('click', step);
        if (runPanel.dataset.status !== 'done') step();
        else if (msg) msg.textContent = 'This notice has finished sending.';
    }
})();
