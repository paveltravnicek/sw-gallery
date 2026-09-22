<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrace pluginu – tři podstránky pod jednou položkou menu:
 *   sw-gallery           Správa fotogalerií (kategorie, subkategorie, fotky)
 *   sw-gallery-settings  Nastavení (barevnost + živý náhled)
 *   sw-gallery-licence   Licence
 *
 * Hlavní slug zůstává 'sw-gallery', takže staré odkazy a záložky dál vedou
 * na správu galerií.
 */
class SWG_Admin {

	const PAGE_GALLERY  = 'sw-gallery';
	const PAGE_SETTINGS = 'sw-gallery-settings';
	const PAGE_LICENCE  = 'sw-gallery-licence';

	private static $instance = null;

	/** hook_suffix => slug podstránky */
	private $hooks = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_swg_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_swg_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** URL podstránky pluginu. */
	public static function page_url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public function menu() {
		$top = add_menu_page(
			'Fotogalerie',
			'Fotogalerie',
			SWG_CAP,
			self::PAGE_GALLERY,
			array( $this, 'render_gallery_page' ),
			'dashicons-format-gallery',
			26
		);

		// První podpoložka má stejný slug jako hlavní menu – WP ji tím přejmenuje
		// z „Fotogalerie" na „Správa fotogalerií" a nevznikne duplicitní odkaz.
		$gallery = add_submenu_page( self::PAGE_GALLERY, 'Správa fotogalerií', 'Správa fotogalerií', SWG_CAP, self::PAGE_GALLERY, array( $this, 'render_gallery_page' ) );
		$settings = add_submenu_page( self::PAGE_GALLERY, 'Nastavení fotogalerie', 'Nastavení', SWG_CAP_ADMIN, self::PAGE_SETTINGS, array( $this, 'render_settings_page' ) );
		$licence  = add_submenu_page( self::PAGE_GALLERY, 'Licence fotogalerie', 'Licence', SWG_CAP_ADMIN, self::PAGE_LICENCE, array( $this, 'render_licence_page' ) );

		$this->hooks = array(
			$top      => self::PAGE_GALLERY,
			$gallery  => self::PAGE_GALLERY,
			$settings => self::PAGE_SETTINGS,
			$licence  => self::PAGE_LICENCE,
		);
	}

	public function assets( $hook ) {
		if ( ! isset( $this->hooks[ $hook ] ) ) {
			return;
		}
		$page = $this->hooks[ $hook ];

		$deps = array();
		if ( self::PAGE_SETTINGS === $page ) {
			// frontend.css jen pro živý náhled v Nastavení. Nesmí se načítat na správě
			// galerií: editor používá stejné názvy tříd (.swg-sub-card) a frontendové
			// styly by mu rozbily rozložení.
			wp_enqueue_style( 'swg-frontend', SWG_URL . 'assets/frontend.css', array(), SWG_VERSION );
			$deps[] = 'swg-frontend';
		}
		wp_enqueue_style( 'swg-admin', SWG_URL . 'assets/admin.css', $deps, SWG_VERSION );

		if ( self::PAGE_LICENCE === $page ) {
			return; // licence žádné JS nepotřebuje
		}

		if ( self::PAGE_GALLERY === $page ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'swg-admin',
			SWG_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			SWG_VERSION,
			true
		);

		$data = SWG_Data::get();
		$ids  = ( self::PAGE_GALLERY === $page ) ? SWG_Data::collect_ids( $data ) : array();

		wp_localize_script(
			'swg-admin',
			'SWG',
			array(
				'data'     => array( 'categories' => $data['categories'] ),
				'thumbs'   => SWG_Data::thumb_map( $ids ),
				'readonly' => ! SWG_Licence::instance()->is_operational(),
				'i18n'     => array(
					'selectPhotos'   => 'Vybrat fotky',
					'addToGallery'   => 'Přidat do galerie',
					'newCategory'    => 'Název kategorie',
					'newSubcategory' => 'Název subkategorie',
					'confirmCat'     => 'Smazat kategorii včetně subkategorií?',
					'confirmSub'     => 'Smazat subkategorii?',
					'emptyPhotos'    => 'Zatím žádné fotky. Přidej je tlačítkem výše.',
					'emptySubs'      => 'Zatím žádná subkategorie.',
					'photo'          => 'fotka',
					'photos2'        => 'fotky',
					'photos5'        => 'fotek',
					'expand'         => 'Rozbalit',
					'collapse'       => 'Sbalit',
				),
			)
		);
	}

