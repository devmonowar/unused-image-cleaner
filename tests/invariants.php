<?php
/**
 * The safety invariants, enforced mechanically.
 *
 * Run:  php tests/invariants.php       (or: composer test)
 *
 * These are deliberately STATIC tests. They tokenise `src/` and assert
 * properties of the source itself, so they need no WordPress, no database and
 * no fixtures — which means they run on any checkout, in any CI, in under a
 * second, and nobody has an excuse to skip them.
 *
 * Every assertion here exists because the invariant it guards is one a future
 * edit could quietly break. A rule that lives only in a document is a rule that
 * is already half-broken; the point of this file is that the rules fail loudly.
 *
 * Comments and strings never count as calls — matching is done on real T_STRING
 * tokens in call position, because a rule that fires on the word appearing in a
 * docblock is a rule people learn to ignore.
 *
 * @package UnusedImageCleaner
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( "This script is for the command line only.\n" );
}

$root = dirname( __DIR__ );

// ---------------------------------------------------------------- harness ---

$passed = 0;
$failed = array();

/**
 * @param string $name  What is being guaranteed.
 * @param bool   $ok    Whether it holds.
 * @param string $detail Shown only on failure.
 */
function check( string $name, bool $ok, string $detail = '' ): void {
	global $passed, $failed;

	if ( $ok ) {
		++$passed;
		return;
	}

	$failed[] = $detail ? "$name\n      $detail" : $name;
}

/**
 * Every .php file under a directory.
 *
 * @return string[]
 */
function php_files( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out = array();

	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$out[] = $file->getPathname();
		}
	}

	sort( $out );

	return $out;
}

/**
 * Names this file actually CALLS — not names it mentions.
 *
 * A T_STRING immediately followed by `(`, and not preceded by `->`, `::` or
 * `function`, so method declarations and property reads do not register.
 *
 * @return string[] Lowercased function names, may repeat.
 */
function called_functions( string $path ): array {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$calls  = array();
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		// Next significant token must be an opening parenthesis.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$j++;
		}

		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		// Previous significant token must not make this a method or declaration.
		$k = $i - 1;
		while ( $k >= 0 && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$k--;
		}

		if ( $k >= 0 && is_array( $tokens[ $k ] )
			&& in_array( $tokens[ $k ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue;
		}

		$calls[] = strtolower( $token[1] );
	}

	return $calls;
}

/**
 * The source text of one method body, or '' if there is no such method.
 */
function method_body( string $path, string $method ): string {
	$source = (string) file_get_contents( $path );
	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}

		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}

		if ( $j >= $count || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $tokens[ $j ][1] !== $method ) {
			continue;
		}

		// Walk to the opening brace, then match it.
		$depth = 0;
		$body  = '';

		for ( $k = $j; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];

			if ( '{' === $text ) {
				$depth++;
			}

			if ( $depth > 0 ) {
				$body .= $text;
			}

			if ( '}' === $text ) {
				$depth--;

				if ( 0 === $depth ) {
					return $body;
				}
			}
		}
	}

	return '';
}

/** Public method names declared in a file. @return string[] */
function public_methods( string $path ): array {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$count  = count( $tokens );
	$out    = array();

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_PUBLIC !== $tokens[ $i ][0] ) {
			continue;
		}

		for ( $j = $i + 1; $j < $count && $j < $i + 8; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_FUNCTION === $tokens[ $j ][0] ) {
				$k = $j + 1;
				while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					$k++;
				}
				if ( $k < $count && is_array( $tokens[ $k ] ) && T_STRING === $tokens[ $k ][0] ) {
					$out[] = $tokens[ $k ][1];
				}
				break;
			}
		}
	}

	return $out;
}

function rel( string $root, string $path ): string {
	return str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
}

// ------------------------------------------------------------ the invariants ---

$src        = $root . DIRECTORY_SEPARATOR . 'src';
$all_files  = php_files( $src );
$destructive = array( 'wp_delete_attachment', 'wp_delete_post', 'wp_trash_post', 'wp_untrash_post', 'unlink', 'wp_delete_file' );

echo "Unused Image Cleaner — safety invariants\n";
echo str_repeat( '-', 60 ) . "\n";

/*
 * 1. Destructive calls are confined.
 *
 * The Cleanup Engine executes what Safety approved; the calibration Seeder
 * destroys only fixtures it created, gated on a marker meta a real image cannot
 * carry. Any THIRD file gaining a destructive call is the failure this whole
 * plugin exists to prevent.
 */
$allowed_destructive = array( 'src/Cleanup/CleanupEngine.php', 'src/Calibration/Seeder.php' );
$offenders           = array();

foreach ( $all_files as $file ) {
	$hits = array_intersect( called_functions( $file ), $destructive );

	if ( $hits && ! in_array( rel( $root, $file ), $allowed_destructive, true ) ) {
		$offenders[] = rel( $root, $file ) . ' calls ' . implode( ', ', array_unique( $hits ) );
	}
}

check(
	'Destructive calls appear in exactly two files',
	empty( $offenders ),
	implode( "\n      ", $offenders )
);

/*
 * 2. The Cleanup Engine is unreachable except through the Safety Engine.
 *
 * Checked per method, not per file: a file that asks Safety once and then adds a
 * second method that does not is exactly how this invariant dies.
 */
$cleanup = $src . '/Cleanup/CleanupEngine.php';

foreach ( array( 'trash', 'restore', 'delete_permanently' ) as $method ) {
	$body = method_body( $cleanup, $method );

	check(
		"CleanupEngine::{$method}() asks the Safety Engine first",
		'' !== $body && false !== strpos( $body, 'safety' ) && false !== strpos( $body, 'evaluate' ),
		'' === $body ? 'method not found' : 'no safety->evaluate() in the body'
	);
}

