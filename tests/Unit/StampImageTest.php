<?php

namespace Tests\Unit;

use App\Support\StampImage;
use PHPUnit\Framework\TestCase;

/**
 * Печать на PDF-документах — стандартный диаметр 40 мм (ГОСТ Р 51511-2001).
 *
 * StampImage нормализует organization_stamp: обрезает пустые поля вокруг
 * оттиска и вписывает его в квадратный холст 480×480 px (без искажения
 * пропорций), а шаблоны ставят жёсткий CSS-размер 40mm × 40mm — большая
 * сторона оттиска занимает ровно 40 мм, диаметр печати всегда стандартный.
 *
 * Задача заказчика: штамп на PDF всегда 40 мм в диаметре, независимо от
 * размера/разрешения загруженной картинки.
 */
class StampImageTest extends TestCase
{
    private function requireGd(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Расширение GD не установлено.');
        }
    }

    /** Тестовая картинка: белый фон заданного размера. */
    private function makeWhiteImage(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 255, 255, 255));

        return $img;
    }

    /** Тестовая картинка: полностью прозрачный фон заданного размера. */
    private function makeTransparentImage(int $w, int $h): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));

        return $img;
    }

    private function toPng(\GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function fromPng(string $png): \GdImage
    {
        $img = imagecreatefromstring($png);
        $this->assertInstanceOf(\GdImage::class, $img);

        return $img;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} R, G, B, A */
    private function sample(\GdImage $img, int $x, int $y): array
    {
        $px = imagecolorat($img, $x, $y);

        return [($px >> 16) & 0xFF, ($px >> 8) & 0xFF, $px & 0xFF, ($px >> 24) & 0x7F];
    }

    private function isRed(array $rgba): bool
    {
        return $rgba[0] > 150 && $rgba[1] < 80 && $rgba[2] < 80 && $rgba[3] < 30;
    }

    /** Нормализованная печать — всегда квадрат 480×480 px. */
    public function test_normalized_stamp_is_square_canvas_480(): void
    {
        $this->requireGd();

        $img = $this->makeWhiteImage(200, 100);
        imagefilledrectangle($img, 20, 20, 179, 79, imagecolorallocate($img, 200, 0, 0));

        $png = StampImage::normalizeToPng($this->toPng($img));
        $this->assertNotNull($png);

        $out = $this->fromPng($png);
        $this->assertSame(480, imagesx($out));
        $this->assertSame(480, imagesy($out));
    }

    /**
     * Пустые поля вокруг оттиска обрезаются: оттиск занимает весь холст,
     * поэтому в 40×40 мм попадает ровно круг печати, а не круг + поля.
     */
    public function test_empty_margins_are_trimmed_so_stamp_fills_the_canvas(): void
    {
        $this->requireGd();

        // 300×300: красный квадрат 100×100 по центру (имитация скана с полями).
        $img = $this->makeWhiteImage(300, 300);
        imagefilledrectangle($img, 100, 100, 199, 199, imagecolorallocate($img, 200, 0, 0));

        $png = StampImage::normalizeToPng($this->toPng($img));
        $this->assertNotNull($png);

        $out = $this->fromPng($png);
        // После обрезки полей оттиск заполняет весь квадратный холст.
        $this->assertTrue($this->isRed($this->sample($out, 2, 2)), 'угол холста должен быть внутри оттиска');
        $this->assertTrue($this->isRed($this->sample($out, 477, 477)), 'угол холста должен быть внутри оттиска');
        $this->assertTrue($this->isRed($this->sample($out, 240, 240)), 'центр холста — оттиск');
    }

    /**
     * Пропорции оттиска сохраняются (не квадратный штамп не сплющивается):
     * круг остаётся кругом — вписываем в квадрат по большей стороне.
     */
    public function test_non_square_stamp_keeps_aspect_ratio(): void
    {
        $this->requireGd();

        // 300×200: красный прямоугольник 200×100 с полями по краям.
        $img = $this->makeWhiteImage(300, 200);
        imagefilledrectangle($img, 50, 50, 249, 149, imagecolorallocate($img, 200, 0, 0));

        $png = StampImage::normalizeToPng($this->toPng($img));
        $this->assertNotNull($png);

        $out = $this->fromPng($png);
        // Оттиск 2:1 вписан по большей стороне (480), высота 240, отцентрован:
        // границы полосы ~120..359, поля сверху/снизу не оттиск.
        $this->assertTrue($this->isRed($this->sample($out, 240, 130)), 'верх оттиска');
        $this->assertTrue($this->isRed($this->sample($out, 240, 350)), 'низ оттиска');
        $this->assertFalse($this->isRed($this->sample($out, 240, 10)), 'поле сверху — не оттиск');
        $this->assertFalse($this->isRed($this->sample($out, 240, 470)), 'поле снизу — не оттиск');
    }

    /** Прозрачный фон PNG — тоже фон, обрезается как белый. */
    public function test_transparent_background_is_trimmed(): void
    {
        $this->requireGd();

        $img = $this->makeTransparentImage(200, 200);
        imagefilledellipse($img, 50, 50, 60, 60, imagecolorallocate($img, 200, 0, 0));

        $png = StampImage::normalizeToPng($this->toPng($img));
        $this->assertNotNull($png);

        $out = $this->fromPng($png);
        $this->assertTrue($this->isRed($this->sample($out, 240, 240)), 'центр — оттиск');
    }

    /** Мусор вместо картинки ⇒ null (фолбэк на оригинал), без исключений. */
    public function test_invalid_binary_returns_null(): void
    {
        $this->assertNull(StampImage::normalizeToPng('это не картинка'));
        $this->assertNull(StampImage::normalizeToPng(''));
    }

    /** Пустая картинка без оттиска ⇒ null, а не белый квадрат в PDF. */
    public function test_blank_image_returns_null(): void
    {
        $this->requireGd();

        $png = StampImage::normalizeToPng($this->toPng($this->makeWhiteImage(100, 100)));
        $this->assertNull($png);
    }

    /**
     * Регрессия: все действующие PDF-шаблоны ставят печать 40×40 мм
     * (а не «плавающий» max-width от разрешения картинки).
     */
    public function test_all_pdf_templates_pin_stamp_to_40mm(): void
    {
        $templates = [
            'start-list.blade.php',
            'final-results.blade.php',
            'title-page-standalone.blade.php',
            'team-standings.blade.php',
        ];

        foreach ($templates as $template) {
            $path = dirname(__DIR__, 2) . '/resources/views/pdf/' . $template;
            $this->assertFileExists($path);

            $css = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression(
                '/\.stamp-img\s*\{[^}]*width:\s*40mm;[^}]*height:\s*40mm;/',
                $css,
                "В шаблоне {$template} печать должна быть зафиксирована на 40×40 мм"
            );
        }
    }
}
