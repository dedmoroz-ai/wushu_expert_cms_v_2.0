<?php

namespace App\Filament\Pages;

use App\Support\MarkdownDoc;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Раздел «Документация» → «Для тренеров»: рендер docs/COACH_GUIDE.md.
 * Доступно всем ролям.
 */
class CoachGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Для тренеров';

    protected static ?string $navigationGroup = 'Документация';

    protected static ?string $title = 'Инструкция тренера';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.docs-page';

    /** markdown-документ руководства (относительно корня проекта) */
    protected const DOCUMENT_PATH = 'docs/COACH_GUIDE.md';

    public function getDoc(): HtmlString
    {
        return MarkdownDoc::render(self::DOCUMENT_PATH);
    }
}
