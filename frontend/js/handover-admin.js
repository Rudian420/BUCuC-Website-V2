// Handover editor interactions: add row, filter contacts table, auto-grow textareas
(function () {
    // "Add a row" in table sections: copy the last row with empty boxes and the next index
    document.querySelectorAll('.addrow').forEach(b => b.addEventListener('click', () => {
        const box = b.previousElementSibling;
        if (!box) return;
        const rows = box.querySelectorAll('.erow');
        const last = rows[rows.length - 1];
        const n = rows.length;
        if (!last) return;
        const copy = last.cloneNode(true);
        copy.querySelectorAll('textarea, input').forEach(el => {
            el.value = '';
            el.name = el.name.replace(/rows\[\d+\]/, `rows[${n}]`);
        });
        box.appendChild(copy);
        const ta = copy.querySelector('textarea');
        if (ta) ta.focus();
    }));

    // contacts search inside the table
    document.querySelectorAll('.tfilter').forEach(f => f.addEventListener('input', () => {
        const q = f.value.trim().toLowerCase();
        const next = f.nextElementSibling;
        if (next) {
            next.querySelectorAll('tbody tr').forEach(tr => {
                const trq = tr.dataset.q || '';
                tr.hidden = q !== '' && !trq.includes(q);
            });
        }
    }));

    // let a long text box grow with its content
    document.querySelectorAll('.edit-rows textarea').forEach(t => {
        const fit = () => {
            t.style.height = 'auto';
            t.style.height = t.scrollHeight + 2 + 'px';
        };
        t.addEventListener('input', fit);
        fit();
    });
})();
