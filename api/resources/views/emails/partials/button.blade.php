{{--
    The one call to action. Brand blue, always — severity never recolours it.

    A bordered <a> inside a bgcolor cell: the cell paints the colour in
    Outlook (which ignores padding on links), the link's own padding and
    border make the whole button clickable everywhere else.

    Required: $url, $label
--}}
<table role="presentation" class="em-btn-wrap" cellspacing="0" cellpadding="0" border="0" style="margin: 0;">
    <tr>
        <td align="center" bgcolor="#2166F5" style="background-color: #2166F5; border-radius: 6px;">
            <a href="{{ $url }}" class="em-button" target="_blank" rel="noopener"
               style="display: inline-block; padding: 13px 30px; border: 1px solid #2166F5; border-radius: 6px; background-color: #2166F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 20px; color: #FFFFFF; text-decoration: none;">{{ $label }}</a>
        </td>
    </tr>
</table>
