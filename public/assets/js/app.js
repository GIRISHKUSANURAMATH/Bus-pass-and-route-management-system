/* Shared front-end helpers (no inline JS anywhere - CSP friendly). */
(function () {
  'use strict';
  const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';

  window.App = {
    csrf: meta('csrf-token'),
    base: meta('base-url'),
    url(path) { return this.base + '/' + String(path).replace(/^\/+/, ''); },

    /** fetch wrapper: JSON in/out, CSRF header, same-origin cookies. */
    async api(path, body, method) {
      const opts = {
        method: method || (body ? 'POST' : 'GET'),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': this.csrf },
      };
      if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
      const res = await fetch(this.url(path), opts);
      let data = {};
      try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Unexpected server response.' }; }
      data.httpStatus = res.status;
      return data;
    },

    /** Read data embedded in <script type="application/json" id="..."> */
    data(id) {
      const el = document.getElementById(id);
      if (!el) return null;
      try { return JSON.parse(el.textContent); } catch (e) { return null; }
    },

    esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },
  };

  document.addEventListener('DOMContentLoaded', () => {
    // Confirm dangerous actions: <form data-confirm="Are you sure?">
    document.querySelectorAll('form[data-confirm]').forEach((f) => {
      f.addEventListener('submit', (ev) => { if (!window.confirm(f.dataset.confirm)) ev.preventDefault(); });
    });
    // Client-side validation (Bootstrap styles); server re-validates everything.
    document.querySelectorAll('form.needs-validation').forEach((f) => {
      f.addEventListener('submit', (ev) => {
        if (!f.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
        f.classList.add('was-validated');
      });
    });
    // Password confirmation check
    document.querySelectorAll('input[data-match]').forEach((inp) => {
      const other = document.getElementById(inp.dataset.match);
      const check = () => inp.setCustomValidity(inp.value === other.value ? '' : 'Passwords do not match');
      inp.addEventListener('input', check); other.addEventListener('input', check);
    });
    // Show / hide password
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.togglePassword);
        inp.type = inp.type === 'password' ? 'text' : 'password';
      });
    });
    // Print buttons
    document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
    // Auto-submit filters
    document.querySelectorAll('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));
    // Image preview for file inputs: <input type=file data-preview="imgId">
    document.querySelectorAll('input[type=file][data-preview]').forEach((inp) => {
      inp.addEventListener('change', () => {
        const img = document.getElementById(inp.dataset.preview);
        if (inp.files && inp.files[0] && img) { img.src = URL.createObjectURL(inp.files[0]); img.classList.remove('d-none'); }
      });
    });
  });
})();
