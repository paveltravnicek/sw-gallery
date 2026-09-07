<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Barevná vrstva fotogalerie.
 *
 * Jediné místo, kde se z nastavení počítají výsledné barvy. Výstupem je mapa
 * CSS proměnných, kterou shortcode vypíše inline za frontend.css.
 *
 * Logika je záměrně jednoduchá:
 *   hlavní barva  = aktivní prvky (podtržítko aktivní záložky, aktivní přepínač)
 *   vedlejší barva = neaktivní prvky (výchozí = hlavní barva)
 *   styl přepínačů = jak se z těch dvou barev poskládá tlačítko
 *   ruční přepis   = kterýkoli konkrétní slot lze přebít vlastní barvou
 *
 * Pozor: stejné odvození má i admin.js kvůli živému náhledu. Když se mění
 * vzoreček tady, musí se změnit i tam (funkce swgDerive).
 */
class SWG_Color {

	/** Povolené styly přepínačů. */
	const STYLES = array( 'soft', 'outline', 'solid' );

	/** Sloty, které jde ručně přebít. */
	const SLOTS = array( 'tab_on', 'tab_off', 'bg', 'bd', 'fg', 'bg_on', 'bd_on', 'fg_on' );

	/**
	 * Normalizuje hex barvu na '#rrggbb'. Neplatný vstup vrací '' (= nepoužít).
	 * Vlastní implementace místo sanitize_hex_color() proto, že navíc rozbaluje
	 * tříznakový zápis a vždy vrací string (ne null).
	 */
	public static function hex( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( '#' !== substr( $value, 0, 1 ) ) {
			$value = '#' . $value;
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			$h = $m[1];
			return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/** '#rrggbb' => array(r, g, b) nebo null. */
	public static function rgb( $hex ) {
		$hex = self::hex( $hex );
		if ( '' === $hex ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
		);
	}

	/** '#rrggbb' => "r, g, b" (pro rgba()). Vrací '' při neplatném vstupu. */
	public static function triplet( $hex ) {
		$rgb = self::rgb( $hex );
		return $rgb ? implode( ', ', $rgb ) : '';
	}

	/** CSS rgba() řetězec. */
	public static function rgba( $hex, $alpha ) {
		$t = self::triplet( $hex );
		if ( '' === $t ) {
			return 'transparent';
		}
		return 'rgba(' . $t . ', ' . round( (float) $alpha, 3 ) . ')';
	}

	/** Smíchá dvě barvy; $weight je podíl první barvy (0–1). */
	public static function mix( $hex_a, $hex_b, $weight ) {
		$a = self::rgb( $hex_a );
		$b = self::rgb( $hex_b );
		if ( ! $a || ! $b ) {
			return self::hex( $hex_a );
		}
		$w   = max( 0, min( 1, (float) $weight ) );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$out .= str_pad( dechex( (int) round( $a[ $i ] * $w + $b[ $i ] * ( 1 - $w ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/** Relativní jas 0–1 (WCAG). */
	public static function luminance( $hex ) {
		$rgb = self::rgb( $hex );
		if ( ! $rgb ) {
			return 1.0;
		}
		$chan = array();
		foreach ( $rgb as $c ) {
			$c      = $c / 255;
			$chan[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
	}

	/**
	 * Čitelná barva textu na daném pozadí – bílá, nebo tmavá inkoustová.
	 * Díky tomu si klient nemůže nastavit nečitelné tlačítko.
	 */
	public static function contrast( $hex ) {
		$bg    = self::luminance( $hex );
		$light = self::ratio( $bg, self::luminance( '#ffffff' ) );
		$dark  = self::ratio( $bg, self::luminance( '#1e2327' ) );
		return ( $dark >= $light ) ? '#1e2327' : '#ffffff';
	}

	/** Kontrastní poměr dvou jasů podle WCAG. */
	private static function ratio( $l1, $l2 ) {
		$hi = max( $l1, $l2 );
		$lo = min( $l1, $l2 );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * Spočítá mapu CSS proměnných z (už normalizovaného) pole nastavení.
	 * Prázdné pole = žádná customizace, použije se výchozí barevnost z CSS.
	 */
	public static function vars( $settings ) {
		$primary = self::hex( isset( $settings['color'] ) ? $settings['color'] : '' );
		if ( '' === $primary ) {
			return array();
		}

		$secondary = self::hex( isset( $settings['color2'] ) ? $settings['color2'] : '' );
		if ( '' === $secondary ) {
			$secondary = $primary;
		}

		$style = isset( $settings['btn_style'] ) ? $settings['btn_style'] : 'soft';
		if ( ! in_array( $style, self::STYLES, true ) ) {
			$style = 'soft';
		}

		$p = $primary;
		$s = $secondary;

		// Zpětná kompatibilita: staré proměnné dál plníme, ať nic jiného nepřestane fungovat.
		$vars = array(
			'--swg-gold'           => $p,
			'--swg-gold-soft'      => self::rgba( $p, 0.5 ),
			'--swg-gold-tint'      => self::rgba( $p, 0.1 ),
			'--swg-gold-tint-soft' => self::rgba( $s, 0.05 ),
			'--swg-gold-muted'     => self::rgba( $s, 0.62 ),
		);

		// Horní záložky kategorií.
		$vars['--swg-tab-line']      = $p;
		$vars['--swg-tab-line-idle'] = 'transparent';

		// Přepínače subkategorií podle zvoleného stylu.
		switch ( $style ) {

			case 'outline':
				$vars['--swg-btn-bg']       = 'transparent';
				$vars['--swg-btn-bd']       = self::rgba( $s, 0.35 );
				$vars['--swg-btn-fg']       = self::rgba( $s, 0.72 );
				$vars['--swg-btn-sub']      = 'var(--swg-muted)';
				$vars['--swg-btn-bg-hover'] = self::rgba( $s, 0.05 );
				$vars['--swg-btn-bd-hover'] = self::rgba( $p, 0.6 );
				$vars['--swg-btn-bg-on']    = self::rgba( $p, 0.08 );
				$vars['--swg-btn-bd-on']    = $p;
				$vars['--swg-btn-fg-on']    = $p;
				$vars['--swg-btn-sub-on']   = 'var(--swg-muted)';
				break;

			case 'solid':
				// Inverze: aktivní tlačítko je plné, text se dopočítá na kontrast.
				$ink                        = self::contrast( $p );
				$vars['--swg-btn-bg']       = self::rgba( $s, 0.08 );
				$vars['--swg-btn-bd']       = self::rgba( $s, 0.28 );
				$vars['--swg-btn-fg']       = self::mix( $s, '#1e2327', 0.55 );
				$vars['--swg-btn-sub']      = 'var(--swg-muted)';
				$vars['--swg-btn-bg-hover'] = self::rgba( $s, 0.16 );
				$vars['--swg-btn-bd-hover'] = self::rgba( $p, 0.5 );
				$vars['--swg-btn-bg-on']    = $p;
				$vars['--swg-btn-bd-on']    = $p;
				$vars['--swg-btn-fg-on']    = $ink;
				$vars['--swg-btn-sub-on']   = self::rgba( $ink, 0.78 );
				break;

			case 'soft':
			default:
				// Výchozí = vzhled z verze 1.3.
				$vars['--swg-btn-bg']       = self::rgba( $s, 0.05 );
				$vars['--swg-btn-bd']       = 'var(--swg-line)';
				$vars['--swg-btn-fg']       = self::rgba( $s, 0.62 );
				$vars['--swg-btn-sub']      = 'var(--swg-muted)';
				$vars['--swg-btn-bg-hover'] = self::rgba( $s, 0.05 );
				$vars['--swg-btn-bd-hover'] = self::rgba( $p, 0.5 );
				$vars['--swg-btn-bg-on']    = self::rgba( $p, 0.1 );
				$vars['--swg-btn-bd-on']    = $p;
				$vars['--swg-btn-fg-on']    = $p;
				$vars['--swg-btn-sub-on']   = 'var(--swg-muted)';
				break;
		}

		// Ruční přepisy jednotlivých slotů (prázdné = nechat automatiku).
		$map = array(
			'tab_on' => '--swg-tab-line',
			'tab_off' => '--swg-tab-line-idle',
			'bg'     => '--swg-btn-bg',
			'bd'     => '--swg-btn-bd',
			'fg'     => '--swg-btn-fg',
			'bg_on'  => '--swg-btn-bg-on',
			'bd_on'  => '--swg-btn-bd-on',
			'fg_on'  => '--swg-btn-fg-on',
		);
		$ovr = isset( $settings['ovr'] ) && is_array( $settings['ovr'] ) ? $settings['ovr'] : array();
		foreach ( $map as $slot => $var ) {
			$value = isset( $ovr[ $slot ] ) ? self::hex( $ovr[ $slot ] ) : '';
			if ( '' !== $value ) {
				$vars[ $var ] = $value;
			}
		}

		// Když si někdo ručně přebije pozadí aktivního tlačítka, dopočítáme k němu
		// čitelný popisek – ale jen pokud si barvu textu nenastavil taky sám.
		if ( ! empty( $ovr['bg_on'] ) && empty( $ovr['fg_on'] ) ) {
			$bg_on = self::hex( $ovr['bg_on'] );
			if ( '' !== $bg_on && 'solid' === $style ) {
				$ink                      = self::contrast( $bg_on );
				$vars['--swg-btn-fg-on']  = $ink;
				$vars['--swg-btn-sub-on'] = self::rgba( $ink, 0.78 );
			}
		}

		// Hover pozadí kopíruje neaktivní pozadí, pokud si ho někdo přebil.
		if ( ! empty( $ovr['bg'] ) ) {
			$vars['--swg-btn-bg-hover'] = $vars['--swg-btn-bg'];
		}

		return $vars;
	}

	/** Mapa proměnných => inline CSS pravidlo pro .swg. */
	public static function css( $vars ) {
		if ( empty( $vars ) ) {
			return '';
		}
		$out = '';
		foreach ( $vars as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return '.swg{' . $out . '}';
	}
}
