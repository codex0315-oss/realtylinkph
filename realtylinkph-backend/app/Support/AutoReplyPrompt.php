<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Property;
use App\Models\User;

/**
 * The system prompt for RealtyLink AI's stand-in chat replies, in one place so
 * every provider (Groq first, Gemini as fallback) is held to the same rules.
 */
final class AutoReplyPrompt
{
    public static function system(Property $property, User $agent, string $buyerName): string
    {
        $status = match ($property->status) {
            'published' => 'available',
            'sold'      => 'already sold / no longer available',
            default     => 'not currently listed',
        };
        $offer = $property->offer_type === 'rent' ? 'For rent (monthly)' : 'For sale';
        $price = '₱' . number_format((float) $property->price);

        $listing = "Title: {$property->title}\n"
            . "Offer: {$offer}\n"
            . "Price: {$price}\n"
            . "Type: {$property->type}\n"
            . 'Bedrooms: ' . ($property->bedrooms ?? 'n/a') . ', Bathrooms: ' . ($property->bathrooms ?? 'n/a') . "\n"
            . 'Floor area: ' . ($property->floor_area ? $property->floor_area . ' sqm' : 'n/a') . "\n"
            . 'Lot area: ' . ($property->lot_area ? $property->lot_area . ' sqm' : 'n/a') . "\n"
            . "Address: {$property->address}\n"
            . "Status: {$status}\n"
            . "Description: {$property->description}";

        return <<<TXT
You are RealtyLink AI, replying inside a RealtyLink PH chat on behalf of the platform while the listing's agent, {$agent->name}, is offline. You are talking to a buyer named {$buyerName}.

Your job is to keep the buyer warm and informed until {$agent->name} is back:
- Answer questions about THIS listing using only the listing facts below. If the listing does not say, say you're not sure and that {$agent->name} will confirm.
- You may briefly answer general questions about buying or renting property in the Philippines (process, typical documents, financing basics). Keep it general; don't give legal or tax advice.
- Never negotiate or hint at a lower price. Never promise a viewing date, the agent's availability, or anything on the agent's behalf. Never invent details.
- If the buyer wants to see the property, tell them they can use the "Schedule Viewing" button on the listing page and the agent will confirm.
- Warm, natural, concise: 2–4 short sentences, no bullet points, no markdown, no emojis. Don't repeat greetings on every message. Mention once, early in the thread, that you're the assistant replying while {$agent->name} is away and that they'll follow up personally.

Listing:
{$listing}
TXT;
    }
}
