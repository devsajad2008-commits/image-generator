<?php

namespace App\Services\ProductImages;

class ImageLicensePolicy
{
    public function classify(?string $license, ?string $licenseUrl): string
    {
        $licenseText = strtolower(trim((string) $license));
        $licenseUrl = strtolower(trim((string) $licenseUrl));
        $normalizedLicense = preg_replace('/[^a-z0-9]+/', '', $licenseText) ?? '';

        if ($licenseText === '' && $licenseUrl === '') {
            return 'unknown';
        }

        if (str_contains($normalizedLicense, 'bync') || str_contains($normalizedLicense, 'bynd')
            || str_contains($normalizedLicense, 'noderivatives')
            || str_contains($normalizedLicense, 'noncommercial')
            || str_contains($licenseText, 'noncommercial') || str_contains($licenseText, 'non-commercial')
            || str_contains($licenseText, 'no derivative') || str_contains($licenseText, 'no-derivatives')
            || str_contains($normalizedLicense, 'allrightsreserved')
            || str_contains($licenseText, 'all rights reserved') || str_contains($licenseUrl, 'by-nc')
            || str_contains($licenseUrl, 'by-nd')) {
            return 'restricted';
        }

        $isPublicDomain = str_contains($licenseText, 'cc0') || str_contains($licenseText, 'public domain')
            || str_contains($licenseUrl, 'creativecommons.org/publicdomain/zero/')
            || str_contains($licenseUrl, 'creativecommons.org/publicdomain/mark/');
        $isCreativeCommons = str_contains($licenseUrl, 'creativecommons.org/licenses/by/')
            || str_contains($licenseUrl, 'creativecommons.org/licenses/by-sa/')
            || str_starts_with($normalizedLicense, 'ccby')
            || str_contains($licenseText, 'cc0');

        return $isPublicDomain || $isCreativeCommons ? 'verified_open' : 'unknown';
    }

    public function allowsAutomaticUse(?string $license, ?string $licenseUrl, ?string $attribution = null): bool
    {
        if ($this->classify($license, $licenseUrl) !== 'verified_open' || ! $licenseUrl) {
            return false;
        }

        $license = strtolower($license);
        $licenseUrl = strtolower($licenseUrl);
        $requiresAttribution = str_contains($license, 'cc by') || str_contains($license, 'cc-by')
            || str_contains($licenseUrl, 'creativecommons.org/licenses/by/');

        return ! $requiresAttribution || filled($attribution);
    }
}
