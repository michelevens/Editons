{{--
    The plain-text alternative: the same Letter as emails/standard, set as
    text. Raw output ({!! !!}) is correct here and only here — this is
    text/plain, nothing interprets it as markup, and escaping would print
    "&amp;" into somebody's inbox.
--}}
{!! mb_strtoupper($brand) !!} — {!! $letter->subtitle !!}

@if ($letter->eyebrow)
{!! mb_strtoupper($letter->eyebrow) !!}
@endif
{!! $letter->headline !!}
{!! str_repeat('=', min(60, mb_strlen($letter->headline))) !!}
@if ($letter->lede)

{!! $letter->lede !!}
@endif
@foreach ($letter->blocks as $block)
@switch($block['type'])
@case('paragraph')

{!! $block['text'] !!}
@break
@case('facts')

@if ($block['title'])
{!! mb_strtoupper($block['title']) !!}
@endif
@foreach ($block['rows'] as $label => $value)
- {!! $label !!}: {!! $value !!}
@endforeach
@break
@case('stats')

@foreach ($block['items'] as $item)
- {!! $item['label'] !!}: {!! $item['value'] !!}
@endforeach
@break
@case('items')

@if ($block['title'])
{!! mb_strtoupper($block['title']) !!}
@endif
@forelse ($block['items'] as $item)
- {!! $item['title'] !!}@if (!empty($item['badge'])) [{!! $item['badge'] !!}]@endif

@if (!empty($item['detail']))
  {!! $item['detail'] !!}
@endif
@if (!empty($item['url']))
  {!! $item['url'] !!}
@endif
@empty
{!! $block['empty'] !!}
@endforelse
@break
@case('table')

@if ($block['title'])
{!! mb_strtoupper($block['title']) !!}
@endif
@foreach ($block['rows'] as $row)
- {!! implode(' · ', array_map('strval', array_filter(array_values($row), fn ($cell) => $cell !== null && $cell !== ''))) !!}
@endforeach
@if (!empty($block['total']))
  {!! implode(' · ', array_map('strval', array_filter(array_values($block['total']), fn ($cell) => $cell !== null && $cell !== ''))) !!}
@endif
@break
@case('quote')

  "{!! $block['text'] !!}"
@if ($block['cite'])
  — {!! $block['cite'] !!}
@endif
@break
@case('book')

{!! $block['caption'] ? mb_strtoupper($block['caption']).': ' : '' !!}{!! $block['title'] !!}@if ($block['author']) — {!! __('by :author', ['author' => $block['author']]) !!}@endif

@break
@case('divider')

----
@break
@endswitch
@endforeach
@if ($letter->cta)

{!! $letter->cta['label'] !!}:
{!! $letter->cta['url'] !!}
@endif
@if ($letter->secondary)

{!! $letter->secondary['label'] !!}:
{!! $letter->secondary['url'] !!}
@endif
@if ($letter->callout)

@if ($letter->callout['title'])
{!! $letter->callout['title'] !!}
@endif
{!! $letter->callout['body'] !!}
@endif

--
{!! $brand !!} · {!! __('Write, edit and publish your book.') !!}
@if ($letter->reason)
{!! $letter->reason !!}
@endif
{!! __('Questions? Write to') !!} {!! $supportAddress !!}
@if ($preferencesUrl)
{!! __('Manage email preferences') !!}: {!! $preferencesUrl !!}
@endif
@if ($unsubscribeUrl)
{!! __('Unsubscribe from these emails') !!}: {!! $unsubscribeUrl !!}
@endif
