# Method Machine Studio — website

Static site plus one PHP form handler, built for Hostinger (or any Apache + PHP host).

| File | Purpose |
| --- | --- |
| `index.html` | Landing page with waitlist forms |
| `apply.html` | Stage 1 application / assessment prototype |
| `404.html` | Not-found page (wired up in `.htaccess`) |
| `submit.php` | Receives waitlist signups and application intake |
| `config.example.php` | Template for the private `config.php` |
| `.htaccess` | 404 page, blocks access to config and data files |
| `favicon.svg`, `site.webmanifest` | Icon and install metadata |

## Deploy to Hostinger

1. Upload everything in this repo to `public_html/` (hPanel → File Manager, or Git deploy).
2. Copy `config.example.php` to `config.php` in the same folder and set:
   - `notify_email` — where new signups/applications are emailed.
   - `from_email` — a mailbox on your domain (create it in hPanel → Emails) so mail isn't marked as spam.
3. Visit your site, join the waitlist, and check that you get the email.

## Where submissions go

`submit.php` appends each submission to a CSV in `mms-data/`, a folder created **next to**
`public_html` (outside the web root, so it can't be downloaded):

- `mms-data/waitlist.csv` — `submitted_at, email, page, ip, user_agent`
- `mms-data/applications.csv` — intake fields, mode, items answered, focus events, and raw answers

Download them from File Manager. To store them elsewhere, set `data_dir` in `config.php`.

The handler validates the email, ignores bots via a hidden honeypot field, rate-limits to
10 submissions per IP per 10 minutes, and escapes values that spreadsheets would treat as formulas.

## Test locally

```sh
cp config.example.php config.php   # set data_dir to a local folder, leave notify_email empty
php -S 127.0.0.1:8000
```

Then open http://127.0.0.1:8000/.

The claude.ai preview of this page has no PHP, so the forms there report that the server
could not be reached. That is expected.
