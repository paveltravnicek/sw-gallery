<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Napojení na SW Licence Hub / SW Guard.
 *
 * Logika 1:1 dle SW Zalomení:
 *  - „Správa webu" přes SW Guard (sw_guard_get_service_state + swg_management_status),
 *  - fallback samostatná licence pluginu ověřovaná proti Hubu (REST swlic/v2).
 *
 * DŮLEŽITÉ: provozní stav (is_operational) řídí POUZE administraci (read-only).
 * Frontend galerie (shortcode) běží vždy bez ohledu na licenci.
 */
class SWG_Licence {

	const OPTION      = 'sw_gallery_license';
	const CRON_HOOK   = 'sw_gallery_license_daily_check';
	const HUB_BASE    = 'https://smart-websites.cz';
	const PLUGIN_SLUG = 'sw-gallery';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'cron_refresh' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_swg_verify_license', array( $this, 'handle_verify' ) );
			add_action( 'admin_post_swg_remove_license', array( $this, 'handle_remove' ) );
			add_action( 'admin_init', array( $this, 'maybe_refresh' ) );
		}
	}

	/* ---------- Cron (volá main při aktivaci/deaktivaci) ---------- */

	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ---------- Stav samostatné licence ---------- */

	private function default_state() {
		return array(
			'key'          => '',
			'status'       => 'missing',
			'type'         => '',
			'valid_to'     => '',
			'domain'       => '',
			'message'      => '',
			'last_check'   => 0,
			'last_success' => 0,
		);
	}

	private function get_state() {
		$s = get_option( self::OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return wp_parse_args( $s, $this->default_state() );
	}

	private function update_state( array $data ) {
		$current = $this->get_state();
		$new      = array_merge( $current, $data );
		$new['key']          = sanitize_text_field( (string) ( $new['key'] ?? '' ) );
		$new['status']       = sanitize_key( (string) ( $new['status'] ?? 'missing' ) );
		$new['type']         = sanitize_key( (string) ( $new['type'] ?? '' ) );
		$new['valid_to']     = sanitize_text_field( (string) ( $new['valid_to'] ?? '' ) );
		$new['domain']       = sanitize_text_field( (string) ( $new['domain'] ?? '' ) );
		$new['message']      = sanitize_text_field( (string) ( $new['message'] ?? '' ) );
		$new['last_check']   = (int) ( $new['last_check'] ?? 0 );
		$new['last_success'] = (int) ( $new['last_success'] ?? 0 );
		update_option( self::OPTION, $new, false );
	}

	/* ---------- Kontext správy webu (SW Guard) ---------- */

	public function get_management_context() {
		$guard   = function_exists( 'sw_guard_get_service_state' );
		$mstatus = $guard ? (string) get_option( 'swg_management_status', 'NONE' ) : 'NONE';
		$sstate  = $guard ? (string) sw_guard_get_service_state( self::PLUGIN_SLUG ) : 'off';
		$last    = $guard ? (int) get_option( 'swg_last_success_ts', 0 ) : 0;
		$recent  = $last > 0 && ( time() - $last ) <= ( 8 * DAY_IN_SECONDS );

		return array(
			'guard_present'      => $guard,
			'management_status'  => $mstatus,
			'service_state'      => in_array( $sstate, array( 'active', 'passive', 'off' ), true ) ? $sstate : 'off',
			'guard_last_success' => $last,
			'connected_recently' => $recent,
			'is_active'          => $guard && $recent && 'ACTIVE' === $mstatus && 'active' === $sstate,
		);
	}

	private function has_active_standalone() {
		$l = $this->get_state();
		return '' !== $l['key'] && 'active' === $l['status'] && 'plugin_single' === $l['type'];
	}

	/** Provozní stav – řídí pouze read-only administrace. */
	public function is_operational() {
		$m = $this->get_management_context();
		if ( $m['is_active'] ) {
			return true;
		}
		return $this->has_active_standalone();
	}

	/* ---------- Ověřování proti Hubu ---------- */

	public function cron_refresh() {
		$this->refresh( 'cron' );
	}

	public function maybe_refresh() {
		$m = $this->get_management_context();
		if ( $m['is_active'] ) {
			return;
		}
		$l = $this->get_state();
		if ( '' === $l['key'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_POST['license_key'] ) ) {
			return;
		}
		if ( $l['last_check'] > 0 && ( time() - (int) $l['last_check'] ) < ( 12 * HOUR_IN_SECONDS ) ) {
			return;
		}
		$this->refresh( 'admin-auto' );
	}

	private function refresh( $reason = 'manual', $override_key = '' ) {
		$key = '' !== $override_key ? sanitize_text_field( $override_key ) : (string) $this->get_state()['key'];

		if ( '' === $key ) {
			$this->update_state(
				array(
					'key'        => '',
					'status'     => 'missing',
					'type'       => '',
					'valid_to'   => '',
					'domain'     => '',
					'message'    => 'Licenční kód zatím není uložený.',
					'last_check' => time(),
				)
			);
			return array( 'ok' => false, 'error' => 'missing_key' );
		}

		$payload = array(
			'license_key'    => $key,
			'plugin_slug'    => self::PLUGIN_SLUG,
			'site_id'        => (string) get_option( 'swg_site_id', '' ),
			'site_url'       => home_url( '/' ),
			'reason'         => $reason,
			'plugin_version' => SWG_VERSION,
		);

		$res = wp_remote_post(
			rtrim( self::HUB_BASE, '/' ) . '/wp-json/swlic/v2/plugin-license',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $res ) ) {
			$this->update_state(
				array(
					'key'        => $key,
					'status'     => 'error',
					'message'    => $res->get_error_message(),
					'last_check' => time(),
				)
			);
			return array( 'ok' => false, 'error' => $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = 'Nepodařilo se ověřit licenci.';
			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$msg = sanitize_text_field( (string) $data['message'] );
			} elseif ( $code > 0 ) {
				$msg = 'Hub vrátil neočekávanou odpověď (HTTP ' . $code . ').';
			}
			$this->update_state(
				array(
					'key'        => $key,
					'status'     => 'error',
					'message'    => $msg,
					'last_check' => time(),
				)
			);
			return array( 'ok' => false, 'error' => 'bad_response', 'message' => $msg );
		}

		$this->update_state(
			array(
				'key'          => $key,
				'status'       => sanitize_key( (string) ( $data['status'] ?? 'missing' ) ),
				'type'         => sanitize_key( (string) ( $data['licence_type'] ?? 'plugin_single' ) ),
				'valid_to'     => sanitize_text_field( (string) ( $data['valid_to'] ?? '' ) ),
				'domain'       => sanitize_text_field( (string) ( $data['assigned_domain'] ?? '' ) ),
				'message'      => sanitize_text_field( (string) ( $data['message'] ?? '' ) ),
				'last_check'   => time(),
				'last_success' => ! empty( $data['ok'] ) ? time() : 0,
			)
		);

		return $data;
	}

	/* ---------- Admin akce (samostatná licence) ---------- */

	public function handle_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Zakázáno.', 'Zakázáno', array( 'response' => 403 ) );
		}
		check_admin_referer( 'swg_verify_license' );
		$key = sanitize_text_field( (string) ( $_POST['license_key'] ?? '' ) );
		$r   = $this->refresh( 'manual', $key );
		$msg = ! empty( $r['message'] ) ? (string) $r['message'] : ( ! empty( $r['ok'] ) ? 'Licence byla ověřena.' : 'Licenci se nepodařilo ověřit.' );
		wp_safe_redirect( add_query_arg( 'swg_license_message', rawurlencode( $msg ), admin_url( 'admin.php?page=sw-gallery' ) ) );
		exit;
	}

	public function handle_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Zakázáno.', 'Zakázáno', array( 'response' => 403 ) );
		}
		check_admin_referer( 'swg_remove_license' );
		delete_option( self::OPTION );
		wp_safe_redirect( add_query_arg( 'swg_license_message', rawurlencode( 'Licenční kód byl odebrán.' ), admin_url( 'admin.php?page=sw-gallery' ) ) );
		exit;
	}

	/* ---------- Data pro licenční kartu ---------- */

	private function get_panel_data() {
		$license = $this->get_state();
		$m       = $this->get_management_context();
		$op      = $this->is_operational();

		$fmt_dt = static function ( $ts ) {
			$ts = (int) $ts;
			return $ts > 0 ? wp_date( 'j. n. Y H:i', $ts ) : '—';
		};
		$fmt_d = static function ( $ymd ) {
			$ymd = (string) $ymd;
			if ( '' === $ymd ) {
				return '—';
			}
			$ts = strtotime( $ymd . ' 12:00:00' );
			return $ts ? wp_date( 'j. n. Y', $ts ) : $ymd;
		};

		$base = array(
			'badge_class' => 'inactive',
			'badge_label' => 'Licence chybí',
			'mode'        => 'Samostatná licence pluginu',
			'valid_to'    => '—',
			'domain'      => '',
			'last_check'  => '—',
			'message'     => '',
			'note'        => '',
			'show_form'   => true,
			'license_key' => $license['key'],
		);

		if ( $m['guard_present'] ) {
			if ( $m['is_active'] ) {
				return array_merge(
					$base,
					array(
						'badge_class' => 'active',
						'badge_label' => 'Platná licence',
						'mode'        => 'Správa webu',
						'valid_to'    => $fmt_d( get_option( 'swg_managed_until', '' ) ),
						'domain'      => (string) get_option( 'swg_licence_domain', '' ),
						'last_check'  => $fmt_dt( $m['guard_last_success'] ),
						'show_form'   => false,
						'note'        => 'Plugin je provozován v rámci Správy webu. Samostatný licenční kód není potřeba.',
					)
				);
			}
			if ( 'NONE' !== $m['management_status'] ) {
				return array_merge(
					$base,
					array(
						'badge_class' => 'inactive',
						'badge_label' => 'Licence neplatná',
						'mode'        => 'Správa webu',
						'valid_to'    => $fmt_d( get_option( 'swg_managed_until', '' ) ),
						'domain'      => (string) get_option( 'swg_licence_domain', '' ),
						'last_check'  => $fmt_dt( $m['guard_last_success'] ),
						'show_form'   => true,
						'message'     => 'Správa webu je po expiraci nebo omezená. Galerie na webu zůstává funkční, administrace je uzamčená.',
					)
				);
			}
		}

		if ( 'active' === $license['status'] ) {
			return array_merge(
				$base,
				array(
					'badge_class' => 'active',
					'badge_label' => 'Platná licence',
					'mode'        => 'Samostatná licence pluginu',
					'valid_to'    => $fmt_d( $license['valid_to'] ),
					'domain'      => (string) $license['domain'],
					'last_check'  => $fmt_dt( $license['last_success'] ),
					'message'     => '' !== $license['message'] ? $license['message'] : 'Plugin běží přes samostatnou licenci.',
				)
			);
		}

		$base['badge_class'] = $op ? 'active' : 'inactive';
		$base['badge_label'] = $op ? 'Platná licence' : 'Licence chybí';
		$base['valid_to']    = $fmt_d( $license['valid_to'] );
		$base['domain']      = (string) $license['domain'];
		$base['last_check']  = $fmt_dt( $license['last_check'] );
		$base['message']     = '' !== $license['message'] ? $license['message'] : 'Bez platné licence zůstává galerie na webu funkční, ale administrace je jen pro čtení.';
		return $base;
	}

	/** Vykreslí licenční kartu (dle SW design systému). */
	public function render_card() {
		$d = $this->get_panel_data();
		?>
		<div class="swg-card swg-card--licence">
			<div class="swg-card-head">
				<div class="swg-card-head-text">
					<h2>Licence pluginu</h2>
					<p class="swg-card-sub">Plugin může běžet buď v rámci platné správy webu, nebo přes samostatnou licenci.</p>
				</div>
				<span class="swg-licence-badge swg-licence-badge--<?php echo esc_attr( $d['badge_class'] ); ?>"><?php echo esc_html( $d['badge_label'] ); ?></span>
			</div>

			<div class="swg-licence-grid">
				<div class="swg-licence-item">
					<span class="swg-licence-label">Režim</span>
					<span class="swg-licence-value"><?php echo esc_html( $d['mode'] ); ?></span>
					<?php if ( '' !== $d['domain'] ) : ?>
						<span class="swg-licence-sub"><?php echo esc_html( $d['domain'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="swg-licence-item">
					<span class="swg-licence-label">Platnost do</span>
					<span class="swg-licence-value"><?php echo esc_html( $d['valid_to'] ); ?></span>
				</div>
				<div class="swg-licence-item">
					<span class="swg-licence-label">Poslední ověření</span>
					<span class="swg-licence-value"><?php echo esc_html( $d['last_check'] ); ?></span>
				</div>
			</div>

			<?php if ( '' !== $d['note'] ) : ?>
				<div class="swg-licence-note"><?php echo esc_html( $d['note'] ); ?></div>
			<?php elseif ( ! empty( $d['show_form'] ) ) : ?>
				<div class="swg-licence-form-wrap">
					<?php if ( '' !== $d['message'] ) : ?>
						<div class="swg-licence-note"><?php echo esc_html( $d['message'] ); ?></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swg-licence-form">
						<?php wp_nonce_field( 'swg_verify_license' ); ?>
						<input type="hidden" name="action" value="swg_verify_license">
						<label for="swg_license_key"><strong>Licenční kód pluginu</strong></label>
						<input type="text" id="swg_license_key" name="license_key" value="<?php echo esc_attr( $d['license_key'] ); ?>" class="regular-text" placeholder="SWLIC-…">
						<p class="description">Použijte jen pro samostatnou licenci pluginu. Pokud máte Správu webu, kód vyplňovat nemusíte.</p>
						<div class="swg-licence-actions">
							<button type="submit" class="button button-primary">Ověřit a uložit licenci</button>
							<?php if ( '' !== $d['license_key'] ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=swg_remove_license' ), 'swg_remove_license' ) ); ?>" class="button">Odebrat licenční kód</a>
							<?php endif; ?>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
