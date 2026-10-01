/* =====================================================================
   ISOKO RYACU — auth.js
   Client-side validation helpers for register / login / sell form.
   Server-side validation in includes/auth.php is the source of truth.
   ===================================================================== */
(function () {
  'use strict';

  function showField(input, message) {
    var group = input.closest('.form-group') || input.parentElement;
    var err = group.querySelector('.form-error');
    if (!err) {
      err = document.createElement('div'); err.className = 'form-error';
      group.appendChild(err);
    }
    err.textContent = message;
    input.style.borderColor = message ? 'var(--accent-600)' : '';
  }

  function validEmail(s) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s); }

  // Register form
  var register = document.getElementById('registerForm');
  if (register) {
    register.addEventListener('submit', function (ev) {
      var ok = true;
      var name = register.querySelector('[name="full_name"]');
      var email = register.querySelector('[name="email"]');
      var pass = register.querySelector('[name="password"]');
      var conf = register.querySelector('[name="password_confirm"]');
      var terms = register.querySelector('[name="agree_terms"]');

      if (name && name.value.trim().length < 3) { showField(name, 'Enter at least 3 characters.'); ok = false; }
      else if (name) showField(name, '');

      if (email && !validEmail(email.value)) { showField(email, 'Enter a valid email.'); ok = false; }
      else if (email) showField(email, '');

      if (pass && pass.value.length < 8) { showField(pass, 'Min 8 characters.'); ok = false; }
      else if (pass) showField(pass, '');

      if (conf && conf.value !== pass.value) { showField(conf, 'Passwords do not match.'); ok = false; }
      else if (conf) showField(conf, '');

      if (terms && !terms.checked) { ev.preventDefault(); alert('Please accept the terms to continue.'); return; }

      if (!ok) ev.preventDefault();
    });
  }

  // Login form
  var login = document.getElementById('loginForm');
  if (login) {
    login.addEventListener('submit', function (ev) {
      var email = login.querySelector('[name="email"]');
      var pass  = login.querySelector('[name="password"]');
      var ok = true;
      if (email && !validEmail(email.value)) { showField(email, 'Enter a valid email.'); ok = false; } else if (email) showField(email, '');
      if (pass && !pass.value)                { showField(pass,  'Enter your password.'); ok = false; } else if (pass) showField(pass, '');
      if (!ok) ev.preventDefault();
    });
  }
})();
