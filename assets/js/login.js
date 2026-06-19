// ============================================================
// assets/js/login.js
// Handles: password visibility toggle + submit spinner
// No jQuery — vanilla JS only
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

  // ---- Password Visibility Toggle ------------------------
  const toggleBtn  = document.getElementById('togglePassword');
  const passwordEl = document.getElementById('password');
  const toggleIcon = document.getElementById('toggleIcon');

  if (toggleBtn && passwordEl) {
    toggleBtn.addEventListener('click', () => {
      const isPassword = passwordEl.type === 'password';
      passwordEl.type  = isPassword ? 'text' : 'password';

      // Swap icon
      toggleIcon.className = isPassword
        ? 'bi bi-eye'
        : 'bi bi-eye-slash';
    });
  }

  // ---- Submit Spinner ------------------------------------
  // Shows a loading state so the user knows the form is processing
  const form       = document.getElementById('loginForm');
  const loginBtn   = document.getElementById('loginBtn');
  const btnText    = document.getElementById('loginBtnText');
  const btnSpinner = document.getElementById('loginBtnSpinner');

  if (form) {
    form.addEventListener('submit', (e) => {
      // Basic client-side check (server also validates)
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value;

      if (!username || !password) {
        // Let native HTML5 validation handle it — don't show spinner
        return;
      }

      // Disable button and show spinner
      loginBtn.disabled    = true;
      btnText.classList.add('d-none');
      btnSpinner.classList.remove('d-none');
    });
  }

  // ---- Auto-dismiss alerts after 5 seconds ---------------
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach((alert) => {
    setTimeout(() => {
      // Use Bootstrap's dismiss API if available
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      // Fade out manually since these alerts may not have data-bs-dismiss
      alert.style.transition = 'opacity 0.5s';
      alert.style.opacity    = '0';
      setTimeout(() => alert.remove(), 500);
    }, 5000);
  });

});
