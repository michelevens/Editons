{{--
    At most one per email, for a caveat the facts cannot carry: a link that
    expires, a security note, the date access stops.

    Required: $body
    Optional: $tone (info | success | warning | danger), $title
--}}
@php
    $c = [
        'info'    => ['bg' => '#F3F7FF', 'line' => '#B9CDF3', 'ink' => '#1D3FA8'],
        'success' => ['bg' => '#EDF8F1', 'line' => '#B7E3CB', 'ink' => '#12795A'],
        'warning' => ['bg' => '#FFF6E6', 'line' => '#F6D79B', 'ink' => '#8A4B00'],
        'danger'  => ['bg' => '#FDF1F2', 'line' => '#F5C2C4', 'ink' => '#B4232A'],
    ][$tone ?? 'info'] ?? ['bg' => '#F3F7FF', 'line' => '#B9CDF3', 'ink' => '#1D3FA8'];
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border-collapse: separate;">
    <tr>
        <td bgcolor="{{ $c['bg'] }}" style="background-color: {{ $c['bg'] }}; border: 1px solid {{ $c['line'] }}; border-left: 4px solid {{ $c['ink'] }}; border-radius: 8px; padding: 14px 18px;">
            @if (!empty($title))
                <p style="margin: 0 0 4px; font-size: 14px; font-weight: 700; line-height: 1.4; color: {{ $c['ink'] }};">{{ $title }}</p>
            @endif
            <p style="margin: 0; font-size: 14px; line-height: 1.55; color: #2B3446;">{{ $body }}</p>
        </td>
    </tr>
</table>
