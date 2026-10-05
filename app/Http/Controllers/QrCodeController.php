<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    public function generate(Competition $competition)
    {
        // Ссылка на публичную страницу результатов (решение заказчика 05.10:
        // общая логика токена — Competition::publicResultsUrl()).
        $url = $competition->publicResultsUrl();

        // Генерируем QR-код
        try {
            $qrCode = QrCode::size(300)
                ->format('svg')
                ->generate($url);

            return response($qrCode, 200)
                ->header('Content-Type', 'image/svg+xml');
        } catch (\Exception $e) {
            // Fallback: используем онлайн API для генерации QR-кода
            $encodedUrl = urlencode($url);
            $apiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encodedUrl}";

            return redirect($apiUrl);
        }
    }
}
