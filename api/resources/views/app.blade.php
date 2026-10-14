<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Read by the editor's unload save: navigator.sendBeacon cannot set
             headers, so the token has to be available to put in the body. --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Ecrivain') }}</title>
        <meta name="description" content="Write, edit, publish and grow your book in one place.">

        {{-- Brand marks from ecrivain-brand-assets. The SVG comes first so a
             browser that supports it skips the raster versions; the .ico
             stays for the ones that do not. --}}
        <link rel="icon" href="/brand/symbol.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">
        <meta name="theme-color" content="#2166F5">

        {{-- What a link to the app looks like when somebody shares it. --}}
        <meta property="og:title" content="{{ config('app.name', 'Ecrivain') }}">
        <meta property="og:description" content="Write. Edit. Publish. Grow.">
        <meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/brand/open-graph.png">
        <meta property="og:type" content="website">
        <meta name="twitter:card" content="summary_large_image">

        {{-- Fonts are bundled (Inter, Source Serif 4, Bricolage Grotesque).
             A Figtree stylesheet used to be fetched here: render-blocking,
             from a third party, and referenced by nothing. --}}

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
