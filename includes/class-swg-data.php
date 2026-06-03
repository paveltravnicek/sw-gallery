<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Datová vrstva fotogalerie.
 *
 * Struktura uložená v option SWG_OPTION:
 * [
 *   'categories' => [
 *      [
 *        'id'    => 'cat_xxxxx',
 *        'title' => 'Exteriéry',
 *        'slug'  => 'exterieery',
 *        'subcategories' => [
 *           [ 'id' => 'sub_xxxxx', 'title' => '1. etapa', 'slug' => '1-etapa', 'photos' => [12,15,18] ],
 *        ],
 *      ],
 *   ],
 * ]
 */
class SWG_Data {

	/** Vrátí celou strukturu (vždy s klíčem 'categories'). */
	public static function get() {
		$data = get_option( SWG_OPTION, array() );
		if ( ! is_array( $data ) || ! isset( $data['categories'] ) || ! is_array( $data['categories'] ) ) {
			$data = array( 'categories' => array() );
		}
		return $data;
	}

	/** Uloží už zsanitovanou strukturu. */
	public static function save( $data ) {
		update_option( SWG_OPTION, $data );
	}

	/**
	 * Zsanituje strukturu přijatou z adminu (JSON dekódovaný do pole).
	 * - tituly přes sanitize_text_field
	 * - fotky přetypované na int, vyhozené nuly a duplicity
	 * - slugy přegenerované a unikátní
	 */
	public static function sanitize( $raw ) {
		$out = array( 'categories' => array() );
		if ( ! is_array( $raw ) || empty( $raw['categories'] ) || ! is_array( $raw['categories'] ) ) {
			return $out;
		}

		$used_cat_slugs = array();

		foreach ( $raw['categories'] as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$title = isset( $cat['title'] ) ? sanitize_text_field( $cat['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}
			$slug = self::unique_slug( $title, $used_cat_slugs );
			$used_cat_slugs[] = $slug;

			$category = array(
				'id'            => self::clean_id( isset( $cat['id'] ) ? $cat['id'] : '', 'cat' ),
				'title'         => $title,
				'slug'          => $slug,
				'subcategories' => array(),
			);

			if ( ! empty( $cat['subcategories'] ) && is_array( $cat['subcategories'] ) ) {
				$used_sub_slugs = array();
				foreach ( $cat['subcategories'] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$sub_title = isset( $sub['title'] ) ? sanitize_text_field( $sub['title'] ) : '';
					if ( '' === $sub_title ) {
						continue;
					}
					$sub_slug = self::unique_slug( $sub_title, $used_sub_slugs );
					$used_sub_slugs[] = $sub_slug;

					$photos = array();
					if ( ! empty( $sub['photos'] ) && is_array( $sub['photos'] ) ) {
						foreach ( $sub['photos'] as $pid ) {
							$pid = (int) $pid;
							if ( $pid > 0 && ! in_array( $pid, $photos, true ) ) {
								$photos[] = $pid;
							}
						}
					}

					$category['subcategories'][] = array(
						'id'     => self::clean_id( isset( $sub['id'] ) ? $sub['id'] : '', 'sub' ),
						'title'  => $sub_title,
						'slug'   => $sub_slug,
						'photos' => $photos,
					);
				}
			}

			$out['categories'][] = $category;
		}

		return $out;
	}

	/** Vyčistí / vygeneruje ID prvku. */
	private static function clean_id( $id, $prefix ) {
		$id = preg_replace( '/[^a-z0-9_]/', '', (string) $id );
		if ( '' === $id ) {
			$id = $prefix . '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
		}
		return $id;
	}

	/** Unikátní slug v rámci dané úrovně. */
	private static function unique_slug( $title, $existing ) {
		$base = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'item';
		}
		$slug = $base;
		$i    = 2;
		while ( in_array( $slug, $existing, true ) ) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	/**
	 * Posbírá všechna ID fotek napříč strukturou (pro localizaci miniatur do adminu).
	 */
	public static function collect_ids( $data ) {
		$ids = array();
		if ( empty( $data['categories'] ) ) {
			return $ids;
		}
		foreach ( $data['categories'] as $cat ) {
			if ( empty( $cat['subcategories'] ) ) {
				continue;
			}
			foreach ( $cat['subcategories'] as $sub ) {
				if ( empty( $sub['photos'] ) ) {
					continue;
				}
				foreach ( $sub['photos'] as $pid ) {
					$ids[] = (int) $pid;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Mapa ID => thumbnail URL pro admin. */
	public static function thumb_map( $ids ) {
		$map = array();
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_src( $id, 'thumbnail' );
			if ( $src ) {
				$map[ $id ] = $src[0];
			}
		}
		return $map;
	}

	/** Při aktivaci nic nepředvyplňujeme, jen zajistíme existenci option. */
	public static function maybe_seed() {
		if ( false === get_option( SWG_OPTION, false ) ) {
			add_option( SWG_OPTION, array( 'categories' => array() ) );
		}
	}
}
