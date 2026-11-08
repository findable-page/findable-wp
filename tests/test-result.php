<?php
/**
 * Result::normalize tests.
 *
 * @package findable-wp
 */

use Findable\Result;

class Test_Result extends Findable_TestCase {

	public function test_valid_response_normalizes() {
		$result = Result::normalize( $this->scan_response() );

		$this->assertNotWPError( $result );
		$this->assertSame( 72, $result['overall'] );
		$this->assertSame( 1, $result['v'] );

		foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
			$this->assertArrayHasKey( 'score', $result['families'][ $family ] );
			$this->assertArrayHasKey( 'passed', $result['families'][ $family ] );
			$this->assertArrayHasKey( 'total', $result['families'][ $family ] );
		}
		$this->assertSame( 80, $result['families']['seo']['score'] );
		$this->assertSame( 3, $result['families']['seo']['total'] );
		// Not-applicable checks count in checks, not applicable totals.
		$this->assertSame( 2, $result['families']['seo']['passed'] );

		foreach ( $result['checks'] as $check ) {
			$this->assertArrayHasKey( 'id', $check );
			$this->assertArrayHasKey( 'f', $check );
			$this->assertArrayHasKey( 'label', $check );
			$this->assertArrayHasKey( 's', $check );
		}
		$this->assertSame( 8, count( $result['checks'] ) );
	}

	public function test_out_of_range_score_clamps_to_100() {
		$result = Result::normalize( $this->scan_response( array( 'overall' => 9999 ) ) );
		$this->assertSame( 100, $result['overall'] );

		$result = Result::normalize( $this->scan_response( array( 'overall' => -5 ) ) );
		$this->assertSame( 0, $result['overall'] );
	}

	public function test_missing_family_is_error() {
		$response = $this->scan_response();
		unset( $response['geo'] );

		$result = Result::normalize( $response );
		$this->assertWPError( $result );
		$this->assertSame( 'findable_bad_response', $result->get_error_code() );
	}

	public function test_non_numeric_overall_is_error() {
		$result = Result::normalize( $this->scan_response( array( 'overall' => 'high' ) ) );
		$this->assertWPError( $result );
	}

	public function test_invalid_check_dropped_others_kept() {
		$response                    = $this->scan_response();
		$response['seo']['checks'][] = array(
			'id'    => 'Bad Id!',
			'label' => 'x',
			'pass'  => false,
		);
		$result                      = Result::normalize( $response );
		$this->assertNotWPError( $result );
		$ids = wp_list_pluck( $result['checks'], 'id' );
		$this->assertNotContains( 'Bad Id!', $ids );
		$this->assertContains( 'title_length', $ids );
	}

	public function test_na_check_is_not_failing() {
		$result = Result::normalize( $this->scan_response() );
		$na     = null;
		foreach ( $result['checks'] as $check ) {
			if ( 'geo_authoritative_links' === $check['id'] ) {
				$na = $check;
			}
		}
		$this->assertSame( 'na', $na['s'] );
		$this->assertSame( 2, $result['families']['geo']['total'] );
		$this->assertSame( 4, $result['passing'] );
		$this->assertSame( 3, $result['failing'] );
		$this->assertNotContains( $na, Result::failing( $result ) );
	}

	public function test_hostile_label_has_no_tags() {
		$response                     = $this->scan_response();
		$response['seo']['checks'][0] = array(
			'id'    => 'title_exists',
			'label' => '<script>alert(1)</script>Title',
			'pass'  => true,
		);
		$result                       = Result::normalize( $response );
		foreach ( $result['checks'] as $check ) {
			if ( 'title_exists' === $check['id'] ) {
				$this->assertSame( 'Title', $check['label'] );
			}
		}
	}

	public function test_check_cap_400() {
		$response = $this->scan_response();
		$checks   = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$checks[] = array(
				'id'    => 'check_' . $i,
				'label' => 'C',
				'pass'  => true,
			);
		}
		$response['seo']['checks'] = $checks;
		$result                    = Result::normalize( $response );
		$this->assertSame( 400, count( $result['checks'] ) );
	}

	public function test_band_boundaries() {
		$this->assertSame( 'poor', Result::band( 49 ) );
		$this->assertSame( 'needs_work', Result::band( 50 ) );
		$this->assertSame( 'needs_work', Result::band( 79 ) );
		$this->assertSame( 'good', Result::band( 80 ) );
	}

	public function test_non_array_input_is_error() {
		$this->assertWPError( Result::normalize( 'nope' ) );
		$this->assertWPError( Result::normalize( null ) );
	}

	public function test_non_array_family_or_check_container_is_error() {
		foreach ( array( 'seo', 'aeo', 'geo' ) as $family ) {
			$response                       = $this->scan_response();
			$response[ $family ]['checks'] = 'nope';
			$this->assertWPError( Result::normalize( $response ), $family );
		}
	}

	public function test_check_without_label_uses_id_and_malformed_checks_are_dropped() {
		$response                    = $this->scan_response();
		$response['seo']['checks'][] = 'not-an-array';
		$response['seo']['checks'][] = array( 'id' => 'no_pass_field', 'label' => 'x' );
		$response['seo']['checks'][] = array( 'id' => 'pass_not_bool', 'pass' => 'yes' );
		$response['seo']['checks'][] = array( 'id' => 'no_label_check', 'pass' => true );
		$result                      = Result::normalize( $response );

		$ids = wp_list_pluck( $result['checks'], 'id' );
		$this->assertNotContains( 'no_pass_field', $ids );
		$this->assertNotContains( 'pass_not_bool', $ids );
		$this->assertContains( 'no_label_check', $ids );
		$labels = wp_list_pluck( $result['checks'], 'label', 'id' );
		$this->assertSame( 'no_label_check', $labels['no_label_check'] );
	}

	public function test_band_labels_and_failing_edge_cases() {
		$this->assertSame( 'Good', Result::band_label( 'good' ) );
		$this->assertSame( 'Needs work', Result::band_label( 'needs_work' ) );
		$this->assertSame( 'Poor', Result::band_label( 'poor' ) );
		$this->assertSame( 'Poor', Result::band_label( 'anything-else' ) );
		$this->assertSame( array(), Result::failing( array() ) );
		$this->assertSame( array(), Result::failing( array( 'checks' => 'x' ) ) );
	}
}
