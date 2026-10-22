<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Unsubscribed') }} · {{ $brand }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; background: #EEF1F6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Helvetica, Arial, sans-serif; color: #2B3446; }
        main { max-width: 520px; margin: 64px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 12px; overflow: hidden; }
        .band { background: #10223D; padding: 26px 32px; }
        .eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 2.6px; text-transform: uppercase; color: #93B1F2; }
        .subtitle { margin-top: 6px; font-family: Georgia, 'Times New Roman', serif; font-size: 24px; color: #fff; }
        .body { padding: 28px 32px 32px; }
        h1 { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-weight: 400; font-size: 22px; color: #10223D; }
        p { line-height: 1.6; }
        a { color: #1F56E0; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <div class="card">
            <div class="band">
                <div class="eyebrow">{{ $brand }}</div>
                <div class="subtitle">{{ __('Email preferences') }}</div>
            </div>
            <div class="body">
                <h1>{{ __('You are unsubscribed') }}</h1>
                <p>{{ __('We will not send you “:label” emails any more. Security and billing emails still arrive, because they are how you hear about your account.', ['label' => __($label)]) }}</p>
                <p><a href="{{ $preferencesUrl }}">{{ __('Change your email preferences') }}</a></p>
            </div>
        </div>
    </main>
</body>
</html>
