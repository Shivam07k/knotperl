# 16. Backup and rollback

Every successful publish moves the previous `storage/app/generated/current` directory into
`storage/app/backups/{publish-id}`. `PUBLISH_KEEP_BACKUPS` controls how many are kept
(default 5). The manifest at `storage/app/generated/manifest.json` records the active ID,
checksum, deployment result and validation counts. Publishing history is appended to
`storage/app/publishing-logs/publishing.jsonl`.

Rollback:

```bash
php artisan cms:rollback              # most recent snapshot
php artisan cms:rollback live-20260101-120000-abc123
```

Or use the dashboard's **Roll back to previous deployment** button. Rollback swaps the
directories, rebuilds the artifact and redeploys it. A failed redeploy is reported; the
restored local directory is preserved.

Content backups come from **Download backup** in the browser admin. Use it before
large structural changes and keep the file outside Cloudflare. R2 object versions are
not enabled by default; enable bucket versioning in Cloudflare if you need it.

Failed publishing recovery:

- The error is written to `publishing.jsonl` with status `failed`.
- `current` is never replaced by an incomplete build; staging is deleted.
- Fix the reported validation or deployment error and publish again.
