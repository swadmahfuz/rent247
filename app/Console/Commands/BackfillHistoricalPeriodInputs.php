<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\HistoricalInvoiceImporter;
use Illuminate\Console\Command;

class BackfillHistoricalPeriodInputs extends Command
{
    protected $signature = 'billing:backfill-period-inputs {--property= : Optional property id}';

    protected $description = 'Rebuild billing-period meter and charge inputs from existing invoices (skips 2nd / owner-occupied unit meters)';

    public function handle(HistoricalInvoiceImporter $importer): int
    {
        $propertyId = $this->option('property');
        $query = Property::query()->orderBy('id');
        if ($propertyId) {
            $query->where('id', $propertyId);
        }

        $properties = $query->get();
        if ($properties->isEmpty()) {
            $this->warn('No properties found.');

            return self::SUCCESS;
        }

        $totalPeriods = 0;
        foreach ($properties as $property) {
            $result = $importer->backfillProperty($property);
            $totalPeriods += $result['periods'];
            $this->info(sprintf(
                'Property #%d (%s): synced %d period(s).',
                $property->id,
                $property->name ?? 'property',
                $result['periods']
            ));
        }

        $this->info("Done. Synced {$totalPeriods} billing period(s).");

        return self::SUCCESS;
    }
}
