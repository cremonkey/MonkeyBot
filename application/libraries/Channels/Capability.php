<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Capability names an adapter can declare (ADR-002, brief §10 / §44).
 *
 * An adapter lists a capability ONLY when its own code implements it and a
 * contract test covers it. Something the platform could do but the adapter
 * does not implement yet is NOT listed. Never fake support.
 */
final class Capability
{
    const SEND_TEXT       = 'send_text';
    const SEND_IMAGE      = 'send_image';       // image by public URL
    const SEND_MEDIA      = 'send_media';       // non-image files (video, audio, document)
    const MARK_SEEN       = 'mark_seen';
    const TYPING          = 'typing';
    const BUTTONS         = 'buttons';
    const TEMPLATES       = 'templates';
    const RECEIVE_WEBHOOK = 'receive_webhook';  // normalizeInbound() parses this channel's push payload

    /** @return string[] every known capability name */
    public static function all()
    {
        return array(
            self::SEND_TEXT, self::SEND_IMAGE, self::SEND_MEDIA, self::MARK_SEEN,
            self::TYPING, self::BUTTONS, self::TEMPLATES, self::RECEIVE_WEBHOOK,
        );
    }
}
