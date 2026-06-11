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

  /* Conditional logic on the public form. Rules ship in the
     #rf-conditions JSON tag; field wrappers carry data-field. This mirrors
     conditions_visible_fields() in apps/web/src/conditions.php EXACTLY —
     change the semantics in both places or not at all:
     - every field defaults visible;
     - a target with ≥1 show rule is visible only if ≥1 of them matches;
     - any matching hide rule hides (hide wins over show);
     - rules never chain: evaluation reads raw input values, never visibility.
     Hidden wrappers get display:none and their inputs disabled so the
     answers never submit (the server drops them anyway). */
  (function () {
    var rulesEl = document.getElementById('rf-conditions');
    var form = document.querySelector('.public-form form');
    if (!rulesEl || !form) {
      return;
    }
    var rules;
    try {
      rules = JSON.parse(rulesEl.textContent || '[]');
    } catch (e) {
      return;
    }
    if (!Array.isArray(rules) || rules.length === 0) {
      return;
    }

    // Non-empty trimmed value(s) for a field — [] when unanswered. Reads
    // disabled inputs too (raw answers, see the no-chaining rule above).
    function selectedValues(fid) {
      var out = [];
      var els = form.querySelectorAll(
        '[name="answers[' + fid + ']"], [name="answers[' + fid + '][]"]'
      );
      Array.prototype.forEach.call(els, function (el) {
        if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) {
          return;
        }
        var v = String(el.value || '').trim();
        if (v !== '') {
          out.push(v);
        }
      });
      return out;
    }

    // Matching mirrors conditions_rule_matches(): absent/empty answer ⇒
    // equals/contains never match, not_equals matches; arrays match ANY
    // selected option (not_equals = NO option equals the value).
    function ruleMatches(cond) {
      var selected = selectedValues(String(cond.field || ''));
      var value = String(cond.value || '');
      if (selected.length === 0) {
        return cond.op === 'not_equals';
      }
      if (cond.op === 'equals') {
        return selected.indexOf(value) !== -1;
      }
      if (cond.op === 'not_equals') {
        return selected.indexOf(value) === -1;
      }
      if (cond.op === 'contains') {
        var needle = value.toLowerCase();
        return selected.some(function (v) {
          return v.toLowerCase().indexOf(needle) !== -1;
        });
      }
      return false;
    }

    function apply() {
      var hasShowRule = {};
      var shown = {};
      var hidden = {};
      rules.forEach(function (rule) {
        if (!rule || !rule.if || !rule.then) {
          return;
        }
        var target = String(rule.then.target || '');
        var matches = ruleMatches(rule.if);
        if (rule.then.action === 'show') {
          hasShowRule[target] = true;
          if (matches) {
            shown[target] = true;
          }
        } else if (rule.then.action === 'hide' && matches) {
          hidden[target] = true;
        }
      });
      Array.prototype.forEach.call(form.querySelectorAll('[data-field]'), function (row) {
        var fid = row.getAttribute('data-field');
        var visible = !((hasShowRule[fid] && !shown[fid]) || hidden[fid]);
        row.style.display = visible ? '' : 'none';
        Array.prototype.forEach.call(
          row.querySelectorAll('input, select, textarea'),
          function (el) { el.disabled = !visible; }
        );
      });
    }

    form.addEventListener('input', apply);
    form.addEventListener('change', apply);
    apply();
  })();

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
