{{--
    A row of stat cards: table cells on desktop, stacked on a phone.

    Required: $items (list of [label, value, tone?])
--}}
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%;">
    <tr>
        @foreach ($items as $item)
            <td class="em-stat" valign="top" width="{{ (int) floor(100 / max(1, count($items))) }}%" style="padding: 0 {{ $loop->last ? '0' : '8px' }} 0 0;">
                @include('emails.partials.stat-card', ['label' => $item['label'], 'value' => $item['value'], 'tone' => $item['tone'] ?? 'default'])
            </td>
        @endforeach
    </tr>
</table>
