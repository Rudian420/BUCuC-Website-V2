// Attendance admin: audience picker, search filtering, picked count
(function () {
    const pickBox = document.getElementById('pickBox');
    document.querySelectorAll('input[name=audience]').forEach(r => {
        r.addEventListener('change', () => {
            if (pickBox) pickBox.classList.toggle('d-none', r.value !== 'pick' || !r.checked);
        });
    });

    const filter = (input, rows) => {
        if (!input) return;
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            document.querySelectorAll(rows).forEach(el => {
                const dq = el.dataset.q || '';
                el.hidden = q !== '' && !dq.includes(q);
            });
        });
    };

    filter(document.getElementById('pickFilter'), '.picklist label');
    filter(document.getElementById('repFilter'), '#repTable tbody tr');

    const pc = document.getElementById('pickCount');
    const countPicked = () => {
        if (pc) pc.textContent = document.querySelectorAll('.picklist input:checked').length + ' picked';
    };
    document.querySelectorAll('.picklist input').forEach(i => i.addEventListener('change', countPicked));
    countPicked();
})();
