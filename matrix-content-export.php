<?php
/**
 * Plugin Name: Matrix Content Export / Import
 * Description: Export pages and flexi blocks (matrix-starter) to Excel (one sheet per page), CSV, or client doc. Select which pages and post types to include. Import CSV back to update content.
 * Author: Bernard Hanna
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_EXPORT_DIR', plugin_dir_path(__FILE__));
define('MATRIX_EXPORT_URL', plugin_dir_url(__FILE__));
define('MATRIX_EXPORT_CLIENT_FORM_SLUG', 'content-editing');
define('MATRIX_EXPORT_CLIENT_FORM_OPTION', 'matrix_export_client_form_post_ids');
define('MATRIX_EXPORT_NOTIFY_EMAIL_OPTION', 'matrix_export_notify_email');
define('MATRIX_EXPORT_REVIEW_NOTIFY_EMAIL_OPTION', 'matrix_export_review_notify_email');
define('MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION', 'matrix_export_admin_duplicate_notify_email');
define('MATRIX_EXPORT_DISABLED_FORM_FIELDS_OPTION', 'matrix_export_disabled_form_fields');
define('MATRIX_EXPORT_DISABLED_BLOCKS_OPTION', 'matrix_export_disabled_blocks');
define('MATRIX_EXPORT_PENDING_REVIEWS_OPTION', 'matrix_export_pending_reviews');
define('MATRIX_EXPORT_AI_SETTINGS_OPTION', 'matrix_export_ai_settings');
define('MATRIX_EXPORT_RUNTIME_SETTINGS_OPTION', 'matrix_export_runtime_settings');
define('MATRIX_EXPORT_STRICT_SETTINGS_OPTION', 'matrix_export_strict_settings');
/** Max size for image uploads in the client form (bytes). Default 2 MB. */
define('MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES', 2 * 1024 * 1024);
/** Max size for video uploads in the client form (bytes). Default 30 MB. */
define('MATRIX_EXPORT_MAX_VIDEO_UPLOAD_BYTES', 30 * 1024 * 1024);
/** Post meta key for content-editing status (To do / In progress / Done / Delete). */
define('MATRIX_EXPORT_STATUS_META_KEY', 'matrix_content_status');
/** Post meta key for email of user who marked content as Done. */
define('MATRIX_EXPORT_STATUS_DONE_BY_META_KEY', 'matrix_content_status_done_by');

require_once MATRIX_EXPORT_DIR . 'includes/class-matrix-export.php';
require_once MATRIX_EXPORT_DIR . 'includes/class-matrix-import.php';

add_action('admin_menu', 'matrix_export_register_menu');
add_action('admin_init', 'matrix_export_handle_actions');
add_action('init', 'matrix_export_handle_client_form_submit', 5);
add_action('wp_ajax_matrix_preview_progress', 'matrix_export_preview_progress');
add_action('wp_ajax_matrix_export_save_page_status', 'matrix_export_ajax_save_page_status');
add_action('wp_ajax_matrix_export_autosave_draft', 'matrix_export_ajax_autosave_draft');
add_action('wp_ajax_matrix_export_duplicate_page', 'matrix_export_ajax_duplicate_page');
add_action('wp_ajax_matrix_export_get_fields_for_posts', 'matrix_export_ajax_get_fields_for_posts');
add_action('wp_ajax_matrix_export_ai_generate_block', 'matrix_export_ajax_ai_generate_block');
add_action('wp_ajax_matrix_export_save_strict_rule', 'matrix_export_ajax_save_strict_rule');
add_action('wp_enqueue_scripts', 'matrix_export_content_editing_scripts', 5);
add_filter('manage_pages_columns', 'matrix_export_add_status_column');
add_filter('manage_posts_columns', 'matrix_export_add_status_column');
add_action('manage_pages_custom_column', 'matrix_export_show_status_column', 10, 2);
add_action('manage_posts_custom_column', 'matrix_export_show_status_column', 10, 2);
add_action('admin_head-edit.php', 'matrix_export_status_column_styles');
add_action('template_redirect', 'matrix_export_render_content_editing_form', 1);
add_action('wp_footer', 'matrix_export_form_saved_notice');
add_action('wp_footer', 'matrix_export_hash_anchor_resolver', 20);
add_filter('show_admin_bar', 'matrix_export_hide_admin_bar_on_content_editing', 99);

/**
 * Preview mode is used by embedded block iframes.
 */
function matrix_export_is_preview_request() {
    return isset($_GET['matrix_preview']) && $_GET['matrix_preview'] === '1';
}

/**
 * On the content-editing page, enqueue media so the editor "Add Media" button opens the modal instead of scrolling.
 */
function matrix_export_content_editing_scripts() {
    if (!matrix_export_is_content_editing_url()) {
        return;
    }
    if (function_exists('wp_enqueue_editor')) {
        wp_enqueue_editor();
    }
    wp_enqueue_media();
}

/**
 * Hide WP admin toolbar on client editing page.
 */
function matrix_export_hide_admin_bar_on_content_editing($show) {
    if (matrix_export_is_content_editing_url() || matrix_export_is_preview_request()) {
        return false;
    }
    return $show;
}

/**
 * Clear cached block preview screenshots.
 *
 * @return int Number of deleted files.
 */
function matrix_export_clear_cached_previews() {
    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) {
        return 0;
    }
    $dir = trailingslashit($uploads['basedir']) . 'matrix-content-export-previews';
    if (!is_dir($dir)) {
        return 0;
    }
    $files = glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    if (!is_array($files) || empty($files)) {
        return 0;
    }
    $deleted = 0;
    foreach ($files as $file) {
        if (is_file($file) && @unlink($file)) {
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Clear cached previews for specific post IDs.
 *
 * @param array<int,int|string> $post_ids
 * @return int Number of deleted files.
 */
function matrix_export_clear_cached_previews_for_posts(array $post_ids) {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (empty($post_ids)) {
        return 0;
    }
    $uploads = wp_upload_dir();
    if (empty($uploads['basedir'])) {
        return 0;
    }
    $dir = trailingslashit($uploads['basedir']) . 'matrix-content-export-previews';
    if (!is_dir($dir)) {
        return 0;
    }
    $deleted = 0;
    foreach ($post_ids as $pid) {
        $files = glob($dir . '/' . $pid . '-*.{jpg,jpeg,png,webp}', GLOB_BRACE);
        if (!is_array($files) || empty($files)) {
            continue;
        }
        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $deleted++;
            }
        }
    }
    return $deleted;
}

/**
 * Check if a flex/hero row index is disabled for a given post.
 *
 * @param int $post_id
 * @param string $block_source e.g. flexible_content_blocks
 * @param int $block_index Zero-based row index
 * @return bool
 */
function matrix_export_is_block_disabled($post_id, $block_source, $block_index) {
    $post_id = (int) $post_id;
    $block_index = (int) $block_index;
    if ($post_id <= 0 || $block_index < 0 || !is_string($block_source) || $block_source === '') {
        return false;
    }
    $map = get_option(MATRIX_EXPORT_DISABLED_BLOCKS_OPTION, []);
    if (!is_array($map) || empty($map[$post_id]) || empty($map[$post_id][$block_source]) || !is_array($map[$post_id][$block_source])) {
        return false;
    }
    $disabled_indices = array_map('intval', $map[$post_id][$block_source]);
    return in_array($block_index, $disabled_indices, true);
}

function matrix_export_register_menu() {
    add_management_page(
        'Matrix Content Gathering',
        'Content Gathering',
        'manage_options',
        'matrix-content-export',
        'matrix_export_render_page',
        'dashicons-download',
        61
    );
}

