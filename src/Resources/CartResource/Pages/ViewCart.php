<?php

namespace Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Support\CartStanding;
use Liberu\Ecommerce\Cart\Models\Cart;

/**
 * One cart, its amounts, and its lines underneath.
 *
 * A `ViewRecord` rather than an `EditRecord`, and the resource registers no edit
 * page at all, so there is no route by which a form over somebody's basket could
 * be reached. `docs/domain.md` says why at length.
 */
class ViewCart extends ViewRecord
{
    protected static string $resource = CartResource::class;

    /**
     * What this cart's standing means, in sentences, above the amounts.
     *
     * Repeated from the list column on purpose: this is the page where the money
     * is, and the sentence that matters most is the one saying the money is out
     * of date.
     */
    public function getSubheading(): ?string
    {
        /** @var Cart $record */
        $record = $this->getRecord();

        return CartStanding::of($record)->summary();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CartResource::recalculateAction(),
            CartResource::abandonAction(),
        ];
    }
}
