<?php
/**
 * Cardul cadou ca imagine (GD) și ca PDF de o pagină, fără biblioteci externe.
 *
 * Fundalul (chenar, titlu, etichete, logo, semnătură) este un șablon JPG pre-randat din
 * assets/img; aici se scriu doar textele variabile și codul de bare, în pozițiile măsurate
 * pe șablonul de 2550×1050 px (8,5 × 3,5 inch la 300 dpi).
 *
 * @package Sterea_Mini
 */

defined( 'ABSPATH' ) || exit;

class STM_Giftcard_Image {

	const W = 2550;
	const H = 1050;

	/**
	 * Fonturile folosite pentru textele variabile.
	 */
	private static function font( $name ) {
		$map = array(
			'serif'   => 'Cormorant-SemiBold.ttf',
			'serif-i' => 'Cormorant-SemiBoldItalic.ttf',
			'sans'    => 'Montserrat-Medium.ttf',
			'sans-b'  => 'Montserrat-Bold.ttf',
		);
		return STM_DIR . 'assets/fonts/' . $map[ $name ];
	}

	public static function available() {
		return function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagettftext' );
	}

	/**
	 * Randează cardul și îl salvează ca JPEG. Întoarce true/false.
	 *
	 * @param array  $d    { style: craciun|negru, kicker, occasion, date_label, date_day, date_year, to, from, message, terms, code }
	 * @param string $file Calea fișierului JPEG de scris.
	 */
	public static function render( $d, $file ) {
		if ( ! self::available() ) {
			return false;
		}
		$tpl = STM_DIR . 'assets/img/giftcard-' . ( 'craciun' === $d['style'] ? 'craciun' : 'negru' ) . '.jpg';
		$im  = @imagecreatefromjpeg( $tpl ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $im ) {
			return false;
		}
		$ink  = imagecolorallocate( $im, 17, 17, 17 );
		$soft = imagecolorallocate( $im, 40, 40, 40 );

		// Rândul mic de sus, centrat, cu litere rărite.
		self::spaced( $im, 'sans', 30, 11, $ink, 1010, 142, mb_strtoupper( $d['kicker'] ), 'center' );

		// Ocazia, pe cotorul din stânga, rotită 90°.
		self::spaced( $im, 'sans', 30, 9, $ink, 118, 690, mb_strtoupper( $d['occasion'] ), 'left', 90 );

		// Caseta cu data.
		self::spaced( $im, 'sans', 22, 3, $ink, 394, 549, mb_strtoupper( $d['date_label'] ), 'center' );
		self::text( $im, 'serif', 112, $ink, 394, 648, $d['date_day'], 'center' );
		self::spaced( $im, 'sans', 22, 2, $ink, 394, 688, mb_strtoupper( $d['date_year'] ), 'center' );

		// Pentru / Din partea (etichetele sunt în șablon). Numele lungi se scriu mai mic, apoi se taie.
		list( $size, $to ) = self::fit_size( 'serif', array( 46, 42, 38, 35, 32, 30 ), $d['to'], 375 );
		self::text( $im, 'serif', $size, $ink, 684, 578, $to, 'left' );
		list( $size, $from ) = self::fit_size( 'serif', array( 46, 42, 38, 35, 32, 30 ), $d['from'], 310 );
		self::text( $im, 'serif', $size, $ink, 748, 647, $from, 'left' );

		// Mesajul: 3 rânduri la mărimea normală; un mesaj lung se scrie mai mic, pe cel mult 4 rânduri.
		$layouts = array( array( 40, 52, 3 ), array( 36, 47, 3 ), array( 33, 43, 4 ), array( 30, 40, 4 ) );
		foreach ( $layouts as $layout ) {
			if ( count( self::split( 'serif-i', $layout[0], $d['message'], 620 ) ) <= $layout[2] ) {
				break;
			}
		}
		list( $size, $line_h, $max_lines ) = $layout;
		$y = 546 + $size;
		foreach ( self::wrap( 'serif-i', $size, $d['message'], 620, $max_lines ) as $line ) {
			self::text( $im, 'serif-i', $size, $soft, 1111, $y, $line, 'left' );
			$y += $line_h;
		}

		// Linia de termeni (pachet, valabilitate, unde se rezervă), 1–2 rânduri centrate.
		$tl = self::wrap( 'sans', 22, mb_strtoupper( $d['terms'] ), 1400, 2, 2 );
		$y  = 790;
		foreach ( $tl as $line ) {
			self::spaced( $im, 'sans', 22, 2, $ink, 1010, $y, $line, 'center' );
			$y += 36;
		}

		// Codul de bare (Code 128 B) și codul scris.
		self::barcode( $im, $d['code'], 1968, 651, 420, 94, $ink );
		self::spaced( $im, 'sans', 24, 7, $ink, 2178, 778, $d['code'], 'center' );

		$ok = imagejpeg( $im, $file, 90 );
		imagedestroy( $im );
		return (bool) $ok;
	}

