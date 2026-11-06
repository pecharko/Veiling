<?php
declare(strict_types=1);

/** Keep the social footer visible even before catalog lots are published. */
function public_social_profile_url(string $platform, mixed $value): ?string
{
    if (!is_string($value) || $value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $hosts = $platform === 'facebook'
        ? ['facebook.com', 'www.facebook.com', 'm.facebook.com']
        : ['instagram.com', 'www.instagram.com'];
    if (!in_array(strtolower((string)($parts['host'] ?? '')), $hosts, true)) return null;
    return $value;
}

function public_social_footer(): void
{
    $icons = [
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" stroke="none" fill="currentColor"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="18" cy="6" r=".8" fill="currentColor" stroke="none"/>',
    ];
    echo '<section class="footer-social" aria-label="Volg ons op sociale media">';
    echo '<span class="footer-social-heading">Volg ons</span><div class="footer-social-icons">';
    foreach ($icons as $platform => $svg) {
        $label = ucfirst($platform);
        $configuredUrl = cfg($platform . '_url');
        // Both official social profiles were confirmed by the organization.
        $defaultUrls = [
            'facebook' => 'https://www.facebook.com/41Lokeren',
            'instagram' => 'https://www.instagram.com/41lokeren/',
        ];
        $profileUrl = $configuredUrl ?: $defaultUrls[$platform];
        $href = public_social_profile_url($platform, $profileUrl);
        $picture = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $svg . '</svg>';
        if ($href !== null) {
            echo '<a class="footer-social-icon" href="' . e($href) . '" aria-label="' . $label
                . ' van 41 Lokeren (opent in nieuw tabblad)" target="_blank" rel="noopener noreferrer">'
                . $picture . '</a>';
        } else {
            // Do not invent links to accounts that have not yet been confirmed.
            echo '<span class="footer-social-icon footer-social-icon--pending" role="img" aria-label="'
                . $label . ' (link nog niet ingesteld)">' . $picture . '</span>';
        }
    }
    echo '</div></section>';
}
