// Attendance session sheet controller: marks, change tracking, filtering
(function () {
    const rows = [...document.querySelectorAll('.mrow')];
    if (!rows.length) return;
    let filterState = '', dirty = false;
    const state = row => {
        const m = row.querySelector('.mark');
        return m ? m.value : '';
    };
    function refresh() {
        let n = 0;
        rows.forEach(row => {
            const orig = row.querySelector('input[name^=orig]');
            const changed = state(row) !== (orig ? orig.value : '');
            row.classList.toggle('changed', changed);
            if (changed) n++;
        });
        dirty = n > 0;
        const changedEl = document.getElementById('changed');
        if (changedEl) changedEl.textContent = n ? `${n} change${n > 1 ? 's' : ''} not saved yet` : 'No changes';
        applyFilter();
    }
    function applyFilter() {
        const qEl = document.getElementById('q');
        const q = qEl ? qEl.value.trim().toLowerCase() : '';
        rows.forEach(row => {
            const s = state(row) || 'none';
            const dq = row.dataset.q || '';
            row.hidden = (q && !dq.includes(q)) || (filterState && s !== filterState);
        });
    }
    rows.forEach(row => row.querySelectorAll('.seg input').forEach(i => i.addEventListener('change', () => {
        const m = row.querySelector('.mark');
        if (m) m.value = i.value;
        refresh();
    })));
    const allPresBtn = document.getElementById('allPresent');
    if (allPresBtn) {
        allPresBtn.addEventListener('click', () => {
            rows.forEach(row => {
                if (row.hidden) return;
                const s = state(row);
                if (s === '' || s === 'absent') {
                    const m = row.querySelector('.mark');
                    if (m) m.value = 'present';
                    const inp = row.querySelector('.seg input[value=present]');
                    if (inp) inp.checked = true;
                }
            });
            refresh();
        });
    }
    const qInp = document.getElementById('q');
    if (qInp) qInp.addEventListener('input', applyFilter);
    document.querySelectorAll('.fchips button').forEach(b => b.addEventListener('click', () => {
        filterState = b.dataset.f;
        document.querySelectorAll('.fchips button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
        applyFilter();
    }));
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    const mf = document.getElementById('marksForm');
    if (mf) mf.addEventListener('submit', () => { dirty = false; });
    refresh();
})();
