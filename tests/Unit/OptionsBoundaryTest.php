<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Boundary discipline for the options surface: theta-only vocabulary (no
 * Edge Bot / Kalshi / perp imports) and no secret material in source.
 */
class OptionsBoundaryTest extends TestCase
{
    private const FORBIDDEN_PATTERNS = ['edge-bot', 'edge_bot', 'edgebot', 'kalshi', 'perp'];

    private const SECRET_PATTERNS = ['HARNESS_OAUTH_TOKEN=ey', 'APCA-API-SECRET-KEY: ', 'access_token='];

    private function optionSurfaceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(
            glob($root.'/app/Robinhood/Options/*.php') ?: [],
            glob($root.'/app/Console/Commands/Option*.php') ?: [],
        );

        $this->assertNotEmpty($paths, 'options surface files must exist');

        return $paths;
    }

    public function test_options_surface_has_no_edge_bot_or_perp_references(): void
    {
        foreach ($this->optionSurfaceFiles() as $path) {
            $contents = (string) file_get_contents($path);
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.preg_quote($pattern, '/').'\b/i',
                    $contents,
                    basename($path)." references '{$pattern}'",
                );
            }
        }
    }

    public function test_options_surface_contains_no_inline_secret_material(): void
    {
        foreach ($this->optionSurfaceFiles() as $path) {
            $contents = (string) file_get_contents($path);
            foreach (self::SECRET_PATTERNS as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path));
            }
        }
    }

    public function test_review_probe_has_no_code_path_to_place(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/OptionReviewProbeCommand.php');

        $this->assertStringNotContainsString('placeOptionOrder', $contents, 'review probe must be structurally unable to place');
        $this->assertStringNotContainsString("'place_option_order'", $contents);
    }
}
