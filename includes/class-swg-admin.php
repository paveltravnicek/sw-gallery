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
			SWG_CAP,
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
		if ( ! current_user_can( SWG_CAP ) ) {
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

		$color_enabled = ! empty( $_POST['swg_color_enabled'] );
		$color_raw     = isset( $_POST['swg_color'] ) ? sanitize_text_field( wp_unslash( $_POST['swg_color'] ) ) : '';
		$color         = $color_enabled ? sanitize_hex_color( $color_raw ) : '';
		$clean['settings'] = array( 'color' => $color ? $color : '' );

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

		$data          = SWG_Data::get();
		$color         = $data['settings']['color'];
		$color_enabled = ( '' !== $color );
		?>
		<div class="wrap swg-wrap">

			<div class="swg-hero">
				<div class="swg-hero-deco" aria-hidden="true"></div>
				<div class="swg-hero-main">
					<span class="swg-badge">Smart Websites</span>
					<h1 class="swg-hero-title">Fotogalerie</h1>
					<p class="swg-hero-sub">Kategorie, subkategorie a fotky z knihovny médií. Na web vložíte shortcodem <code>[sw_gallery]</code>.</p>
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

					<div class="swg-card swg-card--color">
						<div class="swg-card-head">
							<div class="swg-card-head-text">
								<h2>Barevnost přepínačů</h2>
								<p class="swg-card-sub">Vyberte jednu barvu – plugin si z ní odvodí i další odstíny použité u karet subkategorií a záložek kategorií.</p>
							</div>
						</div>
						<div class="swg-color-row">
							<label class="swg-color-toggle">
								<input type="checkbox" name="swg_color_enabled" id="swg-color-enabled" value="1" <?php checked( $color_enabled ); ?> <?php disabled( ! $operational ); ?>>
								<span>Použít vlastní barvu</span>
							</label>
							<input type="color" name="swg_color" id="swg-color-field" value="<?php echo esc_attr( $color ? $color : '#5F7585' ); ?>" <?php disabled( ! $operational || ! $color_enabled ); ?>>
							<input type="text" id="swg-color-hex" class="swg-color-hex-input" value="<?php echo esc_attr( strtoupper( $color ? $color : '#5F7585' ) ); ?>" maxlength="7" placeholder="#5F7585" autocomplete="off" spellcheck="false" <?php disabled( ! $operational || ! $color_enabled ); ?>>
						</div>
						<p class="description">Barvu vyberte z palety, nebo vedle ní rovnou zadejte vlastní HEX kód. Pokud volbu nezapnete (nebo ji vypnete), zůstane zachovaná stávající barva definovaná v CSS pluginu – to platí i po aktualizaci pluginu na novou verzi.</p>
					</div>

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
					<p>Celou galerii (všechny kategorie) vložíte shortcodem <code>[sw_gallery]</code>.</p>
					<p>Jen jednu kategorii vložíte pomocí jejího shortcode – najdete ho přímo u dané kategorie výše, včetně tlačítka pro rychlé zkopírování.</p>
					<p>V klasickém editoru příspěvku můžete galerii vložit i tlačítkem <strong>Fotogalerie</strong> vedle „Přidat médium“.</p>
				</div>

			</div>

		</div>
		<?php
	}
}
