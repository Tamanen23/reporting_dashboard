<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Exceptions\WorkbookStructureException;
use SimpleXMLElement;
use ZipArchive;

final class XlsxHeaderInspector
{
    private const ALIASES = [
        'player_id' => ['player id', 'player_id', 'userid', 'user id', 'id'],
        'username' => ['username', 'user name', 'login', 'user'],
        'registration_date' => ['registration date', 'registered date', 'registered at', 'registration_date', 'created at', 'created date'],
        'registration_completed' => ['registration completed', 'registration completion', 'registration_completed', 'verification status', 'kyc status', 'reg finished'],
        'account_status' => ['account status', 'status', 'account_status'],
        'disabled_status' => ['disabled', 'disabled status', 'is disabled', 'disabled_status'],
        'deleted_status' => ['deleted', 'deleted status', 'is deleted', 'deleted_status'],
        'last_deposit_date' => ['last deposit', 'last deposit date', 'last_deposit_date'],
    ];

    private const PAYMENT_ALIASES = [
        'username' => ['username'],
        'player_id' => ['user id'],
        'currency' => ['currency'],
        'amount' => ['amount'],
        'gateway' => ['gateway'],
        'processed' => ['processed'],
        'transaction_type' => ['type'],
        'transaction_date' => ['processed date', 'processed at'],
        'status' => ['status'],
    ];

    private const BONUS_SUMMARY_ALIASES = [
        'wallet_type' => ['wallet type'],
        'currency' => ['currency'],
        'credited_amount' => ['sum in', 'total bonus credited', 'credited amount'],
        'converted_amount' => ['sum out', 'bonus converted to real', 'converted amount'],
        'credited_count' => ['count in', 'credited count'],
        'converted_count' => ['count out', 'converted count'],
    ];

    private const CASH_OPERATIONS_ALIASES = [
        'bet_id' => ['slip #'],
        'transaction_date' => ['date & time'],
        'currency' => ['currency'],
        'game' => ['game'],
        'cash_amount' => ['cash amount'],
        'withholding_tax' => ['withholding tax'],
        'transaction_type' => ['type'],
        'player_id' => ['user #'],
        'username' => ['user name'],
    ];

    private const PLAYER_ACTIVITY_ALIASES = [
        'player_id' => ['id', 'user id', 'user #'],
        'username' => ['user', 'username', 'user name'],
        'registration_date' => ['registered date', 'registered at'],
        'registration_completed' => ['reg finished'],
        'disabled_status' => ['disabled'],
        'deleted_status' => ['deleted'],
        'amount' => ['amount'],
        'gateway' => ['gateway'],
        'processed' => ['processed'],
        'transaction_type' => ['type'],
        'transaction_date' => ['processed date', 'processed at', 'date & time'],
        'status' => ['status'],
        'bet_id' => ['slip #'],
        'cash_amount' => ['cash amount'],
        'bet_date' => ['issue time'],
        'game' => ['game'],
        'slip_state' => ['slip state'],
        'bet_status' => ['bet status'],
        'stake' => ['stake'],
    ];

    public function inspect(
        string $path,
        array $requiredCanonicalFields,
        ?string $expectedWorksheet = null,
        string $profile = 'registration_dashboard',
        ?string $extension = null,
    ): array {
        $extension = mb_strtolower($extension ?: pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return $this->inspectCsv($path, $requiredCanonicalFields, $profile);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new WorkbookStructureException('The uploaded file is not a readable XLSX workbook.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $worksheets = $this->worksheets($zip);
            $worksheetName = $expectedWorksheet ?? array_key_first($worksheets);
            if ($worksheetName === null || ! isset($worksheets[$worksheetName])) {
                throw new WorkbookStructureException(
                    "The required worksheet '{$expectedWorksheet}' was not found.",
                    ['available_worksheets' => array_keys($worksheets)],
                );
            }
            $sheetXml = $zip->getFromName($worksheets[$worksheetName]);
            if ($sheetXml === false) {
                throw new WorkbookStructureException("The worksheet '{$worksheetName}' is not readable.");
            }
            $sheet = $this->xml($sheetXml);
            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = $sheet->xpath('//x:sheetData/x:row');
            if (! $rows) {
                throw new WorkbookStructureException('The workbook worksheet is empty.');
            }
            $headers = [];
            foreach ($rows[0]->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $type = (string) $cell['t'];
                $values = $cell->xpath('./*[local-name()="v"]');
                $value = $values ? (string) $values[0] : '';
                if ($type === 'inlineStr') {
                    $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: []));
                } elseif ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }
                $headers[] = trim($value);
            }
            $mapping = $this->mapHeaders($headers, $requiredCanonicalFields, $profile);

