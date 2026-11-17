// Login and registration forms.
(function () {
  'use strict';
  const { Api } = App;

  function setup(formId, url) {
    const form = document.getElementById(formId);
    const message = form.querySelector('.form-message');
    const button = form.querySelector('button');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      message.className = 'form-message';
      message.textContent = '';
      if (!form.checkValidity()) {            // browser-side validation; the server validates again
        form.reportValidity();
        return;
      }
      button.disabled = true;
      try {
        await Api.post(url, {
          username: form.elements.username.value.trim(),
          password: form.elements.password.value,
        });
        message.className = 'form-message ok';
        message.textContent = 'Success! Redirecting to the game…';
        window.location.href = 'game.html';
      } catch (e) {
        const details = Object.values(e.errors || {});
        message.textContent = e.message + (details.length && e.status === 400 ? ' ' + details.join(' ') : '');
        button.disabled = false;
      }
    });
  }

  setup('login-form', 'api/auth/login');
  setup('register-form', 'api/auth/register');

  App.userPromise.then((result) => {
    if (result.user) {
      document.getElementById('forms').hidden = true;
      document.getElementById('auth-state').textContent =
        'You are logged in as ' + result.user.username + '. Use Logout in the menu to sign out.';
    } else if (result.error) {
      document.getElementById('auth-state').textContent = 'Could not check your login status: ' + result.error;
    }
  });
})();
