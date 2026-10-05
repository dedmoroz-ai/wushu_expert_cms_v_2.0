<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>AI-аналитика: {{ $competition->name }}</title>
<meta name="description" content="Судейская коллегия и разбор пулов: {{ $metrics['totals']['completed'] }} выступлений, {{ $metrics['totals']['judges'] }} судей.">
<meta name="report-date" content="{{ $competition->start_date?->format('Y-m-d') }}">
<style>
  @page { size: A4; margin: 20mm; }
  body { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; color: #222; line-height: 1.55; margin: 0; background: #fff; }
  .report-shell { max-width: 900px; margin: 30px auto; padding: 0 20px; }
  /* Шапка (светлая, как в админке и в отчёте 02.05): логотип слева. */
  .report-header { background: #fff; padding: 20px 32px; border-bottom: 1px solid #e4e4e7; display: flex; align-items: center; gap: 16px; }
  .report-header img { height: 60px; width: auto; display: block; }
  /* Футер — единая строка заказчика (04.10):
     «WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM 3.0 © 2026 МАКС МОРОЗ (logo)».
     Светлая полоса 50px, как light-тема админки (AppFooterTest). */
  .app-footer { min-height: 50px; box-sizing: border-box; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 0.5rem; padding: 8px 1.5rem; background: #f9fafb; color: #030712; border-top: 1px solid rgba(0, 0, 0, 0.1); font-size: 12px; }
  .app-footer__copy { display: inline-flex; align-items: center; gap: 0.5rem; }
  .app-footer img { height: 22px; width: auto; }
  h1 { text-align: center; color: #1a3a6c; border-bottom: 3px solid #1a3a6c; padding-bottom: 10px; margin-bottom: 25px; }
  h2 { color: #1a3a6c; border-left: 5px solid #1a3a6c; padding-left: 10px; margin-top: 35px; }
  h3 { color: #2a5599; margin-top: 25px; }
  p.meta { text-align: center; color: #555; margin-top: -10px; margin-bottom: 30px; }
  table { border-collapse: collapse; width: 100%; margin: 15px 0 25px; font-size: 14px; page-break-inside: avoid; }
  th, td { border: 1px solid #b8c5d6; padding: 8px 10px; text-align: left; vertical-align: middle; }
  th { background: #1a3a6c; color: #fff; font-weight: 600; }
  tr:nth-child(even) td { background: #f3f6fb; }
  td.center, th.center { text-align: center; }
  .highlight { background: #fff7d6 !important; font-weight: bold; }
  ul, ol { margin: 10px 0 15px 25px; }
  li { margin-bottom: 6px; }
  blockquote { border-left: 4px solid #2a5599; background: #eaf1fb; padding: 10px 15px; margin: 15px 0; color: #1a3a6c; border-radius: 4px; }
  .footer-note { margin-top: 40px; font-size: 13px; color: #555; border-top: 1px solid #ccc; padding-top: 15px; }
  @media print {
    body { margin: 0; }
    .report-shell { margin: 0 auto; padding: 0; }
    .report-header { padding: 10px 0; border-bottom: 1px solid #1a3a6c; }
    .report-header img { height: 50px; }
    h2 { page-break-after: avoid; }
    table { page-break-inside: avoid; }
  }
</style>
</head>
<body>

<header class="report-header">
  <img src="{{ asset('images/logo.png') }}" alt="WUSHU EXPERT">
</header>

<div class="report-shell">

<h1>AI-аналитика: {{ $competition->name }}</h1>
<p class="meta">
  Объект анализа: сводная оценок судей ({{ $metrics['totals']['completed'] }} выступлений, {{ $metrics['totals']['judges'] }} судей, {{ $metrics['totals']['scores'] }} оценок)<br>
  Турнир: {{ $competition->datesLabel() }}@if($competition->city), {{ $competition->city }}@endif ·
  Схема судейства: {{ $competition->isAbScheme() ? 'A/B' : 'простая (R-4.6)' }}<br>
  Сгенерировано: {{ $generatedAt->format('d.m.Y H:i') }}
</p>

@php
    $fmt = fn ($v, $d = 3) => $v === null ? '—' : number_format($v, $d, ',', '');
    $sign = fn ($v) => $v === null ? '—' : ($v > 0 ? '+' : '').number_format($v, 3, ',', '');
@endphp

<h2>1. Общая характеристика судейской коллегии</h2>

@if(trim($summary['overview']) !== '')
    <blockquote>{{ $summary['overview'] }}</blockquote>
@endif

<h3>Таблица 1. Показатели по судьям</h3>
<table>
  <thead>
    <tr>
      <th class="center">Код</th>
      <th>Судья</th>
      <th class="center">Средний балл</th>
      <th class="center">Δ от среднего по строке</th>
      <th class="center">СКО Δ</th>
      <th class="center">Отброшено макс. / мин.</th>
      <th>Комментарий аналитика</th>
    </tr>
  </thead>
  <tbody>
    @foreach($metrics['judges'] as $judge)
      @php $comment = collect($summary['judges'])->firstWhere('code', $judge['code'])['comment'] ?? ''; @endphp
      <tr @if($loop->first)class="highlight"@endif>
        <td class="center">{{ $judge['code'] }}</td>
        <td>{{ $judge['name'] }}</td>
        <td class="center">{{ $fmt($judge['mean']) }}</td>
        <td class="center">{{ $sign($judge['delta']) }}</td>
        <td class="center">{{ $fmt($judge['delta_std']) }}</td>
        <td class="center">{{ $judge['drop_max'] }} / {{ $judge['drop_min'] }}</td>
        <td>{{ $comment }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<p>
  Методика: Δ — среднее отклонение оценки судьи от итогового среднего строки (trimmedMean R-4.6:
  при 3+ оценках отбрасываются одна минимальная и одна максимальная); СКО Δ — непоследовательность судьи;
  «отброшено» — сколько раз оценка судьи была крайней и не вошла в расчёт.
</p>

<!--AI-SECTIONS-->

<h2>2. Разброс оценок в выступлениях</h2>

<p>
  Средний разброс (максимум − минимум оценок) — {{ $fmt($metrics['spread']['mean']) }},
  медиана — {{ $fmt($metrics['spread']['median']) }}, максимум — {{ $fmt($metrics['spread']['max']) }}.
  Выступлений с разбросом ≥ 0,7: {{ $metrics['spread']['ge_07'] }} из {{ $metrics['totals']['completed'] }}.
</p>

<h3>Таблица 2. Наиболее спорные выступления</h3>
<table>
  <thead>
    <tr>
      <th class="center">Код</th>
      <th>Спортсмен</th>
      <th>Дисциплина</th>
      <th>Возрастная группа</th>
      <th class="center">Разброс</th>
      <th class="center">Минимум</th>
      <th class="center">Максимум</th>
      <th class="center">Итог</th>
    </tr>
  </thead>
  <tbody>
    @foreach($metrics['topSpreads'] as $row)
      <tr @if($loop->first)class="highlight"@endif>
        <td class="center">{{ $row['athlete_code'] }}</td>
        <td>{{ $row['athlete'] }}</td>
        <td>{{ $row['style'] }}</td>
        <td>{{ $row['age_group'] }}</td>
        <td class="center">{{ $fmt($row['spread']) }}</td>
        <td class="center">{{ $fmt($row['min_score']) }} ({{ $row['min_judge'] }})</td>
        <td class="center">{{ $fmt($row['max_score']) }} ({{ $row['max_judge'] }})</td>
        <td class="center">{{ $fmt($row['final']) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<h2>3. Пулы: дисциплина × возрастная группа</h2>

<p>
  Содержательных пулов (3 и более выступления): {{ count(array_filter($metrics['pools'], fn ($p) => $p['n'] >= 3)) }}.
  Места присуждены по официальному итоговому баллу протокола; «ср. разброс» — средний разброс судей внутри выступлений пула.
</p>

<h3>Таблица 3. Сводка по пулам</h3>
<table>
  <thead>
    <tr>
      <th>Пул</th>
      <th class="center">N</th>
      <th class="center">Итоги (ср. ± СКО)</th>
      <th class="center">Ср. разброс судей</th>
      <th class="center">Отрыв 1-го от 2-го</th>
    </tr>
  </thead>
  <tbody>
    @foreach($metrics['pools'] as $pool)
      <tr @if(in_array($pool['key'], $metrics['focusKeys'], true))class="highlight"@endif>
        <td>{{ $pool['key'] }}</td>
        <td class="center">{{ $pool['n'] }}</td>
        <td class="center">{{ $fmt($pool['mean_final']) }} ± {{ $fmt($pool['std_final']) }}</td>
        <td class="center">{{ $fmt($pool['mean_spread']) }}</td>
        <td class="center">{{ $fmt($pool['gap']) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<h2>4. Разбор фокусных пулов</h2>

<p>Фокусные пулы — самые «спорные» по судейству (по среднему разбросу оценок) среди содержательных. Комментарии — аналитика LLM.</p>

@php $focusComment = collect($summary['focus_pools']); @endphp
@foreach($metrics['pools'] as $pool)
    @if(in_array($pool['key'], $metrics['focusKeys'], true))
        <h3>{{ $pool['key'] }} (N = {{ $pool['n'] }})</h3>
        @php $comment = $focusComment->firstWhere('key', $pool['key'])['comment'] ?? ''; @endphp
        @if(trim($comment) !== '')<blockquote>{{ $comment }}</blockquote>@endif
        <table>
          <thead>
            <tr>
              <th class="center">Место</th>
              <th class="center">Код</th>
              <th>Спортсмен</th>
              <th class="center">Итог</th>
              <th class="center">Разброс</th>
              <th class="center">Минимум</th>
              <th class="center">Максимум</th>
            </tr>
          </thead>
          <tbody>
            @foreach($pool['athletes'] as $athlete)
              <tr @if($athlete['place'] === 1)class="highlight"@endif>
                <td class="center">{{ $athlete['place'] }}</td>
                <td class="center">{{ $athlete['athlete_code'] }}</td>
                <td>{{ $athlete['athlete'] }}</td>
                <td class="center">{{ $fmt($athlete['final']) }}</td>
                <td class="center">{{ $fmt($athlete['spread']) }}</td>
                <td class="center">{{ $fmt($athlete['min_score']) }} ({{ $athlete['min_judge'] }})</td>
                <td class="center">{{ $fmt($athlete['max_score']) }} ({{ $athlete['max_judge'] }})</td>
              </tr>
            @endforeach
          </tbody>
        </table>
    @endif
@endforeach

<h2>5. Замечания к протоколам</h2>

@if(count($metrics['flips']) > 0)
    <h3>5.1. Где отсечение крайних оценок (R-4.6) изменило расстановку топ-3</h3>
    <ul>
      @foreach($metrics['flips'] as $flip)
        <li>
          <b>{{ $flip['key'] }}</b> — по R-4.6: {{ implode(', ', $flip['trim_top']) }};
          при среднем по всем оценкам: {{ implode(', ', $flip['plain_top']) }}.
        </li>
      @endforeach
    </ul>
@else
    <p>Пулов, где отсечение крайних оценок меняло бы топ-3, не обнаружено.</p>
@endif

@if(count($metrics['mismatches']) > 0)
    <h3>5.2. Расхождения официального итога с авто-расчётом R-4.6</h3>
    <table>
      <thead>
        <tr>
          <th class="center">Код</th>
          <th>Спортсмен</th>
          <th>Пул</th>
          <th class="center">Авто-расчёт</th>
          <th class="center">Официальный итог</th>
          <th class="center">Отклонение</th>
        </tr>
      </thead>
      <tbody>
        @foreach($metrics['mismatches'] as $row)
          <tr>
            <td class="center">{{ $row['athlete_code'] }}</td>
            <td>{{ $row['athlete'] }}</td>
            <td>{{ $row['style'] }} — {{ $row['age_group'] }}</td>
            <td class="center">{{ $fmt($row['auto']) }}</td>
            <td class="center">{{ $fmt($row['final']) }}</td>
            <td class="center">{{ $sign($row['diff']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
@else
    <p>Официальные итоги совпадают с авто-расчётом R-4.6 по всем выступлениям.</p>
@endif

<h2>6. Выводы и рекомендации</h2>

@if(count($summary['conclusions']) > 0)
    <h3>Выводы</h3>
    <ol>
      @foreach($summary['conclusions'] as $item)<li>{{ $item }}</li>@endforeach
    </ol>
@endif

@if(count($summary['recommendations']) > 0)
    <h3>Рекомендации</h3>
    <ol>
      @foreach($summary['recommendations'] as $item)<li>{{ $item }}</li>@endforeach
    </ol>
@endif

<p class="footer-note">
  Документ сгенерирован автоматически: числа рассчитаны детерминированно по данным протоколов
  ({{ $metrics['totals']['scores'] }} оценок, {{ $metrics['totals']['judges'] }} судей), текстовая часть — языковой моделью.<br>
  Методика: итоговая оценка — среднее с отбрасыванием одной минимальной и одной максимальной (R-4.6);
  Δ судьи — среднее его отклонения от итогового среднего строки; разброс выступления — максимум минус минимум оценок судей.<br>
  Для сохранения в PDF: <b>Ctrl + P → Сохранить как PDF</b>.
</p>

</div><!-- /.report-shell -->

<footer class="app-footer">
  <span>WUSHU EXPERT COMPETITION MANAGEMENT SYSTEM {{ config('app.version') }}</span>
  <span class="app-footer__copy">
    <span>&copy; 2026</span>
    <span>МАКС МОРОЗ</span>
    <img src="{{ asset('images/d989.svg') }}" alt="Max Moroz">
  </span>
</footer>

</body>
</html>