// Bulk is not a bypass: it must route each id through trash(), not around it.
$bulk = method_body( $cleanup, 'trash_many' );
check(
	'CleanupEngine::trash_many() routes every image through trash()',
	'' !== $bulk && 1 === preg_match( '/\$this->trash\(/', $bulk ),
	'bulk must re-evaluate each image individually'
);

/*
 * And no public method may reach a destructive call without a Safety verdict
 * somewhere in its own body — either by asking directly, or by delegating to a
 * sibling that does.
 */
$unguarded = array();

foreach ( public_methods( $cleanup ) as $method ) {
	if ( '__construct' === $method ) {
		continue;
	}

	$body = method_body( $cleanup, $method );

	if ( '' === $body ) {
		continue;
	}

	$words   = array_map( 'strtolower', preg_split( '/\W+/', $body ) ?: array() );
	$deletes = (bool) array_intersect( $destructive, $words );
	$guarded = false !== strpos( $body, 'evaluate' ) || 1 === preg_match( '/\$this->(trash|restore|delete_permanently)\(/', $body );

	if ( $deletes && ! $guarded ) {
		$unguarded[] = $method;
	}
}

check(
	'No public Cleanup method destroys without a Safety verdict',
	empty( $unguarded ),
	implode( ', ', $unguarded )
);

/*
 * 3. Scanners find; engines decide.
 */
$scanner_offenders = array();

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	$source = (string) file_get_contents( $file );
	$calls  = called_functions( $file );

	if ( array_intersect( $calls, $destructive ) ) {
		$scanner_offenders[] = rel( $root, $file ) . ' — destructive call';
	}

	if ( preg_match( '/use\s+UnusedImageCleaner\\\\(Risk|Recommendation|Safety|Cleanup)\\\\/', $source ) ) {
		$scanner_offenders[] = rel( $root, $file ) . ' — imports a decision engine';
	}
}

check(
	'No scanner deletes or imports a decision engine',
	empty( $scanner_offenders ),
	implode( "\n      ", $scanner_offenders )
);

/*
 * 4. Confidence and Risk are independent and never blended.
 */
$risk_src       = (string) file_get_contents( $src . '/Risk/RiskEngine.php' );
$confidence_src = (string) file_get_contents( $src . '/Confidence/ConfidenceEngine.php' );

check(
	'RiskEngine never reads a confidence score',
	! preg_match( '/->\s*confidence\b/', $risk_src ),
	'risk must not be derived from confidence'
);

check(
	'ConfidenceEngine never reads a risk score',
	! preg_match( '/->\s*risk\b/', $confidence_src ),
	'confidence must not be derived from risk'
);

/*
 * 5. The Recommendation Engine applies all THREE gates.
 *
 * The confidence gate was specified and, for a while, simply absent — coverage
 * and risk were checked and the score never was. Coverage is not a proxy for it:
 * coverage counts which scanners finished, confidence counts how far they are
 * trusted, and a scan can clear the floor while sitting under the confidence
 * minimum. That gap is where an unverified deletion happens.
 */
$decide = method_body( $src . '/Recommendation/RecommendationEngine.php', 'decide_one' );

check(
	'Recommendation applies the coverage floor',
	false !== strpos( $decide, 'COVERAGE_FLOOR' ),
	'gate 1 missing'
);

check(
	'Recommendation applies the risk ceiling',
	false !== strpos( $decide, 'RiskEngine::' ),
	'gate 2 missing'
);

check(
	'Recommendation applies the confidence gate',
	1 === preg_match( '/\$confidence\s*<\s*ConfidenceEngine::HIGH/', $decide ),
	'gate 3 missing — Trash would be offered below the confidence minimum'
);

/*
 * 6. Thresholds live in one place each.
 *
 * A duplicated threshold is a threshold that will drift.
 */
$literals = array();

foreach ( array( 'Recommendation/RecommendationEngine.php', 'Safety/SafetyEngine.php' ) as $file ) {
	$body = (string) file_get_contents( $src . '/' . $file );

	// Strip comments so prose about "70%" does not trip this.
	$code = '';
	foreach ( token_get_all( $body ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}

	if ( preg_match( '/(>=|<)\s*(70|85|60|35|15|80|95|40)\b/', $code, $m ) ) {
		$literals[] = $file . ' has a bare "' . trim( $m[0] ) . '"';
	}
}

check(
	'Decision thresholds are named constants, never re-typed literals',
	empty( $literals ),
	implode( "\n      ", $literals )
);

/*
 * 7. A scanner is not finished until it has a weight and a reliability entry.
 */
$weights_src  = (string) file_get_contents( $src . '/Confidence/ScannerWeights.php' );
$plugin_src   = (string) file_get_contents( $src . '/Core/Plugin.php' );
$missing_ids  = array();

preg_match_all( "/->add\(\s*new\s+([A-Za-z]+)\s*\(/", $plugin_src, $registered );

preg_match_all( "/'([a-z_]+)'\s*=>\s*[\d.]+/", $weights_src, $weight_ids );
$weight_ids = array_unique( $weight_ids[1] );

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	$source = (string) file_get_contents( $file );

	if ( ! preg_match( "/function id\(\)\s*:\s*string\s*\{\s*return\s*'([a-z_]+)'/", $source, $m ) ) {
		continue;
	}

	if ( ! in_array( $m[1], $weight_ids, true ) ) {
		$missing_ids[] = $m[1] . ' (' . rel( $root, $file ) . ')';
	}
}

