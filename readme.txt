=== WP ClamAV ===
Contributors: barbarrosa
Requires at least: 6.4
Requires PHP: 8.2
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privately preserve and scan WordPress/Forminator uploads with authenticated ClamAV updates.

== Description ==

WP ClamAV preserves upload originals privately, scans their exact bytes and records
a decision before WordPress may publish a copy. Supports WordPress upload/sideload
APIs and Forminator 1.58.4, including the separate AJAX uploader.

* Keep accepted originals, narrative and history until explicit deletion.
* Choose the full database or the guarded PDF/JPEG/PNG profile with PE exclusions.
* Bound ClamAV work with shared admission, CPU pacing and memory/time limits.
* Send per-recipient reports with independently enabled attachments or verified links.
* Update code through authenticated releases and databases directly from ClamAV sources.

Experimental; starts disabled. Actual SiteGround GrowBig deployment is not yet
qualified. Configure private storage, confirmed resource allocations and a verified
native bundle or authenticated HTTPS service. Failed or incomplete scans cannot
create file-sharing capabilities.

Start with README.md and docs/getting-started.md. Browse docs/README.md for
configuration, troubleshooting, architecture, development and release references.

== Installation ==

1. Install a reviewed plugin ZIP and activate it; protection remains disabled.
2. Open Tools > WP ClamAV, or Network Admin > Settings > WP ClamAV when network activated.
3. Configure owner-only private storage outside every website document root.
4. Choose a backend, confirm allocations, select a profile and configure notifications.
5. Enable protection on staging and test outcomes/scheduling before configuring the live site.

No live-site configuration, data cleanup or external storage migration runs on
activation. Native binaries and databases are separate from the plugin ZIP.

== Changelog ==

= 0.1.1 =
Bound scan admission, CPU pacing, account memory and queue work; protect maintenance
from scan floods and defer unsubmitted or policy-held Forminator files.

= 0.1.0 =
Initial experimental implementation and signed release workflow.
