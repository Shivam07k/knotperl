# 15. Image upload and replacement

The browser admin's **Change picture** control uploads images directly through the
worker. The publishing dashboard's **Images** screen provides upload, search, replace,
URL copy and deletion queue for advanced use.

Upload:

1. Choose a folder such as `products/pr001` or `categories/c001`.
2. Select a JPEG, PNG, WebP or GIF up to 8 MB and add alt text.
3. The engine validates MIME and size, hashes the file, generates WebP variants at
   480/960/1440 px plus a 256 px thumbnail, and writes a metadata sidecar.

Duplicate detection uses sha256: if the same hash already exists, the existing object is
returned instead of uploading again.

Replacement is deliberately safe:

1. Upload the new image first.
2. Verify the upload succeeded.
3. If you supply a content section and cell, the engine updates that cell to the new key.
4. Publish the site.
5. Only after that does the old key become eligible for deletion, and only after the
   configured `IMAGE_RETENTION_DAYS` (default 30). Run `php artisan cms:cleanup-images`
   or the dashboard's **Process expired deletions**.

An image missing from an incomplete content file is **never** deleted. Deletion is only
ever driven by the explicit queue. The content file stores keys, never binaries.
