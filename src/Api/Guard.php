<?php

namespace Cachicamo\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Port of Fiscal/CachicamoCore/helpers/sql_injection.go: same pattern families, same three
 * rounds of URL-decoding and the same base64/hex blob sweep. Runs on every value the plugin
 * sends to the core (checkout fields, notes, search terms) before the request leaves the site.
 */
class Guard {

	const MAX_BYTES        = 8192;
	const DECODE_ROUNDS    = 3;
	const MAX_BLOBS        = 8;
	const MIN_BLOB_BYTES   = 20;
	const MAX_BLOB_BYTES   = 4096;
	const MIN_HEX_BYTES    = 32;
	const SAMPLE_RADIUS    = 90;

	/** @var array<int,array{name:string,tokens:array<int,string>,regex:string}>|null */
	private static $patterns = null;

	public static function detect( $raw ) {
		if ( '' === $raw ) {
			return null;
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			$raw = substr( $raw, 0, self::MAX_BYTES );
		}

		$decoded = self::decode_url( $raw );

		$match = self::match_normalized( self::normalize( $decoded ) );
		if ( null !== $match ) {
			return $match;
		}

		if ( false !== strpos( $decoded, '/*' ) ) {
			$match = self::match_normalized( self::normalize_with( $decoded, '' ) );
			if ( null !== $match ) {
				$match['source'] = 'comment_obfuscated';
				return $match;
			}
		}

		foreach ( self::decode_blobs( $decoded ) as $blob ) {
			$match = self::match_normalized( self::normalize( $blob['text'] ) );
			if ( null !== $match ) {
				$match['source'] = $blob['kind'];
				return $match;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $fields Values keyed by field name (name, company, address, notes...).
	 * @return string|null The first offending field name, or null when everything is clean.
	 */
	public static function detect_in_fields( array $fields ) {
		foreach ( $fields as $field => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( null !== self::detect( $value ) ) {
				return $field;
			}
		}
		return null;
	}

	private static function match_normalized( $normalized ) {
		if ( '' === $normalized ) {
			return null;
		}
		foreach ( self::patterns() as $pattern ) {
			if ( ! self::contains_any( $normalized, $pattern['tokens'] ) ) {
				continue;
			}
			if ( preg_match( $pattern['regex'], $normalized, $matches, PREG_OFFSET_CAPTURE ) === 1 ) {
				$start = $matches[0][1];
				$end   = $start + strlen( $matches[0][0] );
				return array(
					'signature' => $pattern['name'],
					'source'    => '',
					'sample'    => self::sample( $normalized, $start, $end ),
				);
			}
		}
		return null;
	}

	private static function contains_any( $value, array $tokens ) {
		foreach ( $tokens as $token ) {
			if ( false !== strpos( $value, $token ) ) {
				return true;
			}
		}
		return false;
	}

	private static function sample( $normalized, $start, $end ) {
		$from = max( $start - self::SAMPLE_RADIUS, 0 );
		$to   = min( $end + self::SAMPLE_RADIUS, strlen( $normalized ) );
		return substr( $normalized, $from, $to - $from );
	}

	private static function normalize( $value ) {
		return self::normalize_with( $value, ' ' );
	}

	private static function normalize_with( $value, $comment_replacement ) {
		$lowered = strtolower( $value );
		$lowered = self::unescape_literals( $lowered );
		$lowered = preg_replace( '/\/\*.*?\*\//s', $comment_replacement, $lowered );
		return self::collapse_spaces( $lowered );
	}

	private static function collapse_spaces( $value ) {
		$length  = strlen( $value );
		$out     = '';
		$pending = false;
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $value[ $index ];
			$ord  = ord( $char );
			if ( $ord <= 0x20 || '+' === $char || 0x7f === $ord ) {
				$pending = true;
				continue;
			}
			if ( $pending && '' !== $out ) {
				$out .= ' ';
			}
			$pending = false;
			$out    .= $char;
		}
		return $out;
	}

	private static function unescape_literals( $value ) {
		if ( false === strpos( $value, '\\' ) && false === strpos( $value, "\x00" ) ) {
			return $value;
		}
		$length = strlen( $value );
		$out    = '';
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $value[ $index ];
			if ( "\x00" === $char ) {
				continue;
			}
			if ( '\\' !== $char || $index + 1 >= $length ) {
				$out .= $char;
				continue;
			}
			$next = $value[ $index + 1 ];
			if ( 'u' === $next && $index + 5 < $length && '0' === $value[ $index + 2 ] && '0' === $value[ $index + 3 ] ) {
				$code = self::hex_pair( $value[ $index + 4 ], $value[ $index + 5 ] );
				if ( null !== $code ) {
					$out   .= chr( $code );
					$index += 5;
					continue;
				}
			} elseif ( 'x' === $next && $index + 3 < $length ) {
				$code = self::hex_pair( $value[ $index + 2 ], $value[ $index + 3 ] );
				if ( null !== $code ) {
					$out   .= chr( $code );
					$index += 3;
					continue;
				}
			} elseif ( "'" === $next || '"' === $next || '\\' === $next ) {
				$out   .= $next;
				$index += 1;
				continue;
			} elseif ( 'n' === $next || 'r' === $next || 't' === $next ) {
				$out   .= ' ';
				$index += 1;
				continue;
			}
			$out .= $char;
		}
		return $out;
	}

	private static function decode_url( $value ) {
		for ( $round = 0; $round < self::DECODE_ROUNDS; $round++ ) {
			if ( false === strpos( $value, '%' ) ) {
				break;
			}
			$decoded = self::decode_url_once( $value );
			if ( $decoded === $value ) {
				break;
			}
			$value = $decoded;
		}
		return $value;
	}

	private static function decode_url_once( $value ) {
		$length = strlen( $value );
		$out    = '';
		for ( $index = 0; $index < $length; $index++ ) {
			if ( '%' === $value[ $index ] && $index + 2 < $length ) {
				$code = self::hex_pair( $value[ $index + 1 ], $value[ $index + 2 ] );
				if ( null !== $code ) {
					$out   .= chr( $code );
					$index += 2;
					continue;
				}
			}
			$out .= $value[ $index ];
		}
		return $out;
	}

	private static function hex_pair( $high, $low ) {
		$high_value = self::hex_value( $high );
		$low_value  = self::hex_value( $low );
		if ( null === $high_value || null === $low_value ) {
			return null;
		}
		return ( $high_value << 4 ) | $low_value;
	}

	private static function hex_value( $char ) {
		if ( $char >= '0' && $char <= '9' ) {
			return ord( $char ) - ord( '0' );
		}
		if ( $char >= 'a' && $char <= 'f' ) {
			return ord( $char ) - ord( 'a' ) + 10;
		}
		if ( $char >= 'A' && $char <= 'F' ) {
			return ord( $char ) - ord( 'A' ) + 10;
		}
		return null;
	}

	private static function decode_blobs( $value ) {
		$blobs  = array();
		$length = strlen( $value );
		$index  = 0;
		while ( $index < $length ) {
			if ( ! self::is_blob_byte( $value[ $index ] ) ) {
				$index++;
				continue;
			}
			$start = $index;
			while ( $index < $length && self::is_blob_byte( $value[ $index ] ) ) {
				$index++;
			}
			$end = $index;
			while ( $end < $length && '=' === $value[ $end ] ) {
				$end++;
			}
			$blob = self::decode_blob( substr( $value, $start, $end - $start ) );
			if ( null !== $blob ) {
				$blobs[] = $blob;
				if ( count( $blobs ) >= self::MAX_BLOBS ) {
					return $blobs;
				}
			}
			$index = $end;
		}
		return $blobs;
	}

	private static function is_blob_byte( $char ) {
		return ( $char >= 'a' && $char <= 'z' ) || ( $char >= 'A' && $char <= 'Z' ) || ( $char >= '0' && $char <= '9' )
			|| '+' === $char || '/' === $char || '_' === $char || '-' === $char;
	}

	private static function decode_blob( $candidate ) {
		$length = strlen( $candidate );
		if ( $length < self::MIN_BLOB_BYTES || $length > self::MAX_BLOB_BYTES ) {
			return null;
		}
		$hex = self::decode_hex( $candidate );
		if ( null !== $hex ) {
			return array(
				'kind' => 'hex',
				'text' => $hex,
			);
		}
		$base64 = self::decode_base64( $candidate );
		if ( null !== $base64 ) {
			return array(
				'kind' => 'base64',
				'text' => $base64,
			);
		}
		return null;
	}

	private static function decode_base64( $candidate ) {
		if ( 1 === strlen( $candidate ) % 4 ) {
			return null;
		}
		$url_safe = (bool) preg_match( '/[-_]/', $candidate );
		if ( $url_safe && preg_match( '/[+\/]/', $candidate ) ) {
			return null;
		}
		$normalized = strtr( $candidate, '-_', '+/' );
		$padded     = '=' === substr( $candidate, -1 );
		if ( ! $padded ) {
			$remainder = strlen( $normalized ) % 4;
			if ( 0 !== $remainder ) {
				$normalized .= str_repeat( '=', 4 - $remainder );
			}
		}
		$decoded = base64_decode( $normalized, true );
		if ( false === $decoded || ! self::is_printable( $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	private static function decode_hex( $candidate ) {
		$trimmed = $candidate;
		if ( strlen( $trimmed ) > 2 && '0' === $trimmed[0] && ( 'x' === $trimmed[1] || 'X' === $trimmed[1] ) ) {
			$trimmed = substr( $trimmed, 2 );
		}
		if ( strlen( $trimmed ) < self::MIN_HEX_BYTES ) {
			return null;
		}
		if ( 0 !== strlen( $trimmed ) % 2 ) {
			$trimmed = substr( $trimmed, 0, -1 );
		}
		if ( ! ctype_xdigit( $trimmed ) ) {
			return null;
		}
		$decoded = hex2bin( $trimmed );
		if ( false === $decoded || ! self::is_printable( $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	private static function is_printable( $data ) {
		$length = strlen( $data );
		if ( $length < 6 ) {
			return false;
		}
		$printable = 0;
		for ( $index = 0; $index < $length; $index++ ) {
			$ord = ord( $data[ $index ] );
			if ( "\t" === $data[ $index ] || "\n" === $data[ $index ] || "\r" === $data[ $index ] || ( $ord >= 0x20 && $ord <= 0x7e ) ) {
				$printable++;
			}
		}
		return $printable * 10 >= $length * 9;
	}

	private static function patterns() {
		if ( null !== self::$patterns ) {
			return self::$patterns;
		}

		self::$patterns = array(
			array(
				'name'   => 'union_select',
				'tokens' => array( 'union' ),
				'regex'  => '/\bunion\b(?:\s+(?:all|distinct))?\s*\(?\s*select\b/',
			),
			array(
				'name'   => 'select_projection',
				'tokens' => array( 'select ' ),
				'regex'  => '/\bselect\s+(?:\*|@@|0x|null\b|top\s+\d|distinct\s+\*|(?:group_)?concat\s*\(|count\s*\(\s*\*|char\s*\(|version\s*\(|database\s*\(|user\s*\(|\d+\s*,\s*\d+)/',
			),
			array(
				'name'   => 'select_from_system',
				'tokens' => array( 'select' ),
				'regex'  => '/\bselect\b[^;]*?\bfrom\s+(?:information_schema|mysql\s*\.|pg_|sys\s*\.|dual\b|sqlite_)/',
			),
			array(
				'name'   => 'stacked_query',
				'tokens' => array( ';' ),
				'regex'  => '/;\s*\(?\s*(?:select|insert|update|delete|drop|alter|create|truncate|exec|execute|declare|shutdown|grant|revoke)\b/',
			),
			array(
				'name'   => 'boolean_tautology',
				'tokens' => array( '=', '<>', '!=' ),
				'regex'  => '/\b(?:or|and|xor)\s+[\'"]?\d+[\'"]?\s*(?:=|<>|!=)\s*[\'"]?\d+/',
			),
			array(
				'name'   => 'quoted_tautology',
				'tokens' => array( '=', 'like' ),
				'regex'  => '/[\'"]\s*(?:or|and|xor|\|\|)\s*[\'"]?[\w\s]*[\'"]?\s*(?:=|\blike\b)\s*[\'"]/',
			),
			array(
				'name'   => 'time_based',
				'tokens' => array( 'sleep', 'benchmark', 'randomblob', 'waitfor', 'dbms_pipe' ),
				'regex'  => '/\b(?:sleep|pg_sleep|benchmark|randomblob|dbms_pipe\.receive_message|dbms_lock\.sleep)\s*\(|\bwaitfor\s+(?:delay|time)\b/',
			),
			array(
				'name'   => 'error_based',
				'tokens' => array( 'extractvalue', 'updatexml', 'xmltype', 'utl_inaddr', 'dbms_xmlgen', 'ctxsys' ),
				'regex'  => '/\b(?:extractvalue|updatexml|xmltype|utl_inaddr|dbms_xmlgen|ctxsys)\s*[.(]/',
			),
			array(
				'name'   => 'db_metadata',
				'tokens' => array(
					'information_schema',
					'mysql',
					'pg_',
					'sysobjects',
					'syscolumns',
					'sysdatabases',
					'sysusers',
					'@@',
					'version(',
					'database(',
					'schema(',
					'user(',
					'current_user',
					'system_user',
					'session_user',
				),
				'regex'  => '/\binformation_schema\b|\bmysql\s*\.\s*(?:user|db|innodb_\w+)\b|\bpg_(?:catalog|user|shadow|tables)\b|\bsys(?:objects|columns|databases|users)\b|@@(?:version|datadir|hostname|basedir|global|session)|\b(?:version|database|schema|user)\(\s*\)|\b(?:current_user|system_user|session_user)\b/',
			),
			array(
				'name'   => 'file_access',
				'tokens' => array( 'load_file', 'outfile', 'dumpfile', 'infile' ),
				'regex'  => '/\bload_file\s*\(|\binto\s+(?:out|dump)file\b|\bload\s+data\s+(?:local\s+)?infile\b/',
			),
			array(
				'name'   => 'command_exec',
				'tokens' => array( 'xp_cmdshell', 'sp_', 'exec ', 'execute ', 'openrowset', 'opendatasource' ),
				'regex'  => '/\bxp_cmdshell\b|\bsp_(?:executesql|oacreate|password|makewebtask)\b|\bexec(?:ute)?\s+(?:master|xp_|sp_)|\bopen(?:rowset|datasource)\s*\(/',
			),
			array(
				'name'   => 'quote_comment_terminator',
				'tokens' => array( '--', '#' ),
				'regex'  => '/[\'"]\s*(?:--|#)[\s+\-]*$/',
			),
			array(
				'name'   => 'char_obfuscation',
				'tokens' => array( 'char', 'concat', 'cast', '0x', 'unhex', 'from_base64' ),
				'regex'  => '/\bchar\s*\(\s*\d+\s*(?:,\s*\d+\s*){2,}\)|\b(?:concat|cast)\s*\(\s*0x|\b0x[0-9a-f]{16,}\b|\b(?:unhex|from_base64)\s*\(/',
			),
			array(
				'name'   => 'dml_statement',
				'tokens' => array( 'insert into', 'update ', 'delete from', 'drop ', 'truncate table', 'alter table', 'create ', 'grant all' ),
				'regex'  => '/\binsert\s+into\s+["\w.]+\s*(?:\(|values\b|select\b|set\b)|\bupdate\s+["\w.]+\s+set\s+["\w.]+\s*=|\bdelete\s+from\s+["\w.]+\s*(?:where\b|;|--|#|$)|\bdrop\s+(?:table|database|schema|user|index)\s+|\btruncate\s+table\b|\balter\s+table\b|\bcreate\s+(?:table|database|user|function|procedure)\s+|\bgrant\s+all\b/',
			),
			array(
				'name'   => 'blind_order_by',
				'tokens' => array( 'order by' ),
				'regex'  => '/\border\s+by\s+\d+\s*(?:--|#|;|\))/',
			),
			array(
				'name'   => 'case_probe',
				'tokens' => array( 'case when' ),
				'regex'  => '/\bcase\s+when\s*\(/',
			),
		);

		return self::$patterns;
	}
}
