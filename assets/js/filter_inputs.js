(function () {
  'use strict';

  const FIXED_CHOICE_FIELDS = new Set([
    'active',
    'granularity',
    'is_active',
    'month',
    'period',
    'priority',
    'shift',
    'status',
    'type',
    'year',
  ]);
  const RECORD_FIELD = /(client|customer|employee|driver|helper|truck|vehicle|route|part|user|supplier|vendor|mechanic|technician)/i;

  function isFilterSelect(select) {
    const id = (select.id || '').toLowerCase();
    const classes = select.className.toLowerCase();
    const name = (select.name || '').toLowerCase();
    const filterAncestor = select.closest('[class*="filter"], [class*="Filter"]');
    const exportForm = select.closest('form[action*="export"]');
    const attendanceReport = name === 'employee_id'
      && Boolean(select.closest('form')?.querySelector('[name="attendance_from"]'));

    const identifiedAsFilter = id.includes('filter')
      || classes.includes('filter')
      || Boolean(filterAncestor)
      || name === 'period'
      || name === 'granularity'
      || (select.hasAttribute('onchange') && /submit/i.test(select.getAttribute('onchange') || ''))
      || Boolean(select.getAttribute('aria-label')?.toLowerCase().startsWith('export '))
      || Boolean(exportForm && ['status', 'client_id', 'shift'].includes(name))
      || attendanceReport
      || select.matches('[data-searchable]');

    if (!identifiedAsFilter || select.multiple || select.matches(':disabled')) return false;

    const optionCount = Array.from(select.options).filter(option => !option.hidden).length;
    const isRecordField = RECORD_FIELD.test(name);
    if (FIXED_CHOICE_FIELDS.has(name) && !isRecordField && !select.matches('[data-searchable]')) return false;

    return isRecordField || select.matches('[data-searchable]') || optionCount > 6;
  }

  function uniqueId(base) {
    let id = base;
    let suffix = 1;
    while (document.getElementById(id)) {
      id = `${base}-${suffix}`;
      suffix += 1;
    }
    return id;
  }

  function enhanceFilterSelect(select, index) {
    if (!isFilterSelect(select) || select.hidden || select.closest('[hidden]')) return;
    if (select.dataset.searchSelectEnhanced === 'true') return;

    let wrapper;
    try {
      const options = Array.from(select.options)
        .map((option, optionIndex) => ({ option, optionIndex }))
        .filter(({ option }) => !option.hidden || option.value === '' || option.selected);
      if (!options.length || !select.parentElement) return;

      const inputId = uniqueId(`fleetops-search-select-${index}`);
      const listId = `${inputId}-options`;
      const labels = select.labels ? Array.from(select.labels) : [];
      const labelText = labels.map(label => label.textContent.trim()).filter(Boolean).join(' ');
      const accessibleName = select.getAttribute('aria-label') || labelText || 'Filter options';
      const emptyOption = options.find(({ option }) => option.value === '' && !option.disabled)?.option;
      const duplicateLabels = new Map();
      options.forEach(({ option }) => {
        const label = option.textContent.trim();
        duplicateLabels.set(label, (duplicateLabels.get(label) || 0) + 1);
      });

      const entries = options.map(({ option, optionIndex }) => {
        const label = option.textContent.trim();
        const duplicateSuffix = duplicateLabels.get(label) > 1
          ? ` (${option.value === '' ? 'blank value' : option.value})`
          : '';
        return {
          option,
          index: optionIndex,
          label,
          display: `${label || (option.value === '' ? 'All' : '(No label)')}${duplicateSuffix}`,
          searchText: `${label} ${option.value}`.toLocaleLowerCase(),
        };
      });

      wrapper = document.createElement('div');
      wrapper.className = 'fleetops-search-select';

      const control = document.createElement('div');
      control.className = 'fleetops-search-select-control';

      const input = document.createElement('input');
      input.type = 'search';
      input.id = inputId;
      input.className = select.className
        .split(/\s+/)
        .filter(className => className && className !== 'form-select')
        .concat('form-control', 'fleetops-search-select-input')
        .join(' ');
      input.style.cssText = select.style.cssText;
      input.autocomplete = 'off';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-haspopup', 'listbox');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-controls', listId);
      input.setAttribute('aria-label', accessibleName);
      input.placeholder = emptyOption?.textContent.trim() || 'Search options';

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'fleetops-search-select-toggle';
      toggle.setAttribute('aria-label', `Show ${accessibleName.toLowerCase()} options`);
      toggle.setAttribute('aria-controls', listId);
      toggle.setAttribute('aria-expanded', 'false');
      toggle.textContent = 'Options';

      control.append(input, toggle);

      let clearButton;
      if (emptyOption) {
        control.classList.add('has-clear');
        clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'fleetops-search-select-clear';
        clearButton.textContent = 'Clear';
        clearButton.setAttribute('aria-label', `Clear ${accessibleName.toLowerCase()}`);
        control.append(clearButton);
      }

      const list = document.createElement('div');
      list.id = listId;
      list.className = 'fleetops-search-select-options';
      list.setAttribute('role', 'listbox');
      list.hidden = true;

      const optionButtons = new Map();
      entries.forEach(entry => {
        const optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'fleetops-search-select-option';
        optionButton.id = `${listId}-option-${entry.index}`;
        optionButton.tabIndex = -1;
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', 'false');
        optionButton.textContent = entry.display;
        optionButton.title = entry.label || entry.option.value;
        optionButton.disabled = entry.option.disabled;
        optionButton.addEventListener('mousedown', event => event.preventDefault());
        optionButton.addEventListener('click', () => selectEntry(entry));
        list.append(optionButton);
        optionButtons.set(entry, optionButton);
      });

      const emptyState = document.createElement('div');
      emptyState.className = 'fleetops-search-select-empty';
      emptyState.textContent = 'No matching options';
      emptyState.hidden = true;
      list.append(emptyState);

      wrapper.append(control, list);

      let filteredEntries = entries;
      let highlightedIndex = -1;
      let query = '';

      function currentEntry() {
        return entries.find(entry => entry.option.selected);
      }

      function updateInputFromSelect() {
        const selected = currentEntry();
        input.value = selected && selected.option.value !== '' ? selected.display : '';
        input.title = selected?.label || '';
      }

      function setExpanded(expanded) {
        list.hidden = !expanded;
        input.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.setAttribute(
          'aria-label',
          `${expanded ? 'Hide' : 'Show'} ${accessibleName.toLowerCase()} options`
        );
      }

      function selectEntry(entry) {
        if (!entry || entry.option.disabled) return;
        const changed = select.selectedIndex !== entry.index;
        select.selectedIndex = entry.index;
        query = '';
        if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
        updateInputFromSelect();
        setExpanded(false);
      }

      function setHighlight(index) {
        if (!filteredEntries.length) {
          highlightedIndex = -1;
          input.removeAttribute('aria-activedescendant');
          return;
        }
        const enabledIndices = filteredEntries
          .map((entry, entryIndex) => entry.option.disabled ? -1 : entryIndex)
          .filter(entryIndex => entryIndex >= 0);
        if (!enabledIndices.length) {
          highlightedIndex = -1;
          input.removeAttribute('aria-activedescendant');
          return;
        }
        highlightedIndex = enabledIndices.reduce((best, entryIndex) => {
          if (best < 0) return entryIndex;
          return Math.abs(entryIndex - index) < Math.abs(best - index) ? entryIndex : best;
        }, -1);
        filteredEntries.forEach((entry, entryIndex) => {
          const button = optionButtons.get(entry);
          const active = entryIndex === highlightedIndex;
          button.classList.toggle('active', active);
          button.setAttribute('aria-selected', String(active));
          if (active) {
            input.setAttribute('aria-activedescendant', button.id);
            if (!list.hidden) button.scrollIntoView?.({ block: 'nearest' });
          }
        });
      }

      function renderOptions() {
        const normalizedQuery = query.trim().toLocaleLowerCase();
        const matchingEntries = normalizedQuery
          ? entries.filter(entry => entry.searchText.includes(normalizedQuery))
          : entries;
        const matchingSet = normalizedQuery ? new Set(matchingEntries) : null;
        filteredEntries = matchingEntries;
        entries.forEach(entry => {
          const button = optionButtons.get(entry);
          button.hidden = matchingSet ? !matchingSet.has(entry) : false;
          button.classList.remove('active');
          button.setAttribute('aria-selected', 'false');
        });
        emptyState.hidden = filteredEntries.length > 0;
        if (!filteredEntries.length) {
          highlightedIndex = -1;
          input.removeAttribute('aria-activedescendant');
          return;
        }
        const selectedIndex = filteredEntries.indexOf(currentEntry());
        setHighlight(selectedIndex < 0 ? 0 : selectedIndex);
      }

      function openList() {
        if (!query) renderOptions();
        setExpanded(true);
      }

      input.addEventListener('focus', () => {
        if (!input.value) query = '';
        openList();
        input.select();
      });

      input.addEventListener('input', () => {
        query = input.value;
        if (!query.trim() && emptyOption && select.value !== '') {
          selectEntry(entries.find(entry => entry.option === emptyOption));
        }
        query = input.value;
        renderOptions();
        setExpanded(true);
      });

      input.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          if (list.hidden) openList();
          const direction = event.key === 'ArrowDown' ? 1 : -1;
          const current = highlightedIndex < 0 ? (direction > 0 ? -1 : filteredEntries.length) : highlightedIndex;
          for (let step = 1; step <= filteredEntries.length; step += 1) {
            const next = (current + direction * step + filteredEntries.length) % filteredEntries.length;
            if (!filteredEntries[next].option.disabled) {
              setHighlight(next);
              break;
            }
          }
        } else if (event.key === 'Enter' && !list.hidden) {
          event.preventDefault();
          selectEntry(filteredEntries[highlightedIndex]);
        } else if (event.key === 'Escape') {
          setExpanded(false);
          query = '';
          updateInputFromSelect();
        }
      });

      input.addEventListener('blur', () => {
        window.setTimeout(() => {
          if (!wrapper.contains(document.activeElement)) {
            setExpanded(false);
            query = '';
            updateInputFromSelect();
          }
        }, 0);
      });

      toggle.addEventListener('mousedown', event => event.preventDefault());
      toggle.addEventListener('click', () => {
        if (list.hidden) {
          input.focus();
          openList();
        } else {
          setExpanded(false);
        }
      });

      if (clearButton) {
        clearButton.addEventListener('mousedown', event => event.preventDefault());
        clearButton.addEventListener('click', () => {
          selectEntry(entries.find(entry => entry.option === emptyOption));
        });
      }

      select.addEventListener('change', updateInputFromSelect);
      select.insertAdjacentElement('afterend', wrapper);
      labels.forEach(label => {
        label.htmlFor = inputId;
      });
      select.classList.add('fleetops-search-select-native');
      select.hidden = true;
      select.dataset.searchSelectEnhanced = 'true';
      updateInputFromSelect();
    } catch (error) {
      wrapper?.remove();
      select.hidden = false;
      console.warn('FleetOps could not enhance a filter select; the original select remains available.', error);
    }
  }

  document.querySelectorAll('select').forEach(enhanceFilterSelect);
})();