	/* ---------- Text ---------- */

	/**
	 * GD primește mărimea în puncte la 96 dpi; mărimile de mai sus sunt în px, ca în șablon.
	 */
	private static function pt( $px ) {
		return $px * 0.75;
	}

	private static function width( $font, $size, $text ) {
		$box = imagettfbbox( self::pt( $size ), 0, self::font( $font ), $text );
		return $box ? abs( $box[2] - $box[0] ) : 0;
	}

	/**
	 * Text simplu; $x este stânga sau centrul, după $align; $y este linia de bază.
	 */
	private static function text( $im, $font, $size, $color, $x, $y, $text, $align = 'left' ) {
		if ( '' === $text ) {
			return;
		}
		if ( 'center' === $align ) {
			$x -= self::width( $font, $size, $text ) / 2;
		}
		imagettftext( $im, self::pt( $size ), 0, (int) round( $x ), (int) round( $y ), $color, self::font( $font ), $text );
	}

	/**
	 * Text cu litere rărite (letter-spacing), ca în CSS; opțional rotit (90° = de jos în sus).
	 */
	private static function spaced( $im, $font, $size, $spacing, $color, $x, $y, $text, $align = 'left', $angle = 0 ) {
		if ( '' === $text ) {
			return;
		}
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$total = 0;
		foreach ( $chars as $c ) {
			$total += self::width( $font, $size, $c ) + $spacing;
		}
		$total -= $spacing;
		if ( 'center' === $align ) {
			$x -= $total / 2;
		}
		$cursor = 0;
		foreach ( $chars as $c ) {
			if ( 90 === $angle ) {
				imagettftext( $im, self::pt( $size ), 90, (int) round( $x ), (int) round( $y - $cursor ), $color, self::font( $font ), $c );
			} else {
				imagettftext( $im, self::pt( $size ), 0, (int) round( $x + $cursor ), (int) round( $y ), $color, self::font( $font ), $c );
			}
			$cursor += self::width( $font, $size, $c ) + $spacing;
		}
	}

	/**
	 * Lățimea unui text cu litere rărite.
	 */
	private static function spaced_width( $font, $size, $text, $spacing ) {
		return self::width( $font, $size, $text ) + $spacing * max( 0, mb_strlen( $text ) - 1 );
	}