	/* ---------- Ukládání ---------- */

	/** Společné kontroly obou formulářů. Vrací false, pokud se nemá ukládat. */
	private function guard_save( $nonce_action, $page, $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Nedostatečná oprávnění.' );
		}
		check_admin_referer( $nonce_action, 'swg_nonce' );

		// Read-only při neplatné licenci – uložení odmítneme.
		if ( ! SWG_Licence::instance()->is_operational() ) {
			wp_safe_redirect( self::page_url( $page, array( 'swg_notice' => 'readonly' ) ) );
			exit;
		}
		return true;
	}

	/** Správa galerií – ukládá jen kategorie. Nastavení barev nechává být. */
	public function handle_save() {
		$this->guard_save( 'swg_save', self::PAGE_GALLERY, SWG_CAP );

		$json = isset( $_POST['swg_data'] ) ? wp_unslash( $_POST['swg_data'] ) : '';
		$raw  = json_decode( $json, true );

		$current = SWG_Data::get();
		$clean   = SWG_Data::sanitize( is_array( $raw ) ? $raw : array() );
		$clean['settings'] = $current['settings'];

		SWG_Data::save( $clean );

		wp_safe_redirect( self::page_url( self::PAGE_GALLERY, array( 'swg_notice' => 'saved' ) ) );
		exit;
	}

	/** Nastavení – ukládá jen barevnost. Kategorie nechává být. */
	public function handle_save_settings() {
		$this->guard_save( 'swg_save_settings', self::PAGE_SETTINGS, SWG_CAP_ADMIN );

		$data             = SWG_Data::get();
		$data['settings'] = $this->collect_settings();
		SWG_Data::save( $data );

		wp_safe_redirect( self::page_url( self::PAGE_SETTINGS, array( 'swg_notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Posbírá nastavení barevnosti z POSTu.
	 * Vypnutý hlavní přepínač = prázdná hlavní barva = žádný override na frontendu.
	 */
	private function collect_settings() {
		if ( empty( $_POST['swg_color_enabled'] ) ) {
			return SWG_Data::normalize_settings( array() );
		}

		$raw = array(
			'color'     => isset( $_POST['swg_color'] ) ? wp_unslash( $_POST['swg_color'] ) : '',
			'color2'    => isset( $_POST['swg_color2'] ) ? wp_unslash( $_POST['swg_color2'] ) : '',
			'btn_style' => isset( $_POST['swg_btn_style'] ) ? sanitize_key( wp_unslash( $_POST['swg_btn_style'] ) ) : 'soft',
			'ovr'       => array(),
		);

		$ovr_post = isset( $_POST['swg_ovr'] ) && is_array( $_POST['swg_ovr'] ) ? wp_unslash( $_POST['swg_ovr'] ) : array();
		foreach ( SWG_Color::SLOTS as $slot ) {
			$raw['ovr'][ $slot ] = isset( $ovr_post[ $slot ] ) ? $ovr_post[ $slot ] : '';
		}

		// Normalizace hlídá i platnost hexů – neplatný zápis prostě spadne na automatiku.
		return SWG_Data::normalize_settings( $raw );
	}

	/** Má instalace aspoň jeden ruční přepis? (Kvůli rozbalení pokročilé sekce.) */
	private function has_overrides( $settings ) {
		foreach ( SWG_Color::SLOTS as $slot ) {
			if ( ! empty( $settings['ovr'][ $slot ] ) ) {
				return true;
			}
		}
		return ( '' !== $settings['color2'] ) || ( 'soft' !== $settings['btn_style'] );
	}

	/**
	 * Jedno barevné pole: vzorník + HEX + tlačítko zpět na automatiku.
	 * $value '' znamená „auto“; skutečná hodnota jde do skrytého inputu,
	 * vzorník jen ukazuje, jakou barvu automatika právě vrací.
	 */
	private function color_field( $args ) {
		$args = array_merge(
			array(
				'name'     => '',
				'slot'     => '',
				'label'    => '',
				'value'    => '',
				'auto'     => true,
				'disabled' => false,
			),
			$args
		);
		$has_value = ( '' !== $args['value'] );
		?>
		<div class="swg-cf<?php echo $args['auto'] ? ' swg-cf--auto-capable' : ''; ?>" data-slot="<?php echo esc_attr( $args['slot'] ); ?>">
			<span class="swg-cf-label"><?php echo esc_html( $args['label'] ); ?></span>
			<div class="swg-cf-row">
				<input type="color" class="swg-cf-swatch" value="#5f7585" <?php disabled( $args['disabled'] ); ?> aria-label="<?php echo esc_attr( $args['label'] ); ?>">
				<input type="text" class="swg-cf-hex" value="<?php echo esc_attr( $has_value ? strtoupper( $args['value'] ) : '' ); ?>"
					placeholder="<?php echo $args['auto'] ? 'auto' : '#5F7585'; ?>" maxlength="7"
					autocomplete="off" spellcheck="false" <?php disabled( $args['disabled'] ); ?>>
				<?php if ( $args['auto'] ) : ?>
					<button type="button" class="swg-cf-reset" title="Zpět na automatickou barvu" aria-label="Zpět na automatickou barvu" <?php disabled( $args['disabled'] ); ?>>&times;</button>
				<?php endif; ?>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>" class="swg-cf-value" value="<?php echo esc_attr( $args['value'] ); ?>">
		</div>
		<?php
	}

	/* ---------- Společné části stránek ---------- */

	private function open_page() {
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
		<?php
		$this->render_notices();
	}

	private function close_page() {
		?>
			</div>
		</div>
		<?php
	}

	private function render_notices() {
		$notice = isset( $_GET['swg_notice'] ) ? sanitize_key( wp_unslash( $_GET['swg_notice'] ) ) : '';
		$lic    = isset( $_GET['swg_license_message'] ) ? sanitize_text_field( wp_unslash( $_GET['swg_license_message'] ) ) : '';

		if ( '' !== $lic ) {
			echo '<div class="swg-inline-notice swg-inline-notice--ok">' . esc_html( $lic ) . '</div>';
		}
		if ( 'saved' === $notice ) {
			echo '<div class="swg-inline-notice swg-inline-notice--ok">Změny byly uloženy.</div>';
		}
	}

	/** Upozornění na read-only režim s odkazem na stránku Licence. */
	private function render_readonly_notice( $what ) {
		?>
		<div class="swg-inline-notice swg-inline-notice--warn">
			Plugin nemá platnou licenci. Galerie na webu zůstává funkční, ale <?php echo esc_html( $what ); ?> je <strong>jen pro čtení</strong>, dokud licenci neobnovíte.
			<?php if ( current_user_can( SWG_CAP_ADMIN ) ) : ?>
				<a href="<?php echo esc_url( self::page_url( self::PAGE_LICENCE ) ); ?>">Přejít na licenci</a>
			<?php else : ?>
				Obraťte se prosím na administrátora webu.
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------- Správa fotogalerií ---------- */

	public function render_gallery_page() {
		$operational = SWG_Licence::instance()->is_operational();
		$this->open_page();

		if ( ! $operational ) {
			$this->render_readonly_notice( 'správa galerií' );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="swg-form">
			<input type="hidden" name="action" value="swg_save">
			<?php wp_nonce_field( 'swg_save', 'swg_nonce' ); ?>
			<input type="hidden" name="swg_data" id="swg-data-json" value="">

			<div class="swg-toolbar">
				<div class="swg-toolbar-left">
					<?php if ( $operational ) : ?>
						<input type="text" id="swg-new-cat" class="swg-input" placeholder="Název nové kategorie">
						<button type="button" class="button swg-btn" id="swg-add-cat">+ Přidat kategorii</button>
					<?php endif; ?>
				</div>
				<div class="swg-toolbar-right">
					<button type="button" class="button swg-btn swg-btn-ghost" id="swg-expand-all">Rozbalit vše</button>
					<button type="button" class="button swg-btn swg-btn-ghost" id="swg-collapse-all">Sbalit vše</button>
					<?php if ( $operational ) : ?>
						<button type="submit" class="button button-primary swg-btn-save">Uložit změny</button>
					<?php endif; ?>
				</div>
			</div>

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
			<p>Jen jednu kategorii vložíte pomocí jejího shortcode – najdete ho u dané kategorie po rozbalení, včetně tlačítka pro rychlé zkopírování.</p>
			<p>V klasickém editoru příspěvku můžete galerii vložit i tlačítkem <strong>Fotogalerie</strong> vedle „Přidat médium".</p>
		</div>
		<?php
		$this->close_page();
	}

	/* ---------- Nastavení ---------- */

	public function render_settings_page() {
		$operational   = SWG_Licence::instance()->is_operational();
		$data          = SWG_Data::get();
		$st            = $data['settings'];
		$color         = $st['color'];
		$color_enabled = ( '' !== $color );
		$disabled      = ( ! $operational );

		$this->open_page();

		if ( ! $operational ) {
			$this->render_readonly_notice( 'nastavení' );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="swg-settings-form">
			<input type="hidden" name="action" value="swg_save_settings">
			<?php wp_nonce_field( 'swg_save_settings', 'swg_nonce' ); ?>

					<div class="swg-card swg-card--color<?php echo $color_enabled ? '' : ' is-off'; ?>" id="swg-color-card">
						<div class="swg-card-head">
							<div class="swg-card-head-text">
								<h2>Barevnost galerie</h2>
								<p class="swg-card-sub">Dvě barvy a styl přepínače stačí na 90 % webů. Zbytek doladíte v pokročilém nastavení – každý prvek zvlášť.</p>
							</div>
							<label class="swg-color-toggle">
								<input type="checkbox" name="swg_color_enabled" id="swg-color-enabled" value="1" <?php checked( $color_enabled ); ?> <?php disabled( $disabled ); ?>>
								<span>Použít vlastní barevnost</span>
							</label>
						</div>

						<div class="swg-color-layout">

							<div class="swg-color-controls">

								<div class="swg-cf-grid swg-cf-grid--base">
									<?php
									$this->color_field(
										array(
											'name'     => 'swg_color',
											'slot'     => 'primary',
											'label'    => 'Hlavní barva (aktivní prvky)',
											'value'    => ( '' !== $color ) ? $color : '#5f7585',
											'auto'     => false,
											'disabled' => $disabled,
										)
									);
									$this->color_field(
										array(
											'name'     => 'swg_color2',
											'slot'     => 'secondary',
											'label'    => 'Vedlejší barva (neaktivní prvky)',
											'value'    => $st['color2'],
											'auto'     => true,
											'disabled' => $disabled,
										)
									);
									?>
								</div>

								<div class="swg-style-picker" role="radiogroup" aria-label="Styl přepínačů">
									<span class="swg-cf-label">Styl přepínačů subkategorií</span>
									<div class="swg-style-opts">
										<?php
										$styles = array(
											'soft'    => array( 'Jemný', 'Světlé pozadí, neutrální rámeček. Výchozí vzhled.' ),
											'outline' => array( 'Obrysový', 'Průhledné pozadí, barevný rámeček.' ),
											'solid'   => array( 'Plný (inverzní)', 'Aktivní přepínač je plně barevný, text se dopočítá na kontrast.' ),
										);
										foreach ( $styles as $key => $meta ) :
											?>
											<label class="swg-style-opt<?php echo ( $st['btn_style'] === $key ) ? ' is-on' : ''; ?>">
												<input type="radio" name="swg_btn_style" value="<?php echo esc_attr( $key ); ?>" <?php checked( $st['btn_style'], $key ); ?> <?php disabled( $disabled ); ?>>
												<span class="swg-style-opt-demo" aria-hidden="true" data-demo="<?php echo esc_attr( $key ); ?>">
													<span class="swg-style-chip swg-style-chip--off"></span>
													<span class="swg-style-chip swg-style-chip--on"></span>
												</span>
												<span class="swg-style-opt-name"><?php echo esc_html( $meta[0] ); ?></span>
												<span class="swg-style-opt-desc"><?php echo esc_html( $meta[1] ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</div>

								<details class="swg-advanced"<?php echo $this->has_overrides( $st ) ? ' open' : ''; ?>>
									<summary>Pokročilé – barva po jednotlivých prvcích</summary>
									<p class="description swg-advanced-hint">Prázdné pole = barva se dopočítá automaticky. Vzorník ukazuje, co automatika právě vrací, takže vidíte, co přepisujete.</p>

									<h3 class="swg-advanced-title">Horní záložky kategorií</h3>
									<div class="swg-cf-grid">
										<?php
										$this->color_field( array( 'name' => 'swg_ovr[tab_on]', 'slot' => 'tab_on', 'label' => 'Podtržítko – aktivní', 'value' => $st['ovr']['tab_on'], 'disabled' => $disabled ) );
										$this->color_field( array( 'name' => 'swg_ovr[tab_off]', 'slot' => 'tab_off', 'label' => 'Podtržítko – neaktivní', 'value' => $st['ovr']['tab_off'], 'disabled' => $disabled ) );
										?>
									</div>
									<p class="description">Barvu textu záložek řídí šablona webu – plugin do ní schválně nesahá.</p>

									<h3 class="swg-advanced-title">Přepínače subkategorií – neaktivní</h3>
									<div class="swg-cf-grid">
										<?php
										$this->color_field( array( 'name' => 'swg_ovr[bg]', 'slot' => 'bg', 'label' => 'Pozadí', 'value' => $st['ovr']['bg'], 'disabled' => $disabled ) );
										$this->color_field( array( 'name' => 'swg_ovr[bd]', 'slot' => 'bd', 'label' => 'Rámeček', 'value' => $st['ovr']['bd'], 'disabled' => $disabled ) );
										$this->color_field( array( 'name' => 'swg_ovr[fg]', 'slot' => 'fg', 'label' => 'Text', 'value' => $st['ovr']['fg'], 'disabled' => $disabled ) );
										?>
									</div>

									<h3 class="swg-advanced-title">Přepínače subkategorií – aktivní</h3>
									<div class="swg-cf-grid">
										<?php
										$this->color_field( array( 'name' => 'swg_ovr[bg_on]', 'slot' => 'bg_on', 'label' => 'Pozadí', 'value' => $st['ovr']['bg_on'], 'disabled' => $disabled ) );
										$this->color_field( array( 'name' => 'swg_ovr[bd_on]', 'slot' => 'bd_on', 'label' => 'Rámeček', 'value' => $st['ovr']['bd_on'], 'disabled' => $disabled ) );
										$this->color_field( array( 'name' => 'swg_ovr[fg_on]', 'slot' => 'fg_on', 'label' => 'Text', 'value' => $st['ovr']['fg_on'], 'disabled' => $disabled ) );
										?>
									</div>

									<p class="swg-advanced-actions">
										<button type="button" class="button swg-btn" id="swg-color-swap" <?php disabled( $disabled ); ?>>Prohodit hlavní a vedlejší barvu</button>
										<button type="button" class="button swg-btn" id="swg-color-reset" <?php disabled( $disabled ); ?>>Zrušit všechny ruční přepisy</button>
									</p>
								</details>

							</div>

							<div class="swg-color-preview">
								<span class="swg-cf-label">Živý náhled</span>
								<div class="swg-preview-stage" id="swg-preview">
									<div class="swg">
										<div class="swg-tabs">
											<button type="button" class="swg-tab is-active">Exteriéry</button>
											<button type="button" class="swg-tab">Interiéry</button>
										</div>
										<div class="swg-subs">
											<button type="button" class="swg-sub-card is-active">
												<span class="swg-sub-card-title">1. etapa</span>
												<span class="swg-sub-card-count">12 fotek</span>
											</button>
											<button type="button" class="swg-sub-card">
												<span class="swg-sub-card-title">2. etapa</span>
												<span class="swg-sub-card-count">8 fotek</span>
											</button>
										</div>
									</div>
								</div>
								<p class="description">Náhled používá stejné CSS jako web. Barvu textu a pozadí stránky si bere z administrace, na webu je řídí šablona.</p>
							</div>

						</div>
					</div>

			<?php if ( $operational ) : ?>
				<div class="swg-footer-save">
					<button type="submit" class="button button-primary swg-btn-save">Uložit nastavení</button>
				</div>
			<?php endif; ?>
		</form>
		<?php
		$this->close_page();
	}

	/* ---------- Licence ---------- */

	public function render_licence_page() {
		$this->open_page();
		SWG_Licence::instance()->render_card();
		$this->close_page();
	}
}
