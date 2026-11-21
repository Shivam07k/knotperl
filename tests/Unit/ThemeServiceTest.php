<?php

namespace Tests\Unit;

use App\Services\CMS\ThemeService;
use Tests\TestCase;

class ThemeServiceTest extends TestCase
{
    public function test_css_overrides_both_themes_and_validates_values(): void
    {
        $css = (new ThemeService())->css([
            'accent_color' => '#d4af37',
            'default_font' => 'Playfair Display',
        ]);

        $this->assertStringContainsString('html[data-theme="obsidian"]', $css);
        $this->assertStringContainsString('html[data-theme="pearl"]', $css);
        $this->assertSame(2, substr_count($css, '--accent: #d4af37'));
        $this->assertSame(2, substr_count($css, "'Playfair Display', Georgia, serif"));
        $this->assertStringNotContainsString('--primary', $css);
        $this->assertStringNotContainsString('--secondary', $css);
    }

    public function test_invalid_colour_and_font_fall_back_without_emitting_arbitrary_css(): void
    {
        $css = (new ThemeService())->css([
            'accent_color' => 'red; background:url(x)',
            'default_font' => 'Comic Sans MS',
        ]);

        $this->assertSame(2, substr_count($css, '--accent: #d4af37'));
        $this->assertSame(2, substr_count($css, "'Playfair Display', Georgia, serif"));
        $this->assertStringNotContainsString('url(', $css);
        $this->assertStringNotContainsString('red;', $css);
        $this->assertStringNotContainsString('Comic Sans', $css);
    }
}
