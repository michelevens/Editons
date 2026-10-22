{{--
    A small status pill. Tone carries meaning, so there is no brand default:
    a badge with no tone is neutral grey, never blue.

    Required: $text
    Optional: $tone (neutral | info | success | warning | danger)
--}}
@php
    $b = [
        'neutral' => ['#EEF0F5', '#4A5366'],
        'info'    => ['#E5EDFC', '#1D3FA8'],
        'success' => ['#E4F5EB', '#12795A'],
        'warning' => ['#FFF3DD', '#8A4B00'],
        'danger'  => ['#FDE9EA', '#B4232A'],
    ][$tone ?? 'neutral'] ?? ['#EEF0F5', '#4A5366'];
@endphp
<span style="display: inline-block; padding: 2px 9px; border-radius: 999px; background-color: {{ $b[0] }}; color: {{ $b[1] }}; font-size: 11px; font-weight: 700; line-height: 18px; letter-spacing: 0.4px; text-transform: uppercase; white-space: nowrap;">{{ $text }}</span>
