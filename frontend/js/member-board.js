// Member board directory: SB, ASB, GB member listings, promotion/demotion and deletion
(function () {
    const dataEl = document.getElementById('member-board-data');
    let cfg = {};
    if (dataEl) {
        try { cfg = JSON.parse(dataEl.textContent); } catch (e) {}
    }

    const BOARD = cfg.board || '';
    const PROXY_URL = cfg.proxyUrl || '';
    const MEMBERS = cfg.members || [];
    const ATT = cfg.att || null;
    const CAN = cfg.can || { gbAsb: false, sp: false, delete: false };

    const POSITIONS = ['GB', 'ASB', 'SB'];
    const POSITION_NAMES = { GB: 'GB', ASB: 'ASB', SB: 'Secretary Panel (SB)' };
    const BADGE_CLASS = { GB: 'status-gb', ASB: 'status-asb', SB: 'status-sb' };

    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function attLine(m) {
        if (!ATT) return '';
        const fmt = p => p === null ? '—' : p + '%';
        const cls = p => p === null ? 'att-none' : (p < ATT.low ? 'att-low' : 'att-ok');
        return `<a class="att-line" href="attendance_member.php?id=${m.id}" title="Attendance: overall, and ${escapeHtml(ATT.semester)}">
            <span class="${cls(m.att)}">Attendance ${fmt(m.att)}</span> · <span class="${cls(m.attSem)}">${escapeHtml(ATT.semester)}: ${fmt(m.attSem)}</span></a>`;
    }

    function allowedMoves(from) {
        const i = POSITIONS.indexOf(from);
        return POSITIONS.filter((p, j) => Math.abs(i - j) === 1 && ((p === 'SB' || from === 'SB') ? CAN.sp : CAN.gbAsb));
    }

    function getInitials(name) {
        return name.split(' ').filter(w => w.length > 0).map(w => w.charAt(0).toUpperCase()).join('').substring(0, 2);
    }

    function getAvatarColor(index) {
        const colors = [
            'linear-gradient(45deg, #007bff, #0056b3)',
            'linear-gradient(45deg, #28a745, #20c997)',
            'linear-gradient(45deg, #ffc107, #ffeb3b)',
            'linear-gradient(45deg, #17a2b8, #138496)',
            'linear-gradient(45deg, #dc3545, #c82333)',
            'linear-gradient(45deg, #6f42c1, #5a32a3)'
        ];
        return colors[index % colors.length];
    }

    function setBadge(row, position) {
        const badge = row.querySelector('.status-badge');
        if (!badge) return;
        badge.className = `status-badge ${BADGE_CLASS[position] || 'status-sb'}`;
        badge.innerHTML = `<i class="fas fa-star me-1"></i>${escapeHtml(position)}`;
    }

    function refreshCount() {
        const total = document.querySelectorAll('#membersTable tbody tr').length;
        const totalEl = document.getElementById('totalMembers');
        if (totalEl) totalEl.textContent = total;
        if (total === 0) {
            const tableCont = document.getElementById('membersTableContainer');
            if (tableCont) tableCont.style.display = 'none';
            const emptyEl = document.getElementById('emptyState');
            if (emptyEl) emptyEl.classList.remove('d-none');
        }
    }

    async function callSheet(payload) {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrf = csrfMeta ? csrfMeta.content : '';
        const response = await fetch(PROXY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(payload)
        });
        let data = null;
        try { data = await response.json(); } catch (e) { }
        if (!response.ok || !data || data.success === false) {
            throw new Error((data && (data.error || data.message)) || `HTTP ${response.status}`);
        }
        return data;
    }

    async function deleteMember(row, button) {
        const { studentId, name } = row.dataset;
        if (!confirm(`Remove "${name}" (Student ID: ${studentId}) from the member list?\n\nThis cannot be undone.`)) return;

        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting...';
        button.disabled = true;

        try {
            const res = await callSheet({ action: 'delete', id: row.dataset.id });
            if (res.warning && window.showNotification) showNotification(`Removed. Note: ${res.warning}`, 'warning');
            if (window.showNotification) showNotification(`${name} has been deleted`, 'success');
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => { row.remove(); refreshCount(); }, 300);
        } catch (error) {
            button.innerHTML = originalContent;
            button.disabled = false;
            if (window.showNotification) showNotification(`Could not delete ${name}: ${error.message}`, 'error');
        }
    }

    function renderMoveSelect(row, current) {
        const cell = row.querySelector('.move-cell');
        if (!cell) return;
        const moves = allowedMoves(current);
        if (!moves.length) {
            cell.innerHTML = '<span class="text-muted small">No permission to move</span>';
            return;
        }
        cell.innerHTML = `<select class="custom-select" data-original-value="${escapeHtml(current)}">
            <option value="${escapeHtml(current)}" selected>${escapeHtml(POSITION_NAMES[current] || current)} (now)</option>
            ${moves.map(p => `<option value="${p}">${POSITIONS.indexOf(p) > POSITIONS.indexOf(current) ? 'Promote to' : 'Demote to'} ${POSITION_NAMES[p]}</option>`).join('')}
        </select>`;
        cell.querySelector('select').addEventListener('change', e => updatePosition(row, e.target));
    }

    async function updatePosition(row, select) {
        const position = select.value;
        const previous = select.dataset.originalValue;
        if (!position || position === previous) return;

        select.disabled = true;
        try {
            const res = await callSheet({ action: 'update', id: row.dataset.id, position });
            if (res.warning && window.showNotification) setTimeout(() => showNotification(`Saved, but ${res.warning}`, 'warning'), 400);
            setBadge(row, position);
            renderMoveSelect(row, position);
            if (window.showNotification) showNotification(`Moved to ${POSITION_NAMES[position]}`, 'success');
        } catch (error) {
            select.value = previous;
            if (window.showNotification) showNotification(`Could not update position: ${error.message}`, 'error');
        } finally {
            select.disabled = false;
        }
    }

    Promise.resolve(MEMBERS)
        .then(members => {
            const ls = document.getElementById('loadingState');
            if (ls) ls.style.display = 'none';
            const lu = document.getElementById('lastUpdated');
            if (lu) lu.textContent = new Date().toLocaleTimeString();

            if (!members || members.length === 0) {
                const es = document.getElementById('emptyState');
                if (es) es.classList.remove('d-none');
                return;
            }

            const tbody = document.querySelector('#membersTable tbody');
            if (!tbody) return;
            tbody.innerHTML = '';

            members.forEach((member, index) => {
                const row = document.createElement('tr');
                row.dataset.studentId = member.studentId;
                row.dataset.id = member.id;
                row.dataset.name = member.name;
                row.innerHTML = `
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="member-avatar me-3" style="background: ${getAvatarColor(index)};">${escapeHtml(getInitials(member.name))}</div>
                            <div><div class="fw-bold">${escapeHtml(member.name)}</div>${ATT ? attLine(member) : ''}</div>
                        </div>
                    </td>
                    <td>${escapeHtml(member.studentId)}</td>
                    <td>${escapeHtml(member.gsuite)}</td>
                    <td><span class="status-badge"></span></td>
                    <td class="move-cell"></td>
                    <td>
                        ${CAN.delete ? '<button class="btn-delete"><i class="fas fa-trash"></i>Delete</button>' : '<span class="text-muted small">—</span>'}
                    </td>`;
                setBadge(row, member.currentPosition);
                renderMoveSelect(row, member.currentPosition);
                if (CAN.delete) {
                    const btnDel = row.querySelector('.btn-delete');
                    if (btnDel) btnDel.addEventListener('click', e => deleteMember(row, e.currentTarget));
                }
                tbody.appendChild(row);
            });

            refreshCount();
            const mtc = document.getElementById('membersTableContainer');
            if (mtc) mtc.style.display = 'block';
        })
        .catch(err => {
            const ls = document.getElementById('loadingState');
            if (ls) ls.style.display = 'none';
            const et = document.getElementById('emptyTitle');
            if (et) et.textContent = 'Could not load the member sheet';
            const ex = document.getElementById('emptyText');
            if (ex) ex.textContent = 'Check your connection and that the Google Sheet is still published as CSV.';
            const es = document.getElementById('emptyState');
            if (es) es.classList.remove('d-none');
        });
})();
