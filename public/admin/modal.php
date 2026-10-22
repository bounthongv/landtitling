<?php
// public/admin/modal.php — the shared popup dialog for the back-office.
//
// One dialog for every screen. A page adds a form by putting its markup in a
// <template> and pointing a button at it:
//
//   <template id="village-form">
//     <form method="post">
//       <input type="hidden" name="id" value="0">
//       <input name="code" required>
//       <input name="name_lo" required>
//       ...
//     </form>
//   </template>
//
//   <button class="js-modal" data-form="village-form"
//           data-title="Edit village" data-submit="Update village"
//           data-id="12" data-code="1301001" data-name-lo="ບ້ານ ...">Edit</button>
//
// The button's data-* attributes fill the form fields of the same name
// (data-district-id -> district_id, data-do-action -> do_action), so one
// template both adds and edits. A button with no data-* values opens the form
// empty — that is the "Add" case. data-readonly="code" locks a field.
require_once __DIR__ . '/../../lib/i18n.php';
?>
<div id="modal-bg" class="modal-bg" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="modal">
    <h2 id="modal-title"></h2>
    <div id="modal-content"></div>
    <div class="modal-actions">
      <button class="btn btn-red" type="button" onclick="closeModal()"><?= te('Cancel') ?></button>
      <button class="btn btn-green" type="submit" form="modal-form" id="modal-submit-btn"><?= te('Save') ?></button>
    </div>
  </div>
</div>
<script>
(function () {
  const DEFAULT_SUBMIT = <?= json_encode(t('Save'), JSON_UNESCAPED_UNICODE) ?>;
  let lastFocused = null;

  // data-district-id -> dataset.districtId (dataset keys are camelCase).
  function camel(name) {
    return name.replace(/[-_]+(.)/g, (m, c) => c.toUpperCase());
  }

  function pick(btn, name) {
    if (!btn || !btn.dataset) return undefined;
    const key = camel(name);
    return Object.prototype.hasOwnProperty.call(btn.dataset, key) ? btn.dataset[key] : undefined;
  }

  function applyValue(field, value) {
    if (field.tagName === 'SELECT') {
      let found = false;
      for (const opt of field.options) {
        if (opt.value === value) { found = true; break; }
      }
      if (!found && value !== '') {
        // The referenced row is missing from the list (filtered out, or a stale
        // page) — keep the value selectable instead of silently dropping it.
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value;
        field.appendChild(opt);
      }
      field.value = value;
      return;
    }
    if (field.type === 'checkbox') {
      field.checked = (value === '1' || value === 'true');
      return;
    }
    field.value = value;
  }

  // Copy the trigger button's data-* values into the cloned form.
  function fill(form, btn) {
    const locked = String((btn && btn.dataset.readonly) || '').split(/\s+/).filter(Boolean);
    form.querySelectorAll('[name]').forEach((field) => {
      const value = pick(btn, field.name);
      if (value !== undefined) applyValue(field, value);
      if (locked.indexOf(field.name) !== -1 && field.tagName !== 'SELECT') {
        field.readOnly = true;
      }
    });
  }

  // openModal(templateId, { title, submit, trigger, onOpen })
  function openModal(templateId, opts) {
    opts = opts || {};
    const tpl = document.getElementById(templateId);
    const bg = document.getElementById('modal-bg');
    if (!tpl || !bg) return;

    const content = document.getElementById('modal-content');
    content.innerHTML = '';
    content.appendChild(tpl.content.cloneNode(true));

    const form = content.querySelector('form');
    if (form) { form.id = 'modal-form'; }

    document.getElementById('modal-title').textContent = opts.title || '';

    const submitBtn = document.getElementById('modal-submit-btn');
    if (opts.submit === null) {
      submitBtn.style.display = 'none';
    } else {
      submitBtn.style.display = '';
      submitBtn.textContent = opts.submit || DEFAULT_SUBMIT;
    }

    if (form && opts.trigger) fill(form, opts.trigger);

    lastFocused = document.activeElement;
    bg.classList.add('show');

    if (form) {
      const first = form.querySelector('input:not([type="hidden"]):not([readonly]), select, textarea');
      if (first) { try { first.focus(); } catch (e) { /* focus is best effort */ } }
    }
    if (typeof opts.onOpen === 'function') opts.onOpen(form);
  }

  function closeModal() {
    const bg = document.getElementById('modal-bg');
    if (!bg) return;
    bg.classList.remove('show');
    document.getElementById('modal-content').innerHTML = '';
    if (lastFocused && lastFocused.focus) {
      try { lastFocused.focus(); } catch (e) { /* focus is best effort */ }
    }
    lastFocused = null;
  }

  // Any button with .js-modal opens the template named by its data-form.
  document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('.js-modal') : null;
    if (!btn) return;
    e.preventDefault();
    openModal(btn.dataset.form, {
      title: btn.dataset.title || '',
      submit: btn.dataset.submit || DEFAULT_SUBMIT,
      trigger: btn
    });
  });

  // Click on the backdrop closes the dialog; Escape does too.
  document.addEventListener('click', function (e) {
    if (e.target.id === 'modal-bg') closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const bg = document.getElementById('modal-bg');
    if (bg && bg.classList.contains('show')) closeModal();
  });

  window.openModal = openModal;
  window.closeModal = closeModal;
})();
</script>
