# Live GPS Tracking

## 1. Data flow
```text
Driver's smartphone (browser, driver/live-tracking.php)
   │ navigator.geolocation.watchPosition({enableHighAccuracy:true})
   ▼
assets/js/driver-gps.js  ── every ~5 s (setting gps_send_interval) ──►  POST api/driver/update-location.php
   {lat, lng, accuracy, speed, heading, timestamp}  + X-CSRF-Token + session cookie
   ▼
PHP: authenticated? role = driver? CSRF ok?
     driver → active assignment → bus → route   (from the DATABASE – any bus_id sent is ignored)
     active trip for this driver AND this bus?   (else 409)
     valid lat/lng/accuracy? timestamp within ±10 min?  ≥2 s since last fix?  no >150 km/h jump?
   ▼
INSERT bus_locations ; UPDATE trips.last_location_at
   ▲
GET api/tracking/bus-location.php   (student: bus of THEIR pass only; admin: any bus)
   ▲ every ~7 s (setting tracking_poll_seconds); slows to 30 s when the tab is hidden
assets/js/track-bus.js → Leaflet + OpenStreetMap: route polyline, stops, boarding (green), destination (red), live bus marker
```

## 2. Trip lifecycle
| Action | Endpoint | Rules |
|---|---|---|
| START TRIP | `api/driver/start-trip.php` `{mode}` | Bus must be *active*, route *active*. Creates `trips` row (`status=active`, `gps_mode`). DB unique keys prevent two active trips for the same driver or bus. Re-pressing resumes the existing trip (its mode cannot change). |
| Location | `api/driver/update-location.php` | See checks above. `is_simulated` is copied from the **trip's** mode, never from the client. |
| STOP TRIP | `api/driver/stop-trip.php` | Marks `completed`, stores end coordinates = last fix. |
| Force end | Admin → Trips → End | Marks `cancelled`; the driver's next upload gets 409 and the page stops sending. |

## 3. Live / delayed / offline (computed on the server)
| Status | Condition (defaults, editable in Admin → Settings) | Shown as |
|---|---|---|
| `live` | active trip and last fix ≤ 30 s old | 🟢 LIVE (or 🟣 LIVE (DEMO SIMULATION)) |
| `delayed` | ≤ 120 s old | 🟡 LOCATION DELAYED |
| `offline` | older | 🔴 BUS LOCATION OFFLINE + "Last updated: 3 minutes ago" |
| `waiting` | trip started, no fix yet | ⏳ waiting for GPS |
| `no_trip` | no active trip | ⚪ NO ACTIVE TRIP (old position greyed out) |

The age is computed with the server clock (`received_at`), so a phone with a wrong clock cannot make old data look live.

## 4. DEMO GPS SIMULATION
For classroom demos where real GPS is unavailable:
- Driver selects **DEMO SIMULATION** → START TRIP. The trip is stored with `gps_mode = 'simulation'`.
- The page interpolates points every 0.4 km along the route's stops (out and back, looping) and posts one point per interval
  through the **same** secure API. It is a time-lapse; no speed is reported.
- Every screen shows **DEMO SIMULATION** (driver banner, purple bus marker, student banner, admin badges, trip history).
  Simulated points are never presented as real GPS.

## 5. Real-world requirements (honest limits)
Live tracking works only when **all** of these are true:
- GPS/location is switched on and the browser has **location permission** for the site;
- the page is served over **HTTPS** (or `localhost`) – browsers block geolocation on plain `http://` LAN addresses;
- the phone has **internet**;
- the browser is a modern one (Chrome/Edge/Firefox/Safari) and the **tracking page stays open** with the screen on.
  Android and iOS normally suspend web pages in the background or when the screen locks; the page asks for a
  *screen wake lock* to reduce this, but cannot guarantee it. If the browser is closed, tracking stops and students
  will see **DELAYED → OFFLINE**, never a fake "LIVE".

For 24×7 tracking independent of a person's phone, use a native Android app with a foreground location service
or a dedicated GPS device that posts to the same API.

## 6. Accuracy & privacy
- `accuracy` (metres) from the phone is stored and drawn as a circle on the student map.
- Location is collected **only during an active trip** started by the driver. The driver's phone number is never
  used for tracking. Students only see the bus of their own valid pass.
