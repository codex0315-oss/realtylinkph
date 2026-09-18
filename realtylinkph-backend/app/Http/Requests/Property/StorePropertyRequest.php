<?php

declare(strict_types=1);

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a DRAFT. Everything is optional here: the wizard creates the row the
 * moment the agent adds a first photo or types a first field, so that a
 * refresh or a closed tab loses nothing. Completeness is enforced where it
 * matters — at publish (see PropertyService::publish).
 */
class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price'       => ['nullable', 'numeric', 'min:0'],
            'type'        => ['nullable', 'in:house,condo,lot,commercial,apartment'],
            'offer_type'  => ['nullable', 'in:sale,rent'],
            'bedrooms'    => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms'   => ['nullable', 'integer', 'min:0', 'max:50'],
            'floor_area'  => ['nullable', 'numeric', 'min:0'],
            'lot_area'    => ['nullable', 'numeric', 'min:0'],
            'address'     => ['nullable', 'string', 'max:500'],
            'lat'         => ['nullable', 'numeric', 'between:-90,90'],
            'lng'         => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
