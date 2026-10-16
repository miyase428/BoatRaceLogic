<?php

declare(strict_types=1);

/**
 * Immutable v1 contract for outcome-blind ML-versus-Traditional comparison.
 * This class intentionally has no production-selection side effects.
 */
final class ComparativeContractV1
{
    public const SCOPE_SCHEMA = 'strict-forward-comparative-scope/v1';
    public const POLICY_SCHEMA = 'strict-forward-comparative-policy/v1';
    public const SNAPSHOT_SCHEMA = 'strict-forward-input-snapshot/v1';
    public const PAIR_SCHEMA = 'strict-forward-comparative-pair/v1';

    public function __construct(
        private readonly string $scopePath,
        private readonly string $policyPath,
        private readonly ?array $frozenScope = null,
        private readonly ?array $frozenPolicy = null,
    ) {
    }

    public static function production(string $repoRoot): self
    {
        $root = rtrim($repoRoot, DIRECTORY_SEPARATOR);
        return new self($root . '/config/strict_forward_comparative_scope_v1.json', $root . '/config/strict_forward_comparative_policy_v1.json');
    }

    /**
     * Historical formal evaluation must never silently substitute the latest
     * config files for a Window's frozen capture contract.  This constructor
     * accepts already hash-verified scope/policy payloads recovered from the
     * capture source freeze and performs the same schema/identity checks as
     * the production-file form.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $policy
     */
    public static function fromFrozenPayloads(array $scope, array $policy): self
    {
        return new self('', '', $scope, $policy);
    }

    /** @return array<string,mixed> */
    public function scope(): array
    {
        $scope = $this->frozenScope ?? $this->read($this->scopePath, 'comparative_scope_invalid');
        if (($scope['schema_version'] ?? '') !== self::SCOPE_SCHEMA || !is_array($scope['uses'] ?? null)) {
            throw new RuntimeException('comparative_scope_schema_invalid');
        }
        $uses = $scope['uses'];
        $canonicalUses = self::sortUses($uses);
        if ($uses !== $canonicalUses || count($uses) !== 123) throw new RuntimeException('comparative_scope_order_or_count_invalid');
        $keys = [];
        $comparable = 0;
        foreach ($uses as $use) {
            if (!is_array($use) || !self::validUse($use)) throw new RuntimeException('comparative_scope_use_invalid');
            $key = (string)$use['use_key'];
            if (isset($keys[$key])) throw new RuntimeException('comparative_scope_duplicate_use');
            $keys[$key] = true;
            $comparable += $use['scope_class'] === 'COMPARABLE' ? 1 : 0;
        }
        if ($comparable !== 40 || count($uses) - $comparable !== 83) throw new RuntimeException('comparative_scope_class_count_invalid');
        $payload = ['schema_version' => self::SCOPE_SCHEMA, 'uses' => $uses];
        $identity = self::identity($payload);
        if (!hash_equals((string)($scope['comparative_scope_identity'] ?? ''), $identity)) {
            throw new RuntimeException('comparative_scope_identity_invalid');
        }
        return $scope;
    }

    /** @return array<string,mixed> */
    public function policy(): array
    {
        $scope = $this->scope();
        $policy = $this->frozenPolicy ?? $this->read($this->policyPath, 'comparative_policy_invalid');
        if (($policy['schema_version'] ?? '') !== self::POLICY_SCHEMA
            || !hash_equals((string)($policy['comparative_scope_identity'] ?? ''), (string)$scope['comparative_scope_identity'])) {
            throw new RuntimeException('comparative_policy_scope_mismatch');
        }
        $payload = $policy;
        unset($payload['comparative_policy_identity'], $payload['provenance']);
        $identity = self::identity($payload);
        if (!hash_equals((string)($policy['comparative_policy_identity'] ?? ''), $identity)) {
            throw new RuntimeException('comparative_policy_identity_invalid');
        }
        return $policy;
    }

