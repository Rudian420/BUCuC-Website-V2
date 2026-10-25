// Reusable clear-all modal controller for pending_applications.php and shortlisted_members.php
(function () {
    const clearAllBtn = document.getElementById('clearAllBtn');
    const modalEl = document.getElementById('clearAllModal');
    const confirmInput = document.getElementById('confirmationInput');
    const confirmBtn = document.getElementById('confirmClearAllBtn');
    if (!clearAllBtn || !modalEl || !confirmInput || !confirmBtn) return;

    const action = clearAllBtn.getAttribute('data-action') || (confirmBtn.getAttribute('data-action') || (location.pathname.includes('shortlisted') ? 'clear_all_shortlisted' : 'clear_all_applications'));
    const isShortlisted = action === 'clear_all_shortlisted' || location.pathname.includes('shortlisted');
    const phrase = clearAllBtn.getAttribute('data-phrase') || (isShortlisted ? 'CLEAR ALL SHORTLISTED' : 'CLEAR ALL APPLICATIONS');
    const endpoint = isShortlisted ? '../backend/Action/clear_all_shortlisted.php' : '../backend/Action/clear_all_applications.php';

    function setConfirmState(ready) {
        confirmBtn.disabled = !ready;
        confirmBtn.classList.toggle('btn-warning', ready);
        confirmBtn.classList.toggle('btn-danger', !ready);
        confirmBtn.innerHTML = ready
            ? '<i class="fas fa-check me-1"></i>Confirmed - ' + (isShortlisted ? 'Clear All Shortlisted' : 'Clear All Applications')
            : '<i class="fas fa-trash-alt me-1"></i>' + (isShortlisted ? 'Clear All Shortlisted' : 'Clear All Applications');
    }

    clearAllBtn.addEventListener('click', () => {
        if (window.bootstrap && bootstrap.Modal) {
            new bootstrap.Modal(modalEl).show();
            confirmInput.value = '';
            setConfirmState(false);
        }
    });

    confirmInput.addEventListener('input', e => setConfirmState(e.target.value.trim() === phrase));

    confirmBtn.addEventListener('click', function () {
        const confirmationText = confirmInput.value.trim();
        if (confirmationText !== phrase) {
            if (window.showNotification) showNotification(`Please type "${phrase}" exactly to confirm.`, 'error');
            return;
        }

        const originalContent = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Clearing...';
        confirmBtn.disabled = true;

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrf = csrfMeta ? csrfMeta.content : '';

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ action: action, confirmation: confirmationText })
        })
            .then(response => response.text())
            .then(text => {
                let data;
                try { data = JSON.parse(text); } catch (e) {
                    throw new Error('Server returned invalid response: ' + text.substring(0, 100));
                }
                if (data.success) {
                    const inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                    if (window.showNotification) showNotification(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (window.showNotification) showNotification(data.message, 'error');
                }
                confirmBtn.innerHTML = originalContent;
                confirmBtn.disabled = false;
            })
            .catch(error => {
                if (window.showNotification) showNotification('Error: ' + error.message, 'error');
                confirmBtn.innerHTML = originalContent;
                confirmBtn.disabled = false;
            });
    });
})();
