<?php
defined( 'ABSPATH' ) || exit;

final class YoOhw_COS_Admin_UI {

	public static function render_empty_state( string $title, string $message = '', array $actions = array(), string $variant = '' ): void {
		echo wp_kses_post( self::get_empty_state( $title, $message, $actions, $variant ) );
	}

	public static function get_empty_state( string $title, string $message = '', array $actions = array(), string $variant = '' ): string {
		$classes = array( 'yoohw-cos-empty-state' );

		if ( '' !== $variant ) {
			$classes[] = 'yoohw-cos-empty-state--' . sanitize_html_class( $variant );
		}

		$output  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		$output .= '<strong>' . esc_html( $title ) . '</strong>';

		if ( '' !== $message ) {
			$output .= '<p>' . esc_html( $message ) . '</p>';
		}

		if ( ! empty( $actions ) ) {
			$output .= '<p class="yoohw-cos-empty-state__actions">';

			foreach ( $actions as $action ) {
				if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
					continue;
				}

				$class = ! empty( $action['class'] )
					? self::sanitize_class_list( (string) $action['class'] )
					: 'button';

				$output .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a> ';
			}

			$output .= '</p>';
		}

		$output .= '</div>';

		return $output;
	}

	public static function has_request_filters( array $keys ): bool {
		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filters follow native WordPress admin behavior.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only value is sanitized after preserving scalar/array shape.
			$value = wp_unslash( $_GET[ $key ] );

			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_text_field', $value );

				if ( ! empty( array_filter( $value ) ) ) {
					return true;
				}

				continue;
			}

			$value = trim( sanitize_text_field( (string) $value ) );

			if ( '' !== $value && '-1' !== $value && '0' !== $value ) {
				return true;
			}
		}

		return false;
	}

	public static function get_readable_chip_style( string $background_color ): string {
		$background_color = sanitize_hex_color( $background_color ) ?: '#f0f0f1';
		$text_color       = self::get_readable_text_color( $background_color );

		return sprintf(
			'background:%1$s;color:%2$s;border-color:%1$s;',
			$background_color,
			$text_color
		);
	}

	private static function get_readable_text_color( string $background_color ): string {
		$hex = ltrim( sanitize_hex_color( $background_color ) ?: '#f0f0f1', '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#1d2327';
		}

		$red   = self::normalize_color_channel( hexdec( substr( $hex, 0, 2 ) ) );
		$green = self::normalize_color_channel( hexdec( substr( $hex, 2, 2 ) ) );
		$blue  = self::normalize_color_channel( hexdec( substr( $hex, 4, 2 ) ) );

		$luminance      = ( 0.2126 * $red ) + ( 0.7152 * $green ) + ( 0.0722 * $blue );
		$black_contrast = ( $luminance + 0.05 ) / 0.05;
		$white_contrast = 1.05 / ( $luminance + 0.05 );

		return $white_contrast > $black_contrast ? '#ffffff' : '#1d2327';
	}

	private static function normalize_color_channel( int $channel ): float {
		$value = max( 0, min( 255, $channel ) ) / 255;

		return $value <= 0.03928
			? $value / 12.92
			: pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}

	private static function sanitize_class_list( string $class_list ): string {
		$classes = preg_split( '/\s+/', $class_list );

		if ( ! is_array( $classes ) ) {
			return 'button';
		}

		$classes = array_filter(
			array_map(
				static function ( string $class ): string {
					return sanitize_html_class( $class );
				},
				$classes
			)
		);

		return ! empty( $classes ) ? implode( ' ', $classes ) : 'button';
	}
}
