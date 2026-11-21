<?php

namespace App\Services\CMS;

use App\Services\Cloudflare\R2StorageService;

class ContentBuilderService
{
    public function __construct(private SpreadsheetSchemaService $schema) {}

    public function build(array $data): array
    {
        $settings = $this->settings($data['Settings'] ?? []);
        $pages = $this->bySlug($this->active($data['Pages'] ?? []), 'slug');
        if (isset($pages['home'])) {
            $pages[''] = $pages['home'];
            unset($pages['home']);
        }
        $categories = $this->active($data['Categories'] ?? []);
        $products = $this->active($data['Products'] ?? []);
        $alts = $this->mediaAlts();
        foreach ($categories as &$category) {
            $category['image_alt'] = $alts[trim((string) ($category['category_image'] ?? ''))] ?? '';
        }
        unset($category);
        $categoryById = $this->byId($categories, 'category_id');

        foreach ($products as &$product) {
            $product['category'] = $categoryById[$product['category_id']] ?? null;
            $product['image_url'] = R2StorageService::url($product['primary_image']);
            $product['image_alt'] = $alts[trim((string) $product['primary_image'])] ?? '';
            $galleryKeys = array_values(array_filter(array_map('trim', explode('|', (string) $product['gallery_images']))));
            $product['gallery_urls'] = array_map(
                static fn ($image): string => R2StorageService::url($image),
                $galleryKeys
            );
            $product['gallery_alts'] = array_map(static fn ($image): string => $alts[$image] ?? '', $galleryKeys);
            $product['price'] = (float) $product['sale_price'] > 0 ? (float) $product['sale_price'] : (float) $product['regular_price'];
        }
        unset($product);

        $sections = [];
        foreach ($this->active($data['Sections'] ?? []) as $section) {
            $slug = (string) $section['page_slug'];
            $slug = $slug === 'home' ? '' : $slug;
            $sections[$slug][] = $section;
        }
        foreach ($sections as &$list) {
            usort($list, static fn ($a, $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order']);
        }
        unset($list);

        return [
            'settings' => $settings,
            'pages' => $pages,
            'products' => $products,
            'featured_products' => array_values(array_filter($products, static fn ($p): bool => (bool) $p['featured'])),
            'categories' => $this->categoryTree($categories),
            'category_by_slug' => $this->bySlug($categories, 'slug'),
            'category_by_id' => $categoryById,
            'banners' => $this->activeBanners($data['Banners'] ?? []),
            'sections' => $sections,
            'navigation' => $this->navigation($data['Navigation'] ?? []),
            'testimonials' => $this->active($data['Testimonials'] ?? []),
            'services' => $this->active($data['Services'] ?? []),
            'seo' => $this->seo($data['SEO'] ?? []),
            'contact' => $this->active($data['Contact'] ?? []),
            'redirects' => $this->active($data['Redirects'] ?? []),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function pagePath(array $page): string
    {
        $template = (string) $page['template'];
        $slug = trim((string) $page['slug'], '/');

        return match ($template) {
            'home' => 'index.html',
            'products' => 'products/index.html',
            default => ($slug === '' ? 'index.html' : $slug.'/index.html'),
        };
    }

    public function url(string $path): string
    {
        return $path === 'index.html' ? '/' : '/'.preg_replace('#/index\.html$#', '/', $path);
    }

    public function seoFor(array $site, string $slug, array $fallback = []): array
    {
        $key = $slug === 'home' ? '' : trim($slug, '/');
        $seo = $site['seo'][$key] ?? [];
        $robots = [];
        if (array_key_exists('robots_index', $seo)) {
            $robots[] = $seo['robots_index'] ? 'index' : 'noindex';
        }
        if (array_key_exists('robots_follow', $seo)) {
            $robots[] = $seo['robots_follow'] ? 'follow' : 'nofollow';
        }

        return [
            'title' => ($seo['meta_title'] ?? '') ?: ($fallback['meta_title'] ?? ($site['settings']['site_name'] ?? 'Knots & Perl')),
            'description' => ($seo['meta_description'] ?? '') ?: ($fallback['meta_description'] ?? ($site['settings']['site_tagline'] ?? '')),
            'keywords' => ($seo['meta_keywords'] ?? '') ?: ($fallback['meta_keywords'] ?? ''),
            'canonical' => ($seo['canonical_url'] ?? '') ?: ($site['settings']['canonical_base'] ?? config('publishing.site_url')),
            'og_title' => ($seo['og_title'] ?? '') ?: (($seo['meta_title'] ?? '') ?: ($fallback['title'] ?? '')),
            'og_description' => ($seo['og_description'] ?? '') ?: ($seo['meta_description'] ?? ''),
            'og_image' => R2StorageService::url($seo['og_image'] ?? ''),
            'robots' => $robots === [] ? 'index, follow' : implode(', ', $robots),
        ];
    }

    private function settings(array $rows): array
    {
        $settings = $this->schema->schema()['defaults'] ?? [];
        foreach ($rows as $row) {
            $key = trim((string) $row['key']);
            if ($key !== '') {
                $settings[$key] = $row['value'];
            }
        }
        foreach (['enable_intro', 'enable_intro_sound', 'enable_heavy_animation', 'maintenance_mode'] as $flag) {
            $settings[$flag] = filter_var($settings[$flag] ?? false, FILTER_VALIDATE_BOOL);
        }
        $settings['countdown_seconds'] = max(0, min(10, (int) ($settings['countdown_seconds'] ?? 3)));
        $settings['theme'] = in_array($settings['theme'] ?? '', ['obsidian', 'pearl'], true) ? $settings['theme'] : 'obsidian';

        return $settings;
    }

    private function active(array $rows): array
    {
        $now = now()->getTimestamp();
        $active = array_values(array_filter($rows, static function ($row) use ($now): bool {
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                return false;
            }
            // An item with a "hide from the website on" time stops showing once that time has passed.
            $hideAt = trim((string) ($row['hide_at'] ?? ''));
            $hideTime = $hideAt === '' ? false : strtotime($hideAt);

            return $hideTime === false || $hideTime > $now;
        }));
        usort($active, static fn ($a, $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        return $active;
    }

    /** Alt text from the admin's media library, keyed by picture key. Empty when no export is configured. */
    private function mediaAlts(): array
    {
        $path = config('cms.media_alt_json');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return [];
        }
        $items = json_decode((string) file_get_contents($path), true);
        if (! is_array($items)) {
            return [];
        }
        $map = [];
        foreach ($items as $item) {
            if (! empty($item['key']) && ! empty($item['alt'])) {
                $map[(string) $item['key']] = (string) $item['alt'];
            }
        }

        return $map;
    }


    private function activeBanners(array $rows): array
    {
        $today = now()->toDateString();
        $active = array_values(array_filter($rows, static function ($row) use ($today): bool {
            if ((string) $row['status'] !== 'active') {
                return false;
            }
            $start = trim((string) $row['start_date']);
            $end = trim((string) $row['end_date']);
            if ($start !== '' && strtotime($start) > strtotime($today)) {
                return false;
            }

            return $end === '' || strtotime($end) >= strtotime($today);
        }));
        usort($active, static fn ($a, $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order']);

        return $active;
    }

    private function byId(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row[$key]] = $row;
        }

        return $map;
    }

    private function bySlug(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $slug = trim((string) $row[$key], '/');
            $map[$slug] = $row;
        }

        return $map;
    }

    private function categoryTree(array $categories): array
    {
        $nodes = [];
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[(string) $category['category_id']] = true;
            $parent = trim((string) $category['parent_category_id']);
            $category['parent_category_id'] = ($parent !== '' && $parent !== (string) $category['category_id'] && isset($categoriesById[$parent])) ? $parent : '';
            $category['children'] = [];
            $nodes[(string) $category['category_id']] = $category;
            $categoriesById[(string) $category['category_id']] = true;
        }

        return $this->tree($nodes, 'category_id', 'parent_category_id');
    }

    private function navigation(array $rows): array
    {
        $result = ['header' => [], 'footer' => []];
        foreach (['header', 'footer'] as $location) {
            $items = $this->active(array_values(array_filter(
                $rows,
                static fn ($row): bool => (string) $row['menu_location'] === $location
            )));
            $nodes = [];
            foreach ($items as $item) {
                $item['children'] = [];
                $nodes[(string) $item['menu_id']] = $item;
            }
            foreach ($nodes as $id => $node) {
                $parent = trim((string) $node['parent_menu_id']);
                if ($parent !== '' && ! isset($nodes[$parent])) {
                    $nodes[$id]['parent_menu_id'] = '';
                }
            }
            $result[$location] = $this->tree($nodes, 'menu_id', 'parent_menu_id');
        }

        return $result;
    }

    /** Builds a plain nested array. A bad parent chain becomes a root, never a cycle. */
    private function tree(array $nodes, string $idField, string $parentField): array
    {
        $build = function (string $parentId, array $ancestors) use (&$build, $nodes, $idField, $parentField): array {
            $branch = [];
            foreach ($nodes as $node) {
                $id = (string) $node[$idField];
                if (in_array($id, $ancestors, true)) {
                    continue;
                }
                if (trim((string) $node[$parentField]) !== $parentId) {
                    continue;
                }
                $node['children'] = $build($id, [...$ancestors, $id]);
                $branch[] = $node;
            }

            return $branch;
        };

        $tree = $build('', []);
        if ($tree === [] && $nodes !== []) {
            // A cyclic parent chain must not hide all categories/menu items.
            return array_values($nodes);
        }

        return $tree;
    }

    private function seo(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $slug = trim((string) $row['page_slug'], '/');
            $map[$slug === 'home' ? '' : $slug] = $row;
        }

        return $map;
    }
}
