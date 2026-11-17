# Test plan and results

Automated: `node tests/logic.test.js` (scoring, letter evaluation, word-list normalisation) and
`php tests/api_test.php http://localhost/wordle` (authentication, persistence, duplicate completion, leaderboard).
Manual: steps below. Add screenshots to a `screenshots/` folder and fill in the Result column (Pass/Fail + date).

| Area | Case | How | Expected | Result |
|---|---|---|---|---|
| Scoring | Win on trials 1–6 | logic test + play | 1024, 512, 256, 128, 64, 32 | |
| Scoring | Loss after trial 6 | logic test + play | 0 points | |
| Scoring | Cumulative total | api test | Sum of all completed games | |
| Evaluation | All correct / all absent | logic test | All green / all gray | |
| Evaluation | Misplaced letters | logic test | Amber ↔ tiles | |
| Evaluation | Repeated letters in guess / target | logic test | Never more credits than copies in target | |
| Input | Too short | Press Enter with < 5 letters | "Not enough letters." | |
| Input | Too long | Type a 6th letter | Ignored | |
| Input | Non-letters | Type digits/symbols | Ignored | |
| Input | Word absent from list | Enter e.g. `zzzzz` | "Not in word list.", row stays, no guess used | |
| Input | Physical and on-screen keyboard | Play one game with each | Both work; keys colored with symbols | |
| Authentication | Valid registration and login | login.html | Redirect to game, name in menu | |
| Authentication | Duplicate username | Register same name twice | Error message | |
| Authentication | Incorrect password | Login with wrong password | Generic error | |
| Authentication | Logout | Menu → Logout | Back to guest | |
| Authentication | Protected route without login | api test / `curl /api/me` | 401 | |
| Persistence | Stats survive refresh and new login | Play, refresh, log out/in | Same stats shown | |
| Persistence | Completion cannot be recorded twice | api test | Second complete → 409 | |
| Leaderboard | Every sort field, both directions | Click each header twice | Correct order, indicator shown | |
| Leaderboard | Numeric time sorting | Compare 00:09:59 vs 00:10:00 | Sorted by seconds | |
| Leaderboard | Deterministic ties | Two players equal score | Completed ↓, time ↑, username ↑ | |
| Resilience | Server unavailable | Stop Apache, reload game page | Clear error, no crash | |
| Resilience | Word dataset unavailable | Empty `words` table | "Word list has not been loaded" message | |
| Resilience | Malformed response | Make `/api/words` return `{}` | "malformed" message | |
| Resilience | Database error | Stop MySQL | JSON 500, page shows message | |
| Accessibility | Keyboard-only play and sorting | Tab/Enter only | Fully usable, visible focus | |
