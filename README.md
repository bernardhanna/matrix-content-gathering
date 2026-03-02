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

## Developer notes

- Main plugin file: `matrix-content-export.php`
- Export logic: `includes/class-matrix-export.php`
- Import/save logic: `includes/class-matrix-import.php`
- Admin UI: `templates/admin-page.php`
- Client form template: `templates/export-client-form.php`
