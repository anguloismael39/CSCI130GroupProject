// Pure game logic shared by the browser and the Node test script (tests/logic.test.js).
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.WordleLogic = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  const WORD_LENGTH = 5;
  const MAX_GUESSES = 6;
  const BASE_SCORE = 1024;

  /** Lower-case, trim, keep only entries that are exactly five letters, remove duplicates. */
  function normalizeWordList(list) {
    if (!Array.isArray(list)) return [];
    const seen = new Set();
    for (const entry of list) {
      if (typeof entry !== 'string') continue;
      const word = entry.trim().toLowerCase();
      if (/^[a-z]{5}$/.test(word)) seen.add(word);
    }
    return Array.from(seen);
  }

  /** Returns an array of 'correct' | 'present' | 'absent', one per letter, using letter counts. */
  function evaluateGuess(guess, target) {
    const result = new Array(WORD_LENGTH).fill('absent');
    const unused = {};
    for (let i = 0; i < WORD_LENGTH; i++) {            // pass 1: exact matches
      if (guess[i] === target[i]) result[i] = 'correct';
      else unused[target[i]] = (unused[target[i]] || 0) + 1;
    }
    for (let i = 0; i < WORD_LENGTH; i++) {            // pass 2: misplaced letters while copies remain
      if (result[i] !== 'correct' && unused[guess[i]] > 0) {
        result[i] = 'present';
        unused[guess[i]]--;
      }
    }
    return result;
  }

  /** Points for solving on trial n: 1024 / 2^(n-1). Anything else scores 0. */
  function scoreForTrial(n) {
    if (!Number.isInteger(n) || n < 1 || n > MAX_GUESSES) return 0;
    return BASE_SCORE / Math.pow(2, n - 1);
  }

  /** Seconds -> HH:MM:SS */
  function formatDuration(totalSeconds) {
    const t = Math.max(0, Math.floor(Number(totalSeconds) || 0));
    const h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
    return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
  }

  return { WORD_LENGTH, MAX_GUESSES, BASE_SCORE, normalizeWordList, evaluateGuess, scoreForTrial, formatDuration };
});
