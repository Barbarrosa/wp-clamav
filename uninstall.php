<?php
/** Indefinite retention: uninstall deliberately leaves originals, submissions, events and configuration intact. */
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
// There is no automatic data-deletion path here. Export/migration and explicit
// authenticated deletion are separate, audited operations.
