// DiagnoCare landing page. Everything on the page is readable without this
// file; it adds the menu, the scroll animations, the product tour, the module
// demo and the demo form.
(function () {
  'use strict';

  // Change these two lines to your real contact details.
  var WHATSAPP_NUMBER = '8801XXXXXXXXX'; // country code and number, digits only
  var EMAIL = 'hello@example.com.bd';

  var root = document.documentElement;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Header shadow once the page scrolls
  var header = document.querySelector('.top');
  var onScroll = function () { header.classList.toggle('scrolled', window.scrollY > 8); };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // Mobile menu
  var burger = document.querySelector('.burger');
  var nav = document.getElementById('nav');
  burger.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    burger.setAttribute('aria-expanded', String(open));
  });
  nav.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') { nav.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); }
  });

  // Reveal sections as they scroll into view
  var revealables = document.querySelectorAll('.rv');
  if (!('IntersectionObserver' in window) || reduceMotion) {
    root.classList.add('no-anim');
    revealables.forEach(function (el) { el.classList.add('seen'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { entry.target.classList.add('seen'); io.unobserve(entry.target); }
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });
    revealables.forEach(function (el) { io.observe(el); });
  }

  // Product tour: tabs that move on by themselves until someone touches them
  var tour = document.querySelector('[data-tour]');
  if (tour) {
    var tabs = Array.prototype.slice.call(tour.querySelectorAll('[role="tab"]'));
    var STEP_MS = 7000;
    var timer = null;
    tour.style.setProperty('--tour-ms', STEP_MS + 'ms');
    tour.querySelectorAll('[role="tabpanel"]').forEach(function (p, i) { p.hidden = i !== 0; });

    var select = function (index, focus) {
      tabs.forEach(function (tab, i) {
        var on = i === index;
        tab.setAttribute('aria-selected', String(on));
        tab.tabIndex = on ? 0 : -1;
        document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
      });
      if (focus) tabs[index].focus();
      if (timer) restart();
    };
    var current = function () { return tabs.findIndex(function (t) { return t.getAttribute('aria-selected') === 'true'; }); };
    var restart = function () {
      // Re-trigger the progress bar animation on the new tab
      tour.classList.remove('playing'); void tour.offsetWidth; tour.classList.add('playing');
    };
    var play = function () {
      if (reduceMotion || timer) return;
      tour.classList.add('playing');
      timer = setInterval(function () { select((current() + 1) % tabs.length); }, STEP_MS);
      restart();
    };
    var stop = function () { clearInterval(timer); timer = null; tour.classList.remove('playing'); };

    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { stop(); select(i); });
      tab.addEventListener('keydown', function (e) {
        var dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (e.key === 'Home') { e.preventDefault(); stop(); select(0, true); }
        if (e.key === 'End') { e.preventDefault(); stop(); select(tabs.length - 1, true); }
        if (dir) { e.preventDefault(); stop(); select((i + dir + tabs.length) % tabs.length, true); }
      });
    });
    tour.addEventListener('mouseenter', function () { if (timer) { clearInterval(timer); timer = null; tour.classList.remove('playing'); tour.dataset.paused = '1'; } });
    tour.addEventListener('mouseleave', function () { if (tour.dataset.paused) { delete tour.dataset.paused; play(); } });

    // Start only when the tour is on screen
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries, obs) {
        if (entries[0].isIntersecting) { play(); obs.disconnect(); }
      }, { threshold: 0.35 }).observe(tour);
    }
  }

  // Module demo: switching one off switches off what depends on it
  var box = document.querySelector('[data-modules]');
  if (box) {
    var switches = Array.prototype.slice.call(box.querySelectorAll('.sw'));
    var isOn = function (key) {
      var s = switches.find(function (b) { return b.dataset.m === key; });
      return s && s.getAttribute('aria-pressed') === 'true';
    };
    var sync = function () {
      // Repeat until nothing changes, so chains (Laboratory → Reports → Portal) settle
      for (var pass = 0; pass < switches.length; pass++) {
        switches.forEach(function (b) {
          var needs = b.dataset.needs;
          var ok = !needs || isOn(needs);
          if (!ok) b.setAttribute('aria-pressed', 'false');
          b.disabled = !ok;
        });
      }
      var count = 0;
      switches.forEach(function (b) {
        var on = b.getAttribute('aria-pressed') === 'true';
        if (on) count++;
        var item = document.querySelector('[data-s="' + b.dataset.m + '"]');
        if (item) item.classList.toggle('off', !on);
      });
      document.getElementById('mod-count').textContent = count + ' of ' + switches.length + ' modules on';
    };
    switches.forEach(function (b) {
      b.addEventListener('click', function () {
        b.setAttribute('aria-pressed', String(b.getAttribute('aria-pressed') !== 'true'));
        sync();
      });
    });
  }

  // "Ask about" buttons pick the plan in the form
  document.querySelectorAll('[data-plan]').forEach(function (link) {
    link.addEventListener('click', function () {
      var select = document.getElementById('f-plan');
      if (select) select.value = link.getAttribute('data-plan');
    });
  });

  // Demo form: opens WhatsApp or the mail app with the details filled in
  var form = document.getElementById('demo-form');
  if (form) {
    var via = 'whatsapp';
    var err = document.getElementById('form-err');
    form.querySelectorAll('button[type="submit"]').forEach(function (b) {
      b.addEventListener('click', function () { via = b.dataset.via; });
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var first = null;
      form.querySelectorAll('input[required]').forEach(function (input) {
        var bad = !input.checkValidity();
        input.classList.toggle('bad', bad);
        if (bad && !first) first = input;
      });
      if (first) {
        err.textContent = 'Please add your name, your clinic and a Bangladeshi mobile number.';
        err.hidden = false;
        first.focus();
        return;
      }
      err.hidden = true;
      var d = new FormData(form);
      var text = 'Demo request for DiagnoCare\nName: ' + d.get('name') + '\nClinic: ' + d.get('clinic') +
        '\nMobile: ' + d.get('phone') + '\nPlan: ' + d.get('plan');
      if (via === 'email') {
        window.location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('Demo request: ' + d.get('clinic')) + '&body=' + encodeURIComponent(text);
      } else {
        window.open('https://wa.me/' + WHATSAPP_NUMBER + '?text=' + encodeURIComponent(text), '_blank', 'noopener');
      }
    });
  }

  var year = document.getElementById('year');
  if (year) year.textContent = String(new Date().getFullYear());
})();
