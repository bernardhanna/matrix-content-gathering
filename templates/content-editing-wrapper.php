<?php
/**
 * Template for /content-editing when loaded inside the theme (so wp_editor works).
 * Loaded via template_include from matrix_export_render_content_editing_form().
 */
if (!defined('ABSPATH')) exit;

// Ensure editor/media assets are available in this custom frontend template.
if (function_exists('wp_enqueue_editor')) {
    wp_enqueue_editor();
}
if (function_exists('wp_enqueue_media')) {
    wp_enqueue_media();
}

$token = isset($_GET['matrix_token']) ? sanitize_text_field(wp_unslash($_GET['matrix_token'])) : '';
$post_ids = Matrix_Export::get_client_link_post_ids($token);
$link_entry = Matrix_Export::get_client_link_entry($token, true);
$data = Matrix_Export::get_export_data_for_form($post_ids);
$draft_state = Matrix_Import::get_client_form_draft_state($token, isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : []);
$data['draft_fields_by_index'] = isset($draft_state['fields_by_index']) ? $draft_state['fields_by_index'] : [];
$data['draft_block_enabled'] = isset($draft_state['block_enabled']) ? $draft_state['block_enabled'] : [];
$data['draft_active_page'] = isset($draft_state['active_page']) ? (int) $draft_state['active_page'] : 0;
$data['draft_saved_at'] = isset($draft_state['saved_at']) ? (int) $draft_state['saved_at'] : 0;
$page_status_from_draft = isset($draft_state['page_status']) && is_array($draft_state['page_status']) ? $draft_state['page_status'] : [];
$page_status = $page_status_from_draft;
if (!empty($post_ids) && defined('MATRIX_EXPORT_STATUS_META_KEY')) {
    foreach ($post_ids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0 || isset($page_status[$pid])) {
            continue;
        }
        $meta = get_post_meta($pid, MATRIX_EXPORT_STATUS_META_KEY, true);
        if (in_array($meta, ['todo', 'inprogress', 'done', 'delete'], true)) {
            $page_status[$pid] = $meta;
        }
    }
}
$data['page_status'] = $page_status;
$data['page_status_done_by'] = isset($draft_state['page_status_done_by']) && is_array($draft_state['page_status_done_by']) ? $draft_state['page_status_done_by'] : [];
$form_action = Matrix_Export::get_client_link_url($token);
$data['matrix_save_status_ajax_url'] = admin_url('admin-ajax.php');
$data['matrix_save_status_nonce'] = wp_create_nonce('matrix_export_save_page_status');
$data['matrix_autosave_draft_ajax_url'] = admin_url('admin-ajax.php');
$data['matrix_autosave_draft_nonce'] = wp_create_nonce('matrix_export_autosave_draft');
$data['matrix_duplicate_page_ajax_url'] = admin_url('admin-ajax.php');
$data['matrix_duplicate_page_nonce'] = wp_create_nonce('matrix_export_duplicate_page');
$data['matrix_ai_generate_ajax_url'] = admin_url('admin-ajax.php');
$data['matrix_ai_generate_nonce'] = wp_create_nonce('matrix_export_ai_generate_block');
$ai_settings = function_exists('matrix_export_get_ai_settings') ? matrix_export_get_ai_settings() : ['enabled' => 0];
$data['matrix_ai_enabled'] = !empty($ai_settings['enabled']);
$data['client_link_expires_at'] = isset($link_entry['expires_at']) ? (int) $link_entry['expires_at'] : 0;
$data['client_link_reminder_days'] = isset($link_entry['reminder_days']) ? (int) $link_entry['reminder_days'] : 0;
$data['client_link_created_at'] = isset($link_entry['created_at']) ? (int) $link_entry['created_at'] : 0;
$data['client_custom_instructions'] = isset($link_entry['custom_instructions']) ? (string) $link_entry['custom_instructions'] : '';
$data['client_requires_approval'] = !empty($link_entry['requires_approval']);
$is_on_site = true;
$in_theme = true;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_bloginfo('name')); ?> - Content Editing</title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('matrix-content-editing-page'); ?>>
<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
}
include MATRIX_EXPORT_DIR . 'templates/export-client-form.php';
wp_footer();
?>
</body>
</html>
