{{--
    One big number. emails.partials.stats lays several in a row on desktop
    and stacks them on a phone.

    Required: $label, $value
    Optional: $tone (default | success | warning | danger)
--}}
@php
    $ink = ['default' => '#10223D', 'success' => '#12795A', 'warning' => '#8A4B00', 'danger' => '#B4232A'][$tone ?? 'default'] ?? '#10223D';
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; border-collapse: separate;">
    <tr>
        <td class="em-panel" bgcolor="#F5F7FB" style="background-color: #F5F7FB; border: 1px solid #E6E9F0; border-radius: 10px; padding: 14px 16px; text-align: left;">
            <p class="em-muted" style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; line-height: 16px; color: #5B6476;">{{ $label }}</p>
            <p class="em-ink serif" style="margin: 4px 0 0; font-family: Georgia, 'Times New Roman', serif; font-size: 26px; line-height: 1.15; color: {{ $ink }};">{{ $value }}</p>
        </td>
    </tr>
</table>
