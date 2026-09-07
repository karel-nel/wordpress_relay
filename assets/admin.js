(function () {
  'use strict';
  document.querySelectorAll('.relay-source input').forEach(function (input) {
    input.addEventListener('change', function () { input.closest('.relay-source').classList.toggle('is-selected', input.checked); });
  });
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.querySelector(button.dataset.copy);
      navigator.clipboard.writeText(input.value).then(function () {
        var old = button.textContent; button.textContent = 'Copied';
        window.setTimeout(function () { button.textContent = old; }, 1200);
      });
    });
  });
  document.querySelectorAll('[data-reveal]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.querySelector(button.dataset.reveal);
      input.type = input.type === 'password' ? 'text' : 'password';
      button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    });
  });
}());
