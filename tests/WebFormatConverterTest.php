<?php

namespace BootDesk\ChatSDK\Web\Tests;

use BootDesk\ChatSDK\Web\WebFormatConverter;
use PHPUnit\Framework\TestCase;

class WebFormatConverterTest extends TestCase
{
    private WebFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new WebFormatConverter;
    }

    public function test_passthrough_text(): void
    {
        $ast = $this->converter->toAst('Hello world');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello world', $markdown);
    }

    public function test_preserves_bold(): void
    {
        $ast = $this->converter->toAst('This is **bold** text');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_preserves_links(): void
    {
        $ast = $this->converter->toAst('[click here](https://example.com)');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('click here', $html);
    }

    public function test_roundtrip(): void
    {
        $text = 'Hello **world** and [link](https://example.com)';
        $ast = $this->converter->toAst($text);
        $output = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('world', $output);
    }
}
