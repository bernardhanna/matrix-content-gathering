<?php
if (!defined('ABSPATH')) exit;
/** @var array $data */
/** @var string $form_action */
/** @var string $token */
/** @var bool $is_on_site Optional. When true, form is shown on the site (e.g. /content-editing). */
$is_on_site = isset($is_on_site) ? $is_on_site : false;
$in_theme = isset($in_theme) && $in_theme;
$rows = $data['rows'];
$meta_keys = $data['meta_keys'];
$field_keys = $data['field_keys'];
$draft_fields_by_index = isset($data['draft_fields_by_index']) && is_array($data['draft_fields_by_index']) ? $data['draft_fields_by_index'] : [];
$draft_block_enabled = isset($data['draft_block_enabled']) && is_array($data['draft_block_enabled']) ? $data['draft_block_enabled'] : [];
$draft_active_page = isset($data['draft_active_page']) ? (int) $data['draft_active_page'] : 0;
$draft_saved_at = isset($data['draft_saved_at']) ? (int) $data['draft_saved_at'] : 0;
$page_status = isset($data['page_status']) && is_array($data['page_status']) ? $data['page_status'] : [];
$page_status_done_by = isset($data['page_status_done_by']) && is_array($data['page_status_done_by']) ? $data['page_status_done_by'] : [];
$client_link_expires_at = isset($data['client_link_expires_at']) ? (int) $data['client_link_expires_at'] : 0;
$client_link_reminder_days = isset($data['client_link_reminder_days']) ? (int) $data['client_link_reminder_days'] : 0;
$client_link_created_at = isset($data['client_link_created_at']) ? (int) $data['client_link_created_at'] : 0;
$client_custom_instructions = isset($data['client_custom_instructions']) ? (string) $data['client_custom_instructions'] : '';
$client_requires_approval = !empty($data['client_requires_approval']);
$matrix_ai_enabled = !empty($data['matrix_ai_enabled']);
$matrix_ai_url = isset($data['matrix_ai_generate_ajax_url']) ? (string) $data['matrix_ai_generate_ajax_url'] : '';
$matrix_ai_nonce = isset($data['matrix_ai_generate_nonce']) ? (string) $data['matrix_ai_generate_nonce'] : '';
$site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
$block_preview_urls = $is_on_site ? Matrix_Export::ensure_block_preview_urls($rows) : [];
$has_previewable_blocks = false;
foreach ($rows as $r) {
    $src = isset($r['block_source']) ? (string) $r['block_source'] : '';
    if ($src === Matrix_Export::FLEX_FIELD || $src === Matrix_Export::HERO_FIELD) {
        $has_previewable_blocks = true;
        break;
    }
}

