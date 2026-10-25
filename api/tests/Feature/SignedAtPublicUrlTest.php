<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
 * Signed links are made at APP_URL, but behind Railway's edge a request for
 * app.ecrivain.ai arrives as the service's *.up.railway.app host. The signed
 * check accepts the link as signed for APP_URL; it still refuses a tampered,
 * expired or borrowed signature.
 */

beforeEach(function () {
    Route::get('/_probe/{id}', fn ($id) => 'ok '.$id)->middleware('signed')->name('probe.signed');
    Route::get('/_probe-other/{id}', fn ($id) => 'other '.$id)->middleware('signed')->name('probe.other');
    Route::getRoutes()->refreshNameLookups();
    config(['app.url' => 'https://app.ecrivain.ai']);
    URL::forceRootUrl('https://app.ecrivain.ai');
    URL::forceScheme('https');
});

function arrivingFromRailway(string $url): string
{
    return str_replace('https://app.ecrivain.ai', 'http://ecrivain-app-production.up.railway.app', $url);
}

it('accepts a link signed for APP_URL when the request arrives under the Railway host', function () {
    $url = URL::temporarySignedRoute('probe.signed', now()->addHour(), ['id' => 7]);

    $this->get(arrivingFromRailway($url))->assertOk()->assertSee('ok 7');
    $this->get($url)->assertOk();
});

it('still refuses a tampered, expired or borrowed signature', function () {
    $url = URL::temporarySignedRoute('probe.signed', now()->addHour(), ['id' => 7]);

    $this->get(arrivingFromRailway(str_replace('/_probe/7', '/_probe/8', $url)))->assertForbidden();
    $this->get(arrivingFromRailway($url.'&extra=1'))->assertForbidden();

    $other = URL::temporarySignedRoute('probe.other', now()->addHour(), ['id' => 7]);
    $signature = parse_url($other, PHP_URL_QUERY);
    $this->get(arrivingFromRailway('https://app.ecrivain.ai/_probe/7?'.$signature))->assertForbidden();

    $expired = URL::temporarySignedRoute('probe.signed', now()->subMinute(), ['id' => 7]);
    $this->get(arrivingFromRailway($expired))->assertForbidden();
});
