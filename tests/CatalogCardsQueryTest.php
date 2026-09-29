<?php
/**
 * Regression tests for the query behind the public catalog.
 *
 * The pivot query that feeds the shared URL and the PDF aliased one of its
 * columns as `condition`, which MySQL treats as a reserved word: the whole
 * statement failed to parse, every owner ended up with an empty catalog
 * ("0 inmuebles") and the PDF download answered with a 500. A column alias
 * is invisible in a unit test that only inspects the rendered output, so the
 * statement is captured here and scanned for the same class of mistake.
 *
 * @package Arriendo_Facil
 */

use PHPUnit\Framework\TestCase;

require_once ARRIENDO_FACIL_PLUGIN_DIR . 'includes/class-tenancy.php';

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Returns the candidate property ids the card query asks for.
	 *
	 * @param array $args Query arguments (unused).
	 * @return int[]
	 */
	function get_posts( $args = array() ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return isset( $GLOBALS['af_test_candidate_ids'] ) ? array_map( 'absint', (array) $GLOBALS['af_test_candidate_ids'] ) : array();
	}
}

/**
 * Class CatalogCardsQueryTest
 */
class CatalogCardsQueryTest extends TestCase {

	/**
	 * MySQL reserved words that would break an unquoted column alias.
	 *
	 * @var string[]
	 */
	private const RESERVED_WORDS = array(
		'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'asensitive',
		'before', 'between', 'bigint', 'binary', 'blob', 'both', 'call',
		'cascade', 'case', 'change', 'char', 'character', 'check', 'collate',
		'column', 'condition', 'constraint', 'continue', 'convert', 'create',
		'cross', 'current_date', 'current_time', 'current_timestamp',
		'current_user', 'cursor', 'database', 'databases', 'day_hour',
		'day_microsecond', 'day_minute', 'day_second', 'dec', 'decimal',
		'declare', 'default', 'delayed', 'delete', 'desc', 'describe',
		'deterministic', 'distinct', 'distinctrow', 'div', 'double', 'drop',
		'dual', 'each', 'else', 'elseif', 'enclosed', 'escaped', 'except',
		'exists', 'exit', 'explain', 'false', 'fetch', 'float', 'float4',
		'float8', 'for', 'force', 'foreign', 'from', 'fulltext', 'generated',
		'get', 'grant', 'group', 'having', 'high_priority', 'hour_microsecond',
		'hour_minute', 'hour_second', 'if', 'ignore', 'in', 'index', 'infile',
		'inner', 'inout', 'insensitive', 'insert', 'int', 'int1', 'int2',
		'int3', 'int4', 'int8', 'integer', 'interval', 'into', 'io_after_gtids',
		'io_before_gtids', 'is', 'iterate', 'join', 'json_table', 'key', 'keys',
		'kill', 'leading', 'leave', 'like', 'limit', 'linear', 'lines', 'load',
		'localtime', 'localtimestamp', 'lock', 'long', 'longblob', 'longtext',
		'loop', 'low_priority', 'master_bind', 'match', 'maxvalue',
		'mediumblob', 'mediumint', 'mediumtext', 'middleint',
		'minute_microsecond', 'minute_second', 'mod', 'modifies', 'natural',
		'not', 'no_write_to_binlog', 'null', 'numeric', 'of', 'on', 'optimize',
		'optimizer_costs', 'option', 'optionally', 'or', 'order', 'out',
		'outer', 'outfile', 'over', 'partition', 'precision', 'primary',
		'procedure', 'purge', 'range', 'read', 'reads', 'read_write', 'real',
		'references', 'regexp', 'release', 'rename', 'repeat', 'replace',
		'require', 'resignal', 'restrict', 'return', 'revoke', 'rlike',
		'schema', 'schemas', 'second_microsecond', 'select', 'sensitive',
		'separator', 'set', 'show', 'signal', 'smallint', 'spatial', 'specific',
		'sql', 'sqlexception', 'sqlstate', 'sqlwarning', 'sql_after_gtids',
		'sql_before_gtids', 'ssl', 'starting', 'stored', 'straight_join',
		'system', 'table', 'terminated', 'then', 'tinyblob', 'tinyint',
		'tinytext', 'to', 'trailing', 'trigger', 'true', 'undo', 'union',
		'unique', 'unlock', 'unsigned', 'update', 'usage', 'use', 'using',
		'utc_date', 'utc_time', 'utc_timestamp', 'values', 'varbinary',
		'varchar', 'varcharacter', 'varying', 'virtual', 'when', 'where',
		'while', 'with', 'write', 'xor', 'year_month', 'zerofill',
	);

