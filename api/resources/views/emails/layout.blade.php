<!DOCTYPE html>
{{--
    Écrivain email layout — the one frame every email is drawn in.

    Built for email clients, not browsers:
      * tables for layout, never flex or grid (Outlook renders with Word);
      * every colour inline and every cell given an explicit background, so a
        client that strips <style> still renders the design;
      * the <style> block only adds the mobile and dark-mode refinements;
      * no web fonts and no images: Georgia for headlines (the manuscript's
        own fallback face), the system sans for everything else. The brand is
        type and colour, which survives image blocking and Outlook alike.

    Header: the dark navy band from the studio sidebar, a small uppercase
    eyebrow carrying the brand, and a serif subtitle naming the email. A 4px
    severity strip under it is the only thing that changes with urgency.

    Variables: $letter (App\Mail\Support\Letter), $brand, $supportAddress,
    $preferencesUrl, $unsubscribeUrl, $locale. All supplied by EcrivainMail.
--}}
@php
    $sev = [
        'action'    => ['strip' => '#2166F5', 'ink' => '#1F56E0'],
        'confirmed' => ['strip' => '#22AD7B', 'ink' => '#12795A'],
        'heads'     => ['strip' => '#F0A10B', 'ink' => '#8A4B00'],
        'urgent'    => ['strip' => '#E5484D', 'ink' => '#B4232A'],
        'info'      => ['strip' => '#737C8F', 'ink' => '#5B6476'],
    ][$letter->severity] ?? ['strip' => '#737C8F', 'ink' => '#5B6476'];
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, 'Helvetica Neue', Helvetica, Arial, sans-serif";
    $serif = "Georgia, 'Times New Roman', Times, serif";
