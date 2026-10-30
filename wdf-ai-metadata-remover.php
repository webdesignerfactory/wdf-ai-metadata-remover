<?php
/**
 * Plugin Name: WDF AI Metadata Remover
 * Plugin URI:  https://webdesignerfactory.com/
 * Description: Free plugin by Web Designer Factory. Removes hidden image metadata (EXIF, GPS, XMP, IPTC, C2PA Content Credentials, JUMBF, text chunks) from JPEG, PNG, WebP and AVIF on upload, with a scan-then-clean tool for the existing library. Lossless: pixels are never re-encoded.
 * Version:     1.1.0
 * Author:      Web Designer Factory
 * Author URI:  https://webdesignerfactory.com
 * License:     GPL-2.0-or-later
 * Text Domain: wdf-ai-metadata-remover
 * Requires at least: 5.8
 * Requires PHP: 7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Stripper: pure PHP, format aware, lossless.
 * ---------------------------------------------------------------------- */
class WDFAIMR_Stripper {

	private $keep_icc;
	private $dry_run;
	private $dropped = false;
	public $found = array();

	public function __construct( $keep_icc = true, $dry_run = false ) {
		$this->keep_icc = (bool) $keep_icc;
		$this->dry_run  = (bool) $dry_run;
	}

	/**
	 * Clean one image file in place.
	 *
	 * @return array { changed: bool, found: string[], error: string|null }
	 */
	public function clean_file( $path ) {
		$this->found   = array();
		$this->dropped = false;

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $this->result( false, 'File not readable' );
		}
		$data = file_get_contents( $path );
		if ( false === $data || strlen( $data ) < 12 ) {
			return $this->result( false, 'Empty file' );
		}

		if ( "\xFF\xD8" === substr( $data, 0, 2 ) ) {
			$out = $this->clean_jpeg( $data );
		} elseif ( "\x89PNG\r\n\x1a\n" === substr( $data, 0, 8 ) ) {
			$out = $this->clean_png( $data );
		} elseif ( 'RIFF' === substr( $data, 0, 4 ) && 'WEBP' === substr( $data, 8, 4 ) ) {
			$out = $this->clean_webp( $data );
		} elseif ( 'ftyp' === substr( $data, 4, 4 ) && preg_match( '/^(avif|avis|heic|heix|heif|mif1|msf1)/', substr( $data, 8, 4 ) ) ) {
			$out = $this->clean_isobmff( $data );
		} else {
			return $this->clean_with_imagick( $path );
		}

		if ( null === $out ) {
			return $this->result( false, 'Could not parse image structure' );
		}
		if ( ! $this->dropped ) {
			return $this->result( false );
		}
		if ( $this->dry_run ) {
			return $this->result( false );
		}
		if ( ! is_writable( $path ) ) {
			return $this->result( false, 'File not writable' );
		}

