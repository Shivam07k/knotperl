<?php

namespace Tests\Unit;

use App\Support\Sanitizer;
use Tests\TestCase;

class SanitizerTest extends TestCase
{
    public function test_it_removes_scripts_event_handlers_and_javascript_urls(): void
    {
        $html = '<p onclick="steal()" onmouseover="x()">Hello <script>alert(1)</script>'
            .'<a href="javascript:alert(1)" title="ok">world</a></p><iframe src="/evil"></iframe>';

        $clean = (new Sanitizer())->clean($html);

        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('>world</a>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('steal', $clean);
    }

    public function test_it_keeps_safe_formatting(): void
    {
        $clean = (new Sanitizer())->clean('<h2>Title</h2><ul><li>One</li></ul>');

        $this->assertSame('<h2>Title</h2><ul><li>One</li></ul>', $clean);
    }
}
