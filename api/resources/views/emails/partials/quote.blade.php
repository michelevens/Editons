{{--
    Somebody's words: a comment, or the passage it is about.

    Required: $text
    Optional: $cite
--}}
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 22px 0 0; width: 100%; border-collapse: separate;">
    <tr>
        <td class="em-panel" bgcolor="#F5F7FB" style="background-color: #F5F7FB; border-left: 3px solid #93B1F2; border-radius: 0 8px 8px 0; padding: 14px 18px;">
            <p class="em-body serif" style="margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 16px; line-height: 1.55; color: #2B3446; white-space: pre-line;">{{ $text }}</p>
            @if (!empty($cite))
                <p class="em-muted" style="margin: 8px 0 0; font-size: 13px; line-height: 1.4; color: #5B6476;">&mdash; {{ $cite }}</p>
            @endif
        </td>
    </tr>
</table>
