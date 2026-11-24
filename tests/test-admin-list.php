<?php
/**
 * Posts list table: Findable column sorting.
 *
 * @package findable-wp
 */

use Findable\Admin;

class Test_Admin_List extends Findable_TestCase {

	public function setUp(): void {
		parent::setUp();
		set_current_screen( 'edit-post' );
		$GLOBALS['pagenow'] = 'edit.php';
		add_action( 'pre_get_posts', array( Admin::class, 'orderby_score' ) );
	}

	public function tearDown(): void {
		remove_action( 'pre_get_posts', array( Admin::class, 'orderby_score' ) );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Run a main admin list query sorted by the Findable column.
	 *
	 * @param string $order ASC or DESC.
	 * @return int[] Post ids in result order.
	 */
	private function sorted_ids( $order ) {
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		return array_map(
			'intval',
			$query->query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'orderby'        => 'findable_score',
					'order'          => $order,
					'fields'         => 'ids',
					'posts_per_page' => 10,
				)
			)
		);
	}

	public function test_unscanned_posts_sort_last_in_both_directions() {
		$unscanned = $this->create_scannable_post();
		$low       = $this->create_scannable_post();
		$high      = $this->create_scannable_post();
		update_post_meta( $low, '_findable_overall', 20 );
		update_post_meta( $high, '_findable_overall', 90 );

		$this->assertSame( array( $low, $high, $unscanned ), $this->sorted_ids( 'ASC' ) );
		$this->assertSame( array( $high, $low, $unscanned ), $this->sorted_ids( 'DESC' ) );
	}
}
