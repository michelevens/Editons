{{--
    Columns and rows, for receipts and statements. The first column is the
    item, the last an amount (right-aligned); the columns between are hidden
    on a phone so the two that matter keep their width. `total` is a bold
    closing row.

    Required: $columns, $rows
    Optional: $title, $total
--}}
@php
    $last = count($columns) - 1;
    $pad = fn (int $i, string $v) => "{$v} ".($i === $last ? '18px' : '8px')." {$v} ".($i === 0 ? '18px' : '8px');
@endphp
<table role="presentation" class="em-panel" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 24px 0 0; width: 100%; border: 1px solid #E6E9F0; border-radius: 10px; border-collapse: separate; background-color: #FFFFFF;">
    @if (!empty($title))
        <tr>
            <td colspan="{{ count($columns) }}" class="em-panel-head" bgcolor="#F5F7FB" style="background-color: #F5F7FB; border-bottom: 1px solid #E6E9F0; border-radius: 10px 10px 0 0; padding: 10px 18px; font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #5B6476;">{{ $title }}</td>
        </tr>
    @endif
    <tr>
        @foreach ($columns as $i => $column)
            <th scope="col" class="em-muted em-line {{ ($i > 0 && $i < $last) ? 'em-hide-sm' : '' }}" align="{{ $i === $last ? 'right' : 'left' }}" style="padding: {{ $pad($i, '10px') }}; border-bottom: 1px solid #EEF0F5; font-size: 12px; font-weight: 600; line-height: 1.4; color: #6B7488; text-align: {{ $i === $last ? 'right' : 'left' }};">{{ $column }}</th>
        @endforeach
    </tr>
    @foreach ($rows as $row)
        <tr>
            @foreach (array_values($row) as $i => $cell)
                <td class="{{ $i === 0 ? 'em-ink' : 'em-body' }} em-line {{ ($i > 0 && $i < $last) ? 'em-hide-sm' : '' }}" valign="top" align="{{ $i === $last ? 'right' : 'left' }}" style="padding: {{ $pad($i, '10px') }}; border-bottom: 1px solid #EEF0F5; font-size: 14px; line-height: 1.45; color: {{ $i === 0 ? '#10223D' : '#2B3446' }}; text-align: {{ $i === $last ? 'right' : 'left' }};{{ $i === $last ? ' white-space: nowrap;' : '' }}">{{ $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    @if (!empty($total))
        <tr>
            @foreach (array_values($total) as $i => $cell)
                <td class="em-ink {{ ($i > 0 && $i < $last) ? 'em-hide-sm' : '' }}" align="{{ $i === $last ? 'right' : 'left' }}" style="padding: {{ $pad($i, '12px') }}; font-size: 15px; font-weight: 700; line-height: 1.45; color: #10223D; text-align: {{ $i === $last ? 'right' : 'left' }}; white-space: nowrap;">{{ $cell }}</td>
            @endforeach
        </tr>
    @endif
</table>
