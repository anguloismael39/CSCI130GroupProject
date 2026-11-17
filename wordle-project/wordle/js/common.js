// Shared by every page: API helper and the login/logout item in the navigation.
(function () {
  'use strict';

  class ApiError extends Error {
    constructor(message, status, errors) {
      super(message);
      this.status = status;
      this.errors = errors || {};
    }
  }

  /** JSON request helper. Throws ApiError with a user-readable message on any failure. */
  async function request(method, url, body) {
    const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(url, options);
    } catch (e) {
      throw new ApiError('Cannot reach the server. Please try again later.', 0);
    }
    let data = null;
    try {
      data = await res.json();
    } catch (e) {
      if (res.ok) throw new ApiError('The server sent an unreadable response.', res.status);
    }
    if (!res.ok) {
      throw new ApiError((data && data.error) || 'Request failed (' + res.status + ').', res.status, data && data.errors);
    }
    return data;
  }

  const Api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body === undefined ? {} : body),
  };

  /** Resolves to { user, stats, error }. user is null for guests. */
  async function fetchUser() {
    try {
      const data = await Api.get('api/me');
      return { user: data.user, stats: data.stats, error: null };
    } catch (e) {
      return { user: null, stats: null, error: e.status === 401 ? null : e.message };
    }
  }

  function renderNav(result) {
    const item = document.getElementById('auth-nav');
    if (!item || !result.user) return;
    item.textContent = '';
    const name = document.createElement('span');
    name.className = 'nav-user';
    name.textContent = result.user.username;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'link-button';
    button.textContent = 'Logout';
    button.addEventListener('click', async () => {
      try {
        await Api.post('api/auth/logout');
      } catch (e) {
        if (e.status !== 401) { alert('Logout failed: ' + e.message); return; }
      }
      window.location.href = 'index.html';
    });
    item.append(name, button);
  }

  const userPromise = fetchUser();
  userPromise.then(renderNav);
  window.App = { Api, ApiError, userPromise };
})();
