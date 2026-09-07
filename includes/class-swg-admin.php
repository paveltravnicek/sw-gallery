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

		// Frontend CSS načítáme i v adminu kvůli živému náhledu – náhled tak používá
		// přesně stejná pravidla jako web a nemůže se rozejít s realitou.
		wp_enqueue_style( 'swg-frontend', SWG_URL . 'assets/frontend.css', array(), SWG_VERSION );
		wp_enqueue_style( 'swg-admin', SWG_URL . 'assets/admin.css', array( 'swg-frontend' ), SWG_VERSION );

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
				'slots'    => SWG_Color::SLOTS,
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

		$clean['settings'] = $this->collect_settings();

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

	public function render_page() {
		$saved        = ( isset( $_GET['swg_notice'] ) && 'saved' === $_GET['swg_notice'] );
		$readonly_msg = ( isset( $_GET['swg_notice'] ) && 'readonly' === $_GET['swg_notice'] );
		$lic_message  = isset( $_GET['swg_license_message'] ) ? sanitize_text_field( wp_unslash( $_GET['swg_license_message'] ) ) : '';
		$licence      = SWG_Licence::instance();
		$operational  = $licence->is_operational();

		$data          = SWG_Data::get();
		$st            = $data['settings'];
		$color         = $st['color'];
		$color_enabled = ( '' !== $color );
		$disabled      = ( ! $operational );
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
