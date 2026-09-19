<?php
/**
 * A refused save keeps what was typed: remember_input() / old().
 *
 * @package BlueFairy\BrewTracker
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormMemoryTest extends TestCase {

	protected function setUp(): void {
		bfbt_reset_stubs();
		BFBT_Batch_Admin::forget_input();
	}

	public function test_remembered_fields_come_back_for_the_same_form_and_the_refused_value_with_them(): void {
		BFBT_Batch_Admin::remember_input( 'log', [ 'log_type' => 'temperature', 'temp' => '200', 'temp_unit' => 'f', 'note' => '<p>too hot</p>' ] );

		$this->assertArrayHasKey( 'bfbt_form_1', $GLOBALS['bfbt_transients'], 'stored per user, beside the notice' );
		$this->assertSame( 'temperature', BFBT_Batch_Admin::old( 'log', 'log_type', 'addition' ) );
		$this->assertSame( '200', BFBT_Batch_Admin::old( 'log', 'temp' ) );
		$this->assertSame( 'f', BFBT_Batch_Admin::old( 'log', 'temp_unit', 'c' ) );
		$this->assertSame( '<p>too hot</p>', BFBT_Batch_Admin::old( 'log', 'note' ) );
		// A field that was not posted answers the default.
		$this->assertSame( '1.090', BFBT_Batch_Admin::old( 'log', 'sg', '1.090' ) );
	}

	public function test_another_forms_memory_is_not_this_forms(): void {
		BFBT_Batch_Admin::remember_input( 'task', [ 'title' => 'Rack' ] );

		$this->assertSame( '', BFBT_Batch_Admin::old( 'log', 'title' ) );
		$this->assertSame( 'Rack', BFBT_Batch_Admin::old( 'task', 'title' ) );
	}

	/**
	 * The store is read once. Within the request every field is served from
	 * memory; the next request finds nothing and renders a fresh form.
	 */
	public function test_the_second_request_reads_an_empty_store(): void {
		BFBT_Batch_Admin::remember_input( 'log', [ 'temp' => '200' ] );

		$this->assertSame( '200', BFBT_Batch_Admin::old( 'log', 'temp' ) );
		$this->assertSame( '200', BFBT_Batch_Admin::old( 'log', 'temp' ), 'the same request still has it' );
		$this->assertSame( [], $GLOBALS['bfbt_transients'], 'the store was emptied on first read' );

		BFBT_Batch_Admin::forget_input();

		$this->assertSame( '', BFBT_Batch_Admin::old( 'log', 'temp' ) );
		$this->assertSame( 'x', BFBT_Batch_Admin::old( 'log', 'temp', 'x' ) );
	}

	public function test_one_users_memory_is_not_anothers(): void {
		$GLOBALS['bfbt_user_id'] = 1;
		BFBT_Batch_Admin::remember_input( 'log', [ 'temp' => '200' ] );

		$GLOBALS['bfbt_user_id'] = 2;
		$this->assertSame( '', BFBT_Batch_Admin::old( 'log', 'temp' ) );

		// User 1's copy is still there for user 1.
		BFBT_Batch_Admin::forget_input();
		$GLOBALS['bfbt_user_id'] = 1;
		$this->assertSame( '200', BFBT_Batch_Admin::old( 'log', 'temp' ) );
	}

	public function test_values_are_sanitised_and_keys_normalised(): void {
		BFBT_Batch_Admin::remember_input( 'log', [ 'Ingredient' => ' <b>Fermaid</b> ', 'qty' => [ 'not', 'scalar' ], '' => 'dropped' ] );

		$this->assertSame( 'Fermaid', BFBT_Batch_Admin::old( 'log', 'ingredient' ) );
		$this->assertSame( '', BFBT_Batch_Admin::old( 'log', 'qty' ) );
	}

	public function test_a_store_that_is_not_ours_is_ignored(): void {
		$GLOBALS['bfbt_transients']['bfbt_form_1'] = 'garbage';

		$this->assertSame( 'd', BFBT_Batch_Admin::old( 'log', 'temp', 'd' ) );
	}
}
