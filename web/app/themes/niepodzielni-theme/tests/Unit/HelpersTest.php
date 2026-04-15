<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for mu-plugins/niepodzielni-core/misc/1-helpers.php
 *
 * Covers: np_get_sortable_date, np_get_flag_map, np_get_post_image_url
 */
class HelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset global mock store after every test.
        $GLOBALS['_np_test_post_meta'] = [];
    }

    // ============================================================
    // np_get_sortable_date()
    // ============================================================


    public function test_sortable_date_returns_fallback_for_empty_string(): void
    {
        $this->assertSame('99999999', np_get_sortable_date(''));
    }


    public function test_sortable_date_returns_custom_fallback_for_empty_string(): void
    {
        $this->assertSame('00000000', np_get_sortable_date('', '00000000'));
    }


    public function test_sortable_date_parses_polish_dd_mm_yyyy_format(): void
    {
        $this->assertSame('20260318', np_get_sortable_date('18.03.2026'));
    }


    public function test_sortable_date_parses_iso_yyyy_mm_dd_format(): void
    {
        $this->assertSame('20260318', np_get_sortable_date('2026-03-18'));
    }


    public function test_sortable_date_handles_start_of_year(): void
    {
        $this->assertSame('20260101', np_get_sortable_date('01.01.2026'));
        $this->assertSame('20260101', np_get_sortable_date('2026-01-01'));
    }


    public function test_sortable_date_handles_end_of_year(): void
    {
        $this->assertSame('20261231', np_get_sortable_date('31.12.2026'));
        $this->assertSame('20261231', np_get_sortable_date('2026-12-31'));
    }


    public function test_sortable_date_returns_fallback_for_unrecognized_format(): void
    {
        $this->assertSame('99999999', np_get_sortable_date('not-a-date'));
        $this->assertSame('99999999', np_get_sortable_date('2026/03/18'));
        $this->assertSame('99999999', np_get_sortable_date('18-03-2026'));
    }


    public function test_sortable_date_both_formats_produce_equal_result_for_same_date(): void
    {
        $this->assertSame(
            np_get_sortable_date('2026-06-15'),
            np_get_sortable_date('15.06.2026'),
        );
    }


    public function test_sortable_date_produces_correctly_sortable_strings(): void
    {
        $dates = ['15.06.2026', '2026-01-01', '31.12.2025'];
        $sorted = $dates;
        usort($sorted, fn($a, $b) => np_get_sortable_date($a) <=> np_get_sortable_date($b));
        $this->assertSame(['31.12.2025', '2026-01-01', '15.06.2026'], $sorted);
    }

    // ============================================================
    // np_get_flag_map()
    // ============================================================


    public function test_flag_map_returns_array(): void
    {
        $this->assertIsArray(np_get_flag_map());
    }


    public function test_flag_map_contains_expected_languages(): void
    {
        $map = np_get_flag_map();
        $this->assertSame('pl', $map['polski']);
        $this->assertSame('gb', $map['angielski']);
        $this->assertSame('ua', $map['ukrainski']);
        $this->assertSame('ua', $map['ukraiński']);   // diacritic variant
        $this->assertSame('de', $map['niemiecki']);
        $this->assertSame('ru', $map['rosyjski']);
        $this->assertSame('fr', $map['francuski']);
        $this->assertSame('es', $map['hiszpanski']);
        $this->assertSame('es', $map['hiszpański']);  // diacritic variant
    }


    public function test_flag_map_all_codes_are_two_characters(): void
    {
        foreach (np_get_flag_map() as $lang => $code) {
            $this->assertSame(
                2,
                strlen($code),
                "Flag code for '{$lang}' must be exactly 2 characters (ISO 3166-1 alpha-2)",
            );
        }
    }


    public function test_flag_map_no_empty_keys_or_values(): void
    {
        foreach (np_get_flag_map() as $lang => $code) {
            $this->assertNotEmpty($lang, 'Language slug must not be empty');
            $this->assertNotEmpty($code, "Flag code for '{$lang}' must not be empty");
        }
    }


    public function test_flag_map_is_idempotent(): void
    {
        $this->assertSame(np_get_flag_map(), np_get_flag_map());
    }

    // ============================================================
    // np_get_post_image_url()
    // ============================================================


    public function test_image_url_returns_first_non_empty_key(): void
    {
        $GLOBALS['_np_test_post_meta'][42] = [
            'zdjecie_glowne' => 'photo_main.jpg',
            'zdjecie'        => 'photo.jpg',
        ];
        $this->assertSame('photo_main.jpg', np_get_post_image_url(42));
    }


    public function test_image_url_falls_back_to_second_key_when_first_is_empty(): void
    {
        $GLOBALS['_np_test_post_meta'][42] = [
            'zdjecie_glowne' => '',
            'zdjecie'        => 'photo.jpg',
        ];
        $this->assertSame('photo.jpg', np_get_post_image_url(42));
    }


    public function test_image_url_returns_empty_string_when_all_keys_are_empty(): void
    {
        $GLOBALS['_np_test_post_meta'][42] = [
            'zdjecie_glowne' => '',
            'zdjecie'        => '',
        ];
        $this->assertSame('', np_get_post_image_url(42));
    }


    public function test_image_url_returns_empty_string_when_post_has_no_meta(): void
    {
        $GLOBALS['_np_test_post_meta'] = [];
        $this->assertSame('', np_get_post_image_url(999));
    }


    public function test_image_url_respects_custom_key_order(): void
    {
        $GLOBALS['_np_test_post_meta'][99] = [
            'zdjecie'     => 'foreground.jpg',
            'zdjecie_tla' => 'background.jpg',
        ];
        // Custom order: zdjecie_tla first
        $this->assertSame('background.jpg', np_get_post_image_url(99, ['zdjecie_tla', 'zdjecie']));
        // Default order: zdjecie first
        $this->assertSame('foreground.jpg', np_get_post_image_url(99, ['zdjecie', 'zdjecie_tla']));
    }


    public function test_image_url_returns_string_type(): void
    {
        $this->assertIsString(np_get_post_image_url(0));
    }
}
