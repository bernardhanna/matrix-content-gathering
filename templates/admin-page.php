<?php
if (!defined('ABSPATH')) exit;

$import_message = isset($import_message) ? $import_message : '';
$export_error   = isset($export_error) ? $export_error : false;

$post_types = Matrix_Export::get_available_post_types();
$all_posts  = Matrix_Export::get_posts_for_selection();
$admin_duplicate_notify_email = get_option(MATRIX_EXPORT_ADMIN_DUPLICATE_NOTIFY_EMAIL_OPTION, '');
$notify_email = get_option(MATRIX_EXPORT_NOTIFY_EMAIL_OPTION, '');
$review_notify_email = get_option(MATRIX_EXPORT_REVIEW_NOTIFY_EMAIL_OPTION, '');
$all_form_field_keys = Matrix_Export::get_all_content_field_keys();
$form_field_post_types_map = Matrix_Export::get_content_field_key_post_types_map();
$disabled_form_field_keys = Matrix_Export::get_disabled_form_fields();
$disabled_form_field_map = array_fill_keys($disabled_form_field_keys, true);
$posts_by_type = [];
foreach ($all_posts as $post) {
    $posts_by_type[ $post->post_type ][] = $post;
}

?>
<div class="wrap matrix-export-wrap">
    <h1>Matrix Content Export / Import</h1>
    <p>Select pages and posts, generate a secure client link, and send that URL to your client so they can edit content directly on the site.</p>

    <?php if ($export_error) : ?>
        <div class="notice notice-warning"><p>Please select at least one page or post to export.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['matrix_export_error']) && $_GET['matrix_export_error'] === 'excel_no_selection') : ?>
        <div class="notice notice-warning"><p>Please select at least one page or post, then click &quot;Download client links workbook (.xlsx)&quot;.</p></div>
    <?php endif; ?>
    <?php if (!empty($client_link_cleared)) : ?>
        <div class="notice notice-success"><p>Client link cleared. Removed <?php echo isset($_GET['matrix_previews_deleted']) ? (int) $_GET['matrix_previews_deleted'] : 0; ?> screenshot(s).</p></div>
    <?php endif; ?>
    <?php if (!empty($client_links_cleared_all)) : ?>
        <div class="notice notice-success"><p>All client links cleared. Removed <?php echo isset($_GET['matrix_previews_deleted']) ? (int) $_GET['matrix_previews_deleted'] : 0; ?> screenshot(s).</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['matrix_previews_queued'])) : ?>
        <?php if ($_GET['matrix_previews_queued'] === '1') : ?>
            <div class="notice notice-info" id="matrix-preview-progress-wrap">
                <p>
                    Screenshot generation started (<?php echo isset($_GET['matrix_previews_tasks']) ? (int) $_GET['matrix_previews_tasks'] : 0; ?> task(s)).
                    <span id="matrix-preview-progress-text">Waiting for progress...</span>
                </p>
                <p style="margin:0.4rem 0 0 0;">
                    <progress id="matrix-preview-progress-bar" value="0" max="100" style="width: 320px;"></progress>
                </p>
                <p style="margin:0.4rem 0 0 0;">
                    Generated: <progress id="matrix-preview-generated-bar" value="0" max="<?php echo isset($_GET['matrix_previews_tasks']) ? (int) $_GET['matrix_previews_tasks'] : 0; ?>" style="width: 320px;"></progress>
                    <span id="matrix-preview-generated-text">0</span>
                </p>
                <p id="matrix-preview-failure-text" style="margin:0.4rem 0 0 0;color:#646970;"></p>
            </div>
        <?php else : ?>
            <?php
            $reason = isset($_GET['matrix_previews_reason']) ? esc_html(wp_unslash($_GET['matrix_previews_reason'])) : '';
            ?>
            <div class="notice notice-warning">
                <p><strong>Screenshot generation is not available in this environment.</strong> <?php echo $reason; ?></p>
                <p class="description" style="margin: 0.5rem 0 0 0;">Section previews are optional. If Node.js is installed on the server but not in the web server&rsquo;s PATH, add to <code>wp-config.php</code>: <code>define('MATRIX_EXPORT_NODE_BINARY', '/path/to/node');</code></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($_GET['matrix_previews_refreshed']) && $_GET['matrix_previews_refreshed'] === '1') :
        $deleted = isset($_GET['matrix_previews_deleted']) ? (int) $_GET['matrix_previews_deleted'] : 0;
        $generated = isset($_GET['matrix_previews_generated']) ? (int) $_GET['matrix_previews_generated'] : 0;
        $tasks = isset($_GET['matrix_previews_tasks']) ? (int) $_GET['matrix_previews_tasks'] : 0;
    ?>
        <div class="notice notice-success"><p>Section screenshots refreshed. Cleared <?php echo (int) $deleted; ?> cached file(s), generated <?php echo (int) $generated; ?> of <?php echo (int) $tasks; ?> screenshot(s).</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['matrix_review_approved'])) : ?>
        <?php $ok = $_GET['matrix_review_approved'] === '1'; ?>
        <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html(isset($_GET['matrix_review_message']) ? wp_unslash((string) $_GET['matrix_review_message']) : ($ok ? 'Submission approved.' : 'Could not approve submission.')); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['matrix_review_rejected'])) : ?>
        <?php $ok = $_GET['matrix_review_rejected'] === '1'; ?>
        <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html(isset($_GET['matrix_review_message']) ? wp_unslash((string) $_GET['matrix_review_message']) : ($ok ? 'Submission rejected.' : 'Could not reject submission.')); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($import_message)) echo $import_message; ?>

    <div class="card" style="max-width: 900px; padding: 1.5rem; margin: 1.5rem 0;">
        <h2 style="margin-top: 0;">1. Choose what to include</h2>

        <form method="post" id="matrix-export-form">
            <?php wp_nonce_field('matrix_export', 'matrix_export_nonce'); ?>
            <input type="hidden" id="matrix-fields-for-posts-url" value="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" />
            <input type="hidden" id="matrix-fields-for-posts-nonce" value="<?php echo esc_attr(wp_create_nonce('matrix_export_fields_for_posts')); ?>" />

            <p><strong>Post types</strong></p>
            <p class="matrix-export-types">
                <?php foreach ($post_types as $pt_key => $pt_label) : ?>
                    <label style="margin-right: 1rem;">
                        <input type="checkbox" name="matrix_export_post_types[]" value="<?php echo esc_attr($pt_key); ?>" class="matrix-export-type-cb" <?php checked(in_array($pt_key, ['page', 'post'], true)); ?> />
                        <?php echo esc_html($pt_label); ?>
                    </label>
                <?php endforeach; ?>
            </p>

            <p><strong>Pages &amp; posts</strong> – select the items to include in the client form.</p>
            <p>
                <button type="button" class="button matrix-select-all">Select all</button>
                <button type="button" class="button matrix-deselect-all">Deselect all</button>
            </p>
            <div class="matrix-export-list" style="max-height: 360px; overflow-y: auto; border: 1px solid #c3c4c7; padding: 10px; background: #fff;">
                <?php foreach ($post_types as $pt_key => $pt_label) :
                    $type_posts = isset($posts_by_type[ $pt_key ]) ? $posts_by_type[ $pt_key ] : [];
                    if (empty($type_posts)) continue;
                ?>
                    <div class="matrix-export-type-group" data-post-type="<?php echo esc_attr($pt_key); ?>">
                        <p style="margin: 0 0 6px 0; font-weight: 600;"><?php echo esc_html($pt_label); ?></p>
                        <ul style="list-style: none; margin: 0 0 1rem 0; padding-left: 0;">
                            <?php foreach ($type_posts as $p) : ?>
                                <li style="margin: 2px 0;">
                                    <label>
                                        <input type="checkbox" name="matrix_export_post_ids[]" value="<?php echo (int) $p->ID; ?>" class="matrix-export-post-cb" data-post-type="<?php echo esc_attr($pt_key); ?>" />
                                        <?php echo esc_html($p->post_title); ?>
                                        <?php if ($p->post_status !== 'publish') echo ' <span style="color:#666;">(' . esc_html($p->post_status) . ')</span>'; ?>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="margin: 12px 0 6px 0;"><strong>Hide fields on the client form</strong></p>
            <input type="hidden" name="matrix_form_fields_settings_present" value="1" />
            <p class="description" style="margin: 0 0 8px 0;">
                Tick a box to <strong>hide</strong> that field in the client editing form. Unticked fields remain editable.
            </p>
            <p style="margin: 0 0 8px;">
                <input type="search" id="matrix-field-visibility-search" placeholder="Search fields..." style="width: 100%; max-width: 520px;" />
            </p>
            <style>
                #matrix-field-visibility-list {
                    max-height: 320px;
                    overflow: auto;
                    border: 1px solid #c3c4c7;
                    padding: 10px;
                    background: #fff;
                    margin-bottom: 10px;
                }
                .matrix-field-visibility-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 8px 10px;
                    align-items: start;
                }
                .matrix-field-visibility-item {
                    display: grid;
                    grid-template-columns: 18px 1fr;
                    gap: 8px;
                    padding: 8px 10px;
                    border: 1px solid #dcdcde;
                    border-radius: 6px;
                    background: #f6f7f7;
                    margin: 0 !important;
                }
                .matrix-field-visibility-item input[type="checkbox"] {
                    margin-top: 2px;
                }
                .matrix-field-visibility-name {
                    font-weight: 600;
                    color: #1d2327;
                    line-height: 1.25;
                }
                .matrix-field-visibility-key {
                    display: block;
                    margin-top: 2px;
                    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                    font-size: 12px;
                    color: #50575e;
                    line-height: 1.25;
                }
            </style>
            <div id="matrix-field-visibility-list">
                <?php if (empty($all_form_field_keys)) : ?>
                    <p class="description" style="margin:0;">No editable fields detected yet.</p>
                <?php else : ?>
                    <div class="matrix-field-visibility-grid">
                    <?php foreach ($all_form_field_keys as $field_key) :
                        $label = Matrix_Export::get_human_field_label($field_key);
                        if (!is_string($label) || $label === '') {
                            $label = $field_key;
                        }
                        $is_disabled = isset($disabled_form_field_map[$field_key]);
                        $field_post_types = isset($form_field_post_types_map[$field_key]) && is_array($form_field_post_types_map[$field_key]) ? $form_field_post_types_map[$field_key] : [];
                        $field_post_types_attr = implode(',', array_map('sanitize_key', $field_post_types));
                        $search_text = strtolower($label . ' ' . $field_key);
                    ?>
                        <label class="matrix-field-visibility-item" data-post-types="<?php echo esc_attr($field_post_types_attr); ?>" data-search="<?php echo esc_attr($search_text); ?>">
                            <input type="checkbox" name="matrix_disabled_form_fields[]" value="<?php echo esc_attr($field_key); ?>" <?php checked($is_disabled); ?> />
                            <span>
                                <span class="matrix-field-visibility-name"><?php echo esc_html($label); ?></span>
                                <span class="matrix-field-visibility-key"><?php echo esc_html($field_key); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <p id="matrix-field-visibility-empty" class="description" style="display:none; margin-top: 0;">No fields match selected post types/search.</p>
            <p class="description" style="margin-top:0;">This only affects the client editing form — it does not change the content on the site.</p>

            <p style="margin: 12px 0 6px 0;"><strong>Admin duplicate notification email (optional)</strong></p>
            <p style="margin-top: 0;">
                <input type="email" name="matrix_admin_duplicate_notify_email" value="<?php echo esc_attr($admin_duplicate_notify_email); ?>" placeholder="name@example.com" style="min-width: 320px;" />
                <br />
                <span class="description">When a client duplicates a page from the content form, send a notification email to this address.</span>
            </p>

            <p style="margin: 12px 0 6px 0;"><strong>Client update notification email (optional)</strong></p>
            <p style="margin-top: 0;">
                <input type="email" name="matrix_notify_email" value="<?php echo esc_attr($notify_email); ?>" placeholder="name@example.com" style="min-width: 320px;" />
                <br />
                <span class="description">When a client saves the form, send a summary of all changed fields (before → after) to this email.</span>
            </p>

            <p style="margin: 12px 0 6px 0;"><strong>Moderation notification email (optional)</strong></p>
            <p style="margin-top: 0;">
                <input type="email" name="matrix_review_notify_email" value="<?php echo esc_attr($review_notify_email); ?>" placeholder="name@example.com" style="min-width: 320px;" />
                <br />
                <span class="description">When a moderated link submission is sent for approval, notify this email. If blank, falls back to Client update notification email.</span>
            </p>

            <p style="margin-top: 1rem;"><strong>2. Create client link</strong></p>
            <p style="margin: 0.35rem 0;">
                <label style="margin-right: 1rem;">
                    Link expires after
                    <input type="number" min="0" step="1" name="matrix_client_link_expires_days" value="0" style="width:80px;" />
                    days
                </label>
                <label>
                    Reminder after
                    <input type="number" min="0" step="1" name="matrix_client_link_reminder_days" value="0" style="width:80px;" />
                    days
                </label>
            </p>
            <p style="margin: 0.35rem 0;">
                <label>
                    <input type="checkbox" name="matrix_client_requires_approval" value="1" />
                    Require approval before publishing (off by default)
                </label>
            </p>
            <p class="description" style="margin-top: 0;">Use 0 to disable. Expiry blocks form access after the selected number of days.</p>
            <p style="margin: 0.5rem 0 0.25rem;"><strong>Custom instructions for this link (optional)</strong></p>
            <p style="margin-top: 0;">
                <textarea name="matrix_client_custom_instructions" rows="3" style="min-width: 520px; width: 100%; max-width: 760px;" placeholder="Example: Use the brand voice guide and do not change hero images."></textarea>
            </p>
            <p>
                <button type="submit" name="matrix_export_format" value="client_link" class="button button-primary">
                    Get client link (form on site)
                </button>
                <button type="submit" name="matrix_export_format" value="client_links_excel" class="button">Download client links workbook (.xlsx)</button>
            </p>
            <p class="description" style="margin-top: 0.25rem;">Workbook: one form is created for all selected items. The Excel file has one row per page/post with <strong>Form Link</strong> (full form), <strong>Form Tab Link</strong> (opens that row’s tab), <strong>Status</strong> (To do / In progress / Done / Delete), and a link to generate screenshots.</p>
            <?php
            $client_link_url = isset($client_link_url) ? $client_link_url : '';
            $show_client_link = isset($show_client_link) ? $show_client_link : false;
            if ($show_client_link || $client_link_url) :
                $url = $client_link_url ? $client_link_url : home_url('/content-editing/');
                $mailto_url = 'mailto:?subject=' . rawurlencode('Content editing link') . '&body=' . rawurlencode("Hi,\n\nPlease use this link to review and update content:\n" . $url . "\n");
            ?>
                <div class="notice notice-success inline" style="margin: 0.5rem 0 0 0;">
                    <p><strong>Client form link:</strong> <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($url); ?></a></p>
                    <p class="description">Send this link to your client. They can edit content and click &quot;Update site content&quot; (or &quot;Save for later&quot;) — no login or download.</p>
                    <p><a class="button button-secondary" href="<?php echo esc_url($mailto_url); ?>">Email link to client</a></p>
                </div>
            <?php endif; ?>
            <p class="description">Each click creates a new unique link with its own token.</p>
        </form>
    </div>

    <div class="card" style="max-width: 900px; padding: 1.5rem; margin: 1.5rem 0;">
        <h2 style="margin-top: 0;">Client links</h2>
        <?php $client_links = isset($client_links) && is_array($client_links) ? $client_links : []; ?>
        <?php if (empty($client_links)) : ?>
            <p class="description">No client links created yet.</p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top: 0.75rem;">
                <thead>
                    <tr>
                        <th>Created</th>
                        <th>Items</th>
                        <th>Link</th>
                        <th>Expiry / Reminder</th>
                        <th>Instructions</th>
                        <th>Publish mode</th>
                        <th style="width: 260px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($client_links as $token => $entry) :
                    $post_ids = isset($entry['post_ids']) && is_array($entry['post_ids']) ? $entry['post_ids'] : [];
                    $created_at = isset($entry['created_at']) ? (int) $entry['created_at'] : 0;
                    $labels = [];
                    foreach ($post_ids as $pid) {
                        $title = get_the_title((int) $pid);
                        if (!is_string($title) || $title === '') {
                            $title = 'Post #' . (int) $pid;
                        }
                        $labels[] = $title;
                    }
                    $link_url = Matrix_Export::get_client_link_url($token);
                    $expires_at = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
                    $reminder_days = isset($entry['reminder_days']) ? (int) $entry['reminder_days'] : 0;
                    $created_days_ago = $created_at > 0 ? (int) floor((time() - $created_at) / DAY_IN_SECONDS) : 0;
                    $is_expired = ($expires_at > 0 && time() > $expires_at);
                    $reminder_due = ($reminder_days > 0 && $created_days_ago >= $reminder_days);
                    $mailto_row_url = 'mailto:?subject=' . rawurlencode('Content editing link') . '&body=' . rawurlencode("Hi,\n\nPlease use this link to review and update content:\n" . $link_url . "\n");
                    $instructions_preview = isset($entry['custom_instructions']) ? wp_strip_all_tags((string) $entry['custom_instructions']) : '';
                    $requires_approval = !empty($entry['requires_approval']);
                ?>
                    <tr>
                        <td><?php echo $created_at > 0 ? esc_html(wp_date('Y-m-d H:i', $created_at)) : '—'; ?></td>
                        <td><?php echo esc_html(implode(', ', array_slice($labels, 0, 3)) . (count($labels) > 3 ? ' +' . (count($labels) - 3) . ' more' : '')); ?></td>
                        <td><a href="<?php echo esc_url($link_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($link_url); ?></a></td>
                        <td>
                            <?php if ($expires_at > 0) : ?>
                                <div><?php echo $is_expired ? '<strong style="color:#b42318;">Expired</strong>' : 'Expires'; ?>: <?php echo esc_html(wp_date('Y-m-d H:i', $expires_at)); ?></div>
                            <?php else : ?>
                                <div>No expiry</div>
                            <?php endif; ?>
                            <?php if ($reminder_days > 0) : ?>
                                <div>Reminder: day <?php echo (int) $reminder_days; ?><?php if ($reminder_due && !$is_expired) : ?> <strong style="color:#b54708;">(due)</strong><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $instructions_preview !== '' ? esc_html(function_exists('mb_substr') ? mb_substr($instructions_preview, 0, 120) : substr($instructions_preview, 0, 120)) . (strlen($instructions_preview) > 120 ? '…' : '') : '—'; ?></td>
                        <td><?php echo $requires_approval ? '<strong>Needs approval</strong>' : 'Publish on submit'; ?></td>
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <a class="button button-small" href="<?php echo esc_url($mailto_row_url); ?>">Email link</a>
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('matrix_client_links_action', 'matrix_client_links_action_nonce'); ?>
                                    <input type="hidden" name="matrix_client_links_action" value="generate_one" />
                                    <input type="hidden" name="matrix_client_link_token" value="<?php echo esc_attr($token); ?>" />
                                    <button type="submit" class="button button-small matrix-generate-btn">Generate screenshots</button>
                                </form>
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('matrix_client_links_action', 'matrix_client_links_action_nonce'); ?>
                                    <input type="hidden" name="matrix_client_links_action" value="clear_one" />
                                    <input type="hidden" name="matrix_client_link_token" value="<?php echo esc_attr($token); ?>" />
                                    <button type="submit" class="button button-small">Clear</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top:0.75rem;">
                <?php wp_nonce_field('matrix_client_links_action', 'matrix_client_links_action_nonce'); ?>
                <input type="hidden" name="matrix_client_links_action" value="clear_all" />
                <button type="submit" class="button">Clear all links (+ screenshots)</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card" style="max-width: 900px; padding: 1.5rem; margin: 1.5rem 0;">
        <h2 style="margin-top: 0;">Pending reviews</h2>
        <?php $pending_reviews = isset($pending_reviews) && is_array($pending_reviews) ? $pending_reviews : []; ?>
        <?php if (empty($pending_reviews)) : ?>
            <p class="description">No pending submissions.</p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top: 0.75rem;">
                <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>Submitted by</th>
                        <th>Items</th>
                        <th>Token</th>
                        <th style="width:260px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending_reviews as $submission_id => $submission) :
                    $submitted_at = isset($submission['created_at']) ? (int) $submission['created_at'] : 0;
                    $submitted_by = isset($submission['created_by_email']) ? (string) $submission['created_by_email'] : '';
                    $token = isset($submission['token']) ? (string) $submission['token'] : '';
                    $post_ids = isset($submission['post_ids']) && is_array($submission['post_ids']) ? array_map('intval', $submission['post_ids']) : [];
                    $labels = [];
                    foreach ($post_ids as $pid) {
                        $title = get_the_title($pid);
                        $labels[] = (is_string($title) && $title !== '') ? $title : ('Post #' . $pid);
                    }
                ?>
                    <tr>
                        <td><?php echo $submitted_at > 0 ? esc_html(wp_date('Y-m-d H:i', $submitted_at)) : '—'; ?></td>
                        <td><?php echo $submitted_by !== '' ? esc_html($submitted_by) : '—'; ?></td>
                        <td><?php echo esc_html(implode(', ', array_slice($labels, 0, 3)) . (count($labels) > 3 ? ' +' . (count($labels) - 3) . ' more' : '')); ?></td>
                        <td><code><?php echo esc_html($token); ?></code></td>
                        <td>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('matrix_reviews_action', 'matrix_reviews_action_nonce'); ?>
                                    <input type="hidden" name="matrix_reviews_action" value="approve" />
                                    <input type="hidden" name="matrix_review_submission_id" value="<?php echo esc_attr((string) $submission_id); ?>" />
                                    <button type="submit" class="button button-primary button-small">Approve &amp; publish</button>
                                </form>
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('matrix_reviews_action', 'matrix_reviews_action_nonce'); ?>
                                    <input type="hidden" name="matrix_reviews_action" value="reject" />
                                    <input type="hidden" name="matrix_review_submission_id" value="<?php echo esc_attr((string) $submission_id); ?>" />
                                    <input type="text" name="matrix_review_note" value="" placeholder="Reason (optional)" style="width:180px;" />
                                    <button type="submit" class="button button-small">Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" style="max-width: 600px; padding: 1.5rem; margin: 1.5rem 0;">
        <h2 style="margin-top: 0;">Import</h2>
        <p>Upload a CSV that was previously exported (single CSV with <code>post_id</code>, <code>block_source</code>, <code>block_index</code>, <code>block_type</code>) to update pages and posts.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('matrix_import', 'matrix_import_nonce'); ?>
            <p>
                <input type="file" name="matrix_import_file" accept=".csv" required />
            </p>
            <p>
                <button type="submit" class="button button-primary">Import CSV</button>
            </p>
        </form>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('matrix-export-form');
    if (!form) return;
    var typeCbs = form.querySelectorAll('.matrix-export-type-cb');
    var typeGroups = form.querySelectorAll('.matrix-export-type-group');
    var postCbs = form.querySelectorAll('.matrix-export-post-cb');
    var fieldItems = form.querySelectorAll('.matrix-field-visibility-item');
    var fieldSearch = form.querySelector('#matrix-field-visibility-search');
    var fieldEmpty = form.querySelector('#matrix-field-visibility-empty');
    var fieldsForPostsUrl = (form.querySelector('#matrix-fields-for-posts-url') || {}).value || '';
    var fieldsForPostsNonce = (form.querySelector('#matrix-fields-for-posts-nonce') || {}).value || '';
    var allowedFieldKeys = null; // Set<string> or null when not filtering by selected posts
    var fieldsFetchTimer = null;

    function filterByTypes() {
        var selected = [];
        typeCbs.forEach(function(cb) { if (cb.checked) selected.push(cb.value); });
        typeGroups.forEach(function(g) {
            var pt = g.getAttribute('data-post-type');
            g.style.display = selected.indexOf(pt) !== -1 ? '' : 'none';
        });
        filterFieldItems();
    }
    function filterFieldItems() {
        if (!fieldItems.length) return;
        var selected = [];
        typeCbs.forEach(function(cb) { if (cb.checked) selected.push(cb.value); });
        var search = fieldSearch ? String(fieldSearch.value || '').toLowerCase().trim() : '';
        var visibleCount = 0;
        fieldItems.forEach(function(item) {
            var allowed = item.getAttribute('data-post-types') || '';
            var allowedTypes = allowed ? allowed.split(',').filter(Boolean) : [];
            var text = String(item.getAttribute('data-search') || '').toLowerCase();
            var fieldKey = '';
            var cb = item.querySelector('input[type="checkbox"][name="matrix_disabled_form_fields[]"]');
            if (cb) fieldKey = String(cb.value || '');
            var typeMatch = selected.length === 0 || allowedTypes.length === 0 || allowedTypes.some(function(pt) { return selected.indexOf(pt) !== -1; });
            var searchMatch = search === '' || text.indexOf(search) !== -1;
            var postMatch = !allowedFieldKeys || !fieldKey ? true : allowedFieldKeys.has(fieldKey);
            var show = typeMatch && searchMatch && postMatch;
            item.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        if (fieldEmpty) {
            fieldEmpty.style.display = visibleCount === 0 ? '' : 'none';
        }
    }

    function getSelectedPostIds() {
        var ids = [];
        postCbs.forEach(function(cb) {
            if (cb.checked) ids.push(parseInt(cb.value, 10));
        });
        return ids.filter(function(n) { return Number.isFinite(n) && n > 0; });
    }

    function fetchAllowedFieldsForSelectedPosts() {
        if (!fieldsForPostsUrl || !fieldsForPostsNonce) return;
        var ids = getSelectedPostIds();
        if (!ids.length) {
            allowedFieldKeys = null;
            filterFieldItems();
            return;
        }
        var body = new FormData();
        body.append('action', 'matrix_export_get_fields_for_posts');
        body.append('matrix_fields_for_posts_nonce', fieldsForPostsNonce);
        ids.forEach(function(id) { body.append('post_ids[]', String(id)); });
        fetch(fieldsForPostsUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success || !res.data || !Array.isArray(res.data.field_keys)) {
                    allowedFieldKeys = null;
                    filterFieldItems();
                    return;
                }
                allowedFieldKeys = new Set(res.data.field_keys.map(function(k) { return String(k || ''); }).filter(Boolean));
                filterFieldItems();
            })
            .catch(function() {
                allowedFieldKeys = null;
                filterFieldItems();
            });
    }
    typeCbs.forEach(function(cb) { cb.addEventListener('change', filterByTypes); });
    if (fieldSearch) {
        fieldSearch.addEventListener('input', filterFieldItems);
    }
    postCbs.forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (fieldsFetchTimer) window.clearTimeout(fieldsFetchTimer);
            fieldsFetchTimer = window.setTimeout(fetchAllowedFieldsForSelectedPosts, 250);
        });
    });
    filterByTypes();
    fetchAllowedFieldsForSelectedPosts();

    form.querySelector('.matrix-select-all').addEventListener('click', function() {
        typeGroups.forEach(function(g) {
            if (g.style.display !== 'none') g.querySelectorAll('.matrix-export-post-cb').forEach(function(c) { c.checked = true; });
        });
    });
    form.querySelector('.matrix-deselect-all').addEventListener('click', function() {
        postCbs.forEach(function(c) { c.checked = false; });
    });
})();