function matrix_export_handle_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (
        isset($_POST['matrix_client_links_action_nonce'], $_POST['matrix_client_links_action']) &&
        wp_verify_nonce($_POST['matrix_client_links_action_nonce'], 'matrix_client_links_action')
    ) {
        $action = sanitize_key(wp_unslash($_POST['matrix_client_links_action']));
        if ($action === 'clear_one' && isset($_POST['matrix_client_link_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['matrix_client_link_token']));
            $post_ids = Matrix_Export::get_client_link_post_ids($token, true);
            $deleted_previews = matrix_export_clear_cached_previews_for_posts($post_ids);
            matrix_export_remove_pending_reviews_for_token($token);
            Matrix_Export::delete_client_link($token);
            wp_safe_redirect(add_query_arg([
                'matrix_client_link_cleared' => '1',
                'matrix_previews_deleted' => (int) $deleted_previews,
            ], wp_get_referer()));
            exit;
        }
        if ($action === 'generate_one' && isset($_POST['matrix_client_link_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['matrix_client_link_token']));
            $post_ids = Matrix_Export::get_client_link_post_ids($token, true);
            $result = Matrix_Export::generate_block_previews_async($post_ids, $token);
            wp_safe_redirect(add_query_arg([
                'matrix_previews_queued' => isset($result['queued']) && $result['queued'] ? '1' : '0',
                'matrix_previews_tasks' => isset($result['tasks']) ? (int) $result['tasks'] : 0,
                'matrix_previews_job' => isset($result['job_key']) ? rawurlencode((string) $result['job_key']) : '',
                'matrix_previews_reason' => isset($result['reason']) ? rawurlencode((string) $result['reason']) : '',
            ], wp_get_referer()));
            exit;
        }
        if ($action === 'update_mode' && isset($_POST['matrix_client_link_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['matrix_client_link_token']));
            $mode = isset($_POST['matrix_client_link_mode']) ? sanitize_key(wp_unslash($_POST['matrix_client_link_mode'])) : 'publish';
            $requires_approval = ($mode === 'approval');
            $strict_mode = !empty($_POST['matrix_client_link_strict_mode']);
            $ai_mode = !empty($_POST['matrix_client_link_ai_mode']);
            $ok = Matrix_Export::update_client_link_modes($token, $requires_approval, $strict_mode, $ai_mode);
            wp_safe_redirect(add_query_arg([
                'matrix_client_mode_updated' => $ok ? '1' : '0',
            ], wp_get_referer()));
            exit;
        }
        if ($action === 'clear_all') {
            $deleted_previews = matrix_export_clear_cached_previews();
            Matrix_Export::clear_client_links();
            delete_option(MATRIX_EXPORT_PENDING_REVIEWS_OPTION);
            wp_safe_redirect(add_query_arg([
                'matrix_client_link_cleared_all' => '1',
                'matrix_previews_deleted' => (int) $deleted_previews,
            ], wp_get_referer()));
            exit;
        }
    }

    if (
        isset($_POST['matrix_reviews_action_nonce'], $_POST['matrix_reviews_action']) &&
        wp_verify_nonce($_POST['matrix_reviews_action_nonce'], 'matrix_reviews_action')
    ) {
        $action = sanitize_key(wp_unslash($_POST['matrix_reviews_action']));
        $submission_id = isset($_POST['matrix_review_submission_id']) ? sanitize_text_field(wp_unslash($_POST['matrix_review_submission_id'])) : '';
        $review_note = isset($_POST['matrix_review_note']) ? sanitize_text_field(wp_unslash($_POST['matrix_review_note'])) : '';
        if ($submission_id !== '' && $action === 'approve') {
            $result = matrix_export_approve_pending_review($submission_id, $review_note);
            wp_safe_redirect(add_query_arg([
                'matrix_review_approved' => !empty($result['success']) ? '1' : '0',
                'matrix_review_message' => rawurlencode(isset($result['message']) ? (string) $result['message'] : ''),
            ], wp_get_referer()));
            exit;
        }
        if ($submission_id !== '' && $action === 'reject') {
            $result = matrix_export_reject_pending_review($submission_id, $review_note);
            wp_safe_redirect(add_query_arg([
                'matrix_review_rejected' => !empty($result['success']) ? '1' : '0',
                'matrix_review_message' => rawurlencode(isset($result['message']) ? (string) $result['message'] : ''),
            ], wp_get_referer()));
            exit;
        }
    }

    if (
        isset($_GET['matrix_generate_screenshots_token'], $_GET['_wpnonce']) &&
        wp_verify_nonce($_GET['_wpnonce'], 'matrix_generate_screenshots_' . $_GET['matrix_generate_screenshots_token'])
    ) {
        $token = sanitize_text_field(wp_unslash($_GET['matrix_generate_screenshots_token']));
        $post_ids = Matrix_Export::get_client_link_post_ids($token, true);
        $result = Matrix_Export::generate_block_previews_async($post_ids, $token);
        $back = admin_url('tools.php?page=matrix-content-export');
        wp_safe_redirect(add_query_arg([
            'matrix_previews_queued' => isset($result['queued']) && $result['queued'] ? '1' : '0',
            'matrix_previews_tasks' => isset($result['tasks']) ? (int) $result['tasks'] : 0,
            'matrix_previews_job' => isset($result['job_key']) ? rawurlencode((string) $result['job_key']) : '',
            'matrix_previews_reason' => isset($result['reason']) ? rawurlencode((string) $result['reason']) : '',
        ], $back));
        exit;
    }

    // Legacy GET export (single CSV / single doc) – no selection
    if (isset($_GET['matrix_export_csv']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_csv')) {
        Matrix_Export::download_csv(null);
        exit;
    }
    if (isset($_GET['matrix_export_doc']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'matrix_export_doc')) {
        Matrix_Export::download_doc(null);
        exit;
    }

    // POST export with selection (Excel/CSV/Doc)
    if (isset($_POST['matrix_export_nonce']) && wp_verify_nonce($_POST['matrix_export_nonce'], 'matrix_export')) {
        if (isset($_POST['matrix_form_fields_settings_present']) && $_POST['matrix_form_fields_settings_present'] === '1') {
            $all_field_keys = Matrix_Export::get_all_content_field_keys();
            $disabled_fields = isset($_POST['matrix_disabled_form_fields']) && is_array($_POST['matrix_disabled_form_fields'])
                ? array_map(function ($key) {
                    return sanitize_key((string) wp_unslash($key));
                }, $_POST['matrix_disabled_form_fields'])
                : [];
            $disabled_map = array_fill_keys(array_filter($disabled_fields), true);
            $clean_disabled = [];
            foreach ($all_field_keys as $field_key) {
                if (isset($disabled_map[$field_key])) {
                    $clean_disabled[] = $field_key;
                }
            }
            if (!empty($clean_disabled)) {
                update_option(MATRIX_EXPORT_DISABLED_FORM_FIELDS_OPTION, array_values(array_unique($clean_disabled)), false);
            } else {
                delete_option(MATRIX_EXPORT_DISABLED_FORM_FIELDS_OPTION);
            }
        }
        if (isset($_POST['matrix_admin_duplicate_notify_email'])) {
            $admin_notify_email = sanitize_email(wp_unslash($_POST['matrix_admin_duplicate_notify_email']));
            if ($admin_notify_email !== '') {
                update_option(MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION, $admin_notify_email);
            } else {
                delete_option(MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION);
            }
        }
        if (isset($_POST['matrix_notify_email'])) {
            $notify_email = sanitize_email(wp_unslash($_POST['matrix_notify_email']));
            if ($notify_email !== '') {
                update_option(MATRIX_EXPORT_NOTIFY_EMAIL_OPTION, $notify_email);
            } else {
                delete_option(MATRIX_EXPORT_NOTIFY_EMAIL_OPTION);
            }
        }
        if (isset($_POST['matrix_review_notify_email'])) {
            $review_notify_email = sanitize_email(wp_unslash($_POST['matrix_review_notify_email']));
            if ($review_notify_email !== '') {
                update_option(MATRIX_EXPORT_REVIEW_NOTIFY_EMAIL_OPTION, $review_notify_email);
            } else {
                delete_option(MATRIX_EXPORT_REVIEW_NOTIFY_EMAIL_OPTION);
            }
        }
        $runtime_node_binary = isset($_POST['matrix_runtime_node_binary']) ? trim((string) wp_unslash($_POST['matrix_runtime_node_binary'])) : '';
        $runtime_playwright_path = isset($_POST['matrix_runtime_playwright_browsers_path']) ? trim((string) wp_unslash($_POST['matrix_runtime_playwright_browsers_path'])) : '';
        $runtime_settings = [
            'node_binary' => sanitize_text_field($runtime_node_binary),
            'playwright_browsers_path' => sanitize_text_field($runtime_playwright_path),
        ];
        if ($runtime_settings['node_binary'] === '' && $runtime_settings['playwright_browsers_path'] === '') {
            delete_option(MATRIX_EXPORT_RUNTIME_SETTINGS_OPTION);
        } else {
            update_option(MATRIX_EXPORT_RUNTIME_SETTINGS_OPTION, $runtime_settings, false);
        }
        $ai_provider = isset($_POST['matrix_ai_provider']) ? sanitize_key(wp_unslash($_POST['matrix_ai_provider'])) : 'openai';
        if (!in_array($ai_provider, ['openai', 'gemini'], true)) {
            $ai_provider = 'openai';
        }
        $ai_model_select = isset($_POST['matrix_ai_model_select']) ? sanitize_text_field(wp_unslash($_POST['matrix_ai_model_select'])) : '';
        $ai_model_custom = isset($_POST['matrix_ai_model_custom']) ? sanitize_text_field(wp_unslash($_POST['matrix_ai_model_custom'])) : '';
        $ai_model_legacy = isset($_POST['matrix_ai_model']) ? sanitize_text_field(wp_unslash($_POST['matrix_ai_model'])) : '';
        $ai_model = $ai_model_custom !== '' ? $ai_model_custom : ($ai_model_select !== '' ? $ai_model_select : $ai_model_legacy);
        $ai_openai_key = isset($_POST['matrix_ai_openai_key']) ? sanitize_text_field(wp_unslash($_POST['matrix_ai_openai_key'])) : '';
        $ai_gemini_key = isset($_POST['matrix_ai_gemini_key']) ? sanitize_text_field(wp_unslash($_POST['matrix_ai_gemini_key'])) : '';
        $existing_ai_settings = matrix_export_get_ai_settings();
        $ai_settings = [
            'enabled' => !empty($_POST['matrix_ai_enabled']) ? 1 : 0,
            'provider' => $ai_provider,
            'model' => $ai_model !== '' ? $ai_model : ($ai_provider === 'gemini' ? 'gemini-3-flash' : 'gpt-5-mini'),
            'openai_api_key' => $ai_openai_key !== '' ? $ai_openai_key : (isset($existing_ai_settings['openai_api_key']) ? (string) $existing_ai_settings['openai_api_key'] : ''),
            'gemini_api_key' => $ai_gemini_key !== '' ? $ai_gemini_key : (isset($existing_ai_settings['gemini_api_key']) ? (string) $existing_ai_settings['gemini_api_key'] : ''),
        ];
        if ($ai_openai_key === '' && !empty($_POST['matrix_ai_openai_key_clear'])) {
            $ai_settings['openai_api_key'] = '';
        }
        if ($ai_gemini_key === '' && !empty($_POST['matrix_ai_gemini_key_clear'])) {
            $ai_settings['gemini_api_key'] = '';
        }
        update_option(MATRIX_EXPORT_AI_SETTINGS_OPTION, $ai_settings, false);
        if (
            isset($_POST['matrix_strict_super_admin_user_id']) ||
            isset($_POST['matrix_strict_default_min_words']) ||
            isset($_POST['matrix_strict_default_min_chars']) ||
            isset($_POST['matrix_strict_enforce_publish_only']) ||
            isset($_POST['matrix_strict_enable_spellcheck'])
        ) {
            $strict_settings = [
                'super_admin_user_id' => isset($_POST['matrix_strict_super_admin_user_id']) ? (int) $_POST['matrix_strict_super_admin_user_id'] : 0,
                'default_min_words' => isset($_POST['matrix_strict_default_min_words']) ? max(0, (int) $_POST['matrix_strict_default_min_words']) : 0,
                'default_min_chars' => isset($_POST['matrix_strict_default_min_chars']) ? max(0, (int) $_POST['matrix_strict_default_min_chars']) : 0,
                'enforce_publish_only' => !empty($_POST['matrix_strict_enforce_publish_only']) ? 1 : 0,
                'enable_spellcheck' => !empty($_POST['matrix_strict_enable_spellcheck']) ? 1 : 0,
            ];
            if ($strict_settings['super_admin_user_id'] > 0 && !get_userdata($strict_settings['super_admin_user_id'])) {
                $strict_settings['super_admin_user_id'] = 0;
            }
            if (
                $strict_settings['super_admin_user_id'] <= 0 &&
                $strict_settings['default_min_words'] <= 0 &&
                $strict_settings['default_min_chars'] <= 0 &&
                empty($strict_settings['enforce_publish_only']) &&
                empty($strict_settings['enable_spellcheck'])
            ) {
                delete_option(MATRIX_EXPORT_STRICT_SETTINGS_OPTION);
            } else {
                update_option(MATRIX_EXPORT_STRICT_SETTINGS_OPTION, $strict_settings, false);
            }
        }
        if (!empty($_POST['matrix_settings_submit']) && (string) wp_unslash($_POST['matrix_settings_submit']) === '1') {
            $back = wp_get_referer();
            if (!is_string($back) || $back === '') {
                $back = admin_url('tools.php?page=matrix-content-export');
            }
            wp_safe_redirect(add_query_arg(['matrix_settings_saved' => '1'], $back));
            exit;
        }
        $post_ids = isset($_POST['matrix_export_post_ids']) && is_array($_POST['matrix_export_post_ids'])
            ? array_map('intval', $_POST['matrix_export_post_ids'])
            : [];
        $format = isset($_POST['matrix_export_format']) ? sanitize_key($_POST['matrix_export_format']) : '';

        if ($format === 'client_link' && !empty($post_ids)) {
            $expires_days = isset($_POST['matrix_client_link_expires_days']) ? max(0, (int) $_POST['matrix_client_link_expires_days']) : 0;
            $reminder_days = isset($_POST['matrix_client_link_reminder_days']) ? max(0, (int) $_POST['matrix_client_link_reminder_days']) : 0;
            $custom_instructions = isset($_POST['matrix_client_custom_instructions']) ? wp_kses_post(wp_unslash((string) $_POST['matrix_client_custom_instructions'])) : '';
            $requires_approval = !empty($_POST['matrix_client_requires_approval']);
            $strict_mode = !empty($_POST['matrix_client_strict_mode']);
            $ai_mode = !empty($_POST['matrix_client_ai_mode']);
            $token = Matrix_Export::create_client_link($post_ids, [
                'expires_days' => $expires_days,
                'reminder_days' => $reminder_days,
                'custom_instructions' => $custom_instructions,
                'requires_approval' => $requires_approval,
                'strict_mode' => $strict_mode,
                'ai_mode' => $ai_mode,
            ]);
            if ($token !== '') {
                $redirect = add_query_arg(
                    [
                        'matrix_client_link' => '1',
                        'matrix_client_token' => rawurlencode($token),
                    ],
                    wp_get_referer()
                );
                wp_safe_redirect($redirect);
                exit;
            }
        }
        if ($format === 'client_links_excel') {
            if (empty($post_ids)) {
                wp_safe_redirect(add_query_arg('matrix_export_error', 'excel_no_selection', wp_get_referer()));
                exit;
            }
            $strict_mode = !empty($_POST['matrix_client_strict_mode']);
            $ai_mode = !empty($_POST['matrix_client_ai_mode']);
            Matrix_Export::download_client_links_workbook($post_ids, [
                'strict_mode' => $strict_mode,
                'ai_mode' => $ai_mode,
            ]);
            exit;
        }
        if ($format !== 'client_link' && !empty($post_ids)) {
            wp_safe_redirect(add_query_arg('matrix_export_error', 'unsupported', wp_get_referer()));
            exit;
        }
        if (!empty($post_ids)) {
            wp_safe_redirect(add_query_arg('matrix_export_error', '1', wp_get_referer()));
            exit;
        }
    }
}

/**
 * AJAX status endpoint for screenshot generation progress.
 */
function matrix_export_preview_progress() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    $job = isset($_GET['job']) ? sanitize_text_field(wp_unslash($_GET['job'])) : '';
    if ($job === '') {
        wp_send_json_success(['found' => false]);
    }
    $status = Matrix_Export::get_preview_status($job);
    if (empty($status)) {
        wp_send_json_success(['found' => false]);
    }
    $total = isset($status['total']) ? (int) $status['total'] : 0;
    $completed = isset($status['completed']) ? (int) $status['completed'] : 0;
    $generated = isset($status['generated']) ? (int) $status['generated'] : 0;
    $done = !empty($status['done']);
    $error = isset($status['error']) ? (string) $status['error'] : '';
    $percent = ($total > 0) ? (int) floor(($completed / $total) * 100) : 0;
    $stats = isset($status['stats']) && is_array($status['stats']) ? $status['stats'] : [];
    wp_send_json_success([
        'found' => true,
        'total' => $total,
        'completed' => $completed,
        'generated' => $generated,
        'done' => $done,
        'error' => $error,
        'percent' => $percent,
        'stats' => [
            'selector_matches' => isset($stats['selector_matches']) ? (int) $stats['selector_matches'] : 0,
            'section_fallback_matches' => isset($stats['section_fallback_matches']) ? (int) $stats['section_fallback_matches'] : 0,
            'no_target_matches' => isset($stats['no_target_matches']) ? (int) $stats['no_target_matches'] : 0,
            'capture_errors' => isset($stats['capture_errors']) ? (int) $stats['capture_errors'] : 0,
            'last_error' => isset($stats['last_error']) ? (string) $stats['last_error'] : '',
        ],
    ]);
}

/**
 * AJAX: save a single page status from the client form.
 */
function matrix_export_ajax_save_page_status() {
    if (!is_user_logged_in() || !matrix_export_user_can_access_client_form()) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if (!isset($_POST['matrix_save_status_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_save_status_nonce'])), 'matrix_export_save_page_status')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }
    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    $post_id = isset($_POST['matrix_post_id']) ? (int) $_POST['matrix_post_id'] : 0;
    $status = isset($_POST['matrix_status']) ? sanitize_text_field(wp_unslash($_POST['matrix_status'])) : '';
    if ($token === '' || $post_id <= 0 || !in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
        wp_send_json_error(['message' => 'Invalid parameters'], 400);
    }
    $allowed = Matrix_Export::get_client_link_post_ids($token);
    if (empty($allowed) || !in_array($post_id, array_map('intval', $allowed), true)) {
        wp_send_json_error(['message' => 'Invalid token or post'], 403);
    }
    $done_by = '';
    if ($status === 'done' && function_exists('wp_get_current_user')) {
        $user = wp_get_current_user();
        $done_by = isset($user->user_email) ? (string) $user->user_email : '';
    }
    if (method_exists('Matrix_Import', 'update_draft_page_status')) {
        Matrix_Import::update_draft_page_status($token, $post_id, $status, $done_by);
    }
    update_post_meta($post_id, MATRIX_EXPORT_STATUS_META_KEY, $status);
    if ($status === 'done' && $done_by !== '') {
        update_post_meta($post_id, MATRIX_EXPORT_STATUS_DONE_BY_META_KEY, $done_by);
    } else {
        delete_post_meta($post_id, MATRIX_EXPORT_STATUS_DONE_BY_META_KEY);
    }
    wp_send_json_success(['saved' => true, 'done_by' => $done_by]);
}

/**
 * AJAX: autosave full form payload in "Save for later" mode.
 */
function matrix_export_ajax_autosave_draft() {
    if (!is_user_logged_in() || !matrix_export_user_can_access_client_form()) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if (!isset($_POST['matrix_autosave_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_autosave_nonce'])), 'matrix_export_autosave_draft')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }
    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    if ($token === '') {
        wp_send_json_error(['message' => 'Missing token'], 400);
    }
    $_POST['matrix_is_autosave'] = '1';
    $result = Matrix_Import::handle_form_submit($_POST, isset($_FILES) ? $_FILES : [], 'later');
    if (empty($result['success'])) {
        wp_send_json_error(['message' => isset($result['message']) ? (string) $result['message'] : 'Autosave failed'], 400);
    }
    $draft_state = Matrix_Import::get_client_form_draft_state($token, []);
    $saved_at = isset($draft_state['saved_at']) ? (int) $draft_state['saved_at'] : time();
    wp_send_json_success([
        'saved' => true,
        'saved_at' => $saved_at,
        'saved_at_text' => wp_date('M j, Y g:i a', $saved_at),
    ]);
}

/**
 * AJAX: duplicate a page/post from the client form and return an editable form URL.
 */
function matrix_export_ajax_duplicate_page() {
    if (!is_user_logged_in() || !matrix_export_user_can_access_client_form()) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if (!isset($_POST['matrix_duplicate_page_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_duplicate_page_nonce'])), 'matrix_export_duplicate_page')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    $post_id = isset($_POST['matrix_post_id']) ? (int) $_POST['matrix_post_id'] : 0;
    if ($token === '' || $post_id <= 0) {
        wp_send_json_error(['message' => 'Invalid parameters'], 400);
    }
    $allowed = Matrix_Export::get_client_link_post_ids($token);
    if (empty($allowed) || !in_array($post_id, array_map('intval', $allowed), true)) {
        wp_send_json_error(['message' => 'Invalid token or post'], 403);
    }

    $source = get_post($post_id);
    if (!$source || !($source instanceof WP_Post)) {
        wp_send_json_error(['message' => 'Source post not found'], 404);
    }
    if (!current_user_can('edit_post', $post_id) || !current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Insufficient permissions'], 403);
    }

    $new_status = current_user_can('publish_posts') ? 'publish' : 'draft';
    $new_post_id = wp_insert_post([
        'post_type' => $source->post_type,
        'post_title' => $source->post_title . ' (Copy)',
        'post_content' => $source->post_content,
        'post_excerpt' => $source->post_excerpt,
        'post_status' => $new_status,
        'post_parent' => (int) $source->post_parent,
        'menu_order' => (int) $source->menu_order,
        'post_author' => get_current_user_id(),
        'comment_status' => $source->comment_status,
        'ping_status' => $source->ping_status,
    ], true);
    if (is_wp_error($new_post_id) || !$new_post_id) {
        wp_send_json_error(['message' => 'Could not duplicate page'], 500);
    }

    $thumb_id = (int) get_post_thumbnail_id($post_id);
    if ($thumb_id > 0) {
        set_post_thumbnail($new_post_id, $thumb_id);
    }

    $taxonomies = get_object_taxonomies($source->post_type, 'names');
    if (is_array($taxonomies)) {
        foreach ($taxonomies as $taxonomy) {
            $term_ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($term_ids)) {
                continue;
            }
            wp_set_post_terms($new_post_id, array_map('intval', (array) $term_ids), $taxonomy, false);
        }
    }

    $template = get_post_meta($post_id, '_wp_page_template', true);
    if (is_string($template) && $template !== '') {
        update_post_meta($new_post_id, '_wp_page_template', $template);
    }

    if (function_exists('get_field') && function_exists('update_field')) {
        foreach ([Matrix_Export::HERO_FIELD, Matrix_Export::FLEX_FIELD] as $acf_field) {
            // Use unformatted/raw ACF values so relationship/taxonomy subfields remain IDs, not WP_Term objects.
            $value = get_field($acf_field, $post_id, false);
            if (is_array($value)) {
                $value = matrix_export_normalize_acf_value_for_save($value);
                update_field($acf_field, $value, $new_post_id);
            }
        }
    }

    // Add duplicate into the same tokenized form so it appears as a new editable tab.
    $links = Matrix_Export::get_client_links();
    if (isset($links[$token]) && is_array($links[$token])) {
        $token_post_ids = isset($links[$token]['post_ids']) && is_array($links[$token]['post_ids'])
            ? array_map('intval', $links[$token]['post_ids'])
            : [];
        $token_post_ids[] = (int) $new_post_id;
        $links[$token]['post_ids'] = array_values(array_unique(array_filter($token_post_ids)));
        update_option(Matrix_Export::CLIENT_LINKS_OPTION, $links, false);
    }

    $form_url = Matrix_Export::get_client_link_url($token);
    $form_url = add_query_arg('matrix_page', (int) $new_post_id, $form_url);
    $view_url = get_preview_post_link($new_post_id);
    if (!is_string($view_url) || $view_url === '') {
        $view_url = get_permalink($new_post_id);
    }
    $edit_url = get_edit_post_link($new_post_id, '');
    matrix_export_send_duplicate_notification_email($post_id, (int) $new_post_id, $token, $form_url, $view_url);

    wp_send_json_success([
        'new_post_id' => (int) $new_post_id,
        'form_url' => is_string($form_url) ? $form_url : '',
        'view_url' => is_string($view_url) ? $view_url : '',
        'edit_url' => is_string($edit_url) ? $edit_url : '',
        'title' => get_the_title($new_post_id),
    ]);
}

