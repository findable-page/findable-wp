<?php
/**
 * Fix_Advice and i18n tests.
 *
 * @package findable-wp
 */

use Findable\Fix_Advice;

class Test_Fix_Advice extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		delete_transient( 'findable_check_text_de' );
		delete_transient( 'findable_check_text_es' );
	}

	public function test_known_check_uses_bundled_advice() {
		$this->assertSame(
			'Keep the title between 30 and 60 characters so search engines do not truncate it.',
			Fix_Advice::for_check( 'title_length' )
		);
	}

	public function test_unknown_check_gets_generic_advice() {
		$this->assertSame(
			'See the full report on findable.page for how to fix this check.',
			Fix_Advice::for_check( 'brand_new_check_we_do_not_know' )
		);
	}

	public function test_lang_mapping() {
		$this->assertSame( 'de', Fix_Advice::lang( 'de_DE' ) );
		$this->assertSame( 'es', Fix_Advice::lang( 'es_ES' ) );
		$this->assertSame( 'ru', Fix_Advice::lang( 'ru_RU' ) );
		$this->assertSame( 'tr', Fix_Advice::lang( 'tr_TR' ) );
		$this->assertSame( '', Fix_Advice::lang( 'fr_FR' ) );
		$this->assertSame( '', Fix_Advice::lang( 'en_US' ) );
	}

	public function test_localized_advice_from_api_cached() {
		$de = wp_json_encode(
			array(
				'title_length' => array(
					'name' => 'Titellänge',
					'fix'  => 'Halte den Titel zwischen 30 und 60 Zeichen.',
				),
			)
		);
		$this->stub_http( $this->http_response( 200, $de ), $calls );

		$advice = Fix_Advice::for_check( 'title_length', 'de_DE' );
		$this->assertSame( 'Halte den Titel zwischen 30 und 60 Zeichen.', $advice );

		// Second lookup comes from the transient, no new HTTP call.
		Fix_Advice::for_check( 'title_length', 'de_DE' );
		$this->assertCount( 1, $calls );
		$this->assertNotEmpty( get_transient( 'findable_check_text_de' ) );
	}

	public function test_failed_fetch_negative_caches_for_1h() {
		$this->stub_http( $this->http_response( 500, 'down' ), $calls );

		$this->assertSame(
			'Keep the title between 30 and 60 characters so search engines do not truncate it.',
			Fix_Advice::for_check( 'title_length', 'de_DE' )
		);

		// Negative cache present: empty array cached for 1h.
		$this->assertSame( array(), get_transient( 'findable_check_text_de' ) );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS - 30, (int) get_option( '_transient_timeout_findable_check_text_de' ) );
		Fix_Advice::for_check( 'title_length', 'de_DE' );
		$this->assertCount( 1, $calls );
	}

	public function test_empty_text_negative_caches_for_1h() {
		$this->stub_http( $this->http_response( 200, '{}' ), $calls );
		$this->assertSame( array(), Fix_Advice::text_map( 'de_DE' ) );
		$this->assertSame( array(), Fix_Advice::text_map( 'de_DE' ) );
		$this->assertCount( 1, $calls );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS - 30, (int) get_option( '_transient_timeout_findable_check_text_de' ) );
	}

	public function test_localized_label_falls_back_to_stored() {
		$this->stub_http( $this->http_response( 500, 'down' ) );
		$label = Fix_Advice::label(
			array(
				'id'    => 'title_length',
				'label' => 'Title length',
			),
			'fr_FR'
		);
		$this->assertSame( 'Title length', $label );
	}

	public function test_check_text_transient_via_text_map() {
		$this->stub_http(
			$this->http_response(
				200,
				wp_json_encode(
					array(
						'desc_exists' => array(
							'name' => 'Desc',
							'fix'  => 'Add one.',
						),
					)
				)
			),
			$calls
		);

		$map = Fix_Advice::text_map( 'es_ES' );
		$this->assertSame( 'https://findable.page/i18n/es.json', $calls[0]['url'] );
		$this->assertArrayHasKey( 'desc_exists', $map );

		// Unsupported locale: no fetch at all.
		$map = Fix_Advice::text_map( 'fr_FR' );
		$this->assertSame( array(), $map );
		$this->assertCount( 1, $calls );
	}
}
