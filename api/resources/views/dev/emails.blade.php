<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Email gallery · Écrivain</title>
    <style>
        body { margin: 0; background: #F5F7FB; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, Helvetica, Arial, sans-serif; color: #2B3446; }
        header { background: #10223D; color: #fff; padding: 28px 32px; }
        header .eyebrow { font-size: 11px; font-weight: 700; letter-spacing: 2.6px; text-transform: uppercase; color: #93B1F2; }
        header h1 { margin: 6px 0 0; font-family: Georgia, serif; font-weight: 400; font-size: 28px; }
        header p { margin: 8px 0 0; color: #A3AEC4; font-size: 14px; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px 64px; }
        h2 { font-family: Georgia, serif; font-weight: 400; font-size: 20px; color: #10223D; margin: 32px 0 12px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E6E9F0; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid #EEF0F5; font-size: 13px; vertical-align: top; }
        th { background: #F5F7FB; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #5B6476; }
        td .subject { font-weight: 600; color: #10223D; }
        td .pre { color: #6B7488; margin-top: 2px; }
        a { color: #1F56E0; font-weight: 600; }
        .sev { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
        .sev-action { background: #2166F5; } .sev-confirmed { background: #22AD7B; } .sev-heads { background: #F0A10B; } .sev-urgent { background: #E5484D; } .sev-info { background: #737C8F; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; background: #EEF0F5; color: #4A5366; }
        .pill.on { background: #E4F5EB; color: #12795A; }
    </style>
</head>
<body>
    <header>
        <div class="eyebrow">Écrivain · local only</div>
        <h1>Email gallery</h1>
        <p>Every transactional email with sample data. Nothing here is sent. Append <code>?title=…</code> to a preview to try a different book title.</p>
    </header>
    <main>
        @foreach ($groups as $group => $emails)
            <h2>{{ $group }}</h2>
            <table>
                <thead>
                    <tr><th>Email</th><th>Subject and preheader</th><th>Preference</th><th>Delivery</th><th>Trigger</th><th>View</th></tr>
                </thead>
                <tbody>
                    @foreach ($emails as $email)
                        <tr>
                            <td><span class="sev sev-{{ $email['severity'] }}" title="{{ $email['severity'] }}"></span>{{ $email['label'] }}</td>
                            <td><div class="subject">{{ $email['subject'] }}</div><div class="pre">{{ $email['preheader'] }}</div></td>
                            <td>{{ $email['category'] }} <span class="pill {{ $email['mandatory'] ? 'on' : '' }}">{{ $email['mandatory'] ? 'always' : 'toggle' }}</span></td>
                            <td>{{ $email['queued'] ? 'Queued' : 'Immediate' }}</td>
                            <td>{{ $email['trigger'] }}</td>
                            <td><a href="{{ route('dev.emails.show', $email['slug']) }}">HTML</a> · <a href="{{ route('dev.emails.show', ['name' => $email['slug'], 'format' => 'text']) }}">Text</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </main>
</body>
</html>
