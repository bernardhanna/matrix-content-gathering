<?php
/**
 * Export pages/posts and their flexi blocks to CSV, Excel (one sheet per page), or ZIP of CSVs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_Export {

    const FLEX_FIELD = 'flexible_content_blocks';
    const HERO_FIELD = 'hero_content_blocks';
    const POST_FIELDS_SOURCE = 'post_fields';

    /** Default post types that typically use flexi in matrix-starter */
    const DEFAULT_POST_TYPES = ['page', 'post'];
    const CLIENT_LINKS_OPTION = 'matrix_export_client_links';

    /**
     * Post types to offer in the export selector (page, post, and public CPTs).
     *
     * @return array<string, string> key => label
     */
    public static function get_available_post_types() {
        $builtin = [
            'page' => 'Pages',
            'post' => 'Posts',
        ];
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $out = $builtin;
        foreach ($cpts as $pt) {
            $out[$pt->name] = $pt->labels->name;
        }
        return $out;
    }

    /**
     * Get posts for selection, optionally filtered by post type(s).
     *
     * @param array $post_types Optional. Post type names. Default all from get_available_post_types().
     * @return WP_Post[]
     */
    public static function get_posts_for_selection(array $post_types = []) {
        if (empty($post_types)) {
            $post_types = array_keys(self::get_available_post_types());
        }
        $query = new WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ]);
        return $query->posts;
    }

    /**
     * Get export data for specific posts. Returns one "sheet" (headers + rows) per post, with current content.
     *
     * @param array $post_ids Post IDs to export.
     * @return array{ post_id => array{ post_title, post_slug, post_type, headers, rows } }
     */
    public static function get_export_data_per_post(array $post_ids) {
        if (empty($post_ids)) {
            return [];
        }
        $post_ids = array_map('intval', array_unique($post_ids));
        $base_keys = ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type'];
        $result = [];

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }
            $all_keys = array_merge([], $base_keys);
            $rows = [];

            foreach ([self::HERO_FIELD, self::FLEX_FIELD] as $field_name) {
                $blocks = get_field($field_name, $post_id);
                if (!is_array($blocks) || empty($blocks)) {
                    continue;
                }
                $index = 0;
                foreach ($blocks as $row) {
                    $layout = isset($row['acf_fc_layout']) ? $row['acf_fc_layout'] : '';
                    $base = [
                        'post_id'      => $post_id,
                        'post_title'   => $post->post_title,
                        'post_slug'    => $post->post_name,
                        'post_type'    => $post->post_type,
                        'block_source' => $field_name,
                        'block_index'  => $index,
                        'block_type'   => $layout,
                    ];
                    $flat = self::flatten_block_row($row);
                    foreach (array_keys($flat) as $k) {
                        if (!in_array($k, $all_keys, true)) {
                            $all_keys[] = $k;
                        }
                    }
                    $rows[] = array_merge($base, $flat);
                    $index++;
                }
            }

            $result[$post_id] = [
                'post_title' => $post->post_title,
                'post_slug'  => $post->post_name,
                'post_type'  => $post->post_type,
                'headers'    => $all_keys,
                'rows'       => $rows,
            ];
        }

        return $result;
    }

    /**
     * Legacy: collect all posts (no filter) and return single flat list. Used for single CSV and doc.
     *
     * @param array|null $post_ids If provided, only these posts. Otherwise all (page + post).
     * @return array{headers: array, rows: array[]}
     */
    public static function get_export_data(array $post_ids = null) {
        $all_rows = [];
        $all_keys = ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type'];

        $post_types = $post_ids === null ? self::DEFAULT_POST_TYPES : [];
        if ($post_ids !== null) {
            $posts = array_filter(array_map('get_post', $post_ids));
        } else {
            $query = new WP_Query([
                'post_type'      => self::DEFAULT_POST_TYPES,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ]);
            $posts = $query->posts;
        }

        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            foreach ([self::HERO_FIELD => 'Hero blocks', self::FLEX_FIELD => 'Page content blocks'] as $field_name => $label) {
                $blocks = get_field($field_name, $post_id);
                if (!is_array($blocks) || empty($blocks)) {
                    continue;
                }
                $index = 0;
                foreach ($blocks as $row) {
                    $layout = isset($row['acf_fc_layout']) ? $row['acf_fc_layout'] : '';
                    $base = [
                        'post_id'       => $post_id,
                        'post_title'    => $post->post_title,
                        'post_slug'     => $post->post_name,
                        'post_type'     => $post->post_type,
                        'block_source'  => $field_name,
                        'block_index'   => $index,
                        'block_type'    => $layout,
                    ];
                    $flat = self::flatten_block_row($row);
                    foreach (array_keys($flat) as $k) {
                        if (!in_array($k, $all_keys, true)) {
                            $all_keys[] = $k;
                        }
                    }
                    $all_rows[] = array_merge($base, $flat);
                    $index++;
                }
            }
        }

        return [
            'headers' => $all_keys,
            'rows'    => $all_rows,
        ];
    }

    /**
     * Flatten one flexible content row to key => value (scalar or encoded string).
     */
    protected static function flatten_block_row(array $row) {
        $flat = [];
        foreach ($row as $key => $value) {
            if ($key === 'acf_fc_layout') {
                continue;
            }
            $flat[$key] = self::encode_value($value);
        }
        return $flat;
    }

    /**
     * Encode a value for CSV (links, arrays, objects -> string).
     */
    protected static function encode_value($value) {
        if (is_scalar($value) || $value === null) {
            return $value === null ? '' : (string) $value;
        }
        if (is_array($value)) {
            if (isset($value['ID']) && (isset($value['url']) || isset($value['sizes']) || isset($value['filename']))) {
                return 'IMAGE:' . $value['ID'] . "\t" . ($value['url'] ?? '');
            }
            if (isset($value['ID'])) {
                return 'IMAGE:' . $value['ID'];
            }
            if (isset($value['url']) || isset($value['title']) || isset($value['target'])) {
                $url   = isset($value['url']) ? (string) $value['url'] : '';
                $title = isset($value['title']) ? (string) $value['title'] : '';
                return 'LINK:' . $url . "\t" . $title;
            }
            return 'JSON:' . wp_json_encode($value);
        }
        if (is_object($value)) {
            return 'JSON:' . wp_json_encode($value);
        }
        return '';
    }

    /**
     * Sanitize a string for use as Excel sheet name (max 31 chars, no : \ / ? * [ ]).
     */
    public static function sanitize_sheet_name($title) {
        $s = preg_replace('/[\:\ \\\\\/\?\*\[\]]/', '', $title);
        return mb_substr($s, 0, 31);
    }

    /**
     * Download one Excel workbook with one sheet per selected post. Each sheet has current block content.
     * Requires PhpSpreadsheet (composer install). CSV format cannot contain multiple sheets.
     *
     * @param array $post_ids
     */
    public static function download_excel_or_zip(array $post_ids) {
        $post_ids = array_map('intval', array_filter($post_ids));
        if (empty($post_ids)) {
            wp_die('No posts selected.');
        }

        $per_post = self::get_export_data_per_post($post_ids);
        if (empty($per_post)) {
            wp_die('No content found for selected posts.');
        }

        $autoload = MATRIX_EXPORT_DIR . 'vendor/autoload.php';
        if (!is_file($autoload)) {
            $link = admin_url('tools.php?page=matrix-content-export');
            wp_die(
                '<p><strong>One file with one sheet per page requires Excel (.xlsx).</strong></p>' .
                '<p>CSV cannot contain multiple sheets. To generate a single Excel file with a sheet for each page, run:</p>' .
                '<p><code>composer install</code></p>' .
                '<p>inside the plugin directory: <code>wp-content/plugins/matrix-content-export</code></p>' .
                '<p><a href="' . esc_url($link) . '">Back to Content Export</a></p>',
                'Excel export requires Composer',
                ['response' => 503, 'back_link' => true]
            );
        }

        require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            wp_die('PhpSpreadsheet not found. Run <code>composer install</code> in the plugin directory.');
        }

        self::download_xlsx($per_post);
    }

    /**
     * Build and stream .xlsx with one sheet per post.
     *
     * @param array $per_post Result of get_export_data_per_post().
     */
    protected static function download_xlsx(array $per_post) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;
        $used_names = [];

        foreach ($per_post as $post_id => $data) {
            $title = $data['post_title'] ?: 'Post ' . $post_id;
            $sheet_name = self::sanitize_sheet_name($title);
            if (isset($used_names[$sheet_name])) {
                $used_names[$sheet_name]++;
                $sheet_name = self::sanitize_sheet_name($title . '-' . $used_names[$sheet_name]);
            } else {
                $used_names[$sheet_name] = 1;
            }

            if ($sheet_index === 0) {
                $sheet = $spreadsheet->getActiveSheet();
            } else {
                $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $sheet_name);
                $spreadsheet->addSheet($sheet);
            }
            $sheet->setTitle($sheet_name);

            $headers = $data['headers'];
            $row_num = 1;
            $col = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue($col . $row_num, $h);
                $col++;
            }
            $row_num++;
            foreach ($data['rows'] as $row) {
                $col = 'A';
                foreach ($headers as $h) {
                    $val = isset($row[$h]) ? $row[$h] : '';
                    $sheet->setCellValue($col . $row_num, $val);
                    $col++;
                }
                $row_num++;
            }

            $sheet_index++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'matrix-content-export-' . date('Y-m-d-His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $writer->save('php://output');
        exit;
    }

    /**
     * Download workbook of selected items grouped by post type, one row per post.
     * One form (one token) for all; each row has Form Link opening directly to that post's page in the form.
     * Columns: Status, Post Name, Post Type, Post URL, Form Link.
     *
     * @param array<int,int|string> $post_ids
     */
    public static function download_client_links_workbook(array $post_ids, array $link_options = []) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            wp_die('No posts selected. Select at least one page or post, then click "Download client links workbook (.xlsx)".', 'No selection', ['response' => 400]);
        }
        $autoload = MATRIX_EXPORT_DIR . 'vendor/autoload.php';
        if (!is_file($autoload)) {
            wp_die('Excel export requires PhpSpreadsheet (vendor/autoload.php missing). Run: composer install', 'Missing dependency', ['response' => 500]);
        }
        require_once $autoload;
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            wp_die('PhpSpreadsheet not found. Run composer install in plugin directory.', 'Missing dependency', ['response' => 500]);
        }

        $posts = array_filter(array_map('get_post', $post_ids));
        if (empty($posts)) {
            wp_die('No valid posts found for selection.', 'No posts', ['response' => 400]);
        }

        // One client link (one token) for the whole selection – one form with tabs
        $token = self::create_client_link($post_ids, $link_options);
        $base_form_url = self::get_client_link_url($token);
        $status_by_post = self::get_client_link_page_statuses($token, $post_ids);

        $post_types = self::get_available_post_types();
        $by_type = [];
        foreach ($posts as $post) {
            $pt = isset($post->post_type) ? (string) $post->post_type : 'other';
            if (!isset($by_type[$pt])) {
                $by_type[$pt] = [];
            }
            $by_type[$pt][] = $post;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet_index = 0;
        $used_names = [];
        $headers = ['Status', 'Post Name', 'Post Type', 'Post URL', 'Form Link'];

        foreach ($by_type as $pt => $type_posts) {
            $label = isset($post_types[$pt]) ? (string) $post_types[$pt] : ucfirst($pt);
            $sheet_name = self::sanitize_sheet_name($label);
            if (isset($used_names[$sheet_name])) {
                $used_names[$sheet_name]++;
                $sheet_name = self::sanitize_sheet_name($label . '-' . $used_names[$sheet_name]);
            } else {
                $used_names[$sheet_name] = 1;
            }

            if ($sheet_index === 0) {
                $sheet = $spreadsheet->getActiveSheet();
            } else {
                $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $sheet_name);
                $spreadsheet->addSheet($sheet);
            }
            $sheet->setTitle($sheet_name);

            $col = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue($col . '1', $h);
                $col++;
            }

            $row_num = 2;
            foreach ($type_posts as $post) {
                $post_id = (int) $post->ID;
                $post_url = get_permalink($post_id);
                $form_tab_link = add_query_arg('matrix_page', $post_id, $base_form_url);
                // Open this row directly on its page in the dropdown form.
                $form_link = $form_tab_link;
                $status_key = isset($status_by_post[$post_id]) ? (string) $status_by_post[$post_id] : 'todo';
                $status_label = self::get_page_status_label($status_key);
                $values = [
                    $status_label,
                    (string) $post->post_title,
                    (string) $pt,
                    (string) $post_url,
                    (string) $form_link,
                ];
                $col = 'A';
                foreach ($values as $v) {
                    $sheet->setCellValue($col . $row_num, $v);
                    $col++;
                }
                $row_num++;
            }

            foreach (range('A', 'E') as $auto_col) {
                $sheet->getColumnDimension($auto_col)->setAutoSize(true);
            }

            $sheet_index++;
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'matrix-client-links-' . date('Y-m-d-His') . '.xlsx';
        if (function_exists('wp_ob_end_flush_all')) {
            wp_ob_end_flush_all();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        $writer->save('php://output');
        exit;
    }

    /**
     * Resolve per-page status for workbook rows.
     * Priority: token draft status, then post meta, then default "todo".
     *
     * @param string $token
     * @param array<int,int> $post_ids
     * @return array<int,string> post_id => status key
     */
    protected static function get_client_link_page_statuses($token, array $post_ids) {
        $out = [];
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return $out;
        }

        // Try token draft status first.
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token !== '') {
            $drafts = get_option('matrix_export_client_form_drafts', []);
            if (is_array($drafts) && isset($drafts[$token]) && is_array($drafts[$token])) {
                $draft = $drafts[$token];
                $draft_status = isset($draft['page_status']) && is_array($draft['page_status']) ? $draft['page_status'] : [];
                foreach ($draft_status as $pid => $status) {
                    $pid = (int) $pid;
                    $status = sanitize_text_field((string) $status);
                    if ($pid > 0 && in_array($status, ['todo', 'inprogress', 'done', 'delete'], true)) {
                        $out[$pid] = $status;
                    }
                }
            }
        }

        // Fill missing items from post meta status.
        foreach ($post_ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || isset($out[$pid])) {
                continue;
            }
            $meta = defined('MATRIX_EXPORT_STATUS_META_KEY') ? get_post_meta($pid, MATRIX_EXPORT_STATUS_META_KEY, true) : '';
            if (in_array($meta, ['todo', 'inprogress', 'done', 'delete'], true)) {
                $out[$pid] = (string) $meta;
                continue;
            }
            $out[$pid] = 'todo';
        }

        return $out;
    }

    /**
     * Convert status key to workbook label.
     *
     * @param string $status
     * @return string
     */
    protected static function get_page_status_label($status) {
        if ($status === 'done') {
            return 'Done';
        }
        if ($status === 'inprogress') {
            return 'In progress';
        }
        if ($status === 'delete') {
            return 'Delete';
        }
        return 'To do';
    }

    /**
     * Single CSV of all selected posts (flat rows, with post_id column). For import round-trip.
     */
    public static function download_csv(array $post_ids = null) {
        $data = self::get_export_data($post_ids);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            wp_die('Could not create CSV stream.');
        }
        fputcsv($out, $data['headers']);
        foreach ($data['rows'] as $row) {
            $line = [];
            foreach ($data['headers'] as $h) {
                $line[] = isset($row[$h]) ? $row[$h] : '';
            }
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $filename = 'matrix-content-export-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        echo $csv;
        exit;
    }

    /**
     * Build client-friendly HTML doc (all selected or all posts). One section per block.
     */
    public static function download_doc(array $post_ids = null) {
        $data = self::get_export_data($post_ids);
        ob_start();
        include MATRIX_EXPORT_DIR . 'templates/export-doc.php';
        $html = ob_get_clean();

        $filename = 'matrix-content-brief-' . date('Y-m-d-His') . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit;
    }

    /**
     * Get or create the one-time token for client form submission. Stored in options.
     */
    public static function get_form_token() {
        $token = get_option('matrix_export_form_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            update_option('matrix_export_form_token', $token);
        }
        return $token;
    }

    /**
     * Return all saved client links keyed by token.
     *
     * @return array<string, array{post_ids: array<int,int>, created_at: int, created_by: int, expires_at: int, reminder_days: int, custom_instructions: string, requires_approval: bool, strict_mode: bool, ai_mode: bool, strict_field_rules: array<string,array{enabled:int,enable_ai_mode:int,enable_required:int,enable_min_words:int,enable_max_words:int,enable_min_chars:int,enable_max_chars:int,min_words:int,max_words:int,min_chars:int,max_chars:int}>}>
     */
    public static function get_client_links() {
        $links = get_option(self::CLIENT_LINKS_OPTION, []);
        if (!is_array($links)) {
            return [];
        }
        $clean = [];
        foreach ($links as $token => $entry) {
            if (!is_string($token) || !preg_match('/^[a-f0-9]{24,128}$/', $token)) {
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $post_ids = isset($entry['post_ids']) && is_array($entry['post_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $entry['post_ids']))))
                : [];
            if (empty($post_ids)) {
                continue;
            }
            $default_ai_mode = false;
            if (function_exists('matrix_export_get_ai_settings')) {
                $ai_settings = matrix_export_get_ai_settings();
                $default_ai_mode = !empty($ai_settings['enabled']);
            }
            $clean[$token] = [
                'post_ids'    => $post_ids,
                'created_at'  => isset($entry['created_at']) ? (int) $entry['created_at'] : time(),
                'created_by'  => isset($entry['created_by']) ? (int) $entry['created_by'] : 0,
                'expires_at'  => isset($entry['expires_at']) ? max(0, (int) $entry['expires_at']) : 0,
                'reminder_days' => isset($entry['reminder_days']) ? max(0, (int) $entry['reminder_days']) : 0,
                'custom_instructions' => isset($entry['custom_instructions']) ? wp_kses_post((string) $entry['custom_instructions']) : '',
                'requires_approval' => !empty($entry['requires_approval']),
                'strict_mode' => !empty($entry['strict_mode']),
                'ai_mode' => array_key_exists('ai_mode', $entry) ? !empty($entry['ai_mode']) : $default_ai_mode,
                'strict_field_rules' => self::sanitize_strict_field_rules(isset($entry['strict_field_rules']) && is_array($entry['strict_field_rules']) ? $entry['strict_field_rules'] : []),
            ];
        }
        return $clean;
    }

    /**
     * Generate and save a unique client link token for selected posts.
     *
     * @param array<int, int|string> $post_ids
     * @param array{expires_days?: int, reminder_days?: int, custom_instructions?: string, requires_approval?: bool|int|string, strict_mode?: bool|int|string, ai_mode?: bool|int|string} $options
     * @return string Token
     */
    public static function create_client_link(array $post_ids, array $options = []) {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) {
            return '';
        }
        $expires_days = isset($options['expires_days']) ? max(0, (int) $options['expires_days']) : 0;
        $reminder_days = isset($options['reminder_days']) ? max(0, (int) $options['reminder_days']) : 0;
        $custom_instructions = isset($options['custom_instructions']) ? wp_kses_post((string) $options['custom_instructions']) : '';
        $requires_approval = !empty($options['requires_approval']);
        $strict_mode = !empty($options['strict_mode']);
        $default_ai_mode = false;
        if (function_exists('matrix_export_get_ai_settings')) {
            $ai_settings = matrix_export_get_ai_settings();
            $default_ai_mode = !empty($ai_settings['enabled']);
        }
        $ai_mode = array_key_exists('ai_mode', $options) ? !empty($options['ai_mode']) : $default_ai_mode;
        $expires_at = $expires_days > 0 ? (time() + ($expires_days * DAY_IN_SECONDS)) : 0;
        $links = self::get_client_links();
        $token = bin2hex(random_bytes(24));
        $links[$token] = [
            'post_ids'    => $post_ids,
            'created_at'  => time(),
            'created_by'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'expires_at'  => $expires_at,
            'reminder_days' => $reminder_days,
            'custom_instructions' => $custom_instructions,
            'requires_approval' => $requires_approval,
            'strict_mode' => $strict_mode,
            'ai_mode' => $ai_mode,
            'strict_field_rules' => [],
        ];
        update_option(self::CLIENT_LINKS_OPTION, $links, false);
        return $token;
    }

    /**
     * Get full client link entry by token.
     *
     * @param string $token
     * @param bool $include_expired
     * @return array<string,mixed>
     */
    public static function get_client_link_entry($token, $include_expired = false) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token === '') {
            return [];
        }
        $links = self::get_client_links();
        if (empty($links[$token]) || !is_array($links[$token])) {
            return [];
        }
        $entry = $links[$token];
        if (!$include_expired && self::is_client_link_expired($entry)) {
            return [];
        }
        return $entry;
    }

    /**
     * Whether a client link has expired.
     *
     * @param array<string,mixed> $entry
     * @return bool
     */
    public static function is_client_link_expired(array $entry) {
        $expires_at = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
        return ($expires_at > 0 && time() > $expires_at);
    }

    /**
     * Get selected post IDs for a client link token.
     *
     * @param string $token
     * @return array<int,int>
     */
    public static function get_client_link_post_ids($token, $allow_expired = false) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token === '') {
            return [];
        }
        $entry = self::get_client_link_entry($token, (bool) $allow_expired);
        if (empty($entry['post_ids']) || !is_array($entry['post_ids'])) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $entry['post_ids']))));
    }

    /**
     * Build the public URL for a tokenized client link.
     *
     * @param string $token
     * @return string
     */
    public static function get_client_link_url($token) {
        $base = home_url('/' . MATRIX_EXPORT_CLIENT_FORM_SLUG . '/');
        if (!is_string($token) || $token === '') {
            return $base;
        }
        return add_query_arg('matrix_token', rawurlencode($token), $base);
    }

    /**
     * Delete one generated client link.
     *
     * @param string $token
     * @return void
     */
    public static function delete_client_link($token) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token === '') {
            return;
        }
        $links = self::get_client_links();
        if (!isset($links[$token])) {
            return;
        }
        unset($links[$token]);
        update_option(self::CLIENT_LINKS_OPTION, $links, false);
    }

    /**
     * Update moderation mode for an existing client link.
     *
     * @param string $token
     * @param bool $requires_approval
     * @return bool
     */
    public static function update_client_link_requires_approval($token, $requires_approval) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token === '') {
            return false;
        }
        $links = self::get_client_links();
        if (!isset($links[$token]) || !is_array($links[$token])) {
            return false;
        }
        $links[$token]['requires_approval'] = (bool) $requires_approval;
        update_option(self::CLIENT_LINKS_OPTION, $links, false);
        return true;
    }

    /**
     * Update publish/strict/AI modes for an existing client link.
     *
     * @param string $token
     * @param bool $requires_approval
     * @param bool $strict_mode
     * @param bool $ai_mode
     * @return bool
     */
    public static function update_client_link_modes($token, $requires_approval, $strict_mode, $ai_mode) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        if ($token === '') {
            return false;
        }
        $links = self::get_client_links();
        if (!isset($links[$token]) || !is_array($links[$token])) {
            return false;
        }
        $links[$token]['requires_approval'] = (bool) $requires_approval;
        $links[$token]['strict_mode'] = (bool) $strict_mode;
        $links[$token]['ai_mode'] = (bool) $ai_mode;
        update_option(self::CLIENT_LINKS_OPTION, $links, false);
        return true;
    }

    /**
     * Save strict rule for one row+field key on a client link.
     *
     * @param string $token
     * @param string $rule_key
     * @param array{enabled:int|bool,enable_ai_mode?:int|bool,enable_required?:int|bool,enable_min_words?:int|bool,enable_max_words?:int|bool,enable_min_chars?:int|bool,enable_max_chars?:int|bool,min_words:int,max_words:int,min_chars:int,max_chars:int} $rule
     * @return bool
     */
    public static function update_client_link_strict_field_rule($token, $rule_key, array $rule) {
        $token = is_string($token) ? strtolower(trim($token)) : '';
        $rule_key = is_string($rule_key) ? trim($rule_key) : '';
        if ($token === '' || $rule_key === '' || !preg_match('/^\d+::[A-Za-z0-9_\-]+$/', $rule_key)) {
            return false;
        }
        $links = self::get_client_links();
        if (!isset($links[$token]) || !is_array($links[$token])) {
            return false;
        }
        $rules = isset($links[$token]['strict_field_rules']) && is_array($links[$token]['strict_field_rules']) ? $links[$token]['strict_field_rules'] : [];
        $rules[$rule_key] = [
            'enabled' => !empty($rule['enabled']) ? 1 : 0,
            'enable_ai_mode' => array_key_exists('enable_ai_mode', $rule) ? (!empty($rule['enable_ai_mode']) ? 1 : 0) : 1,
            'enable_required' => !empty($rule['enable_required']) ? 1 : 0,
            'enable_min_words' => !empty($rule['enable_min_words']) ? 1 : 0,
            'enable_max_words' => !empty($rule['enable_max_words']) ? 1 : 0,
            'enable_min_chars' => !empty($rule['enable_min_chars']) ? 1 : 0,
            'enable_max_chars' => !empty($rule['enable_max_chars']) ? 1 : 0,
            'min_words' => isset($rule['min_words']) ? max(0, (int) $rule['min_words']) : 0,
            'max_words' => isset($rule['max_words']) ? max(0, (int) $rule['max_words']) : 0,
            'min_chars' => isset($rule['min_chars']) ? max(0, (int) $rule['min_chars']) : 0,
            'max_chars' => isset($rule['max_chars']) ? max(0, (int) $rule['max_chars']) : 0,
        ];
        $links[$token]['strict_field_rules'] = self::sanitize_strict_field_rules($rules);
        update_option(self::CLIENT_LINKS_OPTION, $links, false);
        return true;
    }

    /**
     * @param array<string,mixed> $rules
     * @return array<string,array{enabled:int,min_words:int,max_words:int,min_chars:int,max_chars:int}>
     */
    protected static function sanitize_strict_field_rules(array $rules) {
        $clean = [];
        foreach ($rules as $rule_key => $rule) {
            $rule_key = is_string($rule_key) ? trim($rule_key) : '';
            if ($rule_key === '' || !preg_match('/^\d+::[A-Za-z0-9_\-]+$/', $rule_key)) {
                continue;
            }
            if (!is_array($rule)) {
                continue;
            }
            $enabled = !empty($rule['enabled']) ? 1 : 0;
            $enable_ai_mode = array_key_exists('enable_ai_mode', $rule) ? (!empty($rule['enable_ai_mode']) ? 1 : 0) : 1;
            $enable_required = !empty($rule['enable_required']) ? 1 : 0;
            $enable_min_words = !empty($rule['enable_min_words']) ? 1 : 0;
            $enable_max_words = !empty($rule['enable_max_words']) ? 1 : 0;
            $enable_min_chars = !empty($rule['enable_min_chars']) ? 1 : 0;
            $enable_max_chars = !empty($rule['enable_max_chars']) ? 1 : 0;
            $min_words = isset($rule['min_words']) ? max(0, (int) $rule['min_words']) : 0;
            $max_words = isset($rule['max_words']) ? max(0, (int) $rule['max_words']) : 0;
            $min_chars = isset($rule['min_chars']) ? max(0, (int) $rule['min_chars']) : 0;
            $max_chars = isset($rule['max_chars']) ? max(0, (int) $rule['max_chars']) : 0;
            if ($max_words > 0 && $min_words > 0 && $max_words < $min_words) {
                $max_words = $min_words;
            }
            if ($max_chars > 0 && $min_chars > 0 && $max_chars < $min_chars) {
                $max_chars = $min_chars;
            }
            $clean[$rule_key] = [
                'enabled' => $enabled,
                'enable_ai_mode' => $enable_ai_mode,
                'enable_required' => $enable_required,
                'enable_min_words' => $enable_min_words,
                'enable_max_words' => $enable_max_words,
                'enable_min_chars' => $enable_min_chars,
                'enable_max_chars' => $enable_max_chars,
                'min_words' => $min_words,
                'max_words' => $max_words,
                'min_chars' => $min_chars,
                'max_chars' => $max_chars,
            ];
        }
        return $clean;
    }

    /**
     * Remove all generated client links.
     *
     * @return void
     */
    public static function clear_client_links() {
        delete_option(self::CLIENT_LINKS_OPTION);
    }

    /**
     * Export data as a flat list of blocks with index i for form field names.
     * Meta keys (post_id, block_source, etc.) plus field names with raw values for form display.
     *
     * @param array|null $post_ids
     * @return array{headers: array, rows: array[], meta_keys: array}
     */
    public static function get_export_data_for_form(array $post_ids = null, $apply_visibility_settings = true) {
        $all_rows = [];
        $all_keys = ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type'];
        if ($post_ids !== null) {
            $posts = array_filter(array_map('get_post', $post_ids));
        } else {
            $query = new WP_Query([
                'post_type'      => self::DEFAULT_POST_TYPES,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ]);
            $posts = $query->posts;
        }

        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            $core_row = self::build_core_post_fields_row($post);
            if (!empty($core_row)) {
                $base = [
                    'post_id'       => $post_id,
                    'post_title'    => $post->post_title,
                    'post_slug'     => $post->post_name,
                    'post_type'     => $post->post_type,
                    'block_source'  => self::POST_FIELDS_SOURCE,
                    'block_index'   => 0,
                    'block_type'    => 'core_post_fields',
                ];
                foreach (array_keys($core_row) as $k) {
                    if (!in_array($k, $all_keys, true)) {
                        $all_keys[] = $k;
                    }
                }
                $all_rows[] = array_merge($base, $core_row);
            }
            foreach ([self::HERO_FIELD, self::FLEX_FIELD] as $field_name) {
                $blocks = get_field($field_name, $post_id);
                if (!is_array($blocks) || empty($blocks)) {
                    continue;
                }
                $index = 0;
                foreach ($blocks as $row) {
                    $layout = isset($row['acf_fc_layout']) ? $row['acf_fc_layout'] : '';
                    $base = [
                        'post_id'       => $post_id,
                        'post_title'    => $post->post_title,
                        'post_slug'     => $post->post_name,
                        'post_type'     => $post->post_type,
                        'block_source'  => $field_name,
                        'block_index'   => $index,
                        'block_type'    => $layout,
                    ];
                    $flat = self::flatten_block_row_for_form($row);
                    foreach (array_keys($flat) as $k) {
                        if (!in_array($k, $all_keys, true)) {
                            $all_keys[] = $k;
                        }
                    }
                    $merged = array_merge($base, $flat);
                    $merged['block_type'] = $layout;
                    $all_rows[] = $merged;
                    $index++;
                }
            }
        }

        $data = ['headers' => $all_keys, 'rows' => $all_rows];
        $meta_keys = ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type'];
        $field_keys = array_diff($data['headers'], $meta_keys);
        $disabled_fields = $apply_visibility_settings ? self::get_disabled_form_fields() : [];
        if (!empty($disabled_fields)) {
            $disabled_map = array_fill_keys($disabled_fields, true);
            $filtered_rows = [];
            foreach ($data['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (array_keys($row) as $row_key) {
                    if (!isset($disabled_map[$row_key])) {
                        continue;
                    }
                    unset($row[$row_key]);
                }
                $filtered_rows[] = $row;
            }
            $data['rows'] = $filtered_rows;
            $field_keys = array_values(array_filter($field_keys, function ($key) use ($disabled_map) {
                return !isset($disabled_map[$key]);
            }));
            $data['headers'] = array_merge($meta_keys, $field_keys);
        }
        return [
            'headers'    => $data['headers'],
            'rows'       => $data['rows'],
            'meta_keys'  => $meta_keys,
            'field_keys' => array_values($field_keys),
        ];
    }

    /**
     * Return available editable field keys for admin field-visibility settings.
     *
     * @return array<int,string>
     */
    public static function get_all_content_field_keys() {
        $data = self::get_export_data_for_form(null, false);
        $keys = isset($data['field_keys']) && is_array($data['field_keys']) ? $data['field_keys'] : [];
        $keys = array_values(array_filter(array_map('strval', $keys)));
        sort($keys);
        return $keys;
    }

    /**
     * Return map of field key => post types where that field appears.
     *
     * @return array<string, array<int,string>>
     */
    public static function get_content_field_key_post_types_map() {
        $data = self::get_export_data_for_form(null, false);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        $field_keys = isset($data['field_keys']) && is_array($data['field_keys']) ? $data['field_keys'] : [];
        $map = [];
        foreach ($field_keys as $field_key) {
            $field_key = (string) $field_key;
            if ($field_key !== '') {
                $map[$field_key] = [];
            }
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $post_type = isset($row['post_type']) ? sanitize_key((string) $row['post_type']) : '';
            if ($post_type === '') {
                continue;
            }
            foreach ($field_keys as $field_key) {
                $field_key = (string) $field_key;
                if ($field_key === '' || !array_key_exists($field_key, $row)) {
                    continue;
                }
                if (!isset($map[$field_key])) {
                    $map[$field_key] = [];
                }
                $map[$field_key][$post_type] = $post_type;
            }
        }
        foreach ($map as $field_key => $types) {
            $map[$field_key] = array_values($types);
            sort($map[$field_key]);
        }
        return $map;
    }

    /**
     * Return list of field keys hidden from the client form.
     *
     * @return array<int,string>
     */
    public static function get_disabled_form_fields() {
        $option_key = defined('MATRIX_EXPORT_DISABLED_FORM_FIELDS_OPTION') ? MATRIX_EXPORT_DISABLED_FORM_FIELDS_OPTION : '';
        if ($option_key === '') {
            return [];
        }
        $value = get_option($option_key, []);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $field_key) {
            $field_key = sanitize_key((string) $field_key);
            if ($field_key !== '') {
                $out[] = $field_key;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Build editable core WP post fields row (classic editor content/excerpt/featured image).
     *
     * @param WP_Post $post
     * @return array<string, string>
     */
    protected static function build_core_post_fields_row($post) {
        if (!$post || !($post instanceof WP_Post)) {
            return [];
        }
        $post_type = $post->post_type;
        $row = [];
        if (post_type_supports($post_type, 'title') || !empty($post->post_title)) {
            $row['core_post_title'] = (string) $post->post_title;
        }
        if (post_type_supports($post_type, 'editor') || !empty($post->post_content)) {
            $row['post_content'] = (string) $post->post_content;
        }
        if (post_type_supports($post_type, 'excerpt') || !empty($post->post_excerpt)) {
            $row['post_excerpt'] = (string) $post->post_excerpt;
        }
        $thumb_id = (int) get_post_thumbnail_id($post->ID);
        if (post_type_supports($post_type, 'thumbnail') || $thumb_id > 0) {
            if ($thumb_id > 0) {
                $url = wp_get_attachment_image_url($thumb_id, 'full');
                $row['featured_image'] = 'IMAGE:' . $thumb_id . ($url ? "\t" . $url : '');
            } else {
                $row['featured_image'] = '';
            }
        }
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                if (!is_object($taxonomy) || empty($taxonomy->name) || empty($taxonomy->show_ui)) {
                    continue;
                }
                $taxonomy_name = (string) $taxonomy->name;
                $terms = get_the_terms((int) $post->ID, $taxonomy_name);
                $term_ids = [];
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (is_object($term) && isset($term->term_id)) {
                            $term_ids[] = (int) $term->term_id;
                        }
                    }
                }
                $row['taxonomy__' . $taxonomy_name] = 'TAX:' . $taxonomy_name . "\t" . implode(',', $term_ids);
            }
        }
        return $row;
    }

    /**
     * Flatten one flexible-content block for editable form fields.
     * Supports existing repeater/group values by flattening nested keys using "__".
     *
     * Example: slides[0].heading => slides__0__heading
     */
    protected static function flatten_block_row_for_form(array $row) {
        $flat = [];
        foreach ($row as $key => $value) {
            if ($key === 'acf_fc_layout') {
                continue;
            }
            self::flatten_form_value($flat, $key, $value);
        }
        return $flat;
    }

    /**
     * Recursive flattener used for form export only.
     *
     * @param array<string, string> $flat
     * @param string $prefix
     * @param mixed $value
     */
    protected static function flatten_form_value(array &$flat, $prefix, $value) {
        if (is_array($value)) {
            // Keep native encoding for ACF image/link arrays.
            if (isset($value['ID']) && (isset($value['url']) || isset($value['sizes']) || isset($value['filename']))) {
                $flat[$prefix] = 'IMAGE:' . $value['ID'] . "\t" . ($value['url'] ?? '');
                return;
            }
            if (isset($value['ID']) && !isset($value['url']) && !isset($value['title']) && !isset($value['target'])) {
                $flat[$prefix] = 'IMAGE:' . $value['ID'];
                return;
            }
            if (isset($value['url']) || isset($value['title']) || isset($value['target'])) {
                $url = isset($value['url']) ? (string) $value['url'] : '';
                $title = isset($value['title']) ? (string) $value['title'] : '';
                $flat[$prefix] = 'LINK:' . $url . "\t" . $title;
                return;
            }
            foreach ($value as $k => $v) {
                $next = $prefix . '__' . (string) $k;
                self::flatten_form_value($flat, $next, $v);
            }
            return;
        }
        if (is_object($value)) {
            $flat[$prefix] = 'JSON:' . wp_json_encode($value);
            return;
        }
        $flat[$prefix] = $value === null ? '' : (string) $value;
    }

    /**
     * Field names that indicate editable content (text, textarea, WYSIWYG, URL, link, image, video).
     * Only these are shown in the client form; design/layout controls are hidden.
     */
    public static function get_form_included_field_patterns() {
        $exact = [
            'heading', 'title', 'subtitle', 'description', 'content', 'text', 'body', 'copy', 'excerpt', 'summary',
            'button', 'link', 'button_link', 'cta', 'url', 'wysiwyg', 'caption', 'label', 'name', 'intro',
            'image', 'video', 'media_type',
        ];
        $contains = ['heading', 'title', 'description', 'content', 'text', 'body', 'copy', 'excerpt', 'button', 'link', 'cta', 'url', 'caption', 'label', 'wysiwyg', 'intro', 'summary', 'image', 'video'];
        $rules = ['exact' => $exact, 'contains' => $contains];
        return apply_filters('matrix_export_form_included_patterns', $rules);
    }

    /**
     * Design/layout fields to exclude (heading_tag, colors, radii, dividers, padding, etc.).
     * These would confuse clients – we only want content-editing fields.
     */
    public static function get_form_excluded_field_patterns() {
        $exact = [
            'background_type', 'background_image', 'background_video_type', 'background_video_file',
            'background_video_youtube', 'background_video_vimeo', 'video_poster',
            'overlay_enabled', 'overlay_color', 'overlay_opacity',
            'content_box_bg_color', 'content_box_bg_opacity', 'content_box_border_color',
            'content_box_border_width', 'content_box_position', 'max_height', 'padding_settings',
            'heading_tag', 'border_color', 'show_divider', 'text_color', 'section_border_radius',
            'image_border_radius', 'screen_size', 'padding_top', 'padding_bottom',
        ];
        $prefixes = ['background_', 'overlay_', 'content_box_', 'padding_'];
        $suffixes = ['_tag', '_color', '_radius', '_opacity', '_width', '_height', '_border'];
        $rules = ['exact' => $exact, 'prefixes' => $prefixes, 'suffixes' => $suffixes];
        return apply_filters('matrix_export_form_excluded_patterns', $rules);
    }

    /**
     * Whether this field should be shown in the client form.
     * Only content-editing fields (text, textarea, WYSIWYG, URL, link, image, video); no design controls.
     */
    public static function is_content_field($field_name) {
        $name = $field_name;
        if (strpos($name, 'taxonomy__') === 0) {
            return true;
        }
        $excluded = self::get_form_excluded_field_patterns();
        if (in_array($name, $excluded['exact'], true)) {
            return false;
        }
        foreach ($excluded['prefixes'] as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return false;
            }
        }
        if (!empty($excluded['suffixes'])) {
            foreach ($excluded['suffixes'] as $suffix) {
                if (strlen($name) > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
                    return false;
                }
            }
        }
        $included = self::get_form_included_field_patterns();
        if (in_array($name, $included['exact'], true)) {
            return true;
        }
        foreach ($included['contains'] as $sub) {
            if (stripos($name, $sub) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a form field value should be rendered as textarea (long text).
     */
    public static function is_long_text_field($key, $value) {
        $long_keys = ['description', 'content', 'text', 'wysiwyg', 'body', 'copy'];
        foreach ($long_keys as $k) {
            if (stripos($key, $k) !== false) {
                return true;
            }
        }
        if (is_string($value) && (strlen($value) > 200 || strpos($value, "\n") !== false)) {
            return true;
        }
        return false;
    }

    /**
     * Parse encoded value for form: return plain value or [url, title] for LINK.
     *
     * @param mixed $value
     * @param string $field_name Field key helps disambiguate scalar image IDs.
     */
    public static function get_form_field_value($value, $field_name = '') {
        if (!is_string($value)) {
            $taxonomy = self::get_taxonomy_from_form_field_name($field_name);
            if ($taxonomy !== '' && is_array($value)) {
                return [
                    'type' => 'taxonomy',
                    'taxonomy' => $taxonomy,
                    'term_ids' => array_values(array_filter(array_map('intval', $value))),
                    'value' => $value,
                ];
            }
            if ((is_int($value) || is_float($value)) && self::looks_like_image_field_name($field_name)) {
                $id = (int) $value;
                if ($id > 0) {
                    $url = wp_get_attachment_image_url($id, 'full');
                    return ['type' => 'image', 'id' => $id, 'url' => $url ? $url : '', 'value' => (string) $value];
                }
            }
            if ((is_int($value) || is_float($value)) && self::looks_like_video_file_field_name($field_name)) {
                $id = (int) $value;
                if ($id > 0) {
                    $url = wp_get_attachment_url($id);
                    return ['type' => 'video', 'id' => $id, 'url' => $url ? $url : '', 'value' => (string) $value];
                }
            }
            return ['type' => 'text', 'value' => $value === null ? '' : (string) $value];
        }
        if (strpos($value, 'LINK:') === 0) {
            $rest = substr($value, 5);
            $parts = explode("\t", $rest, 2);
            return [
                'type'   => 'link',
                'url'    => isset($parts[0]) ? $parts[0] : '',
                'title'  => isset($parts[1]) ? $parts[1] : '',
            ];
        }
        if (strpos($value, 'TAX:') === 0) {
            $rest = substr($value, 4);
            $parts = explode("\t", $rest, 2);
            $taxonomy = isset($parts[0]) ? sanitize_key((string) $parts[0]) : '';
            $raw_ids = isset($parts[1]) ? (string) $parts[1] : '';
            $term_ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', trim($raw_ids), -1, PREG_SPLIT_NO_EMPTY))));
            if ($taxonomy === '') {
                $taxonomy = self::get_taxonomy_from_form_field_name($field_name);
            }
            return [
                'type' => 'taxonomy',
                'taxonomy' => $taxonomy,
                'term_ids' => $term_ids,
                'value' => $value,
            ];
        }
        if (strpos($value, 'IMAGE:') === 0) {
            $rest = trim(substr($value, 6));
            $parts = explode("\t", $rest, 2);
            $id = isset($parts[0]) ? (int) $parts[0] : 0;
            $url = isset($parts[1]) ? trim($parts[1]) : '';
            return ['type' => 'image', 'id' => $id, 'url' => $url, 'value' => $value];
        }
        if (strpos($value, 'JSON:') === 0) {
            return ['type' => 'skip', 'value' => $value];
        }
        if (self::looks_like_image_field_name($field_name) && ctype_digit(trim($value))) {
            $id = (int) trim($value);
            if ($id > 0) {
                $url = wp_get_attachment_image_url($id, 'full');
                return ['type' => 'image', 'id' => $id, 'url' => $url ? $url : '', 'value' => $value];
            }
        }
        if (self::looks_like_video_file_field_name($field_name) && ctype_digit(trim($value))) {
            $id = (int) trim($value);
            if ($id > 0) {
                $url = wp_get_attachment_url($id);
                return ['type' => 'video', 'id' => $id, 'url' => $url ? $url : '', 'value' => $value];
            }
        }
        if (preg_match('#^https?://#', $value) && preg_match('#\.(png|jpe?g|gif|webp)(\?|$)#i', $value)) {
            return ['type' => 'image', 'id' => 0, 'url' => $value, 'value' => $value];
        }
        if (preg_match('#^https?://#', $value) && strpos($value, 'uploads') !== false) {
            return ['type' => 'image', 'id' => 0, 'url' => $value, 'value' => $value];
        }
        if (self::looks_like_video_file_field_name($field_name) && preg_match('#^https?://#', $value) && preg_match('#\.(mp4|webm|ogg|mov|m4v)(\?|$)#i', $value)) {
            return ['type' => 'video', 'id' => 0, 'url' => $value, 'value' => $value];
        }
        return ['type' => 'text', 'value' => $value];
    }

    /**
     * Get thumbnail URL for an image field value. Handles IMAGE:ID, IMAGE:ID\turl, or plain URL.
     */
    public static function get_image_thumbnail_url($raw) {
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        if (strpos($raw, 'IMAGE:') === 0) {
            $rest = trim(substr($raw, 6));
            $parts = explode("\t", $rest, 2);
            $id = isset($parts[0]) ? (int) $parts[0] : 0;
            if ($id) {
                $url = wp_get_attachment_image_url($id, 'thumbnail');
                if ($url) {
                    return $url;
                }
            }
            if (isset($parts[1]) && trim($parts[1]) !== '') {
                return trim($parts[1]);
            }
            return '';
        }
        if (preg_match('#^https?://#', $raw)) {
            return $raw;
        }
        return '';
    }

    /**
     * Heuristic for fields that store image attachments.
     */
    public static function looks_like_image_field_name($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return false;
        }
        return (bool) preg_match('/(^|_)(image|img|thumbnail|thumb|logo|icon|banner)(_|$)/i', $field_name);
    }

    /**
     * Heuristic for fields that store local video attachments.
     */
    public static function looks_like_video_file_field_name($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return false;
        }
        if ((bool) preg_match('/(^|_)(youtube|vimeo)(_|$)/i', $field_name)) {
            return false;
        }
        if ((bool) preg_match('/(^|_)(type|provider)(_|$)/i', $field_name)) {
            return false;
        }
        return (bool) preg_match('/(^|_)(local_video|video_file|video)(_|$)/i', $field_name);
    }

    /**
     * Known select choices for content-editing form fields.
     *
     * @return array<string, string>
     */
    public static function get_form_select_field_choices($field_name) {
        $field_name = is_string($field_name) ? $field_name : '';
        if ($field_name === 'media_type') {
            return [
                'image' => 'Image',
                'local_video' => 'Local Video',
                'youtube' => 'YouTube Video',
                'vimeo' => 'Vimeo Video',
            ];
        }
        if ($field_name === 'background_type') {
            return [
                'image' => 'Image',
                'video' => 'Video',
            ];
        }
        if ($field_name === 'background_video_type' || $field_name === 'video_provider') {
            return [
                'local' => 'Local Video File',
                'youtube' => 'YouTube',
                'vimeo' => 'Vimeo',
            ];
        }
        return [];
    }

    /**
     * Whether a value (and optionally field name) indicates a yes/no (0 or 1) field for use as radio options.
     * When value is empty, returns true if field_name looks like a boolean (e.g. show_, display_, visible, _enabled).
     *
     * @param mixed $value
     * @param string $field_name Optional. Used to treat empty values as yes/no when name suggests boolean.
     * @return bool
     */
    public static function is_yes_no_field_value($value, $field_name = '') {
        $s = $value === null || $value === '' ? '' : (string) $value;
        if ($s === '0' || $s === '1' || $value === 0 || $value === 1) {
            return true;
        }
        if ($s !== '') {
            return false;
        }
        $fn = is_string($field_name) ? strtolower($field_name) : '';
        return (bool) preg_match('/(^|_)(show|display|visible|enable|hide)(_|$)/i', $fn);
    }

    /**
     * Choices for yes/no (0/1) radio groups on the client form.
     *
     * @return array<string, string>
     */
    public static function get_yes_no_choices() {
        return ['0' => 'No', '1' => 'Yes'];
    }

    /**
     * Extract taxonomy slug from core taxonomy form key: taxonomy__{taxonomy}.
     *
     * @param string $field_name
     * @return string
     */
    public static function get_taxonomy_from_form_field_name($field_name) {
        if (!is_string($field_name) || strpos($field_name, 'taxonomy__') !== 0) {
            return '';
        }
        return sanitize_key(substr($field_name, strlen('taxonomy__')));
    }

    /**
     * Return choices for taxonomy checkbox groups.
     *
     * @param string $taxonomy
     * @return array<int,string> term_id => term label
     */
    public static function get_form_taxonomy_field_choices($taxonomy) {
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return [];
        }
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            if (!is_object($term) || !isset($term->term_id, $term->name)) {
                continue;
            }
            $out[(int) $term->term_id] = (string) $term->name;
        }
        return $out;
    }

    /**
     * Heuristic for fields that represent link/button controls (URL + text).
     */
    public static function looks_like_link_field_name($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return false;
        }
        $is_linkish = (bool) preg_match('/(^|_)(button|cta|link)(_|$)/i', $field_name);
        if (!$is_linkish) {
            return false;
        }
        // Do not treat plain label/text/title/meta helper fields as link objects.
        if (preg_match('/(^|_)(text|label|title|heading|subheading)(_|$)/i', $field_name)) {
            return false;
        }
        if (self::looks_like_image_field_name($field_name)) {
            return false;
        }
        return true;
    }

    /**
     * Human-readable label for exported field keys.
     * Examples:
     * - post_content => Post Content
     * - features__0__feature_text => Features -> Item 1 -> Feature Text
     */
    public static function get_human_field_label($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return '';
        }
        $special = [
            'core_post_title' => 'Page / Post Title',
            'post_content' => 'Post Content',
            'post_excerpt' => 'Post Excerpt',
            'featured_image' => 'Featured Image',
        ];
        if (isset($special[$field_name])) {
            return $special[$field_name];
        }
        if (strpos($field_name, 'taxonomy__') === 0) {
            $taxonomy = self::get_taxonomy_from_form_field_name($field_name);
            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                $obj = get_taxonomy($taxonomy);
                if ($obj && !empty($obj->labels) && !empty($obj->labels->name)) {
                    return (string) $obj->labels->name;
                }
            }
            return 'Taxonomy';
        }
        $parts = explode('__', $field_name);
        $readable = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (ctype_digit($part)) {
                $readable[] = 'Item ' . ((int) $part + 1);
                continue;
            }
            $p = str_replace(['-', '_'], ' ', $part);
            $p = preg_replace('/\s+/', ' ', (string) $p);
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $readable[] = ucwords($p);
        }
        if (empty($readable)) {
            return ucwords(str_replace('_', ' ', $field_name));
        }
        return implode(' -> ', $readable);
    }

    /**
     * Short helper text for common client-editable fields.
     *
     * @param string $field_name
     * @return string
     */
    public static function get_field_help_text($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return '';
        }
        $field_name = strtolower($field_name);
        if (preg_match('/(^|__|_)(headline|heading|title)($|__|_)/', $field_name) && !preg_match('/__matrix_link_title$/', $field_name)) {
            return 'Short title for this section.';
        }
        if (preg_match('/__matrix_link_url$/', $field_name)) {
            return 'Full URL including https://';
        }
        if (preg_match('/__matrix_link_title$/', $field_name)) {
            return 'Text shown on the button/link.';
        }
        return '';
    }

    /**
     * Short helper text for image replacement fields.
     *
     * @param string $field_name
     * @return string
     */
    public static function get_image_help_text($field_name) {
        if (!is_string($field_name) || $field_name === '') {
            return '';
        }
        $field_name = strtolower($field_name);
        if (preg_match('/(^|__|_)(og|social|share)($|__|_)/', $field_name)) {
            return 'Recommended: 1200 x 630.';
        }
        if (preg_match('/(^|__|_)(hero|banner|masthead)($|__|_)/', $field_name)) {
            return 'Recommended: 1920 x 1080.';
        }
        return 'Use a clear, high-quality image.';
    }

    /**
     * Resolve a readable block layout label from ACF config.
     * Falls back to a cleaned version of the layout slug.
     *
     * @param int $post_id
     * @param string $block_source
     * @param string $block_type
     * @return string
     */
    public static function get_block_layout_label($post_id, $block_source, $block_type) {
        $post_id = (int) $post_id;
        $block_source = is_string($block_source) ? $block_source : '';
        $block_type = is_string($block_type) ? $block_type : '';
        if ($block_type === '') {
            return '';
        }

        static $layout_cache = [];
        $cache_key = $post_id . '|' . $block_source;
        if (!isset($layout_cache[$cache_key])) {
            $layout_cache[$cache_key] = [];
            if (function_exists('get_field_object') && $post_id > 0 && $block_source !== '') {
                $field_obj = get_field_object($block_source, $post_id, false, false);
                if (is_array($field_obj) && !empty($field_obj['layouts']) && is_array($field_obj['layouts'])) {
                    foreach ($field_obj['layouts'] as $layout) {
                        if (!is_array($layout)) {
                            continue;
                        }
                        $name = isset($layout['name']) ? (string) $layout['name'] : '';
                        $label = isset($layout['label']) ? (string) $layout['label'] : '';
                        if ($name !== '' && $label !== '') {
                            $layout_cache[$cache_key][$name] = $label;
                        }
                    }
                }
            }
        }

        if (isset($layout_cache[$cache_key][$block_type])) {
            return $layout_cache[$cache_key][$block_type];
        }

        $fallback = preg_replace('/[_-]+\d+$/', '', $block_type);
        $fallback = str_replace(['_', '-'], ' ', $fallback);
        $fallback = trim((string) preg_replace('/\s+/', ' ', (string) $fallback));
        if ($fallback === '') {
            return $block_type;
        }
        return ucwords($fallback);
    }

    /**
     * Build anchor ID used on front-end for a given block source/index.
     * When $block_type (ACF layout name) is provided, returns a theme-friendly id: layout-name-index (e.g. content-one-0, hero-0)
     * so the theme can use the same formula: str_replace('_', '-', get_row_layout()) . '-' . get_row_index()
     * Filter: matrix_export_block_anchor_id
     *
     * @param string $block_source ACF field name (e.g. flexible_content_blocks)
     * @param int $block_index Row index
     * @param string $block_type Optional. ACF layout name (e.g. content_one, hero). When set, anchor is layout-name-index.
     */
    public static function get_block_anchor_id($block_source, $block_index, $block_type = '') {
        $block_index = (int) $block_index;
        if ($block_type !== '') {
            $layout_slug = str_replace('_', '-', strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $block_type)));
            if ($layout_slug !== '') {
                $row_index = $block_index + 1;
                $anchor = $layout_slug . '-' . $row_index;
                if (function_exists('apply_filters')) {
                    $anchor = (string) apply_filters('matrix_export_block_anchor_id', $anchor, $block_source, $block_index, $block_type);
                }
                return $anchor !== '' ? $anchor : $layout_slug . '-' . $row_index;
            }
        }
        $source_slug = preg_replace('/[^a-z0-9_\-]/i', '', (string) $block_source);
        $anchor = 'matrix-block-' . $source_slug . '-' . $block_index;
        if (function_exists('apply_filters')) {
            $anchor = (string) apply_filters('matrix_export_block_anchor_id', $anchor, $block_source, $block_index, $block_type);
        }
        return $anchor !== '' ? $anchor : 'matrix-block-' . $source_slug . '-' . $block_index;
    }

    /**
     * Ensure per-block screenshot previews exist and return URL map keyed by row index.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string> row_index => preview_url
     */
    public static function ensure_block_preview_urls(array $rows) {
        $map = [];
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return $map;
        }
        $preview_dir = trailingslashit($uploads['basedir']) . 'matrix-content-export-previews';
        $preview_url_base = trailingslashit($uploads['baseurl']) . 'matrix-content-export-previews';
        if (!is_dir($preview_dir)) {
            wp_mkdir_p($preview_dir);
        }
        $capture_cookies = self::get_capture_cookies_for_browser();
        $preview_file_meta = [];

        $tasks = [];
        $hero_count_by_post = [];
        foreach ($rows as $row) {
            $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $src = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($pid <= 0 || $src !== self::HERO_FIELD) {
                continue;
            }
            if (!isset($hero_count_by_post[$pid])) {
                $hero_count_by_post[$pid] = 0;
            }
            $hero_count_by_post[$pid]++;
        }
        foreach ($rows as $i => $row) {
            $source = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($source !== self::FLEX_FIELD && $source !== self::HERO_FIELD) {
                continue;
            }
            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $block_index = isset($row['block_index']) ? (int) $row['block_index'] : -1;
            $block_type = isset($row['block_type']) ? (string) $row['block_type'] : '';
            if ($post_id <= 0 || $block_index < 0) {
                continue;
            }
            $permalink = get_permalink($post_id);
            if (!$permalink) {
                continue;
            }
            $permalink = self::normalize_url_for_current_request($permalink);
            $anchor = self::get_block_anchor_id($source, $block_index, $block_type);
            $source_slug = preg_replace('/[^a-z0-9_\-]/i', '', $source);
            $type_slug = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($block_type));
            if ($type_slug === '') {
                $type_slug = 'layout';
            }
            $filename = $post_id . '-' . $source_slug . '-' . $block_index . '-' . $type_slug . '.jpg';
            $file_path = trailingslashit($preview_dir) . $filename;
            $file_url = trailingslashit($preview_url_base) . $filename;
            $preview_file_meta[$i] = ['path' => $file_path, 'url' => $file_url];

            if (!is_file($file_path)) {
                $fallback_index = $block_index;
                if ($source === self::FLEX_FIELD) {
                    $fallback_index = (isset($hero_count_by_post[$post_id]) ? (int) $hero_count_by_post[$post_id] : 0) + $block_index;
                }
                $tasks[] = [
                    'url' => $permalink,
                    'selector' => '#' . $anchor,
                    'output' => $file_path,
                    'fallback_index' => $fallback_index,
                    'cookies' => $capture_cookies,
                ];
            }
            if (is_file($file_path)) {
                $map[$i] = $file_url . '?v=' . (int) @filemtime($file_path);
            }
        }

        // Do not generate on form render; generation is handled explicitly from admin action.
        foreach ($preview_file_meta as $row_idx => $meta) {
            if (empty($meta['path']) || !is_file($meta['path'])) {
                continue;
            }
            $u = isset($meta['url']) ? (string) $meta['url'] : '';
            $map[$row_idx] = $u . '?v=' . (int) @filemtime($meta['path']);
        }
        return $map;
    }

    /**
     * Generate block preview screenshots synchronously (for admin action).
     *
     * @param array<int,int> $post_ids
     * @return array{tasks:int, generated:int, message:string}
     */
    public static function generate_block_previews_sync(array $post_ids = []) {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return ['tasks' => 0, 'generated' => 0, 'message' => 'Uploads directory unavailable'];
        }
        $preview_dir = trailingslashit($uploads['basedir']) . 'matrix-content-export-previews';
        if (!is_dir($preview_dir)) {
            wp_mkdir_p($preview_dir);
        }
        if (empty($post_ids)) {
            $all = [];
            foreach (self::get_client_links() as $entry) {
                if (!empty($entry['post_ids']) && is_array($entry['post_ids'])) {
                    $all = array_merge($all, array_map('intval', $entry['post_ids']));
                }
            }
            $post_ids = array_values(array_unique(array_filter($all)));
        }
        if (empty($post_ids)) {
            return ['tasks' => 0, 'generated' => 0, 'message' => 'No selected pages to capture'];
        }

        $data = self::get_export_data_for_form($post_ids);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        $capture_cookies = self::get_capture_cookies_for_browser();
        $hero_count_by_post = [];
        foreach ($rows as $row) {
            $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $src = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($pid > 0 && $src === self::HERO_FIELD) {
                $hero_count_by_post[$pid] = isset($hero_count_by_post[$pid]) ? ((int) $hero_count_by_post[$pid] + 1) : 1;
            }
        }

        $tasks = [];
        foreach ($rows as $row) {
            $source = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($source !== self::FLEX_FIELD && $source !== self::HERO_FIELD) {
                continue;
            }
            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $block_index = isset($row['block_index']) ? (int) $row['block_index'] : -1;
            $block_type = isset($row['block_type']) ? (string) $row['block_type'] : '';
            if ($post_id <= 0 || $block_index < 0) {
                continue;
            }
            $permalink = get_permalink($post_id);
            if (!$permalink) {
                continue;
            }
            $permalink = self::normalize_url_for_current_request($permalink);
            $anchor = self::get_block_anchor_id($source, $block_index, $block_type);
            $source_slug = preg_replace('/[^a-z0-9_\-]/i', '', $source);
            $type_slug = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($block_type));
            if ($type_slug === '') {
                $type_slug = 'layout';
            }
            $filename = $post_id . '-' . $source_slug . '-' . $block_index . '-' . $type_slug . '.jpg';
            $file_path = trailingslashit($preview_dir) . $filename;
            $fallback_index = $block_index;
            if ($source === self::FLEX_FIELD) {
                $fallback_index = (isset($hero_count_by_post[$post_id]) ? (int) $hero_count_by_post[$post_id] : 0) + $block_index;
            }
            $tasks[] = [
                'url' => $permalink,
                'selector' => '#' . $anchor,
                'output' => $file_path,
                'fallback_index' => $fallback_index,
                'cookies' => $capture_cookies,
            ];
        }

        $tasks = array_slice($tasks, 0, 120);
        if (empty($tasks)) {
            return ['tasks' => 0, 'generated' => 0, 'message' => 'No capture tasks built'];
        }
        $script = MATRIX_EXPORT_DIR . 'scripts/capture-block-previews.mjs';
        $node_bin = self::resolve_node_binary();
        if (!is_file($script) || $node_bin === '' || !function_exists('shell_exec')) {
            return ['tasks' => count($tasks), 'generated' => 0, 'message' => 'Node/Playwright runtime unavailable'];
        }
        $tmp_json = trailingslashit(sys_get_temp_dir()) . 'matrix-block-previews-sync-' . uniqid('', true) . '.json';
        file_put_contents($tmp_json, wp_json_encode($tasks));
        $home_dir = self::resolve_home_dir();
        $browsers_path = self::get_playwright_browsers_path($home_dir);
        $env_prefix = '';
        if ($home_dir !== '') {
            $env_prefix = 'HOME=' . escapeshellarg($home_dir) . ' ';
        }
        if ($browsers_path !== '') {
            $env_prefix .= 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($browsers_path) . ' ';
        }
        $plugin_dir = defined('MATRIX_EXPORT_DIR') ? MATRIX_EXPORT_DIR : dirname(dirname(__FILE__)) . '/';
        $plugin_dir_trimmed = rtrim($plugin_dir, '/');
        $env_prefix .= 'NODE_PATH=' . escapeshellarg($plugin_dir_trimmed . '/node_modules') . ' ';
        $cmd = 'cd ' . escapeshellarg($plugin_dir_trimmed) . ' && ' . $env_prefix . escapeshellarg($node_bin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp_json) . ' 2>&1';
        $output = @shell_exec($cmd);
        @unlink($tmp_json);
        $generated = 0;
        foreach ($tasks as $t) {
            if (!empty($t['output']) && is_file($t['output'])) {
                $generated++;
            }
        }
        self::write_preview_capture_log([
            'time' => gmdate('c'),
            'node' => $node_bin,
            'tasks' => count($tasks),
            'output' => is_string($output) ? trim($output) : '',
            'generated' => $generated,
        ], $preview_dir);
        return ['tasks' => count($tasks), 'generated' => $generated, 'message' => is_string($output) ? trim($output) : ''];
    }

    /**
     * Queue block preview generation in background (non-blocking).
     *
     * @param array<int,int> $post_ids
     * @return array{tasks:int, queued:bool}
     */
    public static function generate_block_previews_async(array $post_ids = [], $job_key = '') {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return ['tasks' => 0, 'queued' => false, 'job_key' => '', 'reason' => 'uploads_unavailable'];
        }
        $preview_dir = trailingslashit($uploads['basedir']) . 'matrix-content-export-previews';
        if (!is_dir($preview_dir)) {
            wp_mkdir_p($preview_dir);
        }
        if (empty($post_ids)) {
            return ['tasks' => 0, 'queued' => false, 'job_key' => '', 'reason' => 'no_post_ids'];
        }

        $data = self::get_export_data_for_form($post_ids);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        $capture_cookies = self::get_capture_cookies_for_browser();
        $hero_count_by_post = [];
        foreach ($rows as $row) {
            $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $src = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($pid > 0 && $src === self::HERO_FIELD) {
                $hero_count_by_post[$pid] = isset($hero_count_by_post[$pid]) ? ((int) $hero_count_by_post[$pid] + 1) : 1;
            }
        }
        $tasks = [];
        foreach ($rows as $row) {
            $source = isset($row['block_source']) ? (string) $row['block_source'] : '';
            if ($source !== self::FLEX_FIELD && $source !== self::HERO_FIELD) {
                continue;
            }
            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $block_index = isset($row['block_index']) ? (int) $row['block_index'] : -1;
            $block_type = isset($row['block_type']) ? (string) $row['block_type'] : '';
            if ($post_id <= 0 || $block_index < 0) {
                continue;
            }
            $permalink = get_permalink($post_id);
            if (!$permalink) {
                continue;
            }
            $permalink = self::normalize_url_for_current_request($permalink);
            $anchor = self::get_block_anchor_id($source, $block_index, $block_type);
            $source_slug = preg_replace('/[^a-z0-9_\-]/i', '', $source);
            $type_slug = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($block_type));
            if ($type_slug === '') {
                $type_slug = 'layout';
            }
            $filename = $post_id . '-' . $source_slug . '-' . $block_index . '-' . $type_slug . '.jpg';
            $file_path = trailingslashit($preview_dir) . $filename;
            $fallback_index = $block_index;
            if ($source === self::FLEX_FIELD) {
                $fallback_index = (isset($hero_count_by_post[$post_id]) ? (int) $hero_count_by_post[$post_id] : 0) + $block_index;
            }
            $tasks[] = [
                'url' => $permalink,
                'selector' => '#' . $anchor,
                'output' => $file_path,
                'fallback_index' => $fallback_index,
                'cookies' => $capture_cookies,
            ];
        }
        $tasks = array_slice($tasks, 0, 120);
        if (empty($tasks)) {
            return ['tasks' => 0, 'queued' => false, 'job_key' => '', 'reason' => 'no_tasks'];
        }
        $script = MATRIX_EXPORT_DIR . 'scripts/capture-block-previews.mjs';
        $node_bin = self::resolve_node_binary();
        if (!is_file($script) || $node_bin === '' || (!function_exists('exec') && !function_exists('shell_exec'))) {
            $diag = self::get_screenshot_runtime_diagnostics();
            return [
                'tasks' => count($tasks),
                'queued' => false,
                'job_key' => '',
                'reason' => isset($diag['reason']) ? $diag['reason'] : 'runtime_unavailable',
            ];
        }
        $job_key = is_string($job_key) && $job_key !== '' ? preg_replace('/[^a-z0-9]/i', '', $job_key) : '';
        if ($job_key === '') {
            $job_key = substr(bin2hex(random_bytes(12)), 0, 24);
        }
        $status_file = self::get_preview_status_file($job_key);
        self::write_preview_status($status_file, [
            'job' => $job_key,
            'total' => count($tasks),
            'completed' => 0,
            'generated' => 0,
            'done' => false,
            'error' => '',
            'updated_at' => time(),
        ]);
        $tmp_json = trailingslashit(sys_get_temp_dir()) . 'matrix-block-previews-async-' . uniqid('', true) . '.json';
        file_put_contents($tmp_json, wp_json_encode($tasks));
        $home_dir = self::resolve_home_dir();
        $browsers_path = self::get_playwright_browsers_path($home_dir);
        $env_prefix = '';
        if ($home_dir !== '') {
            $env_prefix = 'HOME=' . escapeshellarg($home_dir) . ' ';
        }
        if ($browsers_path !== '') {
            $env_prefix .= 'PLAYWRIGHT_BROWSERS_PATH=' . escapeshellarg($browsers_path) . ' ';
        }
        $plugin_dir = defined('MATRIX_EXPORT_DIR') ? MATRIX_EXPORT_DIR : dirname(dirname(__FILE__)) . '/';
        $plugin_dir_trimmed = rtrim($plugin_dir, '/');
        $env_prefix .= 'NODE_PATH=' . escapeshellarg($plugin_dir_trimmed . '/node_modules') . ' ';
        $runner_log = trailingslashit($preview_dir) . 'capture-runner.log';
        $cmd = 'cd ' . escapeshellarg($plugin_dir_trimmed) . ' && ' . $env_prefix . escapeshellarg($node_bin) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp_json) . ' ' . escapeshellarg($status_file) . ' >> ' . escapeshellarg($runner_log) . ' 2>&1 &';
        if (function_exists('exec')) {
            @exec($cmd);
        } else {
            @shell_exec($cmd);
        }
        self::write_preview_capture_log([
            'time' => gmdate('c'),
            'node' => $node_bin,
            'tasks' => count($tasks),
            'output' => 'queued async generation',
        ], $preview_dir);
        return ['tasks' => count($tasks), 'queued' => true, 'job_key' => $job_key, 'reason' => ''];
    }

    /**
     * Runtime diagnostics for screenshot generation capability.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function get_screenshot_runtime_diagnostics() {
        if (!function_exists('exec') && !function_exists('shell_exec')) {
            return ['ok' => false, 'reason' => 'Shell functions are disabled on this host.'];
        }
        $script = MATRIX_EXPORT_DIR . 'scripts/capture-block-previews.mjs';
        if (!is_file($script)) {
            return ['ok' => false, 'reason' => 'Capture script missing on server.'];
        }
        $node_bin = self::resolve_node_binary();
        if ($node_bin === '') {
            return ['ok' => false, 'reason' => 'Node.js is not available to PHP runtime.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Get status file path for a screenshot generation job key.
     */
    public static function get_preview_status_file($job_key) {
        $uploads = wp_upload_dir();
        $base = !empty($uploads['basedir']) ? trailingslashit($uploads['basedir']) : trailingslashit(sys_get_temp_dir());
        $dir = $base . 'matrix-content-export-previews';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $key = preg_replace('/[^a-z0-9]/i', '', (string) $job_key);
        if ($key === '') {
            $key = 'default';
        }
        return trailingslashit($dir) . 'status-' . $key . '.json';
    }

    /**
     * Read progress status for a screenshot generation job.
     *
     * @return array<string,mixed>
     */
    public static function get_preview_status($job_key) {
        $file = self::get_preview_status_file($job_key);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write progress status file for screenshot jobs.
     *
     * @param string $file
     * @param array<string,mixed> $data
     */
    public static function write_preview_status($file, array $data) {
        if (!is_string($file) || $file === '') {
            return;
        }
        @file_put_contents($file, wp_json_encode($data));
    }

    /**
     * Resolve Node binary path in PHP runtime (including common nvm/homebrew/server locations).
     * Server admins can set the path via wp-config.php: define('MATRIX_EXPORT_NODE_BINARY', '/path/to/node');
     * or the filter 'matrix_export_node_binary'.
     *
     * @return string
     */
    protected static function resolve_node_binary() {
        $runtime_settings = function_exists('matrix_export_get_runtime_settings') ? matrix_export_get_runtime_settings() : [];
        $configured = '';
        if (!empty($runtime_settings['node_binary'])) {
            $configured = (string) $runtime_settings['node_binary'];
        }
        if ($configured === '' && defined('MATRIX_EXPORT_NODE_BINARY') && MATRIX_EXPORT_NODE_BINARY !== '') {
            $configured = (string) MATRIX_EXPORT_NODE_BINARY;
        }
        if ($configured === '' && function_exists('apply_filters')) {
            $configured = (string) apply_filters('matrix_export_node_binary', '');
        }
        if ($configured !== '') {
            return $configured;
        }

        $candidates = [];
        $home = self::resolve_home_dir();
        if ($home !== '') {
            $nvm_nodes = glob(trailingslashit($home) . '.nvm/versions/node/*/bin/node');
            if (is_array($nvm_nodes) && !empty($nvm_nodes)) {
                rsort($nvm_nodes, SORT_NATURAL);
                foreach ($nvm_nodes as $n) {
                    $candidates[] = $n;
                }
            }
        }
        $from_path = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($from_path !== '') {
            $candidates[] = $from_path;
        }
        $candidates[] = '/opt/homebrew/bin/node';
        $candidates[] = '/usr/local/bin/node';
        $candidates[] = '/usr/bin/node';
        $candidates[] = '/usr/local/node/bin/node';
        foreach (array_unique($candidates) as $bin) {
            if (is_string($bin) && $bin !== '' && is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }
        return '';
    }

    /**
     * Resolve home directory for background Node/Playwright runtime.
     *
     * @return string
     */
    protected static function resolve_home_dir() {
        $home = function_exists('getenv') ? (string) getenv('HOME') : '';
        if ($home !== '' && is_dir($home)) {
            return $home;
        }
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $u = @posix_getpwuid(posix_geteuid());
            if (is_array($u) && !empty($u['dir']) && is_dir($u['dir'])) {
                return (string) $u['dir'];
            }
        }
        if (defined('ABSPATH')) {
            $parts = explode('/Local Sites/', ABSPATH);
            if (!empty($parts[0]) && is_dir($parts[0])) {
                return $parts[0];
            }
        }
        return '';
    }

    /**
     * Resolve Playwright browser cache directory per OS.
     * Server admins can set a path via wp-config.php: define('MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH', '/path/to/ms-playwright');
     *
     * @param string $home_dir
     * @return string
     */
    protected static function get_playwright_browsers_path($home_dir) {
        $runtime_settings = function_exists('matrix_export_get_runtime_settings') ? matrix_export_get_runtime_settings() : [];
        if (!empty($runtime_settings['playwright_browsers_path'])) {
            $path = rtrim((string) $runtime_settings['playwright_browsers_path'], '/');
            return $path !== '' ? $path : '';
        }
        if (defined('MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH') && MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH !== '') {
            $path = rtrim((string) MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH, '/');
            return $path !== '' ? $path : '';
        }
        if (function_exists('apply_filters')) {
            $path = (string) apply_filters('matrix_export_playwright_browsers_path', '');
            if ($path !== '') {
                return rtrim($path, '/');
            }
        }
        $home_dir = rtrim((string) $home_dir, '/');
        if ($home_dir === '') {
            return '';
        }
        $os = defined('PHP_OS_FAMILY') ? (string) PHP_OS_FAMILY : '';
        if (strcasecmp($os, 'Darwin') === 0) {
            return $home_dir . '/Library/Caches/ms-playwright';
        }
        return $home_dir . '/.cache/ms-playwright';
    }

    /**
     * Normalize URL host/scheme to current request host for local environments.
     *
     * @param string $url
     * @return string
     */
    public static function normalize_url_for_current_request($url) {
        if (!is_string($url) || $url === '' || empty($_SERVER['HTTP_HOST'])) {
            return $url;
        }
        $parts = wp_parse_url($url);
        if (empty($parts['path'])) {
            return $url;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) $_SERVER['HTTP_HOST'];
        $rebuilt = $scheme . '://' . $host . $parts['path'];
        if (!empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    /**
     * Write lightweight debug log for screenshot capture attempts.
     *
     * @param array<string,mixed> $payload
     * @param string $preview_dir
     * @return void
     */
    protected static function write_preview_capture_log(array $payload, $preview_dir) {
        if (!is_dir($preview_dir)) {
            return;
        }
        $log_file = trailingslashit($preview_dir) . 'capture.log';
        $line = wp_json_encode($payload) . PHP_EOL;
        @file_put_contents($log_file, $line, FILE_APPEND);
    }

    /**
     * Build cookie payload for Playwright from current request cookies.
     * Helps screenshot capture work on password-protected local/staging sites.
     *
     * @return array<int, array{name:string,value:string,url:string}>
     */
    protected static function get_capture_cookies_for_browser() {
        if (empty($_COOKIE) || !is_array($_COOKIE)) {
            return [];
        }
        $base_url = home_url('/');
        if (!$base_url) {
            return [];
        }
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $cookies[] = [
                'name' => (string) $name,
                'value' => is_scalar($value) ? (string) $value : '',
                'url' => $base_url,
            ];
        }
        return $cookies;
    }

    /**
     * Download editable client form (HTML). Client opens, edits, submits back to site. No CSV.
     */
    public static function download_client_form(array $post_ids = null) {
        $data = self::get_export_data_for_form($post_ids);
        if (empty($data['rows'])) {
            wp_die('No content to export for the selected items.');
        }
        $form_action = home_url('/');
        $token = self::get_form_token();
        ob_start();
        include MATRIX_EXPORT_DIR . 'templates/export-client-form.php';
        $html = ob_get_clean();

        $filename = 'matrix-content-edit-form-' . date('Y-m-d-His') . '.html';
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit;
    }
}
