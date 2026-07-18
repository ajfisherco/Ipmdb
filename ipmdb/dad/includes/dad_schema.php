<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/dad/dad_schema.php
|--------------------------------------------------------------------------
| Dollar a Day (DAD)
| IPMdb Schema Definition
|--------------------------------------------------------------------------
|
| Canonical structure used by DAD components.
| This file contains no database access.
| It provides a single source of truth for field names,
| defaults, labels, and status values.
|
*/

function dad_schema(): array
{
    return [

        'version' => '1.0',

        'table' => 'dad_contributions',

        'primary_key' => 'id',

        'fields' => [

            'id' => [
                'type' => 'int',
                'auto_increment' => true,
            ],

            'asset_id' => [
                'type' => 'string',
                'length' => 40,
                'required' => true,
            ],

            'email' => [
                'type' => 'string',
                'length' => 255,
                'required' => true,
            ],

            'name' => [
                'type' => 'string',
                'length' => 255,
                'required' => false,
            ],

            'amount' => [
                'type' => 'decimal',
                'precision' => '10,2',
                'default' => '1.00',
            ],

            'currency' => [
                'type' => 'string',
                'length' => 8,
                'default' => 'CAD',
            ],

            'frequency' => [
                'type' => 'enum',
                'values' => [
                    'daily',
                    'monthly',
                    'yearly',
                    'one_time'
                ],
                'default' => 'daily',
            ],

            'payment_method' => [
                'type' => 'enum',
                'values' => [
                    'square',
                    'etr',
                    'paypal',
                    'crypto',
                    'cash',
                    'other'
                ],
                'default' => 'square',
            ],

            'transaction_reference' => [
                'type' => 'string',
                'length' => 255,
                'required' => false,
            ],

            'status' => [
                'type' => 'enum',
                'values' => [
                    'pending',
                    'received',
                    'verified',
                    'allocated',
                    'completed',
                    'cancelled'
                ],
                'default' => 'pending',
            ],

            'purpose' => [
                'type' => 'string',
                'length' => 255,
                'default' => 'Dollar a Day',
            ],

            'notes' => [
                'type' => 'text',
            ],

            'created_at' => [
                'type' => 'datetime',
            ],

            'updated_at' => [
                'type' => 'datetime',
            ],

        ],

        'reporting' => [

            'totals' => [
                'daily',
                'monthly',
                'yearly',
                'lifetime',
            ],

            'group_by' => [
                'status',
                'payment_method',
                'currency',
                'frequency',
            ],

        ],

        'relationship_links' => [

            'ipmdb_assets',
            'ipmdb_relationships',
            'ledger',

        ],

        'principles' => [

            'Truth over convenience.',
            'Every contribution is traceable.',
            'Every decision is attributable.',
            'History is preserved.',
            'Ideas become assets.',

        ],

    ];
}