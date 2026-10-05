<?php

declare(strict_types=1);

namespace Katastr;

use PDO;
use PDOStatement;

/**
 * Připojuje se až při prvním dotazu. Dlaždice z cache na disku databázi nepotřebují,
 * takže se vydají bez navazování spojení a fungují i při nedostupné databázi.
 */
final class Database
{
    /**
     * Bez limitu by při zamrzlé databázi každý požadavek visel 30 s (výchozí PDO::ATTR_TIMEOUT).
     * Nastavuje se tady, ne v DSN: pdo_pgsql připojí connect_timeout z ATTR_TIMEOUT za DSN
     * a ten má přednost.
     */
    private const TIMEOUT_PRIPOJENI_S = 2;

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    private function run(string $sql, array $params): PDOStatement
    {
        $this->pdo ??= new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => self::TIMEOUT_PRIPOJENI_S,
        ]);

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
