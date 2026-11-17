// Sortable leaderboard. Sorting is done by the server through validated query parameters.
(function () {
  'use strict';
  const { Api } = App;
  const { formatDuration } = WordleLogic;
  const statusEl = document.getElementById('lb-status');
  const tbody = document.querySelector('#leaderboard tbody');
  const headers = document.querySelectorAll('#leaderboard th[data-sort]');
  const LABELS = { username: 'Player', score: 'Score', games_played: 'Games played', total_time: 'Total time', completed_games: 'Completed games' };
  let sort = 'score';
  let dir = 'desc';

  function cell(text, numeric) {
    const td = document.createElement('td');
    if (numeric) td.className = 'num';
    td.textContent = text;
    return td;
  }

  function markHeaders() {
    headers.forEach((th) => {
      const active = th.dataset.sort === sort;
      th.setAttribute('aria-sort', active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
      th.querySelector('.sort-indicator').textContent = active ? (dir === 'asc' ? '▲' : '▼') : '';
      if (th.dataset.sort !== 'username') th.classList.add('num');
    });
  }

  async function load() {
    markHeaders();
    statusEl.textContent = 'Loading…';
    try {
      const data = await Api.get('api/leaderboard?sort=' + encodeURIComponent(sort) + '&dir=' + encodeURIComponent(dir));
      if (!data || !Array.isArray(data.players)) throw new Error('The server sent malformed leaderboard data.');
      tbody.textContent = '';
      data.players.forEach((p) => {
        const tr = document.createElement('tr');
        tr.append(cell(p.username, false), cell(p.score, true), cell(p.games_played, true),
                  cell(formatDuration(p.total_time_seconds), true), cell(p.completed_games, true));
        tbody.append(tr);
      });
      statusEl.textContent = data.players.length
        ? 'Sorted by ' + LABELS[sort] + ', ' + (dir === 'asc' ? 'ascending' : 'descending') + '.'
        : 'No players have registered yet.';
    } catch (e) {
      tbody.textContent = '';
      statusEl.textContent = 'Could not load the leaderboard: ' + e.message;
    }
  }

  headers.forEach((th) => {
    th.querySelector('button').addEventListener('click', () => {
      const field = th.dataset.sort;
      if (field === sort) dir = dir === 'asc' ? 'desc' : 'asc';
      else { sort = field; dir = field === 'username' ? 'asc' : 'desc'; }
      load();
    });
  });

  load();
})();
