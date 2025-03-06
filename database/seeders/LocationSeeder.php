<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function database_path;
use function json_decode;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedProvinces();
        $this->seedCities();
        $this->seedDistricts();
        $this->seedSubDistricts();
        $this->seedPostalCodesStreaming();
    }

    /**
     * Seed provinces
     */
    private function seedProvinces(): void
    {
        $this->seedFromJsonFile(
            'provinces',
            database_path('data/locations/provinces.json'),
            function ($province) {
                return [
                    'id'   => $province['prov_id'],
                    'name' => $province['prov_name'],
                ];
            },
            500
        );
    }

    /**
     * Seed cities
     */
    private function seedCities(): void
    {
        $this->seedFromJsonFile(
            'cities',
            database_path('data/locations/cities.json'),
            function ($city) {
                return [
                    'id'          => $city['city_id'],
                    'province_id' => $city['prov_id'],
                    'name'        => $city['city_name'],
                ];
            },
            100
        );
    }

    /**
     * Seed districts
     */
    private function seedDistricts(): void
    {
        $this->seedFromJsonFile(
            'districts',
            database_path('data/locations/districts.json'),
            function ($district) {
                return [
                    'id'      => $district['dis_id'],
                    'city_id' => $district['city_id'],
                    'name'    => $district['dis_name'],
                ];
            },
            100
        );
    }

    /**
     * Seed sub-districts
     */
    private function seedSubDistricts(): void
    {
        $this->seedFromJsonFile(
            'sub_districts',
            database_path('data/locations/sub_districts.json'),
            function ($subDistrict) {
                return [
                    'id'          => $subDistrict['subdis_id'],
                    'district_id' => $subDistrict['dis_id'],
                    'name'        => $subDistrict['subdis_name'],
                ];
            },
            100
        );
    }

    /**
     * Generic method to seed from JSON file with chunking
     */
    private function seedFromJsonFile(
        string $table,
        string $filePath,
        callable $transformer,
        int $chunkSize = 100
    ): void {
        // Use small file reading to save memory
        $json = File::get($filePath);
        $data = json_decode($json, true);
        unset($json); // Free memory immediately

        // Process in chunks
        $chunks = array_chunk($data, $chunkSize);
        unset($data); // Free memory immediately

        foreach ($chunks as $chunk) {
            $inserts = [];
            foreach ($chunk as $item) {
                $inserts[] = $transformer($item);
            }

            // Use prepared statements to handle special characters safely
            $columns      = array_keys($inserts[0]);
            $columnList   = implode(',', $columns);
            $placeholders = implode(',',
                array_fill(0, count($inserts), '(' . implode(',', array_fill(0, count($columns), '?')) . ')'));

            $values = [];
            foreach ($inserts as $insert) {
                foreach ($insert as $value) {
                    $values[] = $value;
                }
            }

            DB::insert("INSERT INTO {$table} ({$columnList}) VALUES {$placeholders}", $values);

            unset($inserts, $chunk); // Free memory immediately

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        unset($chunks); // Free remaining memory
    }

    /**
     * Seed postal codes using streaming approach
     */
    private function seedPostalCodesStreaming(): void
    {
        $filePath  = database_path('data/locations/postal_codes.json');
        $chunkSize = 20; // Smaller chunks for large data

        // Read file line by line to process JSON data in small chunks
        // This approach does not load the entire JSON into memory at once
        $handle = fopen($filePath, 'r');

        // Skip the opening bracket of JSON array
        fseek($handle, 1);

        $buffer      = '';
        $count       = 0;
        $inserts     = [];
        $inString    = false;
        $depth       = 0;
        $objectStr   = '';

        // Process file character by character to extract JSON objects
        while ( ! feof($handle)) {
            $currentChar = fgetc($handle);

            if ($currentChar === '"' && $buffer !== '\\') {
                $inString = ! $inString;
            }

            $buffer = $currentChar;

            if ( ! $inString) {
                if ($currentChar === '{') {
                    $depth++;
                    if ($depth === 1) {
                        $objectStr = '{';
                    } else {
                        $objectStr .= '{';
                    }
                } elseif ($currentChar === '}') {
                    $depth--;
                    $objectStr .= '}';

                    if ($depth === 0) {
                        // Complete object found
                        $postalCode = json_decode($objectStr, true);

                        if ($postalCode && isset($postalCode['postal_id'])) {
                            $inserts[] = [
                                'id'              => $postalCode['postal_id'],
                                'sub_district_id' => $postalCode['subdis_id'],
                                'district_id'     => $postalCode['dis_id'],
                                'city_id'         => $postalCode['city_id'],
                                'province_id'     => $postalCode['prov_id'],
                                'code'            => $postalCode['postal_code'],
                            ];

                            $count++;

                            // Insert when chunk size reached
                            if ($count % $chunkSize === 0) {
                                // Use prepared statements for postal codes too
                                $columns      = array_keys($inserts[0]);
                                $columnList   = implode(',', $columns);
                                $placeholders = implode(',', array_fill(0, count($inserts),
                                    '(' . implode(',', array_fill(0, count($columns), '?')) . ')'));

                                $values = [];
                                foreach ($inserts as $insert) {
                                    foreach ($insert as $value) {
                                        $values[] = $value;
                                    }
                                }

                                DB::insert("INSERT INTO postal_codes ({$columnList}) VALUES {$placeholders}", $values);

                                $inserts = [];

                                // Force garbage collection
                                if (function_exists('gc_collect_cycles')) {
                                    gc_collect_cycles();
                                }
                            }
                        }

                        $objectStr = '';
                    }
                } elseif ($depth > 0) {
                    $objectStr .= $currentChar;
                }
            } elseif ($depth > 0) {
                $objectStr .= $currentChar;
            }
        }

        // Insert any remaining records
        if (count($inserts) > 0) {
            $columns      = array_keys($inserts[0]);
            $columnList   = implode(',', $columns);
            $placeholders = implode(',',
                array_fill(0, count($inserts), '(' . implode(',', array_fill(0, count($columns), '?')) . ')'));

            $values = [];
            foreach ($inserts as $insert) {
                foreach ($insert as $value) {
                    $values[] = $value;
                }
            }

            DB::insert("INSERT INTO postal_codes ({$columnList}) VALUES {$placeholders}", $values);
        }

        fclose($handle);
    }
}
