<x-filament-panels::page>
    @php
        $reports = $this->getReports();
    @endphp

    <style>
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }
        .report-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.15s, transform 0.15s;
        }
        .dark .report-card {
            background: #1f2937;
            border-color: #374151;
        }
        .report-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .report-card h3 {
            margin: 0 0 8px 0;
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            line-height: 1.3;
        }
        .dark .report-card h3 { color: #f3f4f6; }
        .report-card .desc {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 12px 0;
            line-height: 1.4;
            flex: 1;
        }
        .dark .report-card .desc { color: #9ca3af; }
        .report-card .meta {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 14px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .report-card .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .report-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            transition: background 0.15s;
        }
        .report-btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .report-btn-primary:hover { background: #1d4ed8; }
        .report-btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .report-btn-secondary:hover { background: #d1d5db; }
        .dark .report-btn-secondary {
            background: #374151;
            color: #e5e7eb;
        }
        .dark .report-btn-secondary:hover { background: #4b5563; }

        .empty-state {
            background: #fff;
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 48px 24px;
            text-align: center;
            color: #6b7280;
        }
        .dark .empty-state {
            background: #1f2937;
            border-color: #4b5563;
            color: #9ca3af;
        }
        .empty-state code {
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 13px;
            color: #111827;
        }
        .dark .empty-state code {
            background: #374151;
            color: #f3f4f6;
        }
    </style>

    @if(count($reports) === 0)
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;margin:0 auto 12px;display:block;opacity:0.5;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
            </svg>
            <p style="margin:0 0 8px 0;font-size:16px;font-weight:500;">Отчётов пока нет</p>
            <p style="margin:0;font-size:13px;">
                Поместите HTML-файлы в папку<br>
                <code>storage/app/public/reports/</code><br>
                и они автоматически появятся здесь.
            </p>
        </div>
    @else
        <div class="reports-grid">
            @foreach($reports as $r)
                <div class="report-card">
                    <h3>{{ $r['title'] }}</h3>

                    @if($r['description'])
                        <p class="desc">{{ $r['description'] }}</p>
                    @endif

                    <div class="meta">
                        @if($r['report_date'])
                            <span title="Дата отчёта">📅 {{ $r['report_date']->format('d.m.Y') }}</span>
                        @else
                            <span title="Файл обновлён">🕓 {{ $r['mtime']->format('d.m.Y H:i') }}</span>
                        @endif
                        <span title="Размер файла">{{ number_format($r['size'] / 1024, 1, '.', '') }} КБ</span>
                    </div>

                    <div class="actions">
                        <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="report-btn report-btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            Открыть
                        </a>
                        <button type="button"
                                class="report-btn report-btn-secondary"
                                onclick="navigator.clipboard.writeText(window.location.origin + '{{ $r['url'] }}').then(() => { const orig = this.innerHTML; this.innerHTML = '✓ Скопировано'; setTimeout(() => { this.innerHTML = orig; }, 1500); })">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                            </svg>
                            Ссылка
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:24px;font-size:12px;color:#9ca3af;">
            💡 Файлы в папке <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;color:#111827;">storage/app/public/reports/</code> доступны по прямой ссылке всем, кто её знает (без авторизации).
        </div>
    @endif
</x-filament-panels::page>