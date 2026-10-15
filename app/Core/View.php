<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * Render a template inside an optional layout. Templates live in app/Views
     * and receive $data as local variables. The layout receives $content.
     */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $__view, array $__data = []): string
    {
        $__file = APP_PATH . '/Views/' . $__view . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: $__view");
        }
        extract($__data, EXTR_SKIP);
        unset($__data);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
