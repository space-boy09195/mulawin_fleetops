'use strict';

(function () {
  const BASE = window.APP_BASE ?? '';
  const btn = document.getElementById('createBackupBtn');
  if (!btn) return;

  btn.addEventListener('click', () => {
    btn.disabled = true;
    const original = btn.innerHTML;
    btn.textContent = 'Backing up...';

    fetch(BASE + '/ajax/backup_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'create', [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN }),
    })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          location.reload();
        } else {
          alert(res.message || 'Backup failed.');
          btn.disabled = false;
          btn.innerHTML = original;
        }
      })
      .catch(() => {
        alert('Backup failed. Please try again.');
        btn.disabled = false;
        btn.innerHTML = original;
      });
  });
})();
