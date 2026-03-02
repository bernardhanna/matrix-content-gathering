<?php
if (!defined('ABSPATH')) exit;
$headers = $data['headers'];
$rows = $data['rows'];
$meta_keys = array_diff($headers, ['post_id', 'post_title', 'post_slug', 'post_type', 'block_source', 'block_index', 'block_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content brief – <?php echo esc_attr(date('Y-m-d')); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
        h1 { font-size: 1.5rem; border-bottom: 2px solid #333; padding-bottom: 0.5rem; }
        h2 { font-size: 1.2rem; margin-top: 2rem; color: #333; }
        .page-block { margin: 1.5rem 0; padding: 1rem; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
        .block-meta { font-size: 0.85rem; color: #666; margin-bottom: 0.75rem; }
        .field { margin: 0.75rem 0; }
        .field-label { font-weight: 600; font-size: 0.9rem; color: #444; }
        .field-value { margin-top: 0.25rem; padding: 0.5rem; background: #fff; border: 1px solid #eee; min-height: 1.5em; }
        .instructions { background: #e8f4fc; padding: 1rem; border-radius: 6px; margin-bottom: 2rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>Content brief – fill in your copy</h1>
    <div class="instructions">
        <strong>Instructions:</strong> Edit the content in the boxes below. Keep the structure; only change the text and links where needed. 
        You can return this as a Word/Google Doc or use the CSV export for bulk updates.
    </div>

    <?php
    $current_post = null;
    foreach ($rows as $row) :
        $post_id = $row['post_id'];
        $new_page = ($current_post !== $post_id);
        $current_post = $post_id;
        $block_type = isset($row['block_type']) ? $row['block_type'] : '';
        $block_index = isset($row['block_index']) ? $row['block_index'] : 0;
        $source = isset($row['block_source']) ? $row['block_source'] : '';
        if ($new_page) :
            ?>
            <h2><?php echo esc_html($row['post_title']); ?> (<?php echo esc_html($row['post_type']); ?>)</h2>
        <?php endif; ?>

        <div class="page-block">
            <div class="block-meta">Block <?php echo (int) $block_index + 1; ?> – <?php echo esc_html($block_type); ?> (<?php echo esc_html($source); ?>)</div>
            <?php foreach ($meta_keys as $key) :
                $val = isset($row[$key]) ? $row[$key] : '';
                if ($val === '') continue;
                $display = $val;
                if (strpos($val, 'LINK:') === 0) {
                    $parts = explode("\t", substr($val, 5), 2);
                    $display = 'URL: ' . (isset($parts[0]) ? $parts[0] : '') . ' | Button text: ' . (isset($parts[1]) ? $parts[1] : '');
                } elseif (strpos($val, 'JSON:') === 0) {
                    continue; // skip complex fields in doc for clarity
                }
                ?>
                <div class="field">
                    <div class="field-label"><?php echo esc_html($key); ?></div>
                    <div class="field-value"><?php echo nl2br(esc_html($display)); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>
