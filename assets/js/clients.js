document.addEventListener('DOMContentLoaded', () => {
  const url = `${window.APP_BASE ?? ''}/ajax/clients_handler.php`;
  const form = document.getElementById('clientForm');
  const alertEl = document.getElementById('clientFormAlert');
  const modalTitle = document.getElementById('clientModalTitle');
  const clientType = document.getElementById('clientType');
  const parentSelect = document.getElementById('parentClient');
  const parentWrap = document.getElementById('parentClientWrap');
  const addButton = document.getElementById('addClientButton');

  function updateParentOptions() {
    if (!parentSelect || !clientType) return;
    const isForwarder = clientType.value === 'Forwarder';
    parentWrap?.classList.toggle('d-none', isForwarder);
    if (isForwarder) parentSelect.value = '';
    [...parentSelect.options].forEach(option => {
      option.disabled = Boolean(option.value && option.value === form.elements.client_id.value);
    });
  }

  function resetClientForm() {
    form?.reset();
    const id = document.getElementById('clientId');
    if (id) id.value = '';
    if (modalTitle) modalTitle.textContent = 'Add Client';
    updateParentOptions();
  }

  addButton?.addEventListener('click', resetClientForm);
  clientType?.addEventListener('change', updateParentOptions);
  document.querySelectorAll('.js-edit-client').forEach(button => {
    button.addEventListener('click', () => {
      resetClientForm();
      const values = {
        clientId: button.dataset.id,
        client_name: button.dataset.name,
        client_type: button.dataset.type,
        parent_client_id: button.dataset.parent === '0' ? '' : button.dataset.parent,
        contact_person: button.dataset.contact,
        phone: button.dataset.phone,
        email: button.dataset.email,
        address: button.dataset.address,
        notes: button.dataset.notes,
      };
      Object.entries(values).forEach(([key, value]) => {
        const input = form.elements.namedItem(key);
        if (input) input.value = value ?? '';
      });
      if (modalTitle) modalTitle.textContent = 'Edit Client';
      updateParentOptions();
    });
  });

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
    if (data.parent_client_id && data.parent_client_id === data.client_id) {
      return message('A client cannot be its own parent.');
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
