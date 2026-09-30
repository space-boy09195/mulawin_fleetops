(function () {
  'use strict';

  function isFilterSelect(select) {
    const id = (select.id || '').toLowerCase();
    const classes = select.className.toLowerCase();
    const name = (select.name || '').toLowerCase();
    const filterAncestor = select.closest('[class*="filter"], [class*="Filter"]');
    const exportForm = select.closest('form[action*="export"]');
    const attendanceReport = name === 'employee_id' && Boolean(select.closest('form')?.querySelector('[name="attendance_from"]'));

    return id.includes('filter')
      || classes.includes('filter')
      || Boolean(filterAncestor)
      || name === 'period'
      || name === 'granularity'
      || (select.hasAttribute('onchange') && /submit/i.test(select.getAttribute('onchange') || ''))
      || Boolean(select.getAttribute('aria-label')?.toLowerCase().startsWith('export '))
      || Boolean(exportForm && ['status', 'client_id', 'shift'].includes(name))
      || attendanceReport;
  }

  function enhanceFilterSelect(select, index) {
    if (!isFilterSelect(select)) return;

    const selectId = select.id || `fleetops-filter-${index}`;
    select.id = selectId;
    const inputId = `${selectId}-search`;
    const listId = `${inputId}-options`;
    const input = document.createElement('input');
    const list = document.createElement('datalist');

    input.type = 'search';
    input.id = inputId;
    input.className = select.className
      .split(/\s+/)
      .filter(className => className && className !== 'form-select')
      .concat('form-control')
      .join(' ');
    input.style.cssText = select.style.cssText;
    input.setAttribute('list', listId);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute(
      'aria-label',
      select.getAttribute('aria-label')
        || Array.from(document.querySelectorAll(`label[for="${CSS.escape(selectId)}"]`))
          .map(label => label.textContent.trim())
          .filter(Boolean)
          .join(' ')
        || 'Filter options'
    );

    list.id = listId;
    const options = Array.from(select.options);
    const labels = new Map();
    options.forEach(option => {
      const label = option.textContent.trim();
      labels.set(label, (labels.get(label) || 0) + 1);
    });

    const optionValues = new Map();
    options.forEach(option => {
      let label = option.textContent.trim();
      if (labels.get(label) > 1 && option.value !== '') label += ` (${option.value})`;
      const suggestion = document.createElement('option');
      suggestion.value = label;
      list.appendChild(suggestion);
      optionValues.set(label.toLocaleLowerCase(), option.value);
      optionValues.set(option.value.toLocaleLowerCase(), option.value);
    });

    const selected = select.selectedOptions[0];
    const placeholder = options.find(option => option.value === '');
    input.placeholder = placeholder?.textContent.trim() || 'Type to search, then choose an option';
    if (selected && selected.value !== '') {
      const selectedLabel = selected.textContent.trim();
      input.value = labels.get(selectedLabel) > 1
        ? `${selectedLabel} (${selected.value})`
        : selectedLabel;
    }

    input.addEventListener('input', () => {
      const value = input.value.trim();
      if (value === '') {
        select.value = placeholder ? '' : (options[0]?.value ?? '');
        select.dispatchEvent(new Event('change', { bubbles: true }));
        return;
      }
      const mappedValue = optionValues.get(value.toLocaleLowerCase());
      if (mappedValue === undefined) return;
      select.value = mappedValue;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    select.addEventListener('change', () => {
      const current = select.selectedOptions[0];
      if (!current || current.value === '') {
        input.value = '';
        return;
      }
      const label = current.textContent.trim();
      input.value = labels.get(label) > 1 ? `${label} (${current.value})` : label;
    });

    document.querySelectorAll(`label[for="${CSS.escape(selectId)}"]`).forEach(label => {
      label.htmlFor = inputId;
    });
    select.hidden = true;
    select.insertAdjacentElement('afterend', list);
    select.insertAdjacentElement('afterend', input);
  }

  document.querySelectorAll('select').forEach(enhanceFilterSelect);
})();
