// Run with:  node tests/logic.test.js
const assert = require('assert');
const L = require('../js/wordle-logic.js');
let n = 0;
const test = (name, fn) => { fn(); n++; console.log('PASS', name); };

test('scoring: win on trials 1-6', () => {
  [1024, 512, 256, 128, 64, 32].forEach((pts, i) => assert.strictEqual(L.scoreForTrial(i + 1), pts));
});
test('scoring: invalid trials earn 0 (loss = 0)', () => {
  [0, 7, -1, 1.5, NaN].forEach((x) => assert.strictEqual(L.scoreForTrial(x), 0));
});
test('scoring: cumulative total', () => {
  const total = [1, 3, 6].map(L.scoreForTrial).reduce((a, b) => a + b, 0) + 0;
  assert.strictEqual(total, 1024 + 256 + 32);
});
test('evaluate: all correct', () => assert.deepStrictEqual(L.evaluateGuess('crane', 'crane'), Array(5).fill('correct')));
test('evaluate: all absent', () => assert.deepStrictEqual(L.evaluateGuess('fuzzy', 'crane'), Array(5).fill('absent')));
test('evaluate: misplaced letters', () =>
  assert.deepStrictEqual(L.evaluateGuess('nacre', 'crane'), ['present', 'present', 'present', 'present', 'correct']));
test('evaluate: repeated letters in guess (one in target)', () =>
  assert.deepStrictEqual(L.evaluateGuess('geese', 'crane'), ['absent', 'absent', 'absent', 'absent', 'correct']));
test('evaluate: repeated letters in guess, exact match wins the copy', () =>
  assert.deepStrictEqual(L.evaluateGuess('eerie', 'crane'), ['absent', 'absent', 'present', 'absent', 'correct']));
test('evaluate: repeated letters in target', () =>
  assert.deepStrictEqual(L.evaluateGuess('speed', 'abide').slice(0, 1), ['absent']));
test('evaluate: two copies in target, both credited', () =>
  assert.deepStrictEqual(L.evaluateGuess('allay', 'label'), ['present', 'present', 'present', 'absent', 'absent']));
test('evaluate: letter not credited more often than in target', () =>
  assert.deepStrictEqual(L.evaluateGuess('lolly', 'floor'), ['present', 'present', 'absent', 'absent', 'absent']));
test('word list normalisation', () => {
  const out = L.normalizeWordList(['Crane', ' crane ', 'toolong', 'ab1de', 'abc', 42, 'ALOUD']);
  assert.deepStrictEqual(out.sort(), ['aloud', 'crane']);
  assert.deepStrictEqual(L.normalizeWordList(null), []);
});
test('duration formatting', () => {
  assert.strictEqual(L.formatDuration(5075), '01:24:35');
  assert.strictEqual(L.formatDuration(0), '00:00:00');
});
console.log(n + ' tests passed');
