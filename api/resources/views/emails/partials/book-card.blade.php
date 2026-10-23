{{--
    The book an email is about: a small cover beside its title.

    The real cover when there is one: its thumbnail is embedded in the
    message (cid:), because the rendered cover is served behind sign-in and
    a linked picture the inbox cannot load is a broken box. Without it — a
    preview in the browser, where there is no message to embed into, or a
    cover that could not be drawn — the cover is set in the book's own
    palette, which reads as a cover in every client, images on or off.

    Required: $title, $from, $ink
    Optional: $subtitle, $author, $caption, $cover_path
--}}
@php
    $coverSrc = null;

    if (! empty($cover_path) && isset($message)) {
        try {
            $coverSrc = $message->embedData(\Illuminate\Support\Facades\Storage::disk('files')->get($cover_path), 'cover.jpg', 'image/jpeg');
        } catch (\Throwable $e) {
            $coverSrc = null;
        }
    }
@endphp
<table role="presentation" class="em-panel" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border: 1px solid #E6E9F0; border-radius: 10px; border-collapse: separate; background-color: #FFFFFF;">
    <tr>
        <td valign="top" width="88" style="width: 88px; padding: 14px 0 14px 14px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="74" style="width: 74px; border-collapse: separate;">
                <tr>
                    @if ($coverSrc)
                        <td width="74" valign="top" style="width: 74px; padding: 0;"><img src="{{ $coverSrc }}" width="74" alt="{{ __('Cover of :title', ['title' => $title]) }}" style="display: block; width: 74px; height: auto; border: 0; border-radius: 2px 6px 6px 2px; box-shadow: 0 2px 6px rgba(16, 34, 61, 0.18);"></td>
                    @else
                        <td bgcolor="{{ $from }}" height="104" valign="bottom" aria-hidden="true" style="height: 104px; background-color: {{ $from }}; border-radius: 2px 6px 6px 2px; border-left: 4px solid #0B1322; padding: 8px 7px; font-family: Georgia, 'Times New Roman', serif; font-size: 10px; line-height: 1.25; color: {{ $ink }}; word-break: break-word;">{{ \Illuminate\Support\Str::limit($title, 44) }}</td>
                    @endif
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