            return ['format' => 'xlsx', 'worksheet' => $worksheetName, 'headers' => $headers, 'mapping' => $mapping];
        } finally {
            $zip->close();
        }
    }

    private function inspectCsv(string $path, array $requiredCanonicalFields, string $profile): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new WorkbookStructureException('The uploaded CSV file is not readable.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new WorkbookStructureException('The CSV file is empty.');
            }
            $delimiter = collect([',', ';', "\t", '|'])
                ->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))
                ->first() ?? ',';
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter);
        } finally {
            fclose($handle);
        }

        if (! is_array($headers) || $headers === []) {
            throw new WorkbookStructureException('The CSV file does not contain a readable header row.');
        }
        $headers = array_map(
            static fn (mixed $header): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $header)),
            $headers,
        );

        return [
            'format' => 'csv',
            'worksheet' => null,
            'delimiter' => $delimiter,
            'headers' => $headers,
            'mapping' => $this->mapHeaders($headers, $requiredCanonicalFields, $profile),
        ];
    }

    private function mapHeaders(array $headers, array $requiredCanonicalFields, string $profile): array
    {
        $normalisedHeaders = array_map($this->normalise(...), $headers);
        $duplicates = array_keys(array_filter(
            array_count_values($normalisedHeaders),
            fn (int $count, string $header): bool => $header !== '' && $count > 1,
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($duplicates !== []) {
            throw new WorkbookStructureException('The source file contains duplicate column headings.', ['columns' => $duplicates]);
        }
        $aliasesByCanonical = match ($profile) {
            'deposits_withdrawals_bonus_dashboard' => self::PAYMENT_ALIASES,
            'bonus_summary' => self::BONUS_SUMMARY_ALIASES,
            'cash_operations_dashboard' => self::CASH_OPERATIONS_ALIASES,
            'player_activity_retention_dashboard' => self::PLAYER_ACTIVITY_ALIASES,
            default => self::ALIASES,
        };
        $mapping = [];
        foreach ($aliasesByCanonical as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($this->normalise($alias), $normalisedHeaders, true);
                if ($index !== false) {
                    $mapping[$canonical] = $headers[$index];
                    break;
                }
            }
        }
        $missing = array_values(array_diff($requiredCanonicalFields, array_keys($mapping)));
        if ($missing !== []) {
            throw new WorkbookStructureException(
                'This source file does not match the selected report.',
                ['missing_columns' => $missing, 'observed_columns' => $headers],
            );
        }

        return $mapping;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');
        if ($contents === false) {
            return [];
        }
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $contents, $items);

        return array_map(static function (string $item): string {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $item, $texts);

            return html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1);
        }, $items[1] ?? []);
    }

    private function worksheets(ZipArchive $zip): array
    {
        $workbookContents = $zip->getFromName('xl/workbook.xml');
        $relationshipContents = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookContents === false || $relationshipContents === false) {
            throw new WorkbookStructureException('The workbook sheet index is missing.');
        }
        $relationships = $this->xml($relationshipContents);
        $targets = [];
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $targets[(string) $relationship['Id']] = 'xl/'.ltrim((string) $relationship['Target'], '/');
        }
        $workbook = $this->xml($workbookContents);
        $result = [];
        foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [] as $sheet) {
            $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
            if ($relationshipId !== '' && isset($targets[$relationshipId])) {
                $result[(string) $sheet['name']] = $targets[$relationshipId];
            }
        }

        return $result;
    }

    private function xml(string $contents): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents);
            if ($xml === false) {
                throw new WorkbookStructureException('The workbook contains malformed XML.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(trim($value))));
    }
}
