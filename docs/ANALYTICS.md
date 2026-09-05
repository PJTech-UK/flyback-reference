# Counting visitors without tracking them

The site sets no cookies, stores nothing in the browser and loads no third-party
scripts, and the footer says so. Any analytics has to keep that true, which rules
out every hosted JavaScript tracker including the "privacy-friendly" ones — they
still execute someone else's code in the visitor's browser and still send them
the visitor's IP and referrer.

The web server already records every request. Reading its own logs is the only
approach that adds nothing to the page, and it is what this document sets up.

## What you get

- Unique visitors per day, week and month (approximate — see the caveat below)
- Country of origin
- Requested pages and searched URLs
- Referrers
- Browsers and operating systems
- Crawlers separated from people, and named individually

## What is stored

Nothing beyond what the web server logs, and less than it would log by default:
the configuration below truncates IP addresses before they are written. A
visitor's address never reaches disk in full, so there is no personal data to
leak, hand over, or forget to delete.

On Forge this starts from a stronger position than most hosts, because Forge
disables access logging entirely — the site currently records nothing at all
about requests. Everything below is a deliberate step towards logging *less* than
a normal server would, not towards logging where nothing was logged before.

## 1. Turn access logging on — Forge switches it off

Forge's generated site config contains:

```nginx
access_log off;
error_log  /var/log/nginx/<your-site>-error.log error;
```

so there is nothing to analyse until you change it. Errors are logged; requests
are not.

**You do not need the global nginx config**, which Forge no longer exposes. Its
per-site editor (*Site → Nginx Configuration*) writes
`/etc/nginx/sites-available/<your-site>`, and that file is `include`d from
`nginx.conf`'s `http { }` block — so a `map` and a `log_format` placed above the
`server { }` block in it are already in the only context those directives are
valid in.

`deploy/nginx-logging.conf` in this repository is the block to paste, with the
reasoning in comments. Two changes:

**a. Add the anonymising format above the `server { }` block.** The site file is
included inside nginx's `http` context, so a `map` at the top of it is valid
there — it will not work inside `server { }`.

```nginx
# Zero the last octet of IPv4; keep only the first block of IPv6.
map $remote_addr $ip_anon {
    ~(?P<a>\d+\.\d+\.\d+)\.     "$a.0";
    ~(?P<a>[^:]+:[^:]+):        "$a::";
    default                     "0.0.0.0";
}

log_format privacy '$ip_anon - - [$time_local] "$request" $status $body_bytes_sent '
                   '"$http_referer" "$http_user_agent"';
```

**b. Replace `access_log off;` inside `server { }`:**

```nginx
access_log <site-home>/logs/access.log privacy;

# Images are most of the requests and none of the interest.
location /data/ {
    access_log off;
    expires 30d;
}
```

```bash
sudo deploy/setup-logging.sh <site-user> <site-home>
```

`/var/log/nginx` is root-owned, so every later step would need `sudo`. Putting
the log in the site's own directory means the nightly report runs as the site's
own user with no root at all.

nginx's master process runs as root and opens the log files named in its
configuration before workers drop privileges, so this works under Forge's site
isolation even though nginx's workers are not the isolated user. The one catch is
that a file nginx creates is owned by root and the site user then cannot read it,
so create it first with the right owner — `deploy/setup-logging.sh` does that,
installs the rotation config and prints the nginx block to paste.

**A log outside `/var/log/nginx` is rotated by nothing.** Ubuntu's stock
`/etc/logrotate.d/nginx` matches `/var/log/nginx/*.log` and nothing else, and an
unrotated access log plus an announcement-day traffic spike is how a small server
fills its disk. `deploy/logrotate.conf` is the rotation config; install it as
root:

```bash
sudo cp deploy/logrotate.conf /etc/logrotate.d/<your-site>
sudo sed -i 's/SITE/<your-site>/g' /etc/logrotate.d/<your-site>
sudo logrotate -d /etc/logrotate.d/<your-site>    # dry run — read what it says
```

Check a line arrives with a zeroed final octet before going further:

```bash
tail -n 3 <site-home>/logs/access.log
# 203.0.113.0 - - [05/Sep/2026:14:02:11 +0000] "GET /?q=BSC24 HTTP/1.1" 200 5036 "-" "Mozilla/5.0 …"
```

If the site is behind Cloudflare, `$remote_addr` is Cloudflare's edge and every
visitor collapses into a handful of addresses. Use `$http_cf_connecting_ip` in
the `map` instead — and note that Cloudflare then holds the real addresses
whatever you log, and is counting on its own terms in its own dashboard.

