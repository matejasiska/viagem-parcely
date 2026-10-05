<?php

declare(strict_types=1);

namespace Katastr;

final class ApiController
{
    private const MAX_VYSLEDKU_HLEDANI = 50;

    public function __construct(
        private readonly ParcelaRepository $parcely,
        private readonly OkresRepository $okres,
    ) {
    }

    public function okres(): void
    {
        Response::json($this->okres->prehled(), 300);
    }

    public function parcela(array $params): void
    {
        $parcela = $this->parcely->detail((int) $params['id']);

        if ($parcela === null) {
            Response::error(404, 'Parcela s tímto identifikátorem v okrese není.');

            return;
        }

        Response::json($parcela, 3600);
    }

    public function hledani(array $params): void
    {
        $cislo = $params['cislo'] ?? '';
        $ku = $params['ku'] ?? '';

        // ?cislo[]=1 dává v PHP pole místo řetězce.
        if (!is_string($cislo) || !is_string($ku)) {
            Response::error(400, 'Parametry cislo a ku musí být text.');

            return;
        }

        $cislo = trim($cislo);
        $ku = trim($ku);

        if ($cislo === '') {
            Response::error(400, 'Chybí parametr cislo s číslem parcely.');

            return;
        }

        Response::json([
            'parcely' => $this->parcely->hledej($cislo, $ku, self::MAX_VYSLEDKU_HLEDANI),
        ]);
    }
}
