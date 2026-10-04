<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Рендер markdown-руководств из каталога docs/ на страницах раздела «Документация».
 *
 * Заголовкам добавляются якоря в стиле GitHub, чтобы работали ссылки
 * из раздела «Содержание» каждого документа.
 */
class MarkdownDoc
{
    /** @param string $relativePath путь к .md-файлу относительно корня проекта */
    public static function render(string $relativePath): HtmlString
    {
        $path = base_path($relativePath);

        if (! is_file($path)) {
            return new HtmlString('<p>Документ «'.e($relativePath).'» не найден.</p>');
        }

        $html = self::converter()
            ->convert((string) file_get_contents($path))
            ->getContent();

        return new HtmlString(self::addHeadingAnchors($html));
    }

    protected static function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Проставляет id="…" заголовкам h1–h4 по их тексту в стиле GitHub:
     * нижний регистр, всё кроме букв/цифр/пробелов/дефисов/подчёркиваний
     * удаляется, пробелы заменяются на дефисы (повторы не схлопываются).
     * Повторяющиеся заголовки получают суффикс -1, -2, …
     */
    protected static function addHeadingAnchors(string $html): string
    {
        $used = [];

        $result = preg_replace_callback(
            '/<h([1-4])>(.*?)<\/h\1>/s',
            static function (array $m) use (&$used): string {
                $text = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $slug = mb_strtolower($text, 'UTF-8');
                $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $slug);
                $slug = (string) preg_replace('/\s/u', '-', $slug);

                if ($slug === '') {
                    return $m[0];
                }

                if (isset($used[$slug])) {
                    $used[$slug]++;
                    $slug .= '-'.$used[$slug];
                } else {
                    $used[$slug] = 0;
                }

                return '<h'.$m[1].' id="'.$slug.'">'.$m[2].'</h'.$m[1].'>';
            },
            $html,
        );

        return $result ?? $html;
    }
}