	/**
	 * Taie textul cu „…” dacă depășește lățimea.
	 */
	private static function fit( $font, $size, $text, $max, $spacing = 0 ) {
		$text = trim( (string) $text );
		while ( mb_strlen( $text ) > 1 && self::spaced_width( $font, $size, $text, $spacing ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, -2 ) ) . '…';
		}
		return $text;
	}

	/**
	 * Prima mărime din listă la care textul încape; altfel cea mai mică, cu textul tăiat.
	 *
	 * @return array { mărime, text }
	 */
	private static function fit_size( $font, $sizes, $text, $max ) {
		$text = trim( (string) $text );
		foreach ( $sizes as $size ) {
			if ( self::width( $font, $size, $text ) <= $max ) {
				return array( $size, $text );
			}
		}
		$size = end( $sizes );
		return array( $size, self::fit( $font, $size, $text, $max ) );
	}

	/**
	 * Împarte textul pe rânduri de cel mult $max px (fără limită de rânduri).
	 */
	private static function split( $font, $size, $text, $max, $spacing = 0 ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$cur   = '';
		foreach ( $words as $word ) {
			$try = '' === $cur ? $word : $cur . ' ' . $word;
			if ( '' === $cur || self::spaced_width( $font, $size, $try, $spacing ) <= $max ) {
				$cur = $try;
				continue;
			}
			$lines[] = $cur;
			$cur     = $word;
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines;
	}

	/**
	 * Ca split(), dar pe cel mult $max_lines rânduri; ultimul primește „…” dacă nu încape tot.
	 * Un cuvânt mai lung decât rândul se taie și el cu „…”.
	 */
	private static function wrap( $font, $size, $text, $max, $max_lines, $spacing = 0 ) {
		$lines = self::split( $font, $size, $text, $max, $spacing );
		$cut   = count( $lines ) > $max_lines;
		$lines = array_slice( $lines, 0, $max_lines );
		foreach ( $lines as $i => $line ) {
			$last        = $cut && $i === count( $lines ) - 1;
			$lines[ $i ] = self::fit( $font, $size, $last ? $line . '…' : $line, $max, $spacing );
		}
		return $lines;
	}

	/* ---------- Code 128 B ---------- */

	private static function code128_patterns() {
		return array(
			'212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
			'221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
			'231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
			'314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
			'111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
			'114131', '311141', '411131', '211412', '211214', '211232', '2331112',
		);
	}

	/**
	 * Desenează codul $code (ASCII 32–126) ca Code 128 B, centrat în dreptunghiul dat.
	 */
	private static function barcode( $im, $code, $x, $y, $w, $h, $color ) {
		$code = preg_replace( '/[^\x20-\x7E]/', '', (string) $code );
		if ( '' === $code ) {
			return;
		}
		$p     = self::code128_patterns();
		$vals  = array( 104 ); // Start B.
		$sum   = 104;
		$chars = str_split( $code );
		foreach ( $chars as $i => $c ) {
			$v      = ord( $c ) - 32;
			$vals[] = $v;
			$sum   += $v * ( $i + 1 );
		}
		$vals[] = $sum % 103;
		$vals[] = 106; // Stop.
		$seq    = '';
		foreach ( $vals as $v ) {
			$seq .= $p[ $v ];
		}
		$units  = array_sum( str_split( $seq ) );
		$module = max( 1, (int) floor( $w / $units ) );
		$total  = $module * $units;
		$cx     = (int) round( $x + ( $w - $total ) / 2 );
		$dark   = true;
		foreach ( str_split( $seq ) as $n ) {
			$ww = $module * (int) $n;
			if ( $dark ) {
				imagefilledrectangle( $im, $cx, $y, $cx + $ww - 1, $y + $h, $color );
			}
			$cx  += $ww;
			$dark = ! $dark;
		}
	}

	/* ---------- PDF ---------- */

	/**
	 * PDF de o pagină (8,5 × 3,5 inch) care conține imaginea JPEG a cardului.
	 */
	public static function pdf( $jpeg_file, $pdf_file ) {
		$data = @file_get_contents( $jpeg_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		if ( false === $data ) {
			return false;
		}
		$size = @getimagesize( $jpeg_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || IMAGETYPE_JPEG !== $size[2] ) {
			return false;
		}
		$pw = 8.5 * 72;
		$ph = 3.5 * 72;
		$objs = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$objs[] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>', $pw, $ph );
		$objs[] = sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $size[0], $size[1], strlen( $data ) ) . $data . "\nendstream";
		$content = sprintf( "q %.2F 0 0 %.2F 0 0 cm /Im1 Do Q", $pw, $ph );
		$objs[]  = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content );
		$objs[]  = '<< /Title (Card cadou) /Producer (Sterea Mini-Sedinte) >>';

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $i => $body ) {
			$offsets[] = strlen( $out );
			$out      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= 'xref' . "\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $o ) {
			$out .= sprintf( "%010d 00000 n \n", $o );
		}
		$out .= sprintf( "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objs ) + 1, count( $objs ), $xref );
		return false !== file_put_contents( $pdf_file, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