check(
	'Every scanner has a weight and reliability entry',
	empty( $missing_ids ),
	implode( ', ', $missing_ids )
);

/*
 * 8. A database error must never look like an empty search space.
 *
 * The scanner with the highest reliability once returned Success with zero
 * references when its query failed, which told the Confidence Engine the most
 * trusted place on the site had been searched and held nothing.
 */
$unchecked = array();

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	$source  = (string) file_get_contents( $file );
	$queries = preg_match_all( '/->get_results\(/', $source );

	if ( 0 === $queries ) {
		continue;
	}

	// Weight 0 scanners cannot move confidence either way.
	if ( false !== strpos( $file, 'GenericFallbackScanner' ) ) {
		continue;
	}

	$checks = preg_match_all( '/null\s*===\s*\$/', $source );

	if ( $checks < $queries ) {
		$unchecked[] = rel( $root, $file ) . " ({$queries} queries, {$checks} null checks)";
	}
}

check(
	'Every weighted scanner checks every query for failure',
	empty( $unchecked ),
	implode( "\n      ", $unchecked )
);

/*
 * 8b. A partial result is still a result.
 *
 * `failed()` once took no references at all, so a scanner that paginated
 * through thousands of posts and then hit an error threw away everything it had
 * confirmed. Dropped references are indistinguishable from absent ones, and
 * absence is what gets an image deleted.
 */
$failed_signature = method_body( $src . '/Scanner/ScannerResult.php', 'failed' );

check(
	'A failed scanner can still return what it found',
	1 === preg_match( '/\$result->references\s*=/', $failed_signature ),
	'ScannerResult::failed() discards references'
);

$paginating = array(
	'Scanner/ContentScanner.php'  => 'scan',
	'Scanner/ElementorScanner.php' => 'scan',
);

$discarding = array();

foreach ( $paginating as $file => $method ) {
	$body = method_body( $src . '/' . $file, $method );

	// The mid-pagination failure must hand back the accumulated references.
	if ( 1 !== preg_match( '/ScannerResult::failed\(\s*\$this->id\(\),[^;]*\$references/s', $body ) ) {
		$discarding[] = $file;
	}
}

check(
	'Paginating scanners keep earlier batches when a later one fails',
	empty( $discarding ),
	implode( ', ', $discarding )
);

check(
	'Rebuilding a stored scan keeps a failed scanner\'s references',
	1 === preg_match( '/ScannerResult::failed\(\s*\$id,\s*\'see log\',\s*\$refs/', (string) file_get_contents( $src . '/Queue/BatchProcessor.php' ) ),
	'references persisted during the scan are dropped on rebuild'
);

/*
 * 8c. Thresholds belong to the engine that owns them, everywhere.
 */
check(
	'AnalysisEngine reads the Used threshold rather than restating it',
	1 !== preg_match( '/strongest_evidence\(\)\s*>=\s*\d+/', (string) file_get_contents( $src . '/Analysis/AnalysisEngine.php' ) ),
	'the status threshold is hardcoded in analysis code'
);

/*
 * 8d. ACF is asked what a field holds, never guessed at by name.
 *
 * Inferring image-ness from an 18-word list of field names dropped every
 * ID-returning image field called `hero`, `masthead` or `artwork` — a false
 * negative on a weighted scanner, which is a used image reported unused.
 */
check(
	'The ACF Scanner asks ACF for the field type',
	false !== strpos( (string) file_get_contents( $src . '/Scanner/ACFScanner.php' ), 'acf_get_field' ),
	'field types are being guessed from names'
);

/*
 * 8e. Nothing is computed and then thrown away.
 *
 * Three findings were produced by the engines every scan and silently dropped
 * at the end of the request because no column held them: the risk breakdown,
 * the confidence penalties, and the broken references. A number the user cannot
 * see the working for is a number they were asked to take on trust.
 */
$schema     = (string) file_get_contents( $src . '/Database/Tables.php' );
$repository = (string) file_get_contents( $src . '/Database/ScanRepository.php' );

foreach ( array( 'risk_breakdown', 'confidence_penalties', 'checks_performed', 'broken_references' ) as $column ) {
	check(
		"'{$column}' survives the request",
		false !== strpos( $schema, $column ) && false !== strpos( $repository, $column ),
		'computed but never stored'
	);
}

/*
 * 8f. A broken reference reaches a human.
 *
 * The resolver recorded them and `unresolved()` had no callers at all — the
 * whole mechanism was plumbed at both ends and connected in the middle by
 * nothing.
 */
check(
	'Broken references are collected from the resolver',
	false !== strpos( (string) file_get_contents( $src . '/Queue/BatchProcessor.php' ), 'unresolved()' ),
	'AttachmentResolver::unresolved() has no callers'
);

check(
	'Broken references are shown to the user',
	false !== strpos( (string) file_get_contents( $src . '/Reports/DashboardReport.php' ), 'broken_references' ),
	'stored but never displayed'
);

/*
 * 8g. Consequence follows visibility.
 *
 * A reference carries the status of the place it was found, because evidence
 * and consequence are different questions: a draft and a live page are equally
 * good proof that the reference exists, and nothing alike in what deleting the
 * image would cost.
 */
check(
	'A Reference carries the status of its location',
	false !== strpos( (string) file_get_contents( $src . '/Scanner/Reference.php' ), 'location_status' ),
	'the Risk Engine cannot tell a draft from a published page'
);

check(
	'Breadth counts only published locations',
	false !== strpos( method_body( $src . '/Risk/RiskEngine.php', 'is_published_location' ), 'location_status' ),
	'fifty drafts would escalate the risk score'
);

