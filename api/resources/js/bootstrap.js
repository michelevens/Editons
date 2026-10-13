import axios from 'axios';

/**
 * Laravel 13 no longer ships resources/js/bootstrap.js, but Breeze 2.4's
 * app.jsx still imports it. Recreated here with the parts that matter.
 */
window.axios = axios;

// How Laravel tells an AJAX request from a browser navigation, and therefore
// whether to answer 419/JSON or redirect to the login page.
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Where data calls go.
 *
 * The deployment serves the studio from app.ecrivain.ai and its data from
 * api.ecrivain.ai, so every XHR is cross-origin. Two things follow:
 *
 *  - withCredentials, or the session cookie is not sent and every
 *    authenticated call arrives as a guest.
 *  - a base URL, so component code keeps writing `/books/6/sources` and stays
 *    unaware of which host answers it.
 *
 * VITE_API_URL is empty in local development and in any single-host
 * deployment, where relative paths are correct and nothing is cross-origin.
 */
const apiUrl = import.meta.env.VITE_API_URL ?? '';

if (apiUrl) {
    window.axios.defaults.baseURL = apiUrl;
}

window.axios.defaults.withCredentials = true;

// Laravel's CSRF cookie is read per request rather than captured once: the
// token rotates on login and on session regeneration, and a stale value
// means a 419 on the next write.
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';
