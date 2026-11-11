/* Tabaix Visual Editor - "Visual Edit" button in the block editor header */
(function () {
  'use strict';

  function currentLaunchUrl() {
    try {
      var select = window.wp && wp.data && wp.data.select('core/editor');
      if (!select) return null;
      if (select.getEditedPostAttribute('status') !== 'publish') return null;
      var link = select.getPermalink();
      if (!link) return null;
      return link + (link.indexOf('?') > -1 ? '&' : '?') + 'tve_edit=1';
    } catch (e) { return null; }
  }

  function ensureButton() {
    var toolbar = document.querySelector('.edit-post-header__toolbar');
    if (!toolbar) return false;
    var btn = document.getElementById('tve-gb-btn');
    if (!btn) {
      btn = document.createElement('a');
      btn.id = 'tve-gb-btn';
      btn.className = 'components-button is-secondary';
      btn.textContent = 'Visual Edit';
      btn.target = '_blank';
      btn.rel = 'noopener';
      btn.style.marginLeft = '8px';
      btn.style.whiteSpace = 'nowrap';
      toolbar.appendChild(btn);
    }
    var url = currentLaunchUrl();
    if (url) {
      btn.style.display = '';
      btn.href = url;
    } else {
      btn.style.display = 'none';
    }
    return true;
  }

  var tries = 0;
  var iv = setInterval(function () {
    ensureButton();
    if (++tries > 60) clearInterval(iv);
  }, 500);

  // keep the URL fresh as the post state changes
  if (window.wp && wp.data && wp.data.subscribe) {
    wp.data.subscribe(function () { ensureButton(); });
  }
})();