/**
 * AJAX: return detected editable field keys for selected post IDs (admin settings helper).
 */
function matrix_export_ajax_get_fields_for_posts() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }
    if (!isset($_POST['matrix_fields_for_posts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_fields_for_posts_nonce'])), 'matrix_export_fields_for_posts')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }
    $post_ids = isset($_POST['post_ids']) && is_array($_POST['post_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $_POST['post_ids']))))
        : [];
    if (empty($post_ids)) {
        wp_send_json_success(['field_keys' => []]);
    }
    $data = Matrix_Export::get_export_data_for_form($post_ids, false);
    $keys = isset($data['field_keys']) && is_array($data['field_keys']) ? array_values(array_filter(array_map('strval', $data['field_keys']))) : [];
    sort($keys);
    wp_send_json_success(['field_keys' => $keys]);
}

/**
 * Normalize nested ACF values to safe scalars/arrays before update_field().
 *
 * @param mixed $value
 * @return mixed
 */
function matrix_export_normalize_acf_value_for_save($value) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = matrix_export_normalize_acf_value_for_save($v);
        }
        return $out;
    }
    if (!is_object($value)) {
        return $value;
    }
    if ($value instanceof WP_Error) {
        return 0;
    }
    if ($value instanceof WP_Term) {
        return isset($value->term_id) ? (int) $value->term_id : 0;
    }
    if ($value instanceof WP_Post || $value instanceof WP_User) {
        return isset($value->ID) ? (int) $value->ID : 0;
    }
    if (method_exists($value, '__toString')) {
        return (string) $value;
    }
    return 0;
}