/*
 * 8h. Nothing reads a whole table into memory.
 *
 * "Batch. Never load 5,000 posts into memory." Exhausting memory is not a
 * failure the plugin can price the way it prices a database error — it is a
 * fatal that kills the request outright, leaving the scan row stuck at
 * "running" with no result and no explanation.
 *
 * Only wp_posts and wp_postmeta are checked. Options and term meta are bounded
 * by what a site can plausibly hold; posts are not.
 */
$unbounded = array();

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	// The resolver is the one legitimate exception. Its whole job is to hold an
	// index of the entire media library, so reading it in chunks would save no
	// memory — the result is the same size either way — and the contract says it
	// is built once and shared by every scanner. It is bounded by the number of
	// images, which is the thing the user is asking us about.
	if ( false !== strpos( $file, 'AttachmentResolver' ) ) {
		continue;
	}

	$source = (string) file_get_contents( $file );

	if ( ! preg_match( '/\{\$wpdb->(posts|postmeta)\}/', $source ) ) {
		continue;
	}

	$bounded = false !== strpos( $source, 'LIMIT %d OFFSET' ) || false !== strpos( $source, 'array_chunk' );

	if ( ! $bounded ) {
		$unbounded[] = rel( $root, $file );
	}
}

check(
	'No scanner reads posts or postmeta unbounded',
	empty( $unbounded ),
	implode( ', ', $unbounded )
);

/*
 * 8i. Every sentence a user reads is translatable.
 *
 * The admin layer got this right in 195 places while the engines that produce
 * the actual explanations had not a single `__()` between them — so a
 * non-English site saw its labels translated and every reason behind them in
 * English. For a plugin whose entire argument is that it explains itself, the
 * explanations are the worst possible thing to leave untranslated.
 */
$speakers = array(
	'Reports/HealthScore.php',
	'Reports/ExplanationBuilder.php',
	'Reports/DashboardReport.php',
	'Risk/RiskEngine.php',
	'Recommendation/RecommendationEngine.php',
);

$silent = array();

foreach ( $speakers as $file ) {
	$source = (string) file_get_contents( $src . '/' . $file );

	// Strip comments so prose in a docblock is not mistaken for output.
	$code = '';
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}

	// Collect every literal that IS wrapped. `_n()` takes two — the plural form
	// is an argument like any other, and a check that only looked at the first
	// would flag correctly-translated plurals.
	$wrapped = array();

	preg_match_all( "/__\(\s*('[^']*')/", $code, $singular );
	preg_match_all( "/_n\(\s*('[^']*')\s*,\s*('[^']*')/", $code, $plural );

	foreach ( array_merge( $singular[1], $plural[1], $plural[2] ) as $literal ) {
		$wrapped[ $literal ] = true;
	}

	// Anything left that reads like a sentence is going to the screen raw.
	if ( preg_match_all( "/'[A-Z][a-z]+ [a-z][^']*'/", $code, $matches ) ) {
		foreach ( $matches[0] as $literal ) {
			if ( ! isset( $wrapped[ $literal ] ) ) {
				$silent[] = $file . ': ' . $literal;
			}
		}
	}
}

check(
	'Every user-facing sentence goes through the translation layer',
	empty( $silent ),
	implode( "\n      ", array_slice( $silent, 0, 8 ) )
);

/*
 * 8j. A deleted image that something still points at is reported, not dropped.
 *
 * Every stored attachment id ran through `is_known_attachment()`, and every
 * failing branch was a `continue`. A `_thumbnail_id` pointing at attachment 900
 * after 900 was deleted looked exactly like a post with no featured image.
 *
 * The recording sites are deliberately few. The Content Scanner's sweep for
 * `"id":N` matches any block attribute called id, so reporting those would bury
 * the real findings — only fields that are KNOWN to hold an attachment id count.
 */
$known_field_scanners = array(
	'Scanner/MediaRelationshipScanner.php',
	'Scanner/ContentScanner.php',
	'Scanner/CustomizerScanner.php',
	'Scanner/WooCommerceScanner.php',
);

$dropping = array();

foreach ( $known_field_scanners as $file ) {
	if ( false === strpos( (string) file_get_contents( $src . '/' . $file ), 'note_missing_attachment' ) ) {
		$dropping[] = $file;
	}
}

check(
	'Scanners reading a known attachment-id field report it when the image is gone',
	empty( $dropping ),
	implode( ', ', $dropping )
);

/*
 * ACF is the fifth known-field scanner but is not in the list above: it
 * never calls note_missing_attachment() itself, because it never reads an
 * id off a row directly — every value is routed through
 * ImageValueExtractor::extract_declared_image(), the same shared walk every
 * other structured-data scanner uses. The declared-image flag IS "this
 * field is known to hold an attachment id", so the call has to live in the
 * extractor, gated on that flag, not duplicated per caller.
 */
check(
	'ACF-declared image fields report a missing attachment through the shared extractor',
	false !== strpos( (string) file_get_contents( $src . '/Scanner/ImageValueExtractor.php' ), 'note_missing_attachment' ),
	'extract_declared_image() drops an unresolvable id instead of reporting it'
);

check(
	'A missing attachment is distinguished from an unresolvable URL',
	false !== strpos( (string) file_get_contents( $src . '/Reports/DashboardReport.php' ), 'missing_attachment' ),
	'both are reported as the same thing'
);

/*
 * 8k. Every form control says what it is.
 *
 * The filter dropdowns and the bulk checkboxes carried no label at all — a
 * screen reader announced "combo box, Any status" with no hint of what it
 * filtered, and the select-all checkbox for a destructive bulk action used
 * `title`, which several readers ignore outright. The plugin's own rule is that
 * state is never communicated by appearance alone; that has to include the
 * controls, not just the badges.
 */
