# Production Cron Plan

Updated 4 November 2026.

Production needs 40 cron lines: 1 for Travel Core, 15 for Novoton, 12 for Eurosite and 12 for Sphinx, plus up to 10 more depending on what you sell. The times follow the suppliers' answers of 28 October 2026 and are ordered so no job starts before the one it depends on. Their answers, what changed and the questions still open are at the end.

This file replaces the schedule in `CRON_JOBS.txt` (March 2026). When a job is added, moved or dropped, change its row here in the same pull request.

## Setting up the lines

Each line runs one job of one add-on with the PHP command line, not a URL.

- **Copy the command from the add-on's dashboard.** Novoton, Eurosite and Sphinx each print their lines with the store's path and the cron key filled in; Travel Core prints its line under Travel Core → Tools. Never put the key in chat, email or a file in this repository.
- **Use PHP 8.3 or newer**, the same version the site runs. In cPanel a bare `php` can be an older version; the full path is usually `/opt/cpanel/ea-php83/root/usr/bin/php` (ask the host if unsure).
- **Silence the output.** End every line with `>/dev/null 2>&1`, or cPanel emails you every run: over 1,000 emails a day from the jobs that run every few minutes. Each run is still recorded on the dashboards.
- **Mind the server's clock.** The times below are Romanian time. cPanel uses the server's time zone; if it is UTC, every job shifts by 2–3 hours together, so the order still holds.
- **A job never overlaps itself.** Each job holds a lock while it runs, so a run that is still busy makes the next one skip, and a manual "Run now" cannot clash with a scheduled run.

A line looks like this (the path and key are placeholders):

```
0 4 * * *  /opt/cpanel/ea-php83/root/usr/bin/php /home/ACCOUNT/public_html/app/addons/novoton_holidays/cron.php access_key=KEY mode=room_price >/dev/null 2>&1
```

## The night at a glance

| Hours | Novoton | Eurosite | Sphinx | Travel Core |
| --- | --- | --- | --- | --- |
| 01:00–02:00 | Sundays: resorts 01:00, facilities 01:15, hotel facilities 01:45. Every 3 days: hotel list 01:30 | On the 1st: countries 01:00, own cities 01:30 | Sundays: destinations 01:00 | |
| 02:00–03:00 | Photos 02:15, done before 03:00 | On the 1st: cities 02:00 | Hotels and the availability check 02:00 (the whole list on Sundays) | |
| 03:00–04:00 | No call to Novoton, their busiest hour; the calendar rebuild at 03:30 calls nobody | Sundays: hotels 03:00. On the 1st: room types 03:30, tags 03:45 | If sold: circuits 03:15, experiences 03:30, circuit products 03:45 | Sundays: expire requests 03:20 (optional) |
| 04:00–05:00 | Weekly availability check 04:00–04:20, then new products 04:30 | Hotel details 04:00, availability check 04:15 | New products 04:00, product updates 04:30, locations 04:45 (optional). Mondays: package routes 04:00 (if sold) | |
| 05:00–06:00 | Features and `offers_update` 05:00; the full passes of hotel details and season prices start | New products 05:30. Sundays: cleanup 05:00 | Images 05:00, calendar prices 05:15, meal plans 05:30 | |
| 06:00–07:00 | Cleanup 06:00 | Product updates 06:00 | Sundays: meal-plan discovery 06:00. Cleanup 07:00 | |
| Daytime | `offers_update` again 17:00 | | | Balance reminders 09:10 (if you take deposits); exchange rates and the hotel availability work 14:05 |
| All day | Every 5 minutes: hotel details, season prices, computed prices. Every 15 minutes: bookings on request. Hourly (optional): addresses at :45, missing images at :50 | Every 15 minutes: booking status | Every 10 minutes: the image queue. Every 15 minutes: order status, and geocoding (optional) | |

A job starts only after the one it needs, such as Room price before Novoton's Add products. The three suppliers run side by side, each against its own API, and Travel Core reads their results in the afternoon.

## Travel Core

One line is needed; the other two depend on how you sell. None of them calls a supplier.

