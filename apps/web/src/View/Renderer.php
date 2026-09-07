<?php

declare(strict_types=1);

namespace App\View;

/**
 * Minimal PHP-include view renderer.
 *
 * Templates are plain PHP files under a configured templates directory;
 * data is extracted into local variables for the template to use. Output
 * escaping is the template author's responsibility via the static e()
 * helper (or the raw()/attr() variants below) — nothing is auto-escaped,
 * consistent with a hand-rolled, no-framework app.
 */
final class Renderer
{
    public function __construct(private readonly string $templatesPath)
    {
    }

    /**
     * Renders a single template file to a string.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        $renderTemplate = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                include $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        return $renderTemplate($file, $data);
    }

    /**
     * Renders a template and wraps its output as the `content` variable of
     * a layout template (base.php by default).
     *
     * @param array<string, mixed> $data
     */
    public function renderWithLayout(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);

        return $this->render($layout, [...$data, 'content' => $content]);
    }

    private function resolve(string $template): string
    {
        $file = rtrim($this->templatesPath, '/') . '/' . ltrim($template, '/') . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Template not found: %s (looked for %s)', $template, $file));
        }

        return $file;
    }

    /**
     * HTML-escapes a value for safe output in element content or a
     * double-quoted attribute. Non-string scalars are cast to string;
     * null becomes an empty string.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
