<?php
/**
 * Uninstall WP Config Editor.
 *
 * The plugin stores no options. Backups in wp-content are deliberately
 * kept — they are the user's safety net, not plugin data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Nothing to delete.
