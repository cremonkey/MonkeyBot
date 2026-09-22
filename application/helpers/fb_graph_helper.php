<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Single source of truth for the Facebook Graph API version.
 *
 * Before this helper the version was written by hand in ~49 places across eight
 * different versions (v2.6 .. v21.0), plus ~26 URLs with no version at all — those
 * unversioned calls are served by whatever Meta currently considers its oldest
 * supported version, so their behaviour changes on Meta's schedule, not ours.
 *
 * Two tiers, so the migration can move endpoint groups one at a time:
 *   default — the version we are migrating everything towards (currently v26.0)
 *   legacy  — v20.0, which is what Meta ACTUALLY serves today for every stale or
 *             unversioned call. Verified with the facebook-api-version response
 *             header: v2.6, v4.0, v8.0, v18.0 and no-version all come back as
 *             v20.0, because Meta silently promotes dead versions to its oldest
 *             live one. Pinning here freezes current behaviour instead of letting
 *             it jump on its own the day Meta retires v20.0.
 *
 * Both are overridable from application/config/config.php, so a bad rollout is a
 * one-line revert instead of a code rollback.
 */

if (!function_exists('fb_graph_version'))
{
    /**
     * @param string $tier 'default' or 'legacy'
     * @return string e.g. 'v26.0'
     */
    function fb_graph_version($tier = 'default')
    {
        static $cache = array();

        $tier = ($tier === 'legacy') ? 'legacy' : 'default';
        if (isset($cache[$tier])) return $cache[$tier];

        // Hard fallbacks: used only if config.php is missing the keys, so the
        // helper can never return an empty version and build a broken URL.
        $fallback = array('default' => 'v26.0', 'legacy' => 'v20.0');
        $key      = ($tier === 'legacy') ? 'fb_graph_version_legacy' : 'fb_graph_version';

        $version = '';
        if (function_exists('get_instance'))
        {
            $CI =& get_instance();
            if ($CI !== NULL && isset($CI->config)) $version = (string) $CI->config->item($key);
        }

        // Must look like a version ("v" + digits), else fall back.
        if (!preg_match('/^v\d+(\.\d+)?$/', $version)) $version = $fallback[$tier];

        return $cache[$tier] = $version;
    }
}

if (!function_exists('graph_url'))
{
    /**
     * Build a versioned Graph API URL.
     *   graph_url('me/messages')            => https://graph.facebook.com/v26.0/me/messages
     *   graph_url('me/custom_labels', TRUE) => https://graph.facebook.com/v4.0/me/custom_labels
     *
     * @param string $path  path after the version, with or without a leading slash
     * @param bool   $legacy TRUE to use the pinned legacy version
     * @return string
     */
    function graph_url($path = '', $legacy = FALSE)
    {
        $version = fb_graph_version($legacy ? 'legacy' : 'default');
        return 'https://graph.facebook.com/' . $version . '/' . ltrim((string) $path, '/');
    }
}

/* End of file fb_graph_helper.php */
/* Location: ./application/helpers/fb_graph_helper.php */
