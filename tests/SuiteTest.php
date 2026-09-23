<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Executa a suíte de scripts do projeto sob o PHPUnit.
 * Fase 9.6 de docs/AUDITORIA_CONFORMIDADE_2026-08-05.md.
 *
 * POR QUE ENVOLVER EM VEZ DE REESCREVER
 * Os 33 arquivos `tests/test_*.php` são scripts procedurais que imprimem
 * "[OK] / [FAIL]" e encerram com código de saída. Eles já verificam o que
 * precisam e já falham quando devem — reescrevê-los como classes PHPUnit seria
 * um mês de trabalho com risco de introduzir erro em teste que hoje funciona.
 *
 * O que faltava era integração com CI: relatório padronizado, código de saída
 * previsível e execução isolada por caso. É o que esta classe entrega, rodando
 * cada script como subprocesso.
 *
 * Cada script roda em processo próprio DE PROPÓSITO. Vários mexem em estado
 * global (sessão, cache de settings, transações) e alguns manipulam triggers;
 * compartilhar processo faria um teste contaminar o outro — que é justamente o
 * tipo de falha intermitente que corrói a confiança na suíte.
 *
 * Testes novos podem nascer como classes PHPUnit normais, convivendo com estes.
 */
final class SuiteTest extends TestCase
{
    /** Scripts que exigem banco e não devem rodar em CI sem MySQL. */
    private const REQUER_BANCO_ENV = 'PONTO_TESTS_SEM_BANCO';

    public static function scripts(): array
    {
        $dir = dirname(__DIR__) . '/tests';
        $out = [];
        foreach (glob($dir . '/test_*.php') ?: [] as $arquivo) {
            $out[basename($arquivo)] = [$arquivo];
        }
        ksort($out);
        return $out;
    }

    #[DataProvider('scripts')]
    public function testScriptPassa(string $arquivo): void
    {
        if (getenv(self::REQUER_BANCO_ENV)) {
            $this->markTestSkipped('Execução sem banco de dados (' . self::REQUER_BANCO_ENV . ').');
        }

        $php = PHP_BINARY;
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($arquivo) . ' 2>&1';

        $saida = [];
        $codigo = 0;
        exec($cmd, $saida, $codigo);
        $texto = implode("\n", $saida);

        // Erro fatal nunca é aceitável, mesmo que o script devolva 0.
        $this->assertDoesNotMatchRegularExpression(
            '/(Fatal error|Uncaught|Parse error)/',
            $texto,
            "Erro fatal em " . basename($arquivo) . ":\n" . $texto
        );

        // Os scripts imprimem "Failed: N" no fim. Qualquer N > 0 é falha, mesmo
        // que o código de saída seja 0 — houve script que devolveu 0 com falhas.
        if (preg_match('/Failed:\s*(\d+)/', $texto, $m)) {
            $this->assertSame(
                0,
                (int)$m[1],
                "Asserções falharam em " . basename($arquivo) . ":\n"
                . implode("\n", array_filter($saida, fn($l) => str_contains($l, '[FAIL]')))
            );
        }

        $this->assertSame(
            0,
            $codigo,
            "Código de saída {$codigo} em " . basename($arquivo) . ":\n" . $texto
        );
    }

    /** A suíte precisa existir — um glob vazio passaria silenciosamente. */
    public function testSuiteNaoEstaVazia(): void
    {
        $this->assertGreaterThan(
            25,
            count(self::scripts()),
            'A suíte deveria ter dezenas de scripts; encontrar poucos indica '
            . 'problema de caminho ou arquivos removidos por engano.'
        );
    }
}
