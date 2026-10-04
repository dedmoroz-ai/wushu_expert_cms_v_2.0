<?php

namespace App\Filament\Pages;

use App\Support\MarkdownDoc;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Раздел «Документация» → «Для судей»: рендер docs/JUDGE_GUIDE.md.
 * Доступно всем ролям.
 */
class JudgeGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Для судей';

    protected static ?string $navigationGroup = 'Документация';

    protected static ?string $title = 'Инструкция судьи';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.docs-page';

    /** markdown-документ руководства (относительно корня проекта) */
    protected const DOCUMENT_PATH = 'docs/JUDGE_GUIDE.md';

    public function getDoc(): HtmlString
    {
        return MarkdownDoc::render(self::DOCUMENT_PATH);
    }
}
