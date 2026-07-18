<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/core/GraphUtilities.php
|--------------------------------------------------------------------------
| IPMdb Graph Utilities
|--------------------------------------------------------------------------
|
| Shared normalization, identity, weighting, hashing, and integrity tools
| for relationship and graph services.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

trait GraphUtilities
{
    /**
     * Normalize and validate a relationship type.
     */
    protected function normalizeRelationshipType(
        string $relationshipType
    ): string {
        $relationshipType = strtolower(
            trim($relationshipType)
        );

        $relationshipType = preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $relationshipType
        ) ?? '';

        $relationshipType = trim(
            $relationshipType,
            '_'
        );

        if ($relationshipType === '') {
            return 'related_to';
        }

        if (
            property_exists($this, 'relationshipTypes')
            && is_array($this->relationshipTypes)
            && $this->relationshipTypes !== []
            && !in_array(
                $relationshipType,
                $this->relationshipTypes,
                true
            )
        ) {
            return 'related_to';
        }

        return $relationshipType;
    }

    /**
     * Normalize confidence to a percentage from 0 through 100.
     */
    protected function normalizeConfidence(
        mixed $confidence
    ): float {
        if (
            $confidence === null
            || $confidence === ''
        ) {
            return 100.0;
        }

        if (!is_numeric($confidence)) {
            throw new InvalidArgumentException(
                'Confidence must be numeric.'
            );
        }

        $confidence = (float)$confidence;

        if (
            $confidence < 0
            || $confidence > 100
        ) {
            throw new InvalidArgumentException(
                'Confidence must be between 0 and 100.'
            );
        }

        return round($confidence, 2);
    }

    /**
     * Normalize graph weight or strength from 0 through 1.
     */
    protected function normalizeWeight(
        mixed $weight
    ): float {
        if (
            $weight === null
            || $weight === ''
        ) {
            return 1.0;
        }

        if (!is_numeric($weight)) {
            throw new InvalidArgumentException(
                'Graph weight must be numeric.'
            );
        }

        $weight = (float)$weight;

        if (
            $weight < 0
            || $weight > 1
        ) {
            throw new InvalidArgumentException(
                'Graph weight must be between 0 and 1.'
            );
        }

        return round($weight, 6);
    }

    /**
     * Normalize a relationship lifecycle status.
     */
    protected function normalizeStatus(
        string $status
    ): string {
        $status = strtolower(
            trim($status)
        );

        $status = preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $status
        ) ?? '';

        $allowed = [
            'proposed',
            'active',
            'verified',
            'disputed',
            'rejected',
            'expired',
            'archived',
        ];

        return in_array(
            $status,
            $allowed,
            true
        )
            ? $status
            : 'proposed';
    }

    /**
     * Normalize a public graph identifier.
     */
    protected function normalizeIdentifier(
        string $identifier
    ): string {
        $identifier = strtoupper(
            trim($identifier)
        );

        return preg_replace(
            '/[^A-Z0-9\-_.:]+/',
            '',
            $identifier
        ) ?? '';
    }

    /**
     * Generate a collision-resistant relationship identifier.
     */
    protected function generateRelationshipId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(
                    random_bytes(6)
                )
            );
        } catch (Throwable) {
            $random = strtoupper(
                substr(
                    hash(
                        'sha256',
                        uniqid('', true)
                        . microtime(true)
                    ),
                    0,
                    12
                )
            );
        }

        return 'REL-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }

    /**
     * Normalize arbitrary values before hashing.
     */
    protected function normalizeForHash(
        mixed $value
    ): mixed {
        if (is_object($value)) {
            if ($value instanceof JsonSerializable) {
                return $this->normalizeForHash(
                    $value->jsonSerialize()
                );
            }

            if (method_exists($value, 'toArray')) {
                return $this->normalizeForHash(
                    $value->toArray()
                );
            }

            return $this->normalizeForHash(
                get_object_vars($value)
            );
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->normalizeForHash($item),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash(
                $item
            );
        }

        return $value;
    }

    /**
     * Determine whether a value is operationally empty.
     */
    protected function isEmpty(
        mixed $value
    ): bool {
        return $value === null
            || $value === ''
            || (
                is_array($value)
                && $value === []
            );
    }

    /**
     * Produce a stable node key.
     */
    protected function graphNodeKey(
        string $entityType,
        string $entityId
    ): string {
        $entityType = strtolower(
            trim($entityType)
        );

        $entityType = preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $entityType
        ) ?? '';

        $entityId = trim($entityId);

        if (
            $entityType === ''
            || $entityId === ''
        ) {
            return '';
        }

        return $entityType . ':' . $entityId;
    }

    /**
     * Return generic graph utility diagnostics.
     */
    protected function graphUtilityDiagnostics(): array
    {
        return [
            'relationship_id_prefix' => 'REL',
            'hash_algorithm' => 'sha256',
            'confidence_range' => [0, 100],
            'weight_range' => [0, 1],
            'timestamps' => 'UTC',
        ];
    }
}