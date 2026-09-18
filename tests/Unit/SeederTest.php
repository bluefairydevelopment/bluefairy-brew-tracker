<?php
/**
 * The style tree: seeded once, never overwritten.
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SeederTest extends TestCase {

	protected function setUp(): void {
		bfbt_reset_stubs();
	}

	/**
	 * @return object[]
	 */
	private function styles(): array {
		return array_values( $GLOBALS['bfbt_terms']['bfbt_style'] ?? [] );
	}

	public function test_a_fresh_site_gets_four_parents_and_twenty_children(): void {
		$this->assertSame( 24, BFBT_Seeder::seed_styles() );

		$styles  = $this->styles();
		$parents = array_filter( $styles, static fn( object $t ): bool => 0 === $t->parent );

		$this->assertCount( 24, $styles );
		$this->assertCount( 4, $parents );
		$this->assertSame( [ 'mead', 'wine', 'cider', 'beer' ], array_column( array_values( $parents ), 'slug' ) );
	}

	public function test_every_child_hangs_under_its_category(): void {
		BFBT_Seeder::seed_styles();

		$mead  = get_term_by( 'slug', 'mead', 'bfbt_style' );
		$cyser = get_term_by( 'slug', 'cyser', 'bfbt_style' );
		$beer  = get_term_by( 'slug', 'beer', 'bfbt_style' );
		$ipa   = get_term_by( 'slug', 'ipa', 'bfbt_style' );
		$rose  = get_term_by( 'slug', 'rose', 'bfbt_style' );

		$this->assertSame( $mead->term_id, $cyser->parent );
		$this->assertSame( $beer->term_id, $ipa->parent );
		$this->assertSame( 'Rosé', $rose->name );
	}

	public function test_the_four_parents_carry_the_category_meta_and_children_do_not(): void {
		BFBT_Seeder::seed_styles();

		foreach ( [ 'mead', 'wine', 'cider', 'beer' ] as $slug ) {
			$this->assertSame( $slug, get_term_meta( get_term_by( 'slug', $slug, 'bfbt_style' )->term_id, 'bfbt_category', true ) );
		}

		$this->assertSame( '', get_term_meta( get_term_by( 'slug', 'traditional', 'bfbt_style' )->term_id, 'bfbt_category', true ) );
	}

	/**
	 * Activating twice seeds once.
	 */
	public function test_a_second_run_creates_nothing(): void {
		BFBT_Seeder::seed_styles();
		$before = $this->styles();

		$this->assertSame( 0, BFBT_Seeder::seed_styles() );
		$this->assertEquals( $before, $this->styles() );
		$this->assertCount( 24, $this->styles() );
	}

	/**
	 * A term the maker renamed keeps its name; a missing sibling still
	 * arrives.
	 */
	public function test_an_existing_term_is_left_exactly_as_it_is(): void {
		$mead = wp_insert_term( 'Honey wine', 'bfbt_style', [ 'slug' => 'mead' ] );
		wp_insert_term( 'Straight mead', 'bfbt_style', [ 'slug' => 'traditional', 'parent' => $mead['term_id'] ] );
		update_term_meta( $mead['term_id'], 'bfbt_category', 'custom' );

		$this->assertSame( 22, BFBT_Seeder::seed_styles() );

		$this->assertSame( 'Honey wine', get_term_by( 'slug', 'mead', 'bfbt_style' )->name );
		$this->assertSame( 'Straight mead', get_term_by( 'slug', 'traditional', 'bfbt_style' )->name );
		$this->assertSame( 'custom', get_term_meta( $mead['term_id'], 'bfbt_category', true ) );
		$this->assertSame( $mead['term_id'], get_term_by( 'slug', 'melomel', 'bfbt_style' )->parent );
	}

	public function test_the_seeded_marker_is_written(): void {
		$this->assertArrayNotHasKey( 'bfbt_seeded', $GLOBALS['bfbt_options'] );

		BFBT_Seeder::seed_styles();

		$this->assertSame( '1', $GLOBALS['bfbt_options']['bfbt_seeded'] );
	}
}
