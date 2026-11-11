/* Tabaix Visual Editor v1.4 - frontend editing */
(function () {
  'use strict';
  if (!window.TVE || !TVE.ajax) return;

  var mode = 'edit'; // edit | style | block | hide
  var hoverEl = null;
  var editingEl = null;
  var originalHTML = '';
  var selectedEl = null;
  var pendingBlock = null;
  var styleEl = null, styleSel = '', styleOrig = {};
  var page = TVE.page || { texts: [], hides: [], css: '', styles: {}, images: [], sections: [], revisions: [] };

  /* ---------- selector ---------- */
  function selectorFor(el) {
    if (el.id) return '#' + el.id;
    var parts = [];
    var node = el;
    while (node && node.tagName && node.tagName.toLowerCase() !== 'body') {
      var tag = node.tagName.toLowerCase();
      var i = 1, sib = node;
      while ((sib = sib.previousElementSibling) !== null) {
        if (sib.tagName && sib.tagName.toLowerCase() === tag) i++;
      }
      parts.unshift(tag + ':nth-of-type(' + i + ')');
      node = node.parentElement;
    }
    return 'body > ' + parts.join(' > ');
  }

  function shortSel(sel) {
    if (!sel) return '';
    if (sel.charAt(0) === '#') return sel;
    var p = sel.split('>');
    return p[p.length - 1].trim();
  }

  function isUi(el) {
    return el && el.closest && el.closest('#tve-toolbar, #tve-panel, #tve-toast, #tve-floater, #tve-devices, #wpadminbar');
  }

  function isSkippable(el) {
    if (!el || !el.tagName) return true;
    var t = el.tagName.toLowerCase();
    return t === 'html' || t === 'body' || t === 'head' || t === 'script' ||
           t === 'style' || t === 'link' || t === 'meta' || t === 'title';
  }

  /* ---------- ajax ---------- */
  function save(op, data, cb, quiet) {
    data = data || {};
    data.action = 'tve_save';
    data.nonce = TVE.nonce;
    data.op = op;
    var body = new URLSearchParams();
    for (var k in data) {
      var v = data[k];
      body.append(k, (typeof v === 'object') ? JSON.stringify(v) : v);
    }
    fetch(TVE.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          if (res.data && res.data.page) page = res.data.page;
          if (!quiet) toast('Saved');
          if (cb) cb(res.data);
        } else {
          toast((res && res.data && res.data.message) || 'Save failed');
        }
      })
      .catch(function () { toast('Save failed'); });
  }

  /* ---------- toast ---------- */
  var toastTimer = null;
  function toast(msg) {
    var t = document.getElementById('tve-toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 1800);
  }

  /* ---------- toolbar ---------- */
  function buildToolbar() {
    var bar = document.createElement('div');
    bar.id = 'tve-toolbar';
    bar.innerHTML =
      '<button data-m="edit" class="on">Edit</button>' +
      '<button data-m="style">Style</button>' +
      '<button data-m="block">+ Block</button>' +
      '<button data-m="hide">Hide</button>' +
      '<button id="tve-morebtn" title="More">•••</button>' +
      '<a id="tve-exit" href="' + TVE.exitUrl + '">Exit</a>';
    document.body.appendChild(bar);

    bar.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      if (b.id === 'tve-morebtn') { openMoreSheet(); return; }
      setMode(b.getAttribute('data-m'));
    });

    var toastEl = document.createElement('div');
    toastEl.id = 'tve-toast';
    document.body.appendChild(toastEl);

    var panel = document.createElement('div');
    panel.id = 'tve-panel';
    panel.style.display = 'none';
    document.body.appendChild(panel);

    buildFloater();
    buildDevices();
  }

  function setMode(m) {
    mode = m;
    pendingBlock = null;
    document.body.classList.remove('tve-placing');
    stopEditing();
    deselect();
    closeStylePanel(true);
    closePanel();
    var btns = document.querySelectorAll('#tve-toolbar button[data-m]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', btns[i].getAttribute('data-m') === m);
    }
    document.body.classList.toggle('tve-mode-hide', m === 'hide');
    if (m === 'block') openBlockPanel();
  }

  /* ---------- floating contextual toolbar ---------- */
  function buildFloater() {
    var f = document.createElement('div');
    f.id = 'tve-floater';
    f.style.display = 'none';
    f.innerHTML =
      '<span data-f="tag"></span>' +
      '<button data-f="edit">Edit</button>' +
      '<button data-f="style">Style</button>' +
      '<button data-f="hide">Hide</button>' +
      '<button data-f="parent" title="Select parent">↑</button>' +
      '<button data-f="close" title="Deselect">✕</button>';
    document.body.appendChild(f);

    f.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b || !selectedEl) return;
      var act = b.getAttribute('data-f');
      if (act === 'edit') {
        if (selectedEl.tagName.toLowerCase() === 'img') openImagePanel(selectedEl);
        else startEditing(selectedEl);
      } else if (act === 'style') {
        openStylePanel(selectedEl);
      } else if (act === 'hide') {
        toggleHide(selectedEl);
      } else if (act === 'parent') {
        var p = selectedEl.parentElement;
        if (p && !isSkippable(p)) selectEl(p);
        else toast('No parent selectable');
      } else if (act === 'close') {
        deselect();
      }
    });

    var raf = null;
    function reposition() {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null;
        if (selectedEl) positionFloater(selectedEl);
      });
    }
    window.addEventListener('scroll', reposition, { passive: true });
    window.addEventListener('resize', reposition);
  }

  function selectEl(el) {
    deselect();
    selectedEl = el;
    el.classList.add('tve-selected');
    var f = document.getElementById('tve-floater');
    f.style.display = 'flex';
    f.querySelector('[data-f="tag"]').textContent = el.tagName.toLowerCase();
    var hidden = (page.hides || []).indexOf(selectorFor(el)) !== -1;
    f.querySelector('[data-f="hide"]').textContent = hidden ? 'Show' : 'Hide';
    positionFloater(el);
  }

  function deselect() {
    if (selectedEl) selectedEl.classList.remove('tve-selected');
    selectedEl = null;
    var f = document.getElementById('tve-floater');
    if (f) f.style.display = 'none';
  }

  function positionFloater(el) {
    var f = document.getElementById('tve-floater');
    if (!f || f.style.display === 'none') return;
    var r = el.getBoundingClientRect();
    var fh = f.offsetHeight || 44;
    var top = window.scrollY + r.top - fh - 10;
    if (top < window.scrollY + 44) top = window.scrollY + r.bottom + 10;
    var left = window.scrollX + r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - (f.offsetWidth || 280) - 8));
    f.style.top = top + 'px';
    f.style.left = left + 'px';
  }

  /* ---------- device preview ---------- */
  var origViewport = null;
  function buildDevices() {
    var meta = document.querySelector('meta[name="viewport"]');
    origViewport = meta ? meta.getAttribute('content') : 'width=device-width, initial-scale=1';
    var dev = document.createElement('div');
    dev.id = 'tve-devices';
    dev.innerHTML =
      '<button data-d="desktop" class="on">Desktop</button>' +
      '<button data-d="tablet">Tablet</button>' +
      '<button data-d="phone">Phone</button>';
    document.body.appendChild(dev);
    dev.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      setDevice(b.getAttribute('data-d'));
    });
    var saved = null;
    try { saved = localStorage.getItem('tve-device'); } catch (e) {}
    if (!saved && TVE.defaultDevice) saved = TVE.defaultDevice;
    if (saved) setDevice(saved);
  }

  function setDevice(d) {
    var meta = document.querySelector('meta[name="viewport"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.setAttribute('name', 'viewport');
      document.head.appendChild(meta);
    }
    document.documentElement.classList.remove('tve-dev-tablet', 'tve-dev-phone');
    if (d === 'tablet') {
      meta.setAttribute('content', 'width=820');
      document.documentElement.classList.add('tve-dev-tablet');
    } else if (d === 'phone') {
      meta.setAttribute('content', origViewport);
      document.documentElement.classList.add('tve-dev-phone');
    } else {
      meta.setAttribute('content', 'width=1280');
      d = 'desktop';
    }
    var btns = document.querySelectorAll('#tve-devices button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', btns[i].getAttribute('data-d') === d);
    }
    try { localStorage.setItem('tve-device', d); } catch (e) {}
    if (selectedEl) positionFloater(selectedEl);
  }

  /* ---------- panel ---------- */
  function panel() { return document.getElementById('tve-panel'); }
  function closePanel() {
    var p = panel();
    if (p) { p.style.display = 'none'; p.innerHTML = ''; }
  }
  function showPanel(html) {
    var p = panel();
    p.style.display = 'block';
    p.innerHTML = html;
    return p;
  }

  /* ---------- hover ---------- */
  document.addEventListener('mouseover', function (e) {
    if (isUi(e.target)) return;
    var el = e.target.closest ? e.target.closest('*:not(script):not(style)') : null;
    if (!el || isSkippable(el) || el === editingEl || el === selectedEl) return;
    if (hoverEl && hoverEl !== el) hoverEl.classList.remove('tve-hover');
    hoverEl = el;
    el.classList.add('tve-hover');
  });

  document.addEventListener('mouseout', function (e) {
    if (hoverEl && (!e.relatedTarget || !hoverEl.contains(e.relatedTarget))) {
      hoverEl.classList.remove('tve-hover');
      hoverEl = null;
    }
  });

  /* ---------- click ---------- */
  document.addEventListener('click', function (e) {
    if (isUi(e.target)) return;
    var el = e.target.closest ? e.target.closest('*:not(script):not(style)') : null;
    if (!el || isSkippable(el)) return;
    e.preventDefault();
    e.stopPropagation();

    if (pendingBlock) { placeBlock(el); return; }

    if (mode === 'edit') {
      if (el === selectedEl) {
        // second tap edits directly
        if (el.tagName.toLowerCase() === 'img') openImagePanel(el);
        else startEditing(el);
        return;
      }
      selectEl(el);
    } else if (mode === 'style') {
      selectEl(el);
      openStylePanel(el);
    } else if (mode === 'hide') {
      toggleHide(el);
    } else if (mode === 'block') {
      toast('Pick a block above first');
    }
  }, true);

  /* ---------- edit text ---------- */
  function startEditing(el) {
    stopEditing();
    deselect();
    editingEl = el;
    originalHTML = el.innerHTML;
    el.classList.add('tve-editing');
    el.classList.remove('tve-hover');
    try { el.contentEditable = 'plaintext-only'; }
    catch (err) { el.contentEditable = 'true'; }
    el.focus();
    if (document.execCommand) { try { document.execCommand('selectAll', false, null); } catch (e2) {} }
    if (el.tagName.toLowerCase() === 'a') openLinkPanel(el);
    el.addEventListener('blur', finishEditing);
    el.addEventListener('keydown', editKeys);
  }
  function editKeys(ev) {
    if (ev.key === 'Escape') { cancelEditing(); }
    if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter' && editingEl) { editingEl.blur(); }
  }
  function normHtml(h) {
    var d = document.createElement('div');
    d.innerHTML = h;
    return d.innerHTML;
  }
  function finishEditing() {
    if (!editingEl) return;
    var el = editingEl;
    var sel = selectorFor(el);
    var html = el.innerHTML;
    var href = el.tagName.toLowerCase() === 'a' ? (el.getAttribute('data-tve-href') || '') : '';
    stopEditing();
    if (normHtml(html) !== normHtml(originalHTML) || href) {
      save('text', { sel: sel, html: html, href: href }, function () { selectEl(el); });
    } else {
      selectEl(el);
    }
  }
  function cancelEditing() {
    if (!editingEl) return;
    editingEl.innerHTML = originalHTML;
    var el = editingEl;
    stopEditing();
    closePanel();
    selectEl(el);
  }
  function stopEditing() {
    if (!editingEl) return;
    editingEl.contentEditable = 'false';
    editingEl.classList.remove('tve-editing');
    editingEl.removeEventListener('blur', finishEditing);
    editingEl.removeEventListener('keydown', editKeys);
    editingEl = null;
  }

  function openLinkPanel(a) {
    var p = showPanel(
      '<div class="tve-panel-title">Button / Link URL</div>' +
      '<input id="tve-href" type="url" value="' + (a.getAttribute('href') || '').replace(/"/g, '&quot;') + '" placeholder="https://...">' +
      '<button id="tve-href-save" class="tve-primary">Apply URL</button>' +
      '<div class="tve-hint">URL is saved together with the text when you click outside.</div>');
    p.querySelector('#tve-href-save').addEventListener('click', function () {
      var v = p.querySelector('#tve-href').value;
      a.setAttribute('data-tve-href', v);
      if (v) a.setAttribute('href', v);
      closePanel();
      toast('URL set — tap outside the text to save');
      if (editingEl) editingEl.focus();
    });
  }

  /* ---------- hide ---------- */
  function toggleHide(el) {
    var sel = selectorFor(el);
    if ((page.hides || []).indexOf(sel) !== -1) {
      save('unhide', { sel: sel }, function () { location.reload(); }, true);
    } else {
      save('hide', { sel: sel }, function () { location.reload(); }, true);
    }
  }

  /* ---------- image swap ---------- */
  function openImagePanel(img) {
    var sel = selectorFor(img);
    var has = (page.images || []).some(function (im) { return im.sel === sel; });
    var src = img.currentSrc || img.src || '';
    var p = showPanel(
      '<div class="tve-panel-title">Replace image</div>' +
      (src ? '<img src="' + src.replace(/"/g, '&quot;') + '" style="max-width:100%;border-radius:8px;margin-bottom:10px;">' : '') +
      '<button id="tve-img-pick" class="tve-primary">Choose from Media Library</button>' +
      (has ? '<button id="tve-img-remove" class="tve-danger">Restore original image</button>' : '') +
      '<div class="tve-hint">Tap outside this panel to cancel.</div>');
    p.querySelector('#tve-img-pick').addEventListener('click', function () {
      if (!window.wp || !wp.media) { toast('Media library unavailable'); return; }
      var frame = wp.media({ title: 'Choose image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var att = frame.state().get('selection').first().toJSON();
        img.src = att.url;
        img.removeAttribute('srcset');
        save('image', { sel: sel, att_id: att.id, url: att.url }, function () { closePanel(); selectEl(img); });
      });
      frame.open();
    });
    var rm = p.querySelector('#tve-img-remove');
    if (rm) rm.addEventListener('click', function () {
      save('image_remove', { sel: sel }, function () { location.reload(); }, true);
    });
  }

  function rgbToHex(rgb) {
    var m = String(rgb).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
    if (!m) return '';
    function h(n) { n = Math.max(0, Math.min(255, parseInt(n, 10) || 0)); var s = n.toString(16); return s.length < 2 ? '0' + s : s; }
    return '#' + h(m[1]) + h(m[2]) + h(m[3]);
  }

  /* ---------- visual styles ---------- */
  var STYLE_FIELDS = [
    { k: 'color', label: 'Text color', type: 'color' },
    { k: 'bg', label: 'Background', type: 'color' },
    { k: 'font-size', label: 'Font size (px)', type: 'number' },
    { k: 'padding', label: 'Padding (px)', type: 'number' },
    { k: 'radius', label: 'Corner radius (px)', type: 'number' },
    { k: 'align', label: 'Text align', type: 'select', opts: ['', 'left', 'center', 'right', 'justify'] }
  ];
  var STYLE_CSS = { 'color': 'color', 'bg': 'backgroundColor', 'font-size': 'fontSize', 'padding': 'padding', 'radius': 'borderRadius', 'align': 'textAlign' };

  function openStylePanel(el) {
    closeStylePanel(true);
    styleEl = el;
    styleSel = selectorFor(el);
    styleOrig = {};
    var saved = (page.styles && page.styles[styleSel]) || {};
    var html = '<div class="tve-panel-title">Style: ' + el.tagName.toLowerCase() + ' <span style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.6">' + shortSel(styleSel).replace(/</g, '&lt;') + '</span></div><div class="tve-style-grid">';
    STYLE_FIELDS.forEach(function (f) {
      var val = saved[f.k] || '';
      html += '<label>' + f.label;
      if (f.type === 'color') {
        var cur = val;
        if (!cur) {
          try {
            var cs = getComputedStyle(el);
            cur = rgbToHex(cs[f.k === 'bg' ? 'backgroundColor' : 'color']);
          } catch (e) { cur = ''; }
        }
        html += '<input type="color" data-k="' + f.k + '" value="' + (cur || '#000000') + '">';
      } else if (f.type === 'select') {
        html += '<select data-k="' + f.k + '">';
        f.opts.forEach(function (o) {
          html += '<option value="' + o + '"' + (o === val ? ' selected' : '') + '>' + (o || '—') + '</option>';
        });
        html += '</select>';
      } else {
        html += '<input type="number" min="0" max="600" data-k="' + f.k + '" value="' + val + '" placeholder="—">';
      }
      html += '</label>';
    });
    html += '</div><button id="tve-style-apply" class="tve-primary">Apply styles</button>' +
            '<button id="tve-style-clear" class="tve-danger">Clear styles</button>';
    var p = showPanel(html);

    p.querySelectorAll('[data-k]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        inp.setAttribute('data-touched', '1');
        var k = inp.getAttribute('data-k');
        if (!(k in styleOrig)) styleOrig[k] = styleEl.style[STYLE_CSS[k]] || '';
        applyStyleProp(styleEl, k, inp.value);
      });
    });
    p.querySelector('#tve-style-apply').addEventListener('click', function () {
      var props = {};
      p.querySelectorAll('[data-k][data-touched]').forEach(function (inp) {
        var v = inp.value;
        if (v !== '' && v !== null) props[inp.getAttribute('data-k')] = v;
      });
      save('style_save', { sel: styleSel, props: props }, function () { closeStylePanel(false); });
    });
    p.querySelector('#tve-style-clear').addEventListener('click', function () {
      save('style_clear', { sel: styleSel }, function () { location.reload(); }, true);
    });
  }

  function applyStyleProp(el, k, v) {
    var css = STYLE_CSS[k];
    if (!css) return;
    if (k === 'font-size' || k === 'padding' || k === 'radius') {
      el.style[css] = v === '' ? '' : (parseInt(v, 10) + 'px');
    } else {
      el.style[css] = v;
    }
  }

  function closeStylePanel(revert) {
    if (styleEl && revert) {
      for (var k in styleOrig) styleEl.style[STYLE_CSS[k]] = styleOrig[k];
    }
    styleEl = null; styleSel = ''; styleOrig = {};
  }

  /* ---------- page builder: block manager ---------- */
  function findSection(uid) {
    var arr = page.sections || [];
    for (var i = 0; i < arr.length; i++) if (arr[i].uid === uid) return arr[i];
    return null;
  }
  function sectionNode(uid) {
    try { return document.querySelector('[data-tve-section="' + uid + '"]'); }
    catch (e) { return null; }
  }
  function normPos(p) {
    return (p === 'top' || p === 'before_footer' || p === 'bottom') ? p : 'bottom';
  }
  function swapNodes(a, b) {
    if (!a || !b || a === b || !a.parentNode || !b.parentNode) return;
    if (a.nextSibling === b) { a.parentNode.insertBefore(a, b.nextSibling); return; }
    if (b.nextSibling === a) { b.parentNode.insertBefore(b, a.nextSibling); return; }
    var pa = a.parentNode, na = a.nextSibling, pb = b.parentNode, nb = b.nextSibling;
    pa.insertBefore(b, na);
    pb.insertBefore(a, nb);
  }

  function renderBlockList() {
    var names = TVE.sections || {};
    var list = document.getElementById('tve-sec-list');
    if (!list) return;
    var groups = [
      { key: 'placed', title: 'Placed on page' },
      { key: 'top', title: 'Top of page' },
      { key: 'before_footer', title: 'Before footer' },
      { key: 'bottom', title: 'Bottom of page' }
    ];
    var domOrder = {};
    Array.prototype.forEach.call(document.querySelectorAll('.tve-section'), function (node, i) {
      domOrder[node.getAttribute('data-tve-section')] = i;
    });
    var html = '';
    groups.forEach(function (g) {
      var items = (page.sections || []).filter(function (s) {
        var k = s.anchor ? 'placed' : normPos(s.pos);
        return k === g.key;
      });
      if (g.key === 'placed') {
        items.sort(function (a, b) { return (domOrder[a.uid] || 0) - (domOrder[b.uid] || 0); });
      }
      if (!items.length) return;
      html += '<div class="tve-group-title">' + g.title + '</div>';
      items.forEach(function (s) {
        html += '<div class="tve-sec-row" data-uid="' + String(s.uid).replace(/"/g, '&quot;') + '">' +
          '<span class="tve-row-name">' + String(names[s.id] || s.id).replace(/</g, '&lt;') + '</span>' +
          '<span class="tve-row-btns">' +
          '<button data-mv="-1" title="Move up">\u2191</button>' +
          '<button data-mv="1" title="Move down">\u2193</button>' +
          '<button data-dupe="1" title="Duplicate">\u29C9</button>' +
          '<button data-design="1" title="Design: fonts, colors, spacing">\u25C6</button>' +
          '<button data-del="1" class="tve-del" title="Remove">\u2715</button>' +
          '</span></div>';
      });
    });
    if (!html) html = '<div class="tve-hint">No blocks on this page yet — pick one above.</div>';
    list.innerHTML = html;
    bindBlockList(list);
  }

  function bindBlockList(list) {
    list.querySelectorAll('[data-mv]').forEach(function (b) {
      b.addEventListener('click', function () {
        moveBlock(b.closest('.tve-sec-row').getAttribute('data-uid'), parseInt(b.getAttribute('data-mv'), 10));
      });
    });
    list.querySelectorAll('[data-dupe]').forEach(function (b) {
      b.addEventListener('click', function () {
        dupeBlock(b.closest('.tve-sec-row').getAttribute('data-uid'));
      });
    });
    list.querySelectorAll('[data-design]').forEach(function (b) {
      b.addEventListener('click', function () {
        openBlockDesignPanel(b.closest('.tve-sec-row').getAttribute('data-uid'));
      });
    });
    list.querySelectorAll('[data-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Remove this block?')) return;
        delBlock(b.closest('.tve-sec-row').getAttribute('data-uid'));
      });
    });
    list.querySelectorAll('.tve-row-name').forEach(function (sp) {
      sp.style.cursor = 'pointer';
      sp.addEventListener('click', function () {
        var node = sectionNode(sp.closest('.tve-sec-row').getAttribute('data-uid'));
        if (node) { closePanel(); node.scrollIntoView({ block: 'center' }); selectEl(node); }
        else toast('Block not found on this page');
      });
    });
  }

  function moveBlock(uid, dir) {
    var entry = findSection(uid);
    var node = sectionNode(uid);
    if (!entry || !node) { toast('Block not found'); return; }
    if (entry.anchor) {
      // placed block: swap anchors with the previous/next placed block in visual order
      var blocks = Array.prototype.filter.call(document.querySelectorAll('.tve-section'), function (n) {
        var e = findSection(n.getAttribute('data-tve-section'));
        return e && e.anchor;
      });
      var bi = blocks.indexOf(node);
      var other = dir < 0 ? blocks[bi - 1] : blocks[bi + 1];
      if (!other) { toast(dir < 0 ? 'Already at the top' : 'Already at the bottom'); return; }
      var otherUid = other.getAttribute('data-tve-section');
      save('section_move', { uid: uid, swap_uid: otherUid }, function () {
        swapNodes(node, other);
        renderBlockList();
        toast('Moved');
      }, true);
    } else {
      // bucket block: nearest same-bucket neighbor in stored order (== render order)
      var pos = normPos(entry.pos);
      var arr = page.sections || [];
      var i = -1, j = -1, k;
      for (k = 0; k < arr.length; k++) if (arr[k].uid === uid) { i = k; break; }
      if (dir < 0) {
        for (k = i - 1; k >= 0; k--) {
          if (normPos(arr[k].pos) === pos && !arr[k].anchor) { j = k; break; }
        }
      } else {
        for (k = i + 1; k < arr.length; k++) {
          if (normPos(arr[k].pos) === pos && !arr[k].anchor) { j = k; break; }
        }
      }
      if (j === -1) { toast(dir < 0 ? 'Already at the top' : 'Already at the bottom'); return; }
      var otherUid2 = arr[j].uid, otherNode = sectionNode(otherUid2);
      save('section_move', { uid: uid, dir: dir }, function () {
        swapNodes(node, otherNode);
        renderBlockList();
        toast('Moved');
      }, true);
    }
  }

  function dupeBlock(uid) {
    var node = sectionNode(uid);
    save('section_dupe', { uid: uid }, function (data) {
      if (data && data.html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = data.html;
        var el = tmp.firstElementChild;
        if (el && node && node.parentNode) {
          node.parentNode.insertBefore(el, node.nextSibling);
          if (el.scrollIntoView) el.scrollIntoView({ block: 'center' });
        }
      }
      renderBlockList();
      toast('Block duplicated — tap it to edit');
    }, true);
  }

  function delBlock(uid) {
    var node = sectionNode(uid);
    save('section_del', { uid: uid }, function () {
      if (node) {
        if (node === selectedEl) deselect();
        if (node.parentNode) node.parentNode.removeChild(node);
      }
      renderBlockList();
      toast('Block removed');
    }, true);
  }

  /* ---------- per-block design (v1.6): typography, background, padding, video ---------- */
  var BLOCK_PAD_PX = { s: 16, m: 32, l: 56 };

  function blockDesignVal(entry, k) {
    return (entry && entry.style && entry.style[k] !== undefined && entry.style[k] !== null) ? String(entry.style[k]) : '';
  }

  function applyBlockDesignLive(node, k, v) {
    if (!node) return;
    if (k === 'font-size') node.style.fontSize = v === '' ? '' : (parseInt(v, 10) + 'px');
    else if (k === 'align') node.style.textAlign = v;
    else if (k === 'bg') node.style.backgroundColor = v;
    else if (k === 'pad') node.style.padding = v === '' ? '' : (BLOCK_PAD_PX[v] + 'px');
  }

  function openBlockDesignPanel(uid) {
    var entry = findSection(uid);
    var node = sectionNode(uid);
    if (!entry) { toast('Block not found'); return; }
    var names = TVE.sections || {};
    var isVideo = entry.id === 'video-embed';
    var curVideo = entry.video || '';

    var fsizes = ['', '14', '16', '18', '20', '24', '28', '32', '40', '48'];
    var html = '<div class="tve-panel-title">Design: ' + String(names[entry.id] || entry.id).replace(/</g, '&lt;') + '</div><div class="tve-style-grid">';
    html += '<label>Font size (px)<select data-bk="font-size">';
    fsizes.forEach(function (s) {
      html += '<option value="' + s + '"' + (blockDesignVal(entry, 'font-size') === s ? ' selected' : '') + '>' + (s || 'Default') + '</option>';
    });
    html += '</select></label>';
    html += '<label>Text align<select data-bk="align">';
    ['', 'left', 'center', 'right'].forEach(function (a) {
      html += '<option value="' + a + '"' + (blockDesignVal(entry, 'align') === a ? ' selected' : '') + '>' + (a || 'Default') + '</option>';
    });
    html += '</select></label>';
    var curBg = blockDesignVal(entry, 'bg');
    html += '<label>Background<input type="color" data-bk="bg" value="' + (curBg || '#ffffff') + '"></label>';
    html += '<label>Padding<select data-bk="pad">';
    [['', 'Default'], ['s', 'S'], ['m', 'M'], ['l', 'L']].forEach(function (o) {
      html += '<option value="' + o[0] + '"' + (blockDesignVal(entry, 'pad') === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
    });
    html += '</select></label>';
    if (isVideo) {
      html += '<label style="grid-column:1/-1">Video URL (YouTube / Vimeo / MP4)<input type="url" data-bk="video" value="' + curVideo.replace(/"/g, '&quot;') + '" placeholder="https://www.youtube.com/watch?v=..." style="width:100%"></label>';
    }
    html += '</div>';
    if (!isVideo) {
      html += '<label style="display:flex;align-items:center;gap:8px;margin:6px 0 12px;font-size:13px;"><input type="checkbox" data-bk="bg-off"' + (curBg ? '' : ' checked') + '> No background</label>';
    }
    html += '<button id="tve-bd-apply" class="tve-primary">Apply design</button>' +
            '<button id="tve-bd-clear" class="tve-danger">Clear design</button>';
    var p = showPanel(html);
    var orig = {};
    ['font-size', 'align', 'bg', 'pad'].forEach(function (k) { orig[k] = node ? node.style[{ 'font-size': 'fontSize', 'align': 'textAlign', 'bg': 'backgroundColor', 'pad': 'padding' }[k]] || '' : ''; });

    p.querySelectorAll('[data-bk]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var k = inp.getAttribute('data-bk');
        if (k === 'video') return;
        if (k === 'bg-off') return;
        var v = inp.value;
        if (k === 'bg' && p.querySelector('[data-bk="bg-off"]') && p.querySelector('[data-bk="bg-off"]').checked) v = '';
        applyBlockDesignLive(node, k, v);
      });
    });
    var bgOff = p.querySelector('[data-bk="bg-off"]');
    if (bgOff) bgOff.addEventListener('change', function () {
      var c = p.querySelector('[data-bk="bg"]');
      applyBlockDesignLive(node, 'bg', bgOff.checked ? '' : (c ? c.value : ''));
    });

    p.querySelector('#tve-bd-apply').addEventListener('click', function () {
      var props = {};
      p.querySelectorAll('[data-bk]').forEach(function (inp) {
        var k = inp.getAttribute('data-bk');
        if (k === 'video' || k === 'bg-off') return;
        var v = inp.value;
        if (k === 'bg' && bgOff && bgOff.checked) v = '';
        if (v !== '' && v !== null) props[k] = v;
      });
      var vurl = '';
      var vinp = p.querySelector('[data-bk="video"]');
      if (vinp) vurl = vinp.value.trim();
      var payload = { uid: uid, props: props };
      if (isVideo) payload.video = vurl;
      save('section_style', payload, function (data) {
        if (isVideo && data && data.video_html && node) {
          var slot = node.querySelector('.tve-video-slot');
          if (slot) {
            var tmp = document.createElement('div');
            tmp.innerHTML = data.video_html;
            slot.parentNode.replaceChild(tmp.firstElementChild, slot);
          }
        }
        openBlockPanel();
        renderBlockList();
        toast('Design saved');
      });
    });
    p.querySelector('#tve-bd-clear').addEventListener('click', function () {
      var payload = { uid: uid, props: {} };
      if (isVideo) payload.video = '';
      save('section_style', payload, function () {
        ['font-size', 'align', 'bg', 'pad'].forEach(function (k) { applyBlockDesignLive(node, k, ''); });
        openBlockPanel();
        renderBlockList();
        toast('Design cleared');
      }, true);
    });
  }

  /* ---------- blocks ---------- */
  var BLOCK_GROUPS = [
    { title: 'Headers', ids: ['header-bar', 'header-center', 'header-minimal'] },
    { title: 'FAQ', ids: ['faq-plus', 'faq-cards'] },
    { title: 'Content blocks', ids: null }
  ];

  function openBlockPanel() {
    var names = TVE.sections || {};
    var html = '<div class="tve-panel-title">Add a block</div>';
    var listed = {};
    BLOCK_GROUPS.forEach(function (g) {
      var ids = g.ids;
      if (!ids) ids = Object.keys(names).filter(function (id) { return !listed[id]; });
      ids = ids.filter(function (id) { return names[id] && !listed[id]; });
      if (!ids.length) return;
      html += '<div class="tve-group-title">' + g.title + '</div><div class="tve-sec-grid">';
      ids.forEach(function (id) {
        listed[id] = true;
        html += '<button class="tve-sec" data-id="' + id + '">' + names[id] + '</button>';
      });
      html += '</div>';
    });
    html += '<div class="tve-panel-title" style="margin-top:14px">Blocks on this page</div>' +
      '<div class="tve-hint">\u2191 \u2193 reorder · \u29C9 duplicate · \u2715 remove. Tap a block name to jump to it.</div>' +
      '<div id="tve-sec-list"></div>';
    var p = showPanel(html);
    renderBlockList();
    p.querySelectorAll('.tve-sec').forEach(function (b) {
      b.addEventListener('click', function () { openPlacement(b.getAttribute('data-id')); });
    });
  }

  function openPlacement(id) {
    var p = showPanel(
      '<div class="tve-panel-title">Place: ' + ((TVE.sections || {})[id] || id) + '</div>' +
      '<button class="tve-pos tve-primary" data-pos="top">Top of page</button>' +
      '<button class="tve-pos tve-primary" data-pos="before_footer">Before footer</button>' +
      '<button class="tve-pos tve-primary" data-pos="bottom">Bottom of page</button>' +
      '<button class="tve-pos" id="tve-pick-place">Tap on the page…</button>' +
      '<div class="tve-hint">"Tap on the page" inserts the block right after the element you tap — like dropping a Gutenberg block between content.</div>');
    p.querySelectorAll('.tve-pos[data-pos]').forEach(function (b) {
      b.addEventListener('click', function () {
        var pos = b.getAttribute('data-pos');
        save('section_add', { section_id: id, pos: pos }, function (data) {
          insertBlockHtml(data.html, pos, null);
          setMode('block');
        }, true);
      });
    });
    p.querySelector('#tve-pick-place').addEventListener('click', function () {
      pendingBlock = id;
      closePanel();
      document.body.classList.add('tve-placing');
      toast('Tap on the page where the block goes');
    });
  }

  function placeBlock(targetEl) {
    var id = pendingBlock;
    pendingBlock = null;
    document.body.classList.remove('tve-placing');
    var sel = selectorFor(targetEl);
    save('section_add', { section_id: id, pos: 'anchor', anchor: sel }, function (data) {
      insertBlockHtml(data.html, 'anchor', targetEl);
      toast('Block added — tap it to edit its text');
    }, true);
  }

  function insertBlockHtml(html, pos, afterEl) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var node = tmp.firstElementChild;
    if (!node) return;
    if (pos === 'anchor' && afterEl && afterEl.parentNode) {
      afterEl.parentNode.insertBefore(node, afterEl.nextSibling);
    } else if (pos === 'top' && document.body.firstChild) {
      document.body.insertBefore(node, document.body.firstChild);
    } else if (pos === 'before_footer') {
      var f = document.querySelector('footer');
      if (f && f.parentNode) f.parentNode.insertBefore(node, f);
      else document.body.appendChild(node);
    } else {
      document.body.appendChild(node);
    }
    if (node.scrollIntoView) node.scrollIntoView({ block: 'center' });
  }

  /* ---------- navigator ---------- */
  function openNavigator() {
    var names = TVE.sections || {};
    var rows = [];
    (page.sections || []).forEach(function (s) {
      rows.push({ label: (names[s.id] || s.id), sub: 'block', kind: 'block', uid: s.uid });
    });
    (page.hides || []).forEach(function (sel) {
      rows.push({ label: shortSel(sel), sub: 'hidden', kind: 'hide', sel: sel });
    });
    var html = '<div class="tve-panel-title">Navigator</div>';
    if (!rows.length) html += '<div class="tve-hint">Nothing added or hidden on this page yet.</div>';
    rows.forEach(function (r, i) {
      html += '<div class="tve-sec-row"><span>' + String(r.label).replace(/</g, '&lt;') +
              ' <em style="opacity:.55;font-style:normal">· ' + r.sub + '</em></span>' +
              '<button data-nav="' + i + '">Go to</button></div>';
    });
    var p = showPanel(html);
    p.querySelectorAll('[data-nav]').forEach(function (b) {
      b.addEventListener('click', function () {
        var r = rows[parseInt(b.getAttribute('data-nav'), 10)];
        var node = null;
        try {
          if (r.kind === 'block') node = sectionNode(r.uid);
          else node = document.querySelector(r.sel);
        } catch (e) {}
        if (node) {
          closePanel();
          node.scrollIntoView({ block: 'center' });
          selectEl(node);
        } else {
          toast('Element not found on this page');
        }
      });
    });
  }

  /* ---------- more sheet ---------- */
  function openMoreSheet() {
    var p = showPanel(
      '<div class="tve-panel-title">More</div>' +
      '<button id="tve-m-nav" class="tve-primary">Navigator</button>' +
      '<button id="tve-m-css" class="tve-primary">Design CSS</button>' +
      '<button id="tve-m-hist" class="tve-primary">History</button>' +
      '<button id="tve-m-undo" class="tve-primary">Undo last change</button>' +
      '<button id="tve-m-reset" class="tve-danger">Reset this page</button>' +
      '<div class="tve-hint">History keeps your last 15 change points. Undo reverses the most recent one.</div>');
    p.querySelector('#tve-m-nav').addEventListener('click', openNavigator);
    p.querySelector('#tve-m-css').addEventListener('click', openCssPanel);
    p.querySelector('#tve-m-hist').addEventListener('click', openHistoryPanel);
    p.querySelector('#tve-m-undo').addEventListener('click', function () {
      save('undo', {}, function () { location.reload(); }, true);
    });
    p.querySelector('#tve-m-reset').addEventListener('click', function () {
      if (!confirm('Remove ALL visual edits on this page?')) return;
      save('reset', {}, function () { location.reload(); }, true);
    });
  }

  function openCssPanel() {
    var p = showPanel(
      '<div class="tve-panel-title">Custom CSS for this page</div>' +
      '<textarea id="tve-css" rows="8" placeholder="Example:\nh1 { color: #b45309; }">' +
      (page.css || '').replace(/</g, '&lt;') + '</textarea>' +
      '<button id="tve-css-save" class="tve-primary">Apply CSS</button>' +
      '<div class="tve-hint">CSS applies to this page only and never touches your theme files.</div>');
    p.querySelector('#tve-css-save').addEventListener('click', function () {
      var v = p.querySelector('#tve-css').value;
      save('css', { css: v }, function () { location.reload(); }, true);
    });
  }

  function openHistoryPanel() {
    var revs = page.revisions || [];
    var html = '<div class="tve-panel-title">History</div>';
    if (!revs.length) html += '<div class="tve-hint">No changes yet on this page.</div>';
    revs.forEach(function (r, i) {
      var d = new Date(r.time * 1000);
      var label = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      html += '<div class="tve-sec-row"><span>' + label + '</span><button data-r="' + i + '">Restore</button></div>';
    });
    html += '<div class="tve-hint">Restoring keeps your current state as a new history point, so you can undo the restore too.</div>';
    var p = showPanel(html);
    p.querySelectorAll('[data-r]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Restore this version? Current state will be kept in history.')) return;
        save('revision_restore', { index: b.getAttribute('data-r') }, function () { location.reload(); }, true);
      });
    });
  }

  /* ---------- keyboard shortcuts ---------- */
  document.addEventListener('keydown', function (e) {
    var ae = document.activeElement;
    var tag = (ae && ae.tagName || '').toLowerCase();
    var typing = editingEl || tag === 'input' || tag === 'textarea' || tag === 'select' || (ae && ae.isContentEditable);
    if (typing) return;

    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
      e.preventDefault();
      save('undo', {}, function () { location.reload(); }, true);
      return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey) return;

    if (e.key === 'Escape') {
      if (pendingBlock) { pendingBlock = null; document.body.classList.remove('tve-placing'); setMode('block'); }
      else if (!editingEl) { deselect(); closeStylePanel(true); closePanel(); }
      return;
    }
    var k = e.key.toLowerCase();
    if (k === 'e') setMode('edit');
    else if (k === 's') setMode('style');
    else if (k === 'b') setMode('block');
    else if (k === 'h') setMode('hide');
  });

  /* ---------- boot ---------- */
  buildToolbar();
  try {
    // keep designed FAQ accordions expanded while editing so answers stay editable
    document.querySelectorAll('details.tvex-faq').forEach(function (d) { d.setAttribute('open', ''); });
  } catch (e) {}
})();
