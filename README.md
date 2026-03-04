# Matrix Content Export / Import

This plugin supports two main workflows:

- **Client editing form (recommended)**: generate a secure link that opens an on-site editing form (no WordPress admin UI shown). Clients can update content and submit changes back to the site.
- **Import/export**: export content to CSV for round-trip updates (and import it back later).

It is designed for sites using ACF Flexible Content blocks (e.g. matrix-starter), but works for any post types using the expected ACF fields.

## Features

- **Client editing form** at `/<content-editing>/` (tokenized link)
- **Editable core fields** (when present)
  - Page/post title (renames the title **without changing the slug**)
  - Post content (editor)
  - Excerpt (when supported)
  - Featured image
- **Editable taxonomies** (categories/tags/custom taxonomies): rendered as checkbox groups
- **Optional “Duplicate page”** button inside the client form
  - duplicates a page/post and opens the **editable form** for the new copy
  - can optionally notify admins by email
- **Optional email notifications**
  - on client save (changed fields summary)
  - on client duplication
  - separate moderation notification email for approval-required submissions
- **Optional per-link moderation**
  - keep default publish-on-submit behavior
  - or require admin approval before client submissions are published
- **Field visibility settings**: hide specific fields from the client editing form (filterable by selected pages/post types + searchable)
- **Import CSV** to update ACF flexible content blocks

## Requirements

- WordPress 5.8+
- PHP 7.4+
- **Advanced Custom Fields Pro**
- Theme/content model using these ACF flexible content fields (default):
  - `flexible_content_blocks`
  - `hero_content_blocks`

## Installation

1. Copy this folder into `wp-content/plugins/matrix-content-export/`.
2. Activate **Matrix Content Export / Import** in **Plugins**.
3. Ensure ACF Pro is active and your post types contain the relevant ACF fields.

## Admin UI (Tools → Content Gathering)

Go to **Tools → Content Gathering** (the plugin admin page).

### Choose what to include

- **Post types**: toggles which post type groups are visible in the page/post selector UI.
- **Pages & posts**: select the specific items that will appear as tabs inside the client editing form.

### Hide fields on the client form

Use **Hide fields on the client form** to control what clients can edit.

- Tick a checkbox to **hide** that field from the client editing form.
- Unticked fields remain visible/editable.
- The field list is:
  - searchable
  - filtered by selected **post types**
  - filtered by selected **pages/posts** (e.g. select the Homepage and the field list narrows to Homepage-only fields)

### Notification emails (optional)

- **Admin duplicate notification email (optional)**: gets an email whenever a client duplicates a page from the editing form.
- **Client update notification email (optional)**: gets an email whenever a client saves the form, containing a before → after summary of changed fields.

## Client editing form

### Creating a link

1. In **Tools → Content Gathering**, select the pages/posts.
2. Click **Get client link (form on site)**.
3. Send the generated URL to the client.

The URL contains a token (`matrix_token`) that controls which pages/posts appear in the form.
If needed for sensitive clients, enable **Require approval before publishing** when generating the link. This is off by default.
When moderation is enabled, submissions appear in **Pending reviews** in the admin page, where you can approve/publish or reject with an optional review note.

### Access control

The client form is **restricted to logged-in users** and a safe set of roles/capabilities by default.

- Default allowed roles: `administrator`, `site_editor`
- Capability fallback: `manage_options`, `edit_theme_options`

You can customize via filters:

- `matrix_export_client_form_allowed_roles`
- `matrix_export_client_form_allowed_capabilities`

### Form behavior

- Each selected page/post is shown as a tab (and dropdown).
- Clients can:
  - edit text, links, images/video uploads (where enabled)
  - update taxonomies (checkboxes)
  - rename page/post title (slug is not changed)
  - optionally duplicate the page (if using the “Duplicate page” button)
- Submit actions:
  - **Update site content**: writes changes to ACF/core fields
  - **Save for later**: stores a draft state for that token without publishing updates

## Duplicate page behavior

If you use the **Duplicate page** button in the client form:

- The new copy is created (published when the duplicating user can publish; otherwise draft).
- The duplicate is added into the same tokenized form, and the new browser tab opens directly to its editable form.
- Optional email notification is sent to the configured admin address.

## Import / Export (CSV)

### Import (CSV)

Upload a CSV exported from this plugin to update content.

- Import updates ACF flexible content and hero blocks for each post present in the CSV.
- Keep these columns stable for round-trip import:
  - `post_id`
  - `block_source`
  - `block_index`
  - `block_type`

### CSV format

