<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Turns SPX's location list (docs/couriers/spx/SPX-basic_location-*.xlsx) into database/data/spx/locations.json:
 * one row per SPX street (barangay) as [state, city, district, street, flags]. The flags are a string of six 0/1
 * digits in the sheet's order: pickup, COD pickup, deliver, COD deliver, trade-in deliver, pay on delivery.
 * Run by hand when SPX sends a new list; the JSON is committed and App\Couriers\Spx\SpxLocations reads it.
 */
class SpxLocationsCompile extends Command
{
    protected $signature = 'ventaship:spx-locations-compile
        {source? : The xlsx (default: the newest docs/couriers/spx/SPX-basic_location-*.xlsx)}
        {--out= : The JSON to write (default: database/data/spx/locations.json)}';

    protected $description = 'Compile SPX\'s location list xlsx into database/data/spx/locations.json';

    private const COLUMNS = [
        'State' => 'state', 'City' => 'city', 'District' => 'district', 'Street' => 'street',
        'Support Pickup' => 'f0', 'Support COD Pickup' => 'f1', 'Support Deliver' => 'f2',
        'Support COD Deliver' => 'f3', 'Support Trade In Deliver' => 'f4', 'Support Pay On Delivery' => 'f5',
    ];

    public function handle(): int
    {
        $source = $this->argument('source') ?: (collect(glob(base_path('docs/couriers/spx/SPX-basic_location-*.xlsx')) ?: [])->sort()->last());
        if (! $source || ! is_file($source)) {
            $this->error('No SPX location xlsx found.');

            return self::FAILURE;
        }
        $out = $this->option('out') ?: database_path('data/spx/locations.json');

        $zip = new \ZipArchive;
        if ($zip->open($source) !== true) {
            $this->error("Cannot open {$source}.");

            return self::FAILURE;
        }
        $strings = $this->sharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $rows = [];
        $header = null;
        $reader = new \XMLReader;
        $reader->XML($sheet, null, LIBXML_COMPACT | LIBXML_PARSEHUGE);
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $cells = $this->cells(new \SimpleXMLElement($reader->readOuterXml()), $strings);
            if ($header === null) {
                $header = [];
                foreach ($cells as $col => $label) {
                    if (isset(self::COLUMNS[trim($label)])) {
                        $header[$col] = self::COLUMNS[trim($label)];
                    }
                }
                if (count($header) !== count(self::COLUMNS)) {
                    $this->error('The sheet is missing columns: '.implode(', ', array_diff(self::COLUMNS, $header)));

                    return self::FAILURE;
                }

                continue;
            }
            $row = [];
            foreach ($header as $col => $key) {
                $row[$key] = trim((string) ($cells[$col] ?? ''));
            }
            if ($row['state'] === '' && $row['street'] === '') {
                continue;
            }
            $flags = '';
            for ($i = 0; $i < 6; $i++) {
                $flags .= in_array(strtoupper($row["f$i"]), ['1', 'Y', 'YES', 'TRUE'], true) ? '1' : '0';
            }
            $rows[] = [$row['state'], $row['city'], $row['district'], $row['street'], $flags];
        }

        usort($rows, fn ($a, $b) => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        $json = "[\n".implode(",\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $rows))."\n]\n";
        file_put_contents($out, $json);
        $this->info('Wrote '.count($rows).' SPX locations to '.$out.'.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $strings = [];
        foreach ((new \SimpleXMLElement($xml, LIBXML_PARSEHUGE))->si as $si) {
            // Rich text splits a string into runs; plain text is a single <t>.
            $text = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /** @return array<string, string> Cell values keyed by column letter. */
    private function cells(\SimpleXMLElement $row, array $strings): array
    {
        $cells = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string) $c['r']);
            $type = (string) $c['t'];
            $value = match ($type) {
                's' => $strings[(int) $c->v] ?? '',
                'inlineStr' => (string) ($c->is->t ?? ''),
                default => (string) $c->v,
            };
            $cells[$col] = $value;
        }

        return $cells;
    }
}
