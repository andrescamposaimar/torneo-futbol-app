<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\NameParser;
use EntreRedes\Campeones\Linking\PlayerDirectoryInterface;
use EntreRedes\Campeones\Linking\RegisteredPlayer;

/**
 * In-memory PlayerDirectoryInterface double for LinkResolverTest. Mirrors
 * WpPlayerDirectory's own bucketing: each player's key is computed once,
 * via NameParser::keyFor(), when the fixture is loaded — never per lookup.
 */
final class FakePlayerDirectory implements PlayerDirectoryInterface {

    /**
     * @param RegisteredPlayer[] $players
     */
    public function __construct( private readonly array $players ) {
    }

    /**
     * @param array<int, array{id: int, title: string, seasons: string[]}> $rows
     */
    public static function fromFixtureRows( array $rows ): self {
        $players = array_map(
            static fn ( array $row ): RegisteredPlayer => new RegisteredPlayer(
                $row['id'],
                $row['title'],
                $row['seasons'],
                null,
                NameParser::keyFor( $row['title'] )
            ),
            $rows
        );

        return new self( $players );
    }

    public function findBySurname( string $normalizedSurname ): array {
        return array_values(
            array_filter(
                $this->players,
                static fn ( RegisteredPlayer $player ): bool =>
                    null !== $player->key && $player->key->surname === $normalizedSurname
            )
        );
    }

    public function existsById( int $id ): bool {
        foreach ( $this->players as $player ) {
            if ( $player->id === $id ) {
                return true;
            }
        }

        return false;
    }

    public function findByIds( array $ids ): array {
        $wanted = array_flip( $ids );
        $result = [];

        foreach ( $this->players as $player ) {
            if ( isset( $wanted[ $player->id ] ) ) {
                $result[ $player->id ] = $player;
            }
        }

        return $result;
    }
}