	/**
	 * Cast targets are written as "AS <type>" too, and are always valid.
	 *
	 * @var string[]
	 */
	private const CAST_TYPES = array( 'binary', 'char', 'date', 'datetime', 'decimal', 'json', 'nchar', 'real', 'signed', 'time', 'unsigned', 'year' );

	/**
	 * Captures the pivot query issued by the catalog card loader.
	 *
	 * @return string The SQL statement, with placeholders resolved.
	 */
	private function capture_cards_sql() {
		$method = ( new ReflectionClass( 'Arriendo_Facil_Catalog_Share' ) )->getMethod( 'get_cards' );

		$previous_candidates = isset( $GLOBALS['af_test_candidate_ids'] ) ? $GLOBALS['af_test_candidate_ids'] : null;
		$GLOBALS['af_test_candidate_ids'] = array( 800, 833 );

		$captured = null;
		$wpdb     = new class( $captured ) {

			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string|null */
			public $captured;

			public function __construct( &$captured ) {
				$this->captured = &$captured;
			}

			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( array( '%d', '%f', '%s' ), '%s', $query ), $args );
			}

			public function get_results( $query ) {
				$this->captured = $query;
				return array();
			}
		};

		$previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			// No rows are replayed: the assertions are about the statement.
			$this->assertSame( array(), $method->invoke( null, 67 ) );
		} finally {
			$GLOBALS['wpdb']             = $previous_wpdb;
			$GLOBALS['af_test_candidate_ids'] = $previous_candidates;
		}

		$this->assertIsString( $wpdb->captured, 'La consulta del catálogo no llegó a ejecutarse.' );

		return $wpdb->captured;
	}

	/**
	 * No column alias may be a bare reserved word: MySQL rejects the entire
	 * statement, which silently emptied both the shared page and the PDF.
	 */
	public function test_aliases_never_use_reserved_words() {
		$sql = $this->capture_cards_sql();

		// Only unquoted aliases are parsed as bare words here.
		preg_match_all( '/\bAS\s+([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $matches );

		$offenders = array();

		foreach ( $matches[1] as $alias ) {
			$word = strtolower( $alias );

			if ( in_array( $word, self::CAST_TYPES, true ) ) {
				continue;
			}

			if ( in_array( $word, self::RESERVED_WORDS, true ) ) {
				$offenders[] = $alias;
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $offenders ) ),
			'Un alias con palabra reservada de MySQL invalida toda la consulta del catálogo: ' . $sql
		);
	}

	/**
	 * The condition column must still be part of the pivot, quoted so it
	 * parses: it is the exact column that broke the public catalog.
	 */
	public function test_condition_column_is_mapped_and_quoted() {
		$sql = $this->capture_cards_sql();

		$this->assertStringContainsString( "'_af_condition'", $sql );
		$this->assertMatchesRegularExpression( '/\bAS\s+`condition`\s*,/', $sql );
	}

	/**
	 * Ownership scoping is deliberately enforced three times; the SQL layer
	 * must keep both the candidate filter and the hard owner predicate.
	 */
	public function test_query_keeps_the_ownership_predicates() {
		$sql = $this->capture_cards_sql();

		$this->assertStringContainsString( "po.meta_key = '_af_owner_id'", $sql );
		$this->assertStringContainsString( 'po.meta_value = 67', $sql );
		$this->assertStringContainsString( "p.post_type = 'accommodation'", $sql );
		$this->assertStringContainsString( "p.post_status = 'publish'", $sql );
		$this->assertStringContainsString( 'p.ID IN (800,833)', $sql );
	}
}
