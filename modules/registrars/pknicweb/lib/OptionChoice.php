<?php

declare(strict_types=1);

namespace PknicWeb;

/**
 * A field value that must be picked from a <select>'s options, resolved against the live form.
 *  - mode "equal":       an option whose value or text equals one of $wanted (case-insensitive),
 *                        else whose text contains the first wanted value.
 *  - mode "nameservers": an option whose text lists exactly the wanted hostnames (PKNIC nameserver sets).
 */
final class OptionChoice
{
    public ?string $resolved = null;

    /** @param string[] $wanted */
    public function __construct(
        public readonly array $wanted,
        public readonly string $mode = 'equal',
        public readonly bool $required = true
    ) {
    }

    /** @param array<string, string> $options value => visible text */
    public function resolve(array $options): ?string
    {
        $this->resolved = null;
        $wanted = array_values(array_filter(array_map(
            static fn ($w) => strtolower(trim((string) $w)),
            $this->wanted
        ), 'strlen'));
        if ($wanted === []) {
            return null;
        }

        if ($this->mode === 'nameservers') {
            foreach ($options as $value => $text) {
                $hosts = PknicActions::extractHostnames([$text . ' ' . $value]);
                if ($hosts !== [] && PknicActions::sameSet($hosts, $wanted)) {
                    return $this->resolved = (string) $value;
                }
            }
            return null;
        }

        foreach ($options as $value => $text) {
            if (in_array(strtolower((string) $value), $wanted, true) || in_array(strtolower(trim($text)), $wanted, true)) {
                return $this->resolved = (string) $value;
            }
        }
        foreach ($options as $value => $text) {
            if ((string) $value !== '' && str_contains(strtolower($text), $wanted[0])) {
                return $this->resolved = (string) $value;
            }
        }
        return null;
    }
}
