/**
 * documents.js — Mulawin FleetOps
 * Handles: file upload with drag-and-drop, delete confirm, card filtering.
 */

'use strict';

(function () {

  const BASE       = window.APP_BASE ?? '';
  const AJAX_URL   = BASE + '/ajax/document_handler.php';
  const MAX_BYTES  = 10 * 1024 * 1024; // 10 MB

  const ALLOWED_MIMES = [
    'application/pdf',
    'image/jpeg', 'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  ];

  const MIME_ICONS = {
    'application/pdf':   'bi-file-earmark-pdf',
    'image/jpeg':        'bi-file-earmark-image',
    'image/png':         'bi-file-earmark-image',
    'application/msword': 'bi-file-earmark-word',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'bi-file-earmark-word',
    'application/vnd.ms-excel': 'bi-file-earmark-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'bi-file-earmark-excel',
  };

  // ── DOM refs ──────────────────────────────────────────────────────────────
  const filterDocType   = document.getElementById('filterDocType');
  const filterDocSearch = document.getElementById('filterDocSearch');
  const docGrid         = document.getElementById('docGrid');

  const uploadModal     = document.getElementById('uploadModal');
  const dropZone        = document.getElementById('dropZone');
  const fileInput       = document.getElementById('fileInput');
  const filePreview     = document.getElementById('filePreview');
  const previewIcon     = document.getElementById('previewIcon');
  const previewName     = document.getElementById('previewName');
  const previewSize     = document.getElementById('previewSize');
  const clearFile       = document.getElementById('clearFile');
  const uploadDocType   = document.getElementById('uploadDocType');
  const uploadTripId    = document.getElementById('uploadTripId');
  const uploadDescription = document.getElementById('uploadDescription');
  const submitUploadBtn = document.getElementById('submitUploadBtn');
  const uploadBtnSpinner = document.getElementById('uploadBtnSpinner');
  const uploadBtnText   = document.getElementById('uploadBtnText');
  const uploadAlert     = document.getElementById('uploadAlert');
  const uploadProgress  = document.getElementById('uploadProgress');
  const uploadProgressBar = document.getElementById('uploadProgressBar');

  const deleteModal     = document.getElementById('deleteModal');
  const deleteFileName  = document.getElementById('deleteFileName');
  const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
  const deleteBtnSpinner = document.getElementById('deleteBtnSpinner');
  const deleteAlert     = document.getElementById('deleteAlert');

  let selectedFile     = null;
  let pendingDeleteId  = null;

  // ── Helpers ───────────────────────────────────────────────────────────────
  function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)    return (bytes / 1024).toFixed(1)    + ' KB';
    return bytes + ' B';
  }

  function showAlert(el, html, type = 'danger') {
    if (!el) return;
    el.className = `alert alert-${type}`;
    el.innerHTML = html;
    el.classList.remove('d-none');
  }

  function hideAlert(el) {
    if (!el) return;
    el.classList.add('d-none');
    el.innerHTML = '';
  }

  function setBusy(btn, spinner, busy) {
    if (!btn || !spinner) return;
    btn.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
  }

  // ── Filtering ─────────────────────────────────────────────────────────────
  function applyFilters() {
    if (!docGrid) return;
    const typeVal   = filterDocType?.value   ?? '';
    const searchVal = (filterDocSearch?.value ?? '').toLowerCase();
    let visibleCount = 0;

    docGrid.querySelectorAll('.doc-card').forEach(card => {
      const matchType   = !typeVal   || card.dataset.type === typeVal;
      const matchSearch = !searchVal || (card.dataset.search ?? '').includes(searchVal);
      const isMatch = matchType && matchSearch;
      card.classList.toggle('doc-card-hidden', !isMatch);
      if (isMatch) visibleCount++;
    });

    const noResults = document.getElementById('noDocResults');
    noResults?.classList.toggle('d-none', visibleCount > 0);
  }

  filterDocType?.addEventListener('change', applyFilters);
  filterDocSearch?.addEventListener('input', applyFilters);

  // ── File selection ────────────────────────────────────────────────────────
  function setFile(file) {
    hideAlert(uploadAlert);

    if (!ALLOWED_MIMES.includes(file.type)) {
      showAlert(uploadAlert, 'File type not allowed. Use PDF, JPG, PNG, DOCX, or XLSX.');
      return;
    }

    if (file.size > MAX_BYTES) {
      showAlert(uploadAlert, 'File exceeds the 10 MB limit.');
      return;
    }

    selectedFile = file;

    if (previewIcon)  previewIcon.className  = `bi ${MIME_ICONS[file.type] ?? 'bi-file-earmark'} doc-preview-icon`;
    if (previewName)  previewName.textContent = file.name;
    if (previewSize)  previewSize.textContent = fmtSize(file.size);

    dropZone?.classList.add('d-none');
    filePreview?.classList.remove('d-none');

    if (submitUploadBtn) submitUploadBtn.disabled = false;
  }

  function clearSelectedFile() {
    selectedFile = null;
    if (fileInput)  fileInput.value = '';
    dropZone?.classList.remove('d-none');
    filePreview?.classList.add('d-none');
    if (submitUploadBtn) submitUploadBtn.disabled = true;
  }

  // Click on drop zone → open file picker
  dropZone?.addEventListener('click', () => fileInput?.click());

  // "Browse" span inside drop zone
  dropZone?.querySelector('.doc-dropzone-link')?.addEventListener('click', e => {
    e.stopPropagation();
    fileInput?.click();
  });

  fileInput?.addEventListener('change', () => {
    if (fileInput.files[0]) setFile(fileInput.files[0]);
  });

  clearFile?.addEventListener('click', clearSelectedFile);

  // ── Drag & drop ───────────────────────────────────────────────────────────
  dropZone?.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('doc-dropzone-active');
  });

  dropZone?.addEventListener('dragleave', () => {
    dropZone.classList.remove('doc-dropzone-active');
  });

  dropZone?.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('doc-dropzone-active');
    const file = e.dataTransfer?.files[0];
    if (file) setFile(file);
  });

  // ── Reset modal ───────────────────────────────────────────────────────────
  uploadModal?.addEventListener('hidden.bs.modal', () => {
    clearSelectedFile();
    if (uploadDocType)    uploadDocType.value    = '';
    if (uploadTripId)     uploadTripId.value     = '';
    if (uploadDescription) uploadDescription.value = '';
    hideAlert(uploadAlert);
    uploadProgress?.classList.add('d-none');
    if (uploadProgressBar) uploadProgressBar.style.width = '0%';
    setBusy(submitUploadBtn, uploadBtnSpinner, false);
    if (uploadBtnText) uploadBtnText.textContent = 'Upload';
  });

  // ── Submit upload ─────────────────────────────────────────────────────────
  submitUploadBtn?.addEventListener('click', () => {
    hideAlert(uploadAlert);

    if (!selectedFile) {
      showAlert(uploadAlert, 'Please select a file to upload.');
      return;
    }

    const docType = uploadDocType?.value ?? '';
    if (!docType) {
      showAlert(uploadAlert, 'Please select a document type.');
      return;
    }

    const formData = new FormData();
    formData.append('action',      'upload');
    formData.append('file',        selectedFile);
    formData.append('doc_type',    docType);
    formData.append('trip_id',     uploadTripId?.value     ?? '');
    formData.append('description', uploadDescription?.value.trim() ?? '');

    setBusy(submitUploadBtn, uploadBtnSpinner, true);
    if (uploadBtnText) uploadBtnText.textContent = 'Uploading…';
    uploadProgress?.classList.remove('d-none');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable && uploadProgressBar) {
        uploadProgressBar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
      }
    });

    xhr.addEventListener('load', () => {
      setBusy(submitUploadBtn, uploadBtnSpinner, false);
      if (uploadBtnText) uploadBtnText.textContent = 'Upload';

      try {
        const res = JSON.parse(xhr.responseText);
        if (res.success) {
          bootstrap.Modal.getInstance(uploadModal)?.hide();
          window.location.reload();
        } else {
          showAlert(uploadAlert, res.message ?? 'Upload failed.');
          uploadProgress?.classList.add('d-none');
        }
      } catch {
        showAlert(uploadAlert, 'Unexpected server response. Please try again.');
        uploadProgress?.classList.add('d-none');
      }
    });

    xhr.addEventListener('error', () => {
      setBusy(submitUploadBtn, uploadBtnSpinner, false);
      if (uploadBtnText) uploadBtnText.textContent = 'Upload';
      showAlert(uploadAlert, 'Network error. Please try again.');
      uploadProgress?.classList.add('d-none');
    });

    xhr.send(formData);
  });

  // ── Delete flow ───────────────────────────────────────────────────────────
  docGrid?.addEventListener('click', e => {
    const btn = e.target.closest('.btn-doc-delete');
    if (!btn) return;

    pendingDeleteId = btn.dataset.id ?? null;
    if (deleteFileName) deleteFileName.textContent = btn.dataset.name ?? '';
    hideAlert(deleteAlert);
    setBusy(confirmDeleteBtn, deleteBtnSpinner, false);

    new bootstrap.Modal(deleteModal).show();
  });

  deleteModal?.addEventListener('hidden.bs.modal', () => {
    pendingDeleteId = null;
    hideAlert(deleteAlert);
    setBusy(confirmDeleteBtn, deleteBtnSpinner, false);
  });

  confirmDeleteBtn?.addEventListener('click', () => {
    if (!pendingDeleteId) return;

    hideAlert(deleteAlert);
    setBusy(confirmDeleteBtn, deleteBtnSpinner, true);

    fetch(AJAX_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body:    new URLSearchParams({ action: 'delete', document_id: pendingDeleteId }),
    })
      .then(r => r.json())
      .then(res => {
        setBusy(confirmDeleteBtn, deleteBtnSpinner, false);
        if (res.success) {
          bootstrap.Modal.getInstance(deleteModal)?.hide();
          // Remove card from DOM without full reload
          const card = docGrid?.querySelector(`.doc-card [data-id="${pendingDeleteId}"]`)?.closest('.doc-card')
                    ?? docGrid?.querySelector(`[data-id="${pendingDeleteId}"]`)?.closest('.doc-card');
          if (card) card.remove();
          pendingDeleteId = null;
        } else {
          showAlert(deleteAlert, res.message ?? 'Failed to delete document.');
        }
      })
      .catch(() => {
        setBusy(confirmDeleteBtn, deleteBtnSpinner, false);
        showAlert(deleteAlert, 'Network error. Please try again.');
      });
  });

})();