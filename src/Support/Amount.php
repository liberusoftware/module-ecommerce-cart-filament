<?php

namespace Liberu\Ecommerce\Cart\Filament\Support;

use Liberu\Ecommerce\Cart\Values\Money;

/**
 * A stored `*_minor` column, as something an operator can read.
 *
 * Through the domain's own `Money` rather than by dividing here. Every amount in
 * the cart schema is an integer count of a currency's smallest unit, and the
 * exponent is a property of the currency — 2 for GBP, 0 for JPY, 3 for BHD.
 * A column that formatted with a hardcoded `/ 100` would render a yen cart a
 * hundred times too cheap and a dinar cart ten times too dear, which is a
 * refund-sized mistake made by a display helper.
 *
 * `Money::toDecimal()` is string arithmetic on the way out for the same reason
 * `fromDecimal()` is on the way in: handing a float to anything re-introduces one
 * layer later exactly the imprecision integer minor units exist to keep out.
 */
final class Amount
{
    public static function format(int $minor, string $currency): string
    {
        return Money::of($minor, $currency)->toDecimal().' '.$currency;
    }
}
