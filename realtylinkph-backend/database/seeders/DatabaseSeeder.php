<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentBlockedDate;
use App\Models\AgentProfile;
use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo dataset for RealtyLink PH.
 *
 * Designed for a clean database:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan properties:score-featured
 *
 * Users are created with updateOrCreate so re-running is safe, but domain
 * records are skipped when listings already exist — re-seeding on top of live
 * data would duplicate everything.
 *
 * Every account uses the password: password123
 *
 * Listing photos come from database/seeders/assets and are clearly watermarked
 * placeholders. Swap in real photography before any public demo.
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    /** Presence of this account means the demo dataset has already been seeded. */
    private const MARKER_EMAIL = 'admin@realtylinkph.test';

    /** Where the seed images live, relative to this file. */
    private string $assets;

    public function run(): void
    {
        $this->assets = __DIR__ . '/assets';

        // Additive by design: this runs safely alongside hand-made accounts and
        // listings. The guard keys off the demo admin rather than "any listings",
        // so re-running is a no-op instead of duplicating the whole dataset.
        if (User::where('email', self::MARKER_EMAIL)->exists()) {
            $this->command->warn('Demo data is already present — nothing to do.');
            $this->command->line('  To reseed from scratch: php artisan migrate:fresh --seed');
            $this->command->line('  (that DROPS every table, including your own accounts)');

            return;
        }

        $this->command->info('Seeding RealtyLink PH demo data…');

        $admin   = $this->seedAdmin();
        $agents  = $this->seedAgents();
        $this->seedPendingApplications();
        $buyers  = $this->seedBuyers();

        $properties = $this->seedProperties($agents);
        $this->seedBlockedDates($agents);
        $appointments = $this->seedAppointments($properties, $buyers);
        $this->seedReviews($appointments);
        $this->seedConversations($properties, $buyers);
        $this->seedInquiries($properties, $buyers);

        $this->summary($admin, $agents, $buyers, $properties);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Users
    // ─────────────────────────────────────────────────────────────────────────

    private function seedAdmin(): User
    {
        $admin = User::updateOrCreate(
            ['email' => self::MARKER_EMAIL],
            [
                'name'              => 'Site Administrator',
                'password'          => self::PASSWORD,
                'phone'             => '+63 917 000 0001',
                'role_type'         => 'admin',
                'email_verified_at' => now(),
            ],
        );

        $this->command->line('  admin ······················ 1');

        return $admin;
    }

    /** @return array<int, User> */
    private function seedAgents(): array
    {
        $definitions = [
            ['Marisol Andrada',   'marisol.andrada@realtylinkph.test',  'broker',      'PRC-0012845', '+63 917 111 0001'],
            ['Rafael Bautista',   'rafael.bautista@realtylinkph.test',  'broker',      'PRC-0013392', '+63 917 111 0002'],
            ['Celine Dimaculangan', 'celine.dima@realtylinkph.test',    'salesperson', 'ACC-0044120', '+63 917 111 0003'],
            ['Joaquin Reyes',     'joaquin.reyes@realtylinkph.test',    'broker',      'PRC-0014507', '+63 917 111 0004'],
            ['Trisha Villamor',   'trisha.villamor@realtylinkph.test',  'salesperson', 'ACC-0045883', '+63 917 111 0005'],
            ['Emmanuel Sarmiento', 'emman.sarmiento@realtylinkph.test', 'broker',      'PRC-0015664', '+63 917 111 0006'],
        ];

        $agents = [];

        foreach ($definitions as $i => [$name, $email, $type, $prc, $phone]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $name,
                    'password'          => self::PASSWORD,
                    'phone'             => $phone,
                    'role_type'         => 'agent',
                    'email_verified_at' => now(),
                    'property_alerts'   => false,
                ],
            );

            AgentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'applicant_type'     => $type,
                    'prc_number'         => $prc,
                    'status'             => 'approved',
                    'reviewed_at'        => now()->subDays(40 - $i * 3),
                    'admin_note'         => null,
                    'supervising_broker' => $type === 'salesperson' ? 'Marisol Andrada' : null,
                ],
            );

            $agents[] = $user;
        }

        $this->command->line('  approved agents ············ ' . count($agents));

        return $agents;
    }

    /**
     * Two applications waiting on the admin, plus one recently rejected so the
     * re-apply cooldown state is visible in the UI.
     */
    private function seedPendingApplications(): void
    {
        $pending = [
            ['Bianca Ocampo',  'bianca.ocampo@realtylinkph.test',  'salesperson', 'ACC-0051230'],
            ['Diego Fontanilla', 'diego.font@realtylinkph.test',   'broker',      'PRC-0016901'],
        ];

        foreach ($pending as [$name, $email, $type, $prc]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $name,
                    'password'          => self::PASSWORD,
                    'role_type'         => 'buyer',   // stays a buyer until approved
                    'email_verified_at' => now(),
                ],
            );

            AgentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'applicant_type' => $type,
                    'prc_number'     => $prc,
                    'status'         => 'pending',
                    'ai_comment'     => 'Document text is legible and the name on the ID appears consistent with the '
                        . 'accreditation. The live selfie is clear and appears to show the same person. No obvious signs '
                        . 'of tampering. Advisory only — please confirm the licence number against the PRC registry.',
                    'ai_assessed_at' => now()->subHours(3),
                ],
            );
        }

        $rejected = User::updateOrCreate(
            ['email' => 'noel.aguirre@realtylinkph.test'],
            [
                'name'              => 'Noel Aguirre',
                'password'          => self::PASSWORD,
                'role_type'         => 'buyer',
                'email_verified_at' => now(),
            ],
        );

        AgentProfile::updateOrCreate(
            ['user_id' => $rejected->id],
            [
                'applicant_type' => 'salesperson',
                'prc_number'     => 'ACC-0052999',
                'status'         => 'rejected',
                'admin_note'     => 'The accreditation document is too blurred to read the expiry date. '
                    . 'Please re-upload a sharper photo of the front of the document.',
                'reviewed_at'    => now()->subHours(4),   // inside the 12h cooldown
            ],
        );

        $this->command->line('  pending applications ······· 2  (+1 rejected, in cooldown)');
    }

    /** @return array<int, User> */
    private function seedBuyers(): array
    {
        $names = [
            ['Maria Santos',     'maria.santos@example.test'],
            ['John Reyes',       'john.reyes@example.test'],
            ['Ana Villanueva',   'ana.villanueva@example.test'],
            ['Carlo Mendoza',    'carlo.mendoza@example.test'],
            ['Patricia Lim',     'patricia.lim@example.test'],
            ['Miguel Torres',    'miguel.torres@example.test'],
            ['Katrina Dela Cruz', 'katrina.delacruz@example.test'],
            ['Ferdinand Yap',    'ferdinand.yap@example.test'],
        ];

        $buyers = [];

        foreach ($names as $i => [$name, $email]) {
            $buyers[] = User::updateOrCreate(
                ['email' => $email],
                [
                    'name'              => $name,
                    'password'          => self::PASSWORD,
                    'phone'             => '+63 918 222 ' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'role_type'         => 'buyer',
                    'email_verified_at' => $i < 6 ? now() : null,   // a couple left unverified
                    'property_alerts'   => $i % 2 === 0,
                ],
            );
        }

        $this->command->line('  buyers ····················· ' . count($buyers));

        return $buyers;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Listings
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, User>  $agents
     * @return array<int, Property>
     */
    private function seedProperties(array $agents): array
    {
        // [title, type, offer, price, beds, baths, floor, lot, address, lat, lng, images[], status, ageDays, views]
        $rows = [
            ['Sunlit 2BR Corner Unit at Cebu Business Park', 'condo', 'sale', 8_950_000, 2, 2, 78, null,
             'Cebu Business Park, Cebu City, Cebu', 10.3157, 123.8854, ['condo-01', 'interior-01'], 'published', 3, 412],

            ['Modern Studio Near Ayala Center Cebu', 'condo', 'rent', 24_000, 1, 1, 32, null,
             'Archbishop Reyes Ave, Cebu City, Cebu', 10.3181, 123.9059, ['condo-02'], 'published', 6, 268],

            ['Four-Bedroom Family Home in Banilad', 'house', 'sale', 14_500_000, 4, 3, 240, 320,
             'Banilad, Cebu City, Cebu', 10.3390, 123.9110, ['house-01', 'interior-01'], 'published', 9, 501],

            ['Contemporary House and Lot in Talamban', 'house', 'sale', 11_200_000, 3, 3, 185, 260,
             'Talamban, Cebu City, Cebu', 10.3720, 123.9130, ['house-02'], 'published', 14, 190],

            ['Premium 3BR Residence in BGC', 'condo', 'sale', 21_800_000, 3, 2, 118, null,
             '11th Avenue, Bonifacio Global City, Taguig', 14.5510, 121.0503, ['condo-01'], 'published', 2, 640],

            ['Executive 1BR for Rent in Makati CBD', 'condo', 'rent', 45_000, 1, 1, 52, null,
             'Salcedo Village, Makati City, Metro Manila', 14.5547, 121.0244, ['condo-02', 'interior-01'], 'published', 5, 355],

            ['Spacious Townhouse in Quezon City', 'house', 'sale', 9_750_000, 3, 2, 160, 140,
             'Diliman, Quezon City, Metro Manila', 14.6760, 121.0437, ['house-03'], 'published', 11, 224],

            ['Affordable Apartment Unit in Mandaue', 'apartment', 'rent', 12_500, 2, 1, 45, null,
             'A.S. Fortuna St, Mandaue City, Cebu', 10.3236, 123.9223, ['apt-01'], 'published', 8, 143],

            ['Newly Built Apartment Near Lapu-Lapu City Hall', 'apartment', 'rent', 15_800, 2, 1, 50, null,
             'Pajo, Lapu-Lapu City, Cebu', 10.3103, 123.9494, ['apt-02'], 'published', 17, 97],

            ['Prime Commercial Space Along Colon Street', 'commercial', 'rent', 85_000, 0, 2, 210, null,
             'Colon St, Cebu City, Cebu', 10.2954, 123.9008, ['comm-01'], 'published', 4, 176],

            ['Ground Floor Retail Unit in Iloilo City Proper', 'commercial', 'rent', 62_000, 0, 1, 145, null,
             'Iznart St, Iloilo City, Iloilo', 10.7202, 122.5621, ['comm-02'], 'published', 13, 88],

            ['Titled Residential Lot in Consolacion', 'lot', 'sale', 3_400_000, 0, 0, null, 280,
             'Consolacion, Cebu', 10.3760, 123.9580, ['lot-01'], 'published', 21, 132],

            ['Corner Lot with Mountain View in Baguio', 'lot', 'sale', 5_900_000, 0, 0, null, 350,
             'Camp 7, Baguio City, Benguet', 16.4023, 120.5960, ['lot-02'], 'published', 26, 118],

            ['Cozy Two-Bedroom Home in Davao City', 'house', 'sale', 6_800_000, 2, 2, 110, 180,
             'Matina, Davao City, Davao del Sur', 7.1907, 125.4553, ['house-02'], 'published', 19, 165],

            ['Furnished Condo Near Ateneo de Davao', 'condo', 'rent', 21_000, 1, 1, 38, null,
             'Jacinto St, Davao City, Davao del Sur', 7.0722, 125.6131, ['condo-02'], 'published', 7, 209],

            ['Family House in Antipolo with Garden', 'house', 'sale', 8_200_000, 3, 2, 150, 240,
             'Antipolo City, Rizal', 14.5878, 121.1759, ['house-01'], 'published', 12, 148],

            ['Two-Bedroom Apartment in Bacolod', 'apartment', 'rent', 14_000, 2, 1, 48, null,
             'Mandalagan, Bacolod City, Negros Occidental', 10.6407, 122.9689, ['apt-01'], 'published', 23, 71],

            ['Modern Loft in Cagayan de Oro', 'condo', 'rent', 19_500, 1, 1, 42, null,
             'Divisoria, Cagayan de Oro, Misamis Oriental', 8.4542, 124.6319, ['condo-01'], 'published', 16, 104],

            ['Commercial Building Along Mandaue Highway', 'commercial', 'sale', 32_000_000, 0, 4, 620, 480,
             'Highway, Mandaue City, Cebu', 10.3300, 123.9400, ['comm-01', 'comm-02'], 'published', 30, 93],

            ['Agricultural Lot in Iloilo Outskirts', 'lot', 'sale', 2_100_000, 0, 0, null, 1200,
             'Sta. Barbara, Iloilo', 10.8250, 122.5340, ['lot-01'], 'published', 35, 54],

            // Drafts — agent-only, exercises the draft/publish flow
            ['Hillside Home in Busay (Draft)', 'house', 'sale', 17_500_000, 4, 4, 280, 420,
             'Busay, Cebu City, Cebu', 10.3600, 123.8900, ['house-03'], 'draft', 1, 0],

            ['Studio Unit for Rent (Draft)', 'condo', 'rent', 17_000, 1, 1, 30, null,
             'Banawa, Cebu City, Cebu', 10.3060, 123.8830, ['condo-02'], 'draft', 2, 0],

            // Sold — populates the agent Inventory view
            ['Three-Bedroom Home in Talisay', 'house', 'sale', 7_400_000, 3, 2, 145, 200,
             'Talisay City, Cebu', 10.2447, 123.8494, ['house-02'], 'sold', 55, 287],

            ['Condo Unit in Ortigas Center', 'condo', 'sale', 9_100_000, 2, 1, 62, null,
             'Ortigas Center, Pasig City, Metro Manila', 14.5866, 121.0614, ['condo-01'], 'sold', 62, 341],
        ];

        $properties = [];

        foreach ($rows as $i => $row) {
            [$title, $type, $offer, $price, $beds, $baths, $floor, $lot,
             $address, $lat, $lng, $images, $status, $ageDays, $views] = $row;

            $agent = $agents[$i % count($agents)];

            $property = Property::create([
                'agent_id'    => $agent->id,
                'title'       => $title,
                'description' => $this->description($type, $offer, $address, $beds, $floor),
                'price'       => $price,
                'type'        => $type,
                'offer_type'  => $offer,
                'bedrooms'    => $beds,
                'bathrooms'   => $baths,
                'floor_area'  => $floor,
                'lot_area'    => $lot,
                'address'     => $address,
                'lat'         => $lat,
                'lng'         => $lng,
                'status'      => $status,
                'views'       => $views,
                'sold_at'     => $status === 'sold' ? now()->subDays((int) ($ageDays / 3)) : null,
            ]);

            // Backdate so the freshness factor in the featured score varies.
            $property->forceFill([
                'created_at' => now()->subDays($ageDays),
                'updated_at' => now()->subDays(max(0, $ageDays - 1)),
            ])->saveQuietly();

            $this->attachPhotos($property, $images, withPanorama: $i < 3);

            $properties[] = $property;
        }

        $this->command->line('  listings ··················· ' . count($properties)
            . '  (' . collect($properties)->where('status', 'published')->count() . ' published)');

        return $properties;
    }

    private function description(string $type, string $offer, string $address, int $beds, ?int $floor): string
    {
        $where = trim(explode(',', $address)[1] ?? $address);
        $verb  = $offer === 'rent' ? 'available for lease' : 'now on the market';

        return match ($type) {
            'condo' => "A well-maintained condominium unit in {$where}, {$verb}. "
                . ($floor ? "The {$floor} sqm layout makes efficient use of space" : 'Efficiently laid out')
                . ($beds ? " with {$beds} bedroom" . ($beds > 1 ? 's' : '') : '')
                . ', large windows, and access to building amenities. Walking distance to dining, transport, and retail.',

            'house' => "A comfortable family home in {$where}, {$verb}. "
                . ($beds ? "It offers {$beds} bedroom" . ($beds > 1 ? 's' : '') . ', ' : '')
                . 'generous living areas, and secure off-street parking. Set on a quiet street within an established '
                . 'residential neighbourhood, close to schools and daily conveniences.',

            'apartment' => "A practical apartment unit in {$where}, {$verb}. Ideal for small households or "
                . 'professionals, with reliable water and power, on-site caretaker, and easy access to public transport.',

            'commercial' => "A commercial space in {$where}, {$verb}. High visibility along a busy frontage with "
                . 'strong foot traffic, flexible interior layout, and provisions for signage. Suited to retail, '
                . 'food service, or office use.',

            'lot' => "A clean-titled lot in {$where}, {$verb}. Regular shape with road frontage and utilities "
                . 'available at the boundary. A straightforward site for a custom build or a longer-term hold.',

            default => "A property in {$where}, {$verb}.",
        };
    }

    /** @param  array<int, string>  $images */
    private function attachPhotos(Property $property, array $images, bool $withPanorama = false): void
    {
        $order = 0;

        foreach ($images as $key) {
            $source = "{$this->assets}/{$key}.jpg";
            if (! is_file($source)) {
                continue;
            }

            $path = "properties/{$property->id}/{$key}.jpg";
            Storage::disk('public')->put($path, (string) file_get_contents($source));

            PropertyPhoto::create([
                'property_id' => $property->id,
                'url'         => $path,
                'is_360'      => false,
                'sort_order'  => ++$order,
            ]);
        }

        // A few listings get a 360° tour so the badge and viewer are demonstrable.
        $pano = "{$this->assets}/pano-360-01.jpg";
        if ($withPanorama && is_file($pano)) {
            $path = "properties/{$property->id}/pano-360-01.jpg";
            Storage::disk('public')->put($path, (string) file_get_contents($pano));

            PropertyPhoto::create([
                'property_id' => $property->id,
                'url'         => $path,
                'is_360'      => true,
                'sort_order'  => ++$order,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scheduling, messaging, feedback
    // ─────────────────────────────────────────────────────────────────────────

    /** @param  array<int, User>  $agents */
    private function seedBlockedDates(array $agents): void
    {
        foreach ($agents as $i => $agent) {
            // Every agent takes Sundays off.
            AgentBlockedDate::create([
                'agent_id'              => $agent->id,
                'blocked_date'          => null,
                'is_recurring'          => true,
                'recurring_day_of_week' => 0,
                'reason'                => 'Rest day',
            ]);

            // One agent blocks a morning; another takes a full day off.
            if ($i === 0) {
                AgentBlockedDate::create([
                    'agent_id'     => $agent->id,
                    'blocked_date' => now()->addDays(3)->toDateString(),
                    'start_time'   => '08:00',
                    'end_time'     => '12:00',
                    'is_recurring' => false,
                    'reason'       => 'Client turnover',
                ]);
            }

            if ($i === 1) {
                AgentBlockedDate::create([
                    'agent_id'     => $agent->id,
                    'blocked_date' => now()->addDays(5)->toDateString(),
                    'is_recurring' => false,
                    'reason'       => 'Out of town',
                ]);
            }
        }

        $this->command->line('  blocked dates ·············· ' . AgentBlockedDate::count());
    }

    /**
     * @param  array<int, Property>  $properties
     * @param  array<int, User>  $buyers
     * @return array<int, Appointment>
     */
    private function seedAppointments(array $properties, array $buyers): array
    {
        $published = collect($properties)->where('status', 'published')->values();
        $sold      = collect($properties)->where('status', 'sold')->values();

        // [propertyIndex, buyerIndex, status, dayOffset, hour]
        $plan = [
            [0, 0, 'pending',   2,  10],
            [1, 1, 'pending',   3,  14],
            [2, 2, 'confirmed', 4,   9],
            [4, 3, 'confirmed', 6,  15],
            [5, 4, 'confirmed', 8,  11],
            [3, 0, 'cancelled', 1,  13],
            [6, 5, 'cancelled', 2,  16],
        ];

        $appointments = [];

        foreach ($plan as [$pi, $bi, $status, $offset, $hour]) {
            $property = $published[$pi] ?? null;
            if (! $property) {
                continue;
            }

            $appointments[] = Appointment::create([
                'property_id'        => $property->id,
                'buyer_id'           => $buyers[$bi]->id,
                'agent_id'           => $property->agent_id,
                'preferred_datetime' => now()->addDays($offset)->setTime($hour, 0),
                'status'             => $status,
                'notes'              => $status === 'pending'
                    ? 'Weekday afternoons work best for me, but I can be flexible.'
                    : null,
            ]);
        }

        // Past, completed viewings — these are what unlock reviews.
        $completedPlan = [
            [$published[2] ?? null, $buyers[1], 12, 10],
            [$published[5] ?? null, $buyers[2], 18,  9],
            [$published[7] ?? null, $buyers[3], 24, 14],
            [$sold[0]      ?? null, $buyers[4], 31, 11],
            [$sold[1]      ?? null, $buyers[5], 40, 15],
        ];

        foreach ($completedPlan as [$property, $buyer, $daysAgo, $hour]) {
            if (! $property) {
                continue;
            }

            $appointments[] = Appointment::create([
                'property_id'        => $property->id,
                'buyer_id'           => $buyer->id,
                'agent_id'           => $property->agent_id,
                'preferred_datetime' => now()->subDays($daysAgo)->setTime($hour, 0),
                'status'             => 'completed',
            ]);
        }

        $this->command->line('  appointments ··············· ' . count($appointments));

        return $appointments;
    }

    /** @param  array<int, Appointment>  $appointments */
    private function seedReviews(array $appointments): void
    {
        $texts = [
            [5, '👍 On time · Knowledgeable' . "\n" . 'Very responsive and knew the building inside out. Answered every question about association dues without hesitation.'],
            [5, '👍 Responsive · Friendly' . "\n" . 'Made the whole viewing painless. Sent the floor plan and title details the same afternoon.'],
            [4, '👍 Honest' . "\n" . 'Straightforward about the unit\'s condition, including the things that needed work. I appreciated not being oversold.'],
            [5, 'Handled the paperwork end to end and kept me updated at every step. Would work with them again.'],
            [4, 'Good communication throughout. The viewing started a few minutes late but everything else was smooth.'],
        ];

        $completed = array_values(array_filter(
            $appointments,
            static fn (Appointment $a): bool => $a->status === 'completed',
        ));

        $created = 0;

        foreach ($completed as $i => $appointment) {
            if (! isset($texts[$i])) {
                break;
            }

            [$rating, $body] = $texts[$i];

            AgentReview::create([
                'agent_id'       => $appointment->agent_id,
                'buyer_id'       => $appointment->buyer_id,
                'appointment_id' => $appointment->id,
                'rating'         => $rating,
                'review_text'    => $body,
                'is_visible'     => true,
            ]);

            $created++;
        }

        $this->command->line('  reviews ···················· ' . $created);
    }

    /**
     * @param  array<int, Property>  $properties
     * @param  array<int, User>  $buyers
     */
    private function seedConversations(array $properties, array $buyers): void
    {
        $published = collect($properties)->where('status', 'published')->values();

        $threads = [
            [0, 0, [
                ['buyer', 'Hi! Is this unit still available?'],
                ['agent', 'Hello Maria — yes, it is. Would you like to see it this week?'],
                ['buyer', 'That would be great. Is Thursday morning possible?'],
                ['agent', 'Thursday at 10am works. I\'ll send the details shortly.'],
            ]],
            [2, 1, [
                ['buyer', 'Good afternoon. Could you share the total floor area and whether parking is included?'],
                ['agent', 'Good afternoon! It\'s 240 sqm with two dedicated parking slots included in the price.'],
                ['buyer', 'Perfect, thank you. I\'ll discuss with my wife and get back to you.'],
            ]],
            [5, 3, [
                ['buyer', 'Is the rent negotiable for a 2-year lease?'],
                ['agent', 'There is some room for a longer term — let me confirm with the owner and come back to you today.'],
            ]],
            [9, 4, [
                ['buyer', 'Hello, what are the terms for the commercial space? Is there an advance and deposit?'],
            ]],
        ];

        $messageCount = 0;

        foreach ($threads as [$pi, $bi, $script]) {
            $property = $published[$pi] ?? null;
            if (! $property) {
                continue;
            }

            $buyer = $buyers[$bi];

            $conversation = Conversation::create([
                'property_id'     => $property->id,
                'buyer_id'        => $buyer->id,
                'agent_id'        => $property->agent_id,
                'last_message_at' => now()->subHours(count($script)),
            ]);

            foreach ($script as $n => [$who, $body]) {
                $isBuyer = $who === 'buyer';
                $sentAt  = now()->subHours(count($script) - $n);

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id'       => $isBuyer ? $buyer->id : $property->agent_id,
                    'body'            => $body,
                    // Leave the final inbound message unread so badges are visible.
                    'is_read'         => $n < count($script) - 1,
                    'read_at'         => $n < count($script) - 1 ? $sentAt->copy()->addMinutes(4) : null,
                ]);

                $message->forceFill(['created_at' => $sentAt, 'updated_at' => $sentAt])->saveQuietly();
                $messageCount++;
            }

            $conversation->update(['last_message_at' => now()->subHours(1)]);
        }

        $this->command->line('  conversations ·············· ' . count($threads) . " ({$messageCount} messages)");
    }

    /**
     * @param  array<int, Property>  $properties
     * @param  array<int, User>  $buyers
     */
    private function seedInquiries(array $properties, array $buyers): void
    {
        $published = collect($properties)->where('status', 'published')->values();

        // Guest enquiries carry a null user_id — that is what makes them "ghost buyers".
        $rows = [
            [1,  null, 'Rowena Cabrera', 'rowena.cabrera@example.test', '+63 919 555 0101',
             'Hi! Is this property still available? I\'d like to know more.', false],
            [3,  null, 'Dennis Ilagan',  'dennis.ilagan@example.test',  '+63 919 555 0102',
             'Could you share the financing / payment options for this property?', false],
            [4,  0,    null, null, null,
             'Hi! I\'d like to schedule a viewing. What times are available this weekend?', false],
            [8,  null, 'Grace Punzalan', 'grace.punzalan@example.test', null,
             'Is the unit furnished, and are utilities included in the monthly rate?', true],
            [10, 2,    null, null, null,
             'Good day. Is the space suitable for a small cafe? Interested in a long-term lease.', true],
            [12, null, 'Arnel Vergara',  'arnel.vergara@example.test',  '+63 919 555 0103',
             'Is the title clean and transfer-ready? Please advise on total closing costs.', false],
        ];

        $count = 0;

        foreach ($rows as [$pi, $bi, $name, $email, $phone, $message, $read]) {
            $property = $published[$pi] ?? null;
            if (! $property) {
                continue;
            }

            $buyer = $bi !== null ? $buyers[$bi] : null;

            Inquiry::create([
                'property_id' => $property->id,
                'user_id'     => $buyer?->id,
                'name'        => $buyer?->name  ?? $name,
                'email'       => $buyer?->email ?? $email,
                'phone'       => $buyer?->phone ?? $phone,
                'message'     => $message,
                'is_read'     => $read,
            ]);

            $count++;
        }

        $this->command->line('  inquiries ·················· ' . $count . '  (guest + registered)');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, User>  $agents
     * @param  array<int, User>  $buyers
     * @param  array<int, Property>  $properties
     */
    private function summary(User $admin, array $agents, array $buyers, array $properties): void
    {
        $this->command->newLine();
        $this->command->info('Demo data ready.');
        $this->command->newLine();
        $this->command->line('  Sign in with any of these — password: <fg=yellow>' . self::PASSWORD . '</>');
        $this->command->line('    admin   ' . $admin->email);
        $this->command->line('    agent   ' . $agents[0]->email);
        $this->command->line('    buyer   ' . $buyers[0]->email);
        $this->command->newLine();
        $this->command->line('  <fg=yellow>Next:</> php artisan properties:score-featured');
        $this->command->line('  (the homepage Featured row stays empty until this runs)');
        $this->command->newLine();
        $this->command->line('  Listing photos are watermarked placeholders from');
        $this->command->line('  database/seeders/assets — swap in real photography before a public demo.');
        $this->command->newLine();
    }
}