@endphp
<html lang="{{ $locale }}" dir="ltr" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $letter->subject }}</title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <style>table, td, h1, p, a, span, div { font-family: Arial, sans-serif !important; } .serif { font-family: Georgia, serif !important; }</style>
    <![endif]-->
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        .em-button:hover { background-color: #1642B4 !important; border-color: #1642B4 !important; }

        /* Phones. The card is fluid up to 600px anyway, so a reading pane
           a little narrower than 600 keeps the desktop design. */
        @media only screen and (max-width: 520px) {
            .em-container { width: 100% !important; }
            .em-outer { padding: 0 !important; }
            .em-card { border-radius: 0 !important; }
            .em-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .em-header { padding: 24px 22px !important; }
            .em-subtitle { font-size: 22px !important; }
            .em-h1 { font-size: 21px !important; }
            .em-stack { display: block !important; width: 100% !important; box-sizing: border-box; }
            .em-stat { display: block !important; width: 100% !important; margin: 0 0 8px !important; }
            .em-hide-sm { display: none !important; }
            .em-btn-wrap, .em-button { width: 100% !important; box-sizing: border-box; text-align: center !important; }
        }

        @media (prefers-color-scheme: dark) {
            .em-canvas { background-color: #0B1220 !important; }
            .em-card, .em-cell { background-color: #141C2E !important; }
            .em-panel { background-color: #1A2438 !important; border-color: #2B3954 !important; }
            .em-panel-head { background-color: #1F2A40 !important; color: #A3AEC4 !important; border-color: #2B3954 !important; }
            .em-line { border-color: #2B3954 !important; }
            .em-ink { color: #F2F5FA !important; }
            .em-body { color: #D3DAE6 !important; }
            .em-muted { color: #A3AEC4 !important; }
            .em-link { color: #93B1F2 !important; }
        }
    </style>
</head>
<body class="em-canvas" style="margin: 0; padding: 0; width: 100%; background-color: #EEF1F6; -webkit-font-smoothing: antialiased;">
    {{-- Preheader: the grey line inbox lists show after the subject. The
         spacer entities stop clients pulling body copy in after it. --}}
    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; color: #EEF1F6;">
        {{ $letter->preheader }}
        @for ($i = 0; $i < 40; $i++)&#847;&zwnj;&nbsp;@endfor
    </div>

    <table role="presentation" class="em-canvas" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #EEF1F6;">
        <tr>
            <td class="em-outer" align="center" style="padding: 32px 12px;">
                <!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" align="center"><tr><td><![endif]-->
                <table role="presentation" class="em-container em-card" width="600" cellspacing="0" cellpadding="0" border="0" style="width: 100%; max-width: 600px; background-color: #FFFFFF; border-radius: 12px; border-collapse: separate; overflow: hidden;">

                    {{-- Header band --}}
                    <tr>
                        <td class="em-header" bgcolor="#10223D" style="background-color: #10223D; padding: 30px 40px 28px; border-radius: 12px 12px 0 0;">
                            <div style="font-family: {!! $sans !!}; font-size: 11px; font-weight: 700; letter-spacing: 2.6px; text-transform: uppercase; color: #93B1F2; line-height: 16px;">
                                {{ $brand }}
                            </div>
                            <div class="em-subtitle serif" style="margin-top: 6px; font-family: {!! $serif !!}; font-size: 26px; font-weight: 400; color: #FFFFFF; line-height: 1.2;">
                                {{ $letter->subtitle }}
                            </div>
                        </td>
                    </tr>

                    {{-- Severity strip --}}
                    <tr>
                        <td height="4" bgcolor="{{ $sev['strip'] }}" style="height: 4px; line-height: 4px; font-size: 0; mso-line-height-rule: exactly; background-color: {{ $sev['strip'] }};">&nbsp;</td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="em-pad em-cell" bgcolor="#FFFFFF" style="background-color: #FFFFFF; padding: 34px 40px 30px; font-family: {!! $sans !!};">
                            @if ($letter->eyebrow)
                                <div style="font-family: {!! $sans !!}; font-size: 11px; font-weight: 700; letter-spacing: 1.6px; text-transform: uppercase; color: {{ $sev['ink'] }}; line-height: 16px;">{{ $letter->eyebrow }}</div>
                            @endif
                            <h1 class="em-h1 em-ink serif" style="margin: {{ $letter->eyebrow ? '8px' : '0' }} 0 0; font-family: {!! $serif !!}; font-size: 24px; font-weight: 400; line-height: 1.3; color: #10223D;">{{ $letter->headline }}</h1>
                            @if ($letter->lede)
                                <p class="em-body" style="margin: 12px 0 0; font-family: {!! $sans !!}; font-size: 16px; line-height: 1.6; color: #2B3446;">{{ $letter->lede }}</p>
                            @endif

                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="em-pad em-cell em-line" bgcolor="#FFFFFF" style="background-color: #FFFFFF; border-top: 1px solid #EEF0F5; padding: 22px 40px 28px; font-family: {!! $sans !!}; border-radius: 0 0 12px 12px;">
                            <p class="em-muted" style="margin: 0; font-size: 12px; line-height: 1.6; color: #6B7488;">
                                <strong class="em-ink serif" style="font-family: {!! $serif !!}; font-size: 14px; font-weight: 700; color: #10223D;">{{ $brand }}</strong>
                                &nbsp;&middot;&nbsp;{{ __('Write, edit and publish your book.') }}
                            </p>
                            @if ($letter->reason)
                                <p class="em-muted" style="margin: 8px 0 0; font-size: 12px; line-height: 1.6; color: #6B7488;">{{ $letter->reason }}</p>
                            @endif
                            <p class="em-muted" style="margin: 8px 0 0; font-size: 12px; line-height: 1.6; color: #6B7488;">
                                {{ __('Questions? Write to') }}
                                <a class="em-link" href="mailto:{{ $supportAddress }}" style="color: #1F56E0; text-decoration: underline;">{{ $supportAddress }}</a>
                                @if ($preferencesUrl)
                                    &nbsp;&middot;&nbsp;<a class="em-link" href="{{ $preferencesUrl }}" style="color: #1F56E0; text-decoration: underline;">{{ __('Manage email preferences') }}</a>
                                @endif
                            </p>
                            @if ($unsubscribeUrl)
                                <p class="em-muted" style="margin: 8px 0 0; font-size: 12px; line-height: 1.6; color: #6B7488;">
                                    {{ __('Not useful?') }}
                                    <a class="em-link" href="{{ $unsubscribeUrl }}" style="color: #1F56E0; text-decoration: underline;">{{ __('Unsubscribe from these emails') }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
