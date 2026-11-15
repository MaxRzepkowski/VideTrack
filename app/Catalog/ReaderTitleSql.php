<?php

namespace App\Catalog;

use App\Support\Locales;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * The name on a reader's card as SQL: the walk of MediaItem::titleIn() (their
 * language, the fallback one, the row's own), so the two change together. The
 * JSON paths are quoted by the grammar the query compiles with, which is why
 * this is an expression and not a raw string; the clause around the name
 * stays a literal.
 */
final readonly class ReaderTitleSql implements Expression
{
    /**
     * @param  literal-string  $clause  SQL around the name, which stands in for `%s` (a `sprintf` format: a literal percent sign is `%%`)
     */
    public function __construct(private string $locale, private string $clause = '%s') {}

    public function getValue(Grammar $grammar): string
    {
        return sprintf($this->clause, sprintf(
            'coalesce(%s, %s, title)',
            $grammar->wrap("localized_titles->{$this->locale}->title"),
            $grammar->wrap('localized_titles->'.Locales::fallback().'->title'),
        ));
    }
}
