{{--
    A list: findings, books, stores, downloads. Each item has a title, an
    optional detail line, an optional status badge and an optional link.

    Required: $items
    Optional: $title, $empty (shown when there are no items)
--}}
<table role="presentation" class="em-panel" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border: 1px solid #E6E9F0; border-radius: 10px; border-collapse: separate; background-color: #FFFFFF;">
    @if (!empty($title))
        <tr>
            <td colspan="2" class="em-panel-head" bgcolor="#F5F7FB" style="background-color: #F5F7FB; border-bottom: 1px solid #E6E9F0; border-radius: 10px 10px 0 0; padding: 10px 18px; font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #5B6476;">{{ $title }}</td>
        </tr>
    @endif
    @forelse ($items as $item)
        <tr>
            <td class="em-line" valign="top" style="padding: 12px 8px 12px 18px; {{ $loop->last ? '' : 'border-bottom: 1px solid #EEF0F5;' }}">
                <p class="em-ink" style="margin: 0; font-size: 14px; font-weight: 600; line-height: 1.45; color: #10223D;">
                    @if (!empty($item['url']))
                        <a class="em-link" href="{{ $item['url'] }}" style="color: #1F56E0; text-decoration: underline;">{{ $item['title'] }}</a>
                    @else
                        {{ $item['title'] }}
                    @endif
                </p>
                @if (!empty($item['detail']))
                    <p class="em-muted" style="margin: 2px 0 0; font-size: 13px; line-height: 1.5; color: #6B7488;">{{ $item['detail'] }}</p>
                @endif
            </td>
            <td class="em-line" valign="top" align="right" style="padding: 12px 18px 12px 8px; {{ $loop->last ? '' : 'border-bottom: 1px solid #EEF0F5;' }} text-align: right; white-space: nowrap;">
                @if (!empty($item['badge']))
                    @include('emails.partials.status-badge', ['text' => $item['badge'], 'tone' => $item['tone'] ?? 'neutral'])
                @endif
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="2" class="em-muted" style="padding: 14px 18px; font-size: 14px; line-height: 1.5; color: #6B7488;">{{ $empty }}</td>
        </tr>
    @endforelse
</table>
