'use strict';

(function () {
  const form = document.getElementById('automationControlForm');
  if (!form) return;

  form.addEventListener('submit', (event) => {
    if (!window.confirm('Change the automation engine setting?')) {
      event.preventDefault();
    }
  });
})();
