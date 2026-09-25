<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeController extends Controller
{
    public function generate(Competition $competition)
    {
        // Проверяем наличие колонки (для совместимости)
        try {
            $hasPublicTokenColumn = Schema::hasColumn('competitions', 'public_token');
            
            if ($hasPublicTokenColumn) {
                // Генерируем токен, если его нет
                if (!$competition->public_token) {
                    $competition->public_token = Str::random(32);
                    $competition->save();
                }
                $url = route('public.results', ['token' => $competition->public_token]);
            } else {
                // Если колонки нет, используем ID соревнования
                $url = route('public.results', ['token' => 'comp_' . $competition->id]);
            }
        } catch (\Exception $e) {
            // Fallback: используем ID соревнования
            $url = route('public.results', ['token' => 'comp_' . $competition->id]);
        }
        
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
