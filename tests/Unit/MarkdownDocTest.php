<?php

namespace Tests\Unit;

use App\Support\MarkdownDoc;
use Tests\TestCase;

/**
 * Руководства из docs/ рендерятся на страницах раздела «Документация»;
 * ссылки «Содержания» должны попадать в якоря заголовков.
 */
class MarkdownDocTest extends TestCase
{
    /** @return array<int, string> */
    private function documents(): array
    {
        return ['docs/COACH_GUIDE.md', 'docs/JUDGE_GUIDE.md'];
    }

    /** Оба руководства рендерятся: есть заголовки с якорями и таблицы. */
    public function test_guides_render_with_heading_anchors_and_tables(): void
    {
        foreach ($this->documents() as $path) {
            $html = (string) MarkdownDoc::render($path);

            $this->assertStringContainsString('<h2 id="', $html, "Нет якорей заголовков в «{$path}».");
            $this->assertStringContainsString('<table', $html, "Таблицы не отрендерились в «{$path}».");
        }
    }

    /** Все ссылки из «Содержания» ведут на существующие якоря заголовков. */
    public function test_toc_links_resolve_to_heading_anchors(): void
    {
        foreach ($this->documents() as $path) {
            $source = (string) file_get_contents(base_path($path));
            $html = (string) MarkdownDoc::render($path);

            preg_match_all('/\]\(#([^)]+)\)/', $source, $matches);
            $anchors = $matches[1];

            $this->assertNotEmpty($anchors, "В «{$path}» нет ссылок из «Содержания» — тест потерял смысл.");

            $missing = array_values(array_filter(
                $anchors,
                static fn (string $anchor): bool => ! str_contains($html, 'id="'.$anchor.'"'),
            ));

            $this->assertSame(
                [],
                $missing,
                "«{$path}»: не найдены якоря для ссылок «Содержания»: ".implode(', ', $missing),
            );
        }
    }
}
