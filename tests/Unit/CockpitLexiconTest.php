<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cockpit\Lexicon;
use PHPUnit\Framework\TestCase;

/**
 * The Lexicon is the cockpit's no-raw-enums guarantee. These tests lock that
 * guarantee: every documented value decodes to a known, human term, dynamic
 * block reasons fold their detail in, and unknown values still degrade to a
 * readable (never raw, never crashing) term.
 */
class CockpitLexiconTest extends TestCase
{
    public function test_every_catalog_entry_decodes_to_a_known_term(): void
    {
        foreach (Lexicon::catalog() as $category => $map) {
            foreach (array_keys($map) as $value) {
                $term = $this->decode($category, (string) $value);

                $this->assertTrue($term['known'], "{$category}:{$value} should be a known term");
                $this->assertNotSame('', $term['label'], "{$category}:{$value} must have a label");
                $this->assertNotSame('', $term['description'], "{$category}:{$value} must have a description");
                $this->assertContains($term['tone'], [
                    Lexicon::TONE_GOOD, Lexicon::TONE_BAD, Lexicon::TONE_WARN,
                    Lexicon::TONE_ACTIVE, Lexicon::TONE_INFO, Lexicon::TONE_NEUTRAL,
                ], "{$category}:{$value} has an invalid tone");
            }
        }
    }

    public function test_unknown_value_degrades_to_a_humanized_term_not_raw(): void
    {
        $term = Lexicon::status('some_new_broker_state');

        $this->assertFalse($term['known']);
        $this->assertSame('Some new broker state', $term['label']);
        $this->assertStringContainsString('some_new_broker_state', $term['description']);
        $this->assertSame(Lexicon::TONE_INFO, $term['tone']);
    }

    public function test_empty_value_decodes_to_a_neutral_placeholder(): void
    {
        $term = Lexicon::intent(null);

        $this->assertFalse($term['known']);
        $this->assertSame('—', $term['label']);
        $this->assertSame(Lexicon::TONE_NEUTRAL, $term['tone']);
    }

    public function test_kill_switch_boolean_is_decoded_with_correct_semantics(): void
    {
        // true == placement DISABLED — the safety-critical inversion.
        $engaged = Lexicon::killSwitch(true);
        $this->assertSame('Engaged', $engaged['label']);
        $this->assertSame(Lexicon::TONE_BAD, $engaged['tone']);

        $disengaged = Lexicon::killSwitch(false);
        $this->assertSame('Disengaged', $disengaged['label']);
        $this->assertSame(Lexicon::TONE_GOOD, $disengaged['tone']);
    }

    public function test_prefixed_block_reason_folds_its_detail_into_the_description(): void
    {
        $term = Lexicon::blockReason('rail_block: BLOCK_WIDE_SPREAD');

        $this->assertTrue($term['known']);
        $this->assertSame('Risk rail block', $term['label']);
        // The dynamic detail is preserved, humanized, in the description.
        $this->assertStringContainsString('Block wide spread', $term['description']);
    }

    public function test_prefixed_block_reason_decodes_a_known_nested_detail(): void
    {
        $term = Lexicon::blockReason('no_marketable_contract: spread_above_max');

        $this->assertTrue($term['known']);
        $this->assertSame('No marketable contract', $term['label']);
        $this->assertStringContainsString('Spread too wide', $term['description']);
    }

    public function test_classification_vocabulary_matches_broker_truth_reconciler(): void
    {
        foreach ([
            'LIVE_FILLED' => Lexicon::TONE_GOOD,
            'LIVE_POSTED_NOT_FILLED' => Lexicon::TONE_ACTIVE,
            'LIVE_REJECTED' => Lexicon::TONE_BAD,
            'LABEL_CONFLICT_NOT_BROKER_FILLED' => Lexicon::TONE_BAD,
        ] as $value => $tone) {
            $term = Lexicon::classification($value);
            $this->assertTrue($term['known'], "classification {$value} must be known");
            $this->assertSame($tone, $term['tone']);
        }
    }

    /** @return array{value:?string,label:string,description:string,tone:string,known:bool} */
    private function decode(string $category, string $value): array
    {
        return match ($category) {
            'status' => Lexicon::status($value),
            'intent' => Lexicon::intent($value),
            'classification' => Lexicon::classification($value),
            'event' => Lexicon::event($value),
            'block_reason' => Lexicon::blockReason($value),
            'structure_class' => Lexicon::structureClass($value),
            'relative_strength' => Lexicon::relativeStrength($value),
            'gap' => Lexicon::gap($value),
            'geometry' => Lexicon::geometry($value),
            'liquidity' => Lexicon::liquidity($value),
            'side' => Lexicon::side($value),
            'position_effect' => Lexicon::positionEffect($value),
            'option_type' => Lexicon::optionType($value),
            'order_type' => Lexicon::orderType($value),
            'time_in_force' => Lexicon::timeInForce($value),
            'market_hours' => Lexicon::marketHours($value),
            default => $this->fail("unmapped category {$category}"),
        };
    }
}
