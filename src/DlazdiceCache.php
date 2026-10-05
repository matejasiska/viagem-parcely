<?php

declare(strict_types=1);

namespace Katastr;

/**
 * Dlaždice se generují z databáze, ale mezi importy se nemění, takže se ukládají na disk.
 * Prázdná dlaždice se ukládá jako prázdný soubor, aby se opakovaně nedotazovala databáze
 * na místa, kde nic není.
 */
final class DlazdiceCache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $layer, int $z, int $x, int $y): ?string
    {
        $file = $this->path($layer, $z, $x, $y);

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function write(string $layer, int $z, int $x, int $y, string $mvt): void
    {
        $file = $this->path($layer, $z, $x, $y);
        $dir = dirname($file);

        // Druhé is_dir: adresář mohl mezitím založit souběžný požadavek a mkdir pak selže.
        // Nepovedený zápis do cache není chyba požadavku: warning jde do logu a dlaždice
        // se příště vygeneruje znovu.
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        // Zápis přes dočasný soubor, aby paralelní požadavky nepřečetly rozepsanou dlaždici.
        $temp = $file . '.' . getmypid();
        if (file_put_contents($temp, $mvt) === false || !rename($temp, $file)) {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function path(string $layer, int $z, int $x, int $y): string
    {
        return sprintf('%s/%s/%d/%d/%d.pbf', $this->directory, $layer, $z, $x, $y);
    }
}