| Job | Needed | When | What it does | Requests per run |
| --- | --- | --- | --- | --- |
| `exchange_rates` | Yes | `5 14 * * *` daily 14:05 | BNR exchange rates for RON prices; BNR publishes the day's rates around 13:00. The hotel availability work rides on this line, so it has no line of its own. Every day: the "notify me" emails once booking opens (a request still waiting after 12 months is dropped with its address), the list of closed hotels whose page redirects, and in Automatic mode the hidden hotels whose offers are back are shown again. Once a week: the hotel availability review (each hotel's state and demand, the hiding in Automatic mode, the weekly email). By 14:05 every supplier's night is over; if a supplier job that records availability is still running, the review tries again the next day | 1 to BNR |
| `balances` | If you take deposits | `10 9 * * *` daily 09:10 | Balance reminders 7 and 2 days before the due date, and overdue alerts. It emails customers, hence a daytime hour | None |
| `expire_alternative_requests` | Optional | `20 3 * * 0` Sundays 03:20 | Expires Sphinx alternative requests after 30 days and deletes closed ones after 180 days (personal data). For now it runs only by URL (`curl`), not by the PHP command line | None |

`expire_alternative_requests` is not on the Tools page. Its line is a URL (the domain and key are placeholders):

```
20 3 * * 0  curl -s "https://example.com/index.php?dispatch=travel_cron.run&access_key=KEY&cron_mode=expire_alternative_requests" >/dev/null 2>&1
```

## Novoton

15 lines are needed and 2 are optional. Novoton asked (28 Oct 2026) for at most 5 requests at once and 5 a second, and no bulk work between 03:00 and 04:00, their busiest hour; every request of the store passes one gate that keeps to the limit, and both numbers are settings (Advanced). The heaviest loads are now the full passes, from 05:00: hotel details every 3 days in February–May and every 7 days otherwise, season prices monthly. In between, only what `offers_update` names is fetched again. The API calls are named as in Novoton's XML API document, with its function numbers.

| Job | Needed | When | What it does | API calls per run |
| --- | --- | --- | --- | --- |
| `resort_list` | Yes | `0 1 * * 0` Sundays 01:00 | Resort names | `resort_list` (no. 16): 1 per country you sell |
| `list_facilities` | Yes | `15 1 * * 0` Sundays 01:15 | The list of facilities | `list_facilities` (no. 26): 1 |
| `hotel_list` | Yes | `30 1 */3 * *` every 3 days, 01:30 | Basic hotel data per country; also how we notice a hotel Novoton dropped | `hotel_list` (no. 1): 1 per country |
| `hotel_info_batched` | Yes | `*/5 * * * *` every 5 minutes | Hotel details: every hotel you sell every 3 days in February–May and every 7 days otherwise (two settings), from 05:00; in between, hotels never fetched and the ones `offers_update` named with a package their details do not list. Most runs make no call | `hotelinfo` (no. 2): 1 per hotel, up to 5 at once; it no longer calls `offers_update` itself |
| `hotel_facilities_batched` | Yes | `45 1 * * 0` Sundays 01:45 | Which facilities each hotel has; a full pass every 30 days | `hotel_facilities` (no. 27): 1 per hotel, in batches |
| `refresh_images` | Yes | `15 2 * * *` daily 02:15 | Hotel photos: each night a seventh of the hotels with a product, so each hotel about once a week; done before 03:00. New photos are added in Novoton's order and replace the ones Novoton dropped; a photo already on the product is never added twice; photos added by hand are kept (a setting) | `hotel_images` (no. 6): 1 per hotel; a download only for a photo not seen before |
| `sync_priceinfo_batched` | Yes | `*/5 * * * *` every 5 minutes | Season prices: new packages and the ones `offers_update` named; every package once a month (a setting), from 05:00 | `priceinfo` (no. 13): 1 per new or named package, one at a time; once a month 1 per package |
| `compute_prices` | Yes | `*/5 * * * *` every 5 minutes | Lowest price, seasons, early booking | None |
| `recompute_calendar_prices` | Yes | `30 3 * * *` daily 03:30 | Rebuilds the booking calendar's prices | None |
| `room_price` | Yes | `0 4 * * *` daily 04:00 | The weekly availability check: each night a seventh of the resorts you sell, on the check dates. It marks the hotels with prices, and products are created only for those. Its dates, calls and minutes per run are settings (Advanced, Weekly availability check); it ends by 04:20. Dates none of a resort's hotels has published prices for are not asked (a setting, on by default), so out of season it makes far fewer calls | `room_price` (no. 3): about 60 at most, one at a time (1 per resort and date with published prices), never more than 120 (a setting); `resort_list` (no. 16): 1 per country you sell |
| `add_hotels_as_products` | Yes | `30 4 * * *` daily 04:30, after `room_price` | Creates products for priced hotels | `hotel_description` (no. 5) and `hotel_images` (no. 6): 1 each per new product |
| `reassign_features` | Yes | `0 5 * * *` daily 05:00 | Stars, resort, boards and facilities onto products | None |
| `offers_update` | Yes | `0 5,17 * * *` daily 05:00 and 17:00 | Changed offers in every country you sell: marks the packages for `priceinfo` and the hotels for `hotelinfo`; adds new hotels | `offers_update` (no. 25): 1 per country; for each new hotel `hotelinfo`, `hotel_description` and `hotel_images` |
| `resinfo` | Yes | `*/15 * * * *` every 15 minutes | Bookings on request (ASK) only, until the answer is OK, ST or RQ; RQ goes to the alternatives jobs | `resinfo` (no. 15): 1 per booking still ASK |
| `cleanup` | Yes | `0 6 * * *` daily 06:00 | Orphan bookings, old logs, expired cache | None |
| `backfill_images` | Optional | `50 * * * *` hourly | Re-attaches images to products that synced without them; usually nothing to do | `hotel_images` (no. 6): 1 per product missing images, then the image downloads |
| `geocode_addresses` | Optional | `45 * * * *` hourly | Street address from coordinates; only with geocoding on in Travel Core | None to Novoton; OpenStreetMap, 1 per second |

