<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWG_Admin {

	private static $instance = null;
	private $hook_suffix = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_swg_save', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		$this->hook_suffix = add_menu_page(
			'Fotogalerie',
			'Fotogalerie',
			'manage_options',
			'sw-gallery',
			array( $this, 'render_page' ),
			'dashicons-format-gallery',
			26
		);
	}

	public function assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style( 'swg-admin', SWG_URL . 'assets/admin.css', array(), SWG_VERSION );

		wp_enqueue_script(
			'swg-admin',
			SWG_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			SWG_VERSION,
			true
		);

		$data = SWG_Data::get();
		$ids  = SWG_Data::collect_ids( $data );

		wp_localize_script(
			'swg-admin',
			'SWG',
			array(
				'data'     => $data,
				'thumbs'   => SWG_Data::thumb_map( $ids ),
				'readonly' => ! SWG_Licence::instance()->is_operational(),
				'i18n'   => array(
					'selectPhotos'    => 'Vybrat fotky',
					'addToGallery'    => 'Přidat do galerie',
					'newCategory'     => 'Název kategorie',
					'newSubcategory'  => 'Název subkategorie',
					'confirmCat'      => 'Smazat kategorii včetně subkategorií?',
					'confirmSub'      => 'Smazat subkategorii?',
					'emptyPhotos'     => 'Zatím žádné fotky. Přidej je tlačítkem výše.',
					'photo'           => 'fotka',
					'photos2'         => 'fotky',
					'photos5'         => 'fotek',
				),
			)
		);
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( 'swg_save', 'swg_nonce' );

		// Read-only při neplatné licenci – uložení odmítneme.
		if ( ! SWG_Licence::instance()->is_operational() ) {
			$redirect = add_query_arg(
				array(
					'page'       => 'sw-gallery',
					'swg_notice' => 'readonly',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$json = isset( $_POST['swg_data'] ) ? wp_unslash( $_POST['swg_data'] ) : '';
		$raw  = json_decode( $json, true );

		$clean = SWG_Data::sanitize( is_array( $raw ) ? $raw : array() );
		SWG_Data::save( $clean );

		$redirect = add_query_arg(
			array(
				'page'       => 'sw-gallery',
				'swg_notice' => 'saved',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_page() {
		$saved        = ( isset( $_GET['swg_notice'] ) && 'saved' === $_GET['swg_notice'] );
		$readonly_msg = ( isset( $_GET['swg_notice'] ) && 'readonly' === $_GET['swg_notice'] );
		$lic_message  = isset( $_GET['swg_license_message'] ) ? sanitize_text_field( wp_unslash( $_GET['swg_license_message'] ) ) : '';
		$licence      = SWG_Licence::instance();
		$operational  = $licence->is_operational();
		?>
		<div class="wrap swg-wrap">

			<div class="swg-hero">
				<div class="swg-hero-deco" aria-hidden="true"></div>
				<div class="swg-hero-main">
					<span class="swg-badge">Smart Websites</span>
					<h1 class="swg-hero-title">Fotogalerie</h1>
					<p class="swg-hero-sub">Kategorie, subkategorie a fotky z knihovny médií. Na web vložíš shortcodem <code>[sw_gallery]</code>.</p>
				</div>
				<div class="swg-hero-version">
					<span class="swg-hero-version-num"><?php echo esc_html( SWG_VERSION ); ?></span>
					<span class="swg-hero-version-label">Verze pluginu</span>
				</div>
			</div>

			<div class="swg-inner">

				<?php if ( '' !== $lic_message ) : ?>
					<div class="swg-inline-notice swg-inline-notice--ok"><?php echo esc_html( $lic_message ); ?></div>
				<?php endif; ?>

				<?php if ( $saved ) : ?>
					<div class="swg-inline-notice swg-inline-notice--ok">Změny byly uloženy.</div>
				<?php endif; ?>

				<?php $licence->render_card(); ?>

				<?php if ( ! $operational ) : ?>
					<div class="swg-inline-notice swg-inline-notice--warn">
						Plugin nemá platnou licenci. Galerie na webu zůstává funkční, ale administrace je <strong>jen pro čtení</strong> – kategorie, subkategorie ani fotky nelze měnit, dokud licenci neobnovíte.
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="swg-form">
					<input type="hidden" name="action" value="swg_save">
					<?php wp_nonce_field( 'swg_save', 'swg_nonce' ); ?>
					<input type="hidden" name="swg_data" id="swg-data-json" value="">

					<?php if ( $operational ) : ?>
						<div class="swg-toolbar">
							<div class="swg-toolbar-left">
								<input type="text" id="swg-new-cat" class="swg-input" placeholder="Název nové kategorie">
								<button type="button" class="button swg-btn" id="swg-add-cat">+ Přidat kategorii</button>
							</div>
							<button type="submit" class="button button-primary swg-btn-save">Uložit změny</button>
						</div>
					<?php endif; ?>

					<div id="swg-editor" class="swg-editor<?php echo $operational ? '' : ' is-readonly'; ?>">
						<!-- vykresluje admin.js -->
					</div>

					<?php if ( $operational ) : ?>
						<div class="swg-footer-save">
							<button type="submit" class="button button-primary swg-btn-save">Uložit změny</button>
						</div>
					<?php endif; ?>
				</form>

				<div class="swg-help">
					<h2>Jak na vložení</h2>
					<p>Celou galerii (všechny kategorie) vložíš shortcodem <code>[sw_gallery]</code>.</p>
					<p>Jen jednu kategorii vložíš pomocí jejího slugu, např. <code>[sw_gallery category="exterieery"]</code>.</p>
				</div>

			</div>

		</div>
		<?php
	}
}