document.querySelectorAll('form input[name="matrix_client_links_action"][value="generate_one"]').forEach(function(input) {
    var form = input.closest('form');
    if (!form) return;
    form.addEventListener('submit', function() {
        var btn = form.querySelector('.matrix-generate-btn');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = 'Generating...';
    });
});

(function() {
    var job = <?php echo isset($_GET['matrix_previews_job']) ? json_encode((string) $_GET['matrix_previews_job']) : '""'; ?>;
    if (!job) return;
    var bar = document.getElementById('matrix-preview-progress-bar');
    var genBar = document.getElementById('matrix-preview-generated-bar');
    var genText = document.getElementById('matrix-preview-generated-text');
    var failText = document.getElementById('matrix-preview-failure-text');
    var text = document.getElementById('matrix-preview-progress-text');
    if (!bar || !text || !genBar || !genText || !failText) return;
    var ajaxUrl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
    var polls = 0;
    var timer = setInterval(function() {
        polls += 1;
        var url = ajaxUrl + '?action=matrix_preview_progress&job=' + encodeURIComponent(job);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (!json || !json.success || !json.data || !json.data.found) return;
                var d = json.data;
                var pct = typeof d.percent === 'number' ? d.percent : 0;
                bar.value = Math.max(0, Math.min(100, pct));
                genBar.max = d.total || 0;
                genBar.value = d.generated || 0;
                genText.textContent = String(d.generated || 0) + ' / ' + String(d.total || 0);
                text.textContent = ' ' + d.completed + '/' + d.total + ' processed, ' + d.generated + ' generated.';
                var s = d.stats || {};
                failText.textContent = 'Matched anchors: ' + (s.selector_matches || 0)
                    + ' | Section fallback: ' + (s.section_fallback_matches || 0)
                    + ' | No target found: ' + (s.no_target_matches || 0)
                    + ' | Capture errors: ' + (s.capture_errors || 0);
                if (d.done) {
                    clearInterval(timer);
                    if (d.error) {
                        text.textContent += ' Error: ' + d.error;
                        if (s.last_error) failText.textContent += ' | Last error: ' + s.last_error;
                    } else {
                        text.textContent += ' Done. Refresh content-editing to see images.';
                    }
                }
            })
            .catch(function() {});
        if (polls > 120) {
            clearInterval(timer);
            text.textContent += ' Progress timed out. You can refresh this page.';
        }
    }, 2000);
})();
</script>