## Eurosite

All 12 lines are needed. Eurosite set no limits but closes a connection that looks like abuse, so scheduled runs keep to Sphinx's: one request at a time, at most 10 price searches a minute, other requests at most once a second; both are settings (Scheduled runs: pace and limits). The heaviest load is the nightly availability check: one search per city you sell and date, about 6 seconds each.

| Job | Needed | When | What it does | API calls per run |
| --- | --- | --- | --- | --- |
| `countries` | Yes | `0 1 1 * *` monthly, the 1st at 01:00 | Country catalog | `getCountryRequest`: 1 |
| `own_cities` | Yes | `30 1 1 * *` monthly, the 1st at 01:30 | Cities with Eurosite's own offers | `getOwnCityRequest`: 1 |
| `cities` | Yes | `0 2 1 * *` monthly, the 1st at 02:00 | City catalogs of the countries you sell | `getCityRequest`: 1 per country |
| `hotels` | Yes | `0 3 * * 0` Sundays 03:00 | Own hotels and rooms in the cities you sell | `getOwnHotelsRequest`: 1 per city |
| `room_types` | Yes | `30 3 1 * *` monthly, the 1st at 03:30 | Room-type catalog | `getRoomRequest`: 1 |
| `tags` | Yes | `45 3 1 * *` monthly, the 1st at 03:45 | Offer tags | `getTagOffersRequest`: 1 |
| `product_info` | Yes | `0 4 * * *` daily 04:00 | Hotel details (descriptions, pictures), kept 30 days | `getProductInfoRequest`: up to 100 (a setting), only hotels missing or older than 30 days |
| `availability` | Yes | `15 4 * * *` daily 04:15 | Which hotels have an Immediate offer; hides and shows products | `getHotelPriceRequest`: 1 per city × 5 dates (14, 30 and 60 days ahead, 15 Jul, 15 Aug; 7 nights, 2 adults), at most 10 a minute |
| `cleanup` | Yes | `0 5 * * 0` Sundays 05:00 | Trims the sync log, prunes the details cache | None |
| `add_products` | Yes | `30 5 * * *` daily 05:30 | Creates products for hotels with an Immediate offer | None |
| `update_products` | Yes | `0 6 * * *` daily 06:00 | Price, description, pictures and features of existing products | `getProductInfoRequest`: up to 200 products (a setting), only when their details are older than 30 days |
| `booking_status` | Yes | `*/15 * * * *` every 15 minutes | Bookings Eurosite has not confirmed yet, on its schedule: 30 minutes after booking, then every 30 minutes for 2 hours; if still open, again 8 hours later. A new booking stays pending until then | `getBookingRequest`: 1 per booking due |

- Do not also schedule `full`. The dashboard offers it as a shorter plan: one line at 01:00 that runs each catalog once a month and the hotel lists once a week. Installing both runs them twice.
- `booking_status` replaces pressing Sync statuses in Travel Core → Bookings for new bookings. A booking still open after its schedule (13 hours) is left to that button.

