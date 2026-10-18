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

/**
 * Phase 3 (v20.0 -> v26.0 cutover): per-group version resolution.
 *
 * graph_url()/fb_graph_version() above stayed exactly as Phase 0-2 left them
 * (nothing here changes their behaviour). This is the new call style that lets
 * each endpoint group move to v26.0 independently, one config flag at a time,
 * instead of one global legacy/default split.
 *
 * Groups (see docs/GRAPH_API_MIGRATION.md Phase 3 for the full rollout plan):
 *   reads               - /{post_id} fields, /{page_id}/conversations,
 *                          /{thread_id}/messages, /debug_token, /me/picture,
 *                          and other side-effect-free GET lookups
 *   send                - me/messages (Send API)
 *   messenger_profile   - me/messenger_profile (get_started, greeting,
 *                          persistent_menu, whitelisted_domains, ice_breakers)
 *   oauth               - oauth/access_token, oauth/access_token_info
 *   insights            - me/insights, {id}/insights
 *   labels              - me/custom_labels, {label_id}/label
 *   page_admin          - write/moderation calls that don't fit any of the
 *                          5 groups above (delete/hide comment, block
 *                          commenter, IG mention auto-reply, test-user
 *                          roles). Not part of the owner's 5-group rollout
 *                          plan; kept independently flippable so it is never
 *                          silently dragged along by a "reads" or "send" flip.
 *
 * Every group above defaults to fb_graph_version('legacy') (v20.0) when its
 * config key is unset, so behaviour is byte-identical to today until an
 * owner explicitly sets a group's key in application/config/config.php.
 *
 * A second, smaller set of groups is HARD-PINNED: they ignore any config
 * override entirely, because the endpoint has no confirmed v26.0 path and
 * bundling it with a flippable group would let a group-wide flip break it
 * by accident. See docs/GRAPH_API_MIGRATION.md for the research behind each:
 *   messaging_feature_review - me/messaging_feature_review (live code path,
 *                               Social_accounts::page_review_status)
 */
if (!function_exists('fb_graph_group_version'))
{
    /**
     * @param string $group one of the group names documented above
     * @return string e.g. 'v20.0'
     */
    function fb_graph_group_version($group)
    {
        static $cache = array();

        $group = (string) $group;
        if (isset($cache[$group])) return $cache[$group];

        // Hard-pinned: never read from config, always the last version known
        // to support the endpoint. See docs/GRAPH_API_MIGRATION.md.
        $hard_pinned = array(
            'messaging_feature_review' => 'v20.0',
        );
        if (isset($hard_pinned[$group]))
        {
            return $cache[$group] = $hard_pinned[$group];
        }

        // Flippable groups. Anything not on this list falls back to 'reads'
        // (the most conservative, read-only group) rather than building a
        // URL for an unrecognised/mistyped group name.
        $known_groups = array(
            'reads', 'send', 'messenger_profile', 'oauth', 'insights', 'labels', 'page_admin',
        );
        $g = in_array($group, $known_groups, TRUE) ? $group : 'reads';

        $key     = 'fb_graph_group_' . $g . '_version';
        $version = '';
        if (function_exists('get_instance'))
        {
            $CI =& get_instance();
            if ($CI !== NULL && isset($CI->config)) $version = (string) $CI->config->item($key);
        }

        // Unset, blank, or malformed config -> today's real behaviour, unchanged.
        if (!preg_match('/^v\d+(\.\d+)?$/', $version)) $version = fb_graph_version('legacy');

        return $cache[$group] = $version;
    }
}

