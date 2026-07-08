<?php

declare(strict_types=1);

namespace BCC\Search\Tests\Unit;

use BCC\Search\Support\QueryQualityGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared query-quality gate.
 *
 * QueryQualityGate::isSearchable() is a pure static function — no DB, no WP.
 * It is the low-entropy / junk filter both the users and groups verticals
 * run before touching the database, so its rejection rules are load-bearing:
 * a regression here either lets spam queries hit the DB (cost) or silently
 * rejects valid searches (broken UX). Each rule gets an explicit case.
 */
#[CoversClass(QueryQualityGate::class)]
final class QueryQualityGateTest extends TestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'empty'                 => [''];
        yield 'single char (len < 2)' => ['a'];
        yield 'blank after trim'      => ['   '];
        yield 'too long (len > 100)'  => [str_repeat('x', 101)];
        yield 'punctuation only'      => ['----'];
        yield 'symbols only'          => ['****'];
        yield 'all same char'         => ['aaaa'];
        yield 'all same digit'        => ['1111'];
        yield 'all same punctuation'  => ['.....'];
        yield 'single stopword'       => ['the'];
        yield 'stopword phrase'       => ['the and of'];
        yield 'stopword mixed case'   => ['The And OF'];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsLowQualityQuery(string $query): void
    {
        self::assertFalse(
            QueryQualityGate::isSearchable($query),
            sprintf('expected %s to be rejected', var_export($query, true))
        );
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'two letters'                 => ['go'];
        yield 'normal word'                 => ['bitcoin'];
        yield 'word among stopwords'        => ['the bitcoin project'];
        yield 'alphanumeric mix'            => ['web3'];
        yield 'exactly 100 chars'           => [str_repeat('a', 99) . 'b'];
        yield 'non-latin (unicode letters)' => ['さくら'];
        yield 'has punctuation but words'   => ['c++ devs'];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsRealQuery(string $query): void
    {
        self::assertTrue(
            QueryQualityGate::isSearchable($query),
            sprintf('expected %s to be accepted', var_export($query, true))
        );
    }

    public function testBoundaryLengthsAreInclusiveTwoToHundred(): void
    {
        // len == 2 accepted (lower inclusive bound), len == 1 rejected.
        self::assertTrue(QueryQualityGate::isSearchable('ab'));
        self::assertFalse(QueryQualityGate::isSearchable('a'));

        // len == 100 accepted, len == 101 rejected (upper inclusive bound).
        // Use a non-uniform string so the all-same-char rule doesn't fire.
        self::assertTrue(QueryQualityGate::isSearchable(str_repeat('ab', 50)));       // 100
        self::assertFalse(QueryQualityGate::isSearchable(str_repeat('ab', 50) . 'c')); // 101
    }
}
