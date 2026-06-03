<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWG_Shortcode {

	private static $instance = null;
	private $assets_needed = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'sw_gallery', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_print_lightbox' ) );
	}

	public function register_assets() {
		wp_register_style( 'swg-frontend', SWG_URL . 'assets/frontend.css', array(), SWG_VERSION );
		wp_register_script( 'swg-frontend', SWG_URL . 'assets/frontend.js', array(), SWG_VERSION, true );
	}

	private function enqueue() {
		$this->assets_needed = true;
		wp_enqueue_style( 'swg-frontend' );
		wp_enqueue_script( 'swg-frontend' );
	}

	/** Skloňování počtu fotek. */
	private function count_label( $n ) {
		if ( 1 === $n ) {
			return $n . ' fotka';
		}
		if ( $n >= 2 && $n <= 4 ) {
			return $n . ' fotky';
		}
		return $n . ' fotek';
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'category' => '',
			),
			$atts,
			'sw_gallery'
		);

		$data = SWG_Data::get();
		$categories = isset( $data['categories'] ) ? $data['categories'] : array();

		// Filtr na jednu kategorii.
		if ( '' !== $atts['category'] ) {
			$wanted = sanitize_title( $atts['category'] );
			$filtered = array();
			foreach ( $categories as $cat ) {
				if ( isset( $cat['slug'] ) && $cat['slug'] === $wanted ) {
					$filtered[] = $cat;
				}
			}
			$categories = $filtered;
		}

		// Vyhodíme prázdné kategorie (bez subkategorií s fotkami).
		$categories = $this->prune( $categories );

		if ( empty( $categories ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="swg-empty-admin">SW Fotogalerie: zatím nejsou žádné fotky k zobrazení. Doplň je v administraci v sekci Fotogalerie.</p>';
			}
			return '';
		}

		$this->enqueue();

		$uid = 'swg-' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );

		ob_start();
		?>
		<div class="swg" id="<?php echo esc_attr( $uid ); ?>" data-swg>

			<?php if ( count( $categories ) > 1 ) : ?>
				<div class="swg-tabs" role="tablist">
					<?php foreach ( $categories as $ci => $cat ) : ?>
						<button type="button"
							class="swg-tab<?php echo 0 === $ci ? ' is-active' : ''; ?>"
							data-cat-target="<?php echo esc_attr( $cat['slug'] ); ?>"
							role="tab">
							<?php echo esc_html( $cat['title'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $categories as $ci => $cat ) : ?>
				<?php
				$subs = $this->prune_subs( isset( $cat['subcategories'] ) ? $cat['subcategories'] : array() );
				$single_sub = ( 1 === count( $subs ) );
				?>
				<div class="swg-cat<?php echo 0 === $ci ? ' is-active' : ''; ?>" data-cat="<?php echo esc_attr( $cat['slug'] ); ?>">

					<?php if ( ! $single_sub ) : ?>
						<div class="swg-subs" role="tablist">
							<?php foreach ( $subs as $si => $sub ) : ?>
								<button type="button"
									class="swg-sub-card<?php echo 0 === $si ? ' is-active' : ''; ?>"
									data-sub-target="<?php echo esc_attr( $sub['slug'] ); ?>"
									role="tab">
									<span class="swg-sub-card-title"><?php echo esc_html( $sub['title'] ); ?></span>
									<span class="swg-sub-card-count"><?php echo esc_html( $this->count_label( count( $sub['photos'] ) ) ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php foreach ( $subs as $si => $sub ) : ?>
						<div class="swg-panel<?php echo 0 === $si ? ' is-active' : ''; ?>" data-sub="<?php echo esc_attr( $sub['slug'] ); ?>">
							<?php if ( $single_sub ) : ?>
								<h3 class="swg-panel-title"><?php echo esc_html( $sub['title'] ); ?></h3>
							<?php endif; ?>
							<div class="swg-grid">
								<?php foreach ( $sub['photos'] as $pid ) : ?>
									<?php echo $this->render_item( $pid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

				</div>
			<?php endforeach; ?>

		</div>
		<?php
		return ob_get_clean();
	}

	/** Vykreslí jednu položku mřížky (justified flex item). */
	private function render_item( $pid ) {
		$grid = wp_get_attachment_image_src( $pid, 'large' );
		$full = wp_get_attachment_image_src( $pid, 'full' );
		if ( ! $grid ) {
			return '';
		}
		$src   = $grid[0];
		$w     = (int) $grid[1];
		$h     = (int) $grid[2];
		$ratio = ( $h > 0 ) ? round( $w / $h, 3 ) : 1.5;
		$full_url = $full ? $full[0] : $src;

		$alt     = trim( (string) get_post_meta( $pid, '_wp_attachment_image_alt', true ) );
		$caption = trim( (string) wp_get_attachment_caption( $pid ) );
		$label   = ( '' !== $caption ) ? $caption : $alt;

		$srcset = wp_get_attachment_image_srcset( $pid, 'large' );
		$sizes  = wp_get_attachment_image_sizes( $pid, 'large' );

		$style = 'flex-grow:' . $ratio . ';flex-basis:calc(var(--swg-row-h) * ' . $ratio . ');';

		$html  = '<figure class="swg-item" style="' . esc_attr( $style ) . '" ';
		$html .= 'data-ratio="' . esc_attr( $ratio ) . '" ';
		$html .= 'data-full="' . esc_url( $full_url ) . '" ';
		$html .= 'data-caption="' . esc_attr( $label ) . '">';
		$html .= '<img src="' . esc_url( $src ) . '" ';
		if ( $srcset ) {
			$html .= 'srcset="' . esc_attr( $srcset ) . '" ';
		}
		if ( $sizes ) {
			$html .= 'sizes="' . esc_attr( $sizes ) . '" ';
		}
		$html .= 'alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">';
		if ( '' !== $label ) {
			$html .= '<figcaption class="swg-item-cap">' . esc_html( $label ) . '</figcaption>';
		}
		$html .= '<span class="swg-item-zoom" aria-hidden="true">';
		$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M6.5 12a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11zM13 6.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0z"/><path d="M10.344 11.742c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1 6.538 6.538 0 0 1-1.398 1.4z"/><path fill-rule="evenodd" d="M6.5 3a.5.5 0 0 1 .5.5V6h2.5a.5.5 0 0 1 0 1H7v2.5a.5.5 0 0 1-1 0V7H3.5a.5.5 0 0 1 0-1H6V3.5a.5.5 0 0 1 .5-.5z"/></svg>';
		$html .= '</span>';
		$html .= '</figure>';

		return $html;
	}

	/** Odstraní kategorie bez jediné neprázdné subkategorie. */
	private function prune( $categories ) {
		$out = array();
		foreach ( $categories as $cat ) {
			$subs = $this->prune_subs( isset( $cat['subcategories'] ) ? $cat['subcategories'] : array() );
			if ( ! empty( $subs ) ) {
				$cat['subcategories'] = $subs;
				$out[] = $cat;
			}
		}
		return $out;
	}

	/** Odstraní subkategorie bez fotek. */
	private function prune_subs( $subs ) {
		$out = array();
		if ( ! is_array( $subs ) ) {
			return $out;
		}
		foreach ( $subs as $sub ) {
			if ( ! empty( $sub['photos'] ) && is_array( $sub['photos'] ) ) {
				$out[] = $sub;
			}
		}
		return $out;
	}

	/** Lightbox markup vypíšeme jednou do patičky, jen když byl shortcode použit. */
	public function maybe_print_lightbox() {
		if ( ! $this->assets_needed ) {
			return;
		}
		?>
		<div class="swg-lightbox" id="swg-lightbox" aria-hidden="true" role="dialog" aria-modal="true">
			<button type="button" class="swg-lb-close" aria-label="Zavřít">&times;</button>
			<button type="button" class="swg-lb-nav swg-lb-prev" aria-label="Předchozí">&#8249;</button>
			<button type="button" class="swg-lb-nav swg-lb-next" aria-label="Další">&#8250;</button>
			<div class="swg-lb-stage">
				<img class="swg-lb-img" src="" alt="">
			</div>
			<div class="swg-lb-bar">
				<span class="swg-lb-caption"></span>
				<span class="swg-lb-counter"></span>
			</div>
		</div>
		<?php
	}
}
