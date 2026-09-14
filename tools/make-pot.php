<?php
/**
 * Extract translatable strings into a POT file.
 *
 * @package MudravaRUM
 */

// phpcs:ignoreFile

$plugin_root = dirname( __DIR__ ) . '/plugin';
$pot_path    = $plugin_root . '/languages/mudrava-rum.pot';
$domain      = 'mudrava-rum';
$functions   = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );

$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ) );
$files   = array();
$strings = array();

foreach ( $it as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$path = $file->getPathname();
	if ( false !== strpos( $path, '/assets/' ) || false !== strpos( $path, '/languages/' ) || false !== strpos( $path, '/vendor/' ) ) {
		continue;
	}

	$files[] = $path;
}

sort( $files );

foreach ( $files as $path ) {
	$tokens    = token_get_all( file_get_contents( $path ) );
	$rel       = str_replace( $plugin_root . '/', '', $path );
	$translate = array();

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $functions, true ) ) {
			continue;
		}

		$function_line = $token[2];
		$i             = $index + 1;

		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			++$i;
		}

		if ( ! isset( $tokens[ $i ] ) || '(' !== $tokens[ $i ] ) {
			continue;
		}

		++$i;
		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && T_WHITESPACE === $tokens[ $i ][0] ) {
			++$i;
		}

		if ( ! isset( $tokens[ $i ] ) || ! is_array( $tokens[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ][0] ) {
			continue;
		}

		$raw_string = $tokens[ $i ][1];
		$text       = substr( $raw_string, 1, -1 );
		if ( "'" === $raw_string[0] ) {
			$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $text );
		} else {
			$text = stripcslashes( $text );
		}

		$is_domain = false;
		$j         = $i + 1;
		while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}

		if ( isset( $tokens[ $j ] ) && ',' === $tokens[ $j ] ) {
			++$j;
			while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}

			if ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
				$raw_domain = substr( $tokens[ $j ][1], 1, -1 );
				$domain_val = "'" === $tokens[ $j ][1][0] ? str_replace( "\\'", "'", $raw_domain ) : stripcslashes( $raw_domain );
				$is_domain  = ( $domain_val === $domain );
			}
		}

		if ( ! $is_domain || '' === $text ) {
			continue;
		}

		$note = null;
		for ( $k = $index - 1; $k >= 0; --$k ) {
			if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			break;
		}
		for ( $k = $k + 1; $k < $index; ++$k ) {
			if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '~translators:\s*(.*?)(?:\.|\*/|$)~is', $tokens[ $k ][1], $m ) ) {
				$note = trim( str_replace( array( '/*', '*/', "\n", "\r" ), array( '', '', ' ', ' ' ), $m[1] ) );
				$note = trim( $note, " \t*." );
				break;
			}
		}

		if ( ! isset( $translate[ $text ] ) ) {
			$translate[ $text ] = array(
				'note'     => $note,
				'location' => $rel . ':' . $function_line,
			);
		}
	}

	foreach ( $translate as $text => $data ) {
		if ( isset( $strings[ $text ] ) ) {
			$strings[ $text ]['locations'][] = $data['location'];
			if ( null === $strings[ $text ]['note'] ) {
				$strings[ $text ]['note'] = $data['note'];
			}
		} else {
			$strings[ $text ] = array(
				'note'      => $data['note'],
				'locations' => array( $data['location'] ),
			);
		}
	}
}

ksort( $strings );

$output  = "# Copyright (C) 2026 MUDRAVA\n";
$output .= "# This file is distributed under the GPLv2 or later.\n";
$output .= "msgid \"\"\n";
$output .= "msgstr \"\"\n";
$header_lines = array(
	'Project-Id-Version: Mudrava RUM 1.0.0',
	'Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/mudrava-rum',
	'Last-Translator: FULL NAME <EMAIL@ADDRESS>',
	'Language-Team: LANGUAGE <LL@li.org>',
	'MIME-Version: 1.0',
	'Content-Type: text/plain; charset=UTF-8',
	'Content-Transfer-Encoding: 8bit',
	'POT-Creation-Date: ' . gmdate( 'Y-m-d\TH:i:s+00:00' ),
	'PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE',
	'X-Generator: Mudrava RUM make-pot',
	'X-Domain: ' . $domain,
);
foreach ( $header_lines as $header_line ) {
	$output .= '"' . addcslashes( $header_line, '"' ) . '\n"' . "\n";
}
$output .= "\n";

foreach ( $strings as $text => $data ) {
	foreach ( $data['locations'] as $location ) {
		$output .= '#: ' . $location . "\n";
	}
	if ( null !== $data['note'] ) {
		$output .= '#. ' . str_replace( "\n", ' ', $data['note'] ) . "\n";
	}
	$output .= 'msgid "' . addcslashes( $text, "\0\n\t\r\"\\" ) . "\"\n";
	$output .= "msgstr \"\"\n\n";
}

file_put_contents( $pot_path, $output );

echo 'Extracted ' . count( $strings ) . " strings\n";
echo 'Wrote ' . $pot_path . "\n";
