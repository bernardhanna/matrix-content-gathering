<?php
/**
 * Import CSV back into pages/posts ACF flexible content and hero blocks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_Import {
    const CLIENT_FORM_DRAFTS_OPTION = 'matrix_export_client_form_drafts';

    /**
     * Handle CSV upload: parse and update posts.
     *
     * @return string HTML message
     */
    public static function handle_upload() {
        if (empty($_FILES['matrix_import_file']['tmp_name']) || !is_uploaded_file($_FILES['matrix_import_file']['tmp_name'])) {
            return '<div class="notice notice-error"><p>No file uploaded or invalid file.</p></div>';
        }

        $tmp = $_FILES['matrix_import_file']['tmp_name'];
        $csv = array_map('str_getcsv', file($tmp));
        if (empty($csv)) {
            return '<div class="notice notice-error"><p>CSV is empty.</p></div>';
        }

        $headers = array_shift($csv);
        $headers = array_map('trim', $headers);
        $required = ['post_id', 'block_source', 'block_index', 'block_type'];
        foreach ($required as $r) {
            if (!in_array($r, $headers, true)) {
                return '<div class="notice notice-error"><p>CSV must contain columns: ' . esc_html(implode(', ', $required)) . '</p></div>';
            }
        }

        $rows_by_post = [];
        foreach ($csv as $line) {
            $row = array_combine($headers, array_pad($line, count($headers), ''));
            if (empty($row['post_id'])) {
                continue;
            }
            $post_id = (int) $row['post_id'];
            $source = isset($row['block_source']) ? $row['block_source'] : Matrix_Export::FLEX_FIELD;
            if ($source !== Matrix_Export::FLEX_FIELD && $source !== Matrix_Export::HERO_FIELD) {
                continue;
            }
            if (!isset($rows_by_post[$post_id])) {
                $rows_by_post[$post_id] = [Matrix_Export::FLEX_FIELD => [], Matrix_Export::HERO_FIELD => []];
            }
            $idx = isset($row['block_index']) ? (int) $row['block_index'] : count($rows_by_post[$post_id][$source]);
            $decoded = self::decode_row($row);
            $rows_by_post[$post_id][$source][$idx] = $decoded;
        }

        $updated = 0;
        $errors = [];
        foreach ($rows_by_post as $post_id => $sources) {
            if (!get_post($post_id)) {
                $errors[] = 'Post ID ' . $post_id . ' not found.';
                continue;
            }
            foreach ([Matrix_Export::HERO_FIELD, Matrix_Export::FLEX_FIELD] as $field_name) {
                if (empty($sources[$field_name])) {
                    continue;
                }
                ksort($sources[$field_name]);
                $blocks = array_values($sources[$field_name]);
                $ok = update_field($field_name, $blocks, $post_id);
                if ($ok !== false) {
                    $updated++;
                }
            }
        }

        $msg = sprintf(
            'Import complete. Updated %d post(s).',
            count(array_unique(array_keys($rows_by_post)))
        );
        if (!empty($errors)) {
            $msg .= ' Errors: ' . implode(' ', $errors);
        }
        return '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
    }

    /**
     * Decode one CSV row back into an ACF flexible content row (with acf_fc_layout and sub_fields).
     */
    protected static function decode_row(array $row) {
        $layout = isset($row['block_type']) ? $row['block_type'] : '';
        $out = ['acf_fc_layout' => $layout];
        $skip = ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type'];
        foreach ($row as $key => $value) {
            if (in_array($key, $skip, true) || $value === '') {
                continue;
            }
            $out[$key] = self::decode_value($value);
        }
        return $out;
    }

    /**
     * Decode a single cell value (LINK:, IMAGE:, JSON:) back to ACF format.
     */
    protected static function decode_value($value) {
        if (!is_string($value)) {
            return $value;
        }
        if (strpos($value, 'LINK:') === 0) {
            $rest = substr($value, 5);
            $parts = explode("\t", $rest, 2);
            $url = isset($parts[0]) ? trim((string) $parts[0]) : '';
            return [
                'url'   => $url,
                'title' => isset($parts[1]) ? $parts[1] : '',
                'target' => self::is_external_link_url($url) ? '_blank' : '',
            ];
        }
        if (strpos($value, 'IMAGE:') === 0) {
            $rest = trim(substr($value, 6));
            $parts = explode("\t", $rest, 2);
            $id = isset($parts[0]) ? (int) $parts[0] : 0;
            return $id ? $id : $value; // ACF can accept ID for image field
        }
        if (strpos($value, 'JSON:') === 0) {
            $json = substr($value, 5);
            $dec = json_decode($json, true);
            return is_array($dec) ? $dec : $value;
        }
        return $value;
    }

    /**
     * Handle client form submission (editable HTML form). Rebuilds blocks from POST and updates ACF.
     *
     * @param array $post_data Typically $_POST.
     * @param array $files Typically $_FILES (for image uploads).
     * @param string $submit_mode Save mode: publish (default) or later.
     * @return array{success: bool, message: string, redirect: string|null}
     */
    public static function handle_form_submit(array $post_data, array $files = [], $submit_mode = 'publish') {
        $submit_mode = ($submit_mode === 'draft' || $submit_mode === 'later') ? 'later' : 'publish';
        $token = isset($post_data['matrix_form_token']) ? $post_data['matrix_form_token'] : '';
        $post_ids_for_link = Matrix_Export::get_client_link_post_ids($token);
        if (empty($post_ids_for_link)) {
            return ['success' => false, 'message' => 'Invalid or expired link.', 'redirect' => null];
        }
        $allowed_post_map = array_fill_keys(array_map('intval', $post_ids_for_link), true);

        $post_ids = isset($post_data['matrix_post_id']) && is_array($post_data['matrix_post_id']) ? $post_data['matrix_post_id'] : [];
        $sources = isset($post_data['matrix_block_source']) && is_array($post_data['matrix_block_source']) ? $post_data['matrix_block_source'] : [];
        $indices = isset($post_data['matrix_block_index']) && is_array($post_data['matrix_block_index']) ? $post_data['matrix_block_index'] : [];
        $types = isset($post_data['matrix_block_type']) && is_array($post_data['matrix_block_type']) ? $post_data['matrix_block_type'] : [];
        $fields = isset($post_data['matrix_field']) && is_array($post_data['matrix_field']) ? $post_data['matrix_field'] : [];
        $block_enabled = isset($post_data['matrix_block_enabled']) && is_array($post_data['matrix_block_enabled']) ? $post_data['matrix_block_enabled'] : [];

        if (empty($post_ids)) {
            return ['success' => false, 'message' => 'No data received.', 'redirect' => null];
        }
        if (!is_array($fields)) {
            $fields = [];
        }

        $image_upload_result = self::parse_image_uploads($files);
        $image_uploads = isset($image_upload_result['by_index']) ? $image_upload_result['by_index'] : [];
        $upload_errors = isset($image_upload_result['errors']) ? $image_upload_result['errors'] : [];
        $video_upload_result = self::parse_video_uploads($files);
        $video_uploads = isset($video_upload_result['by_index']) ? $video_upload_result['by_index'] : [];
        $upload_errors = array_merge($upload_errors, isset($video_upload_result['errors']) ? $video_upload_result['errors'] : []);
        if (!empty($upload_errors)) {
            return ['success' => false, 'message' => implode(' ', $upload_errors), 'redirect' => null];
        }
        $rows_by_post = [];
        $post_updates = [];
        $disabled_by_post = [];
        $draft_fields_by_index = [];
        $draft_row_signatures = [];
        $draft_block_enabled = [];
        $n = count($post_ids);
        for ($i = 0; $i < $n; $i++) {
            $post_id = isset($post_ids[$i]) ? (int) $post_ids[$i] : 0;
            if ($post_id <= 0 || !isset($allowed_post_map[$post_id])) {
                continue;
            }
            $source = isset($sources[$i]) ? sanitize_text_field($sources[$i]) : Matrix_Export::FLEX_FIELD;
            $idx = isset($indices[$i]) ? (int) $indices[$i] : $i;
            $type = isset($types[$i]) ? sanitize_text_field($types[$i]) : '';
            if ($source !== Matrix_Export::FLEX_FIELD && $source !== Matrix_Export::HERO_FIELD && $source !== Matrix_Export::POST_FIELDS_SOURCE) {
                continue;
            }
            $row_data = isset($fields[$i]) && is_array($fields[$i]) ? $fields[$i] : [];
            if (!empty($image_uploads[$i])) {
                foreach ($image_uploads[$i] as $field_name => $attach_id) {
                    $row_data[$field_name] = $attach_id;
                }
            }
            if (!empty($video_uploads[$i])) {
                foreach ($video_uploads[$i] as $field_name => $attach_id) {
                    $row_data[$field_name] = $attach_id;
                }
            }
            $draft_row_signatures[$i] = [
                'post_id' => $post_id,
                'block_source' => $source,
                'block_index' => $idx,
                'block_type' => $type,
            ];
            $draft_fields_by_index[$i] = self::sanitize_draft_fields_row($row_data);
            if ($source === Matrix_Export::FLEX_FIELD) {
                $draft_block_enabled[$i] = isset($block_enabled[$i]) ? (int) $block_enabled[$i] : 1;
            }
            $row_data = self::convert_image_urls_to_attachments($row_data);
            if ($source === Matrix_Export::POST_FIELDS_SOURCE) {
                if (!isset($post_updates[$post_id])) {
                    $post_updates[$post_id] = [];
                }
                $post_updates[$post_id] = array_merge($post_updates[$post_id], self::decode_post_fields_update($row_data));
                continue;
            }
            if (!isset($rows_by_post[$post_id])) {
                $rows_by_post[$post_id] = [Matrix_Export::FLEX_FIELD => [], Matrix_Export::HERO_FIELD => []];
            }
            if ($source === Matrix_Export::FLEX_FIELD) {
                $enabled = isset($block_enabled[$i]) ? (int) $block_enabled[$i] : 1;
                if ($enabled === 0) {
                    if (!isset($disabled_by_post[$post_id])) {
                        $disabled_by_post[$post_id] = [];
                    }
                    $disabled_by_post[$post_id][] = $idx;
                }
            }
            $decoded = self::decode_form_row($row_data, $type);
            $rows_by_post[$post_id][$source][$idx] = $decoded;
        }
        if ($submit_mode === 'later') {
            $page_status = [];
            $raw_status = isset($post_data['matrix_page_status']) && is_array($post_data['matrix_page_status']) ? $post_data['matrix_page_status'] : [];
            foreach ($raw_status as $post_id => $status) {
                $post_id = (int) $post_id;
                if ($post_id <= 0 || !isset($allowed_post_map[$post_id])) {
                    continue;
                }
                $status = sanitize_text_field(wp_unslash((string) $status));
                if (in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
                    $page_status[$post_id] = $status;
                }
            }
            $drafts = get_option(self::CLIENT_FORM_DRAFTS_OPTION, []);
            $existing_draft = (is_array($drafts) && isset($drafts[$token]) && is_array($drafts[$token])) ? $drafts[$token] : [];
            $page_status_done_by = isset($existing_draft['page_status_done_by']) && is_array($existing_draft['page_status_done_by']) ? $existing_draft['page_status_done_by'] : [];
            self::save_client_form_draft($token, array_merge([
                'saved_at' => time(),
                'active_page' => isset($post_data['matrix_active_page']) ? (int) $post_data['matrix_active_page'] : 0,
                'row_signatures' => $draft_row_signatures,
                'fields_by_index' => $draft_fields_by_index,
                'block_enabled' => $draft_block_enabled,
                'page_status' => $page_status,
            ], ['page_status_done_by' => $page_status_done_by]));
            foreach ($page_status as $pid => $st) {
                update_post_meta($pid, MATRIX_EXPORT_STATUS_META_KEY, $st);
                if ($st === 'done' && isset($page_status_done_by[$pid]) && $page_status_done_by[$pid] !== '') {
                    update_post_meta($pid, MATRIX_EXPORT_STATUS_DONE_BY_META_KEY, $page_status_done_by[$pid]);
                } else {
                    delete_post_meta($pid, MATRIX_EXPORT_STATUS_DONE_BY_META_KEY);
                }
            }
            $is_autosave = !empty($post_data['matrix_is_autosave']) && (string) $post_data['matrix_is_autosave'] === '1';
            if (!$is_autosave) {
                self::send_draft_notification($token, $rows_by_post, $post_updates);
            }
            return [
                'success'  => true,
                'message'  => 'Form saved for later editing. No site content was updated.',
                'redirect' => home_url('/?matrix_form_saved=1'),
            ];
        }

        $changes = [];
        foreach ($rows_by_post as $post_id => $sources) {
            if (!get_post($post_id)) {
                continue;
            }
            foreach ([Matrix_Export::HERO_FIELD, Matrix_Export::FLEX_FIELD] as $source_field) {
                if (empty($sources[$source_field])) {
                    continue;
                }
                ksort($sources[$source_field]);
                $submitted = array_values($sources[$source_field]);
                // Use raw ACF values (unformatted) so taxonomy/relationship fields stay IDs.
                $existing = get_field($source_field, $post_id, false);
                if (is_array($existing) && count($existing) >= count($submitted)) {
                    $blocks = [];
                    foreach ($submitted as $idx => $decoded) {
                        $before_block = isset($existing[$idx]) && is_array($existing[$idx]) ? $existing[$idx] : [];
                        $after_block = array_merge($before_block, $decoded);
                        $after_block = self::normalize_acf_value_for_save($after_block);
                        self::collect_block_changes($changes, $post_id, $source_field, $idx, $before_block, $after_block);
                        $blocks[] = $after_block;
                    }
                } else {
                    $blocks = self::normalize_acf_value_for_save($submitted);
                    foreach ($submitted as $idx => $decoded) {
                        $safe_decoded = self::normalize_acf_value_for_save($decoded);
                        self::collect_block_changes($changes, $post_id, $source_field, $idx, [], $safe_decoded);
                    }
                }
                $blocks = self::normalize_acf_value_for_save($blocks);
                update_field($source_field, $blocks, $post_id);
            }
        }
        foreach ($post_updates as $post_id => $update) {
            self::apply_post_field_updates((int) $post_id, $update);
        }
        self::clear_client_form_draft($token);

        self::save_disabled_blocks($rows_by_post, $disabled_by_post);

        $count = count(array_unique(array_merge(array_keys($rows_by_post), array_keys($post_updates))));
        self::send_change_notification($changes);
        return [
            'success'  => true,
            'message'  => sprintf('Content saved. Updated %d page(s).', $count),
            'redirect' => home_url('/?matrix_form_saved=1'),
        ];
    }

    /**
     * Get saved draft state for the current rows in a client form.
     *
     * @param string $token
     * @param array $rows Export rows from Matrix_Export::get_export_data_for_form().
     * @return array{fields_by_index: array<int, array<string, string>>, block_enabled: array<int, int>, active_page: int, page_status: array<int, string>, page_status_done_by: array<int, string>, saved_at: int}
     */
    public static function get_client_form_draft_state($token, array $rows) {
        $token = sanitize_text_field((string) $token);
        if ($token === '') {
            return ['fields_by_index' => [], 'block_enabled' => [], 'active_page' => 0, 'page_status' => [], 'page_status_done_by' => [], 'saved_at' => 0];
        }
        $drafts = get_option(self::CLIENT_FORM_DRAFTS_OPTION, []);
        if (!is_array($drafts) || empty($drafts[$token]) || !is_array($drafts[$token])) {
            return ['fields_by_index' => [], 'block_enabled' => [], 'active_page' => 0, 'page_status' => [], 'page_status_done_by' => [], 'saved_at' => 0];
        }
        $draft = $drafts[$token];
        $saved_signatures = isset($draft['row_signatures']) && is_array($draft['row_signatures']) ? $draft['row_signatures'] : [];
        $saved_fields = isset($draft['fields_by_index']) && is_array($draft['fields_by_index']) ? $draft['fields_by_index'] : [];
        $saved_block_enabled = isset($draft['block_enabled']) && is_array($draft['block_enabled']) ? $draft['block_enabled'] : [];
        $active_page = isset($draft['active_page']) ? (int) $draft['active_page'] : 0;
        $saved_page_status = isset($draft['page_status']) && is_array($draft['page_status']) ? $draft['page_status'] : [];
        $saved_page_status_done_by = isset($draft['page_status_done_by']) && is_array($draft['page_status_done_by']) ? $draft['page_status_done_by'] : [];

        $fields_by_index = [];
        $block_enabled = [];
        foreach ($rows as $i => $row) {
            if (!isset($saved_signatures[$i]) || !is_array($saved_signatures[$i])) {
                continue;
            }
            $sig = $saved_signatures[$i];
            $matches = (
                isset($row['post_id'], $row['block_source'], $row['block_index'], $row['block_type']) &&
                (int) $row['post_id'] === (int) (isset($sig['post_id']) ? $sig['post_id'] : 0) &&
                (string) $row['block_source'] === (string) (isset($sig['block_source']) ? $sig['block_source'] : '') &&
                (int) $row['block_index'] === (int) (isset($sig['block_index']) ? $sig['block_index'] : -1) &&
                (string) $row['block_type'] === (string) (isset($sig['block_type']) ? $sig['block_type'] : '')
            );
            if (!$matches) {
                continue;
            }
            if (isset($saved_fields[$i]) && is_array($saved_fields[$i])) {
                $fields_by_index[$i] = $saved_fields[$i];
            }
            if (array_key_exists($i, $saved_block_enabled)) {
                $block_enabled[$i] = (int) $saved_block_enabled[$i];
            }
        }
        $page_status = [];
        foreach ($saved_page_status as $post_id => $status) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            $status = sanitize_text_field((string) $status);
            if (in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
                $page_status[$post_id] = $status;
            }
        }
        $page_status_done_by = [];
        foreach ($saved_page_status_done_by as $post_id => $email) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }
            $page_status_done_by[$post_id] = sanitize_text_field((string) $email);
        }
        return [
            'fields_by_index' => $fields_by_index,
            'block_enabled' => $block_enabled,
            'active_page' => $active_page,
            'page_status' => $page_status,
            'page_status_done_by' => $page_status_done_by,
            'saved_at' => isset($draft['saved_at']) ? (int) $draft['saved_at'] : 0,
        ];
    }

    /**
     * Update one page status inside token draft.
     */
    public static function update_draft_page_status($token, $post_id, $status, $done_by_email = '') {
        $token = sanitize_text_field((string) $token);
        $post_id = (int) $post_id;
        if ($token === '' || $post_id <= 0 || !in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
            return;
        }
        $drafts = get_option(self::CLIENT_FORM_DRAFTS_OPTION, []);
        if (!is_array($drafts)) {
            $drafts = [];
        }
        if (!isset($drafts[$token]) || !is_array($drafts[$token])) {
            $drafts[$token] = [];
        }
        if (!isset($drafts[$token]['page_status']) || !is_array($drafts[$token]['page_status'])) {
            $drafts[$token]['page_status'] = [];
        }
        $drafts[$token]['page_status'][$post_id] = $status;
        if ($status === 'done' && $done_by_email !== '') {
            if (!isset($drafts[$token]['page_status_done_by']) || !is_array($drafts[$token]['page_status_done_by'])) {
                $drafts[$token]['page_status_done_by'] = [];
            }
            $drafts[$token]['page_status_done_by'][$post_id] = sanitize_text_field((string) $done_by_email);
        }
        if (empty($drafts[$token]['saved_at'])) {
            $drafts[$token]['saved_at'] = time();
        }
        update_option(self::CLIENT_FORM_DRAFTS_OPTION, $drafts, false);
    }

    /**
     * Keep submitted row values as strings for repopulating draft forms.
     *
     * @param array $row_data
     * @return array<string, string>
     */
    protected static function sanitize_draft_fields_row(array $row_data) {
        $out = [];
        foreach ($row_data as $key => $value) {
            if (!is_string($key) || $key === '' || is_object($value)) {
                continue;
            }
            if (is_array($value)) {
                $taxonomy = Matrix_Export::get_taxonomy_from_form_field_name($key);
                if ($taxonomy !== '') {
                    $out[$key] = array_values(array_filter(array_map('intval', $value)));
                }
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[$key] = (string) $value;
                continue;
            }
            $out[$key] = wp_unslash((string) $value);
        }
        return $out;
    }

    /**
     * Save token-scoped client form draft in options.
     *
     * @param string $token
     * @param array $draft
     */
    protected static function save_client_form_draft($token, array $draft) {
        $token = sanitize_text_field((string) $token);
        if ($token === '') {
            return;
        }
        $drafts = get_option(self::CLIENT_FORM_DRAFTS_OPTION, []);
        if (!is_array($drafts)) {
            $drafts = [];
        }
        $drafts[$token] = $draft;
        update_option(self::CLIENT_FORM_DRAFTS_OPTION, $drafts, false);
    }

    /**
     * Clear token-scoped draft after successful publish save.
     *
     * @param string $token
     */
    protected static function clear_client_form_draft($token) {
        $token = sanitize_text_field((string) $token);
        if ($token === '') {
            return;
        }
        $drafts = get_option(self::CLIENT_FORM_DRAFTS_OPTION, []);
        if (!is_array($drafts) || !isset($drafts[$token])) {
            return;
        }
        unset($drafts[$token]);
        update_option(self::CLIENT_FORM_DRAFTS_OPTION, $drafts, false);
    }

    /**
     * Convert image field values that are URLs into attachment IDs (sideload from URL).
     *
     * @param array $row_data
     * @return array
     */
    protected static function convert_image_urls_to_attachments(array $row_data) {
        foreach ($row_data as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (stripos($key, 'image') === false) {
                continue;
            }
            if (!preg_match('#^https?://#', $value)) {
                continue;
            }
            $existing_id = attachment_url_to_postid($value);
            if ($existing_id) {
                $row_data[$key] = (int) $existing_id;
                continue;
            }
            $attach_id = self::sideload_image_from_url($value);
            if ($attach_id) {
                $row_data[$key] = $attach_id;
            }
        }
        return $row_data;
    }

    /**
     * Sideload an image from a URL into the media library. Returns attachment ID or 0.
     *
     * @param string $url
     * @return int
     */
    protected static function sideload_image_from_url($url) {
        $url = esc_url_raw($url);
        if (!$url) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $file_array = [
            'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
            'tmp_name' => $tmp,
        ];
        $attach_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            return 0;
        }
        return (int) $attach_id;
    }

    /**
     * Parse $_FILES['matrix_image'] and upload each file to media library. Enforces max image size (MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES).
     *
     * @param array $files Typically $_FILES.
     * @return array{by_index: array<int, array<string, int>>, errors: list<string>}
     */
    protected static function parse_image_uploads(array $files) {
        $result = [];
        $errors = [];
        $max_bytes = defined('MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES') ? (int) MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES : (2 * 1024 * 1024);
        if (empty($files['matrix_image']['name']) || !is_array($files['matrix_image']['name'])) {
            return ['by_index' => $result, 'errors' => $errors];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($files['matrix_image']['name'] as $i => $by_field) {
            if (!is_array($by_field)) {
                continue;
            }
            foreach ($by_field as $field_name => $name) {
                $name = sanitize_file_name($name);
                if (empty($name)) {
                    continue;
                }
                $error = isset($files['matrix_image']['error'][$i][$field_name]) ? (int) $files['matrix_image']['error'][$i][$field_name] : UPLOAD_ERR_NO_FILE;
                if ($error !== UPLOAD_ERR_OK) {
                    continue;
                }
                $file = [
                    'name'     => $name,
                    'type'     => isset($files['matrix_image']['type'][$i][$field_name]) ? $files['matrix_image']['type'][$i][$field_name] : '',
                    'tmp_name' => isset($files['matrix_image']['tmp_name'][$i][$field_name]) ? $files['matrix_image']['tmp_name'][$i][$field_name] : '',
                    'error'    => $error,
                    'size'     => isset($files['matrix_image']['size'][$i][$field_name]) ? (int) $files['matrix_image']['size'][$i][$field_name] : 0,
                ];
                if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    continue;
                }
                if ($file['size'] > $max_bytes) {
                    $errors[] = sprintf('Image "%s" is too large (max %s).', $name, size_format($max_bytes));
                    continue;
                }
                $upload = wp_handle_upload($file, ['test_form' => false]);
                if (isset($upload['error'])) {
                    $errors[] = sprintf('Upload failed for "%s": %s', $name, $upload['error']);
                    continue;
                }
                $attach_id = self::create_attachment_from_upload($upload);
                if ($attach_id) {
                    if (!isset($result[$i])) {
                        $result[$i] = [];
                    }
                    $result[$i][$field_name] = $attach_id;
                }
            }
        }
        return ['by_index' => $result, 'errors' => $errors];
    }

    /**
     * Parse $_FILES['matrix_video'] and upload each file to media library.
     *
     * @param array $files Typically $_FILES.
     * @return array{by_index: array<int, array<string, int>>, errors: list<string>}
     */
    protected static function parse_video_uploads(array $files) {
        $result = [];
        $errors = [];
        $max_bytes = defined('MATRIX_EXPORT_MAX_VIDEO_UPLOAD_BYTES') ? (int) MATRIX_EXPORT_MAX_VIDEO_UPLOAD_BYTES : (30 * 1024 * 1024);
        if (empty($files['matrix_video']['name']) || !is_array($files['matrix_video']['name'])) {
            return ['by_index' => $result, 'errors' => $errors];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($files['matrix_video']['name'] as $i => $by_field) {
            if (!is_array($by_field)) {
                continue;
            }
            foreach ($by_field as $field_name => $name) {
                $name = sanitize_file_name($name);
                if (empty($name)) {
                    continue;
                }
                $error = isset($files['matrix_video']['error'][$i][$field_name]) ? (int) $files['matrix_video']['error'][$i][$field_name] : UPLOAD_ERR_NO_FILE;
                if ($error !== UPLOAD_ERR_OK) {
                    continue;
                }
                $file = [
                    'name'     => $name,
                    'type'     => isset($files['matrix_video']['type'][$i][$field_name]) ? $files['matrix_video']['type'][$i][$field_name] : '',
                    'tmp_name' => isset($files['matrix_video']['tmp_name'][$i][$field_name]) ? $files['matrix_video']['tmp_name'][$i][$field_name] : '',
                    'error'    => $error,
                    'size'     => isset($files['matrix_video']['size'][$i][$field_name]) ? (int) $files['matrix_video']['size'][$i][$field_name] : 0,
                ];
                if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    continue;
                }
                if ($file['size'] > $max_bytes) {
                    $errors[] = sprintf('Video "%s" is too large (max %s).', $name, size_format($max_bytes));
                    continue;
                }
                $upload = wp_handle_upload($file, ['test_form' => false]);
                if (isset($upload['error'])) {
                    $errors[] = sprintf('Upload failed for "%s": %s', $name, $upload['error']);
                    continue;
                }
                $attach_id = self::create_attachment_from_upload($upload);
                if ($attach_id) {
                    if (!isset($result[$i])) {
                        $result[$i] = [];
                    }
                    $result[$i][$field_name] = $attach_id;
                }
            }
        }
        return ['by_index' => $result, 'errors' => $errors];
    }

    /**
     * Create a WordPress attachment from an upload array (file path, url, type).
     *
     * @param array $upload From wp_handle_upload: 'file', 'url', 'type'.
     * @return int Attachment ID or 0.
     */
    protected static function create_attachment_from_upload(array $upload) {
        if (empty($upload['file'])) {
            return 0;
        }
        $filename = basename($upload['file']);
        $attachment = [
            'post_mime_type' => isset($upload['type']) ? $upload['type'] : '',
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attach_id) || !$attach_id) {
            return 0;
        }
        wp_generate_attachment_metadata($attach_id, $upload['file']);
        return (int) $attach_id;
    }

    /**
     * Collect readable field-level changes for one block.
     *
     * @param array<int, string> $changes
     * @param int $post_id
     * @param string $source_field
     * @param int $block_index
     * @param array $before_block
     * @param array $after_block
     */
    protected static function collect_block_changes(array &$changes, $post_id, $source_field, $block_index, array $before_block, array $after_block) {
        $post = get_post($post_id);
        $post_label = $post ? $post->post_title : ('Post #' . (int) $post_id);
        $layout = isset($after_block['acf_fc_layout']) ? (string) $after_block['acf_fc_layout'] : '';
        foreach ($after_block as $field => $new_value) {
            if ($field === 'acf_fc_layout') {
                continue;
            }
            $old_value = isset($before_block[$field]) ? $before_block[$field] : '';
            if (!self::values_differ($old_value, $new_value)) {
                continue;
            }
            $changes[] = sprintf(
                '%s | %s | Block %d (%s) | %s: "%s" -> "%s"',
                $post_label,
                $source_field,
                (int) $block_index + 1,
                $layout,
                $field,
                self::stringify_change_value($old_value),
                self::stringify_change_value($new_value)
            );
        }
    }

    /**
     * Compare values safely (supports arrays/scalars).
     */
    protected static function values_differ($a, $b) {
        return wp_json_encode($a) !== wp_json_encode($b);
    }

    /**
     * Convert field value to short readable text for emails.
     */
    protected static function stringify_change_value($value) {
        if (is_array($value)) {
            if (isset($value['url']) || isset($value['title'])) {
                $url = isset($value['url']) ? (string) $value['url'] : '';
                $title = isset($value['title']) ? (string) $value['title'] : '';
                return self::limit_text(trim('url=' . $url . '; text=' . $title));
            }
            return self::limit_text(wp_json_encode($value));
        }
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $id = (int) $value;
            if ($id > 0) {
                $url = wp_get_attachment_url($id);
                if ($url) {
                    return self::limit_text($url);
                }
            }
        }
        $text = is_scalar($value) || $value === null ? (string) $value : wp_json_encode($value);
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return self::limit_text(trim((string) $text));
    }

    /**
     * Truncate long values for readable email lines.
     */
    protected static function limit_text($text, $max = 180) {
        $text = (string) $text;
        if ($text === '') {
            return '(empty)';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max - 3) . '...';
        }
        if (strlen($text) > $max) {
            return substr($text, 0, $max - 3) . '...';
        }
        return $text;
    }

    /**
     * Send notification email with full change list, if configured.
     *
     * @param array<int, string> $changes
     */
    protected static function send_change_notification(array $changes) {
        $option_key = defined('MATRIX_EXPORT_NOTIFY_EMAIL_OPTION') ? MATRIX_EXPORT_NOTIFY_EMAIL_OPTION : '';
        if ($option_key === '') {
            return;
        }
        $to = sanitize_email((string) get_option($option_key, ''));
        if ($to === '' || !is_email($to)) {
            return;
        }
        if (empty($changes)) {
            return;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Client content edits (%d changes)', $site_name, count($changes));
        $body = "A client submitted content edits.\n\n";
        $body .= "Date: " . wp_date('Y-m-d H:i:s') . "\n";
        $body .= "Site: " . home_url('/') . "\n";
        $body .= "Changes:\n";
        foreach ($changes as $line) {
            $body .= '- ' . $line . "\n";
        }
        wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }

    /**
     * Notify team that draft was saved (no publish).
     */
    protected static function send_draft_notification($token, array $rows_by_post, array $post_updates) {
        $option_key = defined('MATRIX_EXPORT_NOTIFY_EMAIL_OPTION') ? MATRIX_EXPORT_NOTIFY_EMAIL_OPTION : '';
        if ($option_key === '') {
            return;
        }
        $to = sanitize_email((string) get_option($option_key, ''));
        if ($to === '' || !is_email($to)) {
            return;
        }
        $post_ids = array_unique(array_merge(array_map('intval', array_keys($rows_by_post)), array_map('intval', array_keys($post_updates))));
        $labels = [];
        foreach ($post_ids as $post_id) {
            if ($post_id <= 0) continue;
            $title = get_the_title($post_id);
            if (!is_string($title) || $title === '') $title = 'Post #' . (int) $post_id;
            $labels[] = $title;
        }
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Client draft updated', $site_name);
        $body = "A client saved the content form as draft (Save for later).\n\n";
        $body .= "Date: " . wp_date('Y-m-d H:i:s') . "\n";
        $body .= "Site: " . home_url('/') . "\n";
        if (!empty($labels)) {
            $body .= "Pages/posts in draft:\n";
            foreach ($labels as $label) {
                $body .= '- ' . $label . "\n";
            }
        }
        if (is_string($token) && $token !== '') {
            $body .= "\nForm link: " . Matrix_Export::get_client_link_url($token) . "\n";
        }
        wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }

    /**
     * Decode submitted core post fields row.
     *
     * @param array $field_data
     * @return array<string, mixed>
     */
    protected static function decode_post_fields_update(array $field_data) {
        $out = [];
        foreach ($field_data as $key => $value) {
            $taxonomy = Matrix_Export::get_taxonomy_from_form_field_name($key);
            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                $term_ids = is_array($value) ? array_values(array_filter(array_map('intval', $value))) : [];
                if (!isset($out['taxonomies']) || !is_array($out['taxonomies'])) {
                    $out['taxonomies'] = [];
                }
                $out['taxonomies'][$taxonomy] = $term_ids;
                continue;
            }
            if (!in_array($key, ['core_post_title', 'post_title', 'post_content', 'post_excerpt', 'featured_image'], true)) {
                continue;
            }
            if ($key === 'core_post_title' || $key === 'post_title') {
                $title = is_string($value) ? wp_unslash($value) : (string) $value;
                $out['post_title'] = sanitize_text_field($title);
                continue;
            }
            if ($key === 'featured_image') {
                if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                    $id = (int) $value;
                    if ($id > 0) {
                        $out['featured_image'] = $id;
                    }
                }
                continue;
            }
            $text = is_string($value) ? wp_unslash($value) : (string) $value;
            $out[$key] = wp_kses_post($text);
        }
        return $out;
    }

    /**
     * Apply updates to WP core post fields.
     *
     * @param int $post_id
     * @param array<string, mixed> $update
     */
    protected static function apply_post_field_updates($post_id, array $update) {
        if ($post_id <= 0 || !get_post($post_id) || empty($update)) {
            return;
        }
        $post_update = ['ID' => (int) $post_id];
        if (array_key_exists('post_title', $update)) {
            $post_update['post_title'] = (string) $update['post_title'];
        }
        if (array_key_exists('post_content', $update)) {
            $post_update['post_content'] = (string) $update['post_content'];
        }
        if (array_key_exists('post_excerpt', $update)) {
            $post_update['post_excerpt'] = (string) $update['post_excerpt'];
        }
        if (count($post_update) > 1) {
            wp_update_post(wp_slash($post_update));
        }
        if (array_key_exists('featured_image', $update)) {
            set_post_thumbnail($post_id, (int) $update['featured_image']);
        }
        if (isset($update['taxonomies']) && is_array($update['taxonomies'])) {
            foreach ($update['taxonomies'] as $taxonomy => $term_ids) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                    continue;
                }
                $term_ids = is_array($term_ids) ? array_values(array_filter(array_map('intval', $term_ids))) : [];
                wp_set_post_terms($post_id, $term_ids, $taxonomy, false);
            }
        }
    }

    /**
     * Decode form field set into one ACF flexible content row. Handles _url / _title for links.
     */
    protected static function decode_form_row(array $field_data, $block_type) {
        $out = ['acf_fc_layout' => $block_type];
        $seen_link_keys = [];
        foreach ($field_data as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_int($value)) {
                self::assign_nested_field_value($out, $key, $value);
                continue;
            }
            $value = is_string($value) ? wp_unslash($value) : (string) $value;
            if (preg_match('/^(.+)__matrix_link_(url|title)$/', $key, $m)) {
                $base = $m[1];
                $part = $m[2];
                if (!isset($seen_link_keys[$base])) {
                    $seen_link_keys[$base] = ['url' => '', 'title' => ''];
                }
                $seen_link_keys[$base][$part] = ($part === 'url')
                    ? self::sanitize_link_url($value)
                    : sanitize_text_field($value);
                continue;
            }
            // Keep rich text edits from WYSIWYG while stripping unsafe markup.
            self::assign_nested_field_value($out, $key, wp_kses_post($value));
        }
        foreach ($seen_link_keys as $base => $link) {
            $url = isset($link['url']) ? (string) $link['url'] : '';
            $link_value = [
                'url'    => $url,
                'title'  => isset($link['title']) ? (string) $link['title'] : '',
                'target' => self::is_external_link_url($url) ? '_blank' : '',
            ];
            self::assign_nested_field_value($out, $base, $link_value);
        }
        return $out;
    }

    /**
     * Sanitize link URL but allow root-relative paths like /about.
     */
    protected static function sanitize_link_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '/') === 0) {
            return '/' . ltrim($url, '/');
        }
        if (preg_match('#^(https?:|mailto:|tel:)#i', $url)) {
            return esc_url_raw($url);
        }
        return sanitize_text_field($url);
    }

    /**
     * Whether URL points to an external host.
     *
     * @param string $url
     * @return bool
     */
    protected static function is_external_link_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        if (strpos($url, '/') === 0 || strpos($url, '#') === 0) {
            return false;
        }
        if (preg_match('#^(mailto:|tel:)#i', $url)) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $link_host = parse_url($url, PHP_URL_HOST);
        $site_host = parse_url(function_exists('home_url') ? home_url('/') : '', PHP_URL_HOST);
        if (!$link_host || !$site_host) {
            return false;
        }

        return strtolower((string) $link_host) !== strtolower((string) $site_host);
    }

    /**
     * Assign value into nested array path encoded as "parent__0__child".
     *
     * @param array $target
     * @param string $key
     * @param mixed $value
     */
    protected static function assign_nested_field_value(array &$target, $key, $value) {
        if (strpos($key, '__') === false) {
            $target[$key] = $value;
            return;
        }
        $parts = explode('__', (string) $key);
        $ref =& $target;
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $seg = ctype_digit($part) ? (int) $part : $part;
            if ($i === $last) {
                $ref[$seg] = $value;
                break;
            }
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref =& $ref[$seg];
        }
    }

    /**
     * Normalize values before update_field(): convert objects to scalar IDs/values
     * and strip unsupported WP_Error instances that can trigger ACF warnings.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function normalize_acf_value_for_save($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize_acf_value_for_save($v);
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
        if ($value instanceof WP_Post) {
            return isset($value->ID) ? (int) $value->ID : 0;
        }
        if ($value instanceof WP_User) {
            return isset($value->ID) ? (int) $value->ID : 0;
        }
        if (method_exists($value, '__toString')) {
            return (string) $value;
        }
        return 0;
    }

    /**
     * Persist disabled flex-block indices selected in the client form.
     *
     * @param array $rows_by_post Submitted rows grouped by post/source.
     * @param array<int, array<int, int>> $disabled_by_post Map post_id => list of disabled indices.
     */
    protected static function save_disabled_blocks(array $rows_by_post, array $disabled_by_post) {
        if (!defined('MATRIX_EXPORT_DISABLED_BLOCKS_OPTION')) {
            return;
        }
        $existing = get_option(MATRIX_EXPORT_DISABLED_BLOCKS_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        foreach ($rows_by_post as $post_id => $sources) {
            if (!isset($sources[Matrix_Export::FLEX_FIELD])) {
                continue;
            }
            $post_id = (int) $post_id;
            $disabled = isset($disabled_by_post[$post_id]) ? array_values(array_unique(array_map('intval', $disabled_by_post[$post_id]))) : [];
            if (!isset($existing[$post_id]) || !is_array($existing[$post_id])) {
                $existing[$post_id] = [];
            }
            if (!empty($disabled)) {
                $existing[$post_id][Matrix_Export::FLEX_FIELD] = $disabled;
            } else {
                unset($existing[$post_id][Matrix_Export::FLEX_FIELD]);
            }
            if (empty($existing[$post_id])) {
                unset($existing[$post_id]);
            }
        }

        update_option(MATRIX_EXPORT_DISABLED_BLOCKS_OPTION, $existing);
    }
}
