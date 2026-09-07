(function () {
  'use strict';
  function setup() {
    document.querySelectorAll('.relay-launcher').forEach(function (button) {
      button.addEventListener('click', function () {
        var panel = document.getElementById(button.getAttribute('aria-controls'));
        var open = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', String(!open));
        panel.hidden = open;
        if (!open) panel.querySelector('input:not(.relay-hp)').focus();
      });
    });
    document.querySelectorAll('.relay-form').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        var result = form.querySelector('.relay-result');
        button.disabled = true;
        result.textContent = '';
        fetch(refineryRelay.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(form))) })
          .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message); return data; }); })
          .then(function (data) { form.reset(); result.textContent = data.message; result.className = 'relay-result relay-success'; })
          .catch(function (error) { result.textContent = error.message || refineryRelay.error; result.className = 'relay-result relay-error'; })
          .finally(function () { button.disabled = false; });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
}());
