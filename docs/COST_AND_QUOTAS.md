# 18. Cost and quota documentation

The architecture targets free tiers, but nothing here is guaranteed free forever.

| Service | Free allowance (typical) | Risk / overage |
|---|---|---|
| Cloudflare Pages Free | Limited builds/bandwidth per month | Exceeding limits can pause builds or require a paid plan; check current Cloudflare pricing |
| Cloudflare R2 | Free monthly storage/egress allowance within limits | Large image libraries or high traffic can exceed the free tier; egress is usually free but storage is not unlimited |
| GitHub Actions | Free minutes for public repos / allowance for private repos | Frequent builds can consume minutes; the workflow is a single short job |
| Laravel | Open source (free) | Hosting the publishing app yourself may cost money if you use a paid PHP host; local runs are free |

Cost controls in this project:

- Publishing is explicit, not per-visitor.
- Each publish reads one JSON file and runs one short build job.
- Images are WebP-compressed with three variants and a thumbnail; deduplication avoids
  duplicate storage.
- Browser/CDN caching uses a one-year immutable policy for hashed image assets.
- No paid database, queue, search or analytics service is required.

Before launch, verify the current limits on the providers' pricing pages. Do not promise
unlimited free usage to stakeholders.
