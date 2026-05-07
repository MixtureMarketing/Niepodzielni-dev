<?php

/**
 * Plugin Name: NP Conversion API (loader)
 * Description: Loader stub — ładuje wtyczkę MU `np-conversion-api/np-conversion-api.php`. Mu-plugins w Bedrock są auto-ładowane tylko z top-level plików; pliki w podkatalogu wymagają tego stuba (analogicznie do niepodzielni-core.php).
 * Version: 1.0.0
 * Author: Mixture Marketing
 */

if (! defined('ABSPATH')) {
    exit;
}

$np_s2s_loader = __DIR__ . '/np-conversion-api/np-conversion-api.php';
if (is_readable($np_s2s_loader)) {
    require_once $np_s2s_loader;
}