// Group rows by post_id for tabs (preserve order; use first occurrence for page title)
$by_page = [];
$page_order = [];
foreach ($rows as $i => $row) {
    $pid = isset($row['post_id']) ? (int) $row['post_id'] : 0;
    if (!isset($by_page[$pid])) {
        $by_page[$pid] = [];
        $page_order[] = $pid;
    }
    $row['_global_index'] = $i;
    $by_page[$pid][] = $row;
}
if (count($page_order) > 1) {
    $front_page_id = function_exists('get_option') ? (int) get_option('page_on_front') : 0;
    $original_pos = array_flip($page_order);
    usort($page_order, function ($a, $b) use ($by_page, $front_page_id, $original_pos) {
        $a_first = isset($by_page[$a][0]) ? $by_page[$a][0] : [];
        $b_first = isset($by_page[$b][0]) ? $by_page[$b][0] : [];
        $a_slug = isset($a_first['post_slug']) ? strtolower((string) $a_first['post_slug']) : '';
        $b_slug = isset($b_first['post_slug']) ? strtolower((string) $b_first['post_slug']) : '';
        $a_title = isset($a_first['post_title']) ? strtolower((string) $a_first['post_title']) : '';
        $b_title = isset($b_first['post_title']) ? strtolower((string) $b_first['post_title']) : '';

        $a_priority = 3;
        $b_priority = 3;
        if ($front_page_id > 0 && (int) $a === $front_page_id) $a_priority = 0;
        if ($front_page_id > 0 && (int) $b === $front_page_id) $b_priority = 0;
        if ($a_priority !== 0 && in_array($a_slug, ['home', 'homepage', 'frontpage'], true)) $a_priority = 1;
        if ($b_priority !== 0 && in_array($b_slug, ['home', 'homepage', 'frontpage'], true)) $b_priority = 1;
        if ($a_priority > 1 && in_array($a_title, ['home', 'homepage', 'front page'], true)) $a_priority = 2;
        if ($b_priority > 1 && in_array($b_title, ['home', 'homepage', 'front page'], true)) $b_priority = 2;

        if ($a_priority !== $b_priority) {
            return $a_priority - $b_priority;
        }
        $a_pos = isset($original_pos[$a]) ? (int) $original_pos[$a] : 99999;
        $b_pos = isset($original_pos[$b]) ? (int) $original_pos[$b] : 99999;
        return $a_pos - $b_pos;
    });
}
if (!$in_theme) : ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit content – then submit to save</title>
    <style>
        :root {
            --matrix-slate-700: #21282f;
            --matrix-slate-600: #323e48;
            --matrix-slate-400: #656e76;
            --matrix-border: #b7bbbf;
            --matrix-surface: #ffffff;
            --matrix-surface-soft: #f5f7f8;
            --matrix-orange: #ff7533;
            --matrix-orange-strong: #ff3e01;
            --matrix-orange-soft: #ffba99;
        }
        body { font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 980px; margin: 2rem auto; padding: 0 1rem 2rem; color: var(--matrix-slate-600); background: #fff; }
        h1 { color: var(--matrix-slate-700); font-size: 2rem; line-height: 1.2; margin-bottom: 0.5rem; }
        .intro { background: #fff; border: 2px solid var(--matrix-orange-soft); padding: 1rem 1.2rem; margin-bottom: 1rem; font-size: 1rem; line-height: 1.6; }
        .intro strong { display: block; margin-bottom: 0.25rem; font-weight: 700; color: var(--matrix-slate-700); }
        .matrix-link-meta-notice { margin: 0.25rem 0 0.6rem; font-size: 0.92rem; color: var(--matrix-slate-400); }
        .matrix-custom-instructions { border-color: #9ec5fe; background: #f5f9ff; }
        .matrix-title-view-row { margin: 0.25rem 0 0.85rem; }
        .matrix-title-view-panel { display: none; }
        .matrix-title-view-panel.matrix-title-view-active { display: block; }
        .matrix-form-errors { margin: 0 0 1rem; padding: 0.75rem 1rem; border: 2px solid #dc3545; background: #f8d7da; color: #721c24; display: none; }
        .matrix-form-errors.matrix-visible { display: block; }
        .matrix-field-error { margin: 0.35rem 0 0; color: #b42318; font-size: 0.88rem; display: none; }
        .matrix-field-error.matrix-visible { display: block; }
        .matrix-field-invalid { border-color: #dc3545 !important; outline-color: rgba(220,53,69,0.25) !important; }
        .matrix-submit-progress { margin-top: 0.6rem; font-size: 0.92rem; color: var(--matrix-slate-600); display: none; }
        .matrix-submit-progress.matrix-visible { display: block; }
        .matrix-panel-nav { display: flex; justify-content: space-between; gap: 0.75rem; margin: 1.2rem 0 0.4rem; }
        .matrix-panel-nav button { border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); padding: 8px 14px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
        .matrix-panel-nav button:disabled { opacity: 0.45; cursor: not-allowed; }
        .matrix-count-hint { margin: 0.3rem 0 0; color: var(--matrix-slate-400); font-size: 0.86rem; }
        form { margin-top: 1rem; }
        .tabs { display: none; }
        .tabs button { padding: 12px 18px; border: 2px solid var(--matrix-border); background: #fff; cursor: pointer; font-size: 0.95rem; color: var(--matrix-slate-700); font-weight: 600; }
        .tabs button:hover { border-color: var(--matrix-orange); }
        .tabs button.active { background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); border-color: var(--matrix-orange); color: #21282f; }
        .tab-panel { display: none; padding: 0.25rem 0; }
        .tab-panel.active { display: block; }
        .block { margin: 1rem 0; padding: 1rem 1.2rem; border: 2px solid #e0e2e4; background: var(--matrix-surface); box-shadow: 0 6px 20px rgba(33,40,47,0.05); }
        .block-title { font-size: 0.95rem; color: #323e48; margin: 0 0 0.75rem 0; padding-bottom: 0.6rem; border-bottom: 1px solid #eceff1; font-weight: 600; }
        .field { margin: 0.9rem 0; }
        .field-label { display: block; font-weight: 600; font-size: 1rem; color: var(--matrix-slate-700); margin-bottom: 0.35rem; }
        input[type="text"], input[type="url"], textarea, input[type="file"] { width: 100%; padding: 12px 14px; border: 2px solid var(--matrix-border); font-size: 1rem; font-family: inherit; box-sizing: border-box; background: #fff; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus, button:focus, a:focus { border-color: var(--matrix-orange); outline: 3px solid rgba(255,117,51,0.28); outline-offset: 1px; box-shadow: none; }
        .submit-wrap { margin-top: 1.75rem; padding: 1rem; border: 2px solid #e0e2e4; background: #fff; }
        .submit-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .submit-wrap button { display: inline-flex; justify-content: center; align-items: center; border: 4px solid transparent; background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); color: #21282f; padding: 10px 22px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .submit-wrap button:hover { background: var(--matrix-orange-strong); border-color: var(--matrix-orange-soft); }
        .submit-wrap button.secondary { background: #fff; border-color: #c8ced3; color: #323e48; }
        .submit-wrap button.secondary:hover { background: #f5f7f8; border-color: #a8b0b8; }
        .submit-wrap p { margin-top: 0.75rem; font-size: 0.95rem; color: var(--matrix-slate-400); }
        .image-field .image-preview { margin: 0.5rem 0; }
        .image-field .image-preview img { max-width: 180px; max-height: 180px; height: auto; display: block; border: 2px solid #e0e2e4; }
        .image-field .image-preview .no-image { color: #646970; font-size: 0.9rem; font-style: italic; }
        .block-preview { margin: 0 0 0.8rem 0; }
        .block-preview img { display: block; width: 100%; max-height: 320px; object-fit: cover; border: 2px solid #e0e2e4; }
        .matrix-help-text { margin: 0.35rem 0 0; color: var(--matrix-slate-400); font-size: 0.88rem; }
        .matrix-page-links { margin: 0.25rem 0 1rem; padding: 0.75rem 1rem; background: #fff; border: 2px solid #e0e2e4; }
        .matrix-page-links strong { display: block; margin-bottom: 0.4rem; color: var(--matrix-slate-700); }
        .matrix-page-links-list { display: flex; flex-wrap: wrap; gap: 8px 12px; }
        .matrix-page-links-list a { color: var(--matrix-slate-700); text-decoration: none; border-bottom: 1px solid transparent; font-size: 0.95rem; }
        .matrix-page-links-list a:hover { border-bottom-color: var(--matrix-orange); }
        .matrix-view-section-btn { display: inline-flex; align-items: center; justify-content: center; margin-left: 8px; padding: 7px 12px; border: 2px solid var(--matrix-orange); background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); color: #21282f; text-decoration: none; font-size: 0.86rem; font-weight: 700; }
        .matrix-view-section-btn:hover { background: var(--matrix-orange-strong); border-color: var(--matrix-orange-soft); }
        .matrix-duplicate-page-btn { display: inline-flex; align-items: center; justify-content: center; margin-left: 8px; padding: 7px 12px; border: 2px solid #c8ced3; background: #fff; color: #323e48; text-decoration: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; }
        .matrix-duplicate-page-btn:hover { background: #f5f7f8; border-color: #a8b0b8; }
        .matrix-auth-actions { position: sticky; top: 0; z-index: 50; display: grid; grid-template-columns: auto auto 1fr auto; align-items: center; gap: 10px; margin-bottom: 0.75rem; background: #fff; border-bottom: 1px solid #eceff1; padding: 6px 0; overflow-x: auto; }
        .matrix-page-select-wrap { position: relative; display: inline-flex; align-items: center; gap: 8px; border-left: 4px solid var(--matrix-border); border-radius: 4px; padding-left: 8px; }
        .matrix-page-select-wrap.status-todo { border-left-color: #d39e00; }
        .matrix-page-select-wrap.status-inprogress { border-left-color: #007bff; }
        .matrix-page-select-wrap.status-done { border-left-color: #28a745; }
        .matrix-page-select-wrap.status-delete { border-left-color: #dc3545; }
        .matrix-page-select-wrap::after { content: '▾'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--matrix-slate-700); font-size: 14px; line-height: 1; pointer-events: none; }
        .matrix-page-select { padding: 8px 34px 8px 10px; border: 2px solid var(--matrix-border); background: #fff; color: var(--matrix-slate-700); font-size: 0.9rem; font-weight: 600; min-width: 220px; appearance: none; -webkit-appearance: none; }
        .matrix-page-status-badge { display: inline-block; margin-left: 8px; padding: 4px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
        .matrix-page-status-badge.status-todo { background: #fff3cd; color: #856404; }
        .matrix-page-status-badge.status-inprogress { background: #cce5ff; color: #004085; }
        .matrix-page-status-badge.status-done { background: #d4edda; color: #155724; }
        .matrix-page-status-badge.status-delete { background: #f8d7da; color: #721c24; }
        .matrix-page-status-wrap { margin-top: 0; display: flex; flex-wrap: nowrap; align-items: center; gap: 8px 10px; white-space: nowrap; }
        .matrix-page-status-done-by { margin: 0; font-size: 0.82rem; color: var(--matrix-slate-400); }
        .matrix-field-radio-group { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; }
        .matrix-field-checkbox-group { display: flex; flex-wrap: wrap; gap: 8px 14px; }
        .matrix-radio-label, .matrix-checkbox-label { cursor: pointer; }
        .matrix-status-radios { display: inline-flex; gap: 8px; align-items: center; }
        .matrix-status-radios label { display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; border-radius: 999px; padding: 4px 10px; cursor: pointer; white-space: nowrap; }
        .matrix-status-radios label.status-todo { background: #fff3cd; color: #856404; border-color: #ffe69c; }
        .matrix-status-radios label.status-inprogress { background: #cfe2ff; color: #084298; border-color: #9ec5fe; }
        .matrix-status-radios label.status-done { background: #d1e7dd; color: #0f5132; border-color: #a3cfbb; }
        .matrix-status-radios label.status-delete { background: #f8d7da; color: #721c24; border-color: #f1aeb5; }
        #matrix-top-status-wrap { justify-self: center; }
        .screen-reader-text { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        .matrix-logout-btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 14px; border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); text-decoration: none; font-size: 0.9rem; font-weight: 600; }
        .matrix-logout-btn:hover { background: #f5f7f8; border-color: #a8b0b8; }
        .matrix-auth-right { display: inline-flex; align-items: center; gap: 8px; }
        .matrix-instructions-btn { width: 34px; height: 34px; border: 2px solid var(--matrix-border); border-radius: 999px; background: #fff; color: var(--matrix-slate-700); font-size: 1rem; font-weight: 700; line-height: 1; cursor: pointer; }
        .matrix-instructions-btn:hover { border-color: var(--matrix-orange); color: #21282f; }
        .matrix-instructions-modal { position: fixed; inset: 0; background: rgba(33,40,47,0.45); display: none; align-items: center; justify-content: center; z-index: 99999; padding: 1rem; }
        .matrix-instructions-modal.matrix-visible { display: flex; }
        .matrix-instructions-dialog { width: min(760px, 100%); max-height: 85vh; overflow: auto; background: #fff; border: 2px solid #e0e2e4; box-shadow: 0 20px 40px rgba(33,40,47,0.18); }
        .matrix-instructions-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid #eceff1; }
        .matrix-instructions-head h2 { margin: 0; font-size: 1.05rem; color: var(--matrix-slate-700); }
        .matrix-instructions-close { border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); padding: 4px 10px; font-size: 0.9rem; cursor: pointer; }
        .matrix-instructions-close:hover { background: #f5f7f8; border-color: #a8b0b8; }
        .matrix-instructions-body { padding: 12px 14px 16px; color: var(--matrix-slate-600); line-height: 1.6; }
        .matrix-review-modal { position: fixed; inset: 0; background: rgba(33,40,47,0.45); display: none; align-items: center; justify-content: center; z-index: 100000; padding: 1rem; }
        .matrix-review-modal.matrix-visible { display: flex; }
        .matrix-review-dialog { width: min(760px, 100%); max-height: 85vh; overflow: auto; background: #fff; border: 2px solid #e0e2e4; box-shadow: 0 20px 40px rgba(33,40,47,0.18); }
        .matrix-review-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid #eceff1; }
        .matrix-review-head h2 { margin: 0; font-size: 1.05rem; color: var(--matrix-slate-700); }
        .matrix-review-body { padding: 12px 14px 16px; color: var(--matrix-slate-600); line-height: 1.55; }
        .matrix-review-body ul { margin: 0.25rem 0 0 1.1rem; }
        .matrix-review-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
        .matrix-review-actions button { border: 2px solid #c8ced3; background: #fff; color: #323e48; padding: 8px 14px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
        .matrix-review-actions button.primary { background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); border-color: var(--matrix-orange); color: #21282f; }
        .matrix-ai-global-wrap { margin-bottom: 14px; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; background: #f9fafb; }
        .matrix-field-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .matrix-ai-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; border: 2px solid #111; border-radius: 4px; background: #111; color: #fff; font-weight: 600; line-height: 1.2; transition: background-color 120ms ease, border-color 120ms ease, transform 90ms ease, box-shadow 120ms ease; cursor: pointer; }
        .matrix-ai-btn:hover { background: #2b2b2b; border-color: #2b2b2b; }
        .matrix-ai-btn:active { transform: translateY(1px); background: #000; border-color: #000; }
        .matrix-ai-btn:focus-visible { outline: 3px solid rgba(255,117,51,0.28); outline-offset: 1px; }
        .matrix-ai-btn[hidden] { display: none !important; }
        .matrix-ai-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
        .matrix-ai-btn-secondary { background: #fff; border-color: #c8ced3; color: #323e48; }
        .matrix-ai-btn-secondary:hover { background: #f5f7f8; border-color: #a8b0b8; }
        .matrix-ai-btn-secondary:active { background: #eceff1; border-color: #8f99a3; }
        .matrix-ai-btn-retry { background: #facc15; border-color: #eab308; color: #1f2937; }
        .matrix-ai-btn-retry:hover { background: #eab308; border-color: #ca8a04; }
        .matrix-ai-btn-retry:active { background: #ca8a04; border-color: #a16207; color: #111827; }
        .matrix-ai-btn-accept { background: #22c55e; border-color: #16a34a; color: #052e16; }
        .matrix-ai-btn-accept:hover { background: #16a34a; border-color: #15803d; color: #ecfdf5; }
        .matrix-ai-btn-accept:active { background: #15803d; border-color: #166534; color: #ecfdf5; }
        .matrix-ai-btn-reject { background: #ef4444; border-color: #dc2626; color: #ffffff; }
        .matrix-ai-btn-reject:hover { background: #dc2626; border-color: #b91c1c; }
        .matrix-ai-btn-reject:active { background: #b91c1c; border-color: #991b1b; }
        .matrix-ai-field-open[aria-expanded="true"] { background: #2b2b2b; border-color: #2b2b2b; }
        .matrix-ai-field-panel { display: none; border: 1px solid #dcdcde; border-radius: 8px; padding: 10px; margin-top: 8px; background: #f9fafb; }
        .matrix-ai-field-panel.is-open { display: block; }
        .matrix-ai-field-panel > label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--matrix-slate-700); }
        .matrix-ai-field-instructions, .matrix-ai-field-bullets { width: 100%; min-height: 52px; margin-bottom: 8px; }
        .matrix-ai-field-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .matrix-ai-feedback-wrap { margin-bottom: 8px; }
        .matrix-ai-field-feedback { width: 100%; min-height: 52px; margin-bottom: 0; }
        .matrix-ai-field-status { margin: 0; min-height: 1.2em; }
        .matrix-ai-field-panel[aria-busy="true"] .matrix-ai-field-status { color: var(--matrix-slate-700); }
        .matrix-ai-field-preview { margin-top: 6px; padding: 8px; border: 1px solid #e2e8f0; background: #fff; border-radius: 4px; white-space: pre-wrap; }
        .matrix-ai-field-preview[hidden] { display: none !important; }
        @media (max-width: 768px) {
            h1 { font-size: 1.7rem; }
            .matrix-auth-actions { align-items: center; }
            .matrix-page-select { width: 100%; }
            .submit-actions { display: grid; grid-template-columns: 1fr; }
            .submit-wrap button { width: 100%; }
        }
    </style>
</head>
<body>
<?php endif; ?>
<?php if ($in_theme) : ?>
<div class="matrix-content-edit-wrap">
<style>
    html {
        background: var(--matrix-surface-soft);
    }
.matrix-content-edit-wrap {
    --matrix-slate-700: #21282f;
    --matrix-slate-600: #323e48;
    --matrix-slate-400: #656e76;
    --matrix-border: #b7bbbf;
    --matrix-surface: #ffffff;
    --matrix-surface-soft: #f5f7f8;
    --matrix-orange: #ff7533;
    --matrix-orange-strong: #ff3e01;
    --matrix-orange-soft: #ffba99;
    font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    max-width: 980px;
    margin: 2rem auto;
    padding: 0 1rem 2rem;
    color: var(--matrix-slate-600);
    background: #fff;
}
.matrix-content-edit-wrap h1 { color: var(--matrix-slate-700); font-size: 2rem; line-height: 1.2; margin-bottom: 0.5rem; }
.matrix-content-edit-wrap .intro { background: #fff; border: 2px solid var(--matrix-orange-soft); padding: 1rem 1.2rem; margin-bottom: 1rem; font-size: 1rem; line-height: 1.6; }
.matrix-content-edit-wrap .intro strong { display: block; margin-bottom: 0.25rem; font-weight: 700; color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-link-meta-notice { margin: 0.25rem 0 0.6rem; font-size: 0.92rem; color: var(--matrix-slate-400); }
.matrix-content-edit-wrap .matrix-custom-instructions { border-color: #9ec5fe; background: #f5f9ff; }
.matrix-content-edit-wrap .matrix-title-view-row { margin: 0.25rem 0 0.85rem; }
.matrix-content-edit-wrap .matrix-title-view-panel { display: none; }
.matrix-content-edit-wrap .matrix-title-view-panel.matrix-title-view-active { display: block; }
.matrix-content-edit-wrap .matrix-form-errors { margin: 0 0 1rem; padding: 0.75rem 1rem; border: 2px solid #dc3545; background: #f8d7da; color: #721c24; display: none; }
.matrix-content-edit-wrap .matrix-form-errors.matrix-visible { display: block; }
.matrix-content-edit-wrap .matrix-field-error { margin: 0.35rem 0 0; color: #b42318; font-size: 0.88rem; display: none; }
.matrix-content-edit-wrap .matrix-field-error.matrix-visible { display: block; }
.matrix-content-edit-wrap .matrix-field-invalid { border-color: #dc3545 !important; outline-color: rgba(220,53,69,0.25) !important; }
.matrix-content-edit-wrap .matrix-submit-progress { margin-top: 0.6rem; font-size: 0.92rem; color: var(--matrix-slate-600); display: none; }
.matrix-content-edit-wrap .matrix-submit-progress.matrix-visible { display: block; }
.matrix-content-edit-wrap .matrix-panel-nav { display: flex; justify-content: space-between; gap: 0.75rem; margin: 1.2rem 0 0.4rem; }
.matrix-content-edit-wrap .matrix-panel-nav button { border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); padding: 8px 14px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
.matrix-content-edit-wrap .matrix-panel-nav button:disabled { opacity: 0.45; cursor: not-allowed; }
.matrix-content-edit-wrap .matrix-count-hint { margin: 0.3rem 0 0; color: var(--matrix-slate-400); font-size: 0.86rem; }
.matrix-content-edit-wrap #matrix-client-form { margin-top: 1rem; }
.matrix-content-edit-wrap .tabs { display: none; }
.matrix-content-edit-wrap .tabs button { padding: 12px 18px; border: 2px solid var(--matrix-border); background: #fff; cursor: pointer; font-size: 0.95rem; color: var(--matrix-slate-700); font-weight: 600; }
.matrix-content-edit-wrap .tabs button:hover { border-color: var(--matrix-orange); }
.matrix-content-edit-wrap .tabs button.active { background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); border-color: var(--matrix-orange); color: #21282f; }
.matrix-content-edit-wrap .tab-panel { display: none; padding: 0.25rem 0; }
.matrix-content-edit-wrap .tab-panel.active { display: block; }
.matrix-content-edit-wrap .block { margin: 1rem 0; padding: 1rem 1.2rem; border: 2px solid #e0e2e4; background: var(--matrix-surface); box-shadow: 0 6px 20px rgba(33,40,47,0.05); }
.matrix-content-edit-wrap .block-title { font-size: 0.95rem; color: #323e48; margin: 0 0 0.75rem 0; padding-bottom: 0.6rem; border-bottom: 1px solid #eceff1; font-weight: 600; }
.matrix-content-edit-wrap .field { margin: 0.9rem 0; }
.matrix-content-edit-wrap .field-label { display: block; font-weight: 600; font-size: 1rem; color: var(--matrix-slate-700); margin-bottom: 0.35rem; }
.matrix-content-edit-wrap input[type="text"],
.matrix-content-edit-wrap input[type="url"],
.matrix-content-edit-wrap input[type="file"],
.matrix-content-edit-wrap textarea { width: 100%; padding: 12px 14px; border: 2px solid var(--matrix-border); font-size: 1rem; font-family: inherit; box-sizing: border-box; background: #fff; }
.matrix-content-edit-wrap textarea { min-height: 120px; resize: vertical; }
.matrix-content-edit-wrap input:focus,
.matrix-content-edit-wrap textarea:focus,
.matrix-content-edit-wrap button:focus,
.matrix-content-edit-wrap a:focus { border-color: var(--matrix-orange); outline: 3px solid rgba(255,117,51,0.28); outline-offset: 1px; box-shadow: none; }
.matrix-content-edit-wrap .submit-wrap { margin-top: 1.75rem; padding: 1rem; border: 2px solid #e0e2e4; background: #fff; }
.matrix-content-edit-wrap .submit-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.matrix-content-edit-wrap .submit-wrap button { display: inline-flex; justify-content: center; align-items: center; border: 4px solid transparent; background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); color: #21282f; padding: 10px 22px; font-size: 1rem; font-weight: 700; cursor: pointer; }
.matrix-content-edit-wrap .submit-wrap button:hover { background: var(--matrix-orange-strong); border-color: var(--matrix-orange-soft); }
.matrix-content-edit-wrap .submit-wrap button.secondary { background: #fff; border-color: #c8ced3; color: #323e48; }
.matrix-content-edit-wrap .submit-wrap button.secondary:hover { background: #f5f7f8; border-color: #a8b0b8; }
.matrix-content-edit-wrap .submit-wrap p { margin-top: 0.75rem; font-size: 0.95rem; color: var(--matrix-slate-400); }
.matrix-content-edit-wrap .image-field .image-preview { margin: 0.5rem 0; }
.matrix-content-edit-wrap .image-field .image-preview img { max-width: 180px; max-height: 180px; height: auto; display: block; border: 2px solid #e0e2e4; }
.matrix-content-edit-wrap .image-field .image-preview .no-image { color: #646970; font-size: 0.9rem; font-style: italic; }
.matrix-content-edit-wrap .block-preview { margin: 0 0 0.8rem 0; }
.matrix-content-edit-wrap .block-preview img { display: block; width: 100%; max-height: 320px; object-fit: cover; border: 2px solid #e0e2e4; }
.matrix-content-edit-wrap .matrix-help-text { margin: 0.35rem 0 0; color: var(--matrix-slate-400); font-size: 0.88rem; }
.matrix-content-edit-wrap .matrix-page-links { margin: 0.25rem 0 1rem; padding: 0.75rem 1rem; background: #fff; border: 2px solid #e0e2e4; }
.matrix-content-edit-wrap .matrix-page-links strong { display: block; margin-bottom: 0.4rem; color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-page-links-list { display: flex; flex-wrap: wrap; gap: 8px 12px; }
.matrix-content-edit-wrap .matrix-page-links-list a { color: var(--matrix-slate-700); text-decoration: none; border-bottom: 1px solid transparent; font-size: 0.95rem; }
.matrix-content-edit-wrap .matrix-page-links-list a:hover { border-bottom-color: var(--matrix-orange); }
.matrix-content-edit-wrap .matrix-view-section-btn { display: inline-flex; align-items: center; justify-content: center; margin-left: 8px; padding: 7px 12px; border: 2px solid var(--matrix-orange); background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); color: #21282f; text-decoration: none; font-size: 0.86rem; font-weight: 700; }
.matrix-content-edit-wrap .matrix-view-section-btn:hover { background: var(--matrix-orange-strong); border-color: var(--matrix-orange-soft); }
.matrix-content-edit-wrap .matrix-duplicate-page-btn { display: inline-flex; align-items: center; justify-content: center; margin-left: 8px; padding: 7px 12px; border: 2px solid #c8ced3; background: #fff; color: #323e48; text-decoration: none; font-size: 0.86rem; font-weight: 700; cursor: pointer; }
.matrix-content-edit-wrap .matrix-duplicate-page-btn:hover { background: #f5f7f8; border-color: #a8b0b8; }
.matrix-content-edit-wrap .matrix-auth-actions { position: sticky; top: 0; z-index: 50; display: grid; grid-template-columns: auto auto 1fr auto; align-items: center; gap: 10px; margin-bottom: 0.75rem; background: #fff; border-bottom: 1px solid #eceff1; padding: 6px 0; overflow-x: auto; }
.matrix-content-edit-wrap .matrix-page-select-wrap.status-todo { border-left-color: #d39e00;     max-width: 280px;}
.matrix-content-edit-wrap .matrix-page-select-wrap.status-inprogress { border-left-color: #007bff; }
.matrix-content-edit-wrap .matrix-page-select-wrap.status-done { border-left-color: #28a745; }
.matrix-content-edit-wrap .matrix-page-select-wrap.status-delete { border-left-color: #dc3545; }
.matrix-content-edit-wrap .matrix-page-select-wrap::after { content: '▾'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--matrix-slate-700); font-size: 14px; line-height: 1; pointer-events: none; }
.matrix-content-edit-wrap .matrix-page-select { padding: 8px 34px 8px 10px; border: 2px solid var(--matrix-border); background: #fff; color: var(--matrix-slate-700); font-size: 0.9rem; font-weight: 600; min-width: 220px; appearance: none; -webkit-appearance: none; }
.matrix-content-edit-wrap .matrix-page-status-badge { display: inline-block; margin-left: 8px; padding: 4px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; }
.matrix-content-edit-wrap .matrix-page-status-badge.status-todo { background: #fff3cd; color: #856404; }
.matrix-content-edit-wrap .matrix-page-status-badge.status-inprogress { background: #cce5ff; color: #004085; }
.matrix-content-edit-wrap .matrix-page-status-badge.status-done { background: #d4edda; color: #155724; }
.matrix-content-edit-wrap .matrix-page-status-badge.status-delete { background: #f8d7da; color: #721c24; }
.matrix-content-edit-wrap .matrix-page-status-wrap { margin-top: 0; display: flex; flex-wrap: nowrap; align-items: center; gap: 8px 10px; white-space: nowrap; }
.matrix-content-edit-wrap .matrix-page-status-done-by { margin: 0; font-size: 0.82rem; color: var(--matrix-slate-400); }
.matrix-content-edit-wrap .matrix-field-radio-group { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; }
.matrix-content-edit-wrap .matrix-field-checkbox-group { display: flex; flex-wrap: wrap; gap: 8px 14px; }
.matrix-content-edit-wrap .matrix-radio-label, .matrix-content-edit-wrap .matrix-checkbox-label { cursor: pointer; }
.matrix-content-edit-wrap .matrix-status-radios { display: inline-flex; gap: 8px; align-items: center; }
.matrix-content-edit-wrap .matrix-status-radios label { display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; border-radius: 999px; padding: 4px 10px; cursor: pointer; white-space: nowrap; }
.matrix-content-edit-wrap .matrix-status-radios label.status-todo { background: #fff3cd; color: #856404; border-color: #ffe69c; }
.matrix-content-edit-wrap .matrix-status-radios label.status-inprogress { background: #cfe2ff; color: #084298; border-color: #9ec5fe; }
.matrix-content-edit-wrap .matrix-status-radios label.status-done { background: #d1e7dd; color: #0f5132; border-color: #a3cfbb; }
.matrix-content-edit-wrap .matrix-status-radios label.status-delete { background: #f8d7da; color: #721c24; border-color: #f1aeb5; }
.matrix-content-edit-wrap #matrix-top-status-wrap { justify-self: center; }
.matrix-content-edit-wrap .screen-reader-text { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.matrix-content-edit-wrap .matrix-logout-btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 14px; border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); text-decoration: none; font-size: 0.9rem; font-weight: 600; }
.matrix-content-edit-wrap .matrix-logout-btn:hover { background: #f5f7f8; border-color: #a8b0b8; }
.matrix-content-edit-wrap .matrix-auth-right { display: inline-flex; align-items: center; gap: 8px; }
.matrix-content-edit-wrap .matrix-instructions-btn { width: 34px; height: 34px; border: 2px solid var(--matrix-border); border-radius: 999px; background: #fff; color: var(--matrix-slate-700); font-size: 1rem; font-weight: 700; line-height: 1; cursor: pointer; }
.matrix-content-edit-wrap .matrix-instructions-btn:hover { border-color: var(--matrix-orange); color: #21282f; }
.matrix-content-edit-wrap .matrix-instructions-modal { position: fixed; inset: 0; background: rgba(33,40,47,0.45); display: none; align-items: center; justify-content: center; z-index: 99999; padding: 1rem; }
.matrix-content-edit-wrap .matrix-instructions-modal.matrix-visible { display: flex; }
.matrix-content-edit-wrap .matrix-instructions-dialog { width: min(760px, 100%); max-height: 85vh; overflow: auto; background: #fff; border: 2px solid #e0e2e4; box-shadow: 0 20px 40px rgba(33,40,47,0.18); }
.matrix-content-edit-wrap .matrix-instructions-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid #eceff1; }
.matrix-content-edit-wrap .matrix-instructions-head h2 { margin: 0; font-size: 1.05rem; color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-instructions-close { border: 2px solid #c8ced3; background: #fff; color: var(--matrix-slate-700); padding: 4px 10px; font-size: 0.9rem; cursor: pointer; }
.matrix-content-edit-wrap .matrix-instructions-close:hover { background: #f5f7f8; border-color: #a8b0b8; }
.matrix-content-edit-wrap .matrix-instructions-body { padding: 12px 14px 16px; color: var(--matrix-slate-600); line-height: 1.6; }
.matrix-content-edit-wrap .matrix-review-modal { position: fixed; inset: 0; background: rgba(33,40,47,0.45); display: none; align-items: center; justify-content: center; z-index: 100000; padding: 1rem; }
.matrix-content-edit-wrap .matrix-review-modal.matrix-visible { display: flex; }
.matrix-content-edit-wrap .matrix-review-dialog { width: min(760px, 100%); max-height: 85vh; overflow: auto; background: #fff; border: 2px solid #e0e2e4; box-shadow: 0 20px 40px rgba(33,40,47,0.18); }
.matrix-content-edit-wrap .matrix-review-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid #eceff1; }
.matrix-content-edit-wrap .matrix-review-head h2 { margin: 0; font-size: 1.05rem; color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-review-body { padding: 12px 14px 16px; color: var(--matrix-slate-600); line-height: 1.55; }
.matrix-content-edit-wrap .matrix-review-body ul { margin: 0.25rem 0 0 1.1rem; }
.matrix-content-edit-wrap .matrix-review-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
.matrix-content-edit-wrap .matrix-review-actions button { border: 2px solid #c8ced3; background: #fff; color: #323e48; padding: 8px 14px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
.matrix-content-edit-wrap .matrix-review-actions button.primary { background: linear-gradient(94deg,#ff9461 17.37%,#ff7533 90.35%); border-color: var(--matrix-orange); color: #21282f; }
.matrix-content-edit-wrap .matrix-ai-global-wrap { margin-bottom: 14px; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; background: #f9fafb; }
.matrix-content-edit-wrap .matrix-field-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.matrix-content-edit-wrap .matrix-ai-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; border: 2px solid #111; border-radius: 4px; background: #111; color: #fff; font-weight: 600; line-height: 1.2; transition: background-color 120ms ease, border-color 120ms ease, transform 90ms ease, box-shadow 120ms ease; cursor: pointer; }
.matrix-content-edit-wrap .matrix-ai-btn:hover { background: #2b2b2b; border-color: #2b2b2b; }
.matrix-content-edit-wrap .matrix-ai-btn:active { transform: translateY(1px); background: #000; border-color: #000; }
.matrix-content-edit-wrap .matrix-ai-btn:focus-visible { outline: 3px solid rgba(255,117,51,0.28); outline-offset: 1px; }
.matrix-content-edit-wrap .matrix-ai-btn[hidden] { display: none !important; }
.matrix-content-edit-wrap .matrix-ai-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
.matrix-content-edit-wrap .matrix-ai-btn-secondary { background: #fff; border-color: #c8ced3; color: #323e48; }
.matrix-content-edit-wrap .matrix-ai-btn-secondary:hover { background: #f5f7f8; border-color: #a8b0b8; }
.matrix-content-edit-wrap .matrix-ai-btn-secondary:active { background: #eceff1; border-color: #8f99a3; }
.matrix-content-edit-wrap .matrix-ai-btn-retry { background: #facc15; border-color: #eab308; color: #1f2937; }
.matrix-content-edit-wrap .matrix-ai-btn-retry:hover { background: #eab308; border-color: #ca8a04; }
.matrix-content-edit-wrap .matrix-ai-btn-retry:active { background: #ca8a04; border-color: #a16207; color: #111827; }
.matrix-content-edit-wrap .matrix-ai-btn-accept { background: #22c55e; border-color: #16a34a; color: #052e16; }
.matrix-content-edit-wrap .matrix-ai-btn-accept:hover { background: #16a34a; border-color: #15803d; color: #ecfdf5; }
.matrix-content-edit-wrap .matrix-ai-btn-accept:active { background: #15803d; border-color: #166534; color: #ecfdf5; }
.matrix-content-edit-wrap .matrix-ai-btn-reject { background: #ef4444; border-color: #dc2626; color: #ffffff; }
.matrix-content-edit-wrap .matrix-ai-btn-reject:hover { background: #dc2626; border-color: #b91c1c; }
.matrix-content-edit-wrap .matrix-ai-btn-reject:active { background: #b91c1c; border-color: #991b1b; }
.matrix-content-edit-wrap .matrix-ai-field-open[aria-expanded="true"] { background: #2b2b2b; border-color: #2b2b2b; }
.matrix-content-edit-wrap .matrix-ai-field-panel { display: none; border: 1px solid #dcdcde; border-radius: 8px; padding: 10px; margin-top: 8px; background: #f9fafb; }
.matrix-content-edit-wrap .matrix-ai-field-panel.is-open { display: block; }
.matrix-content-edit-wrap .matrix-ai-field-panel > label { display: block; font-weight: 600; margin-bottom: 4px; color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-ai-field-instructions, .matrix-content-edit-wrap .matrix-ai-field-bullets { width: 100%; min-height: 52px; margin-bottom: 8px; }
.matrix-content-edit-wrap .matrix-ai-field-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.matrix-content-edit-wrap .matrix-ai-feedback-wrap { margin-bottom: 8px; }
.matrix-content-edit-wrap .matrix-ai-field-feedback { width: 100%; min-height: 52px; margin-bottom: 0; }
.matrix-content-edit-wrap .matrix-ai-field-status { margin: 0; min-height: 1.2em; }
.matrix-content-edit-wrap .matrix-ai-field-panel[aria-busy="true"] .matrix-ai-field-status { color: var(--matrix-slate-700); }
.matrix-content-edit-wrap .matrix-ai-field-preview { margin-top: 6px; padding: 8px; border: 1px solid #e2e8f0; background: #fff; border-radius: 4px; white-space: pre-wrap; }
.matrix-content-edit-wrap .matrix-ai-field-preview[hidden] { display: none !important; }
@media (max-width: 768px) {
    .matrix-content-edit-wrap h1 { font-size: 1.7rem; }
    .matrix-content-edit-wrap .matrix-auth-actions { align-items: center; }
    .matrix-content-edit-wrap .matrix-page-select { width: 100%; }
    .matrix-content-edit-wrap .submit-actions { display: grid; grid-template-columns: 1fr; }
    .matrix-content-edit-wrap .submit-wrap button { width: 100%; }
}
.matrix-content-edit-wrap .matrix-page-select-wrap { position: relative;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-left: 4px solid var(--matrix-border);
    border-radius: 4px;
    padding-left: 8px;
    width: 280x;
    width: 100%; }
</style>
<?php endif; ?>
    <?php if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_logout_url')) :
        $logout_redirect = function_exists('home_url') ? home_url('/') : '/';
        $logout_url = wp_logout_url($logout_redirect);
    ?>
    <h1>Edit content<?php echo $site_name !== '' ? ' for ' . esc_html($site_name) : ''; ?></h1>
    <div class="matrix-auth-actions">
        <?php
            $first_pid = !empty($page_order) ? (int) $page_order[0] : 0;
            $first_status = isset($page_status[$first_pid]) && in_array($page_status[$first_pid], ['todo', 'inprogress', 'done', 'delete'], true) ? $page_status[$first_pid] : 'todo';
        ?>
        <div id="matrix-page-select-wrap" class="matrix-page-select-wrap status-<?php echo esc_attr($first_status); ?>">
            <label for="matrix-page-select" class="screen-reader-text">Page form</label>
            <select id="matrix-page-select" class="matrix-page-select" aria-label="Select page form">
                <?php foreach ($page_order as $select_idx => $pid) :
                    $select_page_rows = $by_page[$pid];
                    $select_first = $select_page_rows[0];
                    $select_title = isset($select_first['post_title']) ? $select_first['post_title'] : ('Page ' . $pid);
                    $select_panel_id = 'panel-' . $pid;
                    $select_status = isset($page_status[$pid]) && in_array($page_status[$pid], ['todo', 'inprogress', 'done', 'delete'], true) ? $page_status[$pid] : 'todo';
                    $select_status_label = $select_status === 'done' ? 'Done' : ($select_status === 'inprogress' ? 'In progress' : ($select_status === 'delete' ? 'Delete' : 'To Do'));
                    $select_done_by = isset($page_status_done_by[$pid]) ? (string) $page_status_done_by[$pid] : '';
                ?>
                    <option value="<?php echo esc_attr($select_panel_id); ?>" data-status="<?php echo esc_attr($select_status); ?>" data-title="<?php echo esc_attr($select_title); ?>" data-done-by="<?php echo esc_attr($select_done_by); ?>" <?php selected($select_idx === 0); ?>><?php echo esc_html($select_title . ' — ' . $select_status_label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <span id="matrix-page-status-badge" class="matrix-page-status-badge status-<?php echo esc_attr($first_status); ?>"><?php echo esc_html($first_status === 'done' ? 'Done' : ($first_status === 'inprogress' ? 'In progress' : ($first_status === 'delete' ? 'Delete' : 'To Do'))); ?></span>
        <div class="matrix-page-status-wrap" id="matrix-top-status-wrap">
            <div class="matrix-status-radios" role="group" aria-label="Page status">
                <label class="status-todo"><input type="radio" name="matrix_page_status_current" value="todo" /> To Do</label>
                <label class="status-inprogress"><input type="radio" name="matrix_page_status_current" value="inprogress" /> In progress</label>
                <label class="status-done"><input type="radio" name="matrix_page_status_current" value="done" /> Done</label>
                <label class="status-delete"><input type="radio" name="matrix_page_status_current" value="delete" /> Delete</label>
            </div>
            <p class="matrix-page-status-done-by" id="matrix-top-status-done-by" style="display:none;"></p>
        </div>
        <div class="matrix-auth-right">
            <button type="button" class="matrix-instructions-btn" id="matrix-open-instructions" aria-haspopup="dialog" aria-controls="matrix-instructions-modal" aria-label="Open instructions">?</button>
            <a class="matrix-logout-btn" href="<?php echo esc_url($logout_url); ?>">Log out</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="matrix-title-view-row" aria-live="polite">
        <?php foreach ($page_order as $title_idx => $pid) :
            $title_page_rows = $by_page[$pid];
            $title_panel_id = 'panel-' . $pid;
            $title_page_link_url = function_exists('get_permalink') ? get_permalink((int) $pid) : '';
            $title_page_link_title = isset($title_page_rows[0]['post_title']) ? (string) $title_page_rows[0]['post_title'] : ('Page ' . (int) $pid);
        ?>
        <div class="matrix-title-view-panel <?php echo $title_idx === 0 ? 'matrix-title-view-active' : ''; ?>" data-panel="<?php echo esc_attr($title_panel_id); ?>">
            <strong>View this page:</strong>
            <?php if ($title_page_link_url) : ?>
                <a href="<?php echo esc_url($title_page_link_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($title_page_link_title); ?></a>
            <?php else : ?>
                <span>Link unavailable</span>
            <?php endif; ?>
            <button type="button" class="matrix-duplicate-page-btn" data-matrix-duplicate-post-id="<?php echo (int) $pid; ?>">Duplicate page</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($is_on_site && isset($_GET['matrix_form_saved']) && $_GET['matrix_form_saved'] === '1') :
        $saved_mode = isset($_GET['matrix_form_saved_mode']) ? sanitize_key(wp_unslash($_GET['matrix_form_saved_mode'])) : 'publish';
        $saved_text = ($saved_mode === 'later' || $saved_mode === 'draft')
            ? 'Form saved for later editing. Your changes were not published.'
            : 'Content saved successfully.';
    ?>
        <div class="notice notice-success" style="background:#00a32a;color:#fff;padding:12px 16px;margin-bottom:1rem;"><?php echo esc_html($saved_text); ?></div>
    <?php endif; ?>
    <?php if ($draft_saved_at > 0) : ?>
        <p class="matrix-link-meta-notice">You have a draft from <?php echo esc_html(wp_date('M j, Y g:i a', $draft_saved_at)); ?>. Your edits below include that draft.</p>
    <?php endif; ?>
    <?php if ($client_link_expires_at > 0) : ?>
        <p class="matrix-link-meta-notice">This link expires on <?php echo esc_html(wp_date('M j, Y g:i a', $client_link_expires_at)); ?>.</p>
    <?php endif; ?>
    <?php if ($client_link_reminder_days > 0 && $client_link_created_at > 0) :
        $days_since_link = (int) floor((time() - $client_link_created_at) / DAY_IN_SECONDS);
        if ($days_since_link >= $client_link_reminder_days) :
    ?>
        <p class="matrix-link-meta-notice">Reminder: this editing request has been open for <?php echo (int) $days_since_link; ?> day<?php echo $days_since_link === 1 ? '' : 's'; ?>.</p>
    <?php endif; endif; ?>
    <div class="matrix-instructions-modal" id="matrix-instructions-modal" role="dialog" aria-modal="true" aria-labelledby="matrix-instructions-title">
        <div class="matrix-instructions-dialog">
            <div class="matrix-instructions-head">
                <h2 id="matrix-instructions-title">Instructions</h2>
                <button type="button" class="matrix-instructions-close" id="matrix-close-instructions">Close</button>
            </div>
            <div class="matrix-instructions-body">
                <p>This form is pre-filled with the current content from the site. Use the dropdown above to switch between pages. Edit the fields, then use Save content back to site or Save for later.</p>
                <?php if ($client_requires_approval) : ?>
                    <p style="margin-top:0.5rem;"><strong>Approval mode:</strong> submitting updates sends them for admin review before anything is published live.</p>
                <?php endif; ?>
                <?php if ($client_custom_instructions !== '') : ?>
                    <hr />
                    <h3 style="margin:0 0 0.35rem;color:#21282f;font-size:0.98rem;">Project-specific instructions</h3>
                    <?php echo wp_kses_post(wpautop($client_custom_instructions)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="matrix-review-modal" id="matrix-review-modal" role="dialog" aria-modal="true" aria-labelledby="matrix-review-title">
        <div class="matrix-review-dialog">
            <div class="matrix-review-head">
                <h2 id="matrix-review-title"><?php echo $client_requires_approval ? 'Review changes before submitting' : 'Review changes before publishing'; ?></h2>
                <button type="button" class="matrix-instructions-close" id="matrix-review-cancel-top">Close</button>
            </div>
            <div class="matrix-review-body">
                <p><?php echo $client_requires_approval ? 'You are about to submit changes for admin approval. Please confirm these changes:' : 'You are about to update the live site. Please confirm these changes:'; ?></p>
                <ul id="matrix-review-list"></ul>
                <div class="matrix-review-actions">
                    <button type="button" id="matrix-review-cancel">Cancel</button>
                    <button type="button" class="primary" id="matrix-review-confirm"><?php echo $client_requires_approval ? 'Submit for approval' : 'Publish now'; ?></button>
                </div>
            </div>
        </div>
    </div>
    <form method="post" action="<?php echo esc_url($form_action); ?>" id="matrix-client-form" enctype="multipart/form-data" class="matrix-client-form" novalidate aria-label="Client content editing form" data-matrix-requires-approval="<?php echo $client_requires_approval ? '1' : '0'; ?>" data-matrix-ai-enabled="<?php echo $matrix_ai_enabled ? '1' : '0'; ?>"<?php if ($matrix_ai_url !== '' && $matrix_ai_nonce !== '') : ?> data-matrix-ai-url="<?php echo esc_url($matrix_ai_url); ?>" data-matrix-ai-nonce="<?php echo esc_attr($matrix_ai_nonce); ?>"<?php endif; ?><?php if (isset($data['matrix_save_status_ajax_url'], $data['matrix_save_status_nonce']) && $data['matrix_save_status_ajax_url'] !== '' && $data['matrix_save_status_nonce'] !== '') : ?> data-matrix-save-status-url="<?php echo esc_url($data['matrix_save_status_ajax_url']); ?>" data-matrix-save-status-nonce="<?php echo esc_attr($data['matrix_save_status_nonce']); ?>"<?php endif; ?><?php if (isset($data['matrix_autosave_draft_ajax_url'], $data['matrix_autosave_draft_nonce']) && $data['matrix_autosave_draft_ajax_url'] !== '' && $data['matrix_autosave_draft_nonce'] !== '') : ?> data-matrix-autosave-url="<?php echo esc_url($data['matrix_autosave_draft_ajax_url']); ?>" data-matrix-autosave-nonce="<?php echo esc_attr($data['matrix_autosave_draft_nonce']); ?>" data-matrix-autosave-interval-ms="75000"<?php endif; ?><?php if (isset($data['matrix_duplicate_page_ajax_url'], $data['matrix_duplicate_page_nonce']) && $data['matrix_duplicate_page_ajax_url'] !== '' && $data['matrix_duplicate_page_nonce'] !== '') : ?> data-matrix-duplicate-url="<?php echo esc_url($data['matrix_duplicate_page_ajax_url']); ?>" data-matrix-duplicate-nonce="<?php echo esc_attr($data['matrix_duplicate_page_nonce']); ?>"<?php endif; ?>>
        <input type="hidden" name="matrix_form_token" value="<?php echo esc_attr($token); ?>" />
        <input type="hidden" name="matrix_form_submit" value="1" />
        <input type="hidden" name="matrix_active_page" value="<?php echo $draft_active_page > 0 ? $draft_active_page : (!empty($page_order) ? (int) $page_order[0] : 0); ?>" />
        <input type="hidden" name="matrix_submit_mode" value="publish" />
        <?php foreach ($page_order as $status_pid) :
            $status_val = isset($page_status[$status_pid]) && in_array($page_status[$status_pid], ['todo', 'inprogress', 'done', 'delete'], true) ? $page_status[$status_pid] : 'todo';
        ?>
            <input type="hidden" name="matrix_page_status[<?php echo (int) $status_pid; ?>]" value="<?php echo esc_attr($status_val); ?>" data-matrix-page-status-hidden="<?php echo (int) $status_pid; ?>" />
        <?php endforeach; ?>
        <div class="matrix-form-errors" id="matrix-form-errors" role="alert" aria-live="assertive">
            <strong>Please fix the highlighted fields before saving.</strong>
            <ul id="matrix-form-errors-list"></ul>
        </div>
        <?php if ($matrix_ai_enabled) : ?>
            <div class="field matrix-ai-global-wrap">
                <label class="field-label" for="matrix-ai-global-tone">AI global tone</label>
                <select id="matrix-ai-global-tone">
                    <option value="Professional">Professional</option>
                    <option value="Friendly">Friendly</option>
                    <option value="Confident">Confident</option>
                    <option value="Persuasive">Persuasive</option>
                    <option value="Concise">Concise</option>
                    <option value="Plain language">Plain language</option>
                    <option value="Formal">Formal</option>
                </select>
                <p class="matrix-help-text">Applies to all AI field generations unless overridden by field-level instructions.</p>
            </div>
        <?php endif; ?>

        <?php if (count($page_order) > 1) : ?>
        <div class="tabs" role="tablist">
            <?php foreach ($page_order as $tab_idx => $pid) :
                $page_rows = $by_page[$pid];
                $first = $page_rows[0];
                $page_title = isset($first['post_title']) ? $first['post_title'] : ('Page ' . $pid);
                $tab_id = 'tab-' . $pid;
                $panel_id = 'panel-' . $pid;
            ?>
                <button type="button" class="tab-btn <?php echo $tab_idx === 0 ? 'active' : ''; ?>" role="tab" aria-selected="<?php echo $tab_idx === 0 ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($panel_id); ?>" id="<?php echo esc_attr($tab_id); ?>" data-panel="<?php echo esc_attr($panel_id); ?>"><?php echo esc_html($page_title); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php foreach ($page_order as $tab_idx => $pid) :
            $page_rows = $by_page[$pid];
            $panel_id = 'panel-' . $pid;
        ?>
            <div class="tab-panel <?php echo $tab_idx === 0 ? 'active' : ''; ?>" id="<?php echo esc_attr($panel_id); ?>" role="tabpanel" aria-labelledby="tab-<?php echo (int) $pid; ?>">
                <?php foreach ($page_rows as $row) :
                    $i = $row['_global_index'];
                    $draft_row_fields = isset($draft_fields_by_index[$i]) && is_array($draft_fields_by_index[$i]) ? $draft_fields_by_index[$i] : [];
                    $post_id = isset($row['post_id']) ? $row['post_id'] : '';
                    $post_title = isset($row['post_title']) ? $row['post_title'] : '';
                    $block_source = isset($row['block_source']) ? $row['block_source'] : '';
                    $block_index = isset($row['block_index']) ? $row['block_index'] : 0;
                    $block_type = isset($row['block_type']) ? $row['block_type'] : '';
                    $block_label = Matrix_Export::get_block_layout_label((int) $post_id, (string) $block_source, (string) $block_type);
                    $post_type_label = isset($row['post_type']) ? $row['post_type'] : 'page';
                    $all_row_keys = array_diff(array_keys($row), array_merge($meta_keys, ['_global_index']));
                    $row_field_keys = array_filter($all_row_keys, function ($fn) { return Matrix_Export::is_content_field($fn); });
                ?>
                    <div class="block" data-row-index="<?php echo (int) $i; ?>">
                        <input type="hidden" name="matrix_post_id[]" value="<?php echo esc_attr($post_id); ?>" />
                        <input type="hidden" name="matrix_block_source[]" value="<?php echo esc_attr($block_source); ?>" />
                        <input type="hidden" name="matrix_block_index[]" value="<?php echo esc_attr($block_index); ?>" />
                        <input type="hidden" name="matrix_block_type[]" value="<?php echo esc_attr($block_type); ?>" />
                        <?php
                        $preview_url = '';
                        if ($block_source !== Matrix_Export::POST_FIELDS_SOURCE && function_exists('get_permalink')) {
                            $anchor = Matrix_Export::get_block_anchor_id($block_source, (int) $block_index, (string) $block_type);
                            $preview_url = get_permalink((int) $post_id);
                            if ($preview_url) {
                                $preview_url = Matrix_Export::normalize_url_for_current_request($preview_url);
                                $preview_url = remove_query_arg('matrix_preview', $preview_url);
                                $preview_url .= '#' . $anchor;
                            }
                        }
                        ?>
                        <?php if (isset($block_preview_urls[$i]) && $block_preview_urls[$i]) : ?>
                            <div class="block-preview">
                                <img src="<?php echo esc_url($block_preview_urls[$i]); ?>" alt="Section preview" loading="lazy" />
                            </div>
                        <?php endif; ?>

                        <div class="block-title">
                            <?php if ($block_source === Matrix_Export::POST_FIELDS_SOURCE) : ?>
                                <?php echo esc_html($post_title); ?> (<?php echo esc_html($post_type_label); ?>) · Core post fields (if applicable)
                            <?php else : ?>
                                <?php echo esc_html($post_title); ?> (<?php echo esc_html($post_type_label); ?>) · Block <?php echo (int) $block_index + 1; ?> – <?php echo esc_html($block_label); ?>
                                <?php if ($preview_url) : ?>
                                    <a class="matrix-view-section-btn" href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">View Section</a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($block_source === Matrix_Export::FLEX_FIELD) :
                                $is_disabled = function_exists('matrix_export_is_block_disabled')
                                    ? matrix_export_is_block_disabled((int) $post_id, (string) $block_source, (int) $block_index)
                                    : false;
                                if (array_key_exists($i, $draft_block_enabled)) {
                                    $is_disabled = ((int) $draft_block_enabled[$i] === 0);
                                }
                            ?>
                                <span style="display:inline-block;margin-left:10px;">
                                    <input type="hidden" name="matrix_block_enabled[<?php echo (int) $i; ?>]" value="1" />
                                    <label style="font-weight:600;">
                                        <input type="checkbox" name="matrix_block_enabled[<?php echo (int) $i; ?>]" value="0" <?php checked($is_disabled); ?> />
                                        Disable this block
                                    </label>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($row_field_keys as $field_name) :
                            $raw = isset($row[$field_name]) ? $row[$field_name] : '';
                            if (array_key_exists($field_name, $draft_row_fields)) {
                                $raw = $draft_row_fields[$field_name];
                            }
                            $field_label = Matrix_Export::get_human_field_label($field_name);
                            $parsed = Matrix_Export::get_form_field_value($raw, $field_name);
                            $field_id = 'matrix-field-' . (int) $i . '-' . sanitize_key($field_name);
                            if ($parsed['type'] === 'skip') continue;

                            $taxonomy_slug = Matrix_Export::get_taxonomy_from_form_field_name($field_name);
                            $taxonomy_choices = $taxonomy_slug !== '' ? Matrix_Export::get_form_taxonomy_field_choices($taxonomy_slug) : [];
                            $select_choices = Matrix_Export::get_form_select_field_choices($field_name);
                            $is_yes_no = empty($select_choices) && empty($taxonomy_choices) && $parsed['type'] === 'text' && Matrix_Export::is_yes_no_field_value($raw, $field_name);
                            $yes_no_choices = $is_yes_no ? Matrix_Export::get_yes_no_choices() : [];
                            $is_link_like = Matrix_Export::looks_like_link_field_name($field_name);
                            if ($parsed['type'] === 'taxonomy' || (!empty($taxonomy_choices) && $taxonomy_slug !== '')) :
                                $selected_term_ids = isset($parsed['term_ids']) && is_array($parsed['term_ids'])
                                    ? array_values(array_unique(array_map('intval', $parsed['term_ids'])))
                                    : [];
                                if (array_key_exists($field_name, $draft_row_fields) && is_array($draft_row_fields[$field_name])) {
                                    $selected_term_ids = array_values(array_unique(array_filter(array_map('intval', $draft_row_fields[$field_name]))));
                                }
                            ?>
                            <div class="field">
                                <label class="field-label"><?php echo esc_html($field_label); ?></label>
                                <?php if (!empty($taxonomy_choices)) : ?>
                                    <input type="hidden" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>][]" value="" />
                                    <div class="matrix-field-checkbox-group" style="display:flex;flex-wrap:wrap;gap:8px 14px;">
                                        <?php foreach ($taxonomy_choices as $term_id => $term_label) : ?>
                                            <label class="matrix-checkbox-label" style="display:inline-flex;align-items:center;gap:6px;font-weight:500;">
                                                <input type="checkbox" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>][]" value="<?php echo (int) $term_id; ?>" <?php checked(in_array((int) $term_id, $selected_term_ids, true)); ?> />
                                                <?php echo esc_html($term_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?>
                                    <p class="matrix-help-text">No terms available for this taxonomy yet.</p>
                                <?php endif; ?>
                            </div>
                        <?php
                            elseif (!empty($yes_no_choices)) :
                                $selected_yn = isset($parsed['value']) ? (string) $parsed['value'] : (string) $raw;
                                if ($selected_yn !== '0' && $selected_yn !== '1') {
                                    $selected_yn = '0';
                                }
                            ?>
                            <div class="field">
                                <span class="field-label"><?php echo esc_html($field_label); ?></span>
                                <div class="matrix-field-radio-group matrix-status-radios" role="group" aria-labelledby="<?php echo esc_attr($field_id . '-label'); ?>">
                                    <?php $yn_first = true; foreach ($yes_no_choices as $yn_value => $yn_label) : ?>
                                        <label class="matrix-radio-label" style="display:inline-flex;align-items:center;gap:6px;font-weight:500;">
                                            <input type="radio" id="<?php echo $yn_first ? esc_attr($field_id) : esc_attr($field_id . '-' . $yn_value); ?>" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" value="<?php echo esc_attr($yn_value); ?>" <?php checked($selected_yn, $yn_value); ?> />
                                            <?php echo esc_html($yn_label); ?>
                                        </label>
                                    <?php $yn_first = false; endforeach; ?>
                                </div>
                                <span id="<?php echo esc_attr($field_id . '-label'); ?>" class="screen-reader-text"><?php echo esc_html($field_label); ?></span>
                            </div>
                        <?php
                            elseif (!empty($select_choices)) :
                                $selected_value = isset($parsed['value']) ? (string) $parsed['value'] : (string) $raw;
                                $use_radios = count($select_choices) === 2;
                            ?>
                            <div class="field">
                                <?php if ($use_radios) : ?>
                                    <span class="field-label"><?php echo esc_html($field_label); ?></span>
                                    <div class="matrix-field-radio-group" role="group" aria-labelledby="<?php echo esc_attr($field_id . '-label'); ?>">
                                        <?php $opt_first = true; foreach ($select_choices as $opt_value => $opt_label) : ?>
                                            <label class="matrix-radio-label" style="display:inline-flex;align-items:center;gap:6px;font-weight:500;">
                                                <input type="radio" id="<?php echo $opt_first ? esc_attr($field_id) : esc_attr($field_id . '-' . sanitize_key((string) $opt_value)); ?>" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" value="<?php echo esc_attr($opt_value); ?>" <?php selected($selected_value, $opt_value); ?> />
                                                <?php echo esc_html($opt_label); ?>
                                            </label>
                                        <?php $opt_first = false; endforeach; ?>
                                    </div>
                                    <span id="<?php echo esc_attr($field_id . '-label'); ?>" class="screen-reader-text"><?php echo esc_html($field_label); ?></span>
                                <?php else : ?>
                                    <label class="field-label" for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></label>
                                    <select id="<?php echo esc_attr($field_id); ?>" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]">
                                        <?php foreach ($select_choices as $opt_value => $opt_label) : ?>
                                            <option value="<?php echo esc_attr($opt_value); ?>" <?php selected($selected_value, $opt_value); ?>><?php echo esc_html($opt_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php
                            elseif ($parsed['type'] === 'link' || ($parsed['type'] === 'text' && $is_link_like)) :
                                $url = isset($parsed['url']) ? $parsed['url'] : '';
                                $title = isset($parsed['title']) ? $parsed['title'] : '';
                                $draft_url_key = $field_name . '__matrix_link_url';
                                $draft_title_key = $field_name . '__matrix_link_title';
                                if (array_key_exists($draft_url_key, $draft_row_fields)) {
                                    $url = (string) $draft_row_fields[$draft_url_key];
                                }
                                if (array_key_exists($draft_title_key, $draft_row_fields)) {
                                    $title = (string) $draft_row_fields[$draft_title_key];
                                }
                                if ($parsed['type'] === 'text' && $is_link_like && isset($parsed['value'])) {
                                    $v = $parsed['value'];
                                    if (preg_match('#^https?://#', $v)) {
                                        $url = $v;
                                    }
                                }
                            ?>
                            <div class="field">
                                <label class="field-label" for="<?php echo esc_attr($field_id . '-url'); ?>"><?php echo esc_html($field_label); ?> – URL</label>
                                <?php $url_help = Matrix_Export::get_field_help_text($field_name . '__matrix_link_url'); ?>
                                <input id="<?php echo esc_attr($field_id . '-url'); ?>" type="text" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>__matrix_link_url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com" data-matrix-validate="url" data-matrix-label="<?php echo esc_attr($field_label . ' URL'); ?>" />
                                <?php if ($url_help !== '') : ?><p class="matrix-help-text"><?php echo esc_html($url_help); ?></p><?php endif; ?>
                                <p id="<?php echo esc_attr($field_id . '-url-error'); ?>" class="matrix-field-error" aria-live="polite"></p>
                            </div>
                            <div class="field">
                                <label class="field-label" for="<?php echo esc_attr($field_id . '-title'); ?>"><?php echo esc_html($field_label); ?> – Button / link text</label>
                                <?php $title_help = Matrix_Export::get_field_help_text($field_name . '__matrix_link_title'); ?>
                                <input id="<?php echo esc_attr($field_id . '-title'); ?>" type="text" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>__matrix_link_title]" value="<?php echo esc_attr($title); ?>" data-matrix-count="chars" data-matrix-count-max="36" />
                                <?php if ($title_help !== '') : ?><p class="matrix-help-text"><?php echo esc_html($title_help); ?></p><?php endif; ?>
                                <p id="<?php echo esc_attr($field_id . '-title-count'); ?>" class="matrix-count-hint" aria-live="polite"></p>
                            </div>
                        <?php
                            elseif ($parsed['type'] === 'image' || Matrix_Export::looks_like_image_field_name($field_name)) :
                                $img_url = isset($parsed['url']) && $parsed['url'] !== '' ? $parsed['url'] : Matrix_Export::get_image_thumbnail_url($raw);
                                if ($img_url && strpos($img_url, 'http') !== 0 && function_exists('home_url')) {
                                    $img_url = home_url($img_url);
                                }
                        ?>
                            <div class="field image-field">
                                <label class="field-label" for="<?php echo esc_attr($field_id . '-file'); ?>"><?php echo esc_html($field_label); ?></label>
                                <?php if ($img_url) : ?>
                                    <div class="image-preview"><img src="<?php echo esc_url($img_url); ?>" alt="" data-matrix-preview /></div>
                                <?php else : ?>
                                    <div class="image-preview"><span class="no-image">No image</span></div>
                                <?php endif; ?>
                                <label class="field-label" style="margin-bottom:4px;">Replace image</label>
                                <input id="<?php echo esc_attr($field_id . '-file'); ?>" type="file" class="matrix-image-upload" name="matrix_image[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" accept="image/*" data-max-bytes="<?php echo (int) (defined('MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES') ? MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES : 2097152); ?>" aria-describedby="<?php echo esc_attr($field_id . '-help'); ?>" />
                                <?php $image_help = Matrix_Export::get_image_help_text($field_name); ?>
                                <p id="<?php echo esc_attr($field_id . '-help'); ?>" class="matrix-help-text">Max <?php echo esc_html(size_format(defined('MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES') ? MATRIX_EXPORT_MAX_IMAGE_UPLOAD_BYTES : 2097152)); ?> per image. <?php echo esc_html($image_help); ?> <span data-matrix-image-dimensions></span></p>
                            </div>
                        <?php
                            elseif ($parsed['type'] === 'video' || Matrix_Export::looks_like_video_file_field_name($field_name)) :
                                $video_url = isset($parsed['url']) ? (string) $parsed['url'] : '';
                                $video_id = isset($parsed['id']) ? (int) $parsed['id'] : 0;
                                if ($video_url === '' && is_string($raw) && preg_match('#^https?://#', $raw)) {
                                    $video_url = $raw;
                                }
                        ?>
                            <div class="field">
                                <label class="field-label" for="<?php echo esc_attr($field_id . '-video-file'); ?>"><?php echo esc_html($field_label); ?></label>
                                <?php if ($video_url !== '') : ?>
                                    <p class="matrix-help-text" style="margin: 0 0 8px;">Current file: <a href="<?php echo esc_url($video_url); ?>" target="_blank" rel="noopener">View current video</a></p>
                                <?php elseif ($video_id > 0) : ?>
                                    <p class="matrix-help-text" style="margin: 0 0 8px;">Current file ID: <?php echo (int) $video_id; ?></p>
                                <?php else : ?>
                                    <p class="matrix-help-text" style="margin: 0 0 8px;">No video file selected.</p>
                                <?php endif; ?>
                                <label class="field-label" style="margin-bottom:4px;">Replace video file</label>
                                <input id="<?php echo esc_attr($field_id . '-video-file'); ?>" type="file" class="matrix-video-upload" name="matrix_video[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" accept="video/mp4,video/webm,video/ogg,video/quicktime,video/x-m4v" />
                                <p class="matrix-help-text">Supported formats: MP4, WebM, OGG, MOV.</p>
                            </div>
                        <?php
                            else :
                                $val = isset($parsed['value']) ? $parsed['value'] : '';
                                $long = Matrix_Export::is_long_text_field($field_name, $val);
                                $use_editor = $is_on_site && $long && function_exists('wp_editor');
                        ?>
                            <div class="field">
                                <div class="matrix-field-head">
                                    <label class="field-label" for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field_label); ?></label>
                                    <?php if ($matrix_ai_enabled) : ?>
                                        <button type="button" class="button button-small matrix-ai-field-open matrix-ai-btn matrix-ai-btn-primary" data-ai-panel-id="<?php echo esc_attr($field_id . '-ai-panel'); ?>" aria-controls="<?php echo esc_attr($field_id . '-ai-panel'); ?>" aria-expanded="false">A.I mode</button>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $is_required = (bool) preg_match('/(^|__|_)(headline|heading|title)($|__|_)/i', (string) $field_name);
                                $is_summary = (bool) preg_match('/(^|__|_)(summary|excerpt|description|subheading|dek)($|__|_)/i', (string) $field_name);
                                $field_help = Matrix_Export::get_field_help_text($field_name);
                                $count_attr = $is_summary ? ' data-matrix-count="words" data-matrix-count-max="30"' : ($is_required ? ' data-matrix-count="chars" data-matrix-count-max="70"' : '');
                                if ($use_editor) :
                                    $editor_id = 'matrix_field_' . $i . '_' . sanitize_key($field_name);
                                    $editor_name = 'matrix_field[' . (int) $i . '][' . esc_attr($field_name) . ']';
                                    wp_editor($val, $editor_id, [
                                        'textarea_name' => $editor_name,
                                        'textarea_rows' => 10,
                                        'media_buttons' => true,
                                        'teeny' => false,
                                        'quicktags' => true,
                                        'tinymce' => ['toolbar1' => 'formatselect,bold,italic,link,unlink,bullist,numlist,blockquote'],
                                    ]);
                                    if ($field_help !== '') :
                                ?>
                                        <p class="matrix-help-text"><?php echo esc_html($field_help); ?></p>
                                <?php endif; if ($is_required || $is_summary) : ?>
                                        <p id="<?php echo esc_attr($editor_id . '-count'); ?>" class="matrix-count-hint" aria-live="polite"></p>
                                <?php endif;
                                    if ($matrix_ai_enabled) : ?>
                                        <div id="<?php echo esc_attr($field_id . '-ai-panel'); ?>" class="matrix-ai-field-panel" data-row-index="<?php echo (int) $i; ?>" data-field-key="<?php echo esc_attr($field_name); ?>" data-field-label="<?php echo esc_attr($field_label); ?>" data-field-target-id="<?php echo esc_attr($editor_id); ?>" role="group" aria-label="<?php echo esc_attr($field_label . ' AI tools'); ?>">
                                            <label>Instructions (optional)</label>
                                            <textarea class="matrix-ai-field-instructions" rows="2" placeholder="Specific direction for this field..."></textarea>
                                            <label>Bullet points (optional)</label>
                                            <textarea class="matrix-ai-field-bullets" rows="2" placeholder="- Key point 1&#10;- Key point 2"></textarea>
                                            <div class="matrix-ai-field-actions">
                                                <button type="button" class="button button-small matrix-ai-field-generate matrix-ai-btn matrix-ai-btn-primary">Generate</button>
                                                <button type="button" class="button button-small matrix-ai-field-retry matrix-ai-btn matrix-ai-btn-retry" hidden>Try again</button>
                                                <button type="button" class="button button-small matrix-ai-field-accept matrix-ai-btn matrix-ai-btn-accept" hidden>Accept</button>
                                                <button type="button" class="button button-small matrix-ai-field-reject matrix-ai-btn matrix-ai-btn-reject" hidden>Reject</button>
                                            </div>
                                            <div class="matrix-ai-feedback-wrap" hidden>
                                                <label>Rejection reason for next try (optional)</label>
                                                <textarea class="matrix-ai-field-feedback" rows="2" placeholder="e.g. Not enough text. Keep all sections and modernize tone."></textarea>
                                            </div>
                                            <p class="matrix-ai-field-status description" role="status" aria-live="polite"></p>
                                            <div class="matrix-ai-field-preview" hidden></div>
                                        </div>
                                <?php endif;
                                elseif ($long) : ?>
                                    <textarea id="<?php echo esc_attr($field_id); ?>" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" rows="6" class="matrix-textarea-html"<?php echo $is_required ? ' data-matrix-required="1"' : ''; ?><?php echo $count_attr; ?> data-matrix-label="<?php echo esc_attr($field_label); ?>"><?php echo esc_textarea($val); ?></textarea>
                                    <?php if ($field_help !== '') : ?><p class="matrix-help-text"><?php echo esc_html($field_help); ?></p><?php endif; ?>
                                    <?php if ($is_required || $is_summary) : ?><p id="<?php echo esc_attr($field_id . '-count'); ?>" class="matrix-count-hint" aria-live="polite"></p><?php endif; ?>
                                    <p id="<?php echo esc_attr($field_id . '-error'); ?>" class="matrix-field-error" aria-live="polite"></p>
                                    <?php if ($matrix_ai_enabled) : ?>
                                        <div id="<?php echo esc_attr($field_id . '-ai-panel'); ?>" class="matrix-ai-field-panel" data-row-index="<?php echo (int) $i; ?>" data-field-key="<?php echo esc_attr($field_name); ?>" data-field-label="<?php echo esc_attr($field_label); ?>" data-field-target-id="<?php echo esc_attr($field_id); ?>" role="group" aria-label="<?php echo esc_attr($field_label . ' AI tools'); ?>">
                                            <label>Instructions (optional)</label>
                                            <textarea class="matrix-ai-field-instructions" rows="2" placeholder="Specific direction for this field..."></textarea>
                                            <label>Bullet points (optional)</label>
                                            <textarea class="matrix-ai-field-bullets" rows="2" placeholder="- Key point 1&#10;- Key point 2"></textarea>
                                            <div class="matrix-ai-field-actions">
                                                <button type="button" class="button button-small matrix-ai-field-generate matrix-ai-btn matrix-ai-btn-primary">Generate</button>
                                                <button type="button" class="button button-small matrix-ai-field-retry matrix-ai-btn matrix-ai-btn-retry" hidden>Try again</button>
                                                <button type="button" class="button button-small matrix-ai-field-accept matrix-ai-btn matrix-ai-btn-accept" hidden>Accept</button>
                                                <button type="button" class="button button-small matrix-ai-field-reject matrix-ai-btn matrix-ai-btn-reject" hidden>Reject</button>
                                            </div>
                                            <div class="matrix-ai-feedback-wrap" hidden>
                                                <label>Rejection reason for next try (optional)</label>
                                                <textarea class="matrix-ai-field-feedback" rows="2" placeholder="e.g. Not enough text. Keep all sections and modernize tone."></textarea>
                                            </div>
                                            <p class="matrix-ai-field-status description" role="status" aria-live="polite"></p>
                                            <div class="matrix-ai-field-preview" hidden></div>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <input id="<?php echo esc_attr($field_id); ?>" type="text" name="matrix_field[<?php echo (int) $i; ?>][<?php echo esc_attr($field_name); ?>]" value="<?php echo esc_attr($val); ?>"<?php echo $is_required ? ' data-matrix-required="1"' : ''; ?><?php echo $count_attr; ?> data-matrix-label="<?php echo esc_attr($field_label); ?>" />
                                    <?php if ($field_help !== '') : ?><p class="matrix-help-text"><?php echo esc_html($field_help); ?></p><?php endif; ?>
                                    <?php if ($is_required || $is_summary) : ?><p id="<?php echo esc_attr($field_id . '-count'); ?>" class="matrix-count-hint" aria-live="polite"></p><?php endif; ?>
                                    <p id="<?php echo esc_attr($field_id . '-error'); ?>" class="matrix-field-error" aria-live="polite"></p>
                                    <?php if ($matrix_ai_enabled) : ?>
                                        <div id="<?php echo esc_attr($field_id . '-ai-panel'); ?>" class="matrix-ai-field-panel" data-row-index="<?php echo (int) $i; ?>" data-field-key="<?php echo esc_attr($field_name); ?>" data-field-label="<?php echo esc_attr($field_label); ?>" data-field-target-id="<?php echo esc_attr($field_id); ?>" role="group" aria-label="<?php echo esc_attr($field_label . ' AI tools'); ?>">
                                            <label>Instructions (optional)</label>
                                            <textarea class="matrix-ai-field-instructions" rows="2" placeholder="Specific direction for this field..."></textarea>
                                            <label>Bullet points (optional)</label>
                                            <textarea class="matrix-ai-field-bullets" rows="2" placeholder="- Key point 1&#10;- Key point 2"></textarea>
                                            <div class="matrix-ai-field-actions">
                                                <button type="button" class="button button-small matrix-ai-field-generate matrix-ai-btn matrix-ai-btn-primary">Generate</button>
                                                <button type="button" class="button button-small matrix-ai-field-retry matrix-ai-btn matrix-ai-btn-retry" hidden>Try again</button>
                                                <button type="button" class="button button-small matrix-ai-field-accept matrix-ai-btn matrix-ai-btn-accept" hidden>Accept</button>
                                                <button type="button" class="button button-small matrix-ai-field-reject matrix-ai-btn matrix-ai-btn-reject" hidden>Reject</button>
                                            </div>
                                            <div class="matrix-ai-feedback-wrap" hidden>
                                                <label>Rejection reason for next try (optional)</label>
                                                <textarea class="matrix-ai-field-feedback" rows="2" placeholder="e.g. Not enough text. Keep all sections and modernize tone."></textarea>
                                            </div>
                                            <p class="matrix-ai-field-status description" role="status" aria-live="polite"></p>
                                            <div class="matrix-ai-field-preview" hidden></div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="submit-wrap">
            <div class="submit-actions">
                <button type="submit" data-submit-mode="publish"><?php echo $client_requires_approval ? 'Submit for approval' : 'Update site content'; ?></button>
                <button type="submit" class="secondary" data-submit-mode="later">Save for later</button>
            </div>
            <p>Your browser will send the form to the website. Make sure you are connected to the internet.</p>
            <p class="matrix-help-text" id="matrix-draft-save-status"><?php echo $draft_saved_at > 0 ? esc_html('Last saved at ' . wp_date('M j, Y g:i a', $draft_saved_at) . '.') : esc_html('No recent autosave yet.'); ?></p>
            <p class="matrix-submit-progress" id="matrix-submit-progress">Saving... Please keep this tab open. <a id="matrix-submit-progress-link" href="#" target="_blank" rel="noopener">Open this form in a new tab</a></p>
        </div>
        <div class="matrix-panel-nav">
            <button id="matrix-bottom-prev" type="button" class="matrix-panel-nav-btn" data-target-panel="" disabled>Previous page</button>
            <button id="matrix-bottom-next" type="button" class="matrix-panel-nav-btn" data-target-panel="" <?php echo count($page_order) > 1 ? '' : 'disabled'; ?>>Next page</button>
        </div>
    </form>

    <script>
    (function() {
        var wrap = document.querySelector('.matrix-content-edit-wrap') || document.body;
        var form = wrap.querySelector('.matrix-client-form') || wrap.querySelector('#matrix-client-form');
        var instructionsOpenBtn = document.getElementById('matrix-open-instructions');
        var instructionsModal = document.getElementById('matrix-instructions-modal');
        var instructionsCloseBtn = document.getElementById('matrix-close-instructions');
        var reviewModal = document.getElementById('matrix-review-modal');
        var reviewList = document.getElementById('matrix-review-list');
        var reviewCancel = document.getElementById('matrix-review-cancel');
        var reviewCancelTop = document.getElementById('matrix-review-cancel-top');
        var reviewConfirm = document.getElementById('matrix-review-confirm');
        var activePageInput = form ? form.querySelector('input[name="matrix_active_page"]') : null;
        var submitModeInput = form ? form.querySelector('input[name="matrix_submit_mode"]') : null;
        var tabs = wrap.querySelectorAll('.tab-btn');
        var pageSelect = wrap.querySelector('#matrix-page-select');
        var panels = wrap.querySelectorAll('.tab-panel');
        var pageStatusBadge = wrap.querySelector('#matrix-page-status-badge');
        var pageSelectWrap = wrap.querySelector('#matrix-page-select-wrap');
        var topStatusDoneBy = document.getElementById('matrix-top-status-done-by');
        var autosaveUrl = form ? form.getAttribute('data-matrix-autosave-url') : '';
        var autosaveNonce = form ? form.getAttribute('data-matrix-autosave-nonce') : '';
        var requiresApproval = form ? String(form.getAttribute('data-matrix-requires-approval') || '0') === '1' : false;
        var aiEnabled = form ? String(form.getAttribute('data-matrix-ai-enabled') || '0') === '1' : false;
        var aiUrl = form ? String(form.getAttribute('data-matrix-ai-url') || '') : '';
        var aiNonce = form ? String(form.getAttribute('data-matrix-ai-nonce') || '') : '';
        var duplicateUrl = form ? form.getAttribute('data-matrix-duplicate-url') : '';
        var duplicateNonce = form ? form.getAttribute('data-matrix-duplicate-nonce') : '';
        var autosaveIntervalMs = form ? parseInt(form.getAttribute('data-matrix-autosave-interval-ms'), 10) : 75000;
        var tokenInput = form ? form.querySelector('input[name="matrix_form_token"]') : null;
        var formErrorsWrap = document.getElementById('matrix-form-errors');
        var formErrorsList = document.getElementById('matrix-form-errors-list');
        var submitProgressEl = document.getElementById('matrix-submit-progress');
        var submitProgressLink = document.getElementById('matrix-submit-progress-link');
        var draftSaveStatusEl = document.getElementById('matrix-draft-save-status');
        var isSubmitting = false;
        var autosaveInFlight = false;
        var lastSubmitButton = null;
        var baselineSnapshot = '';
        var publishReviewApproved = false;
        if (instructionsOpenBtn && instructionsModal) {
            instructionsOpenBtn.addEventListener('click', function() {
                instructionsModal.classList.add('matrix-visible');
            });
            if (instructionsCloseBtn) {
                instructionsCloseBtn.addEventListener('click', function() {
                    instructionsModal.classList.remove('matrix-visible');
                });
            }
            instructionsModal.addEventListener('click', function(e) {
                if (e.target === instructionsModal) {
                    instructionsModal.classList.remove('matrix-visible');
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    instructionsModal.classList.remove('matrix-visible');
                }
            });
        }

        function syncEditors() {
            var editorGlobal = null;
            if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
                editorGlobal = window.tinyMCE;
            } else if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                editorGlobal = window.tinymce;
            }
            if (editorGlobal) editorGlobal.triggerSave();
        }
        function formSnapshot() {
            if (!form) return '';
            syncEditors();
            var parts = [];
            form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(field) {
                if (field.disabled) return;
                var name = field.name || '';
                if (!name || name === 'matrix_form_token' || name === 'matrix_form_submit') return;
                var type = (field.type || '').toLowerCase();
                if (type === 'hidden' || type === 'submit' || type === 'button') return;
                if (type === 'file') {
                    var files = field.files || [];
                    var fp = [];
                    for (var i = 0; i < files.length; i++) fp.push([files[i].name, files[i].size, files[i].lastModified].join(':'));
                    parts.push(name + '::' + fp.join(','));
                } else if (type === 'checkbox' || type === 'radio') {
                    parts.push(name + '::' + (field.checked ? '1' : '0'));
                } else {
                    parts.push(name + '::' + String(field.value || ''));
                }
            });
            return parts.join('\n');
        }
        function isDirty() {
            return formSnapshot() !== baselineSnapshot;
        }
        function markClean() {
            baselineSnapshot = formSnapshot();
        }
        function isFieldChanged(field) {
            if (!field || !field.hasAttribute('data-matrix-origin')) return false;
            return field.getAttribute('data-matrix-origin') !== getFieldComparableValue(field);
        }
        function getFieldComparableValue(field) {
            if (!field) return '';
            var type = (field.type || '').toLowerCase();
            if (type === 'file') {
                var files = field.files || [];
                var fp = [];
                for (var i = 0; i < files.length; i++) fp.push([files[i].name, files[i].size, files[i].lastModified].join(':'));
                return fp.join(',');
            }
            if (type === 'checkbox' || type === 'radio') {
                return field.checked ? '1' : '0';
            }
            return String(field.value || '');
        }
        function buildPublishReviewItems() {
            if (!form) return [];
            syncEditors();
            var byPage = {};
            form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(field) {
                if (field.disabled) return;
                var name = field.name || '';
                if (!name || name === 'matrix_form_token' || name === 'matrix_form_submit') return;
                var type = (field.type || '').toLowerCase();
                if (type === 'hidden' || type === 'submit' || type === 'button') return;
                if (!field.hasAttribute('data-matrix-origin')) {
                    field.setAttribute('data-matrix-origin', getFieldComparableValue(field));
                    return;
                }
                var original = field.getAttribute('data-matrix-origin');
                var current = getFieldComparableValue(field);
                if (original === current) return;
                var panel = field.closest('.tab-panel');
                if (!panel) return;
                var panelId = panel.getAttribute('id') || '';
                if (!panelId) return;
                var pageTitle = panelId;
                if (pageSelect) {
                    var opt = pageSelect.querySelector('option[value="' + panelId + '"]');
                    if (opt) pageTitle = opt.getAttribute('data-title') || opt.textContent || panelId;
                }
                var block = field.closest('.block');
                var blockTitle = 'Core post fields (if applicable)';
                if (block) {
                    var titleEl = block.querySelector('.block-title');
                    if (titleEl) blockTitle = (titleEl.textContent || '').replace(/\s+/g, ' ').trim();
                }
                if (!byPage[pageTitle]) byPage[pageTitle] = {};
                byPage[pageTitle][blockTitle] = true;
            });
            return Object.keys(byPage).sort().map(function(page) {
                var blocks = Object.keys(byPage[page]).sort();
                return page + ' — ' + blocks.join('; ');
            });
        }
        function openPublishReview(items, onConfirm) {
            if (!reviewModal || !reviewList) {
                onConfirm();
                return;
            }
            reviewList.innerHTML = '';
            if (!items || !items.length) {
                var li = document.createElement('li');
                li.textContent = 'No textual changes detected. You can still continue if this is expected.';
                reviewList.appendChild(li);
            } else {
                items.forEach(function(item) {
                    var li = document.createElement('li');
                    li.textContent = item;
                    reviewList.appendChild(li);
                });
            }
            reviewModal.classList.add('matrix-visible');
            var close = function() { reviewModal.classList.remove('matrix-visible'); };
            var cancelHandler = function() { close(); };
            var confirmHandler = function() { close(); onConfirm(); };
            reviewCancel && reviewCancel.addEventListener('click', cancelHandler, { once: true });
            reviewCancelTop && reviewCancelTop.addEventListener('click', cancelHandler, { once: true });
            reviewConfirm && reviewConfirm.addEventListener('click', confirmHandler, { once: true });
            reviewModal.addEventListener('click', function(e) { if (e.target === reviewModal) close(); }, { once: true });
        }
        function showProgress(mode) {
            if (!submitProgressEl) return;
            submitProgressEl.classList.add('matrix-visible');
            if (submitProgressLink) submitProgressLink.href = window.location.href;
            if (mode === 'later') {
                submitProgressEl.firstChild.textContent = 'Saving draft... Please keep this tab open. ';
                return;
            }
            submitProgressEl.firstChild.textContent = requiresApproval
                ? 'Submitting for approval... Please keep this tab open. '
                : 'Saving live updates... Please keep this tab open. ';
        }
        function getFieldKeyFromName(name, rowIndex) {
            var re = new RegExp('^matrix_field\\[' + rowIndex + '\\]\\[([^\\]]+)\\]');
            var m = String(name || '').match(re);
            return m && m[1] ? m[1] : '';
        }
        function getTinyEditorForField(field) {
            if (!field || !field.id) return null;
            if (window.tinyMCE && typeof window.tinyMCE.get === 'function') {
                return window.tinyMCE.get(field.id);
            }
            if (window.tinymce && typeof window.tinymce.get === 'function') {
                return window.tinymce.get(field.id);
            }
            return null;
        }
        function setFieldValue(field, value) {
            var editor = getTinyEditorForField(field);
            if (editor && typeof editor.setContent === 'function') {
                editor.setContent(String(value || ''));
                if (typeof editor.save === 'function') editor.save();
            } else {
                field.value = String(value || '');
            }
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function getFieldCurrentValue(field) {
            if (!field) return '';
            var editor = getTinyEditorForField(field);
            if (editor && typeof editor.getContent === 'function') {
                return String(editor.getContent() || '');
            }
            return String(field.value || '');
        }
        function renderAiPreview(previewEl, text) {
            if (!previewEl) return;
            if (!text) {
                previewEl.hidden = true;
                previewEl.innerHTML = '';
                return;
            }
            previewEl.textContent = String(text);
            previewEl.hidden = false;
        }
        function normalizeAiSuggestionText(raw, fieldKey) {
            var text = String(raw || '').trim();
            if (!text) return '';
            var stripFence = function(s) {
                var v = String(s || '').trim();
                var full = v.match(/```(?:[a-zA-Z0-9_-]+)?\s*([\s\S]*?)\s*```/);
                if (full && full[1]) return String(full[1]).trim();
                if (v.indexOf('```') === 0) {
                    v = v.replace(/^```(?:[a-zA-Z0-9_-]+)?\s*/i, '');
                    v = v.replace(/```$/i, '');
                }
                return v.trim();
            };
            var fromJsonObject = function(s) {
                try {
                    var obj = JSON.parse(String(s || ''));
                    if (obj && obj.fields && typeof obj.fields === 'object') {
                        if (fieldKey && Object.prototype.hasOwnProperty.call(obj.fields, fieldKey)) {
                            return String(obj.fields[fieldKey] || '').trim();
                        }
                        var keys = Object.keys(obj.fields);
                        if (keys.length) return String(obj.fields[keys[0]] || '').trim();
                    }
                } catch (e) {}
                return '';
            };
            var fromJsonish = function(s) {
                if (!fieldKey) return '';
                var escKey = String(fieldKey).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                var re = new RegExp('"' + escKey + '"\\s*:\\s*"([\\\\s\\\\S]*?)"(?:\\s*[,}])?', 'm');
                var m = String(s || '').match(re);
                if (!m || !m[1]) return '';
                var rawValue = String(m[1]);
                try {
                    var decoded = JSON.parse('"' + rawValue + '"');
                    return typeof decoded === 'string' ? decoded.trim() : String(decoded || '').trim();
                } catch (e) {
                    return rawValue.replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\r/g, '\r').replace(/\\t/g, '\t').trim();
                }
            };
            var isLikelyJsonBlob = function(s) {
                var v = String(s || '').trim();
                return v.indexOf('{"fields"') === 0 || v.indexOf('{') === 0 || v.indexOf('"fields"') !== -1 || v.indexOf('```json') === 0;
            };

            var unfenced = stripFence(text);
            var extracted = fromJsonObject(unfenced);
            if (extracted) return extracted;
            extracted = fromJsonish(unfenced);
            if (extracted) return extracted;
            if (isLikelyJsonBlob(unfenced)) return '';
            if (/^\s*```/.test(text)) return '';
            return unfenced;
        }
        function initAiFieldAssist() {
            if (!aiEnabled || !aiUrl || !aiNonce || !form) return;
            var toneSelect = document.getElementById('matrix-ai-global-tone');
            wrap.querySelectorAll('.matrix-ai-field-open').forEach(function(openBtn) {
                var panelId = openBtn.getAttribute('data-ai-panel-id') || '';
                var panel = panelId ? document.getElementById(panelId) : null;
                if (!panel) return;
                var generateBtn = panel.querySelector('.matrix-ai-field-generate');
                var retryBtn = panel.querySelector('.matrix-ai-field-retry');
                var acceptBtn = panel.querySelector('.matrix-ai-field-accept');
                var rejectBtn = panel.querySelector('.matrix-ai-field-reject');
                var instructionsEl = panel.querySelector('.matrix-ai-field-instructions');
                var bulletsEl = panel.querySelector('.matrix-ai-field-bullets');
                var feedbackWrapEl = panel.querySelector('.matrix-ai-feedback-wrap');
                var feedbackEl = panel.querySelector('.matrix-ai-field-feedback');
                var statusEl = panel.querySelector('.matrix-ai-field-status');
                var previewEl = panel.querySelector('.matrix-ai-field-preview');
                if (!generateBtn || !statusEl || !previewEl || !instructionsEl || !bulletsEl) return;
                var suggestion = '';
                var inFlight = false;
                var hasPendingAiDraft = false;
                var originalValueBeforeAi = '';
                panel.classList.remove('is-open');
                panel.setAttribute('aria-busy', 'false');
                var setPanelOpen = function(open) {
                    panel.classList.toggle('is-open', !!open);
                    openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                openBtn.addEventListener('click', function() {
                    setPanelOpen(!panel.classList.contains('is-open'));
                });
                panel.addEventListener('keydown', function(evt) {
                    if (evt.key !== 'Escape') return;
                    setPanelOpen(false);
                    openBtn.focus();
                });
                var setActionsVisible = function(show) {
                    if (retryBtn) retryBtn.hidden = !show;
                    if (acceptBtn) acceptBtn.hidden = !show;
                    if (rejectBtn) rejectBtn.hidden = !show;
                };
                var setFeedbackVisible = function(show) {
                    if (!feedbackWrapEl) return;
                    feedbackWrapEl.hidden = !show;
                };
                var focusEditedField = function(field) {
                    if (!field) return;
                    var editor = getTinyEditorForField(field);
                    if (editor && typeof editor.focus === 'function') {
                        editor.focus();
                        return;
                    }
                    if (typeof field.focus === 'function') {
                        field.focus();
                    }
                };
                var runGenerate = function() {
                    if (inFlight) return;
                    var token = tokenInput ? String(tokenInput.value || '') : '';
                    if (!token) {
                        statusEl.textContent = 'Missing form token.';
                        return;
                    }
                    var block = panel.closest('.block');
                    if (!block) {
                        statusEl.textContent = 'Could not locate block context.';
                        return;
                    }
                    var rowIndex = parseInt(panel.getAttribute('data-row-index') || '-1', 10);
                    if (!Number.isFinite(rowIndex) || rowIndex < 0) {
                        statusEl.textContent = 'Invalid row context.';
                        return;
                    }
                    var postIdEl = block.querySelector('input[name="matrix_post_id[]"]');
                    var sourceEl = block.querySelector('input[name="matrix_block_source[]"]');
                    var blockIndexEl = block.querySelector('input[name="matrix_block_index[]"]');
                    var blockTypeEl = block.querySelector('input[name="matrix_block_type[]"]');
                    var targetId = panel.getAttribute('data-field-target-id') || '';
                    var field = targetId ? document.getElementById(targetId) : null;
                    if (!field) {
                        statusEl.textContent = 'Could not find target field.';
                        return;
                    }
                    var fieldKey = panel.getAttribute('data-field-key') || getFieldKeyFromName(field.name || '', rowIndex);
                    var fieldLabel = panel.getAttribute('data-field-label') || fieldKey;
                    if (!fieldKey) {
                        statusEl.textContent = 'Could not determine field key.';
                        return;
                    }
                    var fieldValue = getFieldCurrentValue(field);
                    var tone = toneSelect ? String(toneSelect.value || '').trim() : '';
                    var instructionText = String(instructionsEl.value || '').trim();
                    var feedbackText = feedbackEl ? String(feedbackEl.value || '').trim() : '';
                    var instructionParts = [];
                    if (tone) instructionParts.push('Global tone: ' + tone);
                    if (instructionText) instructionParts.push(instructionText);
                    if (feedbackText) instructionParts.push('Revision feedback from rejected draft:\n' + feedbackText);
                    var mergedInstructions = instructionParts.join('\n\n');
                    inFlight = true;
                    panel.setAttribute('aria-busy', 'true');
                    generateBtn.disabled = true;
                    if (retryBtn) retryBtn.disabled = true;
                    statusEl.textContent = 'Generating...';
                    var body = new FormData();
                    body.set('action', 'matrix_export_ai_generate_block');
                    body.set('matrix_ai_nonce', aiNonce);
                    body.set('matrix_form_token', token);
                    body.set('post_id', postIdEl ? String(postIdEl.value || '0') : '0');
                    body.set('block_source', sourceEl ? String(sourceEl.value || '') : '');
                    body.set('block_index', blockIndexEl ? String(blockIndexEl.value || '-1') : '-1');
                    body.set('block_type', blockTypeEl ? String(blockTypeEl.value || '') : '');
                    body.set('row_index', String(rowIndex));
                    body.set('instructions', mergedInstructions);
                    body.set('bullets', String(bulletsEl.value || ''));
                    body.set('fields_json', JSON.stringify([{ key: fieldKey, label: fieldLabel, value: fieldValue }]));
                    fetch(aiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function(r) { return r.json(); })
                        .then(function(json) {
                            if (!json || !json.success || !json.data || !json.data.suggestions) {
                                var msg = json && json.data && json.data.message ? String(json.data.message) : 'AI request failed.';
                                statusEl.textContent = msg;
                                setActionsVisible(false);
                                renderAiPreview(previewEl, '');
                                suggestion = '';
                                return;
                            }
                            var suggestions = json.data.suggestions || {};
                            suggestion = suggestions[fieldKey] ? String(suggestions[fieldKey]) : '';
                            if (!suggestion) {
                                var keys = Object.keys(suggestions);
                                suggestion = keys.length ? String(suggestions[keys[0]] || '') : '';
                            }
                            suggestion = normalizeAiSuggestionText(suggestion, fieldKey);
                            if (!suggestion) {
                                statusEl.textContent = 'AI returned structured output that could not be parsed cleanly. Please try again.';
                                setActionsVisible(false);
                                renderAiPreview(previewEl, '');
                                return;
                            }
                            if (!hasPendingAiDraft) {
                                originalValueBeforeAi = getFieldCurrentValue(field);
                            }
                            setFieldValue(field, suggestion);
                            hasPendingAiDraft = true;
                            renderAiPreview(previewEl, '');
                            focusEditedField(field);
                            setFeedbackVisible(false);
                            statusEl.textContent = 'Draft applied directly to the field. Accept to keep it, or Reject to restore previous text.';
                            setActionsVisible(true);
                        })
                        .catch(function() {
                            statusEl.textContent = 'AI request failed. Please try again.';
                            setActionsVisible(false);
                            renderAiPreview(previewEl, '');
                            suggestion = '';
                        })
                        .finally(function() {
                            inFlight = false;
                            panel.setAttribute('aria-busy', 'false');
                            generateBtn.disabled = false;
                            if (retryBtn) retryBtn.disabled = false;
                        });
                };
                generateBtn.addEventListener('click', runGenerate);
                if (retryBtn) retryBtn.addEventListener('click', runGenerate);
                if (acceptBtn) {
                    acceptBtn.addEventListener('click', function() {
                        if (!hasPendingAiDraft) {
                            statusEl.textContent = 'No pending AI draft to accept.';
                            return;
                        }
                        hasPendingAiDraft = false;
                        originalValueBeforeAi = '';
                        if (feedbackEl) feedbackEl.value = '';
                        setFeedbackVisible(false);
                        statusEl.textContent = 'AI draft accepted.';
                    });
                }
                if (rejectBtn) {
                    rejectBtn.addEventListener('click', function() {
                        var targetId = panel.getAttribute('data-field-target-id') || '';
                        var field = targetId ? document.getElementById(targetId) : null;
                        if (field && hasPendingAiDraft) {
                            setFieldValue(field, originalValueBeforeAi);
                        }
                        suggestion = '';
                        hasPendingAiDraft = false;
                        originalValueBeforeAi = '';
                        renderAiPreview(previewEl, '');
                        if (retryBtn) retryBtn.hidden = false;
                        if (acceptBtn) acceptBtn.hidden = true;
                        if (rejectBtn) rejectBtn.hidden = true;
                        setFeedbackVisible(true);
                        statusEl.textContent = 'Draft rejected and previous value restored. Add a rejection reason below, then click Try again.';
                        if (feedbackEl && typeof feedbackEl.focus === 'function') {
                            feedbackEl.focus();
                        }
                    });
                }
            });
        }
        function setDraftStatus(text) {
            if (draftSaveStatusEl && text) draftSaveStatusEl.textContent = text;
        }
        function clearValidation() {
            if (formErrorsWrap) formErrorsWrap.classList.remove('matrix-visible');
            if (formErrorsList) formErrorsList.innerHTML = '';
            if (!form) return;
            form.querySelectorAll('.matrix-field-invalid').forEach(function(el) { el.classList.remove('matrix-field-invalid'); });
            form.querySelectorAll('.matrix-field-error').forEach(function(el) { el.textContent = ''; el.classList.remove('matrix-visible'); });
        }
        function addError(field, msg, summary) {
            field.classList.add('matrix-field-invalid');
            var err = document.getElementById((field.id || '') + '-error');
            if (err) { err.textContent = msg; err.classList.add('matrix-visible'); }
            if (formErrorsList) { var li = document.createElement('li'); li.textContent = summary || msg; formErrorsList.appendChild(li); }
        }
        function validateForm() {
            clearValidation();
            if (!form) return true;
            var invalid = false;
            form.querySelectorAll('[data-matrix-required="1"]').forEach(function(field) {
                // Avoid blocking submit for untouched legacy content.
                if (!isFieldChanged(field)) return;
                if (String(field.value || '').trim() === '') {
                    invalid = true;
                    addError(field, 'This field is required.', (field.getAttribute('data-matrix-label') || 'Field') + ' is required.');
                }
            });
            form.querySelectorAll('[data-matrix-validate="url"]').forEach(function(field) {
                // Only validate URLs the user has edited in this session.
                if (!isFieldChanged(field)) return;
                var v = String(field.value || '').trim();
                if (!v) return;
                if (!/^(https?:\/\/|\/|#|mailto:|tel:)/i.test(v)) {
                    invalid = true;
                    addError(field, 'Please enter a valid URL (https://, /relative-path, #anchor, mailto:, or tel:).', (field.getAttribute('data-matrix-label') || 'URL') + ' has an invalid URL format.');
                }
            });
            if (invalid && formErrorsWrap) {
                formErrorsWrap.classList.add('matrix-visible');
                formErrorsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return !invalid;
        }
        function activateTab(panelId) {
            tabs.forEach(function(b) {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            panels.forEach(function(p) { p.classList.remove('active'); });
            var tab = wrap.querySelector('.tab-btn[data-panel="' + panelId + '"]');
            if (tab) { tab.classList.add('active'); tab.setAttribute('aria-selected', 'true'); }
            var panel = document.getElementById(panelId);
            if (panel) panel.classList.add('active');
            if (pageSelect && panelId) {
                pageSelect.value = panelId;
            }
            if (activePageInput) {
                var pid = String(panelId || '').replace('panel-', '');
                if (/^\d+$/.test(pid)) activePageInput.value = pid;
            }
            wrap.querySelectorAll('.matrix-title-view-panel').forEach(function(b) {
                b.classList.toggle('matrix-title-view-active', b.getAttribute('data-panel') === panelId);
            });
            updatePageStatusBadge();
            syncTopStatusControlsFromSelectedPage();
            updateBottomPanelNav(panelId);
        }
        var statusLabels = { todo: 'To Do', inprogress: 'In progress', done: 'Done', delete: 'Delete' };
        function styleStatusOption(opt) {
            if (!opt) return;
            var status = opt.getAttribute('data-status') || 'todo';
            var bg = '#f8d7da';
            var fg = '#721c24';
            if (status === 'inprogress') {
                bg = '#cce5ff';
                fg = '#004085';
            } else if (status === 'done') {
                bg = '#d4edda';
                fg = '#155724';
            } else if (status === 'delete') {
                bg = '#fff3cd';
                fg = '#856404';
            }
            // Works in Chromium/Safari; other browsers will gracefully ignore.
            opt.style.backgroundColor = bg;
            opt.style.color = fg;
        }
        function styleAllStatusOptions() {
            if (!pageSelect) return;
            Array.prototype.forEach.call(pageSelect.options, function(opt) {
                styleStatusOption(opt);
            });
        }
        function updatePageStatusBadge() {
            if (!pageSelect) return;
            var opt = pageSelect.options[pageSelect.selectedIndex];
            if (!opt) return;
            var status = opt.getAttribute('data-status') || 'todo';
            if (pageStatusBadge) {
                pageStatusBadge.className = 'matrix-page-status-badge status-' + status;
                pageStatusBadge.textContent = statusLabels[status] || status;
            }
            if (pageSelectWrap) {
                pageSelectWrap.classList.remove('status-todo', 'status-inprogress', 'status-done', 'status-delete');
                pageSelectWrap.classList.add('status-' + status);
            }
        }
        function setOptionStatus(panelId, status) {
            if (!pageSelect) return;
            var opt = pageSelect.querySelector('option[value="' + panelId + '"]');
            if (!opt) return;
            opt.setAttribute('data-status', status);
            var title = opt.getAttribute('data-title') || opt.textContent.split(' — ')[0] || '';
            opt.textContent = title + ' — ' + (statusLabels[status] || status);
            styleStatusOption(opt);
            updatePageStatusBadge();
        }
        function setDoneByText(email) {
            if (!topStatusDoneBy) return;
            if (email) {
                topStatusDoneBy.textContent = 'Marked done by: ' + email;
                topStatusDoneBy.style.display = '';
            } else {
                topStatusDoneBy.textContent = '';
                topStatusDoneBy.style.display = 'none';
            }
        }
        function setHiddenPageStatus(panelId, status) {
            var pid = String(panelId || '').replace('panel-', '');
            if (!/^\d+$/.test(pid) || !form) return;
            var hidden = form.querySelector('input[data-matrix-page-status-hidden="' + pid + '"]');
            if (hidden) hidden.value = status;
        }
        function syncTopStatusControlsFromSelectedPage() {
            if (!pageSelect) return;
            var panelId = pageSelect.value || '';
            var opt = pageSelect.options[pageSelect.selectedIndex];
            var status = (opt && opt.getAttribute('data-status')) || 'todo';
            wrap.querySelectorAll('input[name="matrix_page_status_current"]').forEach(function(r) {
                r.checked = (r.value === status);
            });
            var doneBy = opt ? (opt.getAttribute('data-done-by') || '') : '';
            if (status === 'done') setDoneByText(doneBy); else setDoneByText('');
        }
        function updateBottomPanelNav(panelId) {
            var prevBtn = document.getElementById('matrix-bottom-prev');
            var nextBtn = document.getElementById('matrix-bottom-next');
            if (!prevBtn || !nextBtn || !pageSelect) return;
            var idx = pageSelect.selectedIndex;
            if (idx < 0) idx = 0;
            var prevOpt = idx > 0 ? pageSelect.options[idx - 1] : null;
            var nextOpt = idx < (pageSelect.options.length - 1) ? pageSelect.options[idx + 1] : null;
            var prevPanel = prevOpt ? prevOpt.value : '';
            var nextPanel = nextOpt ? nextOpt.value : '';
            prevBtn.setAttribute('data-target-panel', prevPanel);
            nextBtn.setAttribute('data-target-panel', nextPanel);
            prevBtn.disabled = !prevPanel;
            nextBtn.disabled = !nextPanel;
        }
        tabs.forEach(function(btn) {
            btn.addEventListener('click', function() { activateTab(this.getAttribute('data-panel')); });
            btn.addEventListener('keydown', function(e) {
                if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) return;
                e.preventDefault();
                var buttons = Array.prototype.slice.call(tabs);
                var idx = buttons.indexOf(this);
                if (idx < 0) return;
                var next = idx;
                if (e.key === 'ArrowRight') next = (idx + 1) % buttons.length;
                if (e.key === 'ArrowLeft') next = (idx - 1 + buttons.length) % buttons.length;
                if (e.key === 'Home') next = 0;
                if (e.key === 'End') next = buttons.length - 1;
                buttons[next].focus();
                activateTab(buttons[next].getAttribute('data-panel'));
            });
        });
        if (pageSelect) {
            pageSelect.addEventListener('change', function() {
                activateTab(this.value);
            });
            styleAllStatusOptions();
            updatePageStatusBadge();
            syncTopStatusControlsFromSelectedPage();
            updateBottomPanelNav(pageSelect.value || '');
        }
        wrap.querySelectorAll('input[name="matrix_page_status_current"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var panelId = pageSelect && pageSelect.value ? pageSelect.value : '';
                var status = this.value;
                if (!panelId || !status) return;
                setOptionStatus(panelId, status);
                setHiddenPageStatus(panelId, status);
                if (status !== 'done') setDoneByText('');
                var saveUrl = form && form.getAttribute('data-matrix-save-status-url');
                var saveNonce = form && form.getAttribute('data-matrix-save-status-nonce');
                var tokenInput = form && form.querySelector('input[name="matrix_form_token"]');
                if (!saveUrl || !saveNonce || !tokenInput) return;
                var postId = String(panelId).replace('panel-', '');
                if (!/^\d+$/.test(postId)) return;
                var body = new FormData();
                body.append('action', 'matrix_export_save_page_status');
                body.append('matrix_save_status_nonce', saveNonce);
                body.append('matrix_form_token', tokenInput.value);
                body.append('matrix_post_id', postId);
                body.append('matrix_status', status);
                fetch(saveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.success && status === 'done' && res.data && res.data.done_by) {
                            if (pageSelect) {
                                var opt = pageSelect.querySelector('option[value="' + panelId + '"]');
                                if (opt) opt.setAttribute('data-done-by', res.data.done_by);
                            }
                            setDoneByText(res.data.done_by);
                        }
                    })
                    .catch(function() {});
            });
        });
        wrap.querySelectorAll('.matrix-panel-nav-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = this.getAttribute('data-target-panel');
                if (!panel) return;
                activateTab(panel);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
        wrap.querySelectorAll('.matrix-duplicate-page-btn[data-matrix-duplicate-post-id]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var postId = parseInt(this.getAttribute('data-matrix-duplicate-post-id') || '0', 10);
                if (!duplicateUrl || !duplicateNonce || !tokenInput || !postId) return;
                var originalText = this.textContent;
                this.disabled = true;
                this.textContent = 'Duplicating...';
                var body = new FormData();
                body.append('action', 'matrix_export_duplicate_page');
                body.append('matrix_duplicate_page_nonce', duplicateNonce);
                body.append('matrix_form_token', tokenInput.value || '');
                body.append('matrix_post_id', String(postId));
                fetch(duplicateUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success || !res.data || !res.data.form_url) {
                            throw new Error('Could not duplicate this page.');
                        }
                        window.open(res.data.form_url, '_blank', 'noopener');
                        btn.textContent = 'Duplicated';
                        setTimeout(function() {
                            btn.textContent = originalText;
                            btn.disabled = false;
                        }, 1800);
                    })
                    .catch(function() {
                        alert('Could not duplicate this page right now.');
                        btn.textContent = originalText;
                        btn.disabled = false;
                    });
            });
        });
        if (tabs.length > 0) {
            var qs = new URLSearchParams(window.location.search);
            var pageId = qs.get('matrix_page');
            if (pageId && /^\d+$/.test(pageId)) {
                var wanted = 'panel-' + pageId;
                if (wrap.querySelector('.tab-btn[data-panel="' + wanted + '"]')) activateTab(wanted);
            }
        }

        function updateCount(field) {
            if (!field || !field.id) return;
            var mode = field.getAttribute('data-matrix-count');
            if (!mode) return;
            var hint = document.getElementById(field.id + '-count');
            if (!hint) return;
            var max = parseInt(field.getAttribute('data-matrix-count-max'), 10) || 0;
            var text = String(field.value || '').trim();
            if (mode === 'words') {
                var words = text ? text.split(/\s+/).length : 0;
                hint.textContent = words + ' words' + (max ? ' (recommended max ' + max + ')' : '');
            } else {
                hint.textContent = text.length + ' characters' + (max ? ' (recommended max ' + max + ')' : '');
            }
        }
        if (form) {
            form.querySelectorAll('[data-matrix-count]').forEach(function(field) {
                updateCount(field);
                field.addEventListener('input', function() { updateCount(field); });
                field.addEventListener('change', function() { updateCount(field); });
            });
            form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(field) {
                var name = field.name || '';
                if (!name || name === 'matrix_form_token' || name === 'matrix_form_submit') return;
                var type = (field.type || '').toLowerCase();
                if (type === 'hidden' || type === 'submit' || type === 'button') return;
                field.setAttribute('data-matrix-origin', getFieldComparableValue(field));
            });
        }

        var maxImageBytes = 2097152;
        function setDims(container) {
            var img = container && container.querySelector('img[data-matrix-preview]');
            var target = container && container.parentElement ? container.parentElement.querySelector('[data-matrix-image-dimensions]') : null;
            if (!img || !target) return;
            var apply = function() { if (img.naturalWidth > 0 && img.naturalHeight > 0) target.textContent = 'Current: ' + img.naturalWidth + ' x ' + img.naturalHeight; };
            if (img.complete) apply(); else img.addEventListener('load', apply, { once: true });
        }
        wrap.querySelectorAll('.image-field .image-preview').forEach(setDims);
        wrap.querySelectorAll('.matrix-image-upload').forEach(function(input) {
            var m = parseInt(input.getAttribute('data-max-bytes'), 10);
            if (m) maxImageBytes = m;
            input.addEventListener('change', function() {
                var max = parseInt(this.getAttribute('data-max-bytes'), 10) || maxImageBytes;
                var file = this.files && this.files[0];
                if (!file) return;
                if (file.size > max) {
                    alert('This image is too large. Maximum size is ' + (max / 1024 / 1024).toFixed(1) + ' MB.');
                    this.value = '';
                    return;
                }
                var container = this.closest('.image-field').querySelector('.image-preview');
                var preview = container && container.querySelector('img[data-matrix-preview]');
                var noImage = container && container.querySelector('.no-image');
                var localUrl = URL.createObjectURL(file);
                if (preview) {
                    preview.src = localUrl;
                } else if (container) {
                    var img = document.createElement('img');
                    img.src = localUrl;
                    img.alt = '';
                    img.setAttribute('data-matrix-preview', '');
                    container.innerHTML = '';
                    container.appendChild(img);
                }
                if (noImage) noImage.style.display = 'none';
                setDims(container);
            });
        });

        function autosaveDraft() {
            if (!form || !autosaveUrl || !autosaveNonce || autosaveInFlight || isSubmitting) return;
            if (!isDirty()) return;
            autosaveInFlight = true;
            syncEditors();
            var body = new FormData(form);
            body.set('action', 'matrix_export_autosave_draft');
            body.set('matrix_autosave_nonce', autosaveNonce);
            body.set('matrix_submit_mode', 'later');
            body.set('matrix_is_autosave', '1');
            fetch(autosaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.success) {
                        markClean();
                        if (res.data && res.data.saved_at_text) {
                            setDraftStatus('Last saved at ' + res.data.saved_at_text + '.');
                        }
                    }
                    autosaveInFlight = false;
                })
                .catch(function() {
                    autosaveInFlight = false;
                });
        }
        if (autosaveUrl && autosaveNonce && form) {
            setInterval(autosaveDraft, autosaveIntervalMs > 0 ? autosaveIntervalMs : 75000);
        }
        initAiFieldAssist();

        window.addEventListener('beforeunload', function(e) {
            if (isSubmitting || !isDirty()) return;
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Leave anyway?';
            return e.returnValue;
        });

        if (form && submitModeInput) {
            form.querySelectorAll('button[type="submit"][data-submit-mode]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    lastSubmitButton = this;
                    submitModeInput.value = this.getAttribute('data-submit-mode') || 'publish';
                });
            });
            form.addEventListener('submit', function(e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }
                syncEditors();
                var activePanel = pageSelect && pageSelect.value ? pageSelect.value : '';
                if (!activePanel) {
                    var activeBtn = wrap.querySelector('.tab-btn.active');
                    activePanel = activeBtn ? (activeBtn.getAttribute('data-panel') || '') : '';
                }
                if (activePanel && activePageInput) {
                    var pid = activePanel.replace('panel-', '');
                    if (/^\d+$/.test(pid)) activePageInput.value = pid;
                }
                if (!validateForm()) {
                    e.preventDefault();
                    return false;
                }
                if (submitModeInput.value !== 'later') {
                    if (!publishReviewApproved) {
                        e.preventDefault();
                        var reviewItems = buildPublishReviewItems();
                        openPublishReview(reviewItems, function() {
                            publishReviewApproved = true;
                            if (lastSubmitButton && typeof lastSubmitButton.click === 'function') {
                                lastSubmitButton.click();
                            } else if (typeof form.requestSubmit === 'function') {
                                form.requestSubmit();
                            } else {
                                form.submit();
                            }
                        });
                        return false;
                    }
                    publishReviewApproved = false;
                }
                var max = maxImageBytes;
                var inputs = wrap.querySelectorAll('.matrix-image-upload');
                for (var i = 0; i < inputs.length; i++) {
                    var input = inputs[i];
                    var m = parseInt(input.getAttribute('data-max-bytes'), 10);
                    if (m) max = m;
                    var file = input.files && input.files[0];
                    if (file && file.size > max) {
                        e.preventDefault();
                        alert('"' + file.name + '" is too large. Maximum size is ' + (max / 1024 / 1024).toFixed(1) + ' MB.');
                        return false;
                    }
                }
                isSubmitting = true;
                showProgress(submitModeInput.value === 'later' ? 'later' : 'publish');
                form.querySelectorAll('button[type="submit"]').forEach(function(b) { b.disabled = true; });
                if (lastSubmitButton) lastSubmitButton.textContent = 'Saving...';
            });
            setTimeout(markClean, 250);
            setTimeout(markClean, 1200);
        }
    })();
    </script>
<?php if ($in_theme) : ?></div><?php endif; ?>
<?php if (!$in_theme) : ?></body></html><?php endif; ?>
