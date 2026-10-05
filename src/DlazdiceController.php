<?php

declare(strict_types=1);

namespace Katastr;

final class DlazdiceController
{
    /**
     * Parcely se nekreslí od nejmenších zoomů: v zoomu 12 by jedna dlaždice pokrývala
     * přes 30 km2, tedy tisíce parcel, které jsou na obrazovce menší než pixel.
     * Pod tímto zoomem se místo nich zobrazují hranice katastrálních území.
     *
     * Nad horní hranicí si dlaždice dopočítá MapLibre přeskalováním (overzoom), takže
     * nemá smysl generovat samostatné dlaždice pro zoom 17 až 20 se stejným obsahem.
     */
    private const ZOOM_PARCELY = [14, 16];

    /** Hranice KÚ se v zoomu 13 a výš kreslí přeskalováním dlaždic ze zoomu 12. */
    private const ZOOM_KATASTRALNI_UZEMI = [0, 12];

    /** Dlaždice se mění jen při novém importu, proto dlouhá cache. */
    private const CACHE_SEKUND = 86400;

    public function __construct(
        private readonly DlazdiceRepository $repository,
        private readonly DlazdiceCache $cache,
    ) {
    }

    public function parcely(array $params): void
    {
        $this->vydej(
            'parcely',
            fn (int $z, int $x, int $y): string => $this->repository->parcely($z, $x, $y),
            $params,
            self::ZOOM_PARCELY,
        );
    }

    public function katastralniUzemi(array $params): void
    {
        $this->vydej(
            'katastralni_uzemi',
            fn (int $z, int $x, int $y): string => $this->repository->katastralniUzemi($z, $x, $y),
            $params,
            self::ZOOM_KATASTRALNI_UZEMI,
        );
    }

    /** @param array{int, int} $zoomRozsah */
    private function vydej(string $vrstva, callable $generator, array $params, array $zoomRozsah): void
    {
        $z = (int) $params['z'];
        $x = (int) $params['x'];
        $y = (int) $params['y'];

        [$zoomOd, $zoomDo] = $zoomRozsah;

        if ($z < $zoomOd || $z > $zoomDo) {
            Response::error(404, sprintf('Vrstva %s se v zoomu %d negeneruje.', $vrstva, $z));

            return;
        }

        $maxIndex = (1 << $z) - 1;
        if ($x > $maxIndex || $y > $maxIndex) {
            Response::error(404, 'Dlaždice je mimo rozsah zoomu.');

            return;
        }

        $mvt = $this->cache->read($vrstva, $z, $x, $y);

        if ($mvt === null) {
            $mvt = $generator($z, $x, $y);
            $this->cache->write($vrstva, $z, $x, $y, $mvt);
        }

        Response::dlazdice($mvt, self::CACHE_SEKUND);
    }
}
