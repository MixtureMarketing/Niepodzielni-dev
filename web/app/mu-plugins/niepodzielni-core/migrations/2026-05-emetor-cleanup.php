<?php

declare(strict_types=1);

/**
 * Migration: Cleanup pozostałości Emetor + nieaktywnych pluginów
 * Date: 2026-05-08
 *
 * Run via: wp np migrate emetor-cleanup [--dry-run] [--yes]
 *
 * Audit DB dev.niepodzielni.com (2026-05-08) wykrył:
 *  - SEOPress postmeta ~700 row (nieaktywny plugin)
 *  - Astra theme postmeta ~440 row (zastąpiony przez Sage)
 *  - JetEngine/JetReviews ~1370 row
 *  - WP Rocket / AS3CF / Elementor options + tables
 *  - 252 orphan term_relationships (głównie Elementor library)
 *  - 8 revisions, 4 auto-drafts, 6 wpcode drafts (dead)
 *  - psycholog post_type ma 125 postów z comment_status='open' (security risk)
 *
 * NIE usuwa Emetor `np_*` BEZ prefiksu `_` (audit C1, ~9500 row) — wymaga
 * weryfikacji w PHP czy templates nie czytają wariantu publicznego.
 * To osobny migrator po code review.
 *
 * NIE konwertuje charset utf8mb3 → utf8mb4 — wymaga full DB backup
 * + osobny ALTER TABLE per tabela. Do zrobienia osobnym PR-em.
 *
 * Idempotencja: każda operacja sprawdza count przed delete.
 */

namespace Niepodzielni\Core\Migrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param  array{dry_run?: bool, yes?: bool}  $options
 * @return array{name: string, status: string, message: string, duration_ms: float, deleted: array<string,int>}
 */
