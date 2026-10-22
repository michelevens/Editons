{{--
    The book an email is about: a small typographic cover beside its title.

    The cover is drawn in the book's own palette rather than shown as an
    image, because the rendered cover is served behind sign-in. A picture
    the inbox cannot load is a broken box; a coloured block with the title
    set in it is a cover in every client, with images on or off.

    Required: $title, $from, $ink
    Optional: $subtitle, $author, $caption
--}}
<table role="presentation" class="em-panel" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border: 1px solid #E6E9F0; border-radius: 10px; border-collapse: separate; background-color: #FFFFFF;">
    <tr>
        <td valign="top" width="88" style="width: 88px; padding: 14px 0 14px 14px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="74" style="width: 74px; border-collapse: separate;">
                <tr>
                    <td bgcolor="{{ $from }}" height="104" valign="bottom" aria-hidden="true" style="height: 104px; background-color: {{ $from }}; border-radius: 2px 6px 6px 2px; border-left: 4px solid #0B1322; padding: 8px 7px; font-family: Georgia, 'Times New Roman', serif; font-size: 10px; line-height: 1.25; color: {{ $ink }}; word-break: break-word;">{{ \Illuminate\Support\Str::limit($title, 44) }}</td>
                </tr>
            </table>
        </td>
        <td valign="middle" style="padding: 14px 18px 14px 14px;">
            @if (!empty($caption))
                <p class="em-muted" style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; line-height: 16px; color: #5B6476;">{{ $caption }}</p>
            @endif
            <p class="em-ink serif" style="margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 18px; line-height: 1.3; color: #10223D;">{{ $title }}</p>
            @if (!empty($subtitle))
                <p class="em-body" style="margin: 3px 0 0; font-size: 14px; line-height: 1.45; color: #2B3446;">{{ $subtitle }}</p>
            @endif
            @if (!empty($author))
                <p class="em-muted" style="margin: 6px 0 0; font-size: 13px; line-height: 1.45; color: #6B7488;">{{ __('by :author', ['author' => $author]) }}</p>
            @endif
        </td>
    </tr>
</table>
