# Roadmap

## v1: detect and report (in progress)

Read-only. It never changes a log, a Docker setting or a file outside its own settings.

- [x] Signature engine: strips times, ids, addresses and numbers so repeats compare equal
- [x] Parsers for PHP, syslog, Docker json-file and plain logs, with attribution (plugin, program, container)
- [x] Incremental tailing that copes with rotation, truncation, half-written lines and flooding logs
- [x] Detector: repetition count plus sustained rate or share of its own log; `noisy` and `flooding`
- [x] Incident grouping for multi-line events
- [x] Docker log size, growth per hour, and whether anything limits it
- [x] Per-source switches, mute list, thresholds (in the settings file)
- [x] `scripts/scan` and `scripts/report`, and a test suite built on real log lines
- [x] Tools page (Tools → System Information): incidents by level with details and mute, Docker log table, per-source switches, sensitivity, muted list
- [x] Scan frequency chosen in words (off, 5/10/15/30 minutes, hourly, every 3/6/12 hours, daily at a time), written to the plugin's cron file; default every 15 minutes
- [x] Plugin package and `.plg` (`build.sh`): installs the schedule from the saved settings on every install and boot, removes the cron file and package records on uninstall, keeps your settings
- [x] Tested through Unraid's own plugin manager: install, upgrade, uninstall, reinstall, and a scheduled scan started by cron
- [x] GitHub repository and release
- [ ] Community Apps listing
- [ ] Optional Unraid notification when something starts flooding
- [ ] Dashboard tile with the current incident count

## v2: suggested fixes

Still changes nothing. Beside each finding it shows the fix as text you can copy.

- [ ] Docker: the `log-opts` snippet (`max-size` / `max-file`) for the daemon or for that one container
- [ ] A `logrotate` rule for a log that has none
- [ ] Who to tell: the plugin's support link for a PHP or plugin-log incident, the container's template for a crash loop
- [ ] "Explain this": what a signature usually means, from a small built-in list of common ones

## v3: one-click actions

Buttons that change things. Every one needs a confirmation that shows exactly what will happen, and a way back where one exists.

- [ ] Truncate a Docker container's log
- [ ] Rotate a log file now
- [ ] Apply a Docker `max-size` / `max-file` limit

## Later, maybe

- More sources: `dmesg`, the Unraid API log, VM logs, User Scripts logs
- A short history so you can see when a log started spamming
- A link from an incident to the matching view in Logs Viewer
