# Log Health for Unraid

Finds the logs that are spamming, and tells you who is doing it.

Unraid keeps logs in several places, and any of them can quietly fill up with one message repeated thousands of times: a plugin throwing a PHP warning on every page load, a container stuck in a crash loop, a service retrying a connection that will never work. You usually find out late, when a log is hundreds of megabytes or when `/var/log` (which lives in RAM) starts to fill.

Log Health reads your logs on a schedule, works out which lines are the same message repeating, and lists the ones that repeat too often, each named after the **plugin, program or container** behind it. It also shows how big every Docker container's log is, how fast it is growing, and whether anything limits it.

It only reads. It never changes a log, a Docker setting or a file outside its own settings.

> **Status: first release.** Works on Unraid 7.2 and newer. It has been installed, upgraded, uninstalled and reinstalled through Unraid's plugin manager, and its scheduled scan has run from Unraid's cron. Please open an issue if anything behaves oddly.

## Contents

- [What you get](#what-you-get)
- [Install](#install)
- [Using it](#using-it)
- [Reading a finding](#reading-a-finding)
- [Settings](#settings)
- [How it decides](#how-it-decides)
- [Safety and privacy](#safety-and-privacy)
- [Troubleshooting](#troubleshooting)
- [Questions](#questions)
- [Limits](#limits)
- [Uninstall](#uninstall)
- [Development](#development)

## What you get

- **A ranked list of spamming messages.** Each one says who is behind it (for a PHP warning, the plugin plus the file and line), how many lines, how fast, how much of its own log it fills, and whether it is still happening.
- **One entry per problem.** A crash loop that prints a 40-line traceback every few seconds is one finding, not forty.
- **Docker log health.** For each container: size, growth per hour, the limit it rotates at, and how full its first log file is.
- **Mute.** Say "I know about this" and it stops being listed. Unmute it any time.
- **A schedule you choose in words.** Every 5, 10, 15 or 30 minutes, every hour, every 3, 6 or 12 hours, once a day at a time you pick, or off. The default is every 15 minutes. The plugin writes the cron entry for you.
- **Per-source switches.** PHP, the system log, Docker container logs (with or without reading inside them) and other plugin logs can each be turned on or off.

## Install

**From Community Apps:** search for *Log Health* (once it is listed there).

**By hand:** Plugins → Install Plugin, and paste

```
https://raw.githubusercontent.com/joshmans/unraid-log-health/main/unraid-log-health.plg
```

The install runs a first scan in the background, so the page has something to show within about a minute. It then appears under **Tools → System Information → Log Health**. If you use [Menu Manager](https://github.com/joshmans/unraid-menumanager) you can move it wherever you like.

## Using it

Open **Tools → System Information → Log Health**.

The top of the page shows when the last scan ran, how many new lines it read, and when the next one is due. **Scan now** starts a scan straight away; the page updates when it finishes.

Under that are the findings, worst first, then your Docker logs, then anything you have muted, then **Settings**.

For each finding:

- **Details** shows an example of the actual log line, where in the code it came from, which log file it was in, and, for a multi-line incident, the other lines that belong to it.
- **Mute** hides it. It is listed under *Muted* below, where **Unmute** brings it back. An unmuted message shows again at the next scan, but only if it is still spamming.

## Reading a finding

Findings come in three levels, which depend on how fast a message repeats:

| Level | Means | Typical cause |
|---|---|---|
| **Flooding** | About a line a second or faster | A crash loop, a retry loop, debug logging left on. This is what fills logs and disks |
| **Noisy** | Repeating steadily, at least 5 a minute | A service logging every request, a warning on every page load |
| **Chatty** | Slow, but most of a small log | A healthcheck or access-log line every few seconds. Usually harmless; this level is collapsed by default |

Findings that are still happening are listed above ones that have stopped. A finding that has stopped says how long ago, and drops off the list after 24 hours without being seen again.

The **who** on each card is the plugin (from the file that raised a PHP warning), the program (system log), the container (Docker) or the log file (other logs). A PHP warning also shows the file and line, which is usually all you need to report it to the plugin's author.

## Settings

Everything below is on the page, under **Settings**, and saved with **Save settings**. A save is checked as a whole: if any value is out of range nothing is changed and the page says why.

| Setting | Default | What it does |
|---|---|---|
| Scan | Every 15 minutes | How often the scan runs. See below |
| Time of day | 03:00 | Only for *Once a day*. Uses the server's clock, the same one cron uses |
| PHP errors | on | Reads the PHP log and nginx's error log |
| System log | on | Reads syslog |
| Docker container logs | on | Watches every container's log size, growth and limit |
| … read what is inside them | on | Also looks for repeating lines inside each container's log. Turn off to watch sizes only, which is cheaper |
| Other plugin logs | on | Reads other `/var/log/*.log` files |
| Minimum repeats | 50 | A message must repeat this many times in a scan before it can be flagged |
| Noisy at | 5 per minute | Repeating this fast or faster is *noisy* |
| Flooding at | 60 per minute | Repeating this fast or faster is *flooding* (60 is about one line a second) |
| Chatty at | 20 % of its log | A slow message making up this much of its log is *chatty* |
| Smallest log that counts for share | 200 lines | Share only counts in logs of at least this size |
| Docker log is large / huge at | 100 MB / 500 MB | Where a container's log is marked *large* or *huge* |

**Choosing how often to scan.** A scan only reads what your logs gained since the last one, so it is cheap: on my server (123 logs) one that found nothing new took about a seventh of a second. The interval is really about how quickly you want to hear of a flood. Every 15 minutes finds one within a quarter of an hour. A message counts as *still happening* for twice the scan interval (at least 30 minutes), so on a slow schedule things stay listed as current for longer. Schedules of 15 minutes or longer are offset by 3 minutes (for example :03, :18, :33 and :48) so they don't run on the hour, where every other cron job on the server piles up.

The first scan after installing (and after each boot) reads more than later ones, up to the last 2 MB of each log, and can take up to a minute on a server with many containers.

## How it decides

A message is reduced to a **signature** by taking out everything that changes between repeats: times, IDs, IP addresses, device letters, numbers. So `Failed publickey for root from 203.0.113.9 port 51022` and the same message from another address and port count as one message. Quoted text is kept, so `Undefined array key "Title"` and `Undefined array key "Icon"` stay separate.

A signature is flagged when it repeats at least the minimum number of times **and** either fast (a sustained rate) or as a large share of its own log. Share is measured against the log the line came from, not all logs together, so a small log dominated by one message is caught. Which level it gets depends only on the rate; see the table above.

Signatures from one log that repeat almost exactly the same number of times are grouped into one incident and shown as its most informative line (one that names an error or exception), with a count of related lines. This is a heuristic on counts. If two unrelated messages happen to repeat the same number of times in one log they will appear together.

[ARCHITECTURE.md](ARCHITECTURE.md) has the details.

## Safety and privacy

- **It reads and reports; it does not change anything.** No log is truncated or rotated, no Docker setting is touched, no container is stopped.
- **What it writes:** two small files on the flash drive, and only when their content changes: `/boot/config/plugins/unraid-log-health/config.json` (your settings and muted messages) and `unraid-log-health.cron` (the schedule). Everything a scan produces lives in RAM under `/tmp/unraid-log-health`, so scanning never writes to the flash drive.
- **What it reads:** the logs listed under Settings, and for Docker the container config files in `/var/lib/docker/containers` (it never runs the `docker` command). It remembers how far it has read in each file, so it reads only what was added.
- **Nothing leaves your server.** It makes no network connections.
- **The page shows real log lines.** Example lines can contain host names, addresses or whatever else a program logs. The page is behind Unraid's login like the rest of the webGUI, but keep that in mind before sharing a screenshot of it.
- **Changes from the page are protected.** Saving, muting and scanning are POST-only and carry Unraid's CSRF token. The settings form accepts only known fields within fixed ranges, and log file paths cannot be set from it.
- **Log text is never rendered as HTML.** A container can print anything; the page shows it as plain text.

## Troubleshooting

**"No scan has run yet."** The first scan starts at install and takes up to a minute. Press **Scan now** and wait a moment. If it never completes, run `php -q /usr/local/emhttp/plugins/unraid-log-health/scripts/scan` from a terminal and look for an error.

**The next scan time looks wrong.** Times on the page are in the server's timezone, which is the one cron uses, not your browser's.

**Scans don't seem to run on schedule.** Check the entry is in the live crontab:

```
crontab -c /etc/cron.d -l | grep log-health
```

Unraid only builds that from plugins that are registered, so the entry appears once the plugin is installed through the plugin manager. If Scan is set to *Off* there is no entry, on purpose.

**Everything is "chatty".** That means the logs are dominated by slow, regular messages (healthchecks and access logs) and nothing is repeating fast. Lower *Noisy at* if you want to see slower repeats.

**Something I don't care about keeps showing.** Mute it. If the same message keeps coming back in a slightly different form, it is a different signature; mute that too.

**A text report from a terminal:**

```
php -q /usr/local/emhttp/plugins/unraid-log-health/scripts/report
```

## Questions

**Does it change or clean up my logs?** No. Suggested fixes and one-click actions are on the [roadmap](ROADMAP.md), and would be separate, confirmed steps. Today it only reports.

**How is this different from Logs Viewer?** [Logs Viewer](https://github.com/Lazaros-Chalkidis/unraid-logsviewer) is a good log viewer that alerts on patterns you write. Log Health needs no patterns: it finds spam by how often and how fast a message repeats, and names who is behind it. They fit well side by side.

**Why is a Docker log with a limit still flagged as large?** Unraid gives containers `max-size=1g, max-file=3` by default, which means a runaway log can reach 3 GB before old data is dropped. A limit is not the same as a small log, so the page shows the limit and how full the first file is.

**Can it watch a log file of mine?** Other `/var/log/*.log` files are read automatically. Custom paths are not offered on the page.

**Will it slow my server down?** A scan that finds nothing new is a fraction of a second. The first scan reads more (up to 2 MB per log) and takes longer on a server with many containers.

**Does it need Docker?** No. With no containers, or with Docker switched off in Settings, the Docker section is simply empty.

## Limits

- **Docker logs:** only the default `json-file` log driver is read. Containers using another driver are not scanned.
- **Sampling:** if a log gains more than 8 MB between scans, only the most recent 8 MB is read, and the finding is still raised. A very fast flood is counted from a sample, not every line.
- **Recent lines only:** a scan ignores lines older than 24 hours, and a finding disappears after 24 hours without being seen. It is a current-health view, not a history.
- **Signatures are text based.** A message whose wording changes every time is not recognised as a repeat.
- **Grouping is a heuristic** (see above).

## Uninstall

Remove it from Plugins as usual. That stops any running scan, removes the schedule from cron, and deletes the plugin's files and its RAM data. Your `config.json` (schedule, switches and muted messages) is kept on the flash drive so a reinstall picks up where you left off; delete `/boot/config/plugins/unraid-log-health` if you want it gone too.

## Development

The engine needs only `php-cli`, and the page needs no build step.

```
tests/run.sh                    # the test suite: 8 suites, using real (sanitized) log lines
tests/harness/serve.sh          # the page on http://127.0.0.1:8766 against a throwaway fixture, for trying layout changes
./build.sh 2026.09.21b          # packages/unraid-log-health-2026.09.21b.txz, and stamps unraid-log-health.plg with its SHA-256 and CHANGELOG.md
```

The version is a date; a second build on the same day gets a letter (`2026.09.21b`). Unraid ranks a lettered version above the plain one, so a lettered test version must be uninstalled before releasing a lower one. Rebuild right before a release: the `.plg` carries the hash of the exact `.txz` attached to the GitHub release whose tag equals the version.

From a checkout, `scripts/scan` and `scripts/report` run the engine directly. `LH_CONFIG` and `LH_STATE_DIR` point them at other files, which is how the tests work:

```
php source/usr/local/emhttp/plugins/unraid-log-health/scripts/scan
php source/usr/local/emhttp/plugins/unraid-log-health/scripts/report
```

| File | Role |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the engine, the schedule and the page work |
| [ROADMAP.md](ROADMAP.md) | What is planned: suggested fixes, then one-click actions |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

Bug reports and ideas are welcome as [issues](https://github.com/joshmans/unraid-log-health/issues).

## License

[MIT](LICENSE)
