<?php
declare(strict_types=1);

// Rule-based labels only. This never extracts case counts, never marks a report
// Official and never sets an alert. A person does those in the admin page.

const BHW_MUNICIPALITIES = [
    'Alburquerque', 'Alicia', 'Anda', 'Antequera', 'Baclayon', 'Balilihan', 'Batuan', 'Bien Unido', 'Bilar',
    'Buenavista', 'Calape', 'Candijay', 'Carmen', 'Catigbian', 'Clarin', 'Corella', 'Cortes', 'Dagohoy', 'Danao',
    'Dauis', 'Dimiao', 'Duero', 'Garcia Hernandez', 'Getafe', 'Guindulman', 'Inabanga', 'Jagna', 'Lila', 'Loay',
    'Loboc', 'Loon', 'Mabini', 'Maribojoc', 'Panglao', 'Pilar', 'Pres. Carlos P. Garcia', 'Sagbayan', 'San Isidro',
    'San Miguel', 'Sevilla', 'Sierra Bullones', 'Sikatuna', 'Tagbilaran City', 'Talibon', 'Trinidad', 'Tubigon',
    'Ubay', 'Valencia',
];

// First match wins, so specific diseases come before general services.
const BHW_CATEGORIES = [
    'dengue' => '/\b(dengue|aedes)\b/i',
    'leptospirosis' => '/\b(leptospirosis|lepto)\b/i',
    'hiv' => '/\b(HIV|AIDS)\b/',
    'bites' => '/\b(snake ?bites?|rabies|dog bites?|animal bites?)\b/i',
    'vaccination' => '/\b(vaccin\w*|immuni[sz]ation|bakuna|measles|rubella|tigdas|polio)\b/i',
    'respiratory' => '/\b(influenza|flu|flu-like|covid(-19)?|pneumonia|tuberculosis|pertussis|whooping cough|respiratory)\b/i',
    'air' => '/\b(haze|air quality|heat ?stroke|heat index)\b/i',
    'products' => '/\b(fda (advisory|warns?|warning)|mercury|skin (lighten|whiten)\w*)\b/i',
    'other' => '/\b(food poisoning|diarrh?ea|cholera|typhoid|hand,? foot,? and mouth|hfmd|malaria|outbreak)\b/i',
    'services' => '/\b(medical mission|health cent(er|re)s?|rural health units?|provincial health office|health office|department of health|doh|philhealth)\b/i',
];

// A report with any of these words waits for admin review before it appears.
const BHW_SERIOUS = '/\b(outbreak|epidemic|deaths?|dies|died|dead|fatal\w*|namatay|patay|state of calamity|public health emergency|clusters?)\b/iu';

function bhw_category(string $text): ?string
{
    foreach (BHW_CATEGORIES as $cat => $re) {
        if (preg_match($re, $text)) {
            return $cat;
        }
    }
    return null;
}

function bhw_municipalities(string $text): array
{
    $found = [];
    foreach (BHW_MUNICIPALITIES as $m) {
        // Town names are matched case-sensitively, so "loon" and "anda" in ordinary
        // words are ignored. The lookahead skips same-named places in other provinces.
        $name = $m === 'Tagbilaran City' ? 'Tagbilaran(?: City)?' : preg_quote($m, '/');
        if (preg_match('/(?<![\p{L}-])' . $name . '(?![\p{L}-])(?!,?\s*(?:Cebu|Leyte|Negros|Davao|Iloilo|Bulacan|Rizal|Laguna|Batangas|Pangasinan|Nueva|Spain)\b)(?! City\b(?<!Tagbilaran City))/u', $text)) {
            $found[] = $m;
        }
    }
    return $found;
}

function bhw_scope(string $text, array $towns): string
{
    if ($towns) {
        return 'municipal';
    }
    return preg_match('/\b(Bohol|Bol-anon|Boholano)\w*\b/iu', $text) ? 'province' : 'national';
}

function bhw_is_serious(string $text): bool
{
    return (bool) preg_match(BHW_SERIOUS, $text);
}

function bhw_excerpt(string $html, int $max = 240): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    return rtrim($space ? mb_substr($cut, 0, $space, 'UTF-8') : $cut, " ,.;:") . '…';
}
