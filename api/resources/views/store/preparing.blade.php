<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Preparing your download') }} · {{ config('mail.ecrivain.brand', 'Écrivain') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; background: #EEF1F6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Helvetica, Arial, sans-serif; color: #2B3446; }
        main { max-width: 520px; margin: 64px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 12px; overflow: hidden; }
        .band { background: #10223D; padding: 26px 32px; }
        .eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 2.6px; text-transform: uppercase; color: #93B1F2; }
        .subtitle { margin-top: 6px; font-family: Georgia, 'Times New Roman', serif; font-size: 24px; color: #fff; }
        .body { padding: 28px 32px 32px; }
        h1 { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-weight: 400; font-size: 22px; color: #10223D; }
        p { line-height: 1.6; }
        @media (prefers-color-scheme: dark) {
            body { background: #0B1220; color: #D5DCE8; }
            .card { background: #141D2E; }
            h1 { color: #F1F4FA; }
        }
    </style>
</head>
<body>
    <main>
        <div class="card">
            <div class="band">
                <div class="eyebrow">{{ config('mail.ecrivain.brand', 'Écrivain') }}</div>
                <div class="subtitle">{{ __('Your download') }}</div>
            </div>
            <div class="body">
                <h1>{{ __('Your :format is being prepared', ['format' => $format]) }}</h1>
                <p>{{ __('“:title” is being generated again for you. This usually takes a few minutes. Use the same link again shortly — it keeps working until it expires.', ['title' => $title]) }}</p>
            </div>
        </div>
    </main>
</body>
</html>
