# Testing

The project was tested end-to-end (PHP 8.3 + MariaDB 10.11, Apache-equivalent routing, Chromium) with scripted HTTP
tests and browser rendering. Re-run the checklist below before a demo.

## Automated scenario results (95 checks, all passing at release)
| Area | Checks |
|---|---|
| Registration | valid registration + photo upload, duplicate USN rejected, USN normalised, XSS in address rendered escaped |
| Application | fee quote (₹11,220 for Hubballi→Campus annual), stop from another route rejected, client-sent fee ignored, second open application blocked |
| Payment | official QR displayed, duplicate UTR rejected, malformed UTR rejected, PHP file disguised as JPG rejected, valid UTR + screenshot → *Pending Verification* (never auto-verified) |
| Authorization | other student's payment page (IDOR) blocked, student → admin page 403, student → GPS API 403, other student's screenshot 404, driver → admin 403, unauthenticated API 401 |
| CSRF | form POST without token → 419, API without header → 419, logout via GET ignored |
| Admin | all 22 admin pages render; verify payment → approve → pass issued; reject payment with reason shown to student; route/stop/fee/driver/bus CRUD; stop re-ordering; driver assignment; cannot unassign during a trip; force-end trip stops driver GPS; stop request lands on the driver's own route; QR replacement takes effect on the student page and default restore works; CSV export |
| Pass | pass shows QR, verify page VALID, unknown token INVALID, expired pass → INVALID – EXPIRED, photo visible to conductor only via valid token |
| GPS | upload before trip → 409, simulation trip flagged simulated, rate limit → 429, invalid latitude → 422, stale timestamp → 422, `bus_id` from client ignored, admin live map, stop trip → NO ACTIVE TRIP, real-GPS 30 km jump in 2 s rejected |
| Login security | SQL-injection string fails, lockout after 5 failures even with correct password, POST logout destroys session |
| Browser | no JavaScript or CSP errors on student/driver/admin pages; mobile layout of driver page |

## Manual checklist
- [ ] Register a new student with your own details and photo
- [ ] Wrong password shows a generic error; 5 wrong attempts lock the account for 15 min
- [ ] Leave a page idle > 30 min → next click asks to log in again (session expiry)
- [ ] Apply → payment page shows the official QR → scan it with a UPI app (do not pay) to confirm it opens the college merchant
- [ ] Submit UTR → admin verifies → admin approves → student sees pass → scan pass QR with a phone → VALID
- [ ] Driver: DEMO SIMULATION → student Track Bus shows moving purple marker + DEMO SIMULATION banner
- [ ] Driver on HTTPS phone: REAL GPS → student sees LIVE; switch phone to airplane mode → DELAYED then OFFLINE
- [ ] Admin → Trips → Path shows the recorded route
