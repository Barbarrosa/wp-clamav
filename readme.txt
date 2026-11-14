=== WP ClamAV ===
Contributors: barbarrosa
Requires at least: 6.4
Requires PHP: 8.2
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privately preserve and scan WordPress/Forminator uploads with authenticated ClamAV updates.

== Description ==

Experimental implementation. Requires explicit private storage, verified resource
allocations and a compatible native bundle or authenticated HTTPS scanning service.
Actual SiteGround GrowBig deployment is not yet qualified. Starts disabled.

Supports Forminator 1.58.4. Retains originals and submission history indefinitely
until explicit deletion. Failed scans never create file-sharing capabilities.
Multiple notification recipients and optional email-verified review links.

See README.md for installation, resource constraints, retention and test details.

== Installation ==

Upload the reviewed plugin ZIP and activate it. Configure WP ClamAV before enabling
protection. Private storage must be outside all document roots. No live-site
configuration, data cleanup or external storage migration runs on activation.

== Changelog ==

= 0.1.1 =
Bound scan admission, CPU pacing, account memory and queue work; protect maintenance
from scan floods and defer unsubmitted or policy-held Forminator files.

= 0.1.0 =
Initial experimental implementation and signed release workflow.
