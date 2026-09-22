# Log Health

## 2026.09.21b

First release.

- Finds the logs that are spamming and tells you who is doing it, in **Tools > System Information > Log Health**. Each finding is named after the plugin (with file and line), program or container behind it.
- Looks at PHP errors, the system log, the log of every Docker container (its size, growth, limit, and what is repeating inside it) and other plugin logs. Each source can be switched off.
- A message is flagged when it repeats a lot and either fast or as most of its own log. Findings are ranked flooding, noisy and chatty (slow but repetitive, such as healthchecks), still-happening first. A traceback that prints many lines is one finding.
- Mute any finding you know about and don't care about, and unmute it later.
- Choose how often to scan in words (every 5, 10, 15 or 30 minutes, every hour, every 3, 6 or 12 hours, once a day, or off). The default is every 15 minutes. The plugin writes the cron entry for you and keeps it across reboots and updates.
- Reads only. It never changes a log, a Docker setting or a file outside its own settings. It reads only what a log gained since the last scan, and keeps its working data in RAM, so it does not write to your flash drive.
- Works on Unraid 7.2 and newer.
