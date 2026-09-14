<?php

declare(strict_types=1);

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price'       => ['required', 'numeric', 'min:0'],
            'type'        => ['required', 'in:house,condo,lot,commercial,apartment'],
            'offer_type'  => ['nullable', 'in:sale,rent'],
            'bedrooms'    => ['required', 'integer', 'min:0', 'max:50'],
            'bathrooms'   => ['required', 'integer', 'min:0', 'max:50'],
            'floor_area'  => ['nullable', 'numeric', 'min:0'],
            'lot_area'    => ['nullable', 'numeric', 'min:0'],
            'address'     => ['required', 'string', 'max:500'],
            'lat'         => ['nullable', 'numeric', 'between:-90,90'],
            'lng'         => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
