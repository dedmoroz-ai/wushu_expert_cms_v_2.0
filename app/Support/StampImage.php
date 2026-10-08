<?php

namespace App\Support;

/**
 * Нормализация изображения печати (organization_stamp) для PDF-документов.
 *
 * Задача: печать должна всегда печататься стандартного диаметра 40 мм
 * (ГОСТ Р 51511-2001 — оттиск круглой печати), независимо от размера
 * и разрешения загруженной картинки. Для этого:
 *  1) обрезаем пустые поля вокруг оттиска (автокроп по цвету фона);
 *  2) вписываем изображение в квадратный холст без искажения пропорций;
 *  3) шаблоны ставят жёсткий размер 40mm × 40mm, поэтому большая сторона
 *     оттиска занимает ровно 40 мм — диаметр печати всегда стандартный.
 *
 * Любая ошибка обработки ⇒ null / возврат исходной картинки (фолбэк),
 * PDF не должен сломаться из-за картинки.
 */
final class StampImage
{
    /** Сторона квадратного холста, px (300 dpi для 40 мм ≈ 472 px). */
    private const CANVAS_SIZE = 480;

    /** Макс. сторона рабочей копии при поиске границ оттиска (ускорение). */
    private const SCAN_SIZE = 1200;

    /** Порог отличия пикселя от фона при автокропе: сумма |ΔR|+|ΔG|+|ΔB|. */
    private const TRIM_THRESHOLD = 40;

    /** Порог отличия альфа-канала от фона при автокропе. */
    private const TRIM_ALPHA_THRESHOLD = 20;

    /**
     * Нормализованная печать как data-URI для <img> в PDF-шаблонах.
     * Фолбэк — исходная картинка, как раньше.
     *
     * @param string|null $path путь относительно storage/app/public
     */
    public static function toBase64(?string $path): ?string
    {
        if (!$path) return null;

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return null;
        }

        try {
            $data = file_get_contents($fullPath);
            if ($data === false) return null;

            $png = self::normalizeToPng($data);
            if ($png !== null) {
                return 'data:image/png;base64,' . base64_encode($png);
            }

            // Фолбэк: не смогли нормализовать — отдаём оригинал как есть.
            $type = pathinfo($fullPath, PATHINFO_EXTENSION);
            return 'data:image/' . $type . ';base64,' . base64_encode($data);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Автокроп пустых полей + вписывание в квадратный холст + PNG.
     *
     * @return string|null PNG-байты или null (исходник не удалось обработать)
     */
    public static function normalizeToPng(string $binary): ?string
    {
        if ($binary === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 1 || $height < 1) {
            imagedestroy($src);
            return null;
        }

        // Рабочая копия: truecolor с альфой, не больше SCAN_SIZE (ускорение).
        $scale = min(1.0, self::SCAN_SIZE / max($width, $height));
        $workW = max(1, (int) round($width * $scale));
        $workH = max(1, (int) round($height * $scale));

        $work = imagecreatetruecolor($workW, $workH);
        imagealphablending($work, false);
        imagesavealpha($work, true);
        imagefilledrectangle($work, 0, 0, $workW - 1, $workH - 1, imagecolorallocatealpha($work, 0, 0, 0, 127));
        imagecopyresampled($work, $src, 0, 0, 0, 0, $workW, $workH, $width, $height);
        imagedestroy($src);

        // Границы оттиска (фон — пиксель (0,0): белый, прозрачный и т.п.).
        [$minX, $minY, $maxX, $maxY] = self::contentBounds($work, $workW, $workH);
        if ($minX === null) {
            imagedestroy($work);
            return null;
        }

        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;

        // Квадратный холст: сторона = большей стороне оттиска, оттиск по
        // центру — пропорции сохраняются, круг печати вписан в квадрат.
        $side = max($cropW, $cropH);
        $canvas = imagecreatetruecolor($side, $side);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $side - 1, $side - 1, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopy(
            $canvas,
            $work,
            (int) (($side - $cropW) / 2),
            (int) (($side - $cropH) / 2),
            $minX,
            $minY,
            $cropW,
            $cropH
        );
        imagedestroy($work);

        // Финальный размер холста.
        $out = imagecreatetruecolor(self::CANVAS_SIZE, self::CANVAS_SIZE);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefilledrectangle($out, 0, 0, self::CANVAS_SIZE - 1, self::CANVAS_SIZE - 1, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, self::CANVAS_SIZE, self::CANVAS_SIZE, $side, $side);
        imagedestroy($canvas);

        ob_start();
        imagepng($out);
        imagedestroy($out);
        $png = ob_get_clean();

        return $png === '' ? null : $png;
    }

    /**
     * Прямоугольник содержимого (оттиска) на рабочей копии.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null, 3: int|null}
     */
    private static function contentBounds(\GdImage $img, int $width, int $height): array
    {
        $bg = imagecolorat($img, 0, 0);
        $bgR = ($bg >> 16) & 0xFF;
        $bgG = ($bg >> 8) & 0xFF;
        $bgB = $bg & 0xFF;
        $bgA = ($bg >> 24) & 0x7F;

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $px = imagecolorat($img, $x, $y);
                $a = ($px >> 24) & 0x7F;
                $diff = abs((($px >> 16) & 0xFF) - $bgR)
                    + abs((($px >> 8) & 0xFF) - $bgG)
                    + abs(($px & 0xFF) - $bgB);

                if ($diff > self::TRIM_THRESHOLD || abs($a - $bgA) > self::TRIM_ALPHA_THRESHOLD) {
                    if ($x < $minX) $minX = $x;
                    if ($x > $maxX) $maxX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }

        if ($maxX < 0 || $maxY < 0) {
            return [null, null, null, null];
        }

        return [$minX, $minY, $maxX, $maxY];
    }
}