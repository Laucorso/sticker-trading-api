<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\TradingFixtures;
use Tests\TestCase;
use Trading\Domain\Exception\TradingException;

/**
 * Concurrencia real: cada operación corre en un proceso hijo (pcntl_fork) con su
 * propia conexión a MySQL, y todos arrancan a la vez tras una barrera de tiempo.
 *
 * No se puede usar RefreshDatabase: sus datos viven en una transacción sin
 * confirmar que los otros procesos no verían. Por eso se migra una vez por
 * ejecución y se vacían las tablas antes de cada test (DatabaseTruncation).
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;
    use TradingFixtures;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Se necesita la extensión pcntl.');
        }

        parent::setUp();
    }

    /**
     * @param  list<\Closure(): mixed>  $operations
     * @return list<string> 'ok' o el errorCode de dominio de cada operación, en orden
     */
    protected function runConcurrently(array $operations): array
    {
        // El hijo hereda los sockets del padre: si los compartieran, el primero que
        // cerrara la conexión se la cerraría a todos.
        DB::disconnect();

        $startAt = microtime(true) + 0.3;
        $children = [];

        foreach ($operations as $i => $operation) {
            $resultFile = tempnam(sys_get_temp_dir(), 'race');
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork falló');
            }

            if ($pid === 0) {
                $this->runChild($operation, $resultFile, $startAt);
            }

            $children[$i] = [$pid, $resultFile];
        }

        $results = [];
        foreach ($children as $i => [$pid, $resultFile]) {
            pcntl_waitpid($pid, $status);
            $results[$i] = (string) file_get_contents($resultFile);
            unlink($resultFile);
        }

        DB::reconnect();

        return $results;
    }

    /** @return never */
    private function runChild(\Closure $operation, string $resultFile, float $startAt): void
    {
        DB::purge();

        while (microtime(true) < $startAt) {
            usleep(200);
        }

        try {
            $operation();
            $result = 'ok';
        } catch (TradingException $e) {
            $result = $e->errorCode();
        } catch (\Throwable $e) {
            $result = 'unexpected: '.$e::class.': '.$e->getMessage();
        }

        file_put_contents($resultFile, $result);
        DB::disconnect();

        // Sin pasar por los shutdown handlers de PHPUnit/Laravel heredados del padre.
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    /** @param list<string> $results */
    protected function assertOutcomes(array $expected, array $results): void
    {
        $counts = array_count_values($results);
        ksort($counts);
        ksort($expected);

        $this->assertSame($expected, $counts, 'Resultados: '.json_encode($results));
    }

    protected function totalCopies(int $stickerId): int
    {
        return (int) DB::table('user_stickers')->where('sticker_id', $stickerId)->sum('quantity');
    }
}
