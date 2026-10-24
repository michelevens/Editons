<?php

use App\Providers\AppServiceProvider;
use App\Providers\BusinessServiceProvider;
use App\Providers\MailServiceProvider;

return [
    AppServiceProvider::class,
    MailServiceProvider::class,
    BusinessServiceProvider::class,
];
