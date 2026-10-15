<?php

declare(strict_types=1);

namespace PknicWeb;

final class Validator
{
    public const DEFAULT_TLDS = ['pk', 'com.pk', 'net.pk', 'org.pk', 'biz.pk', 'web.pk', 'fam.pk'];

    /** @return string[] */
    public static function tlds(?array $map = null): array
    {
        try {
            $map ??= MapLoader::load();
        } catch (MapException) {
            $map = [];
        }
        $tlds = $map['allowed_tlds'] ?? self::DEFAULT_TLDS;
        return array_map(static fn ($t) => strtolower(ltrim((string) $t, '.')), (array) $tlds);
    }

    public static function isPkTld(string $tld, ?array $map = null): bool
    {
        return in_array(strtolower(ltrim($tld, '.')), self::tlds($map), true);
    }

    /** @return string|null Error message, or null when valid. */
    public static function validateDomain(string $sld, string $tld, ?array $map = null): ?string
    {
        $sld = strtolower(trim($sld));
        $tld = strtolower(ltrim(trim($tld), '.'));

        if (!self::isPkTld($tld, $map)) {
            return "The extension .$tld is not offered through PKNIC by this registrar.";
        }
        if ($sld === '') {
            return 'Please enter a domain name.';
        }
        if (str_contains($sld, '.')) {
            return 'The domain name must not contain dots.';
        }
        if (strlen($sld) > 63) {
            return 'The domain name may be at most 63 characters long.';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $sld)) {
            return 'The domain name may only contain letters a-z, digits 0-9 and hyphens.';
        }
        if ($sld[0] === '-' || str_ends_with($sld, '-')) {
            return 'The domain name must not start or end with a hyphen.';
        }
        if ($tld === 'pk' && strlen($sld) < 4) {
            return 'Domains directly under .pk need at least 4 characters before ".pk".';
        }
        return null;
    }

    /** @return string[] Lower-cased, trailing-dot-stripped, empties removed, order kept. */
    public static function normalizeNameservers(array $nameservers): array
    {
        $out = [];
        foreach ($nameservers as $ns) {
            $ns = rtrim(strtolower(trim((string) $ns)), '.');
            if ($ns !== '') {
                $out[] = $ns;
            }
        }
        return $out;
    }

    public static function validateNameservers(array $nameservers, int $max = 5): ?string
    {
        $list = self::normalizeNameservers($nameservers);
        if (count($list) < 2) {
            return 'Please provide at least 2 nameservers.';
        }
        if (count($list) > $max) {
            return "At most $max nameservers are supported.";
        }
        if (count(array_unique($list)) !== count($list)) {
            return 'Each nameserver may only be entered once.';
        }
        foreach ($list as $ns) {
            if (!self::isValidHostname($ns)) {
                return "\"$ns\" is not a valid nameserver hostname (e.g. ns1.example.com).";
            }
        }
        return null;
    }

    public static function isValidHostname(string $host): bool
    {
        if (strlen($host) > 253 || !str_contains($host, '.')) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return (bool) preg_match('/^[a-z]{2,63}$|^xn--[a-z0-9-]+$/', (string) end($labels));
    }
}
