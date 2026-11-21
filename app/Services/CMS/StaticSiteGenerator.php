<?php

namespace App\Services\CMS;

use App\Services\Cloudflare\R2StorageService;
use Illuminate\Support\Facades\File;
use MatthiasMullie\Minify\CSS as MinifyCss;
use MatthiasMullie\Minify\JS as MinifyJs;
use RuntimeException;

class StaticSiteGenerator
{
    private const PUBLIC_SETTINGS = [
        'site_name', 'site_logo', 'favicon', 'site_tagline', 'primary_color', 'secondary_color',
        'accent_color', 'default_font', 'contact_email', 'contact_phone', 'whatsapp_number',
        'business_address', 'copyright_text', 'default_language', 'currency', 'timezone',
        'theme', 'enable_intro', 'countdown_seconds', 'enable_heavy_animation',
        'intro_video_url', 'intro_texture_url', 'maintenance_mode',
    ];

    private array $routes = [];

    public function __construct(private ContentBuilderService $builder, private ?ThemeService $theme = null)
    {
        $this->theme = $theme ?? new ThemeService();
    }

    public function render(array $site, string $target): array
    {
        $this->routes = [];
        $this->prepare($target);

        $home = $site['pages'][''] ?? null;
        $this->write($target.'/index.html', view('site.pages.home', [
            'site' => $site,
            'page' => $home,
            'seo' => $this->builder->seoFor($site, 'home', $home ?? []),
        ])->render(), '/');

        foreach ($site['pages'] as $slug => $page) {
            if ($slug === '' || in_array($page['template'], ['home', 'products', 'category', 'product'], true)) {
                continue;
            }
            $view = 'site.pages.'.(in_array($page['template'], ['about', 'contact', 'services'], true) ? $page['template'] : 'custom');
            $path = $slug.'/index.html';
            $this->write($target.'/'.$path, view($view, [
                'site' => $site,
                'page' => $page,
                'seo' => $this->builder->seoFor($site, $slug, $page),
            ])->render(), $this->builder->url($path));
        }

        $this->renderProducts($site, $target);
        $this->renderCategories($site, $target);

        $this->write($target.'/404.html', view('site.pages.not-found', [
            'site' => $site,
            'seo' => $this->builder->seoFor($site, '404', ['title' => 'Page not found']),
        ])->render());

        $this->write($target.'/sitemap.html', view('site.pages.sitemap', [
            'site' => $site,
            'links' => $this->routes,
            'seo' => $this->builder->seoFor($site, 'sitemap', ['title' => 'Sitemap']),
        ])->render(), '/sitemap.html');
        $this->write($target.'/sitemap.xml', $this->sitemap($site));
        $this->write($target.'/robots.txt', $this->robots($site));
        $this->write($target.'/_redirects', $this->redirects($site));
        $this->write($target.'/_headers', $this->headers($site));
        $this->writeJson($target.'/data/site.json', [
            'settings' => $this->publicSettings($site),
            'navigation' => $site['navigation'],
            'categories' => $site['categories'],
            'contact' => $site['contact'],
            'generated_at' => $site['generated_at'],
        ]);
        $this->writeJson($target.'/data/products.json', $site['products']);
        $this->writeJson($target.'/data/categories.json', $site['categories']);

        $this->copyAssets($target);
        $this->write($target.'/css/theme.css', $this->theme->css($site['settings'] ?? []));

        return ['path' => $target, 'files' => count($this->routes) + 9, 'routes' => $this->routes];
    }

    private function renderProducts(array $site, string $target): void
    {
        $chunks = array_chunk($site['products'], 12) ?: [[]];
        foreach ($chunks as $index => $products) {
            $number = $index + 1;
            $path = $number === 1 ? 'products/index.html' : 'products/page/'.$number.'/index.html';
            $this->write($target.'/'.$path, view('site.pages.products', [
                'site' => $site,
                'products' => $products,
                'page' => $site['pages']['products'] ?? null,
                'pagination' => ['current' => $number, 'total' => count($chunks), 'base' => '/products'],
                'seo' => $this->builder->seoFor($site, 'products', $site['pages']['products'] ?? []),
            ])->render(), $this->builder->url($path));
        }

        foreach ($site['products'] as $product) {
            $slug = trim((string) $product['slug'], '/');
            if ($slug === '') {
                continue;
            }
            $path = 'products/'.$slug.'/index.html';
            $this->write($target.'/'.$path, view('site.pages.product', [
                'site' => $site,
                'product' => $product,
                'related' => $this->related($site, $product),
                'seo' => $this->builder->seoFor($site, 'products/'.$slug, [
                    'title' => $product['product_name'],
                    'meta_title' => $product['meta_title'],
                    'meta_description' => $product['meta_description'],
                ]),
            ])->render(), $this->builder->url($path));
        }
    }

