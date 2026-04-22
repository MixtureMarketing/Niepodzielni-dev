<?php

/**
 * Suppresses E_DEPRECATED from third-party plugins on PHP 8.4.
 *
 * WP_DEBUG=true causes WordPress to call error_reporting(-1) in wp-settings.php.
 * This mu-plugin runs immediately after, before regular plugins load, and strips
 * E_DEPRECATED + E_USER_DEPRECATED so the bundled AWS SDK in media-cloud-sync
 * does not flood output with "implicitly nullable parameter" notices.
 */
error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);