    /** @return array<string,mixed> */
    public function use(string $venue, int $course, string $target): array
    {
        $key = self::useKey($venue, $course, $target);
        foreach ($this->scope()['uses'] as $use) if (($use['use_key'] ?? '') === $key) return $use;
        throw new RuntimeException('comparative_scope_use_missing');
    }

    /** @return array<string,string> */
    public function identities(): array
    {
        $scope = $this->scope();
        $policy = $this->policy();
        return [
            'comparative_scope_identity' => (string)$scope['comparative_scope_identity'],
            'comparative_policy_identity' => (string)$policy['comparative_policy_identity'],
            'input_snapshot_spec_identity' => self::identity(['schema_version' => self::SNAPSHOT_SCHEMA, 'normalization' => $policy['input_snapshot_normalization'] ?? null]),
            'pair_record_schema_identity' => self::identity(['schema_version' => self::PAIR_SCHEMA, 'pairing_contract' => $policy['pairing_contract'] ?? null]),
        ];
    }

    public static function useKey(string $venue, int $course, string $target): string
    {
        $venue = strtoupper(trim($venue));
        if (!preg_match('/^[A-Z0-9]{3}$/', $venue) || $course < 1 || $course > 6 || !in_array($target, ['first', 'top2', 'top3'], true)) {
            throw new InvalidArgumentException('comparative_use_identity_invalid');
        }
        return 'COURSE_SIGNAL|' . $venue . '|' . $course . '|' . $target;
    }

    public static function identity(array $payload): string
    {
        return 'sha256:' . hash('sha256', self::canonicalJson($payload));
    }

    /** Cross-language canonical JSON: finite floats become tagged decimal strings. */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function canonicalize(mixed $value): mixed
    {
        if (is_float($value)) {
            if (!is_finite($value)) throw new InvalidArgumentException('comparative_non_finite_number');
            return ['__scalar_type' => 'float', 'value' => self::floatText($value)];
        }
        if (is_array($value)) {
            if (array_is_list($value)) return array_map(static fn(mixed $item): mixed => self::canonicalize($item), $value);
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[(string)$key] = self::canonicalize($item);
        }
        return $value;
    }

    public static function floatText(float $value): string
    {
        $text = sprintf('%.17g', $value);
        return str_contains($text, 'E') ? str_replace('E', 'e', $text) : $text;
    }

    /** @param list<array<string,mixed>> $uses @return list<array<string,mixed>> */
    public static function sortUses(array $uses): array
    {
        $order = ['first' => 0, 'top2' => 1, 'top3' => 2];
        usort($uses, static fn(array $a, array $b): int => [strtoupper((string)($a['venue'] ?? '')), (int)($a['course'] ?? 0), $order[(string)($a['target'] ?? '')] ?? 99]
            <=> [strtoupper((string)($b['venue'] ?? '')), (int)($b['course'] ?? 0), $order[(string)($b['target'] ?? '')] ?? 99]);
        return $uses;
    }

    /** @param array<string,mixed> $use */
    private static function validUse(array $use): bool
    {
        $key = self::useKey((string)($use['venue'] ?? ''), (int)($use['course'] ?? 0), (string)($use['target'] ?? ''));
        if (($use['use_key'] ?? '') !== $key || !in_array($use['scope_class'] ?? '', ['COMPARABLE', 'ML_ONLY'], true)
            || !is_array($use['ml_reference'] ?? null) || !array_key_exists('traditional_comparator', $use)) return false;
        if ($use['scope_class'] === 'COMPARABLE') return is_array($use['traditional_comparator']);
        return $use['traditional_comparator'] === 'NONE' && is_string($use['ml_only_reason'] ?? null) && $use['ml_only_reason'] !== '';
    }

    /** @return array<string,mixed> */
    private function read(string $path, string $error): array
    {
        try { $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException($error); }
        if (!is_array($value)) throw new RuntimeException($error);
        return $value;
    }
}
