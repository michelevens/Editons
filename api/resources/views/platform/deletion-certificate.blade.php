<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $c['title'] }} — {{ $c['certificate_id'] }}</title>
    <style>
        body { font: 14px/1.5 system-ui, sans-serif; color: #1b1f24; background: #fff; max-width: 760px; margin: 32px auto; padding: 0 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 24px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; padding: 4px 8px 4px 0; border-top: 1px solid #e3e6ea; vertical-align: top; }
        code { font-size: 12px; word-break: break-all; }
        .muted { color: #5b6470; }
    </style>
</head>
<body>
    <h1>{{ $c['title'] }}</h1>
    <p class="muted">Écrivain · certificate {{ $c['certificate_id'] }} · issued {{ $c['issued_at'] }}</p>

    <h2>Account</h2>
    <table>
        <tr><th>Account id</th><td>{{ $c['account']['id'] }}</td></tr>
        <tr><th>Email (keyed hash)</th><td><code>{{ $c['account']['email_sha256_hmac'] }}</code></td></tr>
        <tr><th>Reason</th><td>{{ $c['request']['reason'] }}</td></tr>
        <tr><th>Requested</th><td>{{ $c['request']['requested_at'] }} by staff #{{ $c['request']['requested_by'] }}</td></tr>
        <tr><th>Grace period</th><td>{{ $c['request']['grace_days'] }} days, until {{ $c['request']['scheduled_for'] }}</td></tr>
        <tr><th>Executed</th><td>{{ $c['executed']['completed_at'] }} by staff #{{ $c['executed']['by'] }}</td></tr>
    </table>

    <h2>Deleted</h2>
    <table>
        @foreach ($c['deleted'] as $table => $rows)
            <tr><td>{{ $table }}</td><td>{{ $rows }}</td></tr>
        @endforeach
        @foreach ($c['cascaded_with_books'] as $table => $rows)
            <tr><td>{{ $table }} <span class="muted">(with the books)</span></td><td>{{ $rows }}</td></tr>
        @endforeach
        <tr><td>files</td><td>{{ $c['files']['deleted'] }} deleted{{ count($c['files']['failed']) ? ', '.count($c['files']['failed']).' could not be deleted' : '' }}</td></tr>
    </table>

    <h2>Anonymised</h2>
    <table>
        @foreach ($c['anonymised'] as $table => $rows)
            <tr><td>{{ $table }}</td><td>{{ $rows }}</td></tr>
        @endforeach
    </table>

    <h2>Retained, and why</h2>
    <table>
        @foreach ($c['retained'] as $table => $row)
            <tr><td>{{ $table }}</td><td>{{ $row['rows'] }}</td><td class="muted">{{ $row['why'] }}</td></tr>
        @endforeach
    </table>

    <h2>Integrity</h2>
    <p>SHA-256 of the canonical JSON (keys sorted, no whitespace): <code>{{ $sha256 }}</code></p>
</body>
</html>