    private function renderCategories(array $site, string $target): void
    {
        foreach ($site['category_by_slug'] as $slug => $category) {
            if ($slug === '') {
                continue;
            }
            $products = array_values(array_filter(
                $site['products'],
                static fn ($product): bool => (string) ($product['category_id'] ?? '') === (string) $category['category_id']
            ));
            $path = 'category/'.$slug.'/index.html';
            $this->write($target.'/'.$path, view('site.pages.category', [
                'site' => $site,
                'category' => $category,
                'products' => $products,
                'seo' => $this->builder->seoFor($site, 'category/'.$slug, $category),
            ])->render(), $this->builder->url($path));
        }
    }

    private function related(array $site, array $product): array
    {
        $related = array_values(array_filter($site['products'], static fn ($item): bool =>
            (string) $item['product_id'] !== (string) $product['product_id']
            && (string) $item['category_id'] === (string) $product['category_id']
        ));

        return array_slice($related, 0, 4);
    }

    private function sitemap(array $site): string
    {
        $base = rtrim((string) (config('publishing.site_url') ?: 'https://example.com'), '/');
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($this->routes as $route) {
            $lines[] = '  <url><loc>'.htmlspecialchars($base.$route, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                .'<lastmod>'.htmlspecialchars((string) $site['generated_at'], ENT_XML1, 'UTF-8').'</lastmod></url>';
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    private function robots(array $site): string
    {
        $base = rtrim((string) (config('publishing.site_url') ?: 'https://example.com'), '/');
        $blocks = ! empty($site['settings']['maintenance_mode']) ? ["User-agent: *\nDisallow: /"] : ["User-agent: *\nAllow: /"];

        return implode("\n\n", $blocks)."\n\nSitemap: {$base}/sitemap.xml\n";
    }

    private function redirects(array $site): string
    {
        $lines = [];
        foreach ($site['redirects'] as $redirect) {
            $lines[] = trim((string) $redirect['source_url']).' '.trim((string) $redirect['destination_url']).' '.(int) $redirect['redirect_type'];
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private function headers(array $site): string
    {
        $origins = [];
        foreach (['form_endpoint', 'newsletter_endpoint'] as $key) {
            $origin = $this->endpointOrigin((string) ($site['settings'][$key] ?? ''));
            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }
        $connect = "connect-src 'self'".($origins === [] ? '' : ' '.implode(' ', array_keys($origins)));

        return "/*\n"
            ."  X-Content-Type-Options: nosniff\n"
            ."  X-Frame-Options: DENY\n"
            ."  Referrer-Policy: strict-origin-when-cross-origin\n"
            ."  Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; {$connect}\n\n"
            ."/assets/*\n  Cache-Control: public, max-age=31536000, immutable\n";
    }

    private function endpointOrigin(string $endpoint): ?string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($scheme === 'https' && preg_match('/^[a-z0-9.-]+$/', $host)) {
            return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
        }
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true) && $port !== null) {
            return 'http://'.$host.':'.$port;
        }

        return null;
    }

    private function publicSettings(array $site): array
    {
        $settings = [];
        foreach (self::PUBLIC_SETTINGS as $key) {
            if (array_key_exists($key, $site['settings'])) {
                $settings[$key] = in_array($key, ['site_logo', 'favicon', 'og_image'], true)
                    ? R2StorageService::url($site['settings'][$key])
                    : $site['settings'][$key];
            }
        }

        return $settings;
    }

    private function prepare(string $target): void
    {
        if (is_dir($target)) {
            File::deleteDirectory($target);
        }
        File::ensureDirectoryExists($target.'/data');
        File::ensureDirectoryExists($target.'/css');
        File::ensureDirectoryExists($target.'/js');
    }

    private function write(string $path, string $contents, ?string $route = null): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents, LOCK_EX);
        if ($route !== null) {
            $this->routes[] = $route;
        }
    }

    private function writeJson(string $path, mixed $data): void
    {
        $this->write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function copyAssets(string $target): void
    {
        $assets = public_path('assets');
        if (is_dir($assets)) {
            File::copyDirectory($assets, $target.'/assets');
        }
        foreach (['css', 'js'] as $folder) {
            $source = public_path($folder);
            if (! is_dir($source)) {
                throw new RuntimeException("Missing static assets: {$folder}");
            }
            foreach (glob($source.'/*') ?: [] as $file) {
                if (! is_file($file)) {
                    continue;
                }
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $destination = $target.'/'.$folder.'/'.basename($file);
                if ($extension === 'css') {
                    file_put_contents($destination, (new MinifyCss($file))->minify(), LOCK_EX);
                } elseif ($extension === 'js') {
                    file_put_contents($destination, (new MinifyJs($file))->minify(), LOCK_EX);
                } else {
                    copy($file, $destination);
                }
            }
        }
    }
}
