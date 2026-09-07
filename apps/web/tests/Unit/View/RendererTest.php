<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\View\Renderer;
use Tests\TestCase;

final class RendererTest extends TestCase
{
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->templatesDir = sys_get_temp_dir() . '/renderer_test_' . uniqid('', true);
        mkdir($this->templatesDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->templatesDir . '/*.php') ?: []);
        @rmdir($this->templatesDir);
    }

    private function writeTemplate(string $name, string $contents): void
    {
        file_put_contents($this->templatesDir . '/' . $name . '.php', $contents);
    }

    public function testRendersSimpleTemplateWithData(): void
    {
        $this->writeTemplate('greeting', '<p>Hello, <?= \App\View\Renderer::e($name) ?>!</p>');

        $renderer = new Renderer($this->templatesDir);
        $output = $renderer->render('greeting', ['name' => 'Alice']);

        $this->assertSame('<p>Hello, Alice!</p>', $output);
    }

    public function testEscapesDangerousInput(): void
    {
        $this->writeTemplate('greeting', '<p><?= \App\View\Renderer::e($name) ?></p>');

        $renderer = new Renderer($this->templatesDir);
        $output = $renderer->render('greeting', ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testEscapeHelperHandlesNullAndScalars(): void
    {
        $this->assertSame('', Renderer::e(null));
        $this->assertSame('42', Renderer::e(42));
        $this->assertSame('&quot;quoted&quot;', Renderer::e('"quoted"'));
    }

    public function testThrowsWhenTemplateMissing(): void
    {
        $renderer = new Renderer($this->templatesDir);

        $this->expectException(\RuntimeException::class);
        $renderer->render('does-not-exist');
    }

    public function testOutputBufferIsClosedWhenTemplateThrows(): void
    {
        $this->writeTemplate('broken', '<?php throw new \RuntimeException("boom"); ?>');

        $renderer = new Renderer($this->templatesDir);
        $levelBefore = ob_get_level();

        try {
            $renderer->render('broken');
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($levelBefore, ob_get_level());
    }

    public function testRenderWithLayoutComposesContentIntoLayout(): void
    {
        $this->writeTemplate('layout', '<html><body><?= $content ?></body></html>');
        $this->writeTemplate('page', '<h1><?= \App\View\Renderer::e($title) ?></h1>');

        $renderer = new Renderer($this->templatesDir);
        $output = $renderer->renderWithLayout('page', ['title' => 'Hi']);

        $this->assertSame('<html><body><h1>Hi</h1></body></html>', $output);
    }

    public function testRenderWithLayoutAllowsCustomLayoutName(): void
    {
        $this->writeTemplate('alt-layout', '[<?= $content ?>]');
        $this->writeTemplate('page', 'body-text');

        $renderer = new Renderer($this->templatesDir);
        $output = $renderer->renderWithLayout('page', [], 'alt-layout');

        $this->assertSame('[body-text]', $output);
    }

    public function testRealLayoutTemplateRendersNavAndTitle(): void
    {
        $realTemplatesDir = dirname(__DIR__, 3) . '/templates';
        $renderer = new Renderer($realTemplatesDir);

        $output = $renderer->renderWithLayout('pages/home', [
            'title' => 'Latest recipes',
            'recipes' => [],
        ], 'layout');

        $this->assertStringContainsString('<nav', $output);
        $this->assertStringContainsString('>Recipes<', $output);
        $this->assertStringContainsString('>Diaries<', $output);
        $this->assertStringContainsString('Latest recipes', $output);
        $this->assertStringContainsString('No public recipes yet', $output);
    }

    public function testRealHomeTemplateRendersCardGridWithRecipes(): void
    {
        $realTemplatesDir = dirname(__DIR__, 3) . '/templates';
        $renderer = new Renderer($realTemplatesDir);

        $output = $renderer->render('pages/home', [
            'title' => 'Latest recipes',
            'recipes' => [
                ['id' => 1, 'name' => 'Session IPA', 'category' => 'beer'],
                ['id' => 2, 'name' => '<xss>', 'category' => 'mead'],
            ],
        ]);

        $this->assertStringContainsString('card-grid', $output);
        $this->assertStringContainsString('Session IPA', $output);
        $this->assertStringContainsString('&lt;xss&gt;', $output);
        $this->assertStringNotContainsString('<xss>', $output);
    }
}
