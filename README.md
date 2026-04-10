# Chihuahua for UpdraftPlus

Sends a lil bark (email) if your nightly UpdraftPlus backups haven't run.

## What This Does

UpdraftPlus is brilliant at backing up WordPress sites. But if your backup schedule breaks (plugin conflict, hosting hiccup, WP-Cron taking a day off) you won't know until you need to restore something. And by then it's too late.

Chihuahua for UpdraftPlus watches your backup schedule and sends you an email if a completed backup isn't recorded within the expected window. Small plugin, loud when it matters.

## Requirements

- WordPress 6.8+
- PHP 8.3+
- [UpdraftPlus](https://wordpress.org/plugins/updraftplus/) (required dependency)

## Installation

Download the latest release from the [Releases](https://github.com/littleroomstudio/updraft-chihuahua/releases) page and upload it via **Plugins → Add New → Upload Plugin**.

WordPress will block activation if UpdraftPlus isn't installed and active first. That's by design—this plugin doesn't do anything without UpdraftPlus.

Alternatively, install [Git Updater](https://git-updater.com/) and point it at `littleroomstudio/updraft-chihuahua` to pull updates directly from this repo.

## How It Works

On activation, the plugin reads UpdraftPlus's scheduled cron events (`updraft_backup` and `updraft_backup_database`) and schedules a daily check six hours after the later of the two.

At check time, it reads the `updraft_last_backup` option and compares the recorded completion timestamp against a configured threshold. If the backup is stale or missing, it sends an email to the configured address.

The check runs via WP-Cron. If you're running production sites with WP-Cron disabled in favor of a real crontab (you should be), trigger the check manually with:

```bash
wp cron event run updraft_chihuahua_check
```

## Configuration

Both settings can be defined in `wp-config.php`. Neither is required tho. The defaults work fine out of the box.

```php
// Address to receive missed-backup alerts. Defaults to admin_email.
define( 'UPDRAFT_CHIHUAHUA_EMAIL', 'you@example.com' );

// Alert threshold in seconds. Defaults to 30 hours.
define( 'UPDRAFT_CHIHUAHUA_THRESHOLD', 30 * HOUR_IN_SECONDS );
```

The 30 hour default gives UpdraftPlus a six hour window past the 24 hour mark. That's plenty of runway for large sites where the uploads directory splits into dozens of zip chunks and the backup process takes a while to finish.

## Admin Notice

When you're viewing the UpdraftPlus settings page, a notice displays the next scheduled check time and the alert recipient address. Helpful for confirming everything's wired up correctly.

## License

[GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html)
