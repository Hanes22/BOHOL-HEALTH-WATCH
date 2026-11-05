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
    'services' => '/\b(new (hospital|clinic|ambulance)s?|hospital (opens|upgrade\w*|inaugurat\w*)|blood (drive|donation|letting)|free (check-?ups?|consultations?|dental|surgery|screening)|medical mission|health cent(er|re)s?|rural health units?|provincial health office|health office|department of health|doh|philhealth)\b/i',
];

// A report with any of these words waits for admin review before it appears.
const BHW_SERIOUS = '/\b(outbreak|epidemic|deaths?|dies|died|dead|fatal\w*|namatay|patay|state of calamity|public health emergency|clusters?)\b/iu';

// Alerts need a public-health signal. A single incident such as one death is news, not an alert.
const BHW_ALERT = '/\b(outbreaks?|epidemic|public health emergency|state of calamity|health (alert|advisory|warning)|code (white|blue|red)|clusters?|(surge|spike|rise|increase) in (\w+ ){0,3}cases|cases? (surge|surged|spike|spiked|rise|rising|rose|increase|increased))\b/iu';
const BHW_ALERT_STRONG = '/\b(outbreaks?|epidemic|public health emergency|state of calamity)\b/iu';
const BHW_GOOD = '/\b(free (medical|dental|check-?ups?|consultations?|vaccin\w*|medicines?|eye|surgery|screening)|medical mission|blood (drive|donation|letting)|cases? (drop|drops|dropped|fall|fell|falls|declin\w*|down|decreas\w*)|(drop|decline|decrease) in (\w+ ){0,3}cases|outbreak (is )?(lifted|over|ended)|recover(ed|y|ies)|discharged|new (hospital|health cent\w+|clinic|ambulance)s?|inaugurat\w*|upgraded?|wins?|awarded?|award|recogni[sz]ed|dengue-free|zero (cases|deaths)|no (new )?(cases|deaths)|milestone|donat(es|ed|ions?)|fully immuni[sz]ed)\b/iu';
const BHW_GOOD_OVERRIDES_SERIOUS = '/\b(zero deaths|no (new )?deaths|outbreak (is )?(lifted|over|ended))\b/iu';

function bhw_tone(string $text): ?string
{
    if (!preg_match(BHW_GOOD, $text)) {
        return null;
    }
    return bhw_is_serious($text) && !preg_match(BHW_GOOD_OVERRIDES_SERIOUS, $text) ? null : 'good';
}

/** Returns active, watch or null. News stays at watch. Only an official source can reach active. */
function bhw_alert_level(string $text, string $trust, string $scope): ?string
{
    if ($scope === 'national' || bhw_tone($text) === 'good' || !preg_match(BHW_ALERT, $text)) {
        return null;
    }
    return $trust === 'official' && preg_match(BHW_ALERT_STRONG, $text) ? 'active' : 'watch';
}

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