		try {
			$rand = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			$rand = uniqid( '', true );
		}
		$tmp = dirname( $path ) . '/.' . $rand . '.wdfaimr.tmp';
		$fh  = @fopen( $tmp, 'x' ); // Exclusive create: fails if anything already sits at that path (file or symlink).
		if ( ! $fh ) {
			return $this->result( false, 'Write failed' );
		}
		$ok = ( fwrite( $fh, $out ) === strlen( $out ) );
		fclose( $fh );
		if ( ! $ok || ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return $this->result( false, 'Write failed' );
		}
		return $this->result( true );
	}

	private function result( $changed, $error = null ) {
		return array(
			'changed' => (bool) $changed,
			'dirty'   => (bool) $this->dropped,
			'found'   => array_values( array_unique( $this->found ) ),
			'error'   => $error,
		);
	}

	/* ---------------- JPEG ---------------- */

	private function clean_jpeg( $d ) {
		$len         = strlen( $d );
		$pos         = 2;
		$out         = "\xFF\xD8";
		$orientation = 1;
		$orient_done = false;

		while ( $pos < $len ) {
			// Skip fill bytes (0xFF padding before a marker).
			while ( $pos + 1 < $len && "\xFF" === $d[ $pos ] && "\xFF" === $d[ $pos + 1 ] ) {
				$pos++;
			}
			if ( $pos + 1 >= $len || "\xFF" !== $d[ $pos ] ) {
				return null;
			}
			$marker = ord( $d[ $pos + 1 ] );

			if ( 0xDA === $marker ) { // Start Of Scan: everything after is image data, copy verbatim.
				if ( ! $orient_done && $orientation > 1 ) {
					$out .= $this->minimal_exif( $orientation );
				}
				$out .= substr( $d, $pos );
				return $out;
			}
			if ( 0xD9 === $marker ) { // End Of Image.
				$out .= substr( $d, $pos, 2 );
				return $out;
			}
			if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) { // Standalone markers.
				$out .= substr( $d, $pos, 2 );
				$pos += 2;
				continue;
			}
			if ( $pos + 4 > $len ) {
				return null;
			}
			$seglen = ( ord( $d[ $pos + 2 ] ) << 8 ) | ord( $d[ $pos + 3 ] );
			if ( $seglen < 2 || $pos + 2 + $seglen > $len ) {
				return null;
			}
			$payload = substr( $d, $pos + 4, $seglen - 2 );
			if ( ! $orient_done && $orientation > 1 && ( $marker < 0xE0 || $marker > 0xEF ) ) {
				$out        .= $this->minimal_exif( $orientation );
				$orient_done = true;
			}
			if ( $this->jpeg_keep( $marker, $payload ) ) {
				$out .= substr( $d, $pos, 2 + $seglen );
			} else {
				if ( 0xE1 === $marker && 0 === strncmp( $payload, "Exif\0\0", 6 ) ) {
					$o = $this->exif_orientation( substr( $payload, 6 ) );
					if ( $o > 1 && $o <= 8 ) {
						$orientation = $o;
					}
				}
				$this->record( $payload );
			}
			$pos += 2 + $seglen;
		}
		return $out;
	}

	/** Read the Orientation tag (0x0112) from a TIFF-structured EXIF payload. */
	private function exif_orientation( $t ) {
		if ( strlen( $t ) < 14 ) {
			return 1;
		}
		$le = 'II' === substr( $t, 0, 2 );
		if ( ! $le && 'MM' !== substr( $t, 0, 2 ) ) {
			return 1;
		}
		$u16 = function ( $o ) use ( $t, $le ) {
			if ( $o + 2 > strlen( $t ) ) {
				return 0;
			}
			$u = unpack( $le ? 'v' : 'n', substr( $t, $o, 2 ) );
			return $u[1];
		};
		$u32 = function ( $o ) use ( $t, $le ) {
			if ( $o + 4 > strlen( $t ) ) {
				return 0;
			}
			$u = unpack( $le ? 'V' : 'N', substr( $t, $o, 4 ) );
			return $u[1];
		};
		$ifd = $u32( 4 );
		$n   = $u16( $ifd );
		if ( $n > 500 ) {
			return 1;
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$e = $ifd + 2 + $i * 12;
			if ( 0x0112 === $u16( $e ) ) {
				return $u16( $e + 8 );
			}
		}
		return 1;
	}

	private function minimal_exif( $orientation ) {
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack( 'n', $orientation ) . "\x00\x00" . "\x00\x00\x00\x00";
		$app1 = "Exif\x00\x00" . $tiff;
		return "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1;
	}

	private function jpeg_keep( $marker, $payload ) {
		switch ( $marker ) {
			case 0xE0: // APP0 JFIF.
				return true;
			case 0xEE: // APP14 Adobe colour transform, required for correct decoding.
				return true;
			case 0xE2: // APP2: ICC profile, or FlashPix / MPF / other containers.
				return $this->keep_icc && 0 === strncmp( $payload, "ICC_PROFILE\0", 12 );
			case 0xFE: // COM comment.
				return false;
		}
		if ( $marker >= 0xE1 && $marker <= 0xEF ) {
			// APP1 EXIF/XMP, APP11 JUMBF/C2PA, APP13 IPTC/Photoshop, and the rest.
			return false;
		}
		return true; // DQT, DHT, SOF, DRI and other structural segments.
	}

	/* ---------------- PNG ---------------- */

	private function clean_png( $d ) {
		$len  = strlen( $d );
		$pos  = 8;
		$out  = substr( $d, 0, 8 );
		$drop = array( 'tEXt', 'zTXt', 'iTXt', 'eXIf', 'caBX', 'tIME' ); // caBX carries C2PA.

		while ( $pos + 8 <= $len ) {
			$u     = unpack( 'Nlen', substr( $d, $pos, 4 ) );
			$clen  = $u['len'];
			$type  = substr( $d, $pos + 4, 4 );
			$total = 12 + $clen;
			if ( $pos + $total > $len ) {
				return null;
			}
			$keep = true;
			if ( in_array( $type, $drop, true ) ) {
				$keep = false;
			} elseif ( 'iCCP' === $type && ! $this->keep_icc ) {
				$keep = false;
			}
			if ( $keep ) {
				$out .= substr( $d, $pos, $total );
			} else {
				$this->record( substr( $d, $pos + 8, $clen ) );
			}
			$pos += $total;
			if ( 'IEND' === $type ) {
				break;
			}
		}
		return $out;
	}

	/* ---------------- WebP ---------------- */

	private function clean_webp( $d ) {
		$len           = strlen( $d );
		$pos           = 12;
		$chunks        = '';
		$drop          = array( 'EXIF', 'XMP ', 'C2PA' );
		$cleared_flags = 0;
		$vp8x_offset   = -1;

		while ( $pos + 8 <= $len ) {
			$fourcc = substr( $d, $pos, 4 );
			$u      = unpack( 'Vsize', substr( $d, $pos + 4, 4 ) );
			$size   = $u['size'];
			$padded = $size + ( $size & 1 );
			if ( $pos + 8 + $size > $len ) {
				return null;
			}
			$chunk = substr( $d, $pos, min( 8 + $padded, $len - $pos ) );
			if ( $size & 1 && strlen( $chunk ) < 8 + $padded ) {
				$chunk .= "\0"; // Normalise a missing pad byte.
			}
			$keep = true;
			if ( in_array( $fourcc, $drop, true ) ) {
				$keep = false;
				if ( 'EXIF' === $fourcc ) {
					$cleared_flags |= 0x08;
				} elseif ( 'XMP ' === $fourcc ) {
					$cleared_flags |= 0x04;
				}
			} elseif ( 'ICCP' === $fourcc && ! $this->keep_icc ) {
				$keep           = false;
				$cleared_flags |= 0x20;
			}
			if ( $keep ) {
				if ( 'VP8X' === $fourcc ) {
					$vp8x_offset = strlen( $chunks );
				}
				$chunks .= $chunk;
			} else {
				$this->record( substr( $d, $pos + 8, $size ) );
			}
			$pos += 8 + $padded;
		}

		if ( ! $this->dropped ) {
			return $d;
		}
		if ( $vp8x_offset >= 0 && $cleared_flags ) {
			$flags                        = ord( $chunks[ $vp8x_offset + 8 ] );
			$chunks[ $vp8x_offset + 8 ]   = chr( $flags & ~$cleared_flags );
		}
		return 'RIFF' . pack( 'V', 4 + strlen( $chunks ) ) . 'WEBP' . $chunks;
	}


	/* ---------------- AVIF / HEIF (ISOBMFF) ----------------
	 * Metadata lives in items of type Exif, mime (XMP, C2PA) or jumb inside meta/iinf,
	 * with payloads located by iloc. We blank the payload bytes in place and rename the
	 * item type so decoders ignore it. No box sizes or offsets change: fully lossless.
	 */

	private function clean_isobmff( $d ) {
		$len   = strlen( $d );
		$boxes = $this->iso_children( $d, 0, $len );
		$meta  = null;
		foreach ( $boxes as $b ) {
			if ( 'meta' === $b['type'] ) {
				$meta = $b;
				break;
			}
		}
		if ( ! $meta ) {
			return $d;
		}
		$children = $this->iso_children( $d, $meta['start'] + 4, $meta['end'] ); // FullBox: skip version/flags.
		$infe     = array();
		$iloc     = null;
		$idat     = null;
		foreach ( $children as $c ) {
			if ( 'iinf' === $c['type'] ) {
				$v    = ord( $d[ $c['start'] ] );
				$skip = $c['start'] + 4 + ( 0 === $v ? 2 : 4 );
				foreach ( $this->iso_children( $d, $skip, $c['end'] ) as $e ) {
					if ( 'infe' === $e['type'] ) {
						$infe[] = $e;
					}
				}
			} elseif ( 'iloc' === $c['type'] ) {
				$iloc = $c;
			} elseif ( 'idat' === $c['type'] ) {
				$idat = $c;
			}
		}
		if ( ! $infe || ! $iloc ) {
			return $d;
		}

		// Parse infe entries we want to drop.
		$targets = array(); // item_id => array( 'type_pos' => int, 'label' => string )
		foreach ( $infe as $e ) {
			$pos = $e['start'];
			$v   = ord( $d[ $pos ] );
			$pos += 4;
			if ( $v < 2 ) {
				continue;
			}
			if ( 2 === $v ) {
				$id   = ( ord( $d[ $pos ] ) << 8 ) | ord( $d[ $pos + 1 ] );
				$pos += 2;
			} else {
				$u    = unpack( 'N', substr( $d, $pos, 4 ) );
				$id   = $u[1];
				$pos += 4;
			}
			$pos += 2; // protection index
			$type_pos = $pos;
			$type     = substr( $d, $pos, 4 );
			$pos     += 4;
			$name_end = strpos( $d, "\0", $pos );
			$name_pos = $pos;
			$name_len = false === $name_end ? 0 : $name_end - $pos;
			$pos      = false === $name_end ? $e['end'] : $name_end + 1;
			$drop     = false;
			if ( 'Exif' === $type || 'jumb' === $type ) {
				$drop = true;
			} elseif ( 'mime' === $type || 'uri ' === $type ) {
				$ct_end = strpos( $d, "\0", $pos );
				$ct     = strtolower( substr( $d, $pos, ( false === $ct_end ? $e['end'] : $ct_end ) - $pos ) );
				if ( false !== strpos( $ct, 'xmp' ) || false !== strpos( $ct, 'c2pa' ) || false !== strpos( $ct, 'jumb' ) || false !== strpos( $ct, 'xml' ) ) {
					$drop = true;
				}
			}
			if ( $drop ) {
				$targets[ $id ] = $type_pos;
				if ( $name_len > 0 ) {
					$d = substr_replace( $d, str_repeat( 'x', $name_len ), $name_pos, $name_len );
				}
			}
		}
		if ( ! $targets ) {
			return $d;
		}

		// Parse iloc to find extents of target items. Every read is bounds-checked against the box end.
		$pos = $iloc['start'];
		$end = $iloc['end'];
		if ( $pos + 8 > $end ) {
			return $d;
		}
		$v   = ord( $d[ $pos ] );
		$pos += 4;
		$b1  = ord( $d[ $pos++ ] );
		$b2  = ord( $d[ $pos++ ] );
		$offset_size = $b1 >> 4;
		$length_size = $b1 & 15;
		$base_size   = $b2 >> 4;
		$index_size  = $v >= 1 ? ( $b2 & 15 ) : 0;
		if ( ! in_array( $offset_size, array( 0, 4, 8 ), true ) || ! in_array( $length_size, array( 0, 4, 8 ), true ) || ! in_array( $base_size, array( 0, 4, 8 ), true ) || ! in_array( $index_size, array( 0, 4, 8 ), true ) ) {
			return $d;
		}
		$truncated = false;
		$read      = function ( $size ) use ( &$pos, &$truncated, $d, $end ) {
			if ( $pos + $size > $end ) {
				$truncated = true;
				$pos       = $end;
				return 0;
			}
			$val = 0;
			for ( $i = 0; $i < $size; $i++ ) {
				$val = ( $val * 256 ) + ord( $d[ $pos++ ] );
			}
			return $val;
		};
		$count  = $read( $v < 2 ? 2 : 4 );
		$ranges = array();
		for ( $i = 0; $i < $count && ! $truncated; $i++ ) {
			$id     = $read( $v < 2 ? 2 : 4 );
			$method = 0;
			if ( $v >= 1 ) {
				$method = $read( 2 ) & 15;
			}
			$read( 2 ); // data_reference_index
			$base = $read( $base_size );
			$ext  = $read( 2 );
			for ( $j = 0; $j < $ext && ! $truncated; $j++ ) {
				if ( $v >= 1 && $index_size > 0 ) {
					$read( $index_size );
				}
				$off = $read( $offset_size );
				$ln  = $read( $length_size );
				if ( $truncated ) {
					break;
				}
				if ( isset( $targets[ $id ] ) ) {
					if ( 0 === $method ) {
						$ranges[] = array( $base + $off, $ln );
					} elseif ( 1 === $method && $idat ) {
						$ranges[] = array( $idat['start'] + $base + $off, $ln );
					}
				}
			}
		}

		if ( $truncated ) {
			return $d; // Malformed iloc: leave the file untouched rather than guess.
		}
		foreach ( $ranges as $r ) {
			list( $start, $ln ) = $r;
			if ( $start < 0 || $ln <= 0 || $start + $ln > $len ) {
				continue;
			}
			$this->record( substr( $d, $start, $ln ) );
			$d = substr_replace( $d, str_repeat( "\0", $ln ), $start, $ln );
		}
		foreach ( $targets as $type_pos ) {
			$this->dropped = true;
			$d = substr_replace( $d, 'skip', $type_pos, 4 );
		}
		return $d;
	}

	/** List boxes between $from and $to: array of [type, start (payload), end]. */
	private function iso_children( $d, $from, $to ) {
		$out = array();
		$pos = $from;
		while ( $pos + 8 <= $to ) {
			$u    = unpack( 'N', substr( $d, $pos, 4 ) );
			$size = $u[1];
			$type = substr( $d, $pos + 4, 4 );
			$hdr  = 8;
			if ( 1 === $size ) {
				if ( $pos + 16 > $to ) {
					break;
				}
				$u    = unpack( 'J', substr( $d, $pos + 8, 8 ) );
				$size = $u[1];
				$hdr  = 16;
			} elseif ( 0 === $size ) {
				$size = $to - $pos;
			}
			if ( $size < $hdr || $pos + $size > $to ) {
				break;
			}
			$out[] = array(
				'type'  => $type,
				'start' => $pos + $hdr,
				'end'   => $pos + $size,
			);
			$pos += $size;
		}
		return $out;
	}

	/* ---------------- Fallback for GIF / TIFF / BMP etc. ---------------- */

	private function clean_with_imagick( $path ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return $this->result( false, 'Unsupported format' );
		}
		if ( $this->dry_run ) {
			try {
				$im = new Imagick( $path );
				$p  = $im->getImageProfiles( '*', false );
				$im->clear();
				$p = array_diff( $p, array( 'icc' ) );
				if ( $p ) {
					$this->dropped = true;
					$this->found[] = 'Embedded profiles (' . implode( ', ', $p ) . ')';
				}
				return $this->result( false );
			} catch ( Exception $e ) {
				return $this->result( false, $e->getMessage() );
			}
		}
		try {
			$im  = new Imagick( $path );
			$icc = $this->keep_icc ? $im->getImageProfiles( 'icc', true ) : array();
			$im->stripImage();
			if ( ! empty( $icc['icc'] ) ) {
				$im->profileImage( 'icc', $icc['icc'] );
			}
			$im->writeImage( $path );
			$im->clear();
			$this->dropped = true;
			$this->found[] = 'All metadata (Imagick)';
			return $this->result( true );
		} catch ( Exception $e ) {
			return $this->result( false, $e->getMessage() );
		}
	}

	/* ---------------- Detection of what was removed ---------------- */

	private function record( $payload ) {
		$this->dropped = true;
		$signatures    = array(
			'C2PA / Content Credentials'        => array( 'c2pa', 'jumb', 'contentauth', 'claim_generator' ),
			'IPTC AI source flag'               => array( 'trainedAlgorithmicMedia', 'DigitalSourceType' ),
			'OpenAI / DALL-E / ChatGPT'         => array( 'OpenAI', 'DALL-E', 'DALL·E', 'ChatGPT' ),
			'Midjourney'                        => array( 'Midjourney' ),
			'Adobe Firefly'                     => array( 'Firefly' ),
			'Stable Diffusion / A1111 / ComfyUI' => array( 'Stable Diffusion', 'StableDiffusion', 'sd-metadata', "parameters\0", "workflow\0", 'ComfyUI' ),
			'Google Imagen / Gemini / SynthID'  => array( 'Imagen', 'SynthID', 'Gemini' ),
			'Microsoft Designer / Bing'         => array( 'Bing Image Creator', 'Microsoft Designer' ),
			'Canva'                             => array( 'Canva' ),
			'Leonardo.Ai'                       => array( 'Leonardo' ),
			'Ideogram'                          => array( 'Ideogram' ),
			'XMP'                               => array( 'ns.adobe.com/xap' ),
			'EXIF'                              => array( "Exif\0" ),
			'IPTC / Photoshop'                  => array( 'Photoshop 3.0', "\x1C\x02" ),
		);
		foreach ( $signatures as $label => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $payload, $needle ) ) {
					$this->found[] = $label;
					break;
				}
			}
		}
		if ( empty( $this->found ) ) {
			$this->found[] = 'Other metadata';
		}
	}
}

