<?php

it('sends an anonymous visitor from the root to sign in', function () {
    // Was Laravel's stock "/ returns 200" test. The root now redirects:
    // app.ecrivain.ai is the application, and the marketing site lives at the
    // apex, so there is no landing page to serve here.
    $this->get('/')->assertRedirect(route('login'));
});
