<?php

namespace App\Services\CMS;

class ThemeService
{
    private const DEFAULT_ACCENT = '#d4af37';

    private const FONT_STACKS = [
        'Playfair Display' => "'Playfair Display', Georgia, serif",
        'Montserrat' => "'Montserrat', system-ui, sans-serif",
        'Georgia' => 'Georgia, serif',
        'system-ui' => 'system-ui, sans-serif',
    ];

    public function css(array $settings): string
    {
        $accent = trim((string) ($settings['accent_color'] ?? ''));
        $accent = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $accent) ? $accent : self::DEFAULT_ACCENT;
        $font = trim((string) ($settings['default_font'] ?? ''));
        $stack = self::FONT_STACKS[$font] ?? self::FONT_STACKS['Playfair Display'];

        $css = '';
        foreach (['obsidian', 'pearl'] as $theme) {
            $css .= 'html[data-theme="'.$theme.'"] { --accent: '.$accent.'; --serif: '.$stack.'; }'."\n";
        }

        return $css;
    }
}
