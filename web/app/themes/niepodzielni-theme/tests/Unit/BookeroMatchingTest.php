<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for mu-plugins/niepodzielni-core/api/13-bookero-worker-sync.php
 *
 * Covers: np_normalize_bookero_name, np_find_worker_by_name
 *
 * These functions are the core of the "Pobierz ID z Bookero" feature.
 * Correct name matching is critical — a failed match means the admin
 * cannot auto-fill the Bookero Worker ID for a specialist.
 */
class BookeroMatchingTest extends TestCase
{
    // ============================================================
    // np_normalize_bookero_name()
    // ============================================================


    public function test_normalize_lowercases_name(): void
    {
        $this->assertSame('anna kowalska', np_normalize_bookero_name('Anna Kowalska'));
    }


    public function test_normalize_removes_polish_diacritics(): void
    {
        $this->assertSame('malgorzata niepolomska', np_normalize_bookero_name('Małgorzata Niepołomska'));
    }


    public function test_normalize_removes_all_polish_characters(): void
    {
        // ą ć ę ł ń ó ś ź ż and uppercase variants
        $this->assertSame(
            'acelnoszz acelnoszz',
            np_normalize_bookero_name('ąćęłńóśźż ĄĆĘŁŃÓŚŹŻ')
        );
    }


    public function test_normalize_collapses_multiple_spaces(): void
    {
        $this->assertSame('adam kowalski', np_normalize_bookero_name('Adam  Kowalski'));
        $this->assertSame('jan nowak',     np_normalize_bookero_name('Jan   Nowak'));
    }


    public function test_normalize_trims_leading_and_trailing_whitespace(): void
    {
        $this->assertSame('anna', np_normalize_bookero_name('  Anna  '));
    }


    public function test_normalize_handles_empty_string(): void
    {
        $this->assertSame('', np_normalize_bookero_name(''));
    }


    public function test_normalize_complex_name_with_diacritics_and_spaces(): void
    {
        $this->assertSame('jan zolciak', np_normalize_bookero_name('Jan Żółciak'));
    }

    // ============================================================
    // np_find_worker_by_name()
    // ============================================================

    private array $workers = [
        ['id' => 1, 'name' => 'Anna Kowalska'],
        ['id' => 2, 'name' => 'Jan Nowak'],
        ['id' => 3, 'name' => 'Maria Łącka'],
        ['id' => 4, 'name' => 'Radomski Adam'],
        ['id' => 5, 'name' => 'Małgorzata Wiśniewska'],
    ];


    public function test_find_returns_exact_match(): void
    {
        $result = np_find_worker_by_name('Anna Kowalska', $this->workers);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('exact', $result['match']);
    }


    public function test_find_is_case_insensitive(): void
    {
        $result = np_find_worker_by_name('anna kowalska', $this->workers);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['id']);
    }


    public function test_find_is_diacritic_insensitive(): void
    {
        // 'Maria Lacka' should match 'Maria Łącka'
        $result = np_find_worker_by_name('Maria Lacka', $this->workers);
        $this->assertNotNull($result);
        $this->assertSame(3, $result['id']);
    }


    public function test_find_is_diacritic_insensitive_for_bookero_side_too(): void
    {
        // WP stores 'Malgorzata Wisniewska' (without diacritics), Bookero has 'Małgorzata Wiśniewska'
        $result = np_find_worker_by_name('Malgorzata Wisniewska', $this->workers);
        $this->assertNotNull($result);
        $this->assertSame(5, $result['id']);
    }


    public function test_find_matches_reversed_word_order(): void
    {
        // WP name 'Adam Radomski', Bookero has 'Radomski Adam'
        $result = np_find_worker_by_name('Adam Radomski', $this->workers);
        $this->assertNotNull($result);
        $this->assertSame(4, $result['id']);
        $this->assertSame('words', $result['match']);
    }


    public function test_find_exact_match_takes_priority_over_word_match(): void
    {
        $workers = [
            ['id' => 10, 'name' => 'Nowak Jan'],  // word match
            ['id' => 11, 'name' => 'Jan Nowak'],  // exact match
        ];
        $result = np_find_worker_by_name('Jan Nowak', $workers);
        $this->assertSame(11, $result['id']);
        $this->assertSame('exact', $result['match']);
    }


    public function test_find_returns_null_when_not_found(): void
    {
        $result = np_find_worker_by_name('Nieistniejący Psycholog', $this->workers);
        $this->assertNull($result);
    }


    public function test_find_returns_null_for_empty_workers_list(): void
    {
        $result = np_find_worker_by_name('Anna Kowalska', []);
        $this->assertNull($result);
    }


    public function test_find_returns_null_for_partial_name_match(): void
    {
        // Partial match (only first name) should NOT succeed
        $result = np_find_worker_by_name('Anna', $this->workers);
        $this->assertNull($result);
    }


    public function test_find_result_contains_required_keys(): void
    {
        $result = np_find_worker_by_name('Jan Nowak', $this->workers);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('id',    $result);
        $this->assertArrayHasKey('name',  $result);
        $this->assertArrayHasKey('match', $result);
    }


    public function test_find_result_id_is_integer(): void
    {
        $result = np_find_worker_by_name('Jan Nowak', $this->workers);
        $this->assertIsInt($result['id']);
    }
}
