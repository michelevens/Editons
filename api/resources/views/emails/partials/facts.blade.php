{{--
    Label/value rows as a two-cell table. Never float — Outlook's Word engine
    ignores it and the label and value collide.

    Required: $rows (label => value)
    Optional: $title
--}}
@if (count($rows))
<table role="presentation" class="em-panel" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border: 1px solid #E6E9F0; border-radius: 10px; border-collapse: separate; background-color: #FFFFFF;">
    @if (!empty($title))
        <tr>
            <td colspan="2" class="em-panel-head" bgcolor="#F5F7FB" style="background-color: #F5F7FB; border-bottom: 1px solid #E6E9F0; border-radius: 10px 10px 0 0; padding: 10px 18px; font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #5B6476;">{{ $title }}</td>
        </tr>
    @endif
    @foreach ($rows as $label => $value)
        <tr>
            <td class="em-muted em-line" valign="top" style="padding: 11px 8px 11px 18px; {{ $loop->last ? '' : 'border-bottom: 1px solid #EEF0F5;' }} font-size: 14px; line-height: 1.45; color: #6B7488; width: 42%;">{{ $label }}</td>
            <td class="em-ink em-line" valign="top" align="right" style="padding: 11px 18px 11px 8px; {{ $loop->last ? '' : 'border-bottom: 1px solid #EEF0F5;' }} font-size: 14px; line-height: 1.45; font-weight: 600; color: #10223D; text-align: right; word-break: break-word;">{{ $value }}</td>
        </tr>
    @endforeach
</table>
@endif