/* -------------------------------------------------------------------------
 * Plugin wiring.
 * ---------------------------------------------------------------------- */
final class WDFAIMR_Plugin {

	const OPT     = 'wdfaimr_settings';
	const META    = '_wdfaimr';
	const SCAN    = '_wdfaimr_scan';
	const FLAG    = '_wdfaimr_flagged';
	const VERSION = '1.1.0';

	private static $instance;
	private $upload_found = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_handle_upload', array( $this, 'on_upload' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_metadata' ), 99, 2 );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_wdfaimr_bulk', array( $this, 'ajax_bulk' ) );
		add_action( 'admin_post_wdfaimr_clean_one', array( $this, 'admin_clean_one' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );
		add_action( 'load-settings_page_wdfaimr-settings', array( $this, 'redirect_to_page_early' ) );

		add_filter( 'manage_media_columns', array( $this, 'column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'credit_link' ), 5 );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer' ) );
		add_action( 'wp_ajax_wdfaimr_dismiss', array( $this, 'ajax_dismiss' ) );
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wdfaimr', 'WDFAIMR_CLI' );
		}
	}

	/* ---------------- Settings ---------------- */

	public function settings() {
		$defaults = array(
			'on_upload'     => 1,
			'keep_icc'      => 1,
			'process_sizes' => 1,
			'credit_link'   => 0,
		);
		return wp_parse_args( (array) get_option( self::OPT, array() ), $defaults );
	}

	public static function activate() {
		set_transient( 'wdfaimr_activated', 1, 60 );
		set_transient( 'wdfaimr_redirect', 1, 60 );
	}

	public function maybe_redirect() {
		if ( ! get_transient( 'wdfaimr_redirect' ) ) {
			return;
		}
		delete_transient( 'wdfaimr_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a core flag, no action taken on it.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'upload.php?page=wdfaimr' ) );
		exit;
	}

	/* ---------------- Credit / backlink ---------------- */

	public function credit_link() {
		echo "\n<!-- Image metadata cleaned by WDF AI Metadata Remover, a free plugin from Web Designer Factory: https://webdesignerfactory.com -->\n";
		$s = $this->settings();
		if ( empty( $s['credit_link'] ) ) {
			return;
		}
		?>
<div class="wdfaimr-credit" id="wdfaimr-credit" style="margin:0;padding:10px 16px;text-align:center;font-size:12px;line-height:1.4;font-family:inherit;background:transparent;color:inherit">Images cleaned by <a href="https://webdesignerfactory.com/?utm_source=plugin&amp;utm_medium=credit&amp;utm_campaign=wdf-ai-metadata-remover" title="Web design and digital marketing agency in Dallas, TX" rel="noopener" style="color:inherit;text-decoration:underline;opacity:.9">Web Designer Factory</a></div>

		<?php
	}

	public function front_assets() {
		$s = $this->settings();
		if ( empty( $s['credit_link'] ) ) {
			return;
		}
		wp_enqueue_script( 'wdfaimr-credit', plugins_url( 'assets/credit.js', __FILE__ ), array(), self::VERSION, true );
	}

	private function should_show_notice() {
		if ( ! current_user_can( 'manage_options' ) || get_option( 'wdfaimr_notice_dismissed' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'media_page_wdfaimr' === $screen->id ) {
			return false;
		}
		return $this->count_meta( self::FLAG ) < 1 && $this->count_meta( self::META ) < 1;
	}

	public function admin_assets( $hook ) {
		$on_page = 'media_page_wdfaimr' === $hook;
		if ( ! $on_page && ! $this->should_show_notice() ) {
			return;
		}
		wp_enqueue_script( 'wdfaimr-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
		$cfg = array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'dismissNonce' => wp_create_nonce( 'wdfaimr_dismiss' ),
			'bulkNonce'    => wp_create_nonce( 'wdfaimr_bulk' ),
			'total'        => $on_page ? $this->count_images() : 0,
			'flagged'      => $on_page ? $this->count_meta( self::FLAG, '1' ) : 0,
			'i18n'         => array(
				'confirmAll'     => __( 'Remove metadata from every image in the library?', 'wdf-ai-metadata-remover' ),
				'confirmFlagged' => __( 'Remove metadata from all %d flagged images?', 'wdf-ai-metadata-remover' ),
			),
		);
		wp_add_inline_script( 'wdfaimr-admin', 'var wdfaimrCfg = ' . wp_json_encode( $cfg ) . ';', 'before' );
	}

	public function admin_footer( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'media_page_wdfaimr' === $screen->id ) {
			return 'WDF AI Metadata Remover is a free plugin by <a href="https://webdesignerfactory.com/?utm_source=plugin&utm_medium=admin&utm_campaign=wdf-ai-metadata-remover" target="_blank" rel="noopener">Web Designer Factory</a>, a web design and digital marketing agency in Dallas, TX.';
		}
		return $text;
	}

	public function row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$links[] = '<a href="https://webdesignerfactory.com/?utm_source=plugin&utm_medium=row&utm_campaign=wdf-ai-metadata-remover" target="_blank" rel="noopener">Web design by Web Designer Factory</a>';
		}
		return $links;
	}

	public function ajax_dismiss() {
		check_ajax_referer( 'wdfaimr_dismiss' );
		if ( current_user_can( 'manage_options' ) ) {
			update_option( 'wdfaimr_notice_dismissed', 1 );
		}
		wp_die();
	}

	public function register_settings() {
		register_setting(
			'wdfaimr',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) {
					return array(
						'on_upload'     => empty( $in['on_upload'] ) ? 0 : 1,
						'keep_icc'      => empty( $in['keep_icc'] ) ? 0 : 1,
						'process_sizes' => empty( $in['process_sizes'] ) ? 0 : 1,
						'credit_link'   => empty( $in['credit_link'] ) ? 0 : 1,
					);
				},
			)
		);
	}

	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=wdfaimr' ) ) . '"><strong>Scan &amp; Settings</strong></a>' );
		return $links;
	}

	/* ---------------- Core processing ---------------- */

	private function is_image_type( $mime ) {
		return is_string( $mime ) && 0 === strpos( $mime, 'image/' ) && 'image/svg+xml' !== $mime;
	}

	public function on_upload( $upload, $context ) {
		$s = $this->settings();
		if ( empty( $s['on_upload'] ) || empty( $upload['file'] ) || ! $this->is_image_type( isset( $upload['type'] ) ? $upload['type'] : '' ) ) {
			return $upload;
		}
		$stripper = new WDFAIMR_Stripper( ! empty( $s['keep_icc'] ) );
		$r        = $stripper->clean_file( $upload['file'] );
		if ( $r['changed'] ) {
			$this->upload_found[ $upload['file'] ] = $r['found'];
		}
		return $upload;
	}

	public function on_metadata( $metadata, $attachment_id ) {
		$s = $this->settings();
		if ( ! empty( $s['on_upload'] ) ) {
			$this->process_attachment( $attachment_id, $metadata );
		}
		return $metadata;
	}

	/**
	 * Strip metadata from an attachment's original, scaled original and every generated size.
	 */
	public function process_attachment( $attachment_id, $metadata = null, $dry_run = false ) {
		$s = $this->settings();
		if ( ! $this->is_image_type( get_post_mime_type( $attachment_id ) ) ) {
			return array( 'skipped' => true );
		}
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		$files = array();
		$main  = get_attached_file( $attachment_id );
		if ( $main ) {
			$files[] = $main;
		}
		$orig = wp_get_original_image_path( $attachment_id );
		if ( $orig ) {
			$files[] = $orig;
		}
		if ( ! empty( $s['process_sizes'] ) && $main && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $main );
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
				if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
					foreach ( $size['sources'] as $src ) {
						if ( ! empty( $src['file'] ) ) {
							$files[] = $dir . '/' . $src['file'];
						}
					}
				}
			}
		}
		$files = array_unique( array_filter( $files ) );

		$stripper = new WDFAIMR_Stripper( ! empty( $s['keep_icc'] ), $dry_run );
		$found       = array();
		$changed     = 0;
		$errors      = array();
		$unsupported = array();

		foreach ( $files as $file ) {
			$r = $stripper->clean_file( $file );
			if ( $r['changed'] || ( $dry_run && $r['dirty'] ) ) {
				$changed++;
				$found = array_merge( $found, $r['found'] );
			}
			if ( ! empty( $r['error'] ) && 'File not readable' !== $r['error'] ) {
				if ( 'Unsupported format' === $r['error'] ) {
					$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
					$unsupported[ $ext ] = isset( $unsupported[ $ext ] ) ? $unsupported[ $ext ] + 1 : 1;
				} else {
					$errors[] = basename( $file ) . ': ' . $r['error'];
				}
			}
		}
		foreach ( $unsupported as $ext => $n ) {
			$errors[] = sprintf( '%d .%s file(s) skipped (format not supported%s)', $n, $ext, class_exists( 'Imagick' ) ? '' : ', Imagick not installed' );
		}
		if ( $main && isset( $this->upload_found[ $main ] ) ) {
			$found = array_merge( $found, $this->upload_found[ $main ] );
			$changed++;
			unset( $this->upload_found[ $main ] );
		}
		$found = array_values( array_unique( $found ) );
		if ( count( $found ) > 1 ) {
			$found = array_values( array_diff( $found, array( 'Other metadata' ) ) );
		}

		$summary = array(
			'time'    => time(),
			'files'   => count( $files ),
			'changed' => $changed,
			'found'   => $found,
			'errors'  => $errors,
		);

		if ( $dry_run ) {
			update_post_meta( $attachment_id, self::SCAN, $summary );
			update_post_meta( $attachment_id, self::FLAG, $changed ? 1 : 0 );
			return $summary;
		}

		update_post_meta( $attachment_id, self::META, $summary );
		update_post_meta( $attachment_id, self::FLAG, 0 );
		delete_post_meta( $attachment_id, self::SCAN );
		if ( $changed ) {
			clean_post_cache( $attachment_id );
		}
		return $summary;
	}

	public function scan_attachment( $attachment_id ) {
		return $this->process_attachment( $attachment_id, null, true );
	}

	private function flagged_ids( $limit = -1 ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'image',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => self::FLAG,
				'meta_value'     => '1',
			)
		);
	}

	private function count_meta( $key, $value = null ) {
		global $wpdb;
		$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'";
		if ( null !== $value ) {
			$sql .= $wpdb->prepare( ' AND m.meta_value = %s', $value );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $key ) );
	}

	/* ---------------- Admin page ---------------- */

	public function menu() {
		add_media_page(
			'WDF AI Metadata Remover',
			'AI Metadata Remover',
			'manage_options',
			'wdfaimr',
			array( $this, 'page' )
		);
		add_options_page(
			'WDF AI Metadata Remover',
			'AI Metadata Remover',
			'manage_options',
			'wdfaimr-settings',
			array( $this, 'redirect_to_page' )
		);
	}

	public function redirect_to_page_early() {
		wp_safe_redirect( admin_url( 'upload.php?page=wdfaimr' ) );
		exit;
	}

	public function redirect_to_page() {
		echo '<div class="wrap"><p><a href="' . esc_url( admin_url( 'upload.php?page=wdfaimr' ) ) . '">' . esc_html__( 'Open WDF AI Metadata Remover', 'wdf-ai-metadata-remover' ) . '</a></p></div>';
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( 'wdfaimr_notice_dismissed', 1 );
		$s       = $this->settings();
		$total   = $this->count_images();
		$flagged = $this->count_meta( self::FLAG, '1' );
		$scanned = $this->count_meta( self::FLAG );
		$cleaned = $this->count_meta( self::META );
		?>
		<div class="wrap">
			<h1>WDF AI Metadata Remover</h1>
			<p>Removes C2PA Content Credentials, JUMBF, XMP, EXIF, IPTC and text chunks from JPEG, PNG, WebP and AVIF files without re-encoding the pixels. Other formats fall back to Imagick (<?php echo class_exists( 'Imagick' ) ? '<strong>available</strong>' : '<strong>not available on this server</strong>'; ?>).</p>

			<h2>Settings</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'wdfaimr' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Clean on upload</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[on_upload]" value="1" <?php checked( $s['on_upload'] ); ?>> Strip metadata from every image as it is uploaded</label></td>
					</tr>
					<tr>
						<th scope="row">Generated sizes</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[process_sizes]" value="1" <?php checked( $s['process_sizes'] ); ?>> Also clean thumbnails and every resized copy</label></td>
					</tr>
					<tr>
						<th scope="row">Colour profile</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[keep_icc]" value="1" <?php checked( $s['keep_icc'] ); ?>> Keep the ICC colour profile (recommended, prevents colour shifts)</label></td>
					</tr>
					<tr>
						<th scope="row">Support this plugin</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[credit_link]" value="1" <?php checked( $s['credit_link'] ); ?>> Show a small "Images cleaned by Web Designer Factory" credit in your site footer</label>
							<p class="description">This plugin is free and always will be. The credit link is the only thing we ask for, and it is entirely optional.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Scan and clean the media library</h2>
			<p>Step 1 scans every image and reports what it finds. Nothing is modified. Step 2 removes the metadata, either per image or for everything flagged at once. Back up your uploads folder first if you want a way to restore original metadata later.</p>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 16px">
				<?php
				$tiles = array(
					array( 'Images in library', $total, '#1d2327' ),
					array( 'Scanned', $scanned, '#1d2327' ),
					array( 'Flagged (metadata found)', $flagged, $flagged ? '#b32d2e' : '#1d2327' ),
					array( 'Cleaned', $cleaned, '#00a32a' ),
				);
				foreach ( $tiles as $t ) {
					printf( '<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px 18px;min-width:150px"><div style="font-size:12px;color:#646970">%s</div><div style="font-size:24px;font-weight:600;color:%s">%s</div></div>', esc_html( $t[0] ), esc_attr( $t[2] ), esc_html( number_format_i18n( $t[1] ) ) );
				}
				?>
			</div>

			<p>
				<button type="button" class="button button-primary" id="wdfaimr-scan">Scan library</button>
				<button type="button" class="button button-primary" id="wdfaimr-clean-flagged" <?php disabled( ! $flagged ); ?>>Clean all flagged (<?php echo esc_html( number_format_i18n( $flagged ) ); ?>)</button>
				<button type="button" class="button" id="wdfaimr-clean-all">Clean all images without scanning</button>
				<button type="button" class="button" id="wdfaimr-stop" disabled>Stop</button>
			</p>
			<div id="wdfaimr-progress" style="display:none;max-width:700px">
				<div style="background:#dcdcde;border-radius:4px;height:18px;overflow:hidden"><div id="wdfaimr-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s"></div></div>
				<p id="wdfaimr-status" style="margin-top:8px"></p>
				<pre id="wdfaimr-log" style="max-height:200px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:8px;font-size:12px;display:none"></pre>
			</div>

			<?php if ( $flagged ) : ?>
			<h2>Scan report</h2>
			<?php
			$rows   = array();
			$bytype = array();
			foreach ( $this->flagged_ids( 1000 ) as $fid ) {
				$m = get_post_meta( $fid, self::SCAN, true );
				if ( empty( $m ) || ! is_array( $m ) ) {
					continue;
				}
				$rows[ $fid ] = $m;
				foreach ( (array) $m['found'] as $label ) {
					$bytype[ $label ] = isset( $bytype[ $label ] ) ? $bytype[ $label ] + 1 : 1;
				}
			}
			arsort( $bytype );
			?>
			<table class="widefat striped" style="max-width:520px;margin-bottom:20px">
				<thead><tr><th>Metadata type</th><th style="width:120px">Images</th></tr></thead>
				<tbody>
				<?php foreach ( $bytype as $label => $n ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( number_format_i18n( $n ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<table class="widefat striped" id="wdfaimr-report">
				<thead><tr><th style="width:70px"></th><th>Image</th><th>Found</th><th style="width:90px">Files</th><th style="width:110px"></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $fid => $m ) : ?>
					<tr data-id="<?php echo (int) $fid; ?>">
						<td><?php echo wp_get_attachment_image( $fid, array( 60, 60 ), true ); ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $fid ) ); ?>"><?php echo esc_html( basename( (string) get_attached_file( $fid ) ) ); ?></a><br><small style="color:#646970">#<?php echo (int) $fid; ?></small></td>
						<td><?php echo esc_html( implode( ', ', (array) $m['found'] ) ); ?></td>
						<td><?php echo (int) $m['changed']; ?> of <?php echo (int) $m['files']; ?></td>
						<td><button type="button" class="button button-small wdfaimr-row-clean">Clean</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $flagged > 1000 ) : ?><p class="description">Showing the first 1,000 flagged images. Use "Clean all flagged" to process every one.</p><?php endif; ?>
			<?php elseif ( $scanned ) : ?>
			<h2>Scan report</h2>
			<p>No flagged images. Everything that has been scanned is clean.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function count_images() {
		return (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(ID) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_mime_type <> 'image/svg+xml'" );
	}

	public function ajax_bulk() {
		check_ajax_referer( 'wdfaimr_bulk' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}
		@set_time_limit( 120 );
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'clean';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$batch  = 'scan' === $mode ? 12 : 8;
		$dry    = 'scan' === $mode;

		if ( 'clean_ids' === $mode ) {
			$raw  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
			$ids  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
			$next = 0;
			$done = true;
		} elseif ( 'clean_flagged' === $mode ) {
			// Cleaned images drop out of the flagged set, so always read from the top.
			$ids  = $this->flagged_ids( $batch );
			$next = 0;
			$done = count( $ids ) < $batch;
		} else {
			$ids  = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_mime_type' => 'image',
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'no_found_rows'  => true,
				)
			);
			$next = $offset + $batch;
			$done = count( $ids ) < $batch;
		}

		$log     = array();
		$changed = 0;
		foreach ( $ids as $id ) {
			$r = $this->process_attachment( $id, null, $dry );
			if ( ! empty( $r['skipped'] ) ) {
				continue;
			}
			if ( $r['changed'] ) {
				$changed++;
				if ( ! $dry ) {
					$log[] = sprintf( '#%d %s: removed %s from %d file(s)', $id, basename( (string) get_attached_file( $id ) ), implode( ', ', $r['found'] ), $r['changed'] );
				}
			}
			foreach ( $r['errors'] as $e ) {
				$log[] = sprintf( '#%d error: %s', $id, $e );
			}
		}

		wp_send_json_success(
			array(
				'processed' => count( $ids ),
				'changed'   => $changed,
				'next'      => $next,
				'done'      => $done,
				'log'       => $log,
			)
		);
	}

	public function admin_clean_one() {
		$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( $nonce, 'wdfaimr_clean_' . $id ) ) {
			wp_die( 'Not allowed.' );
		}
		$r = $this->process_attachment( $id );
		wp_safe_redirect( add_query_arg( 'wdfaimr_done', $r['changed'] ? 'changed' : 'clean', wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) ) );
		exit;
	}

	public function notices() {
		if ( $this->should_show_notice() ) {
			delete_transient( 'wdfaimr_activated' );
			$url = esc_url( admin_url( 'upload.php?page=wdfaimr' ) );
			echo '<div class="notice notice-info is-dismissible" id="wdfaimr-notice" style="display:flex;align-items:center;gap:16px;padding:10px 12px"><p style="margin:0;flex:1"><strong>' . esc_html__( 'WDF AI Metadata Remover is active.', 'wdf-ai-metadata-remover' ) . '</strong> ' . esc_html__( 'New uploads are cleaned automatically. Scan your existing media library to see which images carry AI metadata. You will find the plugin under', 'wdf-ai-metadata-remover' ) . ' <em>Media &rsaquo; AI Metadata Remover</em>.</p><a href="' . $url . '" class="button button-primary" style="white-space:nowrap">' . esc_html__( 'Scan my library', 'wdf-ai-metadata-remover' ) . '</a></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$done = isset( $_GET['wdfaimr_done'] ) ? sanitize_key( wp_unslash( $_GET['wdfaimr_done'] ) ) : '';
		if ( ! $done ) {
			return;
		}
		$msg = 'changed' === $done ? __( 'Metadata removed from the image.', 'wdf-ai-metadata-remover' ) : __( 'No removable metadata was found in that image.', 'wdf-ai-metadata-remover' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/* ---------------- Media list column ---------------- */

	public function column( $cols ) {
		$cols['wdfaimr'] = 'AI metadata';
		return $cols;
	}

	public function column_content( $col, $id ) {
		if ( 'wdfaimr' !== $col ) {
			return;
		}
		if ( ! $this->is_image_type( get_post_mime_type( $id ) ) ) {
			echo '<span style="color:#787c82">n/a</span>';
			return;
		}
		$m = get_post_meta( $id, self::META, true );
		if ( empty( $m ) || ! is_array( $m ) ) {
			$scan = get_post_meta( $id, self::SCAN, true );
			if ( ! empty( $scan ) && is_array( $scan ) ) {
				if ( ! empty( $scan['found'] ) ) {
					echo '<span style="color:#dba617;font-weight:600">Flagged</span><br><small>' . esc_html( implode( ', ', $scan['found'] ) ) . '</small>';
				} else {
					echo '<span style="color:#00a32a">Clean</span>';
				}
				return;
			}
			echo '<span style="color:#787c82">Not scanned</span>';
			return;
		}
		if ( ! empty( $m['found'] ) ) {
			echo '<span style="color:#00a32a">Cleaned</span><br><small>Removed: ' . esc_html( implode( ', ', $m['found'] ) ) . '</small>';
		} else {
			echo '<span style="color:#00a32a">Clean</span>';
		}
		if ( ! empty( $m['errors'] ) ) {
			echo '<br><small style="color:#b32d2e">' . esc_html( implode( '; ', $m['errors'] ) ) . '</small>';
		}
	}

	public function row_action( $actions, $post ) {
		if ( $this->is_image_type( $post->post_mime_type ) && current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=wdfaimr_clean_one&id=' . $post->ID ), 'wdfaimr_clean_' . $post->ID );
			$actions['wdfaimr'] = '<a href="' . esc_url( $url ) . '">Strip AI metadata</a>';
		}
		return $actions;
	}

}

/**
 * Scan or strip AI/provenance metadata from images.
 */
class WDFAIMR_CLI {

	private function ids( $args, $assoc ) {
		if ( ! empty( $assoc['all'] ) ) {
			return get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_mime_type' => 'image',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			);
		}
		if ( empty( $args ) ) {
			WP_CLI::error( 'Pass attachment IDs or --all.' );
		}
		return array_map( 'intval', $args );
	}

	/**
	 * Scan images and report metadata without modifying anything.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to scan.
	 *
	 * [--all]
	 * : Scan every image in the media library.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wdfaimr scan --all
	 */
	public function scan( $args, $assoc ) {
		$plugin  = WDFAIMR_Plugin::instance();
		$ids     = $this->ids( $args, $assoc );
		$flagged = 0;
		foreach ( $ids as $id ) {
			$r = $plugin->scan_attachment( $id );
			if ( ! empty( $r['skipped'] ) ) {
				continue;
			}
			if ( $r['changed'] ) {
				$flagged++;
				WP_CLI::log( sprintf( '#%d flagged: %s (%d of %d files)', $id, implode( ', ', $r['found'] ), $r['changed'], $r['files'] ) );
			}
		}
		WP_CLI::success( sprintf( '%d of %d images contain removable metadata.', $flagged, count( $ids ) ) );
	}

	/**
	 * Strip metadata from images.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to clean.
	 *
	 * [--all]
	 * : Clean every image in the media library.
	 *
	 * [--flagged]
	 * : Clean only images flagged by a previous scan.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wdfaimr clean --all
	 *     wp wdfaimr clean --flagged
	 *     wp wdfaimr clean 123 456
	 */
	public function clean( $args, $assoc ) {
		$plugin = WDFAIMR_Plugin::instance();
		if ( ! empty( $assoc['flagged'] ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_mime_type' => 'image',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'meta_key'       => WDFAIMR_Plugin::FLAG,
					'meta_value'     => '1',
				)
			);
		} else {
			$ids = $this->ids( $args, $assoc );
		}
		$changed = 0;
		foreach ( $ids as $id ) {
			$r = $plugin->process_attachment( $id );
			if ( ! empty( $r['skipped'] ) ) {
				continue;
			}
			if ( $r['changed'] ) {
				$changed++;
				WP_CLI::log( sprintf( '#%d cleaned (%s)', $id, implode( ', ', $r['found'] ) ) );
			}
			foreach ( $r['errors'] as $e ) {
				WP_CLI::warning( sprintf( '#%d %s', $id, $e ) );
			}
		}
		WP_CLI::success( sprintf( '%d of %d images had metadata removed.', $changed, count( $ids ) ) );
	}
}

WDFAIMR_Plugin::instance();

register_uninstall_hook( __FILE__, 'wdfaimr_uninstall' );
function wdfaimr_uninstall() {
	delete_option( 'wdfaimr_settings' );
	delete_option( 'wdfaimr_notice_dismissed' );
	delete_post_meta_by_key( '_wdfaimr' );
	delete_post_meta_by_key( '_wdfaimr_scan' );
	delete_post_meta_by_key( '_wdfaimr_flagged' );
}
