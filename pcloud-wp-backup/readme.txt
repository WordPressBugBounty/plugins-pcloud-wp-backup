=== pCloud WP Backup ===
Contributors: ploudapp, the_root
Tags: backup, pCloud
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.0.4
License: GPLv3 or later

The pCloud WP Backup plugin will help you backup everything on your blog with one click and store it in the cloud in the most secure way.

== Description ==
# pCloud WP Backup

The pCloud WP Backup plugin was created to help you backup everything on your blog with just one click and store it in the cloud in the most secure way possible.

Just create an account select a backup schedule, and we will take care of the rest.

* The backup is done directly in the cloud, so you will have access to all your files on:
Our Blog admin interface.
* All your other devices (laptops, smartphones, tablets etc.) or via https://www.pcloud.com 

## Why you should always have a backup of your website and all its assets

Backups are the ultimate insurance for your website. They ensure that if something were to happen, and you lost all data on it including backup files (which should be regularly updated), then at least one copy of everything would still exist so an emergency situation can easily be resolved with little downtime or disruption.

Just set and forget with pCloud WP Backup. The plugin will backup all your important files automatically, so you can focus on what really matters!

## Restoring backups
The plugin can not only create backups but if something goes wrong with your website it can restore a previous version in just one click

## Security
To guarantee your file's safety, pCloud WP Backup uses TLS/SSL encryption, applied when information is transferred from your website to the pCloud servers. At pCloud data security is our top priority, and we do our best to apply first class safety measures. With pCloud, your files are stored on at least three server locations in a highly secure data storage area. Optionally, you can subscribe for pCloud Crypto and have your most important files encrypted and password protected. We provide the so called client-side encryption, which, unlike server-side encryption, means that no one, except you will, have the keys for file decryption.


== Installation ==
Once installed, you will see a new menu \"pCloud Backup\", open the menu and use the \"Authenticate with pCloud\" link to authenticate with your pCloud account.
After successful authentication, you will be able to enjoy the full functionality of the plugin.

= Minimum Requirements =

* PHP 8.0 or higher
* [pCloud account](https://my.pcloud.com/#page=register&ref=1235)


== Frequently Asked Questions ==
= I am having issues with the plugin, it does not work as expected, what to do ? =

Click \"Backup Now\" and on the upper / right corner of the page you will see small \"debug\" button, click on it and the black info window will show much more debugging / useful info related to the backup process. You can send the content of that black window to: support@pcloud.com and ask for support from the pCloud team. 

= Why the manual ( Backup Now ) works, but the automatic / scheduled does not ? = 

If the manual backup mode works it means that the plugin is functioning correctly, on the other hand - to work correctly in automatic ( scheduled ) mode the website needs to have enough visitors, so they can kick-up the backup process or at least someone needs to visit the admin page of the blog.


== Screenshots ==
1. Here is a screenshot of the plugin in action

== Changelog ==

= 2.0.4 =
* Security: fixed a missing authorization check on the plugin's AJAX handler. Backup and account actions now require administrator capability and a valid nonce, closing a flaw where a logged-in lower-privileged user could trigger a backup or disconnect the pCloud account (CVE-2026-14503).
* Security: the temporary directories that briefly hold backup/restore archives are now hardened against direct web access (index.php, .htaccess, and web.config guard files), so archives cannot be listed or downloaded over HTTP.

= 2.0.3 =
* Maintenance release; no functional changes.

= 2.0.2 =
* Security: removed an unsafe TLS verification bypass on upload; the plugin no longer exposes the OAuth access token to page JavaScript or to the debug error log.
* Reliability: fixed a chunk byte-accounting bug that could leave uploads or downloads short or duplicated when a network hiccup split a chunk.
* Reliability: the database restore now streams the SQL file and parses statements with quote-aware splitting, which fixes restoring content containing semicolon+newline sequences and stops the large-site OOM during restore.
* Performance: archive extraction on restore is now a single-pass operation. Large archives restore dramatically faster.
* Performance: operational options (logs, operation state, async queue, notifications) are no longer autoloaded on every WordPress request.
* Fix: deactivating the plugin no longer wipes your OAuth token and schedule. Only full uninstall removes configuration.
* Fix: the "Next scheduled backup" display now shows the correct time.
* Fix: a transient lock prevents concurrent admin-page polls from racing and doubling up chunk uploads.
* Fix: failed chunk uploads and downloads now surface clear errors instead of hanging forever.
* Fix: database dump no longer falls back to writing `backup.sql` into the web-accessible WordPress root on hosts without a usable tmp dir; it now fails closed.
* Fix: transients are always cleared after a database restore, not only when user sessions were preserved.
* Added: unobtrusive rating prompt after two successful backups — a centered modal on the plugin page with two-touch flow (dismissible, only asked once).
* Fix: automatic backups now show progress in the admin UI — previously the progress bar was deliberately hidden for scheduled backups, making them appear frozen.
* Fix: when the admin page is open during an automatic backup, chunks upload at the same speed as manual backups instead of only advancing once per 2-minute cron tick.
* Fix: in-progress automatic backups are no longer silently aborted when the cron's schedule gate rejects (e.g. hour window closing mid-upload).
* Fix: added a version-gated upgrade routine so cron events and new options are correctly registered on plugin update (WordPress does not fire the activation hook on updates).
* Fix: the plugin now self-heals if its scheduled cron event has been cleared between version bumps (deactivate/reactivate of another plugin, cron-management tools, etc.) — the next admin pageview re-registers it instead of silently never running again.
* Tested against WordPress 6.9 (including 6.9.4).

= 2.0.1 =
* Hotfix for cURL http headers issue.

= 2.0.0 =
* Dropped support for PHP lower than 8.0.
* WordPress higher version support.
* Fixes related to failures in ZIP process and better error handling.
* Files larger than 3.6 Gb will not be backed up.
* Plugin with handle much better situation where we have low memory limit on the server.
* Added an option to choose whether to include a database snapshot in the backup archive or not.
* Added an option to choose in what time interval the plugin will try to make a backup.


= 1.5.0 =
* WordPress higher version support.
* Fixes related to the upload process.

= 1.4.0 =
* WordPress higher version support.
* Fix for multiple unsuccesful upload attempts in single upload mode.

= 1.3.0 =
* Additional improvements.

= 1.2.0 =
* API calls related to archive files listing and account info are now moved to backend, due to security concerns of few users.
* Some timings and limits are increased.

= 1.1.1 =
* Added few missing variables to INSTALL / UNINSTALL process.

= 1.1.0 =
* We have implemented new archiving solution, because of several reported issues related to the zipping process.

= 1.0.4 =
* Increased number of failures for blogs with higher number of assets.
* The plugin will retry more than once in case of bad response from the pCloud server.

= 1.0.3 =
* Added an option to choose whether to include a database snapshot in the backup archive or not.
* Much more debugging is added in order to determine the Zipping process issues.

= 1.0.2 =
* First public release, tested and confirmed to be stable enough. 

= 1.0.0 =
* Initial version of the plugin.


== Upgrade Notice ==
Plugin is more stable now and expected to work faster for bigger archives.

3raydlnawdqicu8ixwjrjgpr0fqgpae4