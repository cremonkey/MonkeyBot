/*
 * SPEC-00 Task D groundwork — global CSRF token injector for jQuery AJAX.
 * INERT until CodeIgniter csrf_protection is enabled in config.php (currently FALSE;
 * see docs/SECURITY-TASK-D.md for why global CI CSRF is deferred on this deployment).
 * When enabled, include this after jQuery in the admin/member footer views.
 */
(function () {
  if (typeof jQuery === 'undefined') return;
  jQuery.ajaxPrefilter(function (options) {
    if (!/^(GET|HEAD|OPTIONS)$/i.test(options.type || 'GET')) {
      var m = document.cookie.match(/(?:^|; )csrf_cookie_name=([^;]+)/);
      if (m) {
        var token = decodeURIComponent(m[1]);
        if (options.data instanceof FormData) {
          options.data.append('csrf_test_name', token);
        } else if (typeof options.data === 'string') {
          options.data += (options.data ? '&' : '') + 'csrf_test_name=' + encodeURIComponent(token);
        } else if (options.data && typeof options.data === 'object') {
          options.data.csrf_test_name = token;
        } else {
          options.data = 'csrf_test_name=' + encodeURIComponent(token);
        }
      }
    }
  });
})();
