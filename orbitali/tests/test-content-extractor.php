<?php
/**
 * Covers extraction of headings/lists/tables, a representative Divi
 * shortcode sample, unsupported-shortcode handling (unwrapped, never
 * executed), and empty-content reporting.
 *
 * @package Orbitali
 */

class Test_Orbitali_Content_Extractor extends WP_UnitTestCase {

	private $registered_shortcodes = array();

	public function tear_down() {
		foreach ( $this->registered_shortcodes as $tag ) {
			remove_shortcode( $tag );
		}
		$this->registered_shortcodes = array();
		parent::tear_down();
	}

	private function register_shortcode( $tag, $callback ) {
		add_shortcode( $tag, $callback );
		$this->registered_shortcodes[] = $tag;
	}

	private function post_with_content( $content ) {
		return self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	public function test_extracts_headings_paragraphs_lists_links_and_tables() {
		$post = $this->post_with_content(
			'<h2>Pricing</h2>' .
			'<p>Choose the plan that fits, see <a href="/pricing">details</a>.</p>' .
			'<ul><li>Starter</li><li>Pro</li></ul>' .
			'<table><tr><th>Plan</th><th>Price</th></tr><tr><td>Starter</td><td>$9</td></tr></table>'
		);

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertFalse( $result['empty'] );
		$this->assertStringContainsString( '## Pricing', $result['body_markdown'] );
		$this->assertStringContainsString( '[details](' . home_url( '/pricing' ) . ')', $result['body_markdown'] );
		$this->assertStringContainsString( '- Starter', $result['body_markdown'] );
		$this->assertStringContainsString( '- Pro', $result['body_markdown'] );
		$this->assertStringContainsString( '| Plan | Price |', $result['body_markdown'] );
		$this->assertStringContainsString( '| Starter | $9 |', $result['body_markdown'] );
	}

	public function test_executes_divi_builder_shortcodes() {
		$this->register_shortcode(
			'et_pb_section',
			function ( $atts, $content = null ) {
				return do_shortcode( $content );
			}
		);
		$this->register_shortcode(
			'et_pb_text',
			function ( $atts, $content = null ) {
				return '<p>' . $content . '</p>';
			}
		);

		$post = $this->post_with_content( '[et_pb_section][et_pb_text]Divi builder paragraph[/et_pb_text][/et_pb_section]' );

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertStringContainsString( 'Divi builder paragraph', $result['body_markdown'] );
	}

	public function test_unsupported_shortcodes_are_unwrapped_not_executed() {
		$this->register_shortcode(
			'random_widget',
			function ( $atts, $content = null ) {
				return '<p>RENDERED:' . $content . '</p>';
			}
		);

		$post = $this->post_with_content( '<p>Before</p>[random_widget]authored text[/random_widget]<p>After</p>' );

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertStringNotContainsString( 'RENDERED', $result['body_markdown'] );
		$this->assertStringContainsString( 'authored text', $result['body_markdown'] );
	}

	public function test_self_closing_unsupported_shortcode_is_excluded_and_reported() {
		$this->register_shortcode(
			'random_widget2',
			function () {
				return 'SHOULD_NOT_APPEAR';
			}
		);

		$post = $this->post_with_content( '<p>Keep this</p>[random_widget2]' );

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertStringNotContainsString( 'SHOULD_NOT_APPEAR', $result['body_markdown'] );
		$this->assertStringContainsString( 'Keep this', $result['body_markdown'] );
		$this->assertNotEmpty( $result['warnings'] );
		$found = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( false !== strpos( $warning, 'random_widget2' ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected a warning naming the excluded shortcode.' );
	}

	public function test_scripts_styles_and_navigation_are_stripped() {
		$post = $this->post_with_content(
			'<script>alert(1)</script><style>.x{color:red}</style><nav><a href="/">Home</a></nav><footer>Copyright</footer><p>Real content</p>'
		);

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertStringNotContainsString( 'alert(1)', $result['body_markdown'] );
		$this->assertStringNotContainsString( 'color:red', $result['body_markdown'] );
		$this->assertStringNotContainsString( 'Copyright', $result['body_markdown'] );
		$this->assertStringContainsString( 'Real content', $result['body_markdown'] );
	}

	public function test_empty_content_is_reported_as_empty() {
		$post = $this->post_with_content( '' );

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertTrue( $result['empty'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_content_with_nothing_readable_after_stripping_is_reported_as_empty() {
		$post = $this->post_with_content( '<script>var x = 1;</script><nav>Menu only</nav>' );

		$result = Orbitali_Content_Extractor::extract( $post );

		$this->assertTrue( $result['empty'] );
	}
}