$unlabelled = array();

foreach ( php_files( $src . '/Admin' ) as $file ) {
	foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $number => $line ) {
		if ( ! preg_match( '/<select|type="checkbox"/', $line ) ) {
			continue;
		}

		if ( false === strpos( $line, 'aria-label' ) ) {
			$unlabelled[] = rel( $root, $file ) . ':' . ( $number + 1 );
		}
	}
}

check(
	'Every form control carries a label a screen reader can read',
	empty( $unlabelled ),
	implode( ', ', $unlabelled )
);

/*
 * 8l. The preview a user reads before deleting admits its own age.
 *
 * The Simulation panel is rendered from the stored scan, so between that scan
 * and the page load somebody could have published a page that now uses the
 * image. The Safety Engine refuses to act on a stale scan either way — but
 * being refused after reading a confident preview is worse than being warned.
 */
check(
	'The Simulation panel warns when its scan no longer matches the site',
	false !== strpos( method_body( $src . '/Admin/Pages/ImageDetailsPage.php', 'simulation_tab' ), 'staleness_warning' ),
	'a stale preview is shown as though it were current'
);

/*
 * 8m. A scanner searches everywhere its plugin stores images.
 *
 * The Elementor scanner read `_elementor_data` — the element tree — and nothing
 * else. Page backgrounds and anything set from Page Settings live in
 * `_elementor_page_settings`, a different key in a different format, and were
 * left to the zero-weight fallback. Weight 15 at reliability 0.97 is a claim
 * about how well Elementor was searched, and that claim has to be true.
 */
$elementor = (string) file_get_contents( $src . '/Scanner/ElementorScanner.php' );

foreach ( array( '_elementor_data', '_elementor_page_settings' ) as $store ) {
	check(
		"The Elementor scanner reads {$store}",
		false !== strpos( $elementor, $store ),
		'an Elementor store is left to the fallback'
	);
}

check(
	'The fallback does not claim the stores a real scanner owns',
	false !== strpos( (string) file_get_contents( $src . '/Scanner/GenericFallbackScanner.php' ), '_elementor_page_settings' ),
	'two scanners would report the same evidence'
);

/*
 * The declared check count is what the "N checks completed" figure in the
 * mockups (confidence-engine.md, evidence-engine.md) is derived from, so it
 * cannot drift silently. WooCommerce accounts for four of these (product
 * gallery, category image, brand image, placeholder image) and is skipped on
 * a site without a store, which is where a smaller number on a real site is
 * expected and correct.
 */
$declared = 0;

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	if ( preg_match( '/function checks\(\): array \{\s*return array\((.*?)\);/s', (string) file_get_contents( $file ), $m ) ) {
		$declared += (int) ( substr_count( $m[1], "'" ) / 2 );
	}
}

check(
	'The scanners declare 50 checks in total',
	50 === $declared,
	'counted ' . $declared . ' — update this alongside any change to a checks() list'
);

/*
 * 8n. An archived scan can actually be read back.
 *
 * The History screen's own docblock claimed every row was a frozen snapshot
 * "which is what makes 'the plugin told me this was unused last month' a
 * question anyone can actually answer" — while offering no way to answer it.
 * Everything needed was on disk: the counts, the coverage, the per-scanner
 * states, the confidence breakdown. Storing an audit trail nobody can open is
 * the same as not keeping one.
 */
$history = (string) file_get_contents( $src . '/Admin/Pages/HistoryPage.php' );

check(
	'A stored scan can be opened from the history list',
	false !== strpos( $history, 'View report' ),
	'the archive is write-only'
);

foreach ( array( 'confidence_breakdown', 'broken_references', 'recommendation_counts' ) as $recorded ) {
	check(
		"The archived report shows what the scan recorded in {$recorded}",
		false !== strpos( $history, $recorded ),
		'stored but not shown'
	);
}

/*
 * 8o. The Usage tab groups by scanner, and draws itself in one query.
 *
 * The specification asks for "every verified location, grouped by scanner". A
 * flat list answers "where is this image?"; the grouping answers the question
 * somebody deciding actually has — who says so, and how much is that worth.
 *
 * The old version also called `get_post()` inside the render loop, so an image
 * used in forty places cost forty queries to draw one table.
 */
$details = (string) file_get_contents( $src . '/Admin/Pages/ImageDetailsPage.php' );

check(
	'The Usage tab groups locations by scanner',
	false !== strpos( method_body( $src . '/Admin/Pages/ImageDetailsPage.php', 'usage_tab' ), 'by_scanner' ),
	'evidence is rendered as one flat list'
);

/*
 * The rule is "no lookup per row", not "no lookup". One `get_post()` for the
 * single image being viewed is fine; the same call inside a loop over that
 * image's evidence is what cost forty queries to draw one table. So the check
 * is scoped to the methods that iterate.
 */
$iterating = array( 'usage_tab', 'search_log', 'risk_breakdown' );
$in_loop   = array();

foreach ( $iterating as $method ) {
	$body = method_body( $src . '/Admin/Pages/ImageDetailsPage.php', $method );

	if ( '' !== $body && preg_match( '/\bget_post(_meta)?\(\s*\$/', $body ) ) {
		$in_loop[] = $method . '()';
	}
}

check(
	'No method that iterates evidence looks posts up one at a time',
	empty( $in_loop ),
	implode( ', ', $in_loop )
);

/*
 * 8p. All six specified tabs exist.
 *
 * "A new capability later means a new tab, not a longer page" is why tabs were
 * mandated at v1 rather than adopted when the screen got uncomfortable. Two of
 * the six were never built, so the capability they were reserved for had
 * nowhere to go.
 */
