<?php

namespace LeKoala\EmailTemplates\Tasks;

use SilverStripe\ORM\DB;
use SilverStripe\Dev\BuildTask;
use LeKoala\EmailTemplates\Models\SentEmail;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputInterface;

/**
 *
 * Finds all non-compressed sent email bodies and compresses them
 *
 * Requires the gzip extension
 *
 * @author gurucomkz
 */
class CompressEmailBodiesTask extends BuildTask
{
    private static $segment = 'CompressEmailBodiesTask';

    protected string $title = "Compress Email Bodies task";
    protected static string $description = "Finds all non-compressed sent email bodies and compresses them";

    public function isEnabled(): bool
    {
        return Director::is_cli() && parent::isEnabled() && function_exists('gzdeflate') && Config::forClass(SentEmail::class)->get('compress_body');
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $table = SentEmail::singleton()->baseTable();
        $fromWhere = "FROM \"$table\" WHERE \"Body\" NOT LIKE '" . SentEmail::COMPRESSED_SIGNATURE . "%'";
        $total = DB::query("SELECT COUNT(ID) $fromWhere")->value();

        if (!$total) {
            $output->writeln("No non-compressed emails found");
            return 0;
        }

        $nonCompressed = DB::query("SELECT ID, \"Body\" $fromWhere");

        echo "Found " . $total . " non-compressed emails\n";

        foreach ($nonCompressed as $pos => $row) {
            if (!$row['Body']) {
                $output->writeln("Email with ID {$row['ID']} has no body");
                continue;
            }
            $compressed = gzdeflate($row['Body'] ?? '');
            if ($compressed === false) {
                $output->writeln("- Failed to compress email with ID {$row['ID']}");
                continue;
            }

            $base64compressed = SentEmail::COMPRESSED_SIGNATURE . base64_encode($compressed);

            SQLUpdate::create($table, [
                'Body' => $base64compressed,
            ], [
                'ID' => $row['ID'],
            ])->execute();

            $this->progress($pos, $total, $output);
        }

        // optimise table
        $this->optimizeTable($output);

        return 0;
    }

    public function optimizeTable(PolyOutput $output)
    {
        $table = SentEmail::singleton()->baseTable();
        $output->writeln('');
        $output->writeln("Optimizing $table table...");
        $db = DB::get_conn();
        if ($db instanceof MySQLDatabase) {
            DB::query("OPTIMIZE TABLE \"$table\"");
        } elseif (get_class($db) == "SilverStripe\PostgreSQL\PostgreSQLDatabase") {
            DB::query("VACUUM FULL \"$table\"");
        } else {
            $output->writeln("Database not supported for optimization");
        }
    }

    const PROGRRESS_SPINNER = [
        '⠋',
        '⠙',
        '⠹',
        '⠼',
        '⠴',
        '⠦',
        '⠧',
        '⠇',
    ];

    public function progress($pos, $total, PolyOutput $output)
    {
        if ($total) {
            $percent = round($pos / $total * 100, 2);
            $percentInt = floor($percent);

            $spinner = "\rConverting: [";
            $spinner .= str_repeat('￭', (int)floor($percentInt / 5));
            $edge = self::PROGRRESS_SPINNER[$pos % count(self::PROGRRESS_SPINNER)];
            $spinner .= "$edge";
            $spinner .= str_repeat(' ', 40 - strlen($spinner));
            $spinner .= "] $percent%   ";
            $output->writeForAnsi($spinner, $percentInt > 0 && $percentInt % 100 == 0);
        }
    }
}
