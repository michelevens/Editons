@extends('emails.layout')

{{--
    The body every Écrivain email shares: the Letter's blocks in the order
    the Mailable added them, then the one button, a secondary text link, and
    at most one callout. Eyebrow, headline and lede are drawn by the layout.

    The plain-text twin is emails/text/standard — the same Letter, so a block
    added here must be added there too (EmailRenderTest checks both render
    every block type).
--}}

@section('content')
    @foreach ($letter->blocks as $block)
        @switch($block['type'])
            @case('paragraph')
                <p class="em-body" style="margin: 16px 0 0; font-size: 16px; line-height: 1.6; color: #2B3446;">{{ $block['text'] }}</p>
                @break
            @case('facts')
                @include('emails.partials.facts', ['rows' => $block['rows'], 'title' => $block['title']])
                @break
            @case('stats')
                @include('emails.partials.stats', ['items' => $block['items']])
                @break
            @case('items')
                @include('emails.partials.items', ['items' => $block['items'], 'title' => $block['title'], 'empty' => $block['empty']])
                @break
            @case('table')
                @include('emails.partials.table', $block)
                @break
            @case('quote')
                @include('emails.partials.quote', ['text' => $block['text'], 'cite' => $block['cite']])
                @break
            @case('book')
                @include('emails.partials.book-card', $block)
                @break
            @case('divider')
                @include('emails.partials.divider')
                @break
        @endswitch
    @endforeach

    @if ($letter->cta)
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 28px 0 0; width: 100%;">
            <tr>
                <td align="left">
                    @include('emails.partials.button', $letter->cta)
                </td>
            </tr>
        </table>
        @if ($letter->showFallbackUrl)
            <p class="em-muted" style="margin: 14px 0 0; font-size: 12px; line-height: 1.55; color: #6B7488;">
                {{ __('If the button does not work, copy this address into your browser:') }}<br>
                <a class="em-link" href="{{ $letter->cta['url'] }}" style="color: #1F56E0; text-decoration: underline; word-break: break-all;">{{ $letter->cta['url'] }}</a>
            </p>
        @endif
    @endif

    @if ($letter->secondary)
        <p style="margin: {{ $letter->cta ? '14px' : '24px' }} 0 0; font-size: 14px; line-height: 1.5;">
            <a class="em-link" href="{{ $letter->secondary['url'] }}" style="color: #1F56E0; text-decoration: underline; font-weight: 600;">{{ $letter->secondary['label'] }}</a>
        </p>
    @endif

    @if ($letter->callout)
        @include('emails.partials.callout', $letter->callout)
    @endif
@endsection
