<?php

namespace Liberu\Ecommerce\Cart\Filament\Support;

use DateTimeInterface;
use Liberu\Ecommerce\Cart\Enums\CartStatus;
use Liberu\Ecommerce\Cart\Models\Cart;

/**
 * Where this cart stands, and whether the numbers next to it can be believed.
 *
 * Three facts, and the domain keeps them apart on purpose. `status` is the
 * lifecycle. `isStale()` is whether the shopper has touched it inside the
 * deployment's window, measured from `last_activity_at` and never from
 * `updated_at`. `totalsAreCurrent()` is whether the stored totals were computed
 * for the revision the cart is now on — recalculation is explicit, so a cart
 * whose line changed a second ago is carrying a subtotal from before it.
 *
 * All three are right to be separate, and all three leave an operator reading a
 * row and working out what it means. This says it — and, crucially, it says when
 * the money on the screen is out of date. The single worst thing this panel could
 * do is show a support agent a stale total as though it were the cart's value,
 * because that number then goes to a shopper in an email.
 *
 * That means this class restates rules the domain also states, so the one thing
 * that matters is that the two never disagree.
 * `tests/Feature/StandingTest.php` asserts `stale` equals `Cart::isStale()` and
 * `totalsAreCurrent` equals `Cart::totalsAreCurrent()` across the whole
 * status × activity × revision matrix, so a release that changes either rule and
 * forgets this file fails a build rather than quietly lying on a screen.
 *
 * Pure PHP over attributes already loaded, so a table of fifty carts costs no
 * queries.
 */
final class CartStanding
{
    private function __construct(
        public readonly CartStatus $status,
        public readonly bool $stale,
        public readonly bool $totalsAreCurrent,
    ) {}

    public static function of(Cart $cart, ?DateTimeInterface $at = null): self
    {
        return new self(
            status: $cart->status,
            // Asked of the model whatever the status is. A merged cart can be
            // stale too, and computing it only for active ones would make this
            // class's answer depend on the order of two questions.
            stale: $cart->isStale($at),
            totalsAreCurrent: $cart->totalsAreCurrent(),
        );
    }

    /** One word, never a colour or an icon on its own. */
    public function label(): string
    {
        if ($this->status !== CartStatus::Active) {
            return match ($this->status) {
                CartStatus::Merged => 'Merged',
                CartStatus::Converted => 'Converted',
                default => 'Abandoned',
            };
        }

        return $this->stale ? 'Stale' : 'Live';
    }

    /** Colour that repeats the label rather than carrying it. */
    public function color(): string
    {
        if ($this->status !== CartStatus::Active) {
            return match ($this->status) {
                CartStatus::Merged => 'gray',
                CartStatus::Converted => 'info',
                default => 'danger',
            };
        }

        return $this->stale ? 'warning' : 'success';
    }

    /** The whole answer, as sentences an operator can act on. */
    public function summary(): string
    {
        return $this->verdict().$this->totalsNote();
    }

    private function verdict(): string
    {
        if ($this->status === CartStatus::Active && ! $this->stale) {
            return 'Live: the shopper can still add to this cart and check out with it.';
        }

        if ($this->status === CartStatus::Active) {
            return 'Active, but untouched for longer than the deployment\'s window, so signing in will not fold it into an account — the prices on it were captured that long ago.';
        }

        return match ($this->status) {
            CartStatus::Merged => 'Folded into an account\'s cart when the shopper signed in. Terminal and read-only, and its lines are kept as the record of what they had before signing in.',
            CartStatus::Converted => 'Handed to checkout. Terminal and read-only — the order is the record now, and these are the numbers somebody was shown when they agreed to pay.',
            default => 'Left alone, or refused a merge. Terminal and read-only, and kept on purpose so somebody can still be emailed about it.',
        };
    }

    /**
     * Said out loud rather than folded into a colour.
     *
     * A total that was true two clicks ago looks exactly like one that is true
     * now, and there is no way for a reader to tell without being told.
     */
    private function totalsNote(): string
    {
        if ($this->totalsAreCurrent) {
            return '';
        }

        return ' Its totals were last computed before the most recent change to a line, so every amount shown here is out of date.';
    }
}
