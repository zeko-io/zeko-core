<?php
/**
 * Central rich-text sanitization for the Zeko ecosystem.
 *
 * Quill-backed editors across modules (business, qa, learn, shop, jobs,
 * love, mentor, pay) store sanitized HTML. wp_kses_post() is a valid but
 * maximal allow-list; this class provides a narrow, documented allow-list
 * that matches the rich-text feature set while explicitly rejecting media
 * embeds, scripts, forms, and style attributes.
 *
 * The allow-list and protocol set are both filterable so individual sites
 * can tighten (or, carefully, widen) the boundary:
 *
 *   - '{s}zeko_sanitize_rich_text_allowlist' (array)
 *   - '{s}zeko_sanitize_rich_text_protocols'  (array of schemes)
 *
 * @package Zeko_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Class Zeko_Core_Sanitize. */
final class Zeko_Core_Sanitize {

	/**
	 * Sanitize rich-text HTML with the documented narrow allow-list.
	 *
	 * @return string Sanitized HTML safe to store and render.
	 * @param string $html Raw rich-text HTML from an editor.
	 */
	public static function rich_text( string $html ): string {
		$allowed   = apply_filters( 'zeko_sanitize_rich_text_allowlist', self::rich_text_allowlist() );
		$protocols = apply_filters(
			'zeko_sanitize_rich_text_protocols',
			array( 'https', 'http', 'mailto', 'tel' )
		);
		return wp_kses( $html, $allowed, $protocols );
	}

	/**
	 * The documented rich-text allow-list.
	 * Inline: p, br, strong/b, em/i, u, s/del, code, pre, span (Quill size/
	 * color classes), sub, sup, a (restricted protocols). Blocks: headings
	 * h1-h6, blockquote, ol, ul, li. `class` is allowed only to preserve
	 * Quill presentation (alignment/indent/size classes). The `style`
	 * attribute is deliberately rejected on every element.
	 * Explicitly NOT allowed: img, iframe, video, audio, object, embed,
	 * link, meta, base, script, style, form, input, button, textarea,
	 * select, option, table. Embeds and media uploads must use a real
	 * attachment flow, never paste/embed inside editor HTML.
	 *
	 * @return array
	 */
	public static function rich_text_allowlist(): array {
		return array(
			'p'          => array( 'class' => true ),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			's'          => array(),
			'del'        => array(),

			'code'       => array(),
			'pre'        => array( 'class' => true ),
			'span'       => array( 'class' => true ),
			'sub'        => array(),
			'sup'        => array(),

			'h1'         => array( 'class' => true ),
			'h2'         => array( 'class' => true ),
			'h3'         => array( 'class' => true ),
			'h4'         => array( 'class' => true ),
			'h5'         => array( 'class' => true ),
			'h6'         => array( 'class' => true ),
			'blockquote' => array( 'class' => true ),
			'ol'         => array(
				'class' => true,
				'start' => true,
			),
			'ul'         => array( 'class' => true ),
			'li'         => array(
				'class' => true,
				'value' => true,
			),

			'a'          => array(
				'href'  => true,
				'title' => true,
				'rel'   => true,
			),
		);
	}

	/**
	 * Convenience alias used on output so stored values render through the
	 * same narrow list (belt-and-braces; write sanitization is authoritative).
	 *
	 * @return string
	 * @param string $html Already-sanitized HTML to render.
	 */
	public static function rich_text_output( string $html ): string {
		return self::rich_text( (string) $html );
	}
}
