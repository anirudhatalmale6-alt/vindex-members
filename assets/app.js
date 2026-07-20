/* Vindex members platform — shared front-end behaviour */
(function () {
  'use strict';
  var root = document.documentElement;

  /* ---------- Theme ---------- */
  var MOON = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
  var SUN  = '<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.4 1.4M17.6 17.6L19 19M19 5l-1.4 1.4M6.4 17.6L5 19"/>';
  function paintThemeIcon() {
    var ic = document.getElementById('theme-ic');
    if (ic) ic.innerHTML = root.getAttribute('data-theme') === 'light' ? SUN : MOON;
  }
  function setTheme(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('vindex-theme', t); } catch (e) {}
    paintThemeIcon();
  }
  var themeBtn = document.getElementById('theme');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    setTheme(root.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
  });
  paintThemeIcon();

  /* ---------- Language ---------- */
  var lang = 'en';
  try { lang = localStorage.getItem('vindex-lang') || 'en'; } catch (e) {}

  function applyLang(l, scope) {
    scope = scope || document;
    var nodes = scope.querySelectorAll('[data-en]');
    for (var i = 0; i < nodes.length; i++) {
      var v = nodes[i].getAttribute('data-' + l);
      if (v !== null) nodes[i].innerHTML = v;
    }
    var ph = scope.querySelectorAll('[data-en-ph]');
    for (var j = 0; j < ph.length; j++) {
      var pv = ph[j].getAttribute('data-' + l + '-ph');
      if (pv !== null) ph[j].setAttribute('placeholder', pv);
    }
    if (scope === document) {
      root.setAttribute('lang', l);
      var btns = document.querySelectorAll('#lang button');
      for (var k = 0; k < btns.length; k++)
        btns[k].classList.toggle('on', btns[k].getAttribute('data-lang') === l);
    }
  }
  var langBox = document.getElementById('lang');
  if (langBox) langBox.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) return;
    lang = b.getAttribute('data-lang');
    try { localStorage.setItem('vindex-lang', lang); } catch (e) {}
    applyLang(lang);
    // re-fix any open fold heights after text length changes
    resizeOpenFolds();
  });
  applyLang(lang);

  /* ---------- Sidebar (mobile) ---------- */
  var sidebar = document.getElementById('sidebar');
  var sideToggle = document.getElementById('side-toggle');
  if (sideToggle && sidebar) sideToggle.addEventListener('click', function () {
    sidebar.classList.toggle('open');
  });

  /* ---------- Fold-outs (delegated; nested-safe) ---------- */
  function openFold(head) {
    head.setAttribute('aria-expanded', 'true');
    var body = head.nextElementSibling;
    body.style.maxHeight = body.scrollHeight + 'px';
    body.addEventListener('transitionend', function te(e) {
      if (e.propertyName === 'max-height' && head.getAttribute('aria-expanded') === 'true') {
        body.style.maxHeight = 'none';
      }
      body.removeEventListener('transitionend', te);
    });
  }
  function closeFold(head) {
    head.setAttribute('aria-expanded', 'false');
    var body = head.nextElementSibling;
    body.style.maxHeight = body.scrollHeight + 'px';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { body.style.maxHeight = '0px'; });
    });
  }
  function resizeOpenFolds() {
    var open = document.querySelectorAll('.fold-head[aria-expanded="true"]');
    for (var i = 0; i < open.length; i++) {
      var b = open[i].nextElementSibling;
      if (b) b.style.maxHeight = 'none';
    }
  }
  document.addEventListener('click', function (ev) {
    var head = ev.target.closest('.fold-head');
    if (!head) return;
    if (head.getAttribute('aria-expanded') === 'true') closeFold(head); else openFold(head);
  });

  /* ---------- Section loading (members index only) ---------- */
  var content = document.getElementById('content-inner');
  var loading = document.getElementById('loading');
  var sideNav = document.getElementById('side-nav');

  function markActive(id) {
    var items = document.querySelectorAll('.side-item');
    for (var i = 0; i < items.length; i++)
      items[i].classList.toggle('active', items[i].getAttribute('data-id') === String(id));
  }

  function loadSection(id) {
    if (!content) return;
    if (loading) loading.hidden = false;
    fetch('index.php?section=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (loading) loading.hidden = true;
        if (!d.ok) { content.innerHTML = '<p class="side-empty">Not found.</p>'; return; }
        var titleEn = d.title_en, titleDa = d.title_da || d.title_en;
        content.innerHTML =
          '<span class="eyebrow" data-en="Section" data-da="Sektion">Section</span>' +
          '<div class="accent-rule"></div>' +
          '<h1 class="section-title" data-en="' + escAttr(titleEn) + '" data-da="' + escAttr(titleDa) + '">' + escHtml(titleEn) + '</h1>' +
          (d.html || '<p class="side-empty" data-en="This section has no content yet." data-da="Denne sektion har intet indhold endnu.">This section has no content yet.</p>');
        applyLang(lang, content);
        content.parentNode.scrollTop = 0;
      })
      .catch(function () {
        if (loading) loading.hidden = true;
        content.innerHTML = '<p class="side-empty">Could not load this section.</p>';
      });
  }
  function escHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function escAttr(s) { return escHtml(s).replace(/"/g, '&quot;'); }

  if (sideNav) sideNav.addEventListener('click', function (ev) {
    var item = ev.target.closest('.side-item');
    if (!item) return;
    ev.preventDefault();
    var id = item.getAttribute('data-id');
    markActive(id);
    loadSection(id);
    if (window.matchMedia('(max-width:860px)').matches && sidebar) sidebar.classList.remove('open');
    if (history.replaceState) history.replaceState(null, '', '#s' + id);
  });

  // Auto-open a section from the URL hash, else leave the welcome panel.
  if (sideNav) {
    var m = (location.hash || '').match(/^#s(\d+)$/);
    if (m) { markActive(m[1]); loadSection(m[1]); }
  }
})();