## Sphinx

12 lines are needed, 4 more if you sell circuits, experiences or packages, and 2 are optional. The heaviest loads are the live searches after the hotel sync and the calendar prices: one request per destination for each of the next 60 days, every night.

| Job | Needed | When | What it does | API calls per run |
| --- | --- | --- | --- | --- |
| `destinations` | Yes | `0 1 * * 0` Sundays 01:00 | Countries, regions and cities | `GET /api/v1/static/destinations`: the list, paged |
| `hotels` | Yes | `0 2 * * 1-6` Monday–Saturday 02:00 | Hotels changed since the last run, then the availability check: live searches 14, 30 and 60 days ahead | `GET /api/v1/static/hotels` with `updated_since`, paged; then `POST /api/v1/hotels/search` 1 per destination and date, and `GET /api/v1/hotels/results` every 3 s for up to 60 s per search |
| `hotels full=1` | Yes | `0 2 * * 0` Sundays 02:00 | The same over the whole list: the only run that notices hotels Sphinx removed | `GET /api/v1/static/hotels` without `updated_since`, paged; then the same searches |
| `add_products` | Yes | `0 4 * * *` daily 04:00 | Creates products for hotels with an immediate offer | None |
| `update_products` | Yes | `30 4 * * *` daily 04:30 | Carries changed hotel data (City, Region, features) onto products | None |
| `sync_images` | Yes | `0 5 * * *` daily 05:00 | Queues hotel images to download | None |
| `process_image_queue` | Yes | `*/10 * * * *` every 10 minutes | Downloads and attaches queued images | No API call: up to 50 downloads from the image addresses the hotel data gives |
| `calendar_prices` | Yes | `15 5 * * *` daily 05:15 | From-prices per date for the booking calendar | `POST /api/v1/cache/hotels`: 1 per destination with products × 60 dates |
| `assign_boards` | Yes | `30 5 * * *` daily 05:30 | Meal plans onto products | None |
| `discover_boards unlimited=1` | Yes | `0 6 * * 0` Sundays 06:00 | Which meal plans each hotel offers. Weekly instead of daily, since they rarely change; `unlimited=1` lets one run cover every destination | `POST /api/v1/hotels/search` 1 per destination (30 days ahead), then `GET /api/v1/hotels/results` every 3 s, up to 20 times |
| `order_status` | Yes | `*/15 * * * *` every 15 minutes | Booking statuses | `GET /api/v1/orders`: 1 per open order |
| `cleanup` | Yes | `0 7 * * *` daily 07:00 | Orphan bookings, old logs, expired cache | None |
| `circuits` | If you sell circuits | `15 3 * * *` daily 03:15 | Circuit catalog, changes only | `GET /api/v1/static/circuits` with `updated_since`, paged |
| `add_circuit_products` | If you sell circuits | `45 3 * * *` daily 03:45 | Products for circuits | None |
| `experiences` | If you sell experiences | `30 3 * * *` daily 03:30 | Experience catalog | `GET /api/v1/static/experiences`, paged |
| `package_routes` | If you sell packages | `0 4 * * 1` Mondays 04:00 | Flight and bus package routes | `GET /api/v1/static/package-routes`, paged |
| `backfill_hotel_locations` | Optional | `45 4 * * *` daily 04:45 | Fills empty City/Region from the destination tree | None |
| `geocode_hotels limit=100` | Optional | `*/15 * * * *` every 15 minutes | City/Region from coordinates; only with geocoding on in Travel Core | None to Sphinx; OpenStreetMap, 1 per second |

Sphinx's settings tab and its dashboard suggest different times (hotels at 02:00 or 03:00, `add_products` at 04:00 or 03:30, `package_routes` daily or weekly). This table follows the settings tab, except `package_routes` (weekly, as on the dashboard), `discover_boards` (weekly) and the jobs neither page lists.

## Run by hand only

These jobs get no cron line; run them from the add-on's dashboard when needed.

- **Novoton:** `full` (repeats scheduled jobs), the alternative-request jobs (`alternative_rs`, `alternative_rs_bookings`, `notify_alternatives`, `expire_requests`), `backfill_descriptions` (one-off repair), the `diagnose_*` checks.
- **Eurosite:** `full` (see the note above), `probe_availability` (a read-only check).
- **Sphinx:** `full`, `reassign_features`, `enrich_hotel_data`, `sync_and_upload_images`, `deduplicate`, `audit_facilities`, the `diagnose_*` checks.

