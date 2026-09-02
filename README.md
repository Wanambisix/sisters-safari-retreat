# Sisters' Safari Retreat — Booking & Inquiry Site

Single-tour booking landing page for **Fit Muslimah Sisters' Safari Retreat**
(10 Day Bush & Beach Safari Kenya, 1st–10th April 2027), served at
https://sisters.halalsafarioperator.com.

Plain PHP + HTML, no framework, no database. Payments are processed by **Pesapal**
(USD, deposit at booking); email notifications are sent over Gmail SMTP via a
dependency-free raw-socket PHP implementation.

## Files

| File | Purpose |
| --- | --- |
| `index.html` | Landing page: itinerary, pricing, booking form, inquiry form, galleries |
| `booking.php` | Booking endpoint: validates, charges the $500 deposit via Pesapal V3, verifies callback/IPN, emails admin + guest |
| `inquiry.php` | Inquiry form handler (emails admin via Gmail SMTP) |
| `pesapal_ipn.json` | Registered Pesapal IPN id (auto-created/updated at runtime) |
| `images/` | All site images, served locally (no external hotlinks) |

## Pricing (April 2027 departure)

- Twin / Double sharing: **$500 downpayment + 5 monthly instalments of $600** (full **$3,500**)
- Single occupancy: **$500 downpayment + 5 monthly instalments of $680** (full **$3,900**)

Checkout currently collects only the $500 downpayment via Pesapal; the monthly
instalments are billed manually (auto-charge was intentionally not implemented).

## IMPORTANT — secrets are NOT in this repository

Live credentials were scrubbed from this repo and replaced with placeholders:
`YOUR_PESAPAL_CONSUMER_KEY`, `YOUR_PESAPAL_CONSUMER_SECRET`,
`YOUR_GMAIL_APP_PASSWORD`.

Before deploying this folder to a server you MUST restore the real values in
`booking.php` and `inquiry.php` (copy them back from your local backup folder,
e.g. `Downloads\booking.php-LIVE-KEYS\`, or from the current live files).
`test-smtp.php` is intentionally excluded — it contains credentials and should
never be uploaded.

## Deploy

Upload the whole folder (including `images/`) to the web root that serves
`sisters.halalsafarioperator.com`. Point the booking form at `booking.php` and
set `$baseUrl` in `booking.php` to the live base URL (IPN/callback depend on it).
