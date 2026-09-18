<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What RealtyLink AI knows about Philippine real estate credentials when it
 * pre-checks an agent application, and the official sources the admin is
 * pointed to for the real verification.
 *
 * Only claims that hold across the law (RA 9646) and PRC's card design are
 * stated as fact. Card details that vary by print run or that a phone photo
 * routinely hides are framed as "note, not proof" — a pre-check that calls a
 * genuine card counterfeit does more damage than one that says "couldn't
 * verify from the image".
 */
final class AgentCredentialReference
{
    public static function knowledge(): string
    {
        return <<<'TXT'
        REFERENCE — real estate credentials in the Philippines (Republic Act No. 9646, the Real Estate Service Act):
        - Two bodies are involved. The Professional Regulation Commission (PRC) LICENSES real estate BROKERS (board exam; PRC ID renewed with continuing professional development) and ACCREDITS real estate SALESPERSONS (no board exam; at least 72 college units; must practise under a named licensed broker, who is accountable for them). The Department of Human Settlements and Urban Development (DHSUD, formerly HLURB) separately REGISTERS brokers and salespersons who market developer projects such as subdivisions and condominiums; that registration is per calendar year.
        - A genuine PRC Professional Identification Card is a PVC card showing: the holder's photo; full name; the profession in a solid colour band across the lower front (reading "REAL ESTATE BROKER" or "REAL ESTATE SALESPERSON"); a registration or accreditation number; registration date; validity (expiry) date; and signature — printed in uniform, sharp type over a fine guilloche (interlocking wavy-line) background, with a holographic PRC seal overlay that shimmers when tilted. Newer cards carry a QR code and barcode on the back that resolve to the holder's record on prc.gov.ph.
        - A DHSUD Certificate of Registration is a letter-sized paper document with the DHSUD header, stating the person's name, their PRC number, a separate DHSUD registration number, and the calendar year it covers.
        - A salesperson's authority is tied to a specific supervising licensed broker.
        TXT;
    }

    public static function method(): string
    {
        return <<<'TXT'
        HOW TO ASSESS — be specific, and separate what you confirmed from what you could not see:
        1. TRANSCRIBE what is legible on each document: full name, profession band text, registration/accreditation number, registration date, validity date, and (for a salesperson) the supervising broker if shown.
        2. COMPARE against the applicant's DECLARED details given below. A name or number that does not match the card is the most important finding — state it plainly and quote both values.
        3. CHECK the printed validity date against TODAY'S DATE given below. Do not assume how long a card is valid; use the date printed on it. If no date is legible, say so.
        4. PHOTO MATCH: is the live selfie plausibly the same person as the photo on the card or ID? Qualitative only — similar or dissimilar, and why.
        5. QUALITY SIGNALS: sharp uniform type, guilloche background, and a profession band with the expected wording support authenticity. Blurry or uneven type, a flat solid background, a band reading a different profession, or visible editing are concerns.
        A phone photo often hides the hologram, QR code and fine background. The ABSENCE of a feature in a photo is a note, never proof of forgery. Use exactly three labels: CONFIRMED, COULD NOT VERIFY FROM IMAGE, CONCERN.
        You are advisory only; the admin decides and will confirm the number on PRC's verification portal. Never write "approved" or "rejected".

        FORMAT — use these headings, plain text under each, under 220 words total:
        **Details read**
        **Match with application**
        **Photo match**
        **Concerns**
        **Confidence** — one of High / Medium / Low, with a one-line reason.
        TXT;
    }

    /**
     * Official places the admin can check a credential. Shown under every
     * assessment; fixed and verified rather than model-generated, so a link
     * can never be invented.
     *
     * @return list<array{label: string, url: string, note: string}>
     */
    public static function links(string $applicantType): array
    {
        $links = [
            [
                'label' => 'PRC license verification',
                'url'   => 'https://online1.prc.gov.ph/Verification',
                'note'  => 'Official lookup by name and profession. This is the real check.',
            ],
            [
                'label' => 'RA 9646 — Real Estate Service Act',
                'url'   => 'https://lawphil.net/statutes/repacts/ra2009/ra_9646_2009.html',
                'note'  => 'The law that defines brokers, salespersons and their requirements.',
            ],
            [
                'label' => 'DHSUD',
                'url'   => 'https://dhsud.gov.ph',
                'note'  => 'Registration for marketing developer projects (subdivisions, condominiums).',
            ],
        ];

        if ($applicantType !== 'broker') {
            $links[0]['note'] = 'Look up the SUPERVISING BROKER here — salespersons are accredited under a licensed broker.';
        }

        return $links;
    }
}