/**
 * Read AI settings from options with defaults.
 *
 * @return array{enabled:int,provider:string,model:string,openai_api_key:string,gemini_api_key:string}
 */
function matrix_export_get_ai_settings() {
    $raw = get_option(MATRIX_EXPORT_AI_SETTINGS_OPTION, []);
    if (!is_array($raw)) {
        $raw = [];
    }
    $provider = isset($raw['provider']) ? sanitize_key((string) $raw['provider']) : 'openai';
    if (!in_array($provider, ['openai', 'gemini'], true)) {
        $provider = 'openai';
    }
    $model = isset($raw['model']) ? sanitize_text_field((string) $raw['model']) : '';
    if ($model === '') {
        $model = $provider === 'gemini' ? 'gemini-3-flash' : 'gpt-5-mini';
    }
    return [
        'enabled' => !empty($raw['enabled']) ? 1 : 0,
        'provider' => $provider,
        'model' => $model,
        'openai_api_key' => isset($raw['openai_api_key']) ? sanitize_text_field((string) $raw['openai_api_key']) : '',
        'gemini_api_key' => isset($raw['gemini_api_key']) ? sanitize_text_field((string) $raw['gemini_api_key']) : '',
    ];
}

/**
 * Runtime settings for screenshot generation paths.
 *
 * @return array{node_binary:string,playwright_browsers_path:string}
 */
function matrix_export_get_runtime_settings() {
    $raw = get_option(MATRIX_EXPORT_RUNTIME_SETTINGS_OPTION, []);
    if (!is_array($raw)) {
        $raw = [];
    }
    return [
        'node_binary' => isset($raw['node_binary']) ? trim((string) $raw['node_binary']) : '',
        'playwright_browsers_path' => isset($raw['playwright_browsers_path']) ? trim((string) $raw['playwright_browsers_path']) : '',
    ];
}

/**
 * Strict mode settings for client form guardrails.
 *
 * @return array{super_admin_user_id:int,default_min_words:int,default_min_chars:int,enforce_publish_only:int,enable_spellcheck:int}
 */
function matrix_export_get_strict_settings() {
    $raw = get_option(MATRIX_EXPORT_STRICT_SETTINGS_OPTION, []);
    if (!is_array($raw)) {
        $raw = [];
    }
    return [
        'super_admin_user_id' => isset($raw['super_admin_user_id']) ? max(0, (int) $raw['super_admin_user_id']) : 0,
        'default_min_words' => isset($raw['default_min_words']) ? max(0, (int) $raw['default_min_words']) : 0,
        'default_min_chars' => isset($raw['default_min_chars']) ? max(0, (int) $raw['default_min_chars']) : 0,
        'enforce_publish_only' => isset($raw['enforce_publish_only']) ? (!empty($raw['enforce_publish_only']) ? 1 : 0) : 1,
        'enable_spellcheck' => !empty($raw['enable_spellcheck']) ? 1 : 0,
    ];
}

/**
 * Extract first JSON object found in text.
 *
 * @param string $text
 * @return array<string,mixed>|null
 */
