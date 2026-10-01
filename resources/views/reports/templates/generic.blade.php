<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { size: A4; margin: 0.8in 0.8in 0.9in 0.8in; }
    * { box-sizing: border-box; }
    body { font-family: "Liberation Sans", Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.5; color: #1a1a1a; margin: 0; }
    header.report-head { border-bottom: 2px solid #1f3a5f; padding-bottom: 10pt; margin-bottom: 16pt; }
    header.report-head h1 { font-size: 20pt; line-height: 1.25; margin: 0 0 4pt 0; color: #1f3a5f; }
    header.report-head .meta { font-size: 9pt; color: #666; }
    section { margin-bottom: 16pt; }
    section > h2 { font-size: 14pt; color: #1f3a5f; margin: 18pt 0 8pt 0; padding-bottom: 3pt; border-bottom: 1px solid #c9d3df; page-break-after: avoid; }
    h3 { font-size: 11.5pt; margin: 12pt 0 4pt 0; page-break-after: avoid; }
    p { margin: 0 0 8pt 0; }
    ul { margin: 0 0 8pt 0; padding-left: 18pt; }
    li { margin-bottom: 2pt; }
    table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt 0; font-size: 10pt; page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th, td { border: 1px solid #c9d3df; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    th { background: #eef2f7; font-weight: bold; }
</style>
</head>
<body>
<header class="report-head">
    <h1>{{ $title }}</h1>
    <div class="meta">{{ $generatedLabel }}: {{ $generatedAt }} · v{{ $versionNo }}</div>
</header>
@foreach ($sections as $section)
    <section id="section-{{ $section['key'] }}">
        <h2>{{ $section['title'] }}</h2>
        {!! $section['html'] !!}
    </section>
@endforeach
</body>
</html>
