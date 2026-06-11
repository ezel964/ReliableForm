/* ReliableForm builder — vanilla JS, no dependencies.
   State is an array of field objects serialized into the hidden #fields_json
   input on submit. Initial state comes from the <script type="application/json"
   id="builder-initial"> tag (edit mode / redisplay after a validation error).
   Field ids are "f_" + 6 random [a-z0-9] chars and stay stable across edits. */
(function () {
  'use strict';

  var form = document.getElementById('builder-form');
  var listEl = document.getElementById('field-list');
  var paletteEl = document.getElementById('palette-buttons');
  var hiddenEl = document.getElementById('fields_json');
  if (!form || !listEl || !paletteEl || !hiddenEl) {
    return;
  }

  var TYPES = [
    { type: 'text', name: 'Short text' },
    { type: 'textarea', name: 'Paragraph' },
    { type: 'email', name: 'Email' },
    { type: 'number', name: 'Number' },
    { type: 'select', name: 'Dropdown' },
    { type: 'radio', name: 'Multiple choice' },
    { type: 'checkbox', name: 'Checkboxes' },
    { type: 'date', name: 'Date' },
    { type: 'rating', name: 'Rating' },
    { type: 'file', name: 'File upload' }
  ];
  var TYPE_NAMES = {};
  TYPES.forEach(function (t) { TYPE_NAMES[t.type] = t.name; });

  var OPTION_TYPES = ['select', 'radio', 'checkbox'];
  var PLACEHOLDER_TYPES = ['text', 'textarea', 'email', 'number'];

  var state = loadInitial();

  buildPalette();
  renderList();

  form.addEventListener('submit', function (ev) {
    if (state.length === 0) {
      ev.preventDefault();
      window.alert('Add at least one field before saving.');
      return;
    }
    hiddenEl.value = JSON.stringify(state);
  });

  /* ---------- state ---------- */

  function loadInitial() {
    var el = document.getElementById('builder-initial');
    if (!el) {
      return [];
    }
    var parsed;
    try {
      parsed = JSON.parse(el.textContent || '[]');
    } catch (e) {
      return [];
    }
    if (!Array.isArray(parsed)) {
      return [];
    }
    var out = [];
    parsed.forEach(function (raw) {
      var field = normalize(raw, out);
      if (field) {
        out.push(field);
      }
    });
    return out;
  }

  // Coerce a loaded field into a well-formed one; drop entries with no usable type.
  function normalize(raw, existing) {
    if (!raw || typeof raw !== 'object' || TYPE_NAMES[raw.type] === undefined) {
      return null;
    }
    var field = {
      id: (typeof raw.id === 'string' && /^f_[a-z0-9]{6}$/.test(raw.id)) ? raw.id : genId(existing),
      type: raw.type,
      label: typeof raw.label === 'string' ? raw.label : '',
      required: raw.required === true
    };
    if (PLACEHOLDER_TYPES.indexOf(field.type) !== -1) {
      field.placeholder = typeof raw.placeholder === 'string' ? raw.placeholder : '';
    }
    if (OPTION_TYPES.indexOf(field.type) !== -1) {
      var options = Array.isArray(raw.options)
        ? raw.options.filter(function (o) { return typeof o === 'string'; })
        : [];
      field.options = options.length ? options : ['Option 1'];
    }
    if (field.type === 'rating') {
      var max = parseInt(raw.max, 10);
      field.max = (max >= 1 && max <= 10) ? max : 5;
    }
    return field;
  }

  function genId(pool) {
    var taken = pool || state;
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    for (;;) {
      var id = 'f_';
      for (var i = 0; i < 6; i++) {
        id += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      var clash = taken.some(function (f) { return f.id === id; });
      if (!clash) {
        return id;
      }
    }
  }

  function addField(type) {
    var field = { id: genId(), type: type, label: TYPE_NAMES[type] + ' question', required: false };
    if (PLACEHOLDER_TYPES.indexOf(type) !== -1) {
      field.placeholder = '';
    }
    if (OPTION_TYPES.indexOf(type) !== -1) {
      field.options = ['Option 1', 'Option 2'];
    }
    if (type === 'rating') {
      field.max = 5;
    }
    state.push(field);
    renderList();
    var cards = listEl.querySelectorAll('.bf-card');
    var last = cards[cards.length - 1];
    if (last) {
      var input = last.querySelector('.bf-label-input');
      if (input) {
        input.focus();
        input.select();
      }
    }
  }

  function swap(a, b) {
    if (b < 0 || b >= state.length) {
      return;
    }
    var tmp = state[a];
    state[a] = state[b];
    state[b] = tmp;
    renderList();
  }

  /* ---------- rendering ---------- */

  function buildPalette() {
    TYPES.forEach(function (t) {
      var b = el('button', { type: 'button', 'class': 'palette-btn', text: '+ ' + t.name });
      b.addEventListener('click', function () { addField(t.type); });
      paletteEl.appendChild(b);
    });
  }

  function renderList() {
    while (listEl.firstChild) {
      listEl.removeChild(listEl.firstChild);
    }
    if (state.length === 0) {
      listEl.appendChild(el('div', {
        'class': 'bf-empty',
        text: 'No fields yet — add one from the palette.'
      }));
      return;
    }
    state.forEach(function (field, idx) {
      listEl.appendChild(buildCard(field, idx));
    });
  }

  function buildCard(field, idx) {
    var card = el('div', { 'class': 'bf-card', 'data-id': field.id });

    var up = iconBtn('↑', 'Move up');
    var down = iconBtn('↓', 'Move down');
    var remove = iconBtn('✕', 'Remove field');
    up.disabled = idx === 0;
    down.disabled = idx === state.length - 1;
    up.addEventListener('click', function () { swap(idx, idx - 1); });
    down.addEventListener('click', function () { swap(idx, idx + 1); });
    remove.addEventListener('click', function () {
      state.splice(idx, 1);
      renderList();
    });

    card.appendChild(el('div', { 'class': 'bf-head' }, [
      el('span', { 'class': 'bf-chip', text: TYPE_NAMES[field.type] }),
      el('span', { 'class': 'bf-spacer' }),
      up, down, remove
    ]));

    var body = el('div', { 'class': 'bf-body' });

    var labelInput = el('input', {
      'class': 'bf-label-input', type: 'text', maxlength: '200',
      value: field.label, placeholder: 'Question label'
    });
    labelInput.addEventListener('input', function () {
      field.label = labelInput.value;
      updatePreview(card, field);
    });
    body.appendChild(controlRow('Label', labelInput));

    if (PLACEHOLDER_TYPES.indexOf(field.type) !== -1) {
      var phInput = el('input', {
        type: 'text', maxlength: '200', value: field.placeholder || '',
        placeholder: 'Shown inside the empty input'
      });
      phInput.addEventListener('input', function () {
        field.placeholder = phInput.value;
        updatePreview(card, field);
      });
      body.appendChild(controlRow('Placeholder', phInput));
    }

    var reqInput = el('input', { type: 'checkbox' });
    reqInput.checked = field.required === true;
    reqInput.addEventListener('change', function () {
      field.required = reqInput.checked;
      updatePreview(card, field);
    });
    body.appendChild(el('label', { 'class': 'bf-required' }, [
      reqInput,
      el('span', { text: 'Required' })
    ]));

    if (OPTION_TYPES.indexOf(field.type) !== -1) {
      body.appendChild(buildOptionsEditor(card, field));
    }

    if (field.type === 'rating') {
      var maxSelect = el('select', {});
      for (var i = 1; i <= 10; i++) {
        var opt = el('option', { value: String(i), text: String(i) });
        if (field.max === i) {
          opt.selected = true;
        }
        maxSelect.appendChild(opt);
      }
      maxSelect.addEventListener('change', function () {
        field.max = parseInt(maxSelect.value, 10) || 5;
        updatePreview(card, field);
      });
      body.appendChild(controlRow('Max stars (1–10)', maxSelect));
    }

    card.appendChild(body);
    card.appendChild(el('div', { 'class': 'bf-preview' }));
    updatePreview(card, field);
    return card;
  }

  function buildOptionsEditor(card, field) {
    var wrap = el('div', { 'class': 'bf-options' }, [
      el('div', { 'class': 'bf-options-title', text: 'Options (1–20)' })
    ]);
    field.options.forEach(function (option, j) {
      var input = el('input', { type: 'text', maxlength: '100', value: option });
      input.addEventListener('input', function () {
        field.options[j] = input.value;
        updatePreview(card, field);
      });
      var rm = iconBtn('✕', 'Remove option');
      rm.disabled = field.options.length <= 1;
      rm.addEventListener('click', function () {
        field.options.splice(j, 1);
        renderList();
      });
      wrap.appendChild(el('div', { 'class': 'bf-option-row' }, [input, rm]));
    });
    var add = el('button', { type: 'button', 'class': 'btn btn-ghost btn-small', text: '+ Add option' });
    add.disabled = field.options.length >= 20;
    add.addEventListener('click', function () {
      field.options.push('Option ' + (field.options.length + 1));
      renderList();
    });
    wrap.appendChild(add);
    return wrap;
  }

  function updatePreview(card, field) {
    var box = card.querySelector('.bf-preview');
    while (box.firstChild) {
      box.removeChild(box.firstChild);
    }
    box.appendChild(renderPreview(field));
  }

  function renderPreview(field) {
    var box = el('div', { 'class': 'preview-field' });
    var label = el('div', { 'class': 'preview-label', text: field.label || '(no label)' });
    if (field.required) {
      label.appendChild(el('span', { 'class': 'req-star', text: ' *' }));
    }
    box.appendChild(label);

    var t = field.type;
    if (t === 'text' || t === 'email' || t === 'number') {
      box.appendChild(el('input', {
        'class': 'input', type: t === 'number' ? 'number' : t,
        placeholder: field.placeholder || '', disabled: true
      }));
    } else if (t === 'date') {
      box.appendChild(el('input', { 'class': 'input', type: 'date', disabled: true }));
    } else if (t === 'textarea') {
      box.appendChild(el('textarea', {
        'class': 'input', rows: '3',
        placeholder: field.placeholder || '', disabled: true
      }));
    } else if (t === 'select') {
      var select = el('select', { 'class': 'input', disabled: true });
      select.appendChild(el('option', { text: 'Choose…' }));
      field.options.forEach(function (o) {
        select.appendChild(el('option', { text: o }));
      });
      box.appendChild(select);
    } else if (t === 'radio' || t === 'checkbox') {
      field.options.forEach(function (o) {
        box.appendChild(el('label', { 'class': 'choice' }, [
          el('input', { type: t, disabled: true }),
          el('span', { text: o })
        ]));
      });
    } else if (t === 'rating') {
      var stars = '';
      var max = field.max || 5;
      for (var i = 0; i < max; i++) {
        stars += '★';
      }
      box.appendChild(el('div', { 'class': 'preview-stars', text: stars }));
    } else if (t === 'file') {
      box.appendChild(el('input', { 'class': 'input', type: 'file', disabled: true }));
      box.appendChild(el('p', { 'class': 'hint', text: 'pdf png jpg jpeg gif txt csv zip · max 2 MB' }));
    }
    return box;
  }

  /* ---------- DOM helpers ---------- */

  function controlRow(labelText, control) {
    return el('div', { 'class': 'bf-row' }, [
      el('label', { text: labelText }),
      control
    ]);
  }

  function iconBtn(glyph, title) {
    return el('button', {
      type: 'button', 'class': 'bf-icon-btn',
      title: title, 'aria-label': title, text: glyph
    });
  }

  // Element factory; only textContent is used — never innerHTML.
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        if (key === 'text') {
          node.textContent = attrs[key];
        } else if (key === 'value' || key === 'checked' || key === 'disabled' || key === 'selected') {
          node[key] = attrs[key];
        } else {
          node.setAttribute(key, attrs[key]);
        }
      });
    }
    (children || []).forEach(function (child) {
      node.appendChild(child);
    });
    return node;
  }
})();
