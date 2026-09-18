<?php

declare(strict_types=1);

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // A draft may be saved half-filled — the wizard autosaves as the agent
        // types. A published listing must never lose its title, price or
        // address through an edit, so those stay required once it's live.
        $property = $this->route('property');
        $core     = $property instanceof Property && $property->status === 'published' ? 'required' : 'nullable';

        return [
            'title'       => ['sometimes', $core, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price'       => ['sometimes', $core, 'numeric', 'min:0'],
            'type'        => ['sometimes', $core, 'in:house,condo,lot,commercial,apartment'],
            'offer_type'  => ['sometimes', 'nullable', 'in:sale,rent'],
            'bedrooms'    => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms'   => ['sometimes', 'nullable', 'integer', 'min:0', 'max:50'],
            'floor_area'  => ['nullable', 'numeric', 'min:0'],
            'lot_area'    => ['nullable', 'numeric', 'min:0'],
            'address'     => ['sometimes', $core, 'string', 'max:500'],
            'lat'         => ['nullable', 'numeric', 'between:-90,90'],
            'lng'         => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