function matrix_export_ai_extract_json_object($text) {
    $text = is_string($text) ? trim($text) : '';
    if ($text === '') {
        return null;
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $decoded = json_decode((string) $m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
        $decoded = json_decode((string) $m[1], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

/**
 * Best-effort extraction of one field value from JSON-like text when full JSON is invalid.
 *
 * @param string $text
 * @param string $field_key
 * @return string
 */
function matrix_export_ai_extract_field_from_jsonish_text($text, $field_key) {
    $text = is_string($text) ? $text : '';
    $field_key = is_string($field_key) ? $field_key : '';
    if ($text === '' || $field_key === '') {
        return '';
    }
    $quoted_key = preg_quote($field_key, '/');
    $raw = '';
    if (preg_match('/"' . $quoted_key . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $text, $m)) {
        $raw = (string) $m[1];
    } elseif (preg_match('/"' . $quoted_key . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)$/s', $text, $m)) {
        // Tolerate truncated model output where the closing quote was cut off.
        $raw = (string) $m[1];
    } else {
        return '';
    }
    $decoded = json_decode('"' . $raw . '"');
    if (is_string($decoded)) {
        return trim($decoded);
    }
    return trim(stripcslashes($raw));
}

/**
 * Best-effort extraction of the first quoted JSON-ish value.
 *
 * @param string $text
 * @return string
 */
function matrix_export_ai_extract_first_jsonish_value($text) {
    $text = is_string($text) ? $text : '';
    if ($text === '') {
        return '';
    }
    if (preg_match('/"[A-Za-z0-9_\-]+"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $text, $m) ||
        preg_match('/"[A-Za-z0-9_\-]+"\s*:\s*"((?:\\\\.|[^"\\\\])*)$/s', $text, $m)) {
        $raw = isset($m[1]) ? (string) $m[1] : '';
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode('"' . $raw . '"');
        if (is_string($decoded)) {
            return trim($decoded);
        }
        return trim(stripcslashes($raw));
    }
    return '';
}

/**
 * Call OpenAI Chat Completions.
 *
 * @param array{provider:string,model:string,openai_api_key:string,gemini_api_key:string} $settings
 * @param string $prompt
 * @return string|WP_Error
 */
function matrix_export_ai_call_openai(array $settings, $prompt) {
    $api_key = isset($settings['openai_api_key']) ? (string) $settings['openai_api_key'] : '';
    if ($api_key === '') {
        return new WP_Error('missing_key', 'OpenAI API key is missing.');
    }
    $model = isset($settings['model']) ? (string) $settings['model'] : '';
    if ($model === '' || stripos($model, 'gemini') !== false) {
        $model = 'gpt-5-mini';
    }
    $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 45,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode([
            'model' => $model,
            'temperature' => 0.7,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a website content assistant. Output only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => (string) $prompt,
                ],
            ],
        ]),
    ]);
    if (is_wp_error($resp)) {
        return $resp;
    }
    $status = (int) wp_remote_retrieve_response_code($resp);
    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
    if ($status >= 400) {
        $err_msg = '';
        if (is_array($body) && isset($body['error']['message']) && is_string($body['error']['message'])) {
            $err_msg = trim($body['error']['message']);
        }
        if ($err_msg === '') {
            $err_msg = 'OpenAI request failed (HTTP ' . $status . ').';
        }
        return new WP_Error('openai_http_error', $err_msg);
    }
    if (is_array($body) && isset($body['error']['message']) && is_string($body['error']['message']) && trim($body['error']['message']) !== '') {
        return new WP_Error('openai_api_error', trim($body['error']['message']));
    }
    $text = isset($body['choices'][0]['message']['content']) ? (string) $body['choices'][0]['message']['content'] : '';
    if ($text === '') {
        return new WP_Error('empty_response', 'OpenAI returned an empty response.');
    }
    return $text;
}

/**
 * Call configured provider and return generated text.
 *
 * @param array{provider:string,model:string,openai_api_key:string,gemini_api_key:string} $settings
 * @param string $prompt
 * @return string|WP_Error
 */
function matrix_export_ai_call_provider(array $settings, $prompt) {
    $provider = isset($settings['provider']) ? (string) $settings['provider'] : 'openai';
    $model = isset($settings['model']) ? (string) $settings['model'] : '';
    $prompt = (string) $prompt;
    $is_connection_error = static function ($error_or_message) {
        $msg = is_wp_error($error_or_message) ? (string) $error_or_message->get_error_message() : (string) $error_or_message;
        $msg = strtolower($msg);
        return (
            strpos($msg, 'curl error 7') !== false ||
            strpos($msg, "couldn't connect to server") !== false ||
            strpos($msg, 'failed to connect') !== false ||
            strpos($msg, 'timed out') !== false ||
            strpos($msg, 'network is unreachable') !== false ||
            strpos($msg, 'could not resolve host') !== false
        );
    };
    if ($provider === 'gemini') {
        $api_key = isset($settings['gemini_api_key']) ? (string) $settings['gemini_api_key'] : '';
        if ($api_key === '') {
            return new WP_Error('missing_key', 'Gemini API key is missing.');
        }
        $model = preg_replace('#^models/#i', '', trim((string) $model));
        if ($model === '') {
            $model = 'gemini-3-flash';
        }
        $request_payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.35,
                'maxOutputTokens' => 2400,
            ],
        ];
        $request_gemini = function ($model_id, $api_version) use ($api_key, $request_payload) {
            $url = 'https://generativelanguage.googleapis.com/' . $api_version . '/models/' . rawurlencode($model_id) . ':generateContent?key=' . rawurlencode($api_key);
            $resp = null;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $resp = wp_remote_post($url, [
                    'timeout' => 45,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode($request_payload),
                ]);
                if (!is_wp_error($resp)) {
                    break;
                }
                $msg = strtolower((string) $resp->get_error_message());
                $retryable = (
                    strpos($msg, 'curl error 7') !== false ||
                    strpos($msg, "couldn't connect to server") !== false ||
                    strpos($msg, 'failed to connect') !== false ||
                    strpos($msg, 'timed out') !== false
                );
                if (!$retryable || $attempt === 1) {
                    break;
                }
                usleep(200000);
            }
            if (is_wp_error($resp)) {
                return $resp;
            }
            $status = (int) wp_remote_retrieve_response_code($resp);
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            if ($status >= 400) {
                $err_msg = '';
                if (is_array($body) && isset($body['error']['message']) && is_string($body['error']['message'])) {
                    $err_msg = trim($body['error']['message']);
                }
                if ($err_msg === '') {
                    $err_msg = 'Gemini request failed (HTTP ' . $status . ').';
                }
                return new WP_Error('gemini_http_error', $err_msg);
            }
            if (is_array($body) && isset($body['error']['message']) && is_string($body['error']['message']) && trim($body['error']['message']) !== '') {
                return new WP_Error('gemini_api_error', trim($body['error']['message']));
            }
            $text = '';
            if (isset($body['candidates'][0]['content']['parts']) && is_array($body['candidates'][0]['content']['parts'])) {
                foreach ($body['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['text']) && is_string($part['text'])) {
                        $text .= $part['text'];
                    }
                }
            }
            if ($text === '' && isset($body['candidates'][0]['output']) && is_string($body['candidates'][0]['output'])) {
                $text = $body['candidates'][0]['output'];
            }
            if ($text === '') {
                return new WP_Error('empty_response', 'Gemini returned an empty response.');
            }
            return $text;
        };

        $versions = ['v1', 'v1beta'];
        $last_error = null;
        foreach ($versions as $version) {
            $result = $request_gemini($model, $version);
            if (!is_wp_error($result)) {
                return $result;
            }
            $last_error = $result;
            $msg = strtolower($result->get_error_message());
            if (strpos($msg, 'not found') === false && strpos($msg, 'not supported') === false) {
                if ($is_connection_error($result) && !empty($settings['openai_api_key'])) {
                    $openai_settings = $settings;
                    $openai_settings['provider'] = 'openai';
                    if (empty($openai_settings['model']) || stripos((string) $openai_settings['model'], 'gemini') !== false) {
                        $openai_settings['model'] = 'gpt-5-mini';
                    }
                    return matrix_export_ai_call_openai($openai_settings, $prompt);
                }
                return $result;
            }
        }

        $fallback_models = ['gemini-3-flash', 'gemini-3.1-flash-lite', 'gemini-2.5-flash'];
        foreach ($fallback_models as $fallback_model) {
            if ($fallback_model === $model) {
                continue;
            }
            foreach ($versions as $version) {
                $result = $request_gemini($fallback_model, $version);
                if (!is_wp_error($result)) {
                    return $result;
                }
                $last_error = $result;
            }
        }
        if (is_wp_error($last_error) && $is_connection_error($last_error) && !empty($settings['openai_api_key'])) {
            $openai_settings = $settings;
            $openai_settings['provider'] = 'openai';
            $openai_settings['model'] = 'gpt-5-mini';
            return matrix_export_ai_call_openai($openai_settings, $prompt);
        }
        return new WP_Error('gemini_model_unavailable', 'Selected Gemini model is unavailable. Try gemini-3-flash or gemini-3.1-flash-lite.');
    }
    return matrix_export_ai_call_openai($settings, $prompt);
}

/**
 * AJAX: generate AI suggestions for one form block.
 */
function matrix_export_ajax_ai_generate_block() {
    if (!is_user_logged_in() || !matrix_export_user_can_access_client_form()) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if (!isset($_POST['matrix_ai_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_ai_nonce'])), 'matrix_export_ai_generate_block')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }
    $settings = matrix_export_get_ai_settings();
    if (empty($settings['enabled'])) {
        wp_send_json_error(['message' => 'AI mode is disabled.'], 400);
    }
    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $block_source = isset($_POST['block_source']) ? sanitize_text_field(wp_unslash($_POST['block_source'])) : '';
    $block_index = isset($_POST['block_index']) ? (int) $_POST['block_index'] : -1;
    $block_type = isset($_POST['block_type']) ? sanitize_text_field(wp_unslash($_POST['block_type'])) : '';
    $instructions = isset($_POST['instructions']) ? sanitize_textarea_field(wp_unslash($_POST['instructions'])) : '';
    $bullets = isset($_POST['bullets']) ? sanitize_textarea_field(wp_unslash($_POST['bullets'])) : '';
    $fields_json = isset($_POST['fields_json']) ? wp_unslash((string) $_POST['fields_json']) : '';

    $allowed_posts = Matrix_Export::get_client_link_post_ids($token);
    if ($post_id <= 0 || empty($allowed_posts) || !in_array($post_id, array_map('intval', $allowed_posts), true)) {
        wp_send_json_error(['message' => 'Invalid token or page context.'], 403);
    }
    $fields = json_decode($fields_json, true);
    if (!is_array($fields) || empty($fields)) {
        wp_send_json_error(['message' => 'No eligible text fields found in this block.'], 400);
    }
    $field_lines = [];
    $allowed_field_keys = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = isset($field['key']) ? sanitize_text_field((string) $field['key']) : '';
        $label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $key;
        $value = isset($field['value']) ? (string) $field['value'] : '';
        if ($key === '') {
            continue;
        }
        $allowed_field_keys[$key] = true;
        $field_lines[] = 'Field: ' . $key . ' (' . $label . ")\n<<<BEGIN_FIELD>>>\n" . $value . "\n<<<END_FIELD>>>";
    }
    if (empty($field_lines)) {
        wp_send_json_error(['message' => 'No valid fields were provided for generation.'], 400);
    }

    $prompt = "Generate improved website copy for one content block.\n";
    $prompt .= "Return ONLY JSON in this exact shape: {\"fields\":{\"field_key\":\"new value\"}}\n";
    $prompt .= "Rules:\n";
    $prompt .= "- Only include field keys that were provided.\n";
    $prompt .= "- Keep brand-safe and professional tone.\n";
    $prompt .= "- Do NOT summarize unless explicitly instructed.\n";
    $prompt .= "- Keep roughly the same depth and amount of content as the current field.\n";
    $prompt .= "- Preserve meaningful structure (headings, paragraphs, bullets/lists).\n";
    $prompt .= "- If the current value is HTML, return HTML (not Markdown).\n";
    $prompt .= "- Preserve intent and avoid adding unsupported claims.\n";
    $prompt .= "- If instructions conflict with existing content, prioritize instructions.\n";
    $prompt .= "Context:\n";
    $prompt .= "post_id: " . $post_id . "\n";
    $prompt .= "block_source: " . $block_source . "\n";
    $prompt .= "block_index: " . $block_index . "\n";
    $prompt .= "block_type: " . $block_type . "\n";
    $prompt .= "Instructions:\n" . ($instructions !== '' ? $instructions : '(none)') . "\n";
    $prompt .= "Bullet points:\n" . ($bullets !== '' ? $bullets : '(none)') . "\n";
    $prompt .= "Current fields:\n" . implode("\n", $field_lines) . "\n";

    $generated = matrix_export_ai_call_provider($settings, $prompt);
    if (is_wp_error($generated)) {
        wp_send_json_error(['message' => $generated->get_error_message()], 500);
    }
    $generated_text = trim((string) $generated);
    $json = matrix_export_ai_extract_json_object((string) $generated);
    if (!is_array($json) || !isset($json['fields']) || !is_array($json['fields'])) {
        $looks_like_json_payload = (
            strpos($generated_text, '{') !== false ||
            strpos($generated_text, '"fields"') !== false ||
            strpos($generated_text, '"post_content"') !== false
        );
        if ($looks_like_json_payload && !empty($allowed_field_keys)) {
            foreach (array_keys($allowed_field_keys) as $k) {
                $candidate = matrix_export_ai_extract_field_from_jsonish_text($generated_text, (string) $k);
                if ($candidate !== '') {
                    wp_send_json_success([
                        'suggestions' => [(string) $k => $candidate],
                        'provider' => $settings['provider'],
                        'model' => $settings['model'],
                        'fallback' => 'jsonish_field_extract',
                    ]);
                }
            }
            // If key extraction fails, still salvage first value for single-field requests.
            $first_candidate = matrix_export_ai_extract_first_jsonish_value($generated_text);
            if ($first_candidate !== '') {
                $single_key = (string) array_key_first($allowed_field_keys);
                wp_send_json_success([
                    'suggestions' => [$single_key => $first_candidate],
                    'provider' => $settings['provider'],
                    'model' => $settings['model'],
                    'fallback' => 'jsonish_first_value',
                ]);
            }
            wp_send_json_error(['message' => 'AI returned incomplete JSON. Try again, or add shorter instructions.'], 500);
        }
        // Tolerate plain-text responses by mapping text to the first requested field.
        if (!empty($allowed_field_keys) && $generated_text !== '') {
            $single_key = (string) array_key_first($allowed_field_keys);
            if (preg_match('/```(?:[a-zA-Z0-9_-]+)?\s*(.*?)\s*```/s', $generated_text, $m)) {
                $generated_text = trim((string) $m[1]);
            }
            if ($generated_text === '') {
                wp_send_json_error(['message' => 'AI returned empty output. Try again or switch model.'], 500);
            }
            wp_send_json_success([
                'suggestions' => [$single_key => $generated_text],
                'provider' => $settings['provider'],
                'model' => $settings['model'],
                'fallback' => 'plain_text',
            ]);
        }
        wp_send_json_error(['message' => 'AI response was not valid JSON. Try again.'], 500);
    }
    $suggestions = [];
    foreach ($json['fields'] as $key => $value) {
        $key = sanitize_text_field((string) $key);
        if ($key === '' || !isset($allowed_field_keys[$key])) {
            continue;
        }
        $suggestions[$key] = is_string($value) ? trim($value) : trim((string) $value);
    }
    if (empty($suggestions)) {
        wp_send_json_error(['message' => 'AI did not return any usable field suggestions.'], 500);
    }
    wp_send_json_success([
        'suggestions' => $suggestions,
        'provider' => $settings['provider'],
        'model' => $settings['model'],
    ]);
}

