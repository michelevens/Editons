import axios from 'axios';

/**
 * Laravel 13 no longer ships resources/js/bootstrap.js, but Breeze 2.4's
 * app.jsx still imports it. Recreated here with the part that matters: the
 * X-Requested-With header, which is how Laravel distinguishes an AJAX request
 * and returns a 419/JSON response instead of a redirect to the login page.
 */
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