### Rotation and history

Rotation is what makes a naive `goaccess access.log` misleading: every night the
file is truncated, so the report only ever covers today. Two ways round it, and
you want the second:

```bash
# Reads the current log and the most recent rotated one. Still loses week-old data.
zcat -f <site-home>/logs/access.log.1* <site-home>/logs/access.log | goaccess -
```

```bash
# Keeps a running database, so history survives rotation. This is the one to use.
goaccess <site-home>/logs/access.log \
  --persist --restore --db-path=<site-home>/goaccess-db \
  ... other options as below ...
```

`--persist` writes what it parsed, `--restore` reads it back, and GoAccess skips
lines it has already counted. Run it once a day, before logrotate does its work —
`logrotate` runs from `/etc/cron.daily` at about 06:25, so a cron entry at 05:00
is safe.

## 2. Report on it

[GoAccess](https://goaccess.io) reads the log and writes a self-contained HTML
report. No database, no daemon, no JavaScript on your site.

```bash
sudo apt-get install goaccess
```

Country lookup needs a GeoIP database. MaxMind's GeoLite2 Country is free with a
registration; put the `.mmdb` somewhere readable and reference it.

```bash
goaccess <site-home>/logs/access.log \
  --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u"' \
  --date-format=%d/%b/%Y --time-format=%H:%M:%S \
  --geoip-database=/usr/share/GeoIP/GeoLite2-Country.mmdb \
  --ignore-crawlers=false \
  --persist --restore --db-path=<site-home>/goaccess-db \
  -o <site-home>/stats.html
```

`--log-format` matches the `privacy` format field for field; change one and you
must change the other.

As a cron entry, in the site user's own crontab (`crontab -e` as that user —
under site isolation that is the per-site account, not `forge`). No root needed:

```cron
0 5 * * * /usr/bin/goaccess <site-home>/logs/access.log --log-format='%h - - [%d:%t %^] "%r" %s %b "%R" "%u"' --date-format=%d/%b/%Y --time-format=%H:%M:%S --geoip-database=/usr/share/GeoIP/GeoLite2-Country.mmdb --ignore-crawlers=false --persist --restore --db-path=<site-home>/goaccess-db -o <site-home>/stats.html
```

**Do not write the report into `public/`.** It would be on the open web, and it
lists your referrers, your traffic, your 404s and every search anyone ran.
`<site-home>/stats.html` is outside the document root: fetch it with
`scp <site-user>@<host>:<site-home>/stats.html .` and open it locally.

`--ignore-crawlers=false` keeps bots in the report and lists them separately,
which is the split you want. GoAccess knows the search engines; the AI crawlers
are newer, and the ones that matter currently identify themselves as `GPTBot`,
`ClaudeBot`, `anthropic-ai`, `CCBot`, `PerplexityBot`, `Google-Extended`,
`Applebot-Extended`, `Bytespider` and `Amazonbot`. Grep the log for those to see
what is arriving:

```bash
grep -ohiE 'GPTBot|ClaudeBot|anthropic-ai|CCBot|PerplexityBot|Google-Extended|Applebot-Extended|Bytespider|Amazonbot' \
  <site-home>/logs/access.log | sort | uniq -c | sort -rn
```

## The caveat about "unique visitors"

GoAccess counts a unique visitor as a distinct IP + user-agent + date. Truncating
the last octet collapses everyone behind the same /24 into one, so the figure
undercounts — noticeably on mobile networks and corporate ranges. It is a
consistent undercount, so trends and relative numbers stay meaningful; treat the
absolute figure as a floor.

That is the trade. The alternative is storing full IP addresses, which is
tracking with extra steps.

## If a CDN is in front

Cloudflare and the like replace `$remote_addr` with their own address. Use
`$http_cf_connecting_ip` in the `map` instead, and remember the CDN then holds
the real addresses regardless of what you log — its own analytics will also be
counting, on its own terms.

## What was deliberately not done

- **No JavaScript beacon**, first-party or otherwise. It would break the footer's
  claim, it misses everyone with a blocker, and it cannot see crawlers at all.
- **No in-application counter.** The site is read-only by design: the SQLite
  connection is opened `PRAGMA query_only`, and nothing under `public/` or `src/`
  writes to disk. Adding a hit counter would give the web process a reason to
  write, which is a security property worth more than a page-view tally.