/**
 * AJAX: save strict mode rule for a specific form field instance.
 */
function matrix_export_ajax_save_strict_rule() {
    if (!is_user_logged_in() || !matrix_export_user_can_access_client_form()) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
    if (!isset($_POST['matrix_strict_rule_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_strict_rule_nonce'])), 'matrix_export_save_strict_rule')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }
    $strict_settings = matrix_export_get_strict_settings();
    $super_admin_user_id = isset($strict_settings['super_admin_user_id']) ? (int) $strict_settings['super_admin_user_id'] : 0;
    $current_user_id = (int) get_current_user_id();
    $can_manage = false;
    if ($super_admin_user_id > 0) {
        $can_manage = ($current_user_id === $super_admin_user_id);
    } else {
        $can_manage = current_user_can('manage_options');
    }
    if (!$can_manage) {
        wp_send_json_error(['message' => 'Only the configured super admin can edit strict field rules.'], 403);
    }

    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    $entry = Matrix_Export::get_client_link_entry($token, true);
    if (empty($entry) || empty($entry['strict_mode'])) {
        wp_send_json_error(['message' => 'Strict mode is not enabled for this form.'], 400);
    }
    $rule_key = isset($_POST['rule_key']) ? sanitize_text_field(wp_unslash($_POST['rule_key'])) : '';
    if ($rule_key === '' || !preg_match('/^\d+::[A-Za-z0-9_\-]+$/', $rule_key)) {
        wp_send_json_error(['message' => 'Invalid field rule key.'], 400);
    }
    $rule = [
        'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        'enable_ai_mode' => array_key_exists('enable_ai_mode', $_POST) ? (!empty($_POST['enable_ai_mode']) ? 1 : 0) : 1,
        'enable_required' => !empty($_POST['enable_required']) ? 1 : 0,
        'enable_min_words' => !empty($_POST['enable_min_words']) ? 1 : 0,
        'enable_max_words' => !empty($_POST['enable_max_words']) ? 1 : 0,
        'enable_min_chars' => !empty($_POST['enable_min_chars']) ? 1 : 0,
        'enable_max_chars' => !empty($_POST['enable_max_chars']) ? 1 : 0,
        'min_words' => isset($_POST['min_words']) ? max(0, (int) $_POST['min_words']) : 0,
        'max_words' => isset($_POST['max_words']) ? max(0, (int) $_POST['max_words']) : 0,
        'min_chars' => isset($_POST['min_chars']) ? max(0, (int) $_POST['min_chars']) : 0,
        'max_chars' => isset($_POST['max_chars']) ? max(0, (int) $_POST['max_chars']) : 0,
    ];
    if ($rule['max_words'] > 0 && $rule['min_words'] > 0 && $rule['max_words'] < $rule['min_words']) {
        wp_send_json_error(['message' => 'Max words must be greater than or equal to min words.'], 400);
    }
    if ($rule['max_chars'] > 0 && $rule['min_chars'] > 0 && $rule['max_chars'] < $rule['min_chars']) {
        wp_send_json_error(['message' => 'Max characters must be greater than or equal to min characters.'], 400);
    }
    $ok = Matrix_Export::update_client_link_strict_field_rule($token, $rule_key, $rule);
    if (!$ok) {
        wp_send_json_error(['message' => 'Could not save strict rule.'], 500);
    }
    wp_send_json_success(['rule_key' => $rule_key, 'rule' => $rule]);
}

/**
 * Get pending moderated submissions keyed by submission ID.
 *
 * @return array<string, array<string,mixed>>
 */
function matrix_export_get_pending_reviews() {
    $reviews = get_option(MATRIX_EXPORT_PENDING_REVIEWS_OPTION, []);
    return is_array($reviews) ? $reviews : [];
}

/**
 * Persist pending moderated submissions.
 *
 * @param array<string, array<string,mixed>> $reviews
 * @return void
 */
function matrix_export_save_pending_reviews(array $reviews) {
    update_option(MATRIX_EXPORT_PENDING_REVIEWS_OPTION, $reviews, false);
}

/**
 * Remove pending submissions for a specific token.
 *
 * @param string $token
 * @return int number removed
 */
function matrix_export_remove_pending_reviews_for_token($token) {
    $token = sanitize_text_field((string) $token);
    if ($token === '') {
        return 0;
    }
    $reviews = matrix_export_get_pending_reviews();
    $removed = 0;
    foreach ($reviews as $id => $entry) {
        $entry_token = isset($entry['token']) ? (string) $entry['token'] : '';
        if ($entry_token !== $token) {
            continue;
        }
        unset($reviews[$id]);
        $removed++;
    }
    if ($removed > 0) {
        matrix_export_save_pending_reviews($reviews);
    }
    return $removed;
}

/**
 * Queue a moderated submission for admin approval instead of publishing immediately.
 *
 * @param string $token
 * @param array $post_data
 * @return array{success: bool, message: string}
 */
function matrix_export_queue_pending_review($token, array $post_data) {
    $token = sanitize_text_field((string) $token);
    if ($token === '') {
        return ['success' => false, 'message' => 'Missing token.'];
    }
    $entry = Matrix_Export::get_client_link_entry($token);
    if (empty($entry)) {
        return ['success' => false, 'message' => 'Invalid or expired link.'];
    }
    $clean = wp_unslash($post_data);
    $clean['matrix_form_token'] = $token;
    $clean['matrix_submit_mode'] = 'publish';
    $clean['matrix_form_submit'] = '1';
    unset($clean['matrix_export_nonce'], $clean['matrix_import_nonce']);

    $post_ids = Matrix_Export::get_client_link_post_ids($token);
    $reviews = matrix_export_get_pending_reviews();
    $submission_id = substr(bin2hex(random_bytes(16)), 0, 24);
    $reviews[$submission_id] = [
        'submission_id' => $submission_id,
        'token' => $token,
        'post_ids' => array_values(array_unique(array_map('intval', $post_ids))),
        'created_at' => time(),
        'created_by' => (int) get_current_user_id(),
        'created_by_email' => (function_exists('wp_get_current_user') && wp_get_current_user()) ? (string) wp_get_current_user()->user_email : '',
        'status' => 'pending',
        'post_data' => $clean,
    ];
    matrix_export_save_pending_reviews($reviews);
    matrix_export_send_pending_review_notification($reviews[$submission_id]);

    return ['success' => true, 'message' => 'Submission sent for review.'];
}

/**
 * Approve and publish a pending moderated submission.
 *
 * @param string $submission_id
 * @return array{success: bool, message: string}
 */
function matrix_export_approve_pending_review($submission_id, $review_note = '') {
    $submission_id = sanitize_text_field((string) $submission_id);
    $review_note = sanitize_text_field((string) $review_note);
    $reviews = matrix_export_get_pending_reviews();
    if ($submission_id === '' || empty($reviews[$submission_id]) || !is_array($reviews[$submission_id])) {
        return ['success' => false, 'message' => 'Submission not found.'];
    }
    $submission = $reviews[$submission_id];
    $post_data = isset($submission['post_data']) && is_array($submission['post_data']) ? $submission['post_data'] : [];
    if (empty($post_data)) {
        return ['success' => false, 'message' => 'Submission payload missing.'];
    }
    $result = Matrix_Import::handle_form_submit($post_data, [], 'publish');
    if (empty($result['success'])) {
        $msg = isset($result['message']) ? (string) $result['message'] : 'Failed to publish pending submission.';
        return ['success' => false, 'message' => $msg];
    }
    matrix_export_send_review_decision_notification($submission, 'approved', $review_note);
    unset($reviews[$submission_id]);
    matrix_export_save_pending_reviews($reviews);
    return ['success' => true, 'message' => 'Submission approved and published.'];
}