## Novoton's answers (28 Oct 2026)

Novoton answered all ten questions and asked one back, about `room_price`. How far back `offers_update` may look is still open.

| Question | Novoton's answer | What changed |
| --- | --- | --- |
| Limits | No hard quota; stay within 5 requests at the same time and 5 a second; no daily quota | Every request of the store, jobs and customers' searches, passes one shared gate: at most 5 at once and 5 a second, both settings |
| Hours | 01:00–06:00 is fine, but not 03:00–04:00, their busiest hour; small jobs every 5 minutes are fine | No job that calls Novoton starts between 03:00 and 04:00 (the 03:30 calendar rebuild calls nobody). Hotel list moved to 01:30, hotel facilities to Sunday 01:45 |
| Hotel details (`hotelinfo`) | Every 3–7 days: every 3 days while they load the new season (February–May), 7 days otherwise | Two settings, 3 days in February–May and 7 otherwise; each pass starts from 05:00 |
| Season prices (`priceinfo`) | Once a month; in between only for what `offers_update` returns | Only new packages and the ones `offers_update` names; a full pass once a month (new setting, default 30) |
| `offers_update` | Every 12 hours, then `priceinfo` only for its results | 05:00 and 17:00, for every country sold (it asked Bulgaria only). It marks the packages and hotels to fetch again |
| Hotel list | `hotel_list` every 3 days, `resort_list` and `list_facilities` every 7 days. `hotel_list` lists every hotel they have, sold or not; a hotel missing from it is removed for good | As planned. A hotel missing from 3 complete lists in a row (9 days) is reported as having left the list |
| `room_price` | They asked what we call it for | See "Still open with the suppliers" |
| Bookings on request (`resinfo`) | Poll only bookings still ASK, every 15–30 minutes; stop at OK, ST or RQ | Every 15 minutes, ASK only. OK, ST, RQ and WT end the polling; RQ now reaches the alternatives jobs |
| Images | No limit on `hotel_images`; download each image once, refresh the list about weekly, within the same 5 connections | New `refresh_images` job: each hotel's list about once a week (a seventh of the hotels each night at 02:15), through the same gate. Only photos not seen before are downloaded, and a photo already on the product is never added twice |
| Production access | They need our server's IP addresses; we can send them later | Still to send |

## Eurosite's answers (28 Oct 2026)

Eurosite answered seven of the eight questions; production access is still open, and removed hotels got no clear answer.

| Question | Eurosite's answer | What changed |
| --- | --- | --- |
| Limits | No set limits; a connection that looks like abuse is closed. We may keep to Sphinx's limits | Scheduled runs: one request at a time, at most 10 price searches a minute, other requests at most once a second, both settings. Customers' searches are not held back |
| Hours | 01:00–06:00 is fine | No change |
| Catalogs | Once a month is enough for this static data | Countries, own cities, cities, room types and tags on the 1st of each month |
| Hotels | Weekly: new hotels do not appear daily. No clear answer on how to learn a hotel was removed | `getOwnHotelsRequest` on Sundays. A hotel missing from 3 complete answers for its city in a row (3 weeks) is reported as having left the list |
| Hotel details | Keeping them 30 days is fine | No change |
| Availability | As often as needed | Nightly, now at 04:15 |
| Booking status | 30 minutes after booking, then every 30 minutes for 2 hours; if still not final, wait 8 hours, then every 30 minutes for 2 hours | New `booking_status` job, every 15 minutes, on that schedule. A new booking stays pending until Eurosite confirms it; it was saved as confirmed |
| Production access | No answer | Still open (see below) |

## Sphinx's answers (28 Oct 2026)

Sphinx answered all five questions. Nothing in its table changes yet: the limit and the calendar-price request are being confirmed (see "Still open with the suppliers").