function np_migration_2026_05_emetor_cleanup(array $options = []): array
{
    global $wpdb;

    $dryRun = (bool) ($options['dry_run'] ?? false);
    $yes = (bool) ($options['yes'] ?? false);

    $start = microtime(true);
    $deleted = [];

    if (! $yes && ! $dryRun) {
        return [
            'name' => '2026-05-emetor-cleanup',
            'status' => 'skipped',
            'message' => 'Wymaga --yes (mutacja) lub --dry-run (preview).',
            'duration_ms' => 0,
            'deleted' => [],
        ];
    }

    // 1. SEOPress postmeta
    $seopressKeys = [
        '_seopress_analysis_target_kw', '_seopress_redirections_type',
        '_seopress_redirections_logged_status', '_seopress_robots_primary_cat',
        '_seopress_titles_title', '_seopress_titles_desc', '_seopress_robots_canonical',
    ];
    $deleted['seopress_meta'] = np_emetor_delete_postmeta_like($wpdb, '_seopress_%', $dryRun);

    // 2. Astra theme postmeta
    $astraKeys = [
        'site-sidebar-layout', 'astra-migrate-meta-layouts', 'theme-transparent-header-meta',
        'site-content-style', 'site-sidebar-style', 'site-post-title',
        'site-content-layout', '_astra_content_layout_flag',
    ];
    $deleted['astra_meta'] = np_emetor_delete_postmeta_in($wpdb, $astraKeys, $dryRun);

    // 3. JetEngine / Jet Reviews
    $deleted['jet_meta'] = np_emetor_delete_postmeta_like($wpdb, 'jet-review%', $dryRun)
        + np_emetor_delete_postmeta_like($wpdb, 'jet_%', $dryRun);

    // 4. CDP (Cross-Domain Posts) postmeta
    $deleted['cdp_meta'] = np_emetor_delete_postmeta_like($wpdb, '_cdp_%', $dryRun);

    // 5. WooCommerce barcode postmeta (z poprzedniego stosu)
    $deleted['woo_barcode_meta'] = np_emetor_delete_postmeta_in(
        $wpdb,
        ['sp_wc_barcode_type_field', 'sp_wc_barcode_field'],
        $dryRun,
    );

    // 6. Plugin remnants options
    $optionPatterns = [
        '_seopress_%', '_site_transient_t15s%seopress%',
        'wpr_%', 'rocket_%', '_site_transient_rocket_%',
        'as3cf_%', '_site_transient_%as3cf%',
        'widget_berocket%',
    ];
    $deleted['plugin_options'] = 0;
    foreach ($optionPatterns as $pattern) {
        $deleted['plugin_options'] += np_emetor_delete_options_like($wpdb, $pattern, $dryRun);
    }
    $singleOptions = [
        'aioseo_activation_redirect', 'wpforms_activation_redirect',
        'seedprod_dismiss_setup_wizard', 'optin_monster_api_activation_redirect_disabled',
        'wpcom_publish_posts_with_markdown', '_tifm_force_disable_feature_update',
        '_wcpay_feature_woopay_first_party_auth', '_wp_social_ninja_version',
        '_give_table_check', 'give_table_check', 'wt_cli_version',
        'wp-short-pixel-activation-date', 'wpmudev_recommended_plugins_registered',
        'sbtt_rating_notice', 'feedback_unread_count', 'monitor_receive_notifications',
        'WPLANG', 'wp_force_deactivated_plugins', 'e_editor_counter',
        'edd_sl_c397ed7a535465d30ffa680d89b6e587',
        'external_updates-screets-lcx', 'external_updates-updraftplus',
        'is_beta_enable_rollback_astra-addon',
        '_hero_tax_bg_desktop', '_hero_tax_bg_mobile',
        'action_scheduler_hybrid_store_demarkation',
        'action_scheduler_lock_async-request-runner',
        'action_scheduler_migration_status', 'as_has_wp_comment_logs',
        'schema-ActionScheduler_LoggerSchema', 'schema-ActionScheduler_StoreSchema',
        'product_cat_children', 'product_brand_children',
        'campaign_category_children', 'temat_children', 'status_children',
        'default_product_cat',
        'wp_rocket_no_licence',
    ];
    $deleted['plugin_options'] += np_emetor_delete_options_in($wpdb, $singleOptions, $dryRun);

    // 7. _partners|* options
    $deleted['plugin_options'] += np_emetor_delete_options_like($wpdb, '_partners|%', $dryRun);

    // 8. Orphan term_relationships
    $orphanCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
         WHERE tr.object_id NOT IN (SELECT ID FROM {$wpdb->posts})",
    );
    if (! $dryRun && $orphanCount > 0) {
        $wpdb->query(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             WHERE tr.object_id NOT IN (SELECT ID FROM {$wpdb->posts})",
        );
    }
    $deleted['orphan_term_rel'] = $orphanCount;

    // 9. Auto-drafts + dead post types
    $autoDraftCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
    );
    if (! $dryRun && $autoDraftCount > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    }
    $deleted['auto_drafts'] = $autoDraftCount;

    // 10. Revisions
    $revCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_status = 'inherit'",
    );
    if (! $dryRun && $revCount > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_status = 'inherit'");
    }
    $deleted['revisions'] = $revCount;

    // 11. WPCode dead drafts (plugin nieaktywny)
    $wpcodeCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpcode' AND post_status = 'draft'",
    );
    if (! $dryRun && $wpcodeCount > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'wpcode' AND post_status = 'draft'");
    }
    $deleted['wpcode_drafts'] = $wpcodeCount;

    // 12. Comment hardening — psycholog NIE powinien mieć commentów
    $commentHardenCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'psycholog' AND (comment_status = 'open' OR ping_status = 'open')",
    );
    if (! $dryRun && $commentHardenCount > 0) {
        $wpdb->query(
            "UPDATE {$wpdb->posts} SET comment_status = 'closed', ping_status = 'closed'
             WHERE post_type = 'psycholog' AND (comment_status = 'open' OR ping_status = 'open')",
        );
    }
    $deleted['psycholog_comments_closed'] = $commentHardenCount;

    return [
        'name' => '2026-05-emetor-cleanup',
        'status' => $dryRun ? 'dry_run' : 'applied',
        'message' => sprintf(
            '%s: %d rows total. Breakdown: %s',
            $dryRun ? 'Dry-run preview' : 'Applied',
            array_sum($deleted),
            json_encode($deleted, JSON_UNESCAPED_SLASHES),
        ),
        'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        'deleted' => $deleted,
    ];
}

/** @internal */
function np_emetor_delete_postmeta_like(\wpdb $wpdb, string $pattern, bool $dryRun): int
{
    $count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern),
    );
    if (! $dryRun && $count > 0) {
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern),
        );
    }
    return $count;
}

/** @internal
 *  @param array<string> $keys
 */
function np_emetor_delete_postmeta_in(\wpdb $wpdb, array $keys, bool $dryRun): int
{
    if (empty($keys)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
            ...$keys,
        ),
    );
    if (! $dryRun && $count > 0) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})",
                ...$keys,
            ),
        );
    }
    return $count;
}

/** @internal */
function np_emetor_delete_options_like(\wpdb $wpdb, string $pattern, bool $dryRun): int
{
    $count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern),
    );
    if (! $dryRun && $count > 0) {
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern),
        );
    }
    return $count;
}

/** @internal
 *  @param array<string> $names
 */
function np_emetor_delete_options_in(\wpdb $wpdb, array $names, bool $dryRun): int
{
    if (empty($names)) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($names), '%s'));
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
            ...$names,
        ),
    );
    if (! $dryRun && $count > 0) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
                ...$names,
            ),
        );
    }
    return $count;
}