/**
 * Reject and remove a pending moderated submission.
 *
 * @param string $submission_id
 * @return array{success: bool, message: string}
 */
function matrix_export_reject_pending_review($submission_id, $review_note = '') {
    $submission_id = sanitize_text_field((string) $submission_id);
    $review_note = sanitize_text_field((string) $review_note);
    $reviews = matrix_export_get_pending_reviews();
    if ($submission_id === '' || empty($reviews[$submission_id]) || !is_array($reviews[$submission_id])) {
        return ['success' => false, 'message' => 'Submission not found.'];
    }
    $submission = $reviews[$submission_id];
    matrix_export_send_review_decision_notification($submission, 'rejected', $review_note);
    unset($reviews[$submission_id]);
    matrix_export_save_pending_reviews($reviews);
    return ['success' => true, 'message' => $review_note !== '' ? ('Submission rejected. Note: ' . $review_note) : 'Submission rejected.'];
}

/**
 * Notify admin when moderated content is submitted.
 *
 * @param array<string,mixed> $submission
 * @return void
 */
function matrix_export_send_pending_review_notification(array $submission) {
    $to = sanitize_email((string) get_option(MATRIX_EXPORT_REVIEW_NOTIFY_EMAIL_OPTION, ''));
    if ($to === '' || !is_email($to)) {
        $to = sanitize_email((string) get_option(MATRIX_EXPORT_NOTIFY_EMAIL_OPTION, ''));
    }
    if ($to === '' || !is_email($to)) {
        $strict_settings = matrix_export_get_strict_settings();
        $admin_user_id = isset($strict_settings['super_admin_user_id']) ? (int) $strict_settings['super_admin_user_id'] : 0;
        if ($admin_user_id > 0) {
            $admin_user = get_userdata($admin_user_id);
            if ($admin_user && !empty($admin_user->user_email)) {
                $to = sanitize_email((string) $admin_user->user_email);
            }
        }
    }
    if ($to === '' || !is_email($to)) {
        return;
    }
    $token = isset($submission['token']) ? (string) $submission['token'] : '';
    $submission_id = isset($submission['submission_id']) ? (string) $submission['submission_id'] : '';
    $post_ids = isset($submission['post_ids']) && is_array($submission['post_ids']) ? array_map('intval', $submission['post_ids']) : [];
    $titles = [];
    foreach ($post_ids as $pid) {
        $title = get_the_title($pid);
        $titles[] = (is_string($title) && $title !== '') ? $title : ('Post #' . $pid);
    }
    $review_url = admin_url('tools.php?page=matrix-content-export');
    $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Content approval required';
    $body = "A client submission is pending review.\n\n";
    $body .= 'Submitted: ' . wp_date('Y-m-d H:i:s') . "\n";
    if ($submission_id !== '') {
        $body .= 'Submission ID: ' . $submission_id . "\n";
    }
    if ($token !== '') {
        $body .= 'Token: ' . $token . "\n";
    }
    if (!empty($titles)) {
        $body .= 'Pages/Posts: ' . implode(', ', $titles) . "\n";
    }
    $body .= 'Review queue: ' . $review_url . "\n";
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

/**
 * Notify the submitter when moderation decision is made.
 *
 * @param array<string,mixed> $submission
 * @param string $decision approved|rejected
 * @param string $note
 * @return void
 */
function matrix_export_send_review_decision_notification(array $submission, $decision, $note = '') {
    $to = isset($submission['created_by_email']) ? sanitize_email((string) $submission['created_by_email']) : '';
    if ($to === '' || !is_email($to)) {
        return;
    }
    $decision = ($decision === 'approved') ? 'approved' : 'rejected';
    $token = isset($submission['token']) ? (string) $submission['token'] : '';
    $post_ids = isset($submission['post_ids']) && is_array($submission['post_ids']) ? array_map('intval', $submission['post_ids']) : [];
    $titles = [];
    foreach ($post_ids as $pid) {
        $title = get_the_title($pid);
        $titles[] = (is_string($title) && $title !== '') ? $title : ('Post #' . $pid);
    }
    $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] Submission ' . $decision;
    $body = "Your content submission has been " . $decision . ".\n\n";
    if ($token !== '') {
        $body .= 'Token: ' . $token . "\n";
    }
    if (!empty($titles)) {
        $body .= 'Pages/Posts: ' . implode(', ', $titles) . "\n";
    }
    if (is_string($note) && $note !== '') {
        $body .= 'Reviewer note: ' . $note . "\n";
    }
    if ($token !== '') {
        $body .= 'Open form: ' . Matrix_Export::get_client_link_url($token) . "\n";
    }
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

/**
 * Email notification when a page/post is duplicated from the client form.
 *
 * @param int $source_post_id
 * @param int $new_post_id
 * @param string $token
 * @param string $form_url
 * @param string $view_url
 * @return void
 */
function matrix_export_send_duplicate_notification_email($source_post_id, $new_post_id, $token, $form_url, $view_url) {
    $option_key = defined('MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION') ? MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION : '';
    if ($option_key === '') {
        return;
    }
    $to = sanitize_email((string) get_option($option_key, ''));
    if ($to === '' || !is_email($to)) {
        return;
    }
    $source_post_id = (int) $source_post_id;
    $new_post_id = (int) $new_post_id;
    if ($source_post_id <= 0 || $new_post_id <= 0) {
        return;
    }

    $source_title = get_the_title($source_post_id);
    if (!is_string($source_title) || $source_title === '') {
        $source_title = 'Post #' . $source_post_id;
    }
    $new_title = get_the_title($new_post_id);
    if (!is_string($new_title) || $new_title === '') {
        $new_title = 'Post #' . $new_post_id;
    }
    $duplicator = '';
    if (function_exists('wp_get_current_user')) {
        $user = wp_get_current_user();
        if ($user && !empty($user->user_email)) {
            $duplicator = (string) $user->user_email;
        }
    }
    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = sprintf('[%s] Client duplicated a page', $site_name);
    $body = "A page/post was duplicated from the content editing form.\n\n";
    $body .= 'Date: ' . wp_date('Y-m-d H:i:s') . "\n";
    $body .= 'Site: ' . home_url('/') . "\n";
    if ($duplicator !== '') {
        $body .= 'Duplicated by: ' . $duplicator . "\n";
    }
    $body .= 'Original: ' . $source_title . ' (ID ' . $source_post_id . ')' . "\n";
    $body .= 'Duplicate: ' . $new_title . ' (ID ' . $new_post_id . ')' . "\n";
    if (is_string($token) && $token !== '') {
        $body .= 'Token: ' . $token . "\n";
    }
    if (is_string($form_url) && $form_url !== '') {
        $body .= 'Open in editable form: ' . $form_url . "\n";
    }
    if (is_string($view_url) && $view_url !== '') {
        $body .= 'View duplicate page: ' . $view_url . "\n";
    }
    $edit_url = get_edit_post_link($new_post_id, '');
    if (is_string($edit_url) && $edit_url !== '') {
        $body .= 'WP Admin edit link: ' . $edit_url . "\n";
    }
    wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

/**
 * Handle client form POST (editable HTML form submitted back to site). No admin login required; token required.
 */
function matrix_export_handle_client_form_submit() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['matrix_form_submit']) || empty($_POST['matrix_form_token'])) {
        return;
    }
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (!matrix_export_user_can_access_client_form()) {
        wp_die(
            esc_html__('You do not have permission to edit this content.', 'matrix-content-export'),
            esc_html__('Access denied', 'matrix-content-export'),
            ['response' => 403]
        );
    }
    $active_page = isset($_POST['matrix_active_page']) ? (int) $_POST['matrix_active_page'] : 0;
    $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
    $submit_mode = isset($_POST['matrix_submit_mode']) ? sanitize_key(wp_unslash($_POST['matrix_submit_mode'])) : 'publish';
    if ($submit_mode !== 'draft' && $submit_mode !== 'later') {
        $submit_mode = 'publish';
    }
    if ($submit_mode === 'draft') {
        $submit_mode = 'later';
    }
    $entry = Matrix_Export::get_client_link_entry($token);
    $requires_approval = !empty($entry['requires_approval']);
    if ($submit_mode === 'publish' && $requires_approval) {
        $queued = matrix_export_queue_pending_review($token, $_POST);
        if (!$queued['success']) {
            wp_die(esc_html($queued['message']), 'Content form', ['response' => 400, 'back_link' => true]);
        }
        $redirect = home_url('/' . MATRIX_EXPORT_CLIENT_FORM_SLUG . '/');
        $redirect = add_query_arg('matrix_form_saved', '1', $redirect);
        $redirect = add_query_arg('matrix_form_saved_mode', 'pending', $redirect);
        $redirect = add_query_arg('matrix_token', rawurlencode($token), $redirect);
        if ($active_page > 0) {
            $redirect = add_query_arg('matrix_page', $active_page, $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }
    $result = Matrix_Import::handle_form_submit($_POST, isset($_FILES) ? $_FILES : [], $submit_mode);
    if ($result['success'] && !empty($result['redirect'])) {
        $redirect = $result['redirect'];
        $token = isset($_POST['matrix_form_token']) ? sanitize_text_field(wp_unslash($_POST['matrix_form_token'])) : '';
        if ($token !== '') {
            $redirect = home_url('/' . MATRIX_EXPORT_CLIENT_FORM_SLUG . '/');
            $redirect = add_query_arg('matrix_form_saved', '1', $redirect);
            $redirect = add_query_arg('matrix_form_saved_mode', $submit_mode, $redirect);
            $redirect = add_query_arg('matrix_token', rawurlencode($token), $redirect);
            if ($active_page > 0) {
                $redirect = add_query_arg('matrix_page', $active_page, $redirect);
            }
        }
        wp_safe_redirect($redirect);
        exit;
    }
    if (!$result['success']) {
        wp_die(esc_html($result['message']), 'Content form', ['response' => 400, 'back_link' => true]);
    }
}

/**
 * Add Content status column to Pages and Posts list.
 */
function matrix_export_add_status_column($columns) {
    $insert = ['matrix_content_status' => __('Content status', 'matrix-content-export')];
    $keys = array_keys($columns);
    $title_pos = array_search('title', $keys, true);
    if ($title_pos !== false && $title_pos < count($keys) - 1) {
        return array_merge(array_slice($columns, 0, $title_pos + 1), $insert, array_slice($columns, $title_pos + 1));
    }
    return array_merge($insert, $columns);
}

/**
 * Output content status in list table.
 */
function matrix_export_show_status_column($column_name, $post_id) {
    if ($column_name !== 'matrix_content_status') {
        return;
    }
    $status = get_post_meta($post_id, MATRIX_EXPORT_STATUS_META_KEY, true);
    if (!in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
        $status = 'todo';
    }
    $labels = [
        'todo' => __('To do', 'matrix-content-export'),
        'inprogress' => __('In progress', 'matrix-content-export'),
        'done' => __('Done', 'matrix-content-export'),
        'delete' => __('Delete', 'matrix-content-export'),
    ];
    $label = isset($labels[$status]) ? $labels[$status] : $labels['todo'];
    $class = 'matrix-status matrix-status-' . $status;
    echo '<span class="' . esc_attr($class) . '" aria-label="' . esc_attr($label) . '">' . esc_html($label) . '</span>';
    if ($status === 'done') {
        $done_by = get_post_meta($post_id, MATRIX_EXPORT_STATUS_DONE_BY_META_KEY, true);
        if ($done_by !== '') {
            echo '<br><span class="matrix-status-done-by">' . esc_html(__('by', 'matrix-content-export') . ' ' . $done_by) . '</span>';
        }
    }
}

/**
 * Inline styles for Content status column.
 */
function matrix_export_status_column_styles() {
    echo '<style>
    .matrix-status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
    .matrix-status-todo { background: #fff3cd; color: #856404; }
    .matrix-status-inprogress { background: #cce5ff; color: #004085; }
    .matrix-status-done { background: #d4edda; color: #155724; }
    .matrix-status-delete { background: #f8d7da; color: #721c24; }
    .matrix-status-done-by { display: block; margin-top: 2px; font-size: 11px; color: #50575e; }
    </style>';
}

function matrix_export_form_saved_notice() {
    if (!isset($_GET['matrix_form_saved']) || $_GET['matrix_form_saved'] !== '1') {
        return;
    }
    $saved_mode = isset($_GET['matrix_form_saved_mode']) ? sanitize_key(wp_unslash($_GET['matrix_form_saved_mode'])) : 'publish';
    if ($saved_mode === 'pending') {
        $notice_text = 'Submitted for approval. Changes will be published after admin review.';
    } else {
        $notice_text = ($saved_mode === 'later' || $saved_mode === 'draft')
            ? 'Form saved for later editing. Changes were not published.'
            : 'Content saved successfully. You can close this page.';
    }
    echo '<div id="matrix-form-saved-notice" style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#00a32a;color:#fff;padding:12px 24px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15);z-index:999999;font-size:14px;">' . esc_html($notice_text) . '</div>';
    echo '<script>setTimeout(function(){ var e=document.getElementById("matrix-form-saved-notice"); if(e) e.remove(); }, 5000);</script>';
}

/**
 * If URL hash doesn't match an element id, fall back to matching [data-matrix-block="<hash>"].
 * This keeps "View Section" links working when themes use non-deterministic section IDs.
 */
function matrix_export_hash_anchor_resolver() {
    if (is_admin()) {
        return;
    }
    ?>
    <script>
    (function () {
      var decodeHash = function () {
        if (!window.location.hash || window.location.hash.length < 2) return '';
        try { return decodeURIComponent(window.location.hash.slice(1)); } catch (e) { return window.location.hash.slice(1); }
      };
      var cssEscape = function (value) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
        return String(value).replace(/["\\]/g, '\\$&');
      };
      var resolveHashTarget = function () {
        var raw = decodeHash();
        if (!raw) return;
        if (document.getElementById(raw)) return;
        var selector = '[data-matrix-block="' + cssEscape(raw) + '"]';
        var target = document.querySelector(selector);
        if (!target) return;
        target.scrollIntoView({ behavior: 'auto', block: 'start' });
      };
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', resolveHashTarget, { once: true });
      } else {
        resolveHashTarget();
      }
      window.addEventListener('load', resolveHashTarget, { once: true });
      window.addEventListener('hashchange', resolveHashTarget);
    })();
    </script>
    <?php
}

/**
 * Check if current request is for the content-editing form (no rewrite rules needed).
 */
function matrix_export_is_content_editing_url() {
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path = trim(parse_url($uri, PHP_URL_PATH), '/');
    $slug = MATRIX_EXPORT_CLIENT_FORM_SLUG;
    return ($path === $slug || $path === $slug . '/' || substr($path, -strlen($slug) - 1) === '/' . $slug);
}

/**
 * Restrict content editing form to trusted logged-in users.
 *
 * Defaults to Administrator + Site Editor roles, with capability fallbacks.
 *
 * @param int $user_id Optional user ID. Defaults to current user.
 * @return bool
 */
function matrix_export_user_can_access_client_form($user_id = 0) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if ($user_id <= 0) {
        return false;
    }

    $user = get_userdata($user_id);
    if (!$user || !isset($user->roles) || !is_array($user->roles)) {
        return false;
    }

    $allowed_roles = apply_filters('matrix_export_client_form_allowed_roles', ['administrator', 'site_editor']);
    $allowed_roles = array_filter(array_map('sanitize_key', (array) $allowed_roles));
    if (!empty(array_intersect($allowed_roles, $user->roles))) {
        return true;
    }

    $allowed_caps = apply_filters('matrix_export_client_form_allowed_capabilities', ['manage_options', 'edit_theme_options']);
    foreach ((array) $allowed_caps as $capability) {
        $capability = sanitize_key((string) $capability);
        if ($capability !== '' && user_can($user_id, $capability)) {
            return true;
        }
    }

    return false;
}

function matrix_export_render_content_editing_form() {
    if (!matrix_export_is_content_editing_url()) {
        return;
    }
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (!matrix_export_user_can_access_client_form()) {
        wp_die(
            esc_html__('You do not have permission to access the content editing form.', 'matrix-content-export'),
            esc_html__('Access denied', 'matrix-content-export'),
            ['response' => 403]
        );
    }
    $token = isset($_GET['matrix_token']) ? sanitize_text_field(wp_unslash($_GET['matrix_token'])) : '';
    $post_ids = Matrix_Export::get_client_link_post_ids($token);
    if (empty($post_ids) || !is_array($post_ids)) {
        $entry_including_expired = Matrix_Export::get_client_link_entry($token, true);
        $is_expired = !empty($entry_including_expired) && Matrix_Export::is_client_link_expired($entry_including_expired);
        $expired_text = '';
        if ($is_expired) {
            $expires_at = isset($entry_including_expired['expires_at']) ? (int) $entry_including_expired['expires_at'] : 0;
            if ($expires_at > 0) {
                $expired_text = ' This link expired on ' . wp_date('M j, Y g:i a', $expires_at) . '.';
            }
        }
        status_header(200);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Content editing</title></head><body style="font-family:sans-serif;max-width:560px;margin:3rem auto;padding:1rem;">';
        echo '<p><strong>Invalid or expired link.</strong>' . esc_html($expired_text) . ' Ask your project team to generate a new client link in <strong>Tools → Content Export</strong>.</p>';
        echo '</body></html>';
        exit;
    }
    $data = Matrix_Export::get_export_data_for_form($post_ids);
    if (empty($data['rows'])) {
        status_header(200);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Content editing</title></head><body style="font-family:sans-serif;max-width:560px;margin:3rem auto;padding:1rem;">';
        echo '<p>No content to edit for the selected pages.</p></body></html>';
        exit;
    }
    global $wp_query;
    $wp_query->is_404 = false;
    status_header(200);
    add_filter('template_include', function () {
        return MATRIX_EXPORT_DIR . 'templates/content-editing-wrapper.php';
    }, 99);
}

function matrix_export_render_page() {
    $import_message = '';
    if (isset($_POST['matrix_import_nonce']) && wp_verify_nonce($_POST['matrix_import_nonce'], 'matrix_import')) {
        $import_message = Matrix_Import::handle_upload();
    }
    $export_error = isset($_GET['matrix_export_error']);
    $client_link_url = '';
    $created_client_token = isset($_GET['matrix_client_token']) ? sanitize_text_field(wp_unslash($_GET['matrix_client_token'])) : '';
    if ($created_client_token !== '') {
        $client_link_url = Matrix_Export::get_client_link_url($created_client_token);
    }
    $show_client_link = isset($_GET['matrix_client_link']);
    $client_links = Matrix_Export::get_client_links();
    $ai_settings = matrix_export_get_ai_settings();
    $runtime_settings = matrix_export_get_runtime_settings();
    $pending_reviews = matrix_export_get_pending_reviews();
    $strict_settings = matrix_export_get_strict_settings();
    uasort($client_links, function ($a, $b) {
        $a_created = isset($a['created_at']) ? (int) $a['created_at'] : 0;
        $b_created = isset($b['created_at']) ? (int) $b['created_at'] : 0;
        return $b_created <=> $a_created;
    });
    uasort($pending_reviews, function ($a, $b) {
        $a_created = isset($a['created_at']) ? (int) $a['created_at'] : 0;
        $b_created = isset($b['created_at']) ? (int) $b['created_at'] : 0;
        return $b_created <=> $a_created;
    });
    $client_link_cleared = isset($_GET['matrix_client_link_cleared']);
    $client_links_cleared_all = isset($_GET['matrix_client_link_cleared_all']);
    include MATRIX_EXPORT_DIR . 'templates/admin-page.php';
}
