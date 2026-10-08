# Sphinx Hotel Search — No Availability Returned (Escalation)

**To:** Sphinx / Christian Tour API team
**From:** Euphoric Travel (account `guta.sergiu@gmail.com`)
**Environment:** dev — `https://api.sphinx2.christiantour.dev.ploi.imementohub.com`
**API key:** `51|<redacted>` (Bearer)

## Summary / the ask

The hotel **search** endpoint returns **zero offers for every query** in the dev
environment — every search completes immediately with `cursor: null` and an empty
`data` array. This happens for individual hotels, whole destinations, all dates we
tried (Jun–Dec 2026), and both `EUR` and `RON`. Authentication and the static
catalog work fine.

**Question for you:** are live hotel **suppliers/contracts enabled for this
account in the dev environment?** The search engine accepts our requests and
returns a valid cursor, but no supplier offers ever come back, while the static
catalog (hotels, descriptions, images) is fully populated. This looks like no
live supplier connection is attached to the account in dev.

## Controls that PASS (so it is not auth / connectivity / our request shape)

```bash
BASE='https://api.sphinx2.christiantour.dev.ploi.imementohub.com'
KEY='51|...'   # the bearer token above

# Connectivity
curl -s "$BASE/api/v1/ping"
# -> "pong"

# Auth
curl -s "$BASE/api/v1/me" -H "Authorization: Bearer $KEY"
# -> {"data":{"name":"Api euphoric","email":"guta.sergiu@gmail.com","agency_name":"Euphoric Travel"}}

# Static catalog (the hotel exists and is fully described)
curl -s "$BASE/api/v1/static/hotels/110248" -H "Authorization: Bearer $KEY"
# -> {"data":{"id":110248,"destination_id":89728,"name":"Guest House Nikolina","type":"hotel","classification":3,...}}

# Request validation works (omitting the filter is correctly rejected)
curl -s -X POST "$BASE/api/v1/hotels/search" -H "Authorization: Bearer $KEY" \
  -H 'Content-Type: application/json' \
  -d '{"check_in":"2026-08-12","check_out":"2026-08-19","occupancy":[{"adults":2,"children_ages":[]}],"currency":"EUR"}'
# -> 422 {"message":"The destination id field is required when hotel ids is not present."}
```

## The problem (reproducible)

A well-formed search per your spec returns a cursor, then the first results page is
already `cursor: null` with empty `data` — i.e. end-of-search with no offers.

```bash
# 1) initiate search for hotel 110248 (Guest House Nikolina, Dubrovnik), Oct 8–15
curl -s -X POST "$BASE/api/v1/hotels/search" -H "Authorization: Bearer $KEY" \
  -H 'Content-Type: application/json' \
  -d '{"check_in":"2026-10-08","check_out":"2026-10-15","occupancy":[{"adults":2,"children_ages":[]}],"currency":"EUR","hotel_ids":[110248]}'
# -> {"cursor":"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...."}

# 2) poll results with that cursor
curl -s "$BASE/api/v1/hotels/results?cursor=<CURSOR_FROM_STEP_1>" -H "Authorization: Bearer $KEY"
# -> {"data":[],"cursor":null}      # empty + cursor:null on the FIRST page => 0 offers, search done
```

Observed: the search completes in **~2–5 seconds** with `cursor: null` on the
first or second page. Per your overview docs, `cursor: null` is the definitive
end-of-search signal; a sub-5s completion with no offers indicates no suppliers
responded at all.

## Coverage tested (all returned 0 offers, all ended `cursor: null`)

| Dimension | Values tested | Result |
|---|---|---|
| Specific hotel | `hotel_ids:[110248]` (Guest House Nikolina, HR) | 0 |
| Whole destination | `destination_id:89728` (Dubrovnik) | 0 |
| Other destinations | 33+ worldwide incl. Dubai, Abu Dhabi, Dhaka, etc. | 0 |
| Dates | Jun, Jul, Aug, Sep, Oct, Dec 2026 (7-night stays) | 0 |
| Currency | `EUR` and `RON` | 0 |
| Occupancy | 2 adults, 1 room | 0 |

We also confirmed `hotel_ids` alone, `destination_id` alone, and both combined all
return 0 for the same hotel/destination.

## The cached-hotels feed is also empty

`POST /api/v1/cache/hotels` (hotels-with-cached-prices by destination — the
synchronous, non-polling path, and the likely source of product-page "from"
prices) reports **zero hotels** for the same destination:

```bash
curl -s -X POST "$BASE/api/v1/cache/hotels" -H "Authorization: Bearer $KEY" \
  -H 'Content-Type: application/json' \
  -d '{"destination_id":89728,"check_in":"2026-08-12","check_out":"2026-08-19","occupancy":[{"adults":2,"children_ages":[]}],"currency":"EUR"}'
# -> {"data":[],"meta":{"stats":{"min_price":{"price":0,"currency":"","nights":0},"total_hotels":0},"pagination":{"page":1,"size":10,"total":0}}}
```

So **all three retrieval paths** — live `hotel_ids` search, live `destination_id`
search, and the cached `cache/hotels` feed — return zero for a destination whose
static catalog is fully populated.

### Exhaustive sweep (every destination)

To rule out a destination-specific gap, we swept **all 741 leaf destinations**
(type city/resort/region) via `POST /api/v1/cache/hotels` for an August 2026 stay:

- **741 / 741 destinations returned `total_hotels: 0`.**
- A 20-destination spread sample was cross-checked with **live** `/hotels/search`
  (cursor-polled to `cursor:null`): live and cache agree — **all 0**.

Combined with the earlier coverage (33 destinations live, dates Jun–Dec 2026,
EUR + RON), **no destination returns any hotel availability** while the static
catalog (`/static/hotels/{id}`, `/static/destinations` = 1000 entries) is fully
populated.

## Cursor lifecycle (why we conclude "no suppliers", not "we stopped early")

Following the cursor chain on a fresh destination search:

```
search-init        -> cursor present, payload {search_id, cursor:13638206914426.016, limit:10000}
GET results poll#1  -> {"data":[], "cursor":"<same position 13638206914426.016>"}   (~3s)
GET results poll#2  -> {"data":[], "cursor":null}                                    (~7s)  => end
```

- We follow the cursor exactly per the spec and only stop when the API returns
  `cursor:null`. The cursor **position never advances** and goes `null` in ~7s
  with **zero** offers.
- Per the spec, a live search with connected suppliers keeps a **non-null** cursor
  for "a couple of tens of seconds" while offers stream in (empty intermediate
  pages are normal *while the cursor stays non-null*). A sub-10s `cursor:null`
  with 0 offers and a non-advancing position is the signature of **no suppliers
  responding** — not a client polling error.

## Environment note

- Static endpoints return data (`/static/hotels/{id}`, `/static/destinations`
  returns 1000 entries), so the catalog is loaded.
- `/packages/search` is a separate product and requires `departure_id` (not the
  subject of this report).

Please advise whether dev supplier availability needs to be enabled for this
account, or whether we should be testing against a different environment.
