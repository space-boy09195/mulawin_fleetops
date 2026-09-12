document.addEventListener('DOMContentLoaded', () => {
  const url = `${window.APP_BASE ?? ''}/ajax/clients_handler.php`;
  const form = document.getElementById('clientForm');
  const alertEl = document.getElementById('clientFormAlert');

  function message(text, type = 'danger') {
    alertEl.className = `alert alert-${type}`;
    alertEl.textContent = text;
    alertEl.classList.remove('d-none');
  }

  async function post(values) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({...values, [window.CSRF_TOKEN_NAME]: window.CSRF_TOKEN}),
    });
    const text = await response.text();
    try { return JSON.parse(text); }
    catch { throw new Error(`Server returned HTTP ${response.status} instead of JSON.`); }
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    alertEl.classList.add('d-none');
    const data = Object.fromEntries(new FormData(form).entries());
    if (!data.client_name.trim()) return message('Client name is required.');
    if (data.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
      return message('Enter a valid client email address.');
    }
    if (!window.confirm(`${data.client_id ? 'Update' : 'Add'} this client?`)) return;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await post({action: 'save', ...data});
      if (result.success) window.location.reload();
      else message(result.message || 'Could not save client.');
    } catch (error) { message(error.message || 'Network error.'); }
    finally { button.disabled = false; }
  });

  document.querySelectorAll('.js-toggle-client').forEach(button => {
    button.addEventListener('click', async () => {
      if (!window.confirm(`${button.dataset.active === '1' ? 'Deactivate' : 'Activate'} this client?`)) return;
      button.disabled = true;
      try {
        const result = await post({action: 'toggle', client_id: button.dataset.id});
        if (result.success) window.location.reload();
        else message(result.message || 'Could not update client.');
      } catch (error) { message(error.message || 'Network error.'); }
      finally { button.disabled = false; }
    });
  });
});