| Question | Sphinx's answer | What follows |
| --- | --- | --- |
| Limits | Static data: no limits. Other requests: 10 a minute | To confirm: the documentation gives 10 a minute for `/api/v1/ping` only, and one customer search polls the results up to 20 times a minute. No pacing added until they answer |
| Removed hotels | No signal: hotels with an immediate or on-request offer are available; the others are unavailable for now, or removed | No change: the weekly full list stays, and the availability check reads the searches |
| Live search for availability and meal plans | Allowed | No change |
| Calendar prices | Use the cache (hotel offers, package offers), built from every API user's live searches | We already call `POST /api/v1/cache/hotels`. To confirm: our request names its fields differently from the documentation, and one request per destination could cover all 60 days |
| Production account | Both the API account and the store's are connected to suppliers | The availability check should find offers in production; its first nights will show it |

### Already answered by Sphinx's API documentation

The [Sphinx API documentation](Sphinx%20API.txt), checked 26 Oct 2026, answers four of the ten questions in full and two in part, so we asked Sphinx only what it leaves open.

| Question | What the documentation says | What we do |
| --- | --- | --- |
| Hours | Run scheduled static-data calls in low-load periods, "usually during night time". | Bulk jobs between 01:00 and 07:00 |
| Limits (in part) | No numbers except `/api/v1/ping` (10 a minute); the API handles "hundreds of parallel requests". After HTTP 429, wait `Retry-After` seconds or until `X-RateLimit-Reset`. | Our client waits as told after a 429, and pauses when `X-RateLimit-Remaining` is 2 or less |
| Static data (in part) | Retrieve destinations, hotels, circuits and experiences every night; with `updated_since`, as often as every hour. Package routes every night. Nothing on how removed hotels are signalled. | Destinations weekly, hotels nightly plus the whole list on Sundays, circuits and experiences daily, package routes weekly |
| Circuits, experiences and package routes | The same: a nightly job, `updated_since` for circuits and experiences. | As above |
| Bookings | No notifications are documented. Orders are read with `GET /api/v1/orders` (newest first, up to 50 a page, `created_after` filter) or by id; a booking still `processing` is checked again after 20 s. | Open orders checked every 15 minutes |
| Images | Cache images locally. Originals without the supplier's watermark need `watermark=0` and the `X-Copyright-Authorization` header. No download limit is given. | Originals downloaded that way, up to 50 every 10 minutes |

The documentation also settles how to poll search results: ask for the next page as soon as a cursor arrives, keep going through empty pages, stop at `cursor: null`. Our availability check polls every 3 s until then, for at most 60 s.

## Still open with the suppliers

As of 4 November 2026. When an answer arrives, add it to that supplier's answers above, and change the schedule if it asks for it.

**Novoton**

- **Why we call `room_price`** (their question). For the weekly availability check: once a week per resort, whether hotels can be booked on future dates without waiting for a customer to search (stays 14 and 30 days ahead, and on 20 June, 15 July, 15 August and 5 September). We send the resort-level request (one call per resort and date, empty `IdHotel`), one at a time: about 60 a night between 04:00 and 04:20, never more than 120. Customers' bookings still go through `room_price` and `hotel_quota` when they search.
- **How far back `offers_update` may look.** We call it at 05:00 and 17:00, each time since our last answered call; after an outage that can be several days back. Is there a limit on how far back `DateTime` may go?
- **Production access.** Novoton needs the production server's IP addresses.

**Eurosite**

- **Production access.** Do they need our server's IP address, or anything else, before we connect from production?
- **Removed hotels.** `getOwnHotelsRequest` only adds and updates hotels. We treat a hotel as removed when three complete weekly answers for its city in a row no longer list it; is there a better signal?
- **Our pace.** Our scheduled jobs send one request at a time, at most 10 `getHotelPriceRequest` a minute and other requests at most once a second. Eurosite can ask for a different pace.

**Sphinx**

- **The limit of 10 requests a minute.** Their documentation gives it for `/api/v1/ping` only and says the other endpoints have much higher limits. Does it also apply to `POST /api/v1/hotels/search`, `GET /api/v1/hotels/results` and `POST /api/v1/cache/hotels`? One customer search polls the results every 3 seconds until the cursor is null, so it can make up to 20 requests in a minute.
- **The calendar-price request.** We call `POST /api/v1/cache/hotels` with `destination_id`, `check_in`, `check_out`, `occupancy` as a list of `{adults, children_ages}`, and `currency`. Their documentation lists `destination_ids`, `nights`, `occupancy` as `['a,a']`, `check_in_after`, `check_in_before` and pagination. Which shape should we send? And may we ask for one destination over the next 60 days in one paged request, instead of one request per day?
