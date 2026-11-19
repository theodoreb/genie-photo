<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Component\Process\Process;

/**
 * The players of one game started for a smoke script or the replay.
 *
 * `genie:seats` gives each player a random password. The last game started is
 * saved in var/seats.json, so later runs of bin/replay-site sign in to it.
 */
final readonly class Seats
{
    private const FILE = '/var/seats.json';

    /**
     * @param array<string, array{account: string, password: string}> $seats
     */
    private function __construct(public int $game, private array $seats)
    {
    }

    /**
     * Starts a game hosted by the first player, with the others seated.
     */
    public static function start(string ...$players): self
    {
        $process = new Process(['./drupack', 'drush', 'genie:seats', ...$players], \dirname(__DIR__, 4).'/drupal', ['DRUPACK_NO_UPDATE_CHECK' => '1']);
        $json = $process->mustRun()->getOutput();
        file_put_contents(\dirname(__DIR__, 3).self::FILE, $json);

        return self::fromJson($json);
    }

    /**
     * Reads the last game started.
     */
    public static function saved(): self
    {
        return self::fromJson(file_get_contents(\dirname(__DIR__, 3).self::FILE));
    }

    public function account(string $player): string
    {
        return $this->seats[$player]['account'];
    }

    public function password(string $player): string
    {
        return $this->seats[$player]['password'];
    }

    private static function fromJson(string $json): self
    {
        ['game' => $game, 'seats' => $seats] = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return new self($game, $seats);
    }
}
