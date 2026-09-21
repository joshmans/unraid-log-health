# How it works

## The pipeline

```
log file ──tail──▶ lines ──parse──▶ (time, message, who, where) ──normalize──▶ signature ──count──▶ flag ──group──▶ incidents
```

`scripts/scan` runs the whole thing once and writes `state.json`; `scripts/report` and (later) the Tools page read it.

| File | Role |
|---|---|
| `include/signature.php` | `lh_normalize()` turns a message into a signature; `lh_sig_id()` gives a (source, signature) pair a short stable id, which is what the mute list stores |
| `include/parsers.php` | One parser per format (PHP, syslog, Docker json-file, plain) and `lh_attribute_path()` which names the plugin behind a file path |
| `include/tail.php` | `lh_tail()` reads only what a file has gained since last time, keyed by inode and offset |
| `include/detect.php` | `lh_flag()` decides what is spam, `lh_group()` folds multi-line events into incidents, plus growth and size helpers |
| `include/docker.php` | Reads container names, log sizes and log limits from Docker's own config files; never calls `docker` |
| `include/config.php` | Defaults, and the settings file laid over them |
| `include/scan.php` | `lh_scan()` ties the above together and keeps the 24-hour history |
| `include/schedule.php` | The scan frequencies in words, turned into a cron line; when the next scan is; writing the plugin's cron file |
| `include/api.php` | The JSON endpoint behind the page: `state`, `save`, `scan`, `mute`, `unmute` |
| `include/page.php`, `LogHealth.page`, `js/health.js`, `css/health.css` | The Tools page |
| `scripts/scan`, `scripts/report` | The cron entry (one scan at a time, under a lock) and a text report |

## Signatures

Rules run most specific first: colour codes, UUIDs, ISO and clock times, dates, IPv4 and IPv6, MACs, device names (`/dev/sdd` and `/dev/sdaf` are the same), SSH key hashes, hex ids, then numbers and versions. Words that merely contain digits (`sha256`, `ssh2`) are left alone. Quoted strings are kept, so `Undefined array key "Title"` and `"Icon"` stay separate.

For PHP the file and line are added to the signature, because *where* the warning comes from is the answer to *who is spamming*.

## What counts as spam

A signature is flagged when `count >= min_count` **and** either `rate/min >= min_rate` or (`share >= share_pct` of its own log, in a log of at least `min_total` lines). Share is measured against the log the line came from, not all logs together, so a small log dominated by one message is caught.

The **level** says how much it matters and depends on rate alone:

| Level | When | Meant for |
|---|---|---|
| `flooding` | `rate >= flood_rate` (60/min, about one a second) | what actually fills logs and disks |
| `noisy` | `rate >= min_rate` (5/min) | worth a look |
| `chatty` | flagged only for its share, at a slow rate | healthchecks and access logs: most of a small log, but harmless. Shown collapsed |

Defaults: 50 lines, 5/min, 20 %, 200 lines, 60/min. All are in the settings file. This tiering came out of running the first version on a real box with 111 containers: measuring "flooding" by share reported 58 incidents, mostly a healthcheck line every 30 seconds.

Results are ordered still-happening first (a signature whose last line is within `active_minutes` of the scan), then by level, then by size.

## Incidents

One traceback, or one dumped error object, prints many different lines that each repeat the same number of times, so each would be flagged on its own. `lh_group()` groups signatures from the same log whose totals are within 1 % (at least 2) of each other and shows the most informative line (one that names an error or exception) with a "+N related" count. This is a heuristic on counts: the members stay individually addressable, and muting a group mutes every member. If two unrelated messages repeat the same number of times in one log they will be shown together.

## Not reporting history

A first scan reads only the last 2 MB of each file (8 MB per run at most, after which it samples from the end and says so), and ignores lines older than 24 hours. Without that, the tail of a huge log would report a flood from last week as current. A flagged signature stays listed for 24 hours after it was last seen, with a "quiet" age once it has stopped.

## Where things live

- Settings: `/boot/config/plugins/unraid-log-health/config.json`, written only when the user changes something.
- Everything a scan produces: `/tmp/unraid-log-health/state.json`, in RAM, so scans never write to the flash drive.
- `LH_CONFIG` and `LH_STATE_DIR` override both, for tests and dry runs.

## Docker

Each container's log is reported with its size, growth per hour, its **limit** (the container's own `max-size` × `max-file`, falling back to the daemon default) and how full the first file is. Unraid gives every container `max-size=1g, max-file=3` by default, so a runaway log can reach 3 GB before old data is dropped: "limited" is not the same as "small", which is why the report shows the number and not just a flag.

Sizes and limits come from `/var/lib/docker/containers/<id>/` (`config.v2.json` for the name, `hostconfig.json` for a per-container `max-size`) and `/etc/docker/daemon.json` for the daemon default. Reading the content of a container's log is switchable separately from watching its size, because it is the more expensive of the two.

## Tests

`tests/run.sh` needs only `php-cli`. The parser tests run against real, sanitized lines from an Unraid 7.4 box (`tests/fixtures/`), and the scan tests build small logs and a fake Docker root in a temp directory.

## Scheduling

The user picks a frequency in words; `lh_schedules()` maps each to a cron expression. Unraid's `update_cron` builds the crontab from `/boot/config/plugins/<plugin>/*.cron` for every plugin registered in `/var/log/plugins`, so saving a schedule means writing (or deleting) `unraid-log-health.cron` on flash and running `update_cron`. Flash is only written when the line changes, and it survives reboots.

The default is **every 15 minutes**: a scan that finds nothing new took 0.15 s on a box with 111 containers, so the interval is about how quickly you want to hear about a flood, not about load. It also keeps "still happening" meaningful: a signature counts as active for twice the scan interval (at least 30 minutes), so one missed scan does not flip everything to "stopped". Slower schedules run at minute 3, not :00, where every other cron job on the box piles up. Times shown on the page are formatted by the server, in the timezone cron runs in.

## The page and its API

Every action that changes anything is POST-only and carries Unraid's `csrf_token` in the form body, so `local_prepend.php` checks it before `api.php` runs (this works on every Unraid version; a JSON body would need the newer `X-CSRF-Token` header). `save` validates every field against a whitelist and ranges and rejects the whole save if any is bad; file paths cannot be set from the form. `mute` only accepts ids that were actually flagged. Scan now starts a background scan and returns at once, and the page polls until it finishes. Everything from a log is shown with `textContent`, never as HTML.
