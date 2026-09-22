<?php

namespace NovaExpress\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Генератор штрих-кодів Code 128 у чистому SVG (без зовнішніх залежностей).
 */
class Barcode {

	private static array $patterns = array(
		'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
		'221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
		'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
		'212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
		'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
		'231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
		'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
		'112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
		'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
		'214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
		'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
	);

	/**
	 * Генерує SVG-зображення штрих-коду Code 128 (Auto C/B).
	 *
	 * @param string $code Номер ТТН або код.
	 * @param int    $height Висота штрих-коду в пікселях.
	 * @param int    $bar_width Базова ширина одного модуля (px).
	 * @return string SVG-розмітка.
	 */
	public static function code128_svg( string $code, int $height = 54, int $bar_width = 2 ): string {
		$code = trim( $code );
		if ( '' === $code ) {
			return '';
		}

		$is_digits = ctype_digit( $code );
		$values    = array();
		$checksum  = 0;

		// Якщо тільки цифри і парна довжина — використовуємо компактний Subset C (2 цифри на символ).
		if ( $is_digits && 0 === ( strlen( $code ) % 2 ) ) {
			$values[] = 105; // Start C.
			$checksum = 105;
			$pairs    = str_split( $code, 2 );
			foreach ( $pairs as $i => $pair ) {
				$val      = (int) $pair;
				$values[] = $val;
				$checksum += $val * ( $i + 1 );
			}
		} else {
			$values[] = 104; // Start B.
			$checksum = 104;
			$len      = strlen( $code );
			for ( $i = 0; $i < $len; $i++ ) {
				$val      = ord( $code[ $i ] ) - 32;
				$values[] = $val;
				$checksum += $val * ( $i + 1 );
			}
		}

		$check_digit = $checksum % 103;
		$values[]    = $check_digit;
		$values[]    = 106; // Stop pattern.

		$bars = '';
		foreach ( $values as $val ) {
			if ( isset( self::$patterns[ $val ] ) ) {
				$bars .= self::$patterns[ $val ];
			}
		}

		$total_modules = 0;
		$bars_len      = strlen( $bars );
		for ( $i = 0; $i < $bars_len; $i++ ) {
			$total_modules += (int) $bars[ $i ];
		}

		$svg_width = $total_modules * $bar_width;
		$svg       = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" height="%d" style="display:block;margin:0 auto;max-width:%dpx;">',
			$svg_width,
			$height,
			$height,
			$svg_width
		);

		$x      = 0;
		$is_bar = true;
		for ( $i = 0; $i < $bars_len; $i++ ) {
			$w = (int) $bars[ $i ] * $bar_width;
			if ( $is_bar ) {
				$svg .= sprintf( '<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>', $x, $w, $height );
			}
			$x      += $w;
			$is_bar = ! $is_bar;
		}

		$svg .= '</svg>';

		return $svg;
	}
}