$missing_tabs = array();

foreach ( array( 'overview', 'usage', 'analysis', 'timeline', 'simulation', 'logs' ) as $tab ) {
	if ( ! preg_match( "/'{$tab}'\s*=>/", $details ) ) {
		$missing_tabs[] = $tab;
	}
}

check(
	'The Image Details screen has all six specified tabs',
	empty( $missing_tabs ),
	implode( ', ', $missing_tabs )
);

/*
 * A log entry knows which image it is about, rather than hiding the id in its
 * own message text where only a LIKE could find it — and a LIKE would match
 * #412 inside #4120, quietly showing somebody another image's history.
 */
check(
	'Log entries record the image they concern',
	false !== strpos( (string) file_get_contents( $src . '/Database/Tables.php' ), 'attachment_id BIGINT UNSIGNED NULL' )
		&& false !== strpos( (string) file_get_contents( $src . '/Database/LogRepository.php' ), 'for_attachment' ),
	'per-image history can only be found by matching message text'
);

/*
 * 8q. The Dashboard shows all nine cards, in the specified order.
 *
 * The specification is unusually firm here: "The order is the user's priority
 * order, and it is a decision, not a suggestion." Four of the nine did not
 * exist and the five that did ran in a different sequence, so the decision had
 * quietly been unmade.
 */
$expected_cards = array(
	'Quick Actions',
	'Scanner Status',
	'Coverage',
	'Confidence',
	'Risk Summary',
	'Storage',
	'Recommendations',
	'Recent Activity',
	'System',
);

$dashboard = method_body( $src . '/Admin/Pages/DashboardPage.php', 'cards' );

preg_match_all(
	"/<h3>' \. esc_html__\( '([A-Za-z ]+)'|this->(coverage|storage)_card/",
	$dashboard,
	$rendered
);

$order = array();

foreach ( $rendered[0] as $index => $match ) {
	if ( '' !== $rendered[1][ $index ] ) {
		$order[] = $rendered[1][ $index ];
		continue;
	}

	$order[] = ucfirst( $rendered[2][ $index ] );
}

check(
	'The Dashboard renders all nine cards in the documented order',
	$expected_cards === $order,
	'got: ' . implode( ' → ', $order )
);

/*
 * "Never render empty charts or zeroed statistics." The storage figures were
 * hardcoded to zero for six milestones, so the card could only have lied.
 */
check(
	'Storage figures come from stored file sizes, not from zero',
	1 !== preg_match( "/'total_bytes'\s*=>\s*0/", (string) file_get_contents( $src . '/Reports/DashboardReport.php' ) ),
	'the Storage card would render zeroes'
);

/*
 * 8r. The Images screen offers every filter and column the spec names.
 *
 * Three of six filters and six of eight columns existed. The missing ones were
 * not decoration: without a size column the Storage figures on the Dashboard
 * have nothing to drill into, and without a scanner filter there is no way to
 * ask "what did the fallback find on its own?", which is the question worth
 * asking after a scanner fails.
 */
$images = (string) file_get_contents( $src . '/Admin/Pages/ImagesPage.php' );

$absent = array();

foreach ( array( 'recommendation', 'status', 'confidence', 'risk_level', 'scanner', 'from', 'to' ) as $filter ) {
	if ( ! preg_match( "/'{$filter}'\s*=>/", $images ) ) {
		$absent[] = $filter;
	}
}

foreach ( array( 'Preview', 'File', 'Status', 'Confidence', 'Risk', 'Recommendation', 'Size', 'Last scanned' ) as $column ) {
	if ( false === strpos( $images, "esc_html__( '{$column}'" ) ) {
		$absent[] = $column;
	}
}

check(
	'The Images screen has all six filters and all eight columns',
	empty( $absent ),
	implode( ', ', $absent )
);

/*
 * Paging has to carry every filter. A page-two link that drops one shows a
 * different set of images under the same heading, and nothing on screen says so.
 */
check(
	'Pagination carries the whole filter set rather than a chosen few',
	false !== strpos( method_body( $src . '/Admin/Pages/ImagesPage.php', 'pagination' ), 'array_merge' ),
	'some filters are listed by hand and will be forgotten'
);

/*
 * 8s. No setting is offered that nothing obeys.
 *
 * The specification's rule for this screen is "every proposed setting must
 * answer 'will changing this improve the user's experience?' — if no, it does
 * not exist." An inert toggle fails that test twice over: it changes nothing,
 * and it tells the user it does.
 *
 * This is the same failure as the Settings screen that announced "Deletion —
 * Not available" on a build that deletes. A stated fact is honest; a switch
 * that does nothing is not.
 */
$settings_src = (string) file_get_contents( $src . '/Core/Settings.php' );

preg_match( '/GOVERNING = array\((.*?)\n\t\);/s', $settings_src, $block );
preg_match_all( "/'([a-z_]+)'\s*=>/", $block[1] ?? '', $governing );

$inert = array();

foreach ( $governing[1] ?? array() as $key ) {
	$obeyed = false;

	foreach ( $all_files as $file ) {
		if ( false !== strpos( $file, 'Settings.php' ) ) {
			continue;
		}

		if ( preg_match( "/Settings::(get|is_on)\(\s*'{$key}'/", (string) file_get_contents( $file ) ) ) {
			$obeyed = true;
			break;
		}
	}

	if ( ! $obeyed ) {
		$inert[] = $key;
	}
}

check(
	'Every setting offered is read by something',
	empty( $inert ),
	'inert: ' . implode( ', ', $inert )
);

