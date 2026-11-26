<?php
/**
 * Plugin Name: SmartSite Share Buttons
 * Description: Adds a row of social share buttons (LinkedIn, Facebook, X, WhatsApp, email, copy link) to the top and bottom of every blog post, including new ones. No tracking, no external scripts.
 * Version: 1.0.0
 * Author: SmartSite
 * License: GPL-2.0-or-later
 * Text Domain: smartsite-share
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmartSite_Share {

	private static $printed_assets = false;

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
	}

	/** Only on the main single blog post, never in feeds, excerpts or other loops. */
	private static function applies( $content ) {
		if ( is_admin() || is_feed() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}
		// Posts that already carry a hand-made bar are left alone.
		if ( false !== strpos( $content, 'data-ss-share' ) ) {
			return false;
		}
		return (bool) apply_filters( 'smartsite_share_enabled', true, get_the_ID() );
	}

	public static function filter_content( $content ) {
		if ( ! self::applies( $content ) ) {
			return $content;
		}
		$top    = self::bar( __( 'Share this article', 'smartsite-share' ) );
		$bottom = self::bar( __( 'Enjoyed this? Share it', 'smartsite-share' ) );
		return self::assets() . $top . $content . $bottom;
	}

	private static function icon( $name ) {
		$i = array(
			'linkedin' => '<svg class="f" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="9" width="4" height="12"/><circle cx="5" cy="5" r="2.2"/><path d="M10 9h3.8v1.8c.7-1.3 2.1-2.1 3.9-2.1 3.4 0 4.3 2.2 4.3 5.3V21h-4v-6c0-1.4-.4-2.4-1.8-2.4-1.5 0-2.2 1-2.2 2.5V21h-4z"/></svg>',
			'facebook' => '<svg class="f" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8V6.5c0-.8.3-1.2 1.3-1.2H17V2h-2.7C11.5 2 10 3.7 10 6.2V8H7.5v3.3H10V22h4V11.3h2.7L17 8z"/></svg>',
			'x'        => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4l16 16"/><path d="M20 4L4 20"/></svg>',
			'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2-5.4A8.5 8.5 0 1 1 21 11.5z"/><path d="M9 9c0 3 3 6 6 6l1.2-1.5-2-1-1 .7c-.8-.4-1.5-1.1-1.9-1.9l.7-1-1-2z"/></svg>',
			'email'    => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>',
			'link'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
		);
		return $i[ $name ];
	}

	private static function bar( $label ) {
		$url   = rawurlencode( get_permalink() );
		$title = rawurlencode( html_entity_decode( wp_strip_all_tags( get_the_title() ), ENT_QUOTES, 'UTF-8' ) );
		$links = array(
			array( 'li', 'LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url, 'linkedin', true ),
			array( 'fb', 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $url, 'facebook', true ),
			array( 'xx', 'X', 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title, 'x', true ),
			array( 'wa', 'WhatsApp', 'https://wa.me/?text=' . $title . '%20' . $url, 'whatsapp', true ),
			array( 'em', 'Email', 'mailto:?subject=' . $title . '&body=' . $url, 'email', false ),
		);
		$out  = '<div class="ss-share" data-ss-share-auto><span class="lbl">' . esc_html( $label ) . '</span><div class="btns">';
		foreach ( $links as $l ) {
			$out .= '<a class="' . esc_attr( $l[0] ) . '" href="' . esc_url( $l[2] ) . '"' . ( $l[4] ? ' target="_blank" rel="noopener noreferrer"' : '' ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s network name */ __( 'Share on %s', 'smartsite-share' ), $l[1] ) ) . '" title="' . esc_attr( $l[1] ) . '">' . self::icon( $l[3] ) . '</a>';
		}
		$out .= '<button class="cp" type="button" data-copy="' . esc_url( get_permalink() ) . '" aria-label="' . esc_attr__( 'Copy link', 'smartsite-share' ) . '" title="' . esc_attr__( 'Copy link', 'smartsite-share' ) . '">' . self::icon( 'link' ) . '</button>';
		$out .= '</div><span class="msg" aria-live="polite"></span></div>';
		return $out;
	}

	/** Styles and the tiny copy-link script, printed once per page. */
	private static function assets() {
		if ( self::$printed_assets ) {
			return '';
		}
		self::$printed_assets = true;
		$css = '.ss-share{display:flex;flex-wrap:wrap;align-items:center;gap:12px 14px;margin:8px 0 28px;padding:14px 18px;background:#0b1d33;border:1px solid rgba(255,255,255,.1);border-radius:16px}'
			. '.ss-share .lbl{font-weight:800;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:#19b8d8;margin-right:4px}'
			. '.ss-share .btns{display:flex;flex-wrap:wrap;gap:10px}'
			. '.ss-share a,.ss-share button{width:44px;height:44px;border-radius:50%;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none!important;padding:0;transition:transform .15s,background .15s,border-color .15s}'
			. '.ss-share svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}'
			. '.ss-share svg.f{fill:currentColor;stroke:none}'
			. '.ss-share a:hover,.ss-share button:hover{transform:translateY(-2px);color:#fff}'
			. '.ss-share .li:hover{background:#0a66c2;border-color:#0a66c2}.ss-share .fb:hover{background:#1877f2;border-color:#1877f2}'
			. '.ss-share .xx:hover{background:#000;border-color:#555}.ss-share .wa:hover{background:#25d366;border-color:#25d366}'
			. '.ss-share .em:hover{background:#19b8d8;border-color:#19b8d8;color:#061426}.ss-share .cp:hover{background:#3b8df0;border-color:#3b8df0}'
			. '.ss-share .msg{font-size:.82rem;color:#7fdcf0;min-width:80px}'
			. '@media(max-width:520px){.ss-share{padding:12px}.ss-share a,.ss-share button{width:42px;height:42px}}';
		$js  = '(function(){document.addEventListener("click",function(e){var b=e.target.closest("[data-copy]");if(!b)return;var url=b.getAttribute("data-copy"),box=b.closest(".ss-share"),msg=box&&box.querySelector(".msg");'
			. 'var done=function(){if(msg){msg.textContent="Link copied";setTimeout(function(){msg.textContent=""},2200)}};'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done,function(){window.prompt("Copy this link",url)})}else{window.prompt("Copy this link",url)}});})();';
		return '<style id="ss-share-css">' . $css . '</style><script id="ss-share-js">' . $js . '</script>';
	}
}

SmartSite_Share::init();
