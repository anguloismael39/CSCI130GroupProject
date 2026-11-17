// Wordle game: all game logic runs here in the browser. The server supplies words and stores results.
(function () {
  'use strict';
  const L = WordleLogic;
  const { Api } = App;
  const $ = (id) => document.getElementById(id);

  const boardEl = $('board'), keyboardEl = $('keyboard'), statusEl = $('status');
  const timerEl = $('timer'), opportunityEl = $('opportunity'), summaryEl = $('summary');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const LABEL = { correct: 'correct', present: 'in the word, wrong position', absent: 'not in the word' };
  const RANK = { absent: 1, present: 2, correct: 3 };
  const LAYOUT = [
    'qwertyuiop'.split(''),
    'asdfghjkl'.split(''),
    ['Enter'].concat('zxcvbnm'.split(''), ['Backspace']),
  ];

  let answers = [];
  let allowedWords = new Set();
  let target = '';                 // kept only in memory, never written into the page before the game ends
  let guesses = [];
  let currentGuess = '';
  let state = 'loading';           // loading | playing | over | error
  let busy = true;                 // blocks input during animations and server requests
  let gameId = null;
  let isAuth = false;
  let authWarning = '';
  let startMs = 0;
  let timerId = null;
  let finalSeconds = 0;
  const keyStates = {};

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const tile = (r, c) => boardEl.children[r].children[c];
  const elapsed = () => Math.max(0, Math.round((Date.now() - startMs) / 1000));

  function setStatus(text) { statusEl.textContent = text; }

  /** Unbiased random index (rejection sampling on a cryptographic random source). */
  function randomIndex(n) {
    const limit = Math.floor(0x100000000 / n) * n;
    const buf = new Uint32Array(1);
    do { crypto.getRandomValues(buf); } while (buf[0] >= limit);
    return buf[0] % n;
  }

  // ---------- Building the interface ----------
  function buildBoard() {
    boardEl.textContent = '';
    for (let r = 0; r < L.MAX_GUESSES; r++) {
      const row = document.createElement('div');
      row.className = 'row';
      row.setAttribute('role', 'row');
      for (let c = 0; c < L.WORD_LENGTH; c++) {
        const t = document.createElement('div');
        t.className = 'tile';
        t.setAttribute('role', 'gridcell');
        t.setAttribute('aria-label', 'Row ' + (r + 1) + ', column ' + (c + 1) + ', empty');
        row.append(t);
      }
      boardEl.append(row);
    }
  }

  function buildKeyboard() {
    keyboardEl.textContent = '';
    LAYOUT.forEach((keys) => {
      const row = document.createElement('div');
      row.className = 'key-row';
      keys.forEach((k) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'key' + (k.length > 1 ? ' wide' : '');
        b.dataset.key = k;
        b.textContent = k === 'Backspace' ? '⌫' : k;
        b.setAttribute('aria-label', k.length > 1 ? k : k.toUpperCase());
        b.addEventListener('click', (e) => {
          handleKey(k);
          if (e.detail > 0) b.blur();   // pointer click: do not keep focus (Enter would re-press this key)
        });
        row.append(b);
      });
      keyboardEl.append(row);
    });
  }

  function resetKeyboard() {
    Object.keys(keyStates).forEach((k) => delete keyStates[k]);
    keyboardEl.querySelectorAll('.key').forEach((b) => {
      delete b.dataset.state;
      if (b.dataset.key.length === 1) b.setAttribute('aria-label', b.dataset.key.toUpperCase());
    });
  }

  function updateKeyboard(guess, result) {
    for (let i = 0; i < L.WORD_LENGTH; i++) {
      const letter = guess[i];
      if (!keyStates[letter] || RANK[result[i]] > RANK[keyStates[letter]]) keyStates[letter] = result[i];
    }
    keyboardEl.querySelectorAll('.key').forEach((b) => {
      const s = keyStates[b.dataset.key];
      if (s) {
        b.dataset.state = s;
        b.setAttribute('aria-label', b.dataset.key.toUpperCase() + ', ' + LABEL[s]);
      }
    });
  }

  function renderCurrentRow() {
    const r = guesses.length;
    if (r >= L.MAX_GUESSES) return;
    for (let c = 0; c < L.WORD_LENGTH; c++) {
      const t = tile(r, c);
      const letter = currentGuess[c] || '';
      t.textContent = letter;
      t.classList.toggle('filled', letter !== '');
      t.setAttribute('aria-label', 'Row ' + (r + 1) + ', column ' + (c + 1) + ', ' + (letter ? letter.toUpperCase() : 'empty'));
    }
  }

  function updateOpportunity() {
    opportunityEl.textContent = state === 'playing' ? String(L.scoreForTrial(guesses.length + 1)) : '–';
  }

  function shakeRow() {
    const row = boardEl.children[guesses.length];
    if (!row) return;
    row.classList.remove('shake');
    void row.offsetWidth;
    row.classList.add('shake');
  }

  function showStats(stats) {
    if (!stats) return;
    $('stat-score').textContent = stats.cumulative_score;
    $('stat-played').textContent = stats.games_played;
    $('stat-won').textContent = stats.games_won;
    $('stat-time').textContent = L.formatDuration(stats.total_time_seconds);
    $('stats-panel').hidden = false;
  }

  // ---------- Timer ----------
  function startTimer() {
    clearInterval(timerId);
    startMs = Date.now();
    finalSeconds = 0;
    timerEl.textContent = L.formatDuration(0);
    timerId = setInterval(() => { timerEl.textContent = L.formatDuration(elapsed()); }, 1000);
  }

  function stopTimer() {
    finalSeconds = elapsed();
    clearInterval(timerId);
    timerId = null;
    timerEl.textContent = L.formatDuration(finalSeconds);
  }

  // ---------- Word list ----------
  async function loadWords() {
    const data = await Api.get('api/words');
    if (!data || !Array.isArray(data.answers) || !Array.isArray(data.allowed)) {
      throw new Error('The word list from the server is malformed.');
    }
    const answerList = L.normalizeWordList(data.answers);
    const allowedList = L.normalizeWordList(data.allowed.concat(data.answers));
    if (!answerList.length || !allowedList.length) throw new Error('The word list from the server is empty or invalid.');
    answers = answerList;
    allowedWords = new Set(allowedList);
  }

  // ---------- Game flow ----------
  async function newGame() {
    busy = true;
    clearInterval(timerId);
    state = 'playing';
    summaryEl.hidden = true;
    guesses = [];
    currentGuess = '';
    gameId = null;
    buildBoard();
    resetKeyboard();
    target = answers[randomIndex(answers.length)];
    timerEl.textContent = L.formatDuration(0);
    updateOpportunity();

    if (isAuth) {
      setStatus('Starting game…');
      try {
        const data = await Api.post('api/games/start');
        gameId = data.game_id;
        setStatus('Type your first guess.');
      } catch (e) {
        gameId = null;
        if (e.status === 401) isAuth = false;
        setStatus('This game could not be registered with the server (' + e.message + '). You can still play, but the result will not be saved.');
      }
    } else {
      setStatus((authWarning ? authWarning + ' ' : '') + 'Playing as a guest. Type your first guess.');
    }
    startTimer();      // timing begins when the target and a playable board are ready
    busy = false;
  }

  function handleKey(key) {
    if (state !== 'playing' || busy) return;
    if (/^[a-z]$/.test(key)) {
      if (currentGuess.length < L.WORD_LENGTH) { currentGuess += key; renderCurrentRow(); }
    } else if (key === 'Backspace') {
      currentGuess = currentGuess.slice(0, -1);
      renderCurrentRow();
    } else if (key === 'Enter') {
      submitGuess();
    }
  }

  async function revealRow(r, guess, result) {
    for (let c = 0; c < L.WORD_LENGTH; c++) {
      if (!reduceMotion) await sleep(180);
      const t = tile(r, c);
      t.dataset.state = result[c];
      t.classList.add('reveal');
      t.setAttribute('aria-label', guess[c].toUpperCase() + ', ' + LABEL[result[c]]);
    }
  }

  async function submitGuess() {
    if (currentGuess.length < L.WORD_LENGTH) { shakeRow(); setStatus('Not enough letters.'); return; }
    if (!allowedWords.has(currentGuess)) { shakeRow(); setStatus('Not in word list.'); return; }

    busy = true;
    const guess = currentGuess;
    const rowIndex = guesses.length;
    const result = L.evaluateGuess(guess, target);
    guesses.push(guess);
    currentGuess = '';
    await revealRow(rowIndex, guess, result);
    updateKeyboard(guess, result);

    const won = guess === target;
    if (won || guesses.length === L.MAX_GUESSES) {
      await finishGame(won);
    } else {
      const parts = result.map((s, i) => guess[i].toUpperCase() + ' ' + LABEL[s]);
      setStatus('Guess ' + guesses.length + ': ' + parts.join('; ') + '.');
      updateOpportunity();
    }
    busy = false;
  }

  async function finishGame(won) {
    state = 'over';
    stopTimer();
    const attempts = guesses.length;
    let points = won ? L.scoreForTrial(attempts) : 0;
    let saveMessage;

    if (gameId !== null) {
      setStatus('Saving your result…');
      try {
        const data = await Api.post('api/games/' + gameId + '/complete', { won, attempts, duration_seconds: finalSeconds });
        points = data.game.score;
        showStats(data.stats);
        saveMessage = 'Your result has been saved.';
      } catch (e) {
        saveMessage = 'Your result could not be saved: ' + e.message;
      }
    } else if (isAuth) {
      saveMessage = 'This game was not saved because it could not be registered with the server.';
    } else {
      saveMessage = 'Log in to save your results and appear on the leaderboard.';
    }
    gameId = null;

    const headline = won
      ? 'You won on guess ' + attempts + '!'
      : 'Out of guesses. Better luck next time.';
    $('sum-result').textContent = headline;
    $('sum-word').textContent = target.toUpperCase();
    $('sum-points').textContent = points;
    $('sum-time').textContent = L.formatDuration(finalSeconds);
    $('sum-save').textContent = saveMessage;
    summaryEl.hidden = false;
    opportunityEl.textContent = String(points);
    setStatus(headline + ' The word was ' + target.toUpperCase() + '. Points earned: ' + points + '. ' + saveMessage);
    summaryEl.focus();
  }

  async function startFromButton(button) {
    button.blur();
    if (busy) return;
    if (!answers.length) {
      busy = true;
      setStatus('Loading word list…');
      try {
        await loadWords();
      } catch (e) {
        state = 'error';
        busy = false;
        setStatus('Could not load the word list: ' + e.message + ' Select New game to try again.');
        return;
      }
    }
    await newGame();
  }

  async function init() {
    buildBoard();
    buildKeyboard();
    const result = await App.userPromise;
    isAuth = !!result.user;
    if (isAuth) showStats(result.stats);
    else {
      $('guest-note').hidden = false;
      if (result.error) authWarning = 'Could not check your login status (' + result.error + ').';
    }
    try {
      await loadWords();
    } catch (e) {
      state = 'error';
      busy = false;
      setStatus('Could not load the word list: ' + e.message + ' Select New game to try again.');
      return;
    }
    await newGame();
  }

  // Physical keyboard
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const tag = e.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (tag === 'BUTTON' && (e.key === 'Enter' || e.key === ' ')) return;   // let the focused button activate itself
    let key = null;
    if (/^[a-zA-Z]$/.test(e.key)) key = e.key.toLowerCase();
    else if (e.key === 'Enter' || e.key === 'Backspace') key = e.key;
    if (key) { e.preventDefault(); handleKey(key); }
  });

  $('new-game').addEventListener('click', (e) => startFromButton(e.currentTarget));
  $('new-game-2').addEventListener('click', (e) => startFromButton(e.currentTarget));

  init();
})();
