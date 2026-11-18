// LabNest landing page. The page works without this file; it only adds the
// mobile menu and sends the demo form to WhatsApp or email (no server needed).
(function () {
  'use strict';

  // Change these two lines to your real contact details.
  var WHATSAPP_NUMBER = '8801XXXXXXXXX'; // country code + number, digits only
  var EMAIL = 'hello@example.com.bd';

  // Mobile menu
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    nav.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') {
        nav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // "Ask for price" buttons pre-select the plan in the form
  document.querySelectorAll('[data-plan]').forEach(function (link) {
    link.addEventListener('click', function () {
      var select = document.getElementById('f-plan');
      if (select) select.value = link.getAttribute('data-plan');
    });
  });

  // Demo form: opens WhatsApp or the mail app with the details filled in
  var form = document.getElementById('demo-form');
  var errorBox = document.getElementById('form-error');
  var via = 'whatsapp';

  if (form) {
    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.addEventListener('click', function () { via = button.getAttribute('data-via'); });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fields = form.querySelectorAll('input[required]');
      var firstBad = null;
      fields.forEach(function (input) {
        input.classList.add('touched');
        if (!input.checkValidity() && !firstBad) firstBad = input;
      });

      if (firstBad) {
        errorBox.textContent = 'Please fill in your name, clinic and a valid Bangladeshi mobile number.';
        errorBox.hidden = false;
        firstBad.focus();
        return;
      }
      errorBox.hidden = true;

      var data = new FormData(form);
      var text = [
        'Demo request for LabNest',
        'Name: ' + data.get('name'),
        'Clinic: ' + data.get('clinic'),
        'Mobile: ' + data.get('phone'),
        'District: ' + (data.get('district') || '-'),
        'Plan: ' + data.get('plan')
      ].join('\n');

      if (via === 'email') {
        window.location.href = 'mailto:' + EMAIL +
          '?subject=' + encodeURIComponent('Demo request: ' + data.get('clinic')) +
          '&body=' + encodeURIComponent(text);
      } else {
        window.open('https://wa.me/' + WHATSAPP_NUMBER + '?text=' + encodeURIComponent(text), '_blank', 'noopener');
      }
    });
  }

  var year = document.getElementById('year');
  if (year) year.textContent = String(new Date().getFullYear());
})();
