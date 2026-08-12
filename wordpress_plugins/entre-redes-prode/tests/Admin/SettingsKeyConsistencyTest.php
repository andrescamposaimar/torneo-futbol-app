<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Admin\SettingsPage;
use EntreRedes\Prode\Admin\SettingsRepository;
use EntreRedes\Prode\Admin\SettingsValidator;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Guards the setting-key lists against drifting apart.
 *
 * An editable integer setting has to appear in three separate places to work:
 * SettingsValidator::INT_FIELDS (accepted and range-checked),
 * SettingsRepository::SETTING_KEYS (read back and written), and
 * SettingsPage::SETTING_LABELS (rendered as a form field). Miss one and the
 * failure is quiet in a way that looks like a save bug: `prode_ranking_from_fecha_id`
 * was added to the validator, the repository, and the labels, but the write loop
 * in SettingsPage::handleSave kept a private copy of the key list. The field
 * validated, entered $clean, was skipped by the writer, and rendered blank on
 * the next page load because no row existed — with no error shown anywhere.
 *
 * These are set comparisons rather than counts, so the failure message names the
 * key that was forgotten.
 */
final class SettingsKeyConsistencyTest extends TestCase {

    /** @return list<string> */
    private function constant( string $class, string $name ): array {
        $value = ( new \ReflectionClass( $class ) )->getConstant( $name );
        $this->assertIsArray( $value, "$class::$name should be an array." );

        // Some lists are key => min/label maps, others plain lists.
        return array_is_list( $value ) ? $value : array_keys( $value );
    }

    public function test_every_validated_int_field_is_persisted(): void {
        $validated = $this->constant( SettingsValidator::class, 'INT_FIELDS' );
        $persisted = SettingsRepository::SETTING_KEYS;

        $missing = array_values( array_diff( $validated, $persisted ) );

        $this->assertSame(
            [],
            $missing,
            'These settings validate but are never written, so saving them '
            . 'silently does nothing: ' . implode( ', ', $missing )
        );
    }

    public function test_every_persisted_setting_is_validated(): void {
        $validated = $this->constant( SettingsValidator::class, 'INT_FIELDS' );
        $persisted = SettingsRepository::SETTING_KEYS;

        $missing = array_values( array_diff( $persisted, $validated ) );

        $this->assertSame(
            [],
            $missing,
            'These settings are written without validation: ' . implode( ', ', $missing )
        );
    }

    public function test_every_persisted_setting_has_a_form_field(): void {
        $persisted = SettingsRepository::SETTING_KEYS;
        $labelled  = $this->constant( SettingsPage::class, 'SETTING_LABELS' );

        $missing = array_values( array_diff( $persisted, $labelled ) );

        $this->assertSame(
            [],
            $missing,
            'These settings cannot be edited because the admin page renders no '
            . 'field for them: ' . implode( ', ', $missing )
        );
    }

    public function test_the_save_loop_uses_the_shared_key_list(): void {
        // The regression itself: handleSave must not reintroduce a local copy of
        // the key list. Asserted against the source because the loop's absence
        // of a duplicate cannot be observed through behaviour alone.
        $source = (string) file_get_contents(
            ( new \ReflectionClass( SettingsPage::class ) )->getFileName()
        );

        $this->assertStringContainsString(
            'SettingsRepository::SETTING_KEYS as $key',
            $source,
            'SettingsPage::handleSave should iterate the shared key list.'
        );
    }

    public function test_new_installs_seed_every_persisted_setting(): void {
        // A missing seed row is survivable (Settings::readInt falls back to a
        // default and upsertSetting creates the row on first save), but leaving
        // it out means the admin field renders blank on a fresh install.
        $seeded = $this->constant( InitialSchema::class, 'SEED_DEFAULTS' );

        if ( [] === $seeded ) {
            $this->markTestSkipped( 'InitialSchema does not expose SEED_DEFAULTS.' );
        }

        $missing = array_values( array_diff( SettingsRepository::SETTING_KEYS, $seeded ) );

        $this->assertSame(
            [],
            $missing,
            'These settings are not seeded on a new install: ' . implode( ', ', $missing )
        );
    }
}
