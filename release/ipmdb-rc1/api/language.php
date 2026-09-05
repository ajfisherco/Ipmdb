<?php

declare(strict_types=1);

function ipmdb_normalize_language_tag(string $tag): string
{
    $tag = str_replace('_', '-', trim($tag));
    if ($tag === '' || !preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $tag)) {
        return '';
    }

    $parts = explode('-', $tag);
    $parts[0] = strtolower($parts[0]);
    foreach ($parts as $index => $part) {
        if ($index === 0) {
            continue;
        }
        if (strlen($part) === 2 || (strlen($part) === 3 && ctype_digit($part))) {
            $parts[$index] = strtoupper($part);
        } elseif (strlen($part) === 4) {
            $parts[$index] = ucfirst(strtolower($part));
        } else {
            $parts[$index] = strtolower($part);
        }
    }

    return implode('-', $parts);
}

function ipmdb_accept_languages(string $header): array
{
    $weighted = [];
    foreach (explode(',', $header) as $position => $entry) {
        $pieces = array_map('trim', explode(';', $entry));
        $tag = ipmdb_normalize_language_tag($pieces[0] ?? '');
        if ($tag === '') {
            continue;
        }

        $quality = 1.0;
        foreach (array_slice($pieces, 1) as $parameter) {
            if (preg_match('/^q=(0(?:\.\d{1,3})?|1(?:\.0{1,3})?)$/', $parameter, $match)) {
                $quality = (float)$match[1];
            }
        }
        if ($quality > 0) {
            $weighted[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
        }
    }

    usort($weighted, static fn(array $a, array $b): int =>
        $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position']
    );

    return array_values(array_unique(array_column($weighted, 'tag')));
}

function ipmdb_doer_language(PDO $pdo, ?string $doerEmail, string $acceptLanguage): array
{
    if ($doerEmail !== null && $doerEmail !== '') {
        $query = $pdo->prepare(
            'SELECT language_tag, fallback_language_tag, auto_publish
             FROM doer_language_preferences WHERE doer_email = ? LIMIT 1'
        );
        $query->execute([$doerEmail]);
        $preference = $query->fetch();
        if ($preference && (int)$preference['auto_publish'] === 1) {
            return [
                'language' => $preference['language_tag'],
                'fallback' => $preference['fallback_language_tag'],
                'source' => 'doer',
            ];
        }
    }

    $deviceLanguages = ipmdb_accept_languages($acceptLanguage);
    return [
        'language' => $deviceLanguages[0] ?? 'en',
        'fallback' => 'en',
        'source' => isset($deviceLanguages[0]) ? 'device' : 'default',
    ];
}

function ipmdb_publication_for(PDO $pdo, string $assetId, array $preference): ?array
{
    $wanted = ipmdb_normalize_language_tag((string)$preference['language']) ?: 'en';
    $fallback = ipmdb_normalize_language_tag((string)$preference['fallback']) ?: 'en';
    $base = explode('-', $wanted)[0];
    $candidates = array_values(array_unique([$wanted, $base, $fallback, 'en']));

    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "SELECT * FROM asset_publications
            WHERE asset_id = ? AND publication_status = 'published'
              AND language_tag IN ($placeholders)
            ORDER BY FIELD(language_tag, $placeholders), published_at DESC, id DESC
            LIMIT 1";
    $query = $pdo->prepare($sql);
    $query->execute(array_merge([$assetId], $candidates, $candidates));
    $publication = $query->fetch();

    if ($publication) {
        return $publication;
    }

    $original = $pdo->prepare(
        "SELECT * FROM asset_publications
         WHERE asset_id = ? AND publication_status = 'published'
           AND translation_method = 'original'
         ORDER BY published_at DESC, id DESC LIMIT 1"
    );
    $original->execute([$assetId]);
    return $original->fetch() ?: null;
}
