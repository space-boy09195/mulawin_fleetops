(function () {
  'use strict';

  const userId = String(window.CURRENT_USER_ID ?? 'guest');
  const stateKey = `fleetops:page:${userId}:${window.location.pathname}`;
  const idempotencyKeys = new Map();
  let saveTimer;

  function scrollStateKey() {
    return `fleetops:scroll:${userId}:${window.location.pathname}${window.location.search}`;
  }
  let restoringUrlState = false;

  window.fleetOpsIdempotencyHeaders = function (scope) {
    let key = idempotencyKeys.get(scope);
    if (!key) {
      key = window.crypto?.randomUUID
        ? window.crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
      idempotencyKeys.set(scope, key);
    }
    return { 'Idempotency-Key': key };
  };

  window.fleetOpsCompleteIdempotency = function (scope) {
    idempotencyKeys.delete(scope);
  };

  function readState(key) {
    try {
      const value = sessionStorage.getItem(key);
      return value ? JSON.parse(value) : {};
    } catch (error) {
      console.warn('FleetOps could not read saved page state.', error);
      return {};
    }
  }

  function writeState(key, value) {
    try {
      sessionStorage.setItem(key, JSON.stringify(value));
    } catch (error) {
      console.warn('FleetOps could not save page state.', error);
    }
  }

  function isSafeStateField(field) {
    const type = (field.type || '').toLowerCase();
    const name = (field.name || field.id || '').toLowerCase();
    const explicitState = field.matches('[data-persist-state]');
    const approvedDraft = field.matches('[data-persist-draft][data-persist-safe]');
    return field.matches('[data-persist-state], [data-persist-draft]')
      && (explicitState || approvedDraft)
      && !['password', 'hidden', 'file', 'submit', 'button', 'reset'].includes(type)
      && !/(csrf|token|password|salary|pay|amount|balance|financial|account)/i.test(name);
  }

  function fieldValue(field) {
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked;
    if (field instanceof HTMLSelectElement && field.multiple) {
      return Array.from(field.selectedOptions, option => option.value);
    }
    return field.value;
  }

  function restoreField(field, value) {
    if (field.type === 'checkbox' || field.type === 'radio') {
      field.checked = Boolean(value);
    } else if (field instanceof HTMLSelectElement && field.multiple && Array.isArray(value)) {
      Array.from(field.options).forEach(option => {
        option.selected = value.includes(option.value);
      });
    } else if (typeof value === 'string') {
      field.value = value;
    }
  }

  function saveFields() {
    const state = {};
    document.querySelectorAll('[data-persist-state], [data-persist-draft]').forEach(field => {
      if (!isSafeStateField(field)) return;
      const key = field.dataset.persistKey || field.name || field.id;
      if (key) state[key] = fieldValue(field);
    });
    writeState(stateKey, state);
  }

  function restoreFields() {
    const state = readState(stateKey);
    document.querySelectorAll('[data-persist-state], [data-persist-draft]').forEach(field => {
      if (!isSafeStateField(field)) return;
      const key = field.dataset.persistKey || field.name || field.id;
      if (key && Object.prototype.hasOwnProperty.call(state, key)) {
        restoreField(field, state[key]);
        field.dispatchEvent(new Event(field instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }));
      }
    });
  }

  function activeTabKey(tab) {
    const target = tab.getAttribute('data-bs-target') || tab.getAttribute('href') || '';
    return target.startsWith('#') ? target.slice(1) : '';
  }

  function updateTabUrl(tab) {
    const key = activeTabKey(tab);
    if (!key) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('tab') === key) return;
    url.searchParams.set('tab', key);
    if (restoringUrlState) {
      window.history.replaceState(window.history.state, '', url);
    } else {
      window.history.pushState(window.history.state, '', url);
    }
  }

  function restoreUrlState() {
    restoringUrlState = true;
    const params = new URLSearchParams(window.location.search);
    const targetId = params.get('tab');
    if (targetId && window.bootstrap?.Tab) {
      const tab = Array.from(document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]'))
        .find(item => activeTabKey(item) === targetId);
      if (tab) window.bootstrap.Tab.getOrCreateInstance(tab).show();
    }
    document.querySelectorAll('[data-persist-filter]').forEach(group => {
      const filterKey = group.dataset.persistFilter;
      const filterValue = params.get(filterKey);
      if (filterValue === null) return;
      const button = Array.from(group.querySelectorAll('[data-filter], [data-state-value]'))
        .find(item => (item.dataset.filter ?? item.dataset.stateValue) === filterValue);
      if (button) button.click();
    });
    restoringUrlState = false;
  }

  function updateFilterUrl(button) {
    const group = button.closest('[data-persist-filter]');
    if (!group) return;
    const key = group.dataset.persistFilter;
    const value = button.dataset.filter ?? button.dataset.stateValue;
    if (!key || value === undefined) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get(key) === value) return;
    url.searchParams.set(key, value);
    if (restoringUrlState) {
      window.history.replaceState(window.history.state, '', url);
    } else {
      window.history.pushState(window.history.state, '', url);
    }
  }

  function persistScroll() {
    const key = scrollStateKey();
    writeState(key, { x: window.scrollX, y: window.scrollY });
    document.querySelectorAll('[data-persist-scroll]').forEach((element, index) => {
      try {
        sessionStorage.setItem(
          `${key}:container:${element.id || index}`,
          String(element.scrollTop)
        );
      } catch (error) {
        console.warn('FleetOps could not save container scroll state.', error);
      }
    });
  }

  function restoreScroll() {
    const key = scrollStateKey();
    const position = readState(key);
    if (Number.isFinite(position.x) && Number.isFinite(position.y)) {
      window.scrollTo(position.x, position.y);
    }
    document.querySelectorAll('[data-persist-scroll]').forEach((element, index) => {
      const containerKey = `${key}:container:${element.id || index}`;
      try {
        const value = Number.parseInt(sessionStorage.getItem(containerKey) || '0', 10);
        if (Number.isFinite(value)) element.scrollTop = value;
      } catch (error) {
        console.warn('FleetOps could not restore container scroll state.', error);
      }
    });
  }

  function restoreModal() {
    const modalId = new URLSearchParams(window.location.search).get('view');
    if (!modalId) return;
    const modal = document.getElementById(modalId);
    if (modal?.matches('[data-persist-modal]') && window.bootstrap?.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    restoreModal();
    requestAnimationFrame(() => requestAnimationFrame(restoreScroll));
    window.setTimeout(restoreFields, 0);
    window.setTimeout(restoreUrlState, 0);

    document.addEventListener('input', event => {
      if (event.target.matches('[data-persist-state], [data-persist-draft]')) {
        saveFields();
        const form = event.target.closest('[data-warn-unsaved]');
        if (form) form.dataset.unsaved = 'true';
      }
    });
    document.addEventListener('change', event => {
      if (event.target.matches('[data-persist-state], [data-persist-draft]')) saveFields();
    });
    document.addEventListener('shown.bs.tab', event => updateTabUrl(event.target));
    document.addEventListener('click', event => {
      const button = event.target.closest('[data-persist-filter] [data-filter], [data-persist-filter] [data-state-value]');
      if (button) updateFilterUrl(button);
    });
    document.addEventListener('show.bs.modal', event => {
      if (!event.target.matches('[data-persist-modal]')) return;
      const url = new URL(window.location.href);
      url.searchParams.set('view', event.target.id);
      window.history.replaceState(window.history.state, '', url);
    });
    document.addEventListener('hidden.bs.modal', event => {
      if (!event.target.matches('[data-persist-modal]')) return;
      const url = new URL(window.location.href);
      url.searchParams.delete('view');
      window.history.replaceState(window.history.state, '', url);
    });
    document.addEventListener('submit', event => {
      const form = event.target;
      if (!form.matches('[data-warn-unsaved]')) return;
      form.dataset.unsaved = 'false';
      saveFields();
    });
    window.addEventListener('beforeunload', event => {
      persistScroll();
      const hasUnsavedForm = Array.from(document.querySelectorAll('[data-warn-unsaved]'))
        .some(form => form.dataset.unsaved === 'true');
      if (hasUnsavedForm) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
    window.addEventListener('scroll', () => {
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(persistScroll, 120);
    }, { passive: true });
    window.addEventListener('popstate', restoreUrlState);
    document.querySelectorAll('[data-persist-scroll]').forEach(element => {
      element.addEventListener('scroll', persistScroll, { passive: true });
    });
  });
})();