- Base columns:
  - `post_id`, `post_title`, `post_slug`, `post_type`
  - `block_source`, `block_index`, `block_type`
- Links: `LINK:<url><TAB><title>`
- Images: `IMAGE:<attachment_id><TAB><url>` (url is optional)
- Complex values: `JSON:<json>`

## Troubleshooting

### “I can’t access the content-editing link”

- Confirm you are logged in with a role/capability allowed by the plugin.
- Confirm the token is valid and not expired (if you set an expiry).

### Fields appear that you don’t want clients editing

Use **Hide fields on the client form** in the admin page. The client form will respect those settings automatically.

### “Node.js is not available to PHP runtime” / Screenshot generation unavailable

Section screenshot generation (block previews in the workbook) requires **Node.js**, the **Playwright** npm package, and the **Chromium** browser on the server. You can:

- **Ignore it** – screenshot generation is optional; the rest of the plugin works without it.
- **Enable it on the server** (SSH as root or the web server user):

If you see this notice in the admin UI:

> Screenshot generation is not available in this environment. Node.js is not available to PHP runtime.
>
> Section previews are optional. If Node.js is installed on the server but not in the web server's PATH, add to wp-config.php:
> `define('MATRIX_EXPORT_NODE_BINARY', '/path/to/node');`

follow the steps below.

Example `wp-config.php` overrides:

```php
define('MATRIX_EXPORT_NODE_BINARY', '/opt/node-20/bin/node');
define('MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH', '/root/.cache/ms-playwright');

// Optional quick debug line to verify constants are loaded.
file_put_contents(
    __DIR__ . '/matrix-export-debug.log',
    'NODE: ' . (defined('MATRIX_EXPORT_NODE_BINARY') ? MATRIX_EXPORT_NODE_BINARY : 'not set') . "\n",
    FILE_APPEND
);
```

  1. **Set the Node path** in `wp-config.php` if the web server user doesn’t have `node` in PATH:
     ```php
     define('MATRIX_EXPORT_NODE_BINARY', '/usr/bin/node');
     ```
     Find the path with `which node` over SSH.

  2. **Install dependencies and Chromium** in the plugin directory, **as the user that runs PHP** (e.g. `www-data`, `apache`, or the site’s system user), so that `node_modules` and the browser cache are readable by the web server:
     ```bash
     cd /var/www/vhosts/…/wp-content/plugins/matrix-content-export
     npm install
     npx playwright install chromium
     ```
     If you’re SSH’d as root, run as the web user instead:
     ```bash
     sudo -u www-data bash -c 'cd /var/www/vhosts/…/wp-content/plugins/matrix-content-export && npm install && npx playwright install chromium'
     ```
     Replace `www-data` and the path with your server’s web user and plugin path.

  3. **Optional:** If the browser cache must live in a specific directory (e.g. a shared path), install Chromium there and tell the plugin in `wp-config.php`:
     ```php
     define('MATRIX_EXPORT_PLAYWRIGHT_BROWSERS_PATH', '/var/www/playwright-browsers');
     ```
     Then run `npx playwright install chromium` with `PLAYWRIGHT_BROWSERS_PATH=/var/www/playwright-browsers` (and ensure the web user can read that directory).

### Screenshots show the wrong section / "View section" link doesn't scroll

Section previews and **View section** links resolve blocks by anchor (`layout-name-index`, e.g. `callout-2`).

Recommended theme markup:

- Keep your normal section `id` (deterministic or random).
- Add a stable `data-matrix-block` attribute using layout + ACF row index:
  - `data-matrix-block="<?php echo esc_attr(str_replace('_', '-', get_row_layout()) . '-' . get_row_index()); ?>"`

Example:

```php
<section
  id="<?php echo esc_attr($section_id); ?>"
  data-matrix-block="<?php echo esc_attr(str_replace('_', '-', get_row_layout()) . '-' . get_row_index()); ?>"
>
```

Why this works:

- Screenshot capture now checks `data-matrix-block` first.
- Front-end hash resolving falls back to `data-matrix-block` when no matching `id` exists.

So `/#callout-2` still scrolls correctly even if your actual section `id` is random like `our-people-8109`.

**Filter:** Change the anchor with the `matrix_export_block_anchor_id` filter if you need a different scheme.

## Developer notes

- Main plugin file: `matrix-content-export.php`
- Export logic: `includes/class-matrix-export.php`
- Import/save logic: `includes/class-matrix-import.php`
- Admin UI: `templates/admin-page.php`
- Client form template: `templates/export-client-form.php`
