<x-filament-panels::page>
    <style>
        .wushu-doc {
            max-width: 72rem;
            font-size: 15px;
            line-height: 1.65;
            color: #111827;
            overflow-x: auto;
        }
        .dark .wushu-doc { color: #e5e7eb; }

        .wushu-doc h1,
        .wushu-doc h2,
        .wushu-doc h3,
        .wushu-doc h4 {
            scroll-margin-top: 6rem;
            font-weight: 700;
            color: #111827;
        }
        .dark .wushu-doc h1,
        .dark .wushu-doc h2,
        .dark .wushu-doc h3,
        .dark .wushu-doc h4 { color: #f3f4f6; }

        .wushu-doc h1 { margin: 0 0 12px; font-size: 26px; }
        .wushu-doc h2 {
            margin: 28px 0 12px;
            padding-bottom: 6px;
            font-size: 21px;
            border-bottom: 1px solid #e5e7eb;
        }
        .dark .wushu-doc h2 { border-color: #374151; }
        .wushu-doc h3 { margin: 22px 0 8px; font-size: 17px; }
        .wushu-doc h4 { margin: 18px 0 6px; font-size: 15px; }

        .wushu-doc p { margin: 10px 0; }

        .wushu-doc ul,
        .wushu-doc ol { margin: 10px 0; padding-left: 22px; }
        .wushu-doc ul { list-style: disc; }
        .wushu-doc ol { list-style: decimal; }
        .wushu-doc li { margin: 4px 0; }

        .wushu-doc a { color: #0284c7; text-decoration: underline; }
        .dark .wushu-doc a { color: #38bdf8; }

        .wushu-doc table {
            width: 100%;
            margin: 14px 0;
            border-collapse: collapse;
            font-size: 14px;
        }
        .wushu-doc th,
        .wushu-doc td {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }
        .dark .wushu-doc th,
        .dark .wushu-doc td { border-color: #374151; }
        .wushu-doc th { background: #f3f4f6; font-weight: 600; }
        .dark .wushu-doc th { background: #1f2937; }

        .wushu-doc blockquote {
            margin: 14px 0;
            padding: 10px 16px;
            border-left: 4px solid #38bdf8;
            border-radius: 8px;
            background: #f0f9ff;
        }
        .dark .wushu-doc blockquote { background: rgba(56, 189, 248, 0.12); }

        .wushu-doc code {
            padding: 1px 6px;
            border-radius: 6px;
            background: #f3f4f6;
            font-size: 13px;
        }
        .dark .wushu-doc code { background: #1f2937; }

        .wushu-doc pre {
            margin: 14px 0;
            padding: 14px;
            border-radius: 10px;
            background: #f3f4f6;
            overflow-x: auto;
        }
        .dark .wushu-doc pre { background: #1f2937; }
        .wushu-doc pre code { padding: 0; background: transparent; }

        .wushu-doc hr {
            margin: 24px 0;
            border: 0;
            border-top: 1px solid #e5e7eb;
        }
        .dark .wushu-doc hr { border-color: #374151; }

        .wushu-doc strong { font-weight: 700; }
    </style>

    <div class="wushu-doc">
        {!! $this->getDoc() !!}
    </div>
</x-filament-panels::page>
