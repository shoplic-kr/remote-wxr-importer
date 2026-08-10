=== Remote WXR Importer ===
Contributors: shoplic
Tags: importer, wxr, rest-api, migration
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports WXR XML files through authenticated REST requests and assigns all imported content to a specified user.

== Description ==

Remote WXR Importer provides the `POST /wp-json/rwi/v1/import` endpoint. It reuses the WXR parser and import behavior from the official WordPress Importer plugin and adds the following features:

* Assigns all imported posts and attachments to the user specified by `author_id`.
* Downloads remote attachments and preserves content URL and featured-image relationships.
* Returns created, skipped, and individually failed items as JSON.
* Validates XML, MIME type, and file size, then removes temporary files when processing ends.

This plugin is intended for single-site installations. It does not use a background queue, so splitting large exports into WXR files containing approximately 10 posts each is recommended.

== Installation ==

1. Install and activate the official `wordpress-importer` plugin.
2. Install and activate Remote WXR Importer.
3. Create an Application Password in the profile of an administrator account.
4. Send REST requests over HTTPS only.

== Usage ==

Send a `multipart/form-data` request with these fields:

* `file` (required): One WXR 1.0 through 1.2 file with a `.xml` extension and a `text/xml` or `application/xml` MIME type.
* `author_id` (required): The ID of an existing user who will own the imported content.
* `fetch_attachments` (optional): Defaults to `true`. Set it to `false` to skip attachment items.

Example:

`curl -X POST "https://example.com/wp-json/rwi/v1/import" -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" -F "file=@migration/export-1.xml;type=application/xml" -F "author_id=3"`

The authenticated user must have both the `import` and `edit_others_posts` capabilities. Cookie and nonce authentication are not accepted.

== File Size and Execution Time ==

The default WXR upload limit is 50 MB and can be changed with the `rwi_max_upload_size` filter. The effective limit cannot exceed the WordPress and PHP `upload_max_filesize` and `post_max_size` limits.

Remote attachments are limited to 30 MB per file by default. Use the WordPress Importer `import_attachment_size_limit` filter to change this limit.

The plugin attempts to remove the execution time limit, but long requests may still be terminated by the server. Split large exports into files containing approximately 10 posts and import them sequentially.

== Privacy and Uninstallation ==

The plugin creates no options, history records, or log files. Import summaries are written to the PHP `error_log`, or `WP_DEBUG_LOG` when configured by WordPress. Uninstalling the plugin does not delete imported content.

== Changelog ==

= 1.0.0 =
* Initial release.