check(
	'Saving settings is nonce-checked and capability-checked',
	1 === preg_match(
		'/handle_save_settings.*?current_user_can.*?check_admin_referer/s',
		(string) file_get_contents( $src . '/Admin/Menu.php' )
	),
	'the scan path reads this option — an unauthenticated write changes conclusions'
);

/*
 * 8t. Every scanner declares a version, and it reaches the fingerprint.
 *
 * "Every result also carries the scanner's version. A scan produced by a
 * scanner version that no longer exists is stale." The plugin version cannot
 * stand in for it: that moves on release, while a scanner can change several
 * times between releases — or not at all while the version moves for reasons
 * that have nothing to do with scanning.
 */
$versionless = array();

foreach ( php_files( $src . '/Scanner' ) as $file ) {
	$source = (string) file_get_contents( $file );

	if ( ! preg_match( '/function id\(\): string/', $source ) ) {
		continue;
	}

	if ( ! preg_match( '/function version\(\): string/', $source ) ) {
		$versionless[] = rel( $root, $file );
	}
}

check(
	'Every scanner declares its own version',
	empty( $versionless ),
	implode( ', ', $versionless )
);

check(
	'Scanner versions are folded into the scan fingerprint',
	false !== strpos( method_body( $src . '/Core/ScanFingerprint.php', 'environment' ), 'versions()' ),
	'changing a scanner would leave old scans looking current'
);

/*
 * 8u. The Content Scanner can reach its Warning state.
 *
 * `$warnings` was declared and tested and never written, so the state was
 * unreachable — a scanner that could only ever report Success or Failed, with
 * nothing in between for "we searched, but not perfectly".
 */
check(
	'The Content Scanner can report a warning',
	1 === preg_match( '/\$warnings\[\]\s*=/', (string) file_get_contents( $src . '/Scanner/ContentScanner.php' ) ),
	'the Warning state is declared but unreachable'
);

/*
 * 8v. Deleting a scan record cannot reach a media file.
 *
 * "Deleting a history record never deletes a media file. This must be
 * impossible to get wrong." So the guarantee is structural rather than careful:
 * `forget()` names four plugin tables, has no branch that can reach a WordPress
 * table, and calls no WordPress deletion function. There is no argument that
 * makes it destroy an image.
 */
$forget = method_body( $src . '/Database/ScanRepository.php', 'forget' );

check(
	'Deleting a scan record touches no WordPress table',
	'' !== $forget && 1 !== preg_match( '/wpdb->(posts|postmeta|users|options)/', $forget ),
	'the record-deletion path can reach WordPress data'
);

check(
	'Deleting a scan record calls no WordPress deletion function',
	'' !== $forget && 1 !== preg_match( '/wp_delete|unlink|wp_trash/', $forget ),
	'the record-deletion path can destroy a file'
);

/*
 * Both new handlers are guarded like every other one in the file. Export reads
 * a whole scan; record deletion erases one.
 */
$menu = (string) file_get_contents( $src . '/Admin/Menu.php' );

foreach ( array( 'handle_export_scan', 'handle_forget_scan' ) as $handler ) {
	check(
		"{$handler}() checks capability and nonce",
		1 === preg_match( '/' . $handler . '.*?current_user_can.*?check_admin_referer/s', $menu ),
		'an unguarded admin-post handler'
	);
}

/*
 * 8w. The scan result uses the documented vocabulary.
 *
 * Filing a scan where two scanners failed under "Completed" hides the one thing
 * somebody reading scan history is trying to find out.
 */
$repository = (string) file_get_contents( $src . '/Database/ScanRepository.php' );

foreach ( array( 'Completed With Warnings', 'Partially Completed', 'Failed' ) as $label ) {
	check(
		"The result vocabulary includes '{$label}'",
		false !== strpos( $repository, $label ),
		'a scan with holes reads as a clean one'
	);
}

/*
 * 8x. A user decision outranks the scan, and is enforced twice.
 *
 * Ignore, Mark Safe and Exclude Forever are the one place a person overrules
 * the engines. Everything else here is derived and a rescan can overturn it;
 * these cannot be, or the feature is decoration.
 *
 * Two enforcement points, because they answer different questions. The
 * Recommendation Engine stops SUGGESTING the image — that is advice. The
 * never-delete gate stops it being TRASHED — that is permission. Without the
 * second, a decision made today could be undone by a bulk action running
 * against a scan taken this morning.
 */
$recommendation = (string) file_get_contents( $src . '/Recommendation/RecommendationEngine.php' );

check(
	'The Recommendation Engine honours a user decision before its own gates',
	1 === preg_match( '/user_decision.*?honour_decision/s', method_body( $src . '/Recommendation/RecommendationEngine.php', 'decide_one' ) ),
	'a rescan would overturn what the user decided'
);

// The rule has to be in the list `check()` actually walks. A method that still
// exists but is no longer called is the shape this check exists to catch.
check(
	'The never-delete gate refuses images the user protected',
	false !== strpos( method_body( $src . '/Safety/NeverDeleteRules.php', 'check' ), 'user_said_so' ),
	'a decision stops the suggestion but not the deletion'
);

check(
	'The decision handler is capability and nonce checked',
	1 === preg_match( '/handle_decide.*?current_user_can.*?check_admin_referer/s', (string) file_get_contents( $src . '/Admin/Menu.php' ) ),
	'an unguarded write to what the plugin will delete'
);

/*
 * A decision is stored on the attachment rather than in a scan table, which is
 * what lets it outlive pruning and rebuilds — and is also why dropping the
 * tables on uninstall does not reach it.
 */
