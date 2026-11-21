<?php

namespace Tests\Unit;

use Tests\TestCase;

class VisitorChromeTest extends TestCase
{
    public function test_nav_highlight_matches_paths_with_or_without_trailing_slash(): void
    {
        $js = (string) file_get_contents(base_path('public/js/site.js'));

        $this->assertStringContainsString("replace(/\\/+$/, '')", $js);
        $this->assertStringNotContainsString('|| links[0]', $js);
    }

    public function test_nav_highlight_left_is_measured_from_the_nav(): void
    {
        $js = (string) file_get_contents(base_path('public/js/site.js'));

        $this->assertStringContainsString('linkBox.left - navBox.left', $js);
        $this->assertStringNotContainsString('link.offsetLeft', $js);
    }

    public function test_nav_has_only_one_underline_mechanism(): void
    {
        $css = (string) file_get_contents(base_path('public/css/site.css'));

        $this->assertStringContainsString('.nav-highlight {', $css);
        $this->assertStringNotContainsString('.nav-item > a::after', $css);
        $this->assertStringNotContainsString('[aria-current="page"]::after', $css);
    }

    public function test_scrollbars_are_hidden_without_disabling_scrolling(): void
    {
        $css = (string) file_get_contents(base_path('public/css/site.css'));

        $this->assertStringContainsString('scrollbar-width: none', $css);
        $this->assertStringContainsString('*::-webkit-scrollbar { display: none; }', $css);
        $this->assertStringNotContainsString('html { overflow: hidden; }', $css);
    }
}
