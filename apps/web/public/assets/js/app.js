/* ReliableForm — tiny progressive-enhancement niceties.
   Everything still works with this file missing (except the builder). */
(function () {
  'use strict';

  // Confirm dialogs (delete form, etc.)
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        ev.preventDefault();
      }
    }
  });

  // Copy-to-clipboard buttons: <button data-copy="text to copy">
  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!btn) {
      return;
    }
    var text = btn.getAttribute('data-copy') || '';

    function feedback() {
      var original = btn.textContent;
      btn.textContent = 'Copied!';
      btn.classList.add('copied');
      window.setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('copied');
      }, 1500);
    }

    function fallbackCopy() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* best effort */ }
      document.body.removeChild(ta);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(feedback, function () {
        fallbackCopy();
        feedback();
      });
    } else {
      fallbackCopy();
      feedback();
    }
  });

  // Flash messages fade out after a few seconds.
  Array.prototype.forEach.call(document.querySelectorAll('.flash'), function (el) {
    window.setTimeout(function () {
      el.classList.add('flash-hide');
      window.setTimeout(function () {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }, 450);
    }, 6000);
  });
})();