check(
	'Uninstall removes the decisions it stored on attachments',
	false !== strpos( (string) file_get_contents( $root . '/uninstall.php' ), 'delete_post_meta_by_key' ),
	'the plugin would leave meta behind on every image'
);

/*
 * Every decision must be reversible. "Exclude Forever" is exactly the label
 * that needs an obvious way back, and a decision the user cannot undo is a trap.
 */
check(
	'Every decision can be cleared from the interface',
	false !== strpos( (string) file_get_contents( $src . '/Admin/Pages/ImagesPage.php' ), "'clear'" )
		&& false !== strpos( (string) file_get_contents( $src . '/Admin/Pages/ImageDetailsPage.php' ), "'clear'" ),
	'a decision could be made but never unmade'
);

/*
 * 8y. The audit trail records what was found, not where we looked.
 *
 * `raw_match` is "the literal string found — kept for the audit trail". Nine
 * scanners were passing the field path into it, so the evidence row read
 * "found at settings.background_image — settings.background_image": a trail
 * that restates the question instead of answering it.
 */
$collector = method_body( $src . '/Scanner/CollectsReferences.php', 'collect_hits' );

check(
	'The shared collector passes the matched value, not the field path',
	'' !== $collector && false !== strpos( $collector, "raw" ) && 1 !== preg_match( "/\\\$hit\['method'\],\s*\\\$hit\['field'\]/", $collector ),
	'the audit trail repeats the field name twice'
);

check(
	'The evidence panel shows what was actually there',
	false !== strpos( method_body( $src . '/Admin/Pages/ImageDetailsPage.php', 'usage_tab' ), 'raw_match' ),
	'the matched string is stored and never shown'
);

/*
 * 9. The never-delete gate covers the whole of the site's identity.
 *
 * Its docblock promised logo, icon, header and background; the background was
 * missing for six milestones.
 */
$rules_src = (string) file_get_contents( $src . '/Safety/NeverDeleteRules.php' );

foreach ( array( 'site_icon', 'site_logo', 'custom_logo', 'header_image', 'background_image' ) as $key ) {
	check(
		"Never-delete gate protects '{$key}'",
		false !== strpos( $rules_src, "'{$key}'" ),
		'not checked by the gate'
	);
}

/*
 * 10. A scan is only valid for the content it was run against.
 *
 * Hashing attachments alone let a page be edited to ADD an image while the
 * stored scan — still reporting that image Unused — stayed valid and trashable.
 */
$fingerprint_src = (string) file_get_contents( $src . '/Core/ScanFingerprint.php' );
$full_body       = method_body( $src . '/Core/ScanFingerprint.php', 'full' );

check(
	'The scan fingerprint includes the content that was searched',
	false !== strpos( $full_body, 'content()' ),
	'a content edit would not invalidate a stored scan'
);

check(
	'The fingerprint fits its database column',
	66 <= 96,
	'scanner_fingerprint must be wide enough or it truncates and never matches'
);

/*
 * 11. The admin layer renders; it never destroys.
 */
$admin_offenders = array();

foreach ( php_files( $src . '/Admin' ) as $file ) {
	if ( array_intersect( called_functions( $file ), $destructive ) ) {
		$admin_offenders[] = rel( $root, $file );
	}
}

check(
	'No admin screen calls a destructive function directly',
	empty( $admin_offenders ),
	implode( ', ', $admin_offenders )
);

/*
 * 12. Namespace matches path, everywhere. PSR-4 is load-bearing: the autoloader
 *     is hand-rolled and a mismatch is a fatal error at runtime, not a warning.
 */
$namespace_offenders = array();

foreach ( $all_files as $file ) {
	$source = (string) file_get_contents( $file );

	// Anchored to the start of a line: the word also appears in prose, and a
	// docblock sentence ending in a semicolon should not count as a declaration.
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $m ) ) {
		$namespace_offenders[] = rel( $root, $file ) . ' — no namespace';
		continue;
	}

	$expected = 'UnusedImageCleaner\\' . str_replace( '/', '\\', dirname( rel( $root, $file ) ) );
	$expected = rtrim( str_replace( 'UnusedImageCleaner\\src', 'UnusedImageCleaner', $expected ), '\\' );

	if ( trim( $m[1] ) !== $expected ) {
		$namespace_offenders[] = rel( $root, $file ) . " — {$m[1]}, expected {$expected}";
	}
}

check(
	'Every namespace matches its directory',
	empty( $namespace_offenders ),
	implode( "\n      ", $namespace_offenders )
);

/*
 * 13. The plugin must not tell the user something the code contradicts.
 */
$claim_offenders = array();

foreach ( array_merge( php_files( $src ), php_files( $root . DIRECTORY_SEPARATOR . 'tools' ) ) as $file ) {
	$source = (string) file_get_contents( $file );

	if ( preg_match( '/(performs no deletions|removes nothing|no delete path|all read-only)/i', $source, $m ) ) {
		$claim_offenders[] = rel( $root, $file ) . ' — "' . $m[1] . '"';
	}
}

check(
	'Nothing claims the plugin cannot delete',
	empty( $claim_offenders ),
	implode( "\n      ", $claim_offenders )
);

// ------------------------------------------------------------------ report ---

echo "\n";

foreach ( $failed as $failure ) {
	echo "  FAIL  {$failure}\n";
}

$total = $passed + count( $failed );

echo str_repeat( '-', 60 ) . "\n";
printf( "%d/%d assertions passed\n", $passed, $total );

if ( $failed ) {
	printf( "\n%d INVARIANT(S) BROKEN.\n", count( $failed ) );
	exit( 1 );
}

echo "\nAll safety invariants hold.\n";
exit( 0 );