if (!function_exists('ig_graph_url'))
{
    /** graph.instagram.com URL (Instagram API with Instagram Login). Version: config ig_graph_version, else v25.0. */
    function ig_graph_url($path = '')
    {
        $version = '';
        if (function_exists('get_instance'))
        {
            $CI =& get_instance();
            if ($CI !== NULL && isset($CI->config)) $version = (string) $CI->config->item('ig_graph_version');
        }
        if (!preg_match('/^v\d+(\.\d+)?$/', $version)) $version = 'v25.0';
        return 'https://graph.instagram.com/' . $version . '/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('ig_graph_is_ig_token_path'))
{
    /** TRUE when the path carries an Instagram Login token (access_token=IG...). */
    function ig_graph_is_ig_token_path($path)
    {
        return (bool) preg_match('/[?&]access_token=IG[A-Za-z0-9]/', (string) $path);
    }
}

if (!function_exists('graph_url_group'))
{
    /**
     * Build a versioned Graph API URL for a specific endpoint group.
     *   graph_url_group('send', 'me/messages') => https://graph.facebook.com/v20.0/me/messages
     * (v20.0 today; becomes v26.0 for that one group once its config key is set)
     *
     * @param string $group endpoint group, see fb_graph_group_version()
     * @param string $path  path after the version, with or without a leading slash
     * @return string
     */
    function graph_url_group($group, $path = '')
    {
        // Instagram Login tokens (IG...) only work on graph.instagram.com. Facebook tokens start
        // with EAA, so this never changes a Facebook call. See ig_login_helper.php.
        if (ig_graph_is_ig_token_path($path)) return ig_graph_url($path);
        $version = fb_graph_group_version($group);
        return 'https://graph.facebook.com/' . $version . '/' . ltrim((string) $path, '/');
    }
}

/**
 * Page/Post/Video/Stories insights metrics confirmed REMOVED by Meta "when
 * v26.0 releases" (Graph API v25.0 changelog,
 * developers.facebook.com/docs/graph-api/changelog/version25.0, fetched
 * 2026-09-22). Meta's replacement guidance: use page_total_media_view_unique
 * / page_media_view (page & post), STORY_MEDIA_VIEW /
 * STORY_TOTAL_MEDIA_VIEW_UNIQUE (stories). No replacement metric is given
 * for the video-insights impressions family.
 *
 * NOTE: the messaging/conversation metrics this codebase actually requests
 * (page_messages_total_messaging_connections, page_messages_new_conversations_unique,
 * page_messages_blocked_conversations_unique, page_messages_reported_conversations_unique)
 * are NOT on this list -- confirmed against the same changelog page, which
 * only documents impression/view metric removals. So flipping the
 * 'insights' group today would not break the one live insights call site.
 * This list exists so any FUTURE insights call (or a metric added to the
 * existing one) is checked before the version is trusted, instead of
 * discovering a dead metric from a raw Graph API error in production.
 */
if (!function_exists('fb_graph_insights_dropped_metrics'))
{
    function fb_graph_insights_dropped_metrics()
    {
        return array(
            'page' => array(
                'page_impressions_unique', 'page_impressions_paid_unique',
                'page_impressions_viral_unique', 'page_impressions_nonviral_unique',
                'page_posts_impressions', 'page_posts_impressions_unique',
                'page_posts_impressions_paid', 'page_posts_impressions_paid_unique',
                'page_posts_impressions_organic_unique', 'page_posts_served_impressions_organic_unique',
                'page_posts_impressions_viral', 'page_posts_impressions_viral_unique',
                'page_posts_impressions_nonviral', 'page_posts_impressions_nonviral_unique',
                'page_video_views_unique',
            ),
            'post' => array(
                'post_impressions_unique', 'post_impressions_paid_unique',
                'post_impressions_fan_unique', 'post_impressions_organic_unique',
                'post_impressions_viral_unique', 'post_impressions_nonviral_unique',
                'post_video_views_organic_unique', 'post_video_views_paid_unique',
                'post_video_views_unique',
            ),
            'video' => array(
                'total_video_impressions', 'total_video_impressions_unique',
                'total_video_impressions_paid_unique', 'total_video_impressions_paid',
                'total_video_impressions_organic_unique', 'total_video_impressions_organic',
                'total_video_impressions_viral_unique', 'total_video_impressions_viral',
                'total_video_impressions_fan_unique', 'total_video_impressions_fan',
                'total_video_impressions_fan_paid_unique', 'total_video_impressions_fan_paid',
                'total_video_views_organic_unique', 'total_video_views_paid_unique',
                'total_video_views_unique',
            ),
            'stories' => array(
                'PAGE_STORY_IMPRESSIONS_BY_STORY_ID', 'PAGE_STORY_IMPRESSIONS_BY_STORY_ID_UNIQUE',
            ),
        );
    }
}

if (!function_exists('fb_graph_insights_unsupported'))
{
    /**
     * @param string $metrics_csv comma-separated `metric=` value as sent to Graph
     * @return array the subset of $metrics_csv confirmed removed by Meta (empty = safe)
     */
    function fb_graph_insights_unsupported($metrics_csv)
    {
        $requested = array_map('trim', explode(',', (string) $metrics_csv));
        $dropped   = fb_graph_insights_dropped_metrics();
        $dropped_all = array();
        foreach ($dropped as $list) $dropped_all = array_merge($dropped_all, $list);
        return array_values(array_intersect($requested, $dropped_all));
    }
}

/* End of file fb_graph_helper.php */
/* Location: ./application/helpers/fb_graph_helper.php */